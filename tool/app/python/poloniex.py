#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb

# print data2

socket = 'wss://api2.poloniex.com'

pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 0 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 7

def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = json.loads(message)
    print data
    # if data.has_key('depth') == False:
    #     return 1 
    
    # param = data['depth']
    # # asks = param[]
    # smb = data['symbol']
  
    # if param.has_key('bids') == True:
    #     if param['bids']:
    #         bids = param['bids'][0]
    #         r.rpush('depth_update',json.dumps({'type': 1 , 'symbol':smb.upper() , 'platform' : platform , 'price' : bids[1] , 'num' : bids[0]}))
    #     # print (bids)
    # if param.has_key('asks') == True:
    #     if param['asks']:
    #         asks = param['asks'][0] 
    #         r.rpush('depth_update',json.dumps({'type': 2 , 'symbol':smb.upper() , 'platform' : platform , 'price' : asks[1] , 'num' : asks[0]}))
    #     # print (asks)
    
    
    
    
def on_close(ws):
    print(ws)

def on_error(ws, error):
    print(ws)
    print(error)
    
def on_open(ws):
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE is_poloniex = 1 AND is_enabled = 1' )
    results = cursor.fetchall()
    symbol = []
    para = json.dumps({ "command": "subscribe", "channel": "USDT_ETC" })
    ws.send(para)
    # for row in results:
    #       currency_name = row[3]
    #       quote_name = row[4]
    #     #   para ={"symbol":currency_name+'_'+quote_name,"limit":5}
    #       para = json.dumps({"cmd":3,"action":"sub", "symbol": currency_name.lower()+'_'+quote_name.lower()})
    #       ws.send(para)
         
    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15,ping_timeout=5)
