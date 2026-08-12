#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb

# print data2

socket = 'wss://socket.coinex.com/'
symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 3 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 9

# p = r.pipeline(transaction=False)

def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    
    data = json.loads(message)
    
    if 'method' in data == False:
        return 1 
    data = data['params']
    smb = data[2]
    bids = data[1]['bids']
    asks = data[1]['asks']
    key1 = "%s_%d_%d"%(smb.upper(), platform,1)
    key2 = "%s_%d_%d"%(smb.upper(), platform,2)
    r.set(key1, json.dumps(bids),ex=60)
    r.set(key2, json.dumps(asks),ex=60)
    
    
    
    
    
def on_close(a,b,c):
    print('close')

def on_error(ws, error):
    print(ws)
    print(error)
    
def on_open(ws):
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE is_coinex = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_gate = 1 OR is_mexc = 1 OR is_aex = 1 OR is_biance = 1 OR is_kucoin = 1 OR is_lbank = 1 OR is_hotcoin = 1 OR is_ftx = 1 OR is_df = 1) ORDER BY id ASC LIMIT 200,200' )
    results = cursor.fetchall()
    
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
          symbol.append([currency_name.upper()+quote_name.upper(),5,'0',False])
          symbol2.append(currency_name.upper()+quote_name.upper())
        #   para ={"symbol":currency_name+'_'+quote_name,"limit":5}
        #   para = json.dumps({"cmd":3,"action":"sub", "symbol": currency_name.lower()+'_'+quote_name.lower()})
          
    # print(symbol)
    para = json.dumps({
            "method": "depth.subscribe_multi",
            "params":symbol,
            "id": 15
            })
    ws.send(para)     
    
    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')
    
# websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15,ping_timeout=10)
