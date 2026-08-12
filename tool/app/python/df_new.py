#coding=utf-8
import websocket, json ,ssl ,redis
import MySQLdb as mdb
import time
import traceback

socket = 'wss://ascendex.com/0/api/pro/v1/stream'

pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379,db = 3 ,  password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
redis_client = redis.Redis(connection_pool=pool)
platform = 13
# 本地维护的全量深度数据，使用字典来存储每个交易对的数据
local_full_depth = {}

p = redis_client.pipeline(transaction=False)

def extract_top_5(data):
    """提取买卖盘各前 5 档数据"""
    bids = sorted(data.get("bids", []), key=lambda x: float(x[0]), reverse=True)[:5]
    asks = sorted(data.get("asks", []), key=lambda x: float(x[0]))[:5]
    return {
        "bids": bids,
        "asks": asks
    }

def update_local_full_depth(depth_data, trading_pair):
    """更新本地全量深度数据"""
    global local_full_depth
    if trading_pair not in local_full_depth:
        return

    def process_side(side, new_data):
        temp_data = local_full_depth[trading_pair][side].copy()
        for price, amount in new_data:
            # 将 amount 转换为浮点数
            amount = float(amount)
            found = False
            for i, existing_item in enumerate(temp_data):
                if existing_item[0] == price:
                    if amount == 0:
                        # 如果数量为 0，移除该档位
                        del temp_data[i]
                    else:
                        # 更新该档位的数量
                        # print(f"更新 {side} 中价格 {price} 的档位数量为 {amount}")
                        temp_data[i] = [price, amount]
                    found = True
                    break
            if not found and amount > 0:
                # 如果不存在该档位且数量大于 0，插入新档位
                # print(f"在 {side} 中插入价格 {price}，数量 {amount} 的新档位")
                temp_data.append([price, amount])
        local_full_depth[trading_pair][side] = temp_data

    # depth_data = update.get("depth", {})
    process_side("bids", depth_data.get("bids", []))
    process_side("asks", depth_data.get("asks", []))

def on_message(ws, message):
    # print(f"接收到的原始消息: {message}")
    try:
        obj = json.loads(message)
        # print(obj)
        if 'm' not in obj:
            return
        
        m = obj['m']
       
        if m == 'ping':
            print('receive ping' )
            dd = { 'op': 'pong'}
            ws.send(json.dumps(dd))
            return
        if 'symbol' not in obj:
            return
        
        smb = obj['symbol']
        smb = smb.replace("/", "", 1)
        if m == 'depth':
            update_data = obj['data']
            update_local_full_depth(update_data, smb)
            # 这里原代码中 trading_pair 未定义，应改为 smb
            if smb not in local_full_depth:
                return
            top_5 = extract_top_5(local_full_depth[smb])
            # print(f"更新后的 5 档深度数据: {trading_pair}")
            # print(top_5)

            # 将买卖 5 档数据存入 Redis
            trading_pair_upper = smb.upper()
            top_5_bids_key = f"{trading_pair_upper}_{platform}_1"
            top_5_asks_key = f"{trading_pair_upper}_{platform}_2"
            redis_client.set(top_5_bids_key, json.dumps(top_5["bids"]),ex=60)
            redis_client.set(top_5_asks_key, json.dumps(top_5["asks"]),ex=60)
            return
        elif m == 'depth-snapshot':
            snapshot = obj['data']
            local_full_depth[smb] = {
                "bids": [[float(price), float(size)] for price, size in snapshot.get("bids", [])],
                "asks": [[float(price), float(size)] for price, size in snapshot.get("asks", [])]
            }
            
            return
    except Exception as e:
        print(f"解析消息时出错: {e}")
        traceback.print_exc()
        print("--------")

def on_close(ws, s, c):
    print(ws)
    print(s)
    print(c)

def on_error(ws, error):
    print(ws)
    print(error)

def on_open(ws):
    print("WebSocket 连接已打开")
    # 获取所有交易对
    conn = mdb.connect(host='127.0.0.1', port=3306, user='tool', passwd=__import__('os').getenv('DB_PASSWORD', ''), db='tool', charset='utf8')
    cursor = conn.cursor()
    cursor.execute("SELECT id,currency_name,quote_name FROM currency_match WHERE  is_df = 1 AND is_enabled = 1")
    results = cursor.fetchall()
    print(f"查询结果数量: {len(results)}")
    for row in results:
        currency_name = row[1]
        quote_name = row[2]
        d = { "op": "req", "action": "depth-snapshot", "args": {"symbol": currency_name + '/' + quote_name}}
        ws.send(json.dumps(d))
        d = { "op": "sub", "id": row[0], "ch": "depth:" + currency_name + '/' + quote_name}
        ws.send(json.dumps(d))
    print(f"{len(results)} 条交易对")

def on_error(ws, error):
    import traceback
    print(f"WebSocket 连接出错: {type(error).__name__} - {error}")
    traceback.print_exc()

websocket.enableTrace(False)
ws = websocket.WebSocketApp(socket, on_message = on_message, on_error = on_error, on_close = on_close, on_open = on_open)
ping_text = {"op": "ping"}
try:
    print("尝试建立 WebSocket 连接...")
    ws.run_forever(sslopt={"cert_reqs": ssl.CERT_NONE}, ping_interval=15, ping_timeout=10, ping_payload=json.dumps(ping_text))
except Exception as e:
    print(f"运行 WebSocket 时出错: {e}")
