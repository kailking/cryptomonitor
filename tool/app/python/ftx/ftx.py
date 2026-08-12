#coding=utf-8

import websocket, json ,ssl ,redis
import MySQLdb as mdb
import zlib
from decimal import Decimal

# print data2

socket = 'wss://ftx.com/ws/'
symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 4 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
cursor = conn.cursor()
platform = 12


def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = json.loads(message)
    # print(data)
    if data['type'] == 'partial':
        smb = data['market']
        smb = smb.replace("/", "", 1)
        bid_key = "%s_%d_%d"%(smb.upper(), platform,1)
        ask_key = "%s_%d_%d"%(smb.upper(), platform,2)
        param = data['data']
        if 'bids' in param:
            if param['bids']:
                r.delete(bid_key)
                bid_arr = {}
                for price,size in param['bids']:
                    bid_arr[str(price)] = size
                r.set(bid_key,json.dumps(bid_arr))
        if 'asks' in param:
            if param['asks']:
                r.delete(ask_key)
                ask_arr = {}
                for price,size in param['asks']:
                    ask_arr[str(price)] = size
                r.set(ask_key,json.dumps(ask_arr))
                    
    if data['type'] == 'update':
        smb = data['market']
        smb = smb.replace("/", "", 1)
        bid_key = "%s_%d_%d"%(smb.upper(), platform,1)
        ask_key = "%s_%d_%d"%(smb.upper(), platform,2)
        param = data['data']
        if 'bids' in param:
            if param['bids']:
                bid_arr = r.get(bid_key)
                if bid_arr:
                    bid_arr = json.loads(bid_arr)
                    for price,size in param['bids']:
                        if size:
                            # print(price)
                            bid_arr[str(price)] = size
                        else:
                            # print('del bid %s'%(price,))
                            # print(bid_arr[str(price)])
                            bid_arr.pop(str(price))
                    r.set(bid_key,json.dumps(bid_arr))
        if 'asks' in param:
            if param['asks']:
                ask_arr = r.get(ask_key)
                if ask_arr:
                    ask_arr = json.loads(ask_arr)
                    for price,size in param['asks']:
                        if size:
                            # print(price)
                            ask_arr[str(price)] = size
                        else:
                            # print(ask_arr[str(price)])
                            # print('del ask %s'%(price,))
                            ask_arr.pop(str(price))
                            # print(res)
                    r.set(ask_key,json.dumps(ask_arr))
        
    
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
    cursor.execute('SELECT * FROM currency_match WHERE is_enabled = 1 AND is_ftx = 1 ORDER BY id ASC LIMIT 200' )
    results = cursor.fetchall()
    
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
        #   symbol.append([currency_name.upper()+quote_name.upper(),5,'0',False])
          symbol2.append(currency_name.upper()+quote_name.upper())
        #   para = json.dumps({'op': 'subscribe', 'channel': 'trades', 'market': currency_name.upper()+'-PERP'})
        #   ws.send(para)
        #   para ={"symbol":currency_name+'_'+quote_name,"limit":5}
        #   para = json.dumps({"cmd":3,"action":"sub", "symbol": currency_name.lower()+'_'+quote_name.lower()})
          para = json.dumps({'op': 'subscribe', 'channel': 'orderbook', 'market': currency_name.upper()+'_'+quote_name.upper()})
          ws.send(para)
    
    # print(symbol)
    
    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
websocket.enableTrace(False)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15, ping_timeout=10)
