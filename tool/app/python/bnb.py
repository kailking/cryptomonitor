#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb

# print data2

socket = 'ws://stream.binance.com:9443'
symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 2 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 2

def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = json.loads(message)
    print(data)
    # if data.has_key('depth') == False:
    #     return 1 
    # if data['s'] in symbol2:
    #     # print(data['s'])
    #     key1 = "%s_%d_%d"%(data['s'], platform,1)
    #     key2 = "%s_%d_%d"%(data['s'], platform,2)
    #     # r.rpush('depth_update_kucoin',smb.upper())
    #     r.set(key2, json.dumps({'type': 2 , 'symbol':data['s'] , 'platform' : platform , 'price' : data['a'] , 'num' : data['A']}))
    #     r.set(key1, json.dumps({'type': 1 , 'symbol':data['s'], 'platform' : platform , 'price' : data['b'] , 'num' : data['B']}))
    
    
    
    
def on_close(ws):
    print(ws)

def on_error(ws, error):
    print(ws)
    print(error)
    
def on_open(ws):
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE is_biance = 1 AND is_enabled = 1' )
    results = cursor.fetchall()
    
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
          symbol.append(currency_name.lower()+quote_name.lower()+'@depth5')
          symbol2.append(currency_name.upper()+quote_name.upper())
        #   para ={"symbol":currency_name+'_'+quote_name,"limit":5}
        #   para = json.dumps({"cmd":3,"action":"sub", "symbol": currency_name.lower()+'_'+quote_name.lower()})
          
    para = json.dumps({
            "method": "SUBSCRIBE",
            "params":symbol,
            "id": 1
            })
    ws.send(para)     
    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15,ping_timeout=10)
