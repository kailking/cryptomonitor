# coding=utf-8
import os
for k in ["http_proxy", "https_proxy", "all_proxy",
          "HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY",
          "no_proxy", "NO_PROXY"]:
    os.environ.pop(k, None)

import sys
import time
import ssl
import threading
import multiprocessing as mp
import socket
import re
import json

import websocket
import redis
import MySQLdb as mdb

# ===== 基础配置 =====
WS_URL = "wss://wbs-api.mexc.com/ws"

MYSQL_CFG = dict(
    host='127.0.0.1',
    port=3306,
    user='tool',
    passwd=__import__('os').getenv('DB_PASSWORD', ''),
    db='tool',
    charset='utf8'
)

# ✅ 改：decode_responses=False（存 bytes 更快；避免每次编码/解码）
REDIS_CFG = dict(
    host='127.0.0.1',
    port=6379,
    db=3,
    password=__import__('os').getenv('REDIS_PASSWORD') or None,
    decode_responses=False
)

PLATFORM = 5
LEVEL = 5
TTL_SECONDS = 30

# ===== 并发/性能参数 =====
MAX_SUB_PER_WS = 30           # ✅ 文档：每个 ws 最多 30 订阅
MAX_CONN_CAP = 300            # ✅ 最大连接数上限（按机器调）

# ✅ 进程分片（核心优化：像 gate/huobi 一样）
PROC_NUM = 8                  # 24 核机器建议 8 起步；不够再加 10/12

# 订阅发送节流
SUB_SEND_BATCH = 10
SUB_SEND_SLEEP = 0.15
START_BATCH = 10
START_BATCH_SLEEP = 1.0

# ping
PING_INTERVAL = 20

# Redis pipeline
PIPELINE_BATCH = 1200         # setex 命令条数（你每消息2条，所以满得很快）
PIPELINE_FLUSH_MS = 80        # ✅ 时间阈值 flush，避免低频/异常时一直不落库（设 0 关闭）

# socket buffer（配合你已经调大的 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024

SYMBOL_SQL = "SELECT currency_name, quote_name FROM currency_match WHERE is_mexc=1 AND is_enabled=1"

# ===== protobuf 生成目录 =====
PROTO_GEN_DIR = "/www/wwwroot/tool/app/python/proto/websocket-proto-main/gen"
if PROTO_GEN_DIR not in sys.path:
    sys.path.insert(0, PROTO_GEN_DIR)

from PushDataV3ApiWrapper_pb2 import PushDataV3ApiWrapper  # noqa

# ===== 可选：更快 JSON =====
try:
    import orjson
    def jdumps(obj):  # -> bytes
        return orjson.dumps(obj)
    def jsend(obj):   # -> str (ws send)
        return orjson.dumps(obj).decode("utf-8")
    def jloads(b):    # -> dict
        return orjson.loads(b)
