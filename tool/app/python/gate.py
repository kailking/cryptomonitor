# coding=utf-8
import time
import ssl
import json
import threading
import websocket
import redis
import MySQLdb as mdb
import multiprocessing as mp
import os
import socket

# ===== 可选：更快 JSON =====
try:
    import orjson
    def jloads(s): return orjson.loads(s)
    def jdumps(obj): return orjson.dumps(obj)
except Exception:
    def jloads(s): return json.loads(s)
    def jdumps(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")

# ===== 配置（与你原一致）=====
WS_URL = "wss://api.gateio.ws/ws/v4/"

MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None)

PLATFORM = 4
TTL_SECONDS = 30
LEVELS = "5"
INTERVAL = "1000ms"

# ===== 多连接参数（重点调这里）=====
# 你现在 symbols 很多，建议每个连接减少订阅量，配合多进程扛吞吐
SYMS_PER_CONN = 100     # 每个连接订多少交易对（建议 60~120）
SUB_BATCH = 20          # 每批发送多少条 subscribe
SUB_SLEEP = 0.25        # 每批之间 sleep，避免限流（0.2~0.5）
WORKER_START_STAGGER = 0.6  # 启动连接错峰

# Redis pipeline
PIPELINE_BATCH = 300

# Socket buffer（你已经把 sysctl rmem/wmem_max 调大了，这里才会生效）
SOCKBUF_BYTES = 128 * 1024 * 1024  # 128MB（getsockopt 可能显示 256MB 属正常）

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_gate = 1 AND is_enabled = 1
"""

def load_gate_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    syms = [f"{c}_{q}" for c, q in rows]
    print("loaded gate symbols:", len(syms))
    return syms

class GateWorker(threading.Thread):
    def __init__(self, wid, symbols, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.symbols = symbols
        self.r = redis_client
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

    def reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self.pipe_cnt = 0

    def run(self):
        websocket.enableTrace(False)

        while True:
            try:
                ws = websocket.WebSocketApp(
                    WS_URL,
                    on_open=self.on_open,
                    on_message=self.on_message,
                    on_error=self.on_error,
                    on_close=self.on_close,
                )
                ws.run_forever(
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
                print(f"[worker-{self.wid}] exception:", e)
            time.sleep(2)

    def on_open(self, ws):
        # 验证 socket buffer 是否真的生效
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            print(f"[worker-{self.wid}] SO_RCVBUF={rcv} SO_SNDBUF={snd}")
        except Exception:
            pass

        total = len(self.symbols)
        print(f"[worker-{self.wid}] connected, subscribing {total} symbols")

        now = int(time.time())
        for i in range(0, total, SUB_BATCH):
            batch = self.symbols[i:i + SUB_BATCH]
            for sym in batch:
                msg = {
                    "time": now,
                    "channel": "spot.order_book",
                    "event": "subscribe",
                    "payload": [sym, LEVELS, INTERVAL]
                }
                ws.send(json.dumps(msg))
            time.sleep(SUB_SLEEP)

        print(f"[worker-{self.wid}] subscribe sent")

    def on_message(self, ws, message):
        try:
            data = jloads(message)
        except Exception:
            return

        result = data.get("result")
        if not result or not isinstance(result, dict):
            return

        smb = result.get("s")
        if not smb:
            return

        smb2 = smb.replace("_", "", 1)   # BTC_USDT -> BTCUSDT
        smb_up = smb2.upper()
        
        bids = result.get("bids")
        asks = result.get("asks")
        # if smb2 == 'DYMUSDT':
        #     print(asks)
        setex = self._p_setex
        ttl = TTL_SECONDS
        platform = PLATFORM
        
        if bids:
            key1 = f"{smb_up}_{platform}_1"
            setex(key1, ttl, jdumps(bids))
            self.pipe_cnt += 1

        if asks:
            key2 = f"{smb_up}_{platform}_2"
            setex(key2, ttl, jdumps(asks))
            self.pipe_cnt += 1

        if self.pipe_cnt >= PIPELINE_BATCH:
            try:
                self._p_execute()
            except Exception:
                self.reset_pipeline()
            else:
                self.pipe_cnt = 0

    def on_error(self, ws, error):
        print(f"[worker-{self.wid}] ws error:", error)

    def on_close(self, ws, code, msg):
        print(f"[worker-{self.wid}] ws closed:", code, msg)
        try:
            if self.pipe_cnt > 0:
                self._p_execute()
        except Exception:
            pass
        self.pipe_cnt = 0

def run_process(proc_id, symbols):
    """
    每个进程负责一部分 symbols；进程内再按 SYMS_PER_CONN 切成多个 websocket 线程连接
    注意：Redis 连接池必须在子进程内创建，不要跨进程共享
    """
    print(f"[proc-{proc_id}] pid={os.getpid()} symbols={len(symbols)}")

    pool = redis.ConnectionPool(**REDIS_CFG, decode_responses=False)
    r = redis.Redis(connection_pool=pool)

    workers = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        w = GateWorker(f"{proc_id}-{wid}", chunk, r)
        w.start()
        workers.append(w)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print(f"[proc-{proc_id}] workers started: {len(workers)}")

    while True:
        time.sleep(60)

def main():
    syms = load_gate_symbols()
    if not syms:
        raise RuntimeError("no gate symbols")

    # ===== 多进程分片参数 =====
    PROC_NUM = 8  # 24 核机器建议先从 8 起步；不够再加到 10/12

    # 轮询切片：让每个进程分到的活跃币更均匀
    shards = [syms[i::PROC_NUM] for i in range(PROC_NUM)]

    procs = []
    for pid in range(PROC_NUM):
        p = mp.Process(target=run_process, args=(pid, shards[pid]), daemon=False)
        p.start()
        procs.append(p)
        time.sleep(0.5)  # 进程错峰启动，避免订阅风暴

    for p in procs:
        p.join()

if __name__ == "__main__":
    # Linux 默认 fork；显式设置可避免某些环境差异
    try:
        mp.set_start_method("fork")
    except RuntimeError:
        pass
    main()
