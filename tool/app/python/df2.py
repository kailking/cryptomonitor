#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb
import time
import traceback
# print data2

socket = 'wss://ascendex.com/0/api/pro/v1/stream'

pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379,db = 4 ,  password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(connection_pool=pool)
# print r.get('a')
platform = 13

p = r.pipeline(transaction=False)

def on_message(ws, message):
    # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
    try:
        obj = json.loads(message)
        # print(obj)
        if 'm' not in obj:
            return
        
        m = obj['m']
       
        if m=='ping':
            print('receive ping' )
            dd = { 'op': 'pong'}
                # # print d
            ws.send(json.dumps(dd))
            return
        if 'symbol' not in obj:
            return 
        
        smb = obj['symbol']
        smb = smb.replace("/", "", 1)
        print(smb)
        if m =='depth':
             # Update the orderbook using depth messages
            bid_key = "%s_%d_%d"%(smb.upper(), platform,1)
            ask_key = "%s_%d_%d"%(smb.upper(), platform,2)
           
            bid_arr = r.get(bid_key)
            if bid_arr:
                bid_arr = json.loads(bid_arr)
                for price,size in obj['data']['bids']:
                    if size != '0':
                        # print(size)
                        bid_arr[str(price)] = size
                    else:
                        # print('del bid %s'%(price,))
                        # print(bid_arr[str(price)])
                        if str(price) in bid_arr:
                            bid_arr.pop(str(price))
                r.set(bid_key,json.dumps(bid_arr))
            ask_arr = r.get(ask_key)
            if ask_arr:
                ask_arr = json.loads(ask_arr)
                for price,size in obj['data']['asks']:
                    if  size != '0':
                        # print(size)
                        ask_arr[str(price)] = size
                    else:
                        # print(ask_arr[str(price)])
                        # print('del ask %s'%(price,))
                        if str(price) in ask_arr:
                            ask_arr.pop(str(price))
                        # print(res)
                r.set(ask_key,json.dumps(ask_arr))
            return 
        elif m == 'depth-snapshot':
            bid_key = "%s_%d_%d"%(smb.upper(), platform,1)
            ask_key = "%s_%d_%d"%(smb.upper(), platform,2)
            # Construct a new orderbook using the snapshot data
            bids = obj['data']['bids']
            asks = obj['data']['asks']
            r.delete(bid_key)
            bid_arr = {}
            for price,size in bids:
                bid_arr[str(price)] = size
            r.set(bid_key,json.dumps(bid_arr))
            r.delete(ask_key)
            ask_arr = {}
            for price,size in asks:
                ask_arr[str(price)] = size
            r.set(ask_key,json.dumps(ask_arr))
            return 
    except Exception as e:
        print(e)
        traceback.print_exc()
        print("--------")
            
       
    
    # return 1

       



def on_close(ws,s,c):
    print(ws)
    print(s)
    print(c)

def on_error(ws, error):
    print(ws)
    print(error)

def on_open(ws):
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute('SELECT * FROM currency_match WHERE  is_df = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_biance = 1 OR is_mexc = 1 OR is_aex = 1 OR is_kucoin = 1 OR is_coinex = 1 OR is_lbank = 1 OR is_hotcoin = 1 OR is_ftx = 1 OR is_gate = 1) LIMIT 150,150' )
    results = cursor.fetchall()
    symbol = []
    for row in results:
          currency_name = row[3]
          quote_name = row[4]
          # print symbol
          d = { "op": "req", "action": "depth-snapshot", "args":{"symbol":currency_name+'/'+quote_name}}
            # # print d
          ws.send( json.dumps(d))
            # print symbol
          d = { "op": "sub", "id": "abc123", "ch":"depth:"+currency_name+'/'+quote_name}
            # # print d
          ws.send( json.dumps(d))
    
    

    # print json.dumps(d)
    # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
    # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')

websocket.enableTrace(True)
ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)
ping_text = {"op":"ping"}
ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=15,ping_timeout=10,ping_payload=json.dumps(ping_text))
