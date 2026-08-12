# coding=utf-8
"""
bitmart.py（compress WS + spot/depth5）优化版 —— 保持你现有业务不变：
- 订阅 spot/depth5
- Redis key: SYMBOL(去掉第一个_)_PLATFORM_1/2
- TTL 15 秒

优化点：
1) 多进程分片（PROC_NUM）+ 进程内多连接（SYMS_PER_CONN），避免单进程/单连接扛太多导致延迟
2) 解压更稳：raw deflate / zlib wrapper / gzip 三种都试
3) 心跳更稳：固定 10s 发 ping；同时做“假活检测”（NO_MSG_RECONNECT_SEC 无任何消息就重连）
4) Redis pipeline：条数 + 时间阈值 flush（降低尾延迟）
5) socket buffer + TCP_NODELAY
6) 解析更鲁棒：处理 table=spot/depth5；兼容 symbol 字段缺失；过滤 subscribe/错误
"""

import os
import time
import ssl
import json
import zlib
import gzip
import socket
import threading
import multiprocessing as mp

import websocket
import redis
import MySQLdb as mdb

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

# ===================== 配置 =====================
WS_URL = "wss://ws-manager-compress.bitmart.com/api?protocol=1.1"

MYSQL_CFG = dict(
    host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''),
    db='tool', charset='utf8'
)

REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None, decode_responses=False)

PLATFORM = 17
TTL_SECONDS = 30

DEPTH_CHANNEL = "spot/depth5"

# ===== 多进程/多连接参数 =====
PROC_NUM = 6            # 24核：6~10 先6
SYMS_PER_CONN = 90      # 建议 <=100；先90更稳
ARGS_BATCH = 20
SUB_SLEEP = 0.22
WORKER_START_STAGGER = 0.6

# Redis pipeline
PIPELINE_BATCH = 500
PIPELINE_FLUSH_MS = 80

# 心跳/假活
PING_INTERVAL = 10
NO_MSG_RECONNECT_SEC = 60

# socket buffer
SOCKBUF_BYTES = 128 * 1024 * 1024

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_bitmart = 1 AND is_enabled = 1
"""

# ===================== 工具函数 =====================
def load_bitmart_symbols():
    """
    BitMart depth channel 的 symbol 格式是 BTC_USDT（中间下划线）
    """
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    syms = []
    for c, q in rows:
        c = str(c).upper().strip()
        q = str(q).upper().strip()
        if c and q:
            syms.append("%s_%s" % (c, q))

    print("loaded bitmart symbols:", len(syms))
    return syms

def chunk_list(arr, n):
    for i in range(0, len(arr), n):
        yield arr[i:i + n]

def inflate_ws_payload(payload):
    """
    BitMart compress WS：常见 binary。
    依次尝试：
    - raw deflate (wbits=-MAX_WBITS)
    - zlib wrapper
    - gzip
    """
    if payload is None:
        return None

    if isinstance(payload, str):
        return payload

    if not isinstance(payload, (bytes, bytearray)):
        return None

    data = bytes(payload)
    if not data:
        return ""

    # 1) raw deflate
    try:
        out = zlib.decompress(data, -zlib.MAX_WBITS)
        return out.decode("utf-8", "ignore")
    except Exception:
        pass

    # 2) zlib wrapper
    try:
        out = zlib.decompress(data)
        return out.decode("utf-8", "ignore")
    except Exception:
        pass

    # 3) gzip
    try:
        out = gzip.decompress(data)
        return out.decode("utf-8", "ignore")
    except Exception:
        return None

def to_redis_symbol(sym):
    # BTC_USDT -> BTCUSDT（只去掉第一个 _）
    return sym.replace("_", "", 1).upper()

# ===================== Worker =====================
class BitmartConn(threading.Thread):
    def __init__(self, wid, symbols, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.symbols = symbols[:]
        self.r = redis_client

        # pipeline
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self._last_flush = time.time()
        self._flush_interval = PIPELINE_FLUSH_MS / 1000.0 if PIPELINE_FLUSH_MS and PIPELINE_FLUSH_MS > 0 else 0.0

        # ws / liveness
        self.ws = None
        self._hb_stop = threading.Event()
        self.last_msg_ts = 0

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

    # -------- heartbeat / fake-alive --------
    def _start_hb(self):
        self._hb_stop.clear()
        t = threading.Thread(target=self._hb_loop, daemon=True)
        t.start()

    def _stop_hb(self):
        self._hb_stop.set()

    def _hb_loop(self):
        time.sleep(1)
        while not self._hb_stop.is_set():
            ws = self.ws
            if not ws or not ws.sock or not ws.sock.connected:
                return

            now = int(time.time())

            # 假活检测：超过 NO_MSG_RECONNECT_SEC 没任何消息就重连
            if self.last_msg_ts and (now - self.last_msg_ts) >= NO_MSG_RECONNECT_SEC:
                self.log("no msg for %ss, force reconnect" % (now - self.last_msg_ts))
                try:
                    ws.close()
                except Exception:
                    pass
                return

            # ping：优先发文本 "ping"（最通用）
            try:
                ws.send("ping")
            except Exception:
                # fallback：有的实现用 {"op":"ping"}
                try:
                    ws.send('{"op":"ping"}')
                except Exception:
                    return

            self._hb_stop.wait(PING_INTERVAL)

    # -------- ws callbacks --------
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

        self.log("connected, subscribing symbols=%d" % len(self.symbols))

        # 先开心跳
        self._start_hb()

        args = ["%s:%s" % (DEPTH_CHANNEL, s) for s in self.symbols]

        for batch in chunk_list(args, ARGS_BATCH):
            sub_msg = {"op": "subscribe", "args": batch}
            try:
                ws.send(jsend(sub_msg))
            except Exception as e:
                self.log("subscribe send error:", repr(e))
                return
            time.sleep(SUB_SLEEP)

        self.log("subscribe sent")

    def on_message(self, ws, message):
        self.last_msg_ts = int(time.time())

        txt = inflate_ws_payload(message)
        if not txt:
            return

        # pong
        if txt == "pong" or txt == "PONG":
            return

        try:
            data = jloads(txt)
        except Exception:
            return

        if not isinstance(data, dict):
            return

        # 订阅确认 / 错误
        if data.get("event") == "subscribe":
            return
        if data.get("event") == "error" or data.get("errorCode") or data.get("errorMessage"):
            self.log("server err:", str(data)[:220])
            return

        # 深度推送：{"table":"spot/depth5","data":[{"symbol":"ETH_USDT","asks":[...],"bids":[...]}]}
        if data.get("table") != DEPTH_CHANNEL:
            return

        items = data.get("data")
        if not items or not isinstance(items, list):
            return

        ttl = TTL_SECONDS
        platform = PLATFORM

        for row in items:
            if not isinstance(row, dict):
                continue
            sym = row.get("symbol")
            if not sym:
                continue

            bids = row.get("bids") or []
            asks = row.get("asks") or []
            if not bids and not asks:
                continue

            smb = to_redis_symbol(sym)

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
        self._stop_hb()
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

# ===================== multiprocess =====================
def run_process(pid, symbols):
    print("[proc-%d] pid=%d symbols=%d" % (pid, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)

    conns = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        c = BitmartConn("%d-%d" % (pid, wid), chunk, r)
        c.start()
        conns.append(c)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] conns started: %d" % (pid, len(conns)))
    while True:
        time.sleep(60)

def main():
    syms = load_bitmart_symbols()
    if not syms:
        raise RuntimeError("no bitmart symbols")

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
