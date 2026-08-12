# coding=utf-8
import time
import os
import threading
import multiprocessing as mp

import redis
import MySQLdb as mdb
import unicorn_binance_websocket_api

# ===== 可选：更快 JSON =====
try:
    import orjson
    def jloads(b):  # b: str/bytes
        return orjson.loads(b)
    def jdumps(obj):  # bytes
        return orjson.dumps(obj)
except Exception:
    import json
    def jloads(s):
        return json.loads(s)
    def jdumps(obj):
        return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")

# ===== 配置 =====
MYSQL_CFG = dict(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
REDIS_CFG = dict(host='127.0.0.1', port=6379, db=3, password=__import__('os').getenv('REDIS_PASSWORD') or None)

PLATFORM = 2
TTL_SECONDS = 30

# ===== 多进程分片参数（核心优化点）=====
PROC_NUM = 8  # 24 核机器建议 8 起步；不够再加 10/12

# ===== 单进程内多连接参数 =====
SYMS_PER_CONN = 120          # 每个 worker 订阅多少 symbol（建议 60~150）
WORKER_START_STAGGER = 0.35  # 启动错峰，避免同时建连
PIPELINE_BATCH = 300         # pipeline 每多少次 setex 才 exec（一次消息2条 setex，下面会 +2）

# 可选：时间阈值 flush，避免低频币对一直等（不想要可设为 0）
PIPELINE_FLUSH_MS = 80

# Binance depth 频道参数
BINANCE_CHANNELS = ['depth5']  # 仍保持你的现状不改频道

SYMBOL_SQL = "SELECT symbol FROM currency_match WHERE is_biance=1 AND is_enabled=1"


def load_binance_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    syms = [row[0].lower() for row in rows]  # binance stream 用小写
    print("loaded binance symbols:", len(syms))
    return syms


class BinanceWorker(threading.Thread):
    """
    每个 worker = 1 个 BinanceWebSocketApiManager + 1 组 symbols
    """
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

        self.ubwa = None
        self.stream_id = None

    def reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self.pipe_cnt = 0
        self._last_flush = time.time()

    def run(self):
        while True:
            try:
                self.connect_and_loop()
            except Exception as e:
                print("[worker-%s] exception: %r" % (self.wid, e))
            # 防抖，避免疯狂重连
            time.sleep(2)

    def connect_and_loop(self):
        total = len(self.symbols)
        print("[worker-%s] connecting ... syms=%d" % (self.wid, total))

        # 每个 worker 独立一个 manager（避免全局共享）
        # output_default="dict" 让库尽量直接产 dict（不同版本可能不支持；不支持也没关系）
        try:
            self.ubwa = unicorn_binance_websocket_api.BinanceWebSocketApiManager(
                exchange="binance.com",
                output_default="dict",
            )
        except TypeError:
            self.ubwa = unicorn_binance_websocket_api.BinanceWebSocketApiManager(
                exchange="binance.com",
            )

        # 每个 worker 控制在 SYMS_PER_CONN，避免 1008 payload too long
        self.stream_id = self.ubwa.create_stream(BINANCE_CHANNELS, self.symbols)
        print("[worker-%s] stream created: %s" % (self.wid, self.stream_id))

        empty_spin = 0
        while True:
            msg = self.ubwa.pop_stream_data_from_stream_buffer()
            if not msg:
                empty_spin += 1
                # 60s 没数据，强制重连（解决“进程活着但不更新”）
                if empty_spin >= 60000:  # 60000 * 0.001s = 60s
                    raise RuntimeError("buffer empty too long, force reconnect")
                time.sleep(0.001)
                continue

            empty_spin = 0

            # msg 可能是 dict / str / bytes（取决于库版本与 output_default）
            try:
                if isinstance(msg, (dict,)):
                    data = msg
                else:
                    data = jloads(msg)
            except Exception:
                continue

            d = data.get('data')
            if not d:
                continue
            
            bids = d.get('bids')
            asks = d.get('asks')
            if not bids or not asks:
                continue

            stream = data.get('stream')
            if not stream:
                continue

            smb = stream.split('@', 1)[0]  # "btcusdt@depth5@100ms" -> "btcusdt"
            smb_up = smb.upper()
            # if smb_up == 'ZBTUSDT':
            #     print(asks)
                
            ttl = TTL_SECONDS
            platform = PLATFORM

            k1 = "%s_%d_%d" % (smb_up, platform, 1)
            k2 = "%s_%d_%d" % (smb_up, platform, 2)

            try:
                self._p_setex(k1, ttl, jdumps(bids))
                self._p_setex(k2, ttl, jdumps(asks))
                self.pipe_cnt += 2  # 一条消息两次 setex
            except Exception:
                self.reset_pipeline()
                continue

            # flush：条数触发 +（可选）时间触发
            now = time.time()
            if self.pipe_cnt >= PIPELINE_BATCH or (self._flush_interval and (now - self._last_flush) >= self._flush_interval):
                try:
                    self._p_execute()
                    self.pipe_cnt = 0
                    self._last_flush = now
                except Exception:
                    self.reset_pipeline()

    def stop(self):
        try:
            if self.ubwa:
                self.ubwa.stop_manager_with_all_streams()
        except Exception:
            pass


def run_process(proc_id, symbols):
    """
    每个进程负责一部分 symbols；进程内再按 SYMS_PER_CONN 切成多个 worker（每个 worker 1 个 manager/连接）
    注意：Redis 连接池必须在子进程内创建，不要跨进程共享
    """
    print("[proc-%d] pid=%d symbols=%d" % (proc_id, os.getpid(), len(symbols)))

    pool = redis.ConnectionPool(**REDIS_CFG, decode_responses=False)
    r = redis.Redis(connection_pool=pool)

    workers = []
    wid = 1
    for i in range(0, len(symbols), SYMS_PER_CONN):
        chunk = symbols[i:i + SYMS_PER_CONN]
        w = BinanceWorker("%d-%d" % (proc_id, wid), chunk, r)
        w.start()
        workers.append(w)
        wid += 1
        time.sleep(WORKER_START_STAGGER)

    print("[proc-%d] workers started: %d" % (proc_id, len(workers)))

    while True:
        time.sleep(60)


def main():
    syms = load_binance_symbols()
    if not syms:
        raise RuntimeError("no binance symbols")

    # 轮询切片：让活跃币更均匀分散到不同进程
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
    try:
        mp.set_start_method("fork")
    except RuntimeError:
        pass
    main()
