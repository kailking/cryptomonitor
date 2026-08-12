#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb
import time
# print data2

socket = 'wss://ws.gate.io/v3/'

pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 0 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 4 

def on_message(ws, message):
    print(message)
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    data = json.loads(message)
    
    
    # if data.has_key('params') == False:
    #     return 1 
    
    # param = data['params'][1]
    # # asks = param[]
    # smb = data['params'][2]
    # print smb 
    # if param.has_key('bids') == True:
      
    #     if param['bids']:
    #         bids = param['bids'][0]
            
    #         r.rpush('depth_update',json.dumps({'type': 1 , 'symbol':smb , 'platform' : platform , 'price' : bids[0] , 'num' : bids[1]}))
    #     # print (bids)
    # if param.has_key('asks') == True:
    #     if param['asks']:
    #         asks = param['asks'][0] 
    #         r.rpush('depth_update',json.dumps({'type': 2 , 'symbol':smb , 'platform' : platform , 'price' : asks[0] , 'num' : asks[1]}))
        # print (asks)
    
    
    
    
def on_close(ws):
    print(ws)

def on_error(ws, error):
    print(ws)
    print(error)
    
def on_open(ws):
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE is_gate = 1 AND is_enabled = 1' )
    results = cursor.fetchall()
    symbol = []
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
          symbol.append([currency_name+'_'+quote_name,5,'0'])
          par = {"id":12312, "method":"kline.subscribe", "params":[currency_name+'_'+quote_name, 60]}
          ws.send(json.dumps(par))
    # print symbol
    

    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=60,ping_timeout=5)
