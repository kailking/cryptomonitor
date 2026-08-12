# coding=utf-8
import time
import ssl
import json
import gzip
import io
import threading
import multiprocessing as mp
import os
import socket

import redis
import MySQLdb as mdb
import websocket

# ===== 更快 json（可选）=====
try:
    import orjson
    def jloads(b): return orjson.loads(b)
    def jdumps(obj): return orjson.dumps(obj)
    def jsend(obj): return orjson.dumps(obj).decode("utf-8")
except Exception:
    def jloads(b): return json.loads(b)
    def jdumps(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    def jsend(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)

# ===== 配置（与你 binance.py 一样）=====
MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None)

PLATFORM = 1
TTL_SECONDS = 30
LEVELS = 5

# 全量深度用 /ws + depth.step0
WS_URL = "wss://api.huobi.pro/ws"

# ===== 多进程分片参数（核心优化点）=====
PROC_NUM = 8  # 24 核机器建议 8 起步；不够再加 10/12

# ===== 单进程内多连接参数 =====
SYMS_PER_CONN = 100        # 每条 websocket 连接订阅多少 symbol（建议 60~120）
SUB_BATCH = 20             # 每批订阅多少个 topic
SUB_SLEEP = 0.25           # 每批订阅间隔，避免限流（0.2~0.5）
WORKER_START_STAGGER = 0.6 # 每条连接错峰启动

# Redis pipeline
PIPELINE_BATCH = 300       # 每累计多少条 setex 才 execute（注意：一次消息通常2条 setex）
# 可选：加一个时间阈值，避免低频币对也一直等（不想要可设为 0）
PIPELINE_FLUSH_MS = 80     # >=0；建议 50~150ms

# Socket buffer（配合你已调大的 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024  # 128MB（getsockopt 可能显示 256MB 属正常）

# 你库里火币字段名不确定：按实际改
SYMBOL_SQL = "SELECT symbol FROM currency_match WHERE is_huobi=1 AND is_enabled=1"

# ==========================


def gunzip_if_needed(payload):
    """
    Huobi WS 返回通常是 gzip bytes。
    这里尽量走 gzip.decompress（最快），失败再 fallback。
    """
    if isinstance(payload, (bytes, bytearray)):
        try:
            return gzip.decompress(payload)
        except Exception:
            try:
                with gzip.GzipFile(fileobj=io.BytesIO(payload)) as f:
                    return f.read()
            except Exception:
                return payload
    return payload.encode("utf-8")


