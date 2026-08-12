#coding=utf-8

import websocket, json ,ssl ,redis
import MySQLdb as mdb
import zlib
from decimal import Decimal
import gzip

# print data2

socket = 'wss://wss.hotcoinfin.com/trade/multiple'
symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379,db = 3 ,  password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(connection_pool=pool)
# print r.get('a')
conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
cursor = conn.cursor()
platform = 11


def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = gzip.decompress(message)
    data = json.loads(data)
    # print(data)
    if 'data' in data == False:
        return 1
    if 'ch' in data == False:
        return 1
    stream = data['ch'].split('.')
            # print(stream[0])
    smb = stream[1]
    
    smb = smb.replace("_", "", 1)
    param = data['data']
    # print(param)
    if 'bids' in param:
        if param['bids']:
            bids = param['bids']
            key1 = "%s_%d_%d"%(smb.upper(), platform,1)
            r.set(key1, json.dumps(bids[0:5]))
            r.expire(key1,60)
    if 'asks' in param:
        if param['asks']:
            asks = param['asks']
            key2 = "%s_%d_%d"%(smb.upper(), platform,2)
            r.set(key2, json.dumps(asks[0:5]))
            r.expire(key2,60)
    # if 'ping' in data:
    #     ws.send(json.dumps({'action':'pong','pong':data['ping']}))
    #     return 1
    
    # smb = data['pair']
    
    # smb = smb.replace("_", "", 1)
    # param = data['depth']
    # # print smb
    # if 'bids' in param:
    #     if param['bids']:
    #         bids = param['bids']
    #         key1 = "%s_%d_%d"%(smb.upper(), platform,1)
    #         r.set(key1, json.dumps(bids))
    #         r.expire(key1,60)
    #         # r.rpush('depth_update',json.dumps({'type': 1 , 'symbol':smb , 'platform' : platform , 'price' : bids[0] , 'num' : bids[1]}))
    #     # print (bids)
    # if 'asks' in param:
    #     if param['asks']:
    #         asks = param['asks']
    #         key2 = "%s_%d_%d"%(smb.upper(), platform,2)
    #         r.set(key2, json.dumps(asks))
    #         r.expire(key2,60)
   
def on_close(a,b,c):
    print('close')

def on_error(ws, error):
    print(ws)
    print(error)
    
    
def on_open(ws):
    # 获取所有交易对
    cursor.execute('SELECT * FROM currency_match WHERE is_hotcoin = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_gate = 1 OR is_mexc = 1 OR is_aex = 1 OR is_kucoin = 1 OR is_coinex = 1 OR is_lbank = 1 OR is_biance = 1 OR is_ftx = 1 OR is_df = 1) ORDER BY id ASC ' )
    results = cursor.fetchall()
    
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
        #   symbol.append([currency_name.upper()+quote_name.upper(),5,'0',False])
          symbol2.append(currency_name.upper()+quote_name.upper())
          para = json.dumps({"sub":"market.%s_%s.trade.depth"%(currency_name.lower(),quote_name.lower())})
          ws.send(para)
        #   para ={"symbol":currency_name+'_'+quote_name,"limit":5}
        #   para = json.dumps({"cmd":3,"action":"sub", "symbol": currency_name.lower()+'_'+quote_name.lower()})
          
    # print(symbol)
    
    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
# websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15, ping_timeout=10)
