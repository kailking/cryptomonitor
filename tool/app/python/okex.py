# coding=utf-8
import time
import ssl
import threading
import multiprocessing as mp
import os
import socket

import websocket
import redis
import MySQLdb as mdb

# ===== 更快 JSON（可选）=====
try:
    import orjson
    def jloads(b): return orjson.loads(b)
    def jdumps(obj): return orjson.dumps(obj)
    def jsend(obj): return orjson.dumps(obj).decode("utf-8")
except Exception:
    import json
    def jloads(s): return json.loads(s)
    def jdumps(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    def jsend(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False)

# ===== 配置（与你原来一样）=====
WS_URL = "wss://ws.okx.com:8443/ws/v5/public"

MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None)

PLATFORM = 3
TTL_SECONDS = 30

# ===== 多进程分片参数（核心优化点）=====
PROC_NUM = 8  # 24 核机器建议 8 起步；不够再加 10/12

# ===== 单进程内多连接参数 =====
SYMS_PER_CONN = 120          # 每条连接订阅多少 instId（建议 60~150）
SUB_BATCH = 50               # 每批 args 多少个（OKX 单次 args 太多可能返回错误/断开）
SUB_SLEEP = 0.20             # 每批订阅间隔，避免限流（0.15~0.35）
WORKER_START_STAGGER = 0.6   # 每条连接错峰启动

# pipeline 攒批执行：本机 Redis 可 200~1000
PIPELINE_BATCH = 300

# 可选：时间阈值 flush，避免低频币对一直等（不想要可设为 0）
PIPELINE_FLUSH_MS = 80

# socket buffer（配合你已调大的 sysctl）
SOCKBUF_BYTES = 128 * 1024 * 1024

# 你库里字段 is_okex
SYMBOL_SQL = "SELECT currency_name, quote_name FROM currency_match WHERE is_okex=1 AND is_enabled=1"
# ============================


def load_okx_args():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    args = []
    for currency_name, quote_name in rows:
        # OKX instId 格式：BTC-USDT
        args.append({"channel": "books5", "instId": f"{currency_name}-{quote_name}"})

    print("loaded okx symbols:", len(args))
    return args


class OKXWorker(threading.Thread):
    def __init__(self, wid, sub_args, redis_client):
        super().__init__(daemon=True)
        self.wid = wid
        self.sub_args = sub_args
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

    def on_open(self, ws):
        # 验证 socket buffer 是否生效
        try:
            sock = ws.sock.sock
            rcv = sock.getsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF)
            snd = sock.getsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF)
            print(f"[worker-{self.wid}] SO_RCVBUF={rcv} SO_SNDBUF={snd}")
        except Exception:
            pass

        total = len(self.sub_args)
        print(f"[worker-{self.wid}] connected, subscribing {total} instIds")

        # 分批订阅：避免一次 args 太多导致 OKX 返回错误/断开
        sid = 1
        for i in range(0, total, SUB_BATCH):
            batch = self.sub_args[i:i + SUB_BATCH]
            msg = {"op": "subscribe", "args": batch}
            try:
                ws.send(jsend(msg))
            except Exception:
                raise
            sid += 1
            time.sleep(SUB_SLEEP)

        print(f"[worker-{self.wid}] subscribe sent")

    def on_message(self, ws, message):
        try:
            data = jloads(message)
        except Exception:
            return

        # 订阅确认/错误等事件
        if isinstance(data, dict) and "event" in data:
            ev = data.get("event")
            if ev == "error":
                # 打印有助于定位：{"event":"error","code":"60012","msg":"..."}
                # print(f"[worker-{self.wid}] event error:", data)
                pass
            elif ev == "ping":
                try:
                    ws.send('{"event":"pong"}')
                except Exception:
                    pass
            return

        # op ping
        if isinstance(data, dict) and data.get("op") == "ping":
            try:
                ws.send('{"op":"pong"}')
            except Exception:
                pass
            return

        rows = data.get("data")
        if not rows:
            return

        setex = self._p_setex
        ttl = TTL_SECONDS
        platform = PLATFORM

        for row in rows:
            inst = row.get("instId")
            if not inst:
                continue

            smb_up = inst.replace("-", "", 1).upper()

            bids = row.get("bids")
            if bids:
                setex(f"{smb_up}_{platform}_1", ttl, jdumps(bids))
                self.pipe_cnt += 1

            asks = row.get("asks")
            if asks:
                setex(f"{smb_up}_{platform}_2", ttl, jdumps(asks))
                self.pipe_cnt += 1

        self.flush_if_needed(time.time())

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
                print(f"[worker-{self.wid}] run_forever exception:", e)

            time.sleep(2)


def run_process(proc_id, args_list):
    """
    每个进程负责一部分 instId；进程内再按 SYMS_PER_CONN 切成多个 ws 连接线程
    """
    print(f"[proc-{proc_id}] pid={os.getpid()} instIds={len(args_list)}")

    pool = redis.ConnectionPool(**REDIS_CFG, decode_responses=False)
    r = redis.Redis(connection_pool=pool)

    workers = []
    wid = 1
    for i in range(0, len(args_list), SYMS_PER_CONN):
        chunk = args_list[i:i + SYMS_PER_CONN]
        w = OKXWorker(f"{proc_id}-{wid}", chunk, r)
        w.start()
        workers.append(w)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print(f"[proc-{proc_id}] workers started:", len(workers))
    while True:
        time.sleep(60)


def main():
    args = load_okx_args()
    if not args:
        raise RuntimeError("no okx args")

    # 轮询切片：让活跃币更均匀分散到不同进程
    shards = [args[i::PROC_NUM] for i in range(PROC_NUM)]

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
