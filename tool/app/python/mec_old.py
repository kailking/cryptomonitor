import socketio ,json ,ssl ,redis
import MySQLdb as mdb
import time
pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 3 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
# print r.get('a')
platform = 5
conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
cursor = conn.cursor()
cursor.execute('SELECT * FROM currency_match WHERE is_mexc = 1 AND is_enabled = 1 AND (is_huobi = 1 OR is_okex = 1 OR is_gate = 1 OR is_biance = 1 OR is_aex = 1 OR is_kucoin = 1 OR is_coinex = 1 OR is_lbank = 1 OR is_hotcoin = 1 OR is_ftx = 1 OR is_df = 1)' )
results = cursor.fetchall()
symbol = []
for row in results:
    currency_name = row[3]
    quote_name = row[4]
    # print(currency_name+'-'+quote_name)
    symbol.append(currency_name+'_'+quote_name)
sio = socketio.Client(ssl_verify=False,)
# print(symbol)
@sio.event
def connect():
    print("I'm connected!")
    # while True:
        # sio.emit('get.depth',{'symbol':'BTC_USDT'})
        # sio.emit('get.depth',{'symbol':'ETH_USDT'})
    sio.emit('sub.limit.depth',{"req":"sub.limit.depth","symbol":'ETH_USDT',"depth": 5})
    # sio.emit('sub.limit.depth',{"req":"sub.limit.depth","symbol":'BTC_USDT',"depth": 5})
    # for row in symbol:
    #     sio.emit('sub.limit.depth',{"req":"sub.limit.depth","symbol":row,"depth": 5})
        # sio.emit('get.depth',{"req":"get.depth","symbol":row})


@sio.event
def message(data):
    print('I received a message!')

# @sio.on('')
def on_message(data):
    # return 1
    # print(data)
    smb = data['symbol'].replace("_", "", 1)
    # print(smb)
    if data['data']:
        bids = data['data']['bids']
        asks = data['data']['asks']
        key1 = "%s_%d_%d"%(smb.upper(), platform,1)
        key2 = "%s_%d_%d"%(smb.upper(), platform,2)
        r.set(key1, json.dumps(bids),ex=60)
        # r.expire(key1,60)
        r.set(key2, json.dumps(asks),ex=60)
        # r.expire(key2,60)
        # print(bids)

    # data = json.loads(data)
    # if data['symbol'] == 'ETH_USDT':
    #     print(data)

@sio.event
def disconnect():
    print('disconnected from server')

sio.on('push.limit.depth',on_message)
sio.connect('wss://wbs.mexc.com/raw/ws',transports=['websocket'])
sio.wait()

