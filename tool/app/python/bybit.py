# coding=utf-8

import os
import time
import ssl
import json
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

# ===== Bybit V5 Spot WS =====
WS_URL = "wss://stream.bybit.com/v5/public/spot"

# ===== 环境配置 =====
MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None, decode_responses=False)

PLATFORM = 16
TTL_SECONDS = 30

# ===== 多进程/多连接参数 =====
PROC_NUM = 6            # 24核：6~10；先6
SYMS_PER_CONN = 150     # 你的 args<=10 已限制订阅消息数量，单连接 symbols 不要太大
WORKER_START_STAGGER = 0.6

# subscribe 限制
SUB_ARGS_LIMIT = 10
SUB_SLEEP = 0.18

# Redis pipeline
PIPELINE_BATCH = 500
PIPELINE_FLUSH_MS = 80

# 假活/心跳
PING_INTERVAL = 20
NO_MSG_RECONNECT_SEC = 60

# socket buffer
SOCKBUF_BYTES = 128 * 1024 * 1024

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_bybit = 1 AND is_enabled = 1
"""

def load_bybit_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    syms = [("{}{}".format(c, q)).upper() for (c, q) in rows]
    print("loaded bybit symbols:", len(syms))
    return syms

def chunk_list(arr, n):
    for i in range(0, len(arr), n):
        yield arr[i:i + n]

class BybitConn(threading.Thread):
    def __init__(self, wid, symbols, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.symbols = symbols[:]
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

            # 假活：超过 NO_MSG_RECONNECT_SEC 没任何消息（包括 pong/数据）就重连
            if self.last_msg_ts and (now - self.last_msg_ts) >= NO_MSG_RECONNECT_SEC:
                self.log("no msg for %ss, force reconnect" % (now - self.last_msg_ts))
                try:
                    ws.close()
                except Exception:
                    pass
                return

            # Bybit JSON ping
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

        topics = ["orderbook.1.%s" % s for s in self.symbols]

        for batch in chunk_list(topics, SUB_ARGS_LIMIT):
            msg = {"op": "subscribe", "args": batch}
            try:
                ws.send(jsend(msg))
            except Exception as e:
                self.log("subscribe send error:", repr(e))
                return
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

        op = data.get("op")
        if op in ("ping", "pong", "subscribe"):
            return

        topic = data.get("topic")
        payload = data.get("data")
        if not topic or not topic.startswith("orderbook.1.") or not isinstance(payload, dict):
            return

        sym = payload.get("s") or topic.split(".")[-1]
        sym = str(sym).upper()

        bids = payload.get("b") or []
        asks = payload.get("a") or []
        if not bids and not asks:
            return

        # 只取 1 档（仍存数组）
        bid1 = [bids[0]] if isinstance(bids, list) and bids else []
        ask1 = [asks[0]] if isinstance(asks, list) and asks else []

        if not bid1 and not ask1:
            return

        ttl = TTL_SECONDS
        platform = PLATFORM

        try:
            if bid1:
                self._p_setex("%s_%d_1" % (sym, platform), ttl, jdumps(bid1))
                self.pipe_cnt += 1
            if ask1:
                self._p_setex("%s_%d_2" % (sym, platform), ttl, jdumps(ask1))
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
                    on_close=self.on_close
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

# ========= multiprocess =========
def run_process(pid, symbols):
    print("[proc-%d] pid=%d symbols=%d" % (pid, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)

    conns = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        c = BybitConn("%d-%d" % (pid, wid), chunk, r)
        c.start()
        conns.append(c)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] conns started: %d" % (pid, len(conns)))
    while True:
        time.sleep(60)

def main():
    syms = load_bybit_symbols()
    if not syms:
        raise RuntimeError("no bybit symbols (currency_match.is_bybit=1?)")

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
