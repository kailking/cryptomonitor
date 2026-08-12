# coding=utf-8


import time
import ssl
import json
import threading
import random
import string
import socket
import os
import multiprocessing as mp

import redis
import MySQLdb as mdb
import websocket
import requests

# ===== 可选：更快 JSON =====
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
MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None, decode_responses=False)

PLATFORM = 8
TTL_SECONDS = 30

# 多进程
PROC_NUM = 8  # 24 核建议 8 起步；不够再加 10/12

# 单进程内连接分片
SYMS_PER_CONN = 120

# 订阅节流
SUB_BATCH = 50
SUB_SLEEP = 0.20
WORKER_START_STAGGER = 0.6

# Redis pipeline
PIPELINE_BATCH = 400
PIPELINE_FLUSH_MS = 80

# 假活检测
NO_MSG_RECONNECT_SEC = 60

# socket buffer（配合你已调大的 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024

# 黑名单持久化 key（Redis set）
BAD_SYMBOLS_KEY = b"kucoin_bad_symbols_level2Depth5"

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_kucoin = 1 AND is_enabled = 1
"""

KUCOIN_BULLET_PUBLIC = "https://api.kucoin.com/api/v1/bullet-public"

# ================== 工具函数 ==================
def load_kucoin_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    # KuCoin topic 用 BTC-USDT
    syms = [("%s-%s" % (c, q)).upper() for (c, q) in rows]
    print("loaded kucoin symbols:", len(syms))
    return syms

def to_redis_symbol(sym):
    # BTC-USDT -> BTCUSDT
    return sym.replace("-", "", 1).upper()

def rand_connect_id(n=12):
    return "".join(random.choice(string.ascii_letters + string.digits) for _ in range(n))

def get_bullet_public(session):
    resp = session.post(KUCOIN_BULLET_PUBLIC, timeout=10)
    data = resp.json()
    if data.get("code") != "200000":
        raise RuntimeError("bullet-public failed: %s" % str(data)[:200])

    d = data.get("data") or {}
    token = d.get("token")
    servers = d.get("instanceServers") or []
    if not token or not servers:
        raise RuntimeError("bullet-public missing token/servers: %s" % str(data)[:200])

    s0 = servers[0]
    endpoint = s0.get("endpoint")
    ping_interval_ms = int(s0.get("pingInterval", 18000))
    ping_timeout_ms = int(s0.get("pingTimeout", 10000))
    if not endpoint:
        raise RuntimeError("bullet-public missing endpoint: %s" % str(s0)[:200])

    return token, endpoint, ping_interval_ms, ping_timeout_ms

# ================== Worker ==================
class KucoinWorker(threading.Thread):
    def __init__(self, wid, symbols, redis_client, http_session):
        super().__init__(daemon=True)
        self.wid = wid
        self.symbols = symbols[:]  # list[str] BTC-USDT

        self.r = redis_client
        self.session = http_session

        # pipeline
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

        self._last_flush = time.time()
        self._flush_interval = PIPELINE_FLUSH_MS / 1000.0 if PIPELINE_FLUSH_MS and PIPELINE_FLUSH_MS > 0 else 0.0

        # ws
        self.ws = None
        self.stop_flag = False
        self.last_msg_ts = 0

        # ping
        self.ping_interval_ms = 18000
        self.ping_timeout_ms = 10000
        self._hb_stop = threading.Event()
        self._hb_thread = None

        # blacklist (in-memory) + persisted in redis set
        self.bad_symbols = set()
        self._load_bad_symbols()

        # batch subscribe tracking: id -> [symbols]
        self._sub_pending = {}
        self._sub_retry_q = []
        self._sub_lock = threading.Lock()

    # ---------- log / helpers ----------
    def log(self, *a):
        print("[worker-%s]" % str(self.wid), *a)

    def _load_bad_symbols(self):
        try:
            items = self.r.smembers(BAD_SYMBOLS_KEY)
            if items:
                for x in items:
                    # redis decode_responses=False: x is bytes
                    if isinstance(x, (bytes, bytearray)):
                        self.bad_symbols.add(x.decode("utf-8", "ignore").upper())
                    else:
                        self.bad_symbols.add(str(x).upper())
                self.log("loaded bad_symbols:", len(self.bad_symbols))
        except Exception:
            pass

    def _add_bad_symbol(self, sym):
        sym = str(sym).upper()
        if sym in self.bad_symbols:
            return
        self.bad_symbols.add(sym)
        try:
            self.r.sadd(BAD_SYMBOLS_KEY, sym.encode("utf-8"))
        except Exception:
            pass
        self.log("blacklist symbol:", sym)

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
        if self._hb_thread and self._hb_thread.is_alive():
            return
        t = threading.Thread(target=self._heartbeat_loop, daemon=True)
        self._hb_thread = t
        t.start()

    def _stop_heartbeat(self):
        self._hb_stop.set()

    def _heartbeat_loop(self):
        time.sleep(1)

        while not self._hb_stop.is_set():
            ws = self.ws
            if not ws or not ws.sock or not ws.sock.connected:
                return

            now = int(time.time())

            # 假活检测
            if self.last_msg_ts and (now - self.last_msg_ts) >= NO_MSG_RECONNECT_SEC:
                self.log("no msg for %ss, force reconnect" % (now - self.last_msg_ts))
                try:
                    ws.close()
                except Exception:
                    pass
                return

            # KuCoin ping
            try:
                ws.send(jsend({"id": str(int(time.time() * 1000)), "type": "ping"}))
            except Exception:
                return

            # 更保守：pingInterval 的一半，限制 [5, 10] 秒
            interval = max(5, min(10, int((self.ping_interval_ms / 1000.0) * 0.5)))
            self._hb_stop.wait(interval)

    # ---------- subscribe logic ----------
    def _extract_symbols_from_topic_error(self, msg):
        """
        从错误字符串里提取 symbols（可能是单个或逗号分隔）
        'topic /spotMarket/level2Depth5:BTC-USDT,TUSD-USDT ...'
        """
        s = str(msg.get("data", ""))
        idx = s.find("/spotMarket/level2Depth5:")
        if idx < 0:
            return []
        tail = s[idx + len("/spotMarket/level2Depth5:"):].strip()
        if not tail:
            return []
        tail = tail.split(" ", 1)[0]  # 截断到空格前
        return [x.strip().upper() for x in tail.split(",") if x.strip()]

    def _enqueue_single_retry(self, symbols):
        if not symbols:
            return
        with self._sub_lock:
            for s in symbols:
                s = str(s).upper()
                if not s:
                    continue
                if s in self.bad_symbols:
                    continue
                self._sub_retry_q.append(s)

    def _drain_single_subscribe(self, ws, max_once=300):
        """
        对 retry 队列逐个订阅，定位坏 symbol。
        注意：单个订阅也可能触发风控，所以要节流。
        """
        sent = 0
        while sent < max_once:
            with self._sub_lock:
                if not self._sub_retry_q:
                    return
                sym = self._sub_retry_q.pop(0)

            if not sym or sym in self.bad_symbols:
                continue

            sub = {
                "id": "%s-single-%d" % (str(self.wid), int(time.time() * 1000)),
                "type": "subscribe",
                "topic": "/spotMarket/level2Depth5:" + sym,
                "privateChannel": False,
                "response": True
            }
            try:
                ws.send(jsend(sub))
                sent += 1
            except Exception:
                return

            # 单个订阅温和一点
            time.sleep(max(0.08, SUB_SLEEP))

    def _handle_batch_topic_error(self, ws, msg):
        """
        只有遇到“topic /spotMarket/level2Depth5:... (code=400)”才触发：
        - 拿到这一批的 symbols
        - 入队，开始逐个订阅定位坏币
        """
        bad_batch = self._extract_symbols_from_topic_error(msg)

        # 如果 error 不完整，用 msg['id'] 找到原始 batch
        mid = str(msg.get("id", ""))
        if mid:
            with self._sub_lock:
                pending = self._sub_pending.get(mid)
            if pending:
                # 以 pending 为准（更可靠）
                bad_batch = [x.upper() for x in pending]

        if not bad_batch:
            return

        # 入队逐个订阅
        self._enqueue_single_retry(bad_batch)

        # 立刻开始逐个订阅定位
        self._drain_single_subscribe(ws)

    # ---------- ws callbacks ----------
    def on_open(self, ws):
        self.last_msg_ts = int(time.time())

        # 打印 sockbuf（可注释）
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            self.log("SO_RCVBUF=%s SO_SNDBUF=%s" % (rcv, snd))
        except Exception:
            pass

        # 先启动心跳，确保不会因为订阅耗时而错过 ping
        self._start_heartbeat()

        # 过滤黑名单
        syms = [s for s in self.symbols if str(s).upper() not in self.bad_symbols]
        self.symbols = syms

        total = len(syms)
        self.log("connected, subscribing", total, "symbols (batch-first)")

        sid = 1
        for i in range(0, total, SUB_BATCH):
            batch = syms[i:i + SUB_BATCH]
            if not batch:
                continue

            topic = "/spotMarket/level2Depth5:" + ",".join(batch)
            sub_id = "%s-%d" % (str(self.wid), sid)

            with self._sub_lock:
                self._sub_pending[sub_id] = batch[:]

            sub = {
                "id": sub_id,
                "type": "subscribe",
                "topic": topic,
                "privateChannel": False,
                "response": True
            }

            try:
                ws.send(jsend(sub))
            except Exception as e:
                self.log("subscribe send error:", repr(e), "topic_head=", topic[:120])

            sid += 1
            time.sleep(SUB_SLEEP)

        self.log("subscribe sent (batch-first)")

    def on_message(self, ws, message):
        try:
            msg = jloads(message)
        except Exception:
            return

        self.last_msg_ts = int(time.time())

        t = msg.get("type")

        # welcome/pong/ack
        if t in ("welcome", "pong", "ack"):
            return

        # subscribe response（response=True 才有）
        if t == "subscribe":
            # 成功订阅会返回 {type:"subscribe", topic:"...", ...}
            # 这里不做处理即可
            return

        # error：关键处理
        if t == "error":
            data_str = str(msg.get("data", ""))
            code = msg.get("code")
            # 只对这种 “topic /spotMarket/level2Depth5:...” 错误做降级定位
            if code == 400 and "topic " in data_str and "/spotMarket/level2Depth5:" in data_str:
                self.log("batch subscribe error => fallback single:", data_str[:220])

                # 如果 topic 里只有一个 symbol，说明单独订阅也失败：直接拉黑
                syms_in_err = self._extract_symbols_from_topic_error(msg)
                if len(syms_in_err) == 1:
                    self._add_bad_symbol(syms_in_err[0])
                else:
                    self._handle_batch_topic_error(ws, msg)
            else:
                self.log("server error:", str(msg)[:220])
            return

        # 只处理真正数据消息
        if t != "message":
            return
        if msg.get("subject") != "level2":
            return

        topic = msg.get("topic", "")
        if not topic.startswith("/spotMarket/level2Depth5:"):
            return

        try:
            _, sym = topic.split(":", 1)
        except Exception:
            return
        sym = str(sym).upper()

        # 如果是坏币，忽略
        if sym in self.bad_symbols:
            return

        data = msg.get("data") or {}
        bids = data.get("bids")
        asks = data.get("asks")
        if not bids and not asks:
            return

        smb = to_redis_symbol(sym)
        ttl = TTL_SECONDS
        platform = PLATFORM

        try:
            if bids:
                self._p_setex("%s_%d_1" % (smb, platform), ttl, jdumps(bids))
                self.pipe_cnt += 1
            if asks:
                self._p_setex("%s_%d_2" % (smb, platform), ttl, jdumps(asks))
                self.pipe_cnt += 1
        except Exception:
            self.reset_pipeline()
            return

        self.flush_if_needed(time.time())

    def on_error(self, ws, error):
        self.log("ws error:", repr(error))

    def on_close(self, ws, code, msg):
        self.log("ws closed:", code, msg)
        self._stop_heartbeat()
        try:
            if self.pipe_cnt > 0:
                self._p_execute()
        except Exception:
            pass
        self.pipe_cnt = 0

    # ---------- main loop ----------
    def run(self):
        websocket.enableTrace(False)

        while not self.stop_flag:
            try:
                token, endpoint, pi_ms, pt_ms = get_bullet_public(self.session)
                self.ping_interval_ms = pi_ms
                self.ping_timeout_ms = pt_ms

                ws_url = "%s?token=%s&connectId=%s" % (endpoint, token, rand_connect_id())
                self.last_msg_ts = int(time.time())

                self.log("connecting ... pingInterval(ms)=", self.ping_interval_ms, "syms=", len(self.symbols))

                self.ws = websocket.WebSocketApp(
                    ws_url,
                    on_open=self.on_open,
                    on_message=self.on_message,
                    on_error=self.on_error,
                    on_close=self.on_close,
                )

                self.ws.run_forever(
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

# ================== multiprocess runner ==================
def run_process(pid, symbols):
    print("[proc-%d] pid=%d symbols=%d" % (pid, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)
    session = requests.Session()

    workers = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        w = KucoinWorker("%d-%d" % (pid, wid), chunk, r, session)
        w.start()
        workers.append(w)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] workers started: %d" % (pid, len(workers)))
    while True:
        time.sleep(60)

# ================== main ==================
def main():
    syms = load_kucoin_symbols()
    if not syms:
        raise RuntimeError("no kucoin symbols")

    proc_num = min(PROC_NUM, max(1, len(syms)))
    shards = [syms[i::proc_num] for i in range(proc_num)]

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