except Exception:
    def jdumps(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    def jsend(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)
    def jloads(b):
        return json.loads(b)


def load_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    symbols = [("%s%s" % (c, q)).upper() for c, q in rows]
    print("loaded mexc symbols:", len(symbols))
    return symbols


def chunk_list(arr, n):
    for i in range(0, len(arr), n):
        yield arr[i:i + n]


def decode_limit_depth(pb_bytes):
    """
    解析 PushDataV3ApiWrapper -> publicLimitDepths
    返回: (symbol, bids[[p,q]...], asks[[p,q]...]) 或 None
    """
    w = PushDataV3ApiWrapper()
    w.ParseFromString(pb_bytes)

    sym = getattr(w, "symbol", "")
    if not sym:
        return None
    sym = sym.upper()

    depth = getattr(w, "publicLimitDepths", None)
    if depth is None:
        return None

    lvl = LEVEL
    asks = []
    bids = []

    if hasattr(depth, "asks"):
        for it in depth.asks:
            p = getattr(it, "price", "")
            q = getattr(it, "quantity", "")
            if p and q:
                asks.append([str(p), str(q)])
                if len(asks) >= lvl:
                    break

    if hasattr(depth, "bids"):
        for it in depth.bids:
            p = getattr(it, "price", "")
            q = getattr(it, "quantity", "")
            if p and q:
                bids.append([str(p), str(q)])
                if len(bids) >= lvl:
                    break

    if not bids or not asks:
        return None
    return sym, bids, asks


def extract_failed_symbols(msg):
    """
    msg 示例：
    'Subscribed successful! []. Not Subscribed successfully! [spot@public.limit.depth.v3.api.pb@NIGHTUSDT@5,...]'
    解析失败的 SYMBOL 集合
    """
    failed = set()
    up = msg.upper()
    pat = re.compile(r'\.pb@([A-Z0-9]+)@' + str(LEVEL))
    for m in pat.finditer(up):
        failed.add(m.group(1))
    return failed


class MexcWorker(threading.Thread):
    def __init__(self, wid, symbols, redis_client):
        super(MexcWorker, self).__init__()
        self.daemon = True
        self.wid = wid

        # ✅ worker 最多只负责 30 个订阅
        syms = [s.upper() for s in symbols][:MAX_SUB_PER_WS]
        self.symbols = syms
        self.symbol_set = set(syms)

        self.r = redis_client
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0

        self._last_flush = time.time()
        self._flush_interval = PIPELINE_FLUSH_MS / 1000.0 if PIPELINE_FLUSH_MS and PIPELINE_FLUSH_MS > 0 else 0.0

        self.ws = None
        self._stop_flag = False

        # 预绑定（减少属性查找）
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

    def _reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._last_flush = time.time()
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

    def _flush(self, now_ts=None):
        if self.pipe_cnt <= 0:
            return
        try:
            self._p_execute()
        except Exception:
            pass
        self._reset_pipeline()
        if now_ts is not None:
            self._last_flush = now_ts

    def _flush_if_needed(self, now_ts):
        if self.pipe_cnt >= PIPELINE_BATCH:
            self._flush(now_ts)
            return
        if self._flush_interval and (now_ts - self._last_flush) >= self._flush_interval:
            self._flush(now_ts)

    def _ping_loop(self):
        while not self._stop_flag:
            time.sleep(PING_INTERVAL)
            try:
                ws = self.ws
                if ws:
                    ws.send('{"method":"PING"}')
            except Exception:
                pass

    def _send_subscribe_batches(self, ws, syms):
        """
        ✅ 分批发送订阅，避免一次性 30 个也触发风控
        """
        if not syms:
            return
        lvl = LEVEL
        for batch in chunk_list(syms, SUB_SEND_BATCH):
            channels = [f"spot@public.limit.depth.v3.api.pb@{s}@{lvl}" for s in batch]
            msg = {"method": "SUBSCRIPTION", "params": channels, "id": self.wid}
            ws.send(jsend(msg))
            time.sleep(SUB_SEND_SLEEP)
        print("[worker-%d] subscribe sent, count=%d" % (self.wid, len(syms)))

    def run(self):
        websocket.enableTrace(False)

        t = threading.Thread(target=self._ping_loop)
        t.daemon = True
        t.start()

        while True:
            try:
                print("[worker-%d] connecting ... symbols=%d" % (self.wid, len(self.symbols)))

                ws = websocket.WebSocketApp(
                    WS_URL,
                    on_open=self.on_open,
                    on_message=self.on_message,
                    on_error=self.on_error,
                    on_close=self.on_close
                )
                self.ws = ws

                ws.run_forever(
                    sslopt={"cert_reqs": ssl.CERT_NONE},
                    ping_interval=None,
                    ping_timeout=None,
                    http_proxy_host=None,
                    http_proxy_port=None,
                    sockopt=[
                        (socket.SOL_SOCKET, socket.SO_RCVBUF, SOCKBUF_BYTES),
                        (socket.SOL_SOCKET, socket.SO_SNDBUF, SOCKBUF_BYTES),
                        (socket.IPPROTO_TCP, socket.TCP_NODELAY, 1),
                    ],
                )

                print("[worker-%d] run_forever returned" % self.wid)

            except Exception as e:
                print("[worker-%d] exception: %s" % (self.wid, repr(e)))

            time.sleep(2)

    def on_open(self, ws):
        # ✅ 严格控制每连接订阅数 <= 30
        syms = list(self.symbol_set)
        if len(syms) > MAX_SUB_PER_WS:
            syms = syms[:MAX_SUB_PER_WS]
            self.symbol_set = set(syms)
            self.symbols = syms

        # 打印一下 sockbuf 是否生效（可注释）
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            print("[worker-%d] SO_RCVBUF=%s SO_SNDBUF=%s" % (self.wid, rcv, snd))
        except Exception:
            pass

        print("[worker-%d] connected, subscribing=%d" % (self.wid, len(syms)))
        self._send_subscribe_batches(ws, syms)

    def on_message(self, ws, message):
        # ===== binary protobuf =====
        if isinstance(message, (bytes, bytearray)):
            decoded = decode_limit_depth(message)
            if not decoded:
                return
            sym, bids, asks = decoded

            if sym not in self.symbol_set:
                return

            k1 = "%s_%d_%d" % (sym, PLATFORM, 1)
            k2 = "%s_%d_%d" % (sym, PLATFORM, 2)

            setex = self._p_setex
            ttl = TTL_SECONDS

            # ✅ 存 bytes（jdumps），Redis decode_responses=False
            try:
                setex(k1, ttl, jdumps(bids))
                setex(k2, ttl, jdumps(asks))
                self.pipe_cnt += 2
            except Exception:
                self._reset_pipeline()
                return

            self._flush_if_needed(time.time())
            return

        # ===== text (ack/pong/errors) =====
        try:
            data = jloads(message)
        except Exception:
            return

        msg = str(data.get("msg", ""))
        if msg == "PONG":
            return

        # 订阅失败：剔除失败项
        if "Not Subscribed" in msg:
            failed = extract_failed_symbols(msg)
            if failed:
                before = len(self.symbol_set)
                self.symbol_set.difference_update(failed)
                self.symbols = [s for s in self.symbols if s in self.symbol_set]
                after = len(self.symbol_set)
                print("[worker-%d] subscribe rejected: removed=%d remaining=%d" %
                      (self.wid, before - after, after))
            return

        if "code" in data:
            try:
                code = int(data.get("code", 0))
            except Exception:
                code = 0
            if code != 0:
                print("[worker-%d] ack error: %s" % (self.wid, str(data)[:220]))

    def on_error(self, ws, error):
        print("[worker-%d] ws error: %s" % (self.wid, repr(error)))

    def on_close(self, ws, code, msg):
        print("[worker-%d] ws closed: %s %s" % (self.wid, str(code), str(msg)))
        self._flush()


def run_process(proc_id, buckets):
    """
    每个进程负责一批 bucket（每个 bucket <= 30 symbols），进程内再批量启动 worker 线程。
    """
    print("[proc-%d] pid=%d buckets=%d" % (proc_id, os.getpid(), len(buckets)))

    # ✅ 每个进程单独建 Redis 连接池（不要跨进程共享）
    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)

    workers = []
    wid_base = proc_id * 10000  # 避免 wid 冲突（方便看日志）
    wid = 1

    for i in range(0, len(buckets), START_BATCH):
        batch = buckets[i:i + START_BATCH]
        for b in batch:
            w = MexcWorker(wid_base + wid, b, r)
            w.start()
            workers.append(w)
            wid += 1
        print("[proc-%d] started workers: %d" % (proc_id, len(workers)))
        time.sleep(START_BATCH_SLEEP)

    print("[proc-%d] workers started total: %d" % (proc_id, len(workers)))

    while True:
        time.sleep(60)


