# coding=utf-8
"""
BigONE 深度(全量+增量) -> 本地维护 -> 提取5档 -> Redis

优化点（不改你的业务：仍维护全量、仍按 changeId/prevId 连续性、仍写 5 档到 Redis）：
1) 多进程分片 + 单进程内多连接（避免单 ws 订阅过多导致延迟/假活）
2) 订阅分批发送（SUB_BATCH + SUB_SLEEP）
3) Redis：decode_responses=False + pipeline setex（一次命令写入+TTL）
4) 维护深度：用 dict(price->amount) 做 O(1) 更新，不再 O(n^2) 扫列表
5) 提取 top5：只从 dict 里取（按价格排序取前5），成本更低、更稳定
6) 连续性断裂：标记该 market 需要 resub，不全表扫描，不反复查 DB
7) 心跳/重连：每个连接独立心跳线程 + 假活检测 + 自动重连
"""

import time
import ssl
import json
import threading
import socket
import os
import multiprocessing as mp

import websocket
import redis
import pymysql

# ===== 更快 JSON（可选）=====
try:
    import orjson
    def jloads(b):
        if isinstance(b, (bytes, bytearray)):
            return orjson.loads(b)
        return orjson.loads(b.encode("utf-8"))
    def jdumps(obj):  # bytes
        return orjson.dumps(obj)
    def jsend(obj):   # str
        return orjson.dumps(obj).decode("utf-8")
