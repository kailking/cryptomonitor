# #coding=utf-8
# import websocket, json ,ssl ,redis
# import MySQLdb as mdb
# import time
# # print data2
# import sys

# socket = 'wss://wbs.mexc.com/ws'

# pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 3 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# # print r.get('a')
# platform = 5
# page = sys.argv[1]
# offset = int(page) * 30

# def on_message(ws, message):
#     # {"method": "depth.update", "params": [false, {"asks": [["0.06505512", "0"], ["0.06549162", "866.309"]]}, "ROSE3L_USDT"], "id": null}
#     data = json.loads(message)
#     # print(message)
#     # print data
#      # return 1
#     # print(data)
#     if 's' in data:
#         smb = data['s']
#         print(smb)
#         if data['d']:
#             bids = data['d']['bids']
#             asks = data['d']['asks']
#             bid_arr = []
#             ask_arr = []
#             # print(json.dumps(bids))
#             for bid in bids:
#                 bid_arr.append([bid['p'],bid['v']])
#             for ask in asks:
#                 ask_arr.append([ask['p'],ask['v']])
#             # print(json.dumps(bid_arr))    
#             key1 = "%s_%d_%d"%(smb.upper(), platform,1)
#             key2 = "%s_%d_%d"%(smb.upper(), platform,2)
#             r.set(key1,json.dumps(bid_arr),ex=10)
#             r.set(key2, json.dumps(ask_arr),ex=10)
        
    




# def on_close(ws):
#     print(ws)

# def on_error(ws, error):
#     print(ws)
#     print(error)

# def on_open(ws):
#     # 获取所有交易对
#     conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
#     cursor = conn.cursor()
#     cursor.execute('SELECT * FROM currency_match WHERE is_mexc = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_gate = 1 OR is_biance = 1 OR is_aex = 1 OR is_kucoin = 1 OR is_coinex = 1 OR is_lbank = 1 OR is_hotcoin = 1 OR is_ftx = 1 OR is_df = 1) LIMIT 30 OFFSET ' + str(offset) )
#     results = cursor.fetchall()
#     symbol = []
#     for row in results:
#           currency_name = row[3]
#           quote_name = row[4]
#           ws.send(json.dumps({"method":"SUBSCRIPTION", "params":["spot@public.limit.depth.v3.api@"+currency_name+quote_name+"@5"]}))
#         #   symbol.append([currency_name+'_'+quote_name,5,'0'])
    
#     # print symbol
#     # d = {"id":12312, "method": "depth.subscribe", "params": symbol}
#     # # print d
#     # ws.send( json.dumps(d))

#     # print json.dumps(d)
#     # print '{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}'
#     # ws.send('{"id":12312, "method":"depth.subscribe", "params":[["BTC_USDT", 5, "0.01"], ["ETH_USDT", 5, "0"]]}')

# websocket.enableTrace(False)
# ws = websocket.WebSocketApp(socket, on_message = on_message,on_error = on_error, on_close = on_close, on_open= on_open)

# ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE},ping_interval=20,ping_timeout=10,ping_payload=json.dumps({"method":"PING"}))
