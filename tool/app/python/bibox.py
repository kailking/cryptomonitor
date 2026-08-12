#coding=utf-8

import websocket, json ,ssl ,redis
import MySQLdb as mdb
import zlib
from decimal import Decimal

# print data2

socket = 'wss://npush.bibox360.com'
symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 4 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
cursor = conn.cursor()
platform = 9

def decode_data(message):
    if message[0] == '\x01' or message[0] == 1:
        message = message[1:]
        data = zlib.decompress(message, zlib.MAX_WBITS | 32)
        jmsgs = json.loads(data)
        return jmsgs
        # print(type(jmsgs))
    elif message[0] == '\x00' or message[0] == 0:
        message = message[1:]
        jmsgs = json.loads(message)
        return jmsgs
    else:
        jmsgs = json.loads(message)
        return jmsgs

def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = decode_data(message)
    # print(data)
    # return 1
    if 'd' in data == False:
        
        return 1
    
    if 't' in data == False:
        print(data)
        return 1
        
    d_type = data['t']
    
    data = data['d']
    
    smb = data['pair']
    smb = smb.replace("_", "", 1)
    # print(d_type)
    if d_type == 0:
        # print('err')
        bid_key = '%s_%d_bids'%(smb,platform)
        ask_key = '%s_%d_asks'%(smb,platform)
        r.delete(bid_key)
        r.delete(ask_key)
        all_keys = r.keys('%s_%d_*'%(smb,platform))
        if all_keys:
            for k in all_keys:
                r.delete(k)
        # cursor.execute('DELETE FROM order_book WHERE platform = %d AND symbol = \'%s\''%(platform,smb))
        asks = data['asks']
        bids = data['bids']
        for bid in bids:
            r.sadd(bid_key,bid[1])
            r.set('%s_%d_bid_%s'%(smb,platform,bid[1]),bid[0])
            # cursor.execute("INSERT INTO `order_book`(`platform`, `symbol`,`type`, `price`, `num`) VALUES (%s,%s,%s,%s,%s)", (platform,smb,1,bid[1],bid[0]))
        for ask in asks:
            r.sadd(ask_key,ask[1])
            r.set('%s_%d_ask_%s'%(smb,platform,ask[1]),ask[0])
    if d_type == 1:
        bid_key = '%s_%d_bids'%(smb,platform)
        ask_key = '%s_%d_asks'%(smb,platform)
        asks = data['add']['asks']
        bids = data['add']['bids']
        pip_key = 'platform_9_queue'
        if bids:
            for bid in bids:
                r.rpush(pip_key,json.dumps({'symbol': smb, 'price':bid[1], 'num':bid[0],'dir':'add','type':1}))
        if asks:
            for ask in asks:
                r.rpush(pip_key,json.dumps({'symbol': smb, 'price':ask[1], 'num':ask[0],'dir':'add','type':2}))
        asks = data['del']['asks']
        bids = data['del']['bids']
        if bids:
            for bid in bids:
                r.rpush(pip_key,json.dumps({'symbol': smb, 'price':bid[1], 'num':bid[0],'dir':'del','type':1}))
        if asks:
            for ask in asks:
                r.rpush(pip_key,json.dumps({'symbol': smb, 'price':ask[1], 'num':ask[0],'dir':'del','type':2}))
        return 1
        # for ask in asks:
        #     price = ask[1]
        #     # print(price)
        #     numb = ask[0]
        #     if numb <= 0:
        #         print('remove ask')
        #         r.srem(ask_key,price)
        #     else:
        #         print('update ask')
        #         r.set("%s_%d_bid_%s"%(smb, platform,price),numb)
            
    # if 'method' in data == False:
    #     return 1 
    # data = data['params']
    # smb = data[2]
    # bids = data[1]['bids']
    # asks = data[1]['asks']
    # key1 = "%s_%d_%d"%(smb.upper(), platform,1)
    # key2 = "%s_%d_%d"%(smb.upper(), platform,2)
    # r.set(key1, json.dumps(bids))
    # r.expire(key1,60)
    # r.set(key2, json.dumps(asks))
    # r.expire(key2,60)
def on_close(a,b,c):
    print('close')

def on_error(ws, error):
    print(ws)
    print(error)
    
def on_open(ws):
    # 获取所有交易对
    cursor.execute('SELECT * FROM currency_match WHERE is_bibox = 1 AND is_enabled = 1 AND symbol IN (\'BTCUSDT\',\'ETHUSDT\',\'ETCUSDT\') ORDER BY id ASC LIMIT 200' )
    results = cursor.fetchall()
    
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
        #   symbol.append([currency_name.upper()+quote_name.upper(),5,'0',False])
          symbol2.append(currency_name.upper()+quote_name.upper())
          para = json.dumps({'sub': currency_name.upper()+'_'+quote_name.upper()+'_depth'})
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