def main():
    symbols = load_symbols()
    if not symbols:
        raise RuntimeError("no mexc symbols")

    total = len(symbols)

    # ✅ 按 30/连接 计算需要多少连接
    conn_need = int((total + MAX_SUB_PER_WS - 1) / MAX_SUB_PER_WS)
    conn_num = min(MAX_CONN_CAP, max(1, conn_need))

    print("plan: total=%d, need_conn=%d, conn_num=%d, per_conn<=%d" %
          (total, conn_need, conn_num, MAX_SUB_PER_WS))

    # ✅ 直接按 30 切分为 buckets（每个 bucket <= 30）
    buckets = [chunk for chunk in chunk_list(symbols, MAX_SUB_PER_WS)]

    if len(buckets) > MAX_CONN_CAP:
        print("WARNING: need_conn=%d > MAX_CONN_CAP=%d, will only use first %d buckets!" %
              (len(buckets), MAX_CONN_CAP, MAX_CONN_CAP))
        buckets = buckets[:MAX_CONN_CAP]

    # ✅ 多进程轮询分片 buckets（核心优化）
    proc_num = min(PROC_NUM, max(1, len(buckets)))
    shards = [buckets[i::proc_num] for i in range(proc_num)]

    procs = []
    for pid in range(proc_num):
        p = mp.Process(target=run_process, args=(pid, shards[pid]), daemon=False)
        p.start()
        procs.append(p)
        time.sleep(0.5)  # 进程错峰启动，避免握手风暴

    for p in procs:
        p.join()


if __name__ == "__main__":
    try:
        mp.set_start_method("fork")
    except RuntimeError:
        pass
    main()
