# coding=utf-8
import asyncio
import time
import json
import os
import redis
import MySQLdb as mdb

from kucoin.client import Client
from kucoin.asyncio import KucoinSocketManager

# ===== 可选：更快 JSON =====
try:
    import orjson
    def jloads(s): return orjson.loads(s)
    def jdumps(obj): return orjson.dumps(obj)
except Exception:
    def jloads(s): return json.loads(s)
    def jdumps(obj): return json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


# ===== 配置（与你原一致）=====
MYSQL_CFG = dict(host=os.getenv('DB_HOST', '127.0.0.1'), port=int(os.getenv('DB_PORT', '3306')), user=os.getenv('DB_USERNAME', 'tool'), passwd=os.getenv('DB_PASSWORD', ''), db=os.getenv('DB_DATABASE', 'tool'), charset='utf8')
REDIS_CFG = dict(host=os.getenv('REDIS_HOST', '127.0.0.1'), port=int(os.getenv('REDIS_PORT', '6379')), db=3, password=os.getenv('REDIS_PASSWORD') or None)

API_KEY = os.getenv('KUCOIN_API_KEY', '')
API_SECRET = os.getenv('KUCOIN_API_SECRET', '')
API_PASSPHRASE = os.getenv('KUCOIN_API_PASSPHRASE', '')

PLATFORM = 8
TTL_SECONDS = 60

# ===== 多连接参数（重点调这里）=====
SYMS_PER_CONN = 200      # 每个 websocket 连接订多少交易对（建议 100~300）
SUB_BATCH = 50           # 每批 subscribe 带多少 symbol
SUB_SLEEP = 0.20         # 每批之间 sleep，避免限流（0.1~0.3）
WORKER_START_STAGGER = 0.3  # 启动错峰

# Redis pipeline
PIPELINE_BATCH = 400

SYMBOL_SQL = """
SELECT currency_name, quote_name
FROM currency_match
WHERE is_kucoin = 1 AND is_enabled = 1
"""


def load_kucoin_symbols():
    conn = mdb.connect(**MYSQL_CFG)
    cur = conn.cursor()
    cur.execute(SYMBOL_SQL)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    # KuCoin topic 用 BTC-USDT
    syms = [f"{c}-{q}" for c, q in rows]
    print("loaded kucoin symbols:", len(syms))
    return syms


def to_redis_symbol(topic_symbol):
    # BTC-USDT -> BTCUSDT
    return topic_symbol.replace("-", "", 1).upper()


class KucoinWorker:
    """
    每个 worker = 1 条 KuCoin websocket 连接
    """
    def __init__(self, wid, symbols, redis_client):
        self.wid = wid
        self.symbols = symbols

        self.r = redis_client
        self.p = self.r.pipeline(transaction=False)
        self.pipe_cnt = 0

        # 预绑定加速
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute

        # 用于快速过滤
        self.symbol_set = set([s.upper() for s in symbols])

        self.ksm = None

    def reset_pipeline(self):
        self.p = self.r.pipeline(transaction=False)
        self._p_setex = self.p.setex
        self._p_execute = self.p.execute
        self.pipe_cnt = 0

    async def handle_evt(self, msg):
        """
        KuCoin level2Depth5 推送格式大致：
        {
          "type":"message",
          "topic":"/spotMarket/level2Depth5:BTC-USDT",
          "subject":"level2",
          "data":{
             "asks":[["price","size"],...],
             "bids":[["price","size"],...]
          }
        }
        """
        try:
            if not isinstance(msg, dict):
                return

            if msg.get("type") != "message":
                # welcome/ack/pong 等都忽略
                return

            if msg.get("subject") != "level2":
                return

            topic = msg.get("topic", "")
            if not topic.startswith("/spotMarket/level2Depth5:"):
                return

            # topic: /spotMarket/level2Depth5:BTC-USDT
            _, sym = topic.split(":", 1)
            sym_up = sym.upper()

            # 只处理本 worker 负责的 symbols
            if sym_up not in self.symbol_set:
                return

            data = msg.get("data") or {}
            bids = data.get("bids")
            asks = data.get("asks")

            smb = to_redis_symbol(sym_up)
            ttl = TTL_SECONDS
            platform = PLATFORM

            if bids:
                k1 = f"{smb}_{platform}_1"
                self._p_setex(k1, ttl, jdumps(bids))
                self.pipe_cnt += 1

            if asks:
                k2 = f"{smb}_{platform}_2"
                self._p_setex(k2, ttl, jdumps(asks))
                self.pipe_cnt += 1

            if self.pipe_cnt >= PIPELINE_BATCH:
                try:
                    self._p_execute()
                    self.pipe_cnt = 0
                except Exception:
                    self.reset_pipeline()

        except Exception:
            # 不要让异常把回调打挂
            return

    async def run(self, loop):
        # KuCoin 客户端每个 worker 独立一份，避免共享导致阻塞
        client = Client(API_KEY, API_SECRET, API_PASSPHRASE)

        self.ksm = await KucoinSocketManager.create(loop, client, self.handle_evt)

        total = len(self.symbols)
        print(f"[worker-{self.wid}] connected, subscribing {total} symbols")

        # 分批订阅：KuCoin 支持 topic 里逗号拼接多个 symbol
        # /spotMarket/level2Depth5:BTC-USDT,ETH-USDT,...
        for i in range(0, total, SUB_BATCH):
            batch = self.symbols[i:i+SUB_BATCH]
            topic = "/spotMarket/level2Depth5:" + ",".join(batch)
            try:
                await self.ksm.subscribe(topic)
            except Exception as e:
                print(f"[worker-{self.wid}] subscribe error:", repr(e), "topic_head=", topic[:120])
            await asyncio.sleep(SUB_SLEEP)

        print(f"[worker-{self.wid}] subscribe sent")

        # 保持连接
        while True:
            # 定期 flush pipeline（防止低流量时积压）
            if self.pipe_cnt > 0:
                try:
                    self._p_execute()
                    self.pipe_cnt = 0
                except Exception:
                    self.reset_pipeline()

            await asyncio.sleep(2)


async def main():
    syms = load_kucoin_symbols()
    if not syms:
        raise RuntimeError("no kucoin symbols")

    # redis client（共享连接池即可）
    pool = redis.ConnectionPool(**REDIS_CFG, decode_responses=False)
    r = redis.Redis(connection_pool=pool)

    # 按 SYMS_PER_CONN 拆分成多个 worker
    workers = []
    wid = 1

    loop = asyncio.get_event_loop()

    for i in range(0, len(syms), SYMS_PER_CONN):
        chunk = syms[i:i+SYMS_PER_CONN]
        w = KucoinWorker(wid, chunk, r)
        workers.append(w)
        wid += 1

    print("workers planned:", len(workers))

    # 错峰启动，避免同时建连
    tasks = []
    for idx, w in enumerate(workers):
        await asyncio.sleep(WORKER_START_STAGGER)
        tasks.append(loop.create_task(w.run(loop)))

    print("workers started:", len(tasks))

    await asyncio.gather(*tasks)


if __name__ == "__main__":
    asyncio.get_event_loop().run_until_complete(main())
