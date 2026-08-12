#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb


import unicorn_binance_websocket_api

ubwa = unicorn_binance_websocket_api.BinanceWebSocketApiManager(exchange="binance.com")
conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
cursor = conn.cursor()
cursor.execute('SELECT * FROM currency_match WHERE is_biance = 1 AND is_enabled = 1 ' )
results = cursor.fetchall()

symbol = []
symbol2 = []
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 2 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 2

for row in results:
      currency_name = row[3]
      quote_name = row[4]
      symbol.append(currency_name.lower()+quote_name.lower())
      
      
ubwa.create_stream(['depth5'], symbol)

while True:
    oldest_data_from_stream_buffer = ubwa.pop_stream_data_from_stream_buffer()
    if oldest_data_from_stream_buffer:
        # print(oldest_data_from_stream_buffer)
         
        datas = json.loads(oldest_data_from_stream_buffer)
        print(datas)
        # print(data[2])
        # for data in datas:
        #     print(data)
        # if data.has_key('depth') == False:
        #     return 1 
            # if data['s'] in symbol2:
            #     key1 = "%s_%d_%d"%(data['s'], platform,1)
            #     key2 = "%s_%d_%d"%(data['s'], platform,2)
            #     # r.rpush('depth_update_kucoin',smb.upper())
            #     r.set(key2, json.dumps({'type': 2 , 'symbol':data['s'] , 'platform' : platform , 'price' : data['a'] , 'num' : data['A']}))
            #     r.expire(key2,60)
            #     r.set(key1, json.dumps({'type': 1 , 'symbol':data['s'], 'platform' : platform , 'price' : data['b'] , 'num' : data['B']}))
            #     r.expire(key1,60)
        
        
# print data2