except Exception:
    def jloads(b):
        if isinstance(b, (bytes, bytearray)):
            b = b.decode("utf-8", "ignore")
        return json.loads(b)
    def jdumps(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    def jsend(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)

# ================== 配置 ==================
WS_URL = "wss://big.one/ws/v2"
HEADERS = {"Sec-WebSocket-Protocol": "json"}

MYSQL_CFG = dict(
    host=os.getenv('DB_HOST', '127.0.0.1'),
    user=os.getenv('DB_USERNAME', 'tool'),
    password=os.getenv('DB_PASSWORD', ''),
    database=os.getenv('DB_DATABASE', 'tool'),
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=True,
)

REDIS_CFG = dict(host=os.getenv('REDIS_HOST', '127.0.0.1'), port=int(os.getenv('REDIS_PORT', '6379')), password=os.getenv('REDIS_PASSWORD') or None, db=11, decode_responses=False)

PLATFORM = 14          # 你原来写死 _14_1/_14_2
TTL_SECONDS = 15       # 你原来没 setex，这里统一 setex

# 多进程 + 每连接订阅量（按你机器和交易对数量调）
PROC_NUM = 6           # 24 核建议 6~10；先 6
SYMS_PER_CONN = 120    # 每个 ws 订阅多少 market；多了就分更多连接

SUB_BATCH = 40
SUB_SLEEP = 0.12
WORKER_START_STAGGER = 0.4

# Redis pipeline
PIPELINE_BATCH = 600          # setex 命令条数
PIPELINE_FLUSH_MS = 80        # 时间阈值 flush（0 关闭）

# 心跳 / 假活
PING_INTERVAL = 10
NO_MSG_RECONNECT_SEC = 60

# socket buffer（配合你已调大的 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024

SYMBOL_SQL = "SELECT id, currency_name, quote_name FROM currency_match WHERE is_bigone=1 AND is_enabled=1"


def get_trading_pairs(db):
    """从数据库获取 (id, MARKET)  MARKET=BTC-USDT"""
    with db.cursor() as cursor:
        cursor.execute(SYMBOL_SQL)
        rows = cursor.fetchall()
    out = []
    for row in rows:
        pid = int(row["id"])
        market = ("%s-%s" % (row["currency_name"], row["quote_name"])).upper()
        out.append((pid, market))
    return out


def market_to_redis_symbol(market):
    return market.replace("-", "", 1).upper()


class BigOneConn(threading.Thread):
    """
    一个线程 = 一个 websocket 连接，负责一组 markets。
    本地深度用 dict(price->amount) 维护，更新更快。
    """
    def __init__(self, wid, pairs, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.pairs = pairs[:]  # list[(pair_id, MARKET)]
        self.market_ids = {m: pid for pid, m in self.pairs}
        self.markets = [m for _, m in self.pairs]

        self.r = redis_client
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self._last_flush = time.time()
        self._flush_interval = PIPELINE_FLUSH_MS / 1000.0 if PIPELINE_FLUSH_MS and PIPELINE_FLUSH_MS > 0 else 0.0

        self.ws = None
        self._hb_stop = threading.Event()
        self.last_msg_ts = 0

        # local state:
        # depth_map[market] = {"bids": {price: amount}, "asks": {price: amount}}
        self.depth_map = {}
        self.change_id = {}      # market -> changeId (int)
        self.need_resub = set()  # 断裂的 market：标记，定时 resub

    def log(self, *a):
        print("[conn-%s]" % str(self.wid), *a)

    def reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self.pipe_cnt = 0
        self._last_flush = time.time()

    def flush_if_needed(self, now_ts):
        if self.pipe_cnt <= 0:
            return
        if self.pipe_cnt >= PIPELINE_BATCH or (self._flush_interval and (now_ts - self._last_flush) >= self._flush_interval):
            try:
                self._p_execute()
                self.pipe_cnt = 0
                self._last_flush = now_ts
            except Exception:
                self.reset_pipeline()

    # ---------- heartbeat ----------
    def _start_heartbeat(self):
        self._hb_stop.clear()
        t = threading.Thread(target=self._heartbeat_loop, daemon=True)
        t.start()

    def _stop_heartbeat(self):
        self._hb_stop.set()

    def _heartbeat_loop(self):
        while not self._hb_stop.is_set():
            ws = self.ws
            if not ws or not ws.sock or not ws.sock.connected:
                return

            now = int(time.time())
            if self.last_msg_ts and (now - self.last_msg_ts) >= NO_MSG_RECONNECT_SEC:
                self.log("no msg for %ss, force reconnect" % (now - self.last_msg_ts))
                try:
                    ws.close()
                except Exception:
                    pass
                return

            # BigONE ping：你原来用 {"type":"ping"}，保持
            try:
                ws.send('{"type":"ping"}')
            except Exception:
                return

            # 定时对断裂 market 做 resub（温和一点）
            if self.need_resub:
                self._resub_some(ws, max_once=10)

            self._hb_stop.wait(PING_INTERVAL)

    def _resub_some(self, ws, max_once=10):
        todo = []
        for m in list(self.need_resub):
            todo.append(m)
            if len(todo) >= max_once:
                break
        for m in todo:
            pid = self.market_ids.get(m)
            if not pid:
                self.need_resub.discard(m)
                continue
            req = {
                "requestId": str(pid),
                "subscribeMarketDepthRequest": {"market": m}
            }
            try:
                ws.send(jsend(req))
            except Exception:
                return
            # resub 发出去就先移除，后续 snapshot 会重置
            self.need_resub.discard(m)
            time.sleep(0.05)

    # ---------- ws callbacks ----------
    def on_open(self, ws):
        self.last_msg_ts = int(time.time())

        # socket buffer（可注释）
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            self.log("SO_RCVBUF=%s SO_SNDBUF=%s" % (rcv, snd))
        except Exception:
            pass

        self.log("connected, subscribing markets=", len(self.pairs))

        # 初始化 state
        for _, m in self.pairs:
            self.depth_map[m] = {"bids": {}, "asks": {}}
            self.change_id[m] = 0

        # 开心跳（先开，避免订阅耗时导致假活）
        self._start_heartbeat()

        # 分批订阅
        for batch in self._chunk(self.pairs, SUB_BATCH):
            for pid, m in batch:
                req = {
                    "requestId": str(pid),
                    "subscribeMarketDepthRequest": {"market": m}
                }
                ws.send(jsend(req))
            time.sleep(SUB_SLEEP)

        self.log("subscribe sent")

    def on_message(self, ws, message):
        self.last_msg_ts = int(time.time())
        try:
            data = jloads(message)
        except Exception:
            return

        if not isinstance(data, dict):
            return

        if "error" in data:
            # 不要 print 太多，避免 IO 卡住
            err = data.get("error", {})
            self.log("error:", str(err)[:180])
            return

        # snapshot
        if "depthSnapshot" in data:
            snap = data["depthSnapshot"]
            depth = (snap.get("depth") or {})
            market = (depth.get("market") or "").upper()
            if not market:
                return

            bids = depth.get("bids") or []
            asks = depth.get("asks") or []

            # 用 dict 存 price->amount
            bmap = {}
            for it in bids:
                try:
                    p = float(it["price"]); a = float(it["amount"])
                    if a > 0:
                        bmap[p] = a
                except Exception:
                    pass

            amap = {}
            for it in asks:
                try:
                    p = float(it["price"]); a = float(it["amount"])
                    if a > 0:
                        amap[p] = a
                except Exception:
                    pass

            self.depth_map[market] = {"bids": bmap, "asks": amap}
            self.change_id[market] = int(snap.get("changeId", 0))  # 你原假设有 changeId

            self._write_top5(market)
            return

        # update
        if "depthUpdate" in data:
            upd = data["depthUpdate"]
            depth = (upd.get("depth") or {})
            market = (depth.get("market") or "").upper()
            if not market:
                return

            new_cid = int(upd.get("changeId", 0))
            prev_id = int(upd.get("prevId", 0))
            cur_id = int(self.change_id.get(market, 0))

            if new_cid <= cur_id:
                return

            # 不连续：标记 resub（不要全表扫描/不要频繁查 DB）
            if prev_id != cur_id:
                self.need_resub.add(market)
                return

            # 连续：应用增量
            self.change_id[market] = new_cid
            dmap = self.depth_map.get(market)
            if not dmap:
                self.depth_map[market] = {"bids": {}, "asks": {}}
                dmap = self.depth_map[market]

            self._apply_side(dmap["bids"], (depth.get("bids") or []))
            self._apply_side(dmap["asks"], (depth.get("asks") or []))

            self._write_top5(market)
            return

    def _apply_side(self, side_map, updates):
        """
        updates: [{"price": "...", "amount": "..."}]
        """
        for it in updates:
            try:
                p = float(it["price"]); a = float(it["amount"])
            except Exception:
                continue
            if a <= 0:
                side_map.pop(p, None)
            else:
                side_map[p] = a

    def _write_top5(self, market):
        dmap = self.depth_map.get(market)
        if not dmap:
            return

        bids_map = dmap["bids"]
        asks_map = dmap["asks"]
        if not bids_map or not asks_map:
            return

        # top5：bids 按价格降序，asks 升序
        bids5 = [[p, bids_map[p]] for p in sorted(bids_map.keys(), reverse=True)[:5]]
        asks5 = [[p, asks_map[p]] for p in sorted(asks_map.keys())[:5]]

        smb = market_to_redis_symbol(market)
        k1 = f"{smb}_{PLATFORM}_1"
        k2 = f"{smb}_{PLATFORM}_2"

        try:
            self._p_setex(k1, TTL_SECONDS, jdumps(bids5))
            self._p_setex(k2, TTL_SECONDS, jdumps(asks5))
            self.pipe_cnt += 2
        except Exception:
            self.reset_pipeline()
            return

        self.flush_if_needed(time.time())

    def on_error(self, ws, error):
        self.log("ws error:", repr(error))

    def on_close(self, ws, code, reason):
        self.log("ws closed:", code, reason)
        self._stop_heartbeat()
        try:
            if self.pipe_cnt > 0:
                self._p_execute()
        except Exception:
            pass
        self.pipe_cnt = 0

    def run(self):
        websocket.enableTrace(False)

        while True:
            try:
                self.log("connecting ...")
                w = websocket.WebSocketApp(
                    WS_URL,
                    header=HEADERS,
                    on_open=self.on_open,
                    on_message=self.on_message,
                    on_error=self.on_error,
                    on_close=self.on_close,
                )
                self.ws = w
                w.run_forever(
                    sslopt={"cert_reqs": ssl.CERT_NONE},
                    ping_interval=None,
                    ping_timeout=None,
                    sockopt=[
                        (socket.SOL_SOCKET, socket.SO_RCVBUF, SOCKBUF_BYTES),
                        (socket.SOL_SOCKET, socket.SO_SNDBUF, SOCKBUF_BYTES),
                        (socket.IPPROTO_TCP, socket.TCP_NODELAY, 1),
                    ],
                )
            except Exception as e:
                self.log("exception:", repr(e))
            time.sleep(2)

    @staticmethod
    def _chunk(arr, n):
        for i in range(0, len(arr), n):
            yield arr[i:i + n]


def run_process(pid, pairs):
    print("[proc-%d] pid=%d pairs=%d" % (pid, os.getpid(), len(pairs)))

    # 每个进程独立 DB/Redis 连接（不要跨进程共享）
    db = pymysql.connect(**MYSQL_CFG)
    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)

    # 该进程负责的 pairs 再按 SYMS_PER_CONN 拆成多个连接
    conns = []
    wid = 1
    for i in range(0, len(pairs), SYMS_PER_CONN):
        chunk = pairs[i:i + SYMS_PER_CONN]
        c = BigOneConn(f"{pid}-{wid}", chunk, r)
        c.start()
        conns.append(c)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] conns started: %d" % (pid, len(conns)))
    while True:
        time.sleep(60)


def main():
    # 主进程只用于加载交易对
    db = pymysql.connect(**MYSQL_CFG)
    pairs = get_trading_pairs(db)
    db.close()

    if not pairs:
        raise RuntimeError("no bigone pairs")

    # 轮询切片到多个进程，尽量均匀
    proc_num = min(PROC_NUM, max(1, len(pairs)))
    shards = [pairs[i::proc_num] for i in range(proc_num)]

    procs = []
    for pid in range(proc_num):
        p = mp.Process(target=run_process, args=(pid, shards[pid]), daemon=False)
        p.start()
        procs.append(p)
        time.sleep(0.5)

    for p in procs:
        p.join()


if __name__ == "__main__":
    try:
        mp.set_start_method("fork")
    except RuntimeError:
        pass
    main()
