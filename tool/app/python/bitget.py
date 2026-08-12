# coding=utf-8


import os
import time
import ssl
import json
import socket
import threading
import multiprocessing as mp

import redis
import MySQLdb as mdb
import websocket

# ===== 可选：更快 JSON =====
try:
    import orjson
    def jloads(b):
        if isinstance(b, (bytes, bytearray)):
            return orjson.loads(b)
        return orjson.loads(b.encode("utf-8"))
    def jdumps(obj):  # bytes (for redis)
        return orjson.dumps(obj)
    def jsend(obj):   # str (for ws send)
        return orjson.dumps(obj).decode("utf-8")
except Exception:
    def jloads(b):
        if isinstance(b, (bytes, bytearray)):
            b = b.decode("utf-8", errors="ignore")
        return json.loads(b)
    def jdumps(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    def jsend(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)

# ===== 配置 =====
WS_URL = "wss://ws.bitget.com/v2/ws/public"

MYSQL_CFG = dict(
    host='127.0.0.1', port=3306,
    user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''),
    db='tool', charset='utf8'
)

REDIS_CFG = dict(
    host='127.0.0.1', port=6379,
    db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None,
    decode_responses=False
)

PLATFORM = 15
TTL_SECONDS = 30

# ===== 多进程/多连接参数 =====
PROC_NUM = 6            # 24 核建议 6~10；先 6
SYMS_PER_CONN = 40      # 你原设定 OK（建议 <50）
WORKER_START_STAGGER = 0.5

# 订阅限速（Bitget 常见限制：10 msg/sec 左右，你原逻辑保留并更稳）
SUB_BATCH = 10
SUB_SLEEP = 1.2

# Redis pipeline
PIPELINE_BATCH = 300          # 命令条数（setex 次数）
PIPELINE_FLUSH_MS = 80        # 时间阈值 flush（降低尾延迟）

# 心跳/假活
PING_INTERVAL = 25            # 每 25s 发一次 "ping"
PONG_TIMEOUT = 60             # 超过 60s 没收到 pong 强制重连

# socket buffer（配合 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_bitget = 1 AND is_enabled = 1
"""

# ========= MySQL =========
def load_bitget_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    # Bitget Spot instId 常见为 BTCUSDT（无 -）
    syms = [("{}{}".format(c, q)).upper() for (c, q) in rows]
    print("loaded bitget symbols:", len(syms))
    return syms

def chunk_list(arr, n):
    for i in range(0, len(arr), n):
        yield arr[i:i + n]

# ========= Worker =========
class BitgetConn(threading.Thread):
    """
    一个线程 = 一个 ws 连接，订阅一组 symbols
    """
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
        self.last_pong_ts = 0

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

    # -------- heartbeat --------
    def _start_hb(self):
        self._hb_stop.clear()
        t = threading.Thread(target=self._hb_loop, daemon=True)
        t.start()

    def _stop_hb(self):
        self._hb_stop.set()

    def _hb_loop(self):
        # 给连接一点时间
        time.sleep(1)
        while not self._hb_stop.is_set():
            ws = self.ws
            if not ws or not ws.sock or not ws.sock.connected:
                return

            # 发 ping（Bitget 要求应用层 ping/pong）
            try:
                ws.send("ping")
            except Exception:
                return

            # pong 超时重连
            if self.last_pong_ts and (time.time() - self.last_pong_ts) > PONG_TIMEOUT:
                self.log("pong timeout, force reconnect")
                try:
                    ws.close()
                except Exception:
                    pass
                return

            self._hb_stop.wait(PING_INTERVAL)

    # -------- ws callbacks --------
    def on_open(self, ws):
        self.last_pong_ts = time.time()

        # socket buffer（可注释）
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            self.log("SO_RCVBUF=%s SO_SNDBUF=%s" % (rcv, snd))
        except Exception:
            pass

        self.log("connected, subscribing symbols=%d" % len(self.symbols))

        # 先启动心跳，避免订阅耗时导致 ping 问题
        self._start_hb()

        # 分批订阅（每批 SUB_BATCH，批间 SUB_SLEEP）
        for batch in chunk_list(self.symbols, SUB_BATCH):
            args = [{"instType": "SPOT", "channel": "books5", "instId": sym} for sym in batch]
            sub_msg = {"op": "subscribe", "args": args}
            try:
                ws.send(jsend(sub_msg))
            except Exception as e:
                self.log("subscribe send error:", repr(e))
                return
            time.sleep(SUB_SLEEP)

        self.log("subscribe sent")

    def on_message(self, ws, message):
        # pong 可能是纯字符串
        if message == "pong" or (isinstance(message, (bytes, bytearray)) and message == b"pong"):
            self.last_pong_ts = time.time()
            return

        # 解析 json
        try:
            data = jloads(message)
        except Exception:
            return

        if not isinstance(data, dict):
            return

        # 订阅确认/心跳/错误
        ev = data.get("event")
        if ev in ("subscribe", "unsubscribe"):
            return
        if ev == "error":
            # 打印少一点，避免 IO 卡住
            self.log("event error:", str(data)[:220])
            return

        arg = data.get("arg")
        arr = data.get("data")

        if not arg or not arr or not isinstance(arr, list):
            return

        if arg.get("instType") != "SPOT" or arg.get("channel") != "books5":
            return

        inst_id = arg.get("instId")
        if not inst_id:
            return

        row = arr[0] if arr else None
        if not isinstance(row, dict):
            return

        bids = row.get("bids") or []
        asks = row.get("asks") or []
        if not bids and not asks:
            return

        smb_up = inst_id.upper()
        ttl = TTL_SECONDS
        platform = PLATFORM

        try:
            if bids:
                self._p_setex("%s_%d_1" % (smb_up, platform), ttl, jdumps(bids))
                self.pipe_cnt += 1
            if asks:
                self._p_setex("%s_%d_2" % (smb_up, platform), ttl, jdumps(asks))
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

    # -------- run loop --------
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

# ========= multiprocess =========
def run_process(pid, symbols):
    print("[proc-%d] pid=%d symbols=%d" % (pid, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG)
    r = redis.Redis(connection_pool=pool)

    conns = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        c = BitgetConn("%d-%d" % (pid, wid), chunk, r)
        c.start()
        conns.append(c)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] conns started: %d" % (pid, len(conns)))
    while True:
        time.sleep(60)

def main():
    syms = load_bitget_symbols()
    if not syms:
        raise RuntimeError("no bitget symbols")

    # 轮询分片到进程，尽量均匀
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
