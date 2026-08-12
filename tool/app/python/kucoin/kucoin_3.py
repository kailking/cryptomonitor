#!/usr/bin/python3.6.4
import sys
sys.path.append("/root/.pyenv/versions/3.6.4/lib/python3.6/site-packages")
print(sys.modules.keys())
# 
import asyncio
import os
import redis
import json
import MySQLdb as mdb
from kucoin.client import Client
from kucoin.asyncio import KucoinSocketManager
import numpy as np
api_key = os.getenv('KUCOIN_API_KEY', '')
api_secret = os.getenv('KUCOIN_API_SECRET', '')
api_passphrase = os.getenv('KUCOIN_API_PASSPHRASE', '')
platform = 8
pool = redis.ConnectionPool(host=os.getenv('REDIS_HOST', '127.0.0.1'), port=int(os.getenv('REDIS_PORT', '6379')), db=3, password=os.getenv('REDIS_PASSWORD') or None, decode_responses=True)
r = redis.Redis(connection_pool=pool)

async def main():
    global loop
    conn = mdb.connect(host=os.getenv('DB_HOST', '127.0.0.1'), port=int(os.getenv('DB_PORT', '3306')), user=os.getenv('DB_USERNAME', 'tool'), passwd=os.getenv('DB_PASSWORD', ''), db=os.getenv('DB_DATABASE', 'tool'), charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE is_kucoin = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_gate = 1 OR is_mexc = 1 OR is_aex = 1 OR is_biance = 1 OR is_coinex = 1 OR is_lbank = 1 OR is_hotcoin = 1 OR is_ftx = 1) AND currency_name NOT IN (\'WAXP\',\'SUN\',\'GALA\',\'REV\',\'OXEN\',\'BSV\') ORDER BY id ASC LIMIT 200 OFFSET 400' )
    results = cursor.fetchall()
    symbol = []
    for row in results:
        currency_name = row[3]
        quote_name = row[4]
        symbol.append(currency_name+'-'+quote_name)
    async def handle_evt(msg):
        # print(msg)
        # return 1
        if msg['subject'] == 'level2':
                res = msg['topic'].split(':')
                smb = res[1].replace("-", "", 1)
                if msg['data']:
                    # print(smb)
                    key1 = "%s_%d_%d"%(smb, platform,1)
                    key2 = "%s_%d_%d"%(smb, platform,2)
                    bids = msg['data']['bids']
                    asks = msg['data']['asks']
                    r.set(key1, json.dumps(bids),ex=60)
                    # r.expire(key1,60)
                    r.set(key2, json.dumps(asks),ex=60)
                    # r.expire(key2,60)
            
                    
                    
                

     

    client = Client(api_key, api_secret, api_passphrase)

    ksm = await KucoinSocketManager.create(loop, client, handle_evt)

    # Note: try these one at a time, if all are on you will see a lot of output

    # ETH-USDT Market Ticker
    # await ksm.subscribe('/market/ticker:ETH-USDT')
    # # BTC Symbol Snapshots
    # await ksm.subscribe('/market/snapshot:BTC')
    # # KCS-BTC Market Snapshots
    # await ksm.subscribe('/market/snapshot:KCS-BTC')
    # # All tickers

    str1 = ','
    

    arr = np.array(symbol)
    
    newarr = np.array_split(arr, 90)
    for syb in newarr:
        await ksm.subscribe('/spotMarket/level2Depth5:'+str1.join(syb))
    # await ksm.subscribe('/spotMarket/level2Depth5:WAXP-USDT')
    # Level 2 Market Data
    
   
    # Market Execution Data
    # await ksm.subscribe('/market/match:BTC-USDT')
    # # Level 3 market data
    # await ksm.subscribe('/market/level3:BTC-USDT')
    # # Account balance - must be authenticated
    # await ksm.subscribe('/account/balance')

    while True:
        # print("sleeping to keep loop open")
        await asyncio.sleep(2, loop=loop)


if __name__ == "__main__":

    loop = asyncio.get_event_loop()
    loop.run_until_complete(main())