class HTXWorker(threading.Thread):
    def __init__(self, wid, symbols, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.symbols = symbols
        self.r = redis_client

        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

        self._last_flush = time.time()
        self._flush_interval = PIPELINE_FLUSH_MS / 1000.0 if PIPELINE_FLUSH_MS and PIPELINE_FLUSH_MS > 0 else 0.0

    def reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self.pipe_cnt = 0
        self._last_flush = time.time()

    def run(self):
        websocket.enableTrace(False)

        while True:
            try:
                ws = websocket.WebSocketApp(
                    WS_URL,
                    on_open=self.on_open,
                    on_message=self.on_message,
                    on_error=self.on_error,
                    on_close=self.on_close
                )

                # 关键：禁用 websocket-client 的 ping_interval
                # 只使用火币 JSON ping/pong，避免 “ping/pong timed out”
                ws.run_forever(
                    ping_interval=None,
                    ping_timeout=None,
                    sslopt={"cert_reqs": ssl.CERT_NONE},
                    sockopt=[
                        (socket.SOL_SOCKET, socket.SO_RCVBUF, SOCKBUF_BYTES),
                        (socket.SOL_SOCKET, socket.SO_SNDBUF, SOCKBUF_BYTES),
                        (socket.IPPROTO_TCP, socket.TCP_NODELAY, 1),
                    ],
                )
            except Exception as e:
                print("[worker-%s] exception: %s" % (self.wid, e))
            time.sleep(2)

    def on_open(self, ws):
        # 验证 socket buffer 是否生效
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            print("[worker-%s] SO_RCVBUF=%s SO_SNDBUF=%s" % (self.wid, rcv, snd))
        except Exception:
            pass

        total = len(self.symbols)
        print("[worker-%s] connected, subscribing %d symbols" % (self.wid, total))

        # 订阅 depth.step0，然后本地 slice 前 5 档
        # 改为分批订阅，减少 sleep 次数，效率更高且更不容易触发限流
        sub_id = 1
        for i in range(0, total, SUB_BATCH):
            batch = self.symbols[i:i + SUB_BATCH]
            for sym in batch:
                topic = "market.%s.depth.step0" % sym  # sym 必须小写，例如 btcusdt
                msg = {"sub": topic, "id": "w%s_%d" % (self.wid, sub_id)}
                sub_id += 1
                try:
                    ws.send(jsend(msg))
                except Exception:
                    # 如果发送失败，让 run_forever 触发重连
                    raise
            time.sleep(SUB_SLEEP)

        print("[worker-%s] subscribe sent" % self.wid)

    def flush_if_needed(self, now_ts):
        if self.pipe_cnt <= 0:
            return
        # 条数触发
        if self.pipe_cnt >= PIPELINE_BATCH:
            try:
                self._p_execute()
                self.pipe_cnt = 0
                self._last_flush = now_ts
            except Exception:
                self.reset_pipeline()
            return

        # 时间触发（可选）
        if self._flush_interval and (now_ts - self._last_flush) >= self._flush_interval:
            try:
                self._p_execute()
                self.pipe_cnt = 0
                self._last_flush = now_ts
            except Exception:
                self.reset_pipeline()

    def on_message(self, ws, message):
        raw = gunzip_if_needed(message)
        try:
            data = jloads(raw)
        except Exception:
            return

        # 火币服务端心跳：{"ping": 123456} -> {"pong": 123456}
        if isinstance(data, dict) and "ping" in data:
            try:
                ws.send(jsend({"pong": data["ping"]}))
            except Exception:
                pass
            return

        if not isinstance(data, dict):
            return

        ch = data.get("ch")
        tick = data.get("tick")
        if not ch or not tick:
            return

        # ch: market.btcusdt.depth.step0
        if not ch.startswith("market."):
            return

        sym = ch[len("market."):].split(".", 1)[0]
        if not sym:
            return

        bids = tick.get("bids")
        asks = tick.get("asks")
        if not bids or not asks:
            return

        # 只取前 5 档
        bids5 = bids[:LEVELS]
        asks5 = asks[:LEVELS]

        smb_up = sym.upper()
        key1 = "%s_%d_%d" % (smb_up, PLATFORM, 1)
        key2 = "%s_%d_%d" % (smb_up, PLATFORM, 2)

        # 这里一次消息写两条，所以 pipe_cnt += 2（你原来 +=1 会导致 execute 频率偏低）
        setex = self._p_setex
        ttl = TTL_SECONDS

        try:
            setex(key1, ttl, jdumps(bids5))
            setex(key2, ttl, jdumps(asks5))
            self.pipe_cnt += 2
        except Exception:
            self.reset_pipeline()
            return

        self.flush_if_needed(time.time())

    def on_error(self, ws, error):
        print("[worker-%s] ws error: %s" % (self.wid, error))

    def on_close(self, ws, code, msg):
        print("[worker-%s] ws closed: %s %s" % (self.wid, code, msg))
        try:
            if self.pipe_cnt > 0:
                self._p_execute()
        except Exception:
            pass
        self.pipe_cnt = 0


def load_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    symbols = [r[0].lower() for r in rows]
    print("loaded symbols:", len(symbols))
    if not symbols:
        raise RuntimeError("No symbols found. Please check SYMBOL_SQL.")
    return symbols


def run_process(proc_id, symbols):
    """
    每个进程负责一部分 symbols；进程内再按 SYMS_PER_CONN 切成多个 websocket 线程连接
    注意：Redis 连接池必须在子进程内创建，不要跨进程共享
    """
    print("[proc-%d] pid=%d symbols=%d" % (proc_id, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG, decode_responses=False)
    r = redis.Redis(connection_pool=pool)

    workers = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        w = HTXWorker("%d-%d" % (proc_id, wid), chunk, r)
        w.start()
        workers.append(w)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] workers started: %d" % (proc_id, len(workers)))

    while True:
        time.sleep(60)


def main():
    symbols = load_symbols()

    # 轮询切片：让活跃币更均匀分散到不同进程
    shards = [symbols[i::PROC_NUM] for i in range(PROC_NUM)]

    procs = []
    for pid in range(PROC_NUM):
        p = mp.Process(target=run_process, args=(pid, shards[pid]), daemon=False)
        p.start()
        procs.append(p)
        time.sleep(0.5)  # 进程错峰启动，避免订阅风暴

    for p in procs:
        p.join()


if __name__ == "__main__":
    try:
        mp.set_start_method("fork")
    except RuntimeError:
        pass
    main()
