import websocket
import json
import threading
import time
from threading import Lock
import datetime
import os
import redis
import pymysql

# 1.0version
# 连接的WebSocket地址
url = "wss://big.one/ws/v2"
# 设置请求头，使用json协议
headers = {"Sec-WebSocket-Protocol": "json"}

# 本地维护的全量深度数据，使用字典来存储每个交易对的数据
local_full_depth = {}

# 本地维护的 changeId，使用字典来存储每个交易对的 changeId
local_change_id = {}

# 全局 WebSocket 实例
ws = None
# 重连标志
should_reconnect = True
# 锁对象，用于线程安全
ws_lock = Lock()

# 连接 Redis
pool = redis.ConnectionPool(host=os.getenv('REDIS_HOST', '127.0.0.1'), port=int(os.getenv('REDIS_PORT', '6379')), password=os.getenv('REDIS_PASSWORD') or None, decode_responses=True, db=11)

# 使用连接池创建 Redis 客户端实例
redis_client = redis.Redis(connection_pool=pool)

# 连接数据库
db_connection = pymysql.connect(
    host=os.getenv('DB_HOST', '127.0.0.1'),
    user=os.getenv('DB_USERNAME', 'tool'),
    password=os.getenv('DB_PASSWORD', ''),
    database=os.getenv('DB_DATABASE', 'tool'),
    cursorclass=pymysql.cursors.DictCursor
)


def get_trading_pairs():
    """从数据库中获取交易对及其对应的 id"""
    try:
        with db_connection.cursor() as cursor:
            sql = "SELECT id, currency_name, quote_name FROM currency_match WHERE is_bigone = 1 AND is_enabled = 1"
            cursor.execute(sql)
            results = cursor.fetchall()
            trading_pairs = [(row['id'], f"{row['currency_name']}-{row['quote_name']}") for row in results]
            return trading_pairs
    except Exception as e:
        print(f"获取交易对时出错: {e}")
        return []


def on_open(ws):
    """WebSocket 连接打开时的回调函数"""
    trading_pairs = get_trading_pairs()
    for pair_id, trading_pair in trading_pairs:
        # 初始化每个交易对的本地深度数据和 changeId
        local_full_depth[trading_pair] = {
            "bids": [],
            "asks": []
        }
        local_change_id[trading_pair] = 0
        # 订阅每个交易对的市场深度数据的请求，使用 currency_match 表的 id 作为 request_id
        subscribe_request = {
            "requestId": str(pair_id),
            "subscribeMarketDepthRequest": {"market": trading_pair}
        }
        ws.send(json.dumps(subscribe_request))


def extract_top_5(data):
    """提取买卖盘各前 5 档数据"""
    bids = sorted(data.get("bids", []), key=lambda x: float(x[0]), reverse=True)[:5]
    asks = sorted(data.get("asks", []), key=lambda x: float(x[0]))[:5]
    return {
        "bids": bids,
        "asks": asks
    }


def update_local_full_depth(update, trading_pair):
    """更新本地全量深度数据"""
    global local_full_depth, local_change_id
    new_change_id = int(update.get("changeId", 0))
    prev_id = int(update.get("prevId", 0))
    if new_change_id <= local_change_id.get(trading_pair, 0):
        print(f"忽略旧的更新，当前 changeId: {local_change_id[trading_pair]}, 新 changeId: {new_change_id}")
        return
    if prev_id != local_change_id.get(trading_pair, 0):
        print(f"数据不连续，当前 local changeId: {local_change_id[trading_pair]}, 接收到的 prevId: {prev_id}, 接收到的 changeId: {new_change_id}")
        # 尝试重新订阅深度数据
        global ws
        with ws_lock:
            if ws and ws.sock and ws.sock.connected:
                trading_pairs = get_trading_pairs()
                for pair_id, pair in trading_pairs:
                    if pair == trading_pair:
                        subscribe_request = {
                            "requestId": str(pair_id),
                            "subscribeMarketDepthRequest": {"market": trading_pair}
                        }
                        ws.send(json.dumps(subscribe_request))
                        print("尝试重新订阅深度数据以恢复连续性")
                        break
        return

    print(f"开始更新深度数据，新 changeId: {new_change_id}")
    # 更新 changeId
    local_change_id[trading_pair] = new_change_id

    def process_side(side, new_data):
        temp_data = local_full_depth[trading_pair][side].copy()
        for item in new_data:
            price = float(item["price"])
            amount = float(item["amount"])
            found = False
            for i, existing_item in enumerate(temp_data):
                if existing_item[0] == price:
                    if amount == 0:
                        # 如果数量为 0，移除该档位
                        del temp_data[i]
                    else:
                        # 更新该档位的数量
                        temp_data[i] = [price, amount]
                        print(f"更新 {side} 中价格 {price} 的档位数量为 {amount}")
                    found = True
                    break
            if not found and amount > 0:
                # 如果不存在该档位且数量大于 0，插入新档位
                temp_data.append([price, amount])
                print(f"在 {side} 中插入价格 {price}，数量 {amount} 的新档位")
        local_full_depth[trading_pair][side] = temp_data

    depth_data = update.get("depth", {})
    process_side("bids", depth_data.get("bids", []))
    process_side("asks", depth_data.get("asks", []))


def on_message(ws, message):
    """WebSocket 接收到消息时的回调函数"""
    try:
        data = json.loads(message)
        timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        # print(f"[{timestamp}] Received raw data: {data}")  # 添加时间戳

        if "depthSnapshot" in data:
            trading_pair = data["depthSnapshot"]["depth"]["market"]
            snapshot = data["depthSnapshot"]["depth"]
            # 保存全量深度数据
            local_full_depth[trading_pair] = {
                "bids": [[float(item["price"]), float(item["amount"])] for item in snapshot.get("bids", [])],
                "asks": [[float(item["price"]), float(item["amount"])] for item in snapshot.get("asks", [])]
            }
            # 假设 depthSnapshot 包含 changeId 字段
            local_change_id[trading_pair] = int(data["depthSnapshot"].get("changeId", 0))
            print(f"初始全量深度快照数据: {trading_pair}")
            # print(local_full_depth[trading_pair])
            top_5 = extract_top_5(local_full_depth[trading_pair])
            print(f"初始 5 档深度数据: {trading_pair}")
            print(top_5)

            # 将买卖 5 档数据存入 Redis
            trading_pair_upper = trading_pair.replace("-", "").upper()
            top_5_bids_key = f"{trading_pair_upper}_14_1"
            top_5_asks_key = f"{trading_pair_upper}_14_2"
            redis_client.set(top_5_bids_key, json.dumps(top_5["bids"]))
            redis_client.set(top_5_asks_key, json.dumps(top_5["asks"]))

        elif "depthUpdate" in data:
            trading_pair = data["depthUpdate"]["depth"]["market"]
            print(f"[{timestamp}] Received depth update for {trading_pair}: {data}")
            update = data["depthUpdate"]
            # 更新本地全量深度数据
            update_local_full_depth(update, trading_pair)
            top_5 = extract_top_5(local_full_depth[trading_pair])
            print(f"更新后的 5 档深度数据: {trading_pair}")
            print(top_5)

            # 将买卖 5 档数据存入 Redis
            trading_pair_upper = trading_pair.replace("-", "").upper()
            top_5_bids_key = f"{trading_pair_upper}_14_1"
            top_5_asks_key = f"{trading_pair_upper}_14_2"
            redis_client.set(top_5_bids_key, json.dumps(top_5["bids"]))
            redis_client.set(top_5_asks_key, json.dumps(top_5["asks"]))

        elif "error" in data:
            print(f"错误信息: {data['error']['message']}")
    except json.JSONDecodeError:
        print("接收到的消息格式错误，无法解析")


def on_error(ws, error):
    """WebSocket 连接出错时的回调函数"""
    import traceback
    print(f"连接错误: {error}")
    traceback.print_exc()


def on_close(ws):
    """WebSocket 连接关闭时的回调函数"""
    global should_reconnect
    print("连接已关闭")
    print(f"Connection closed with code: {ws.close_code}, reason: {ws.close_reason}")
    should_reconnect = True


def keep_alive():
    """保持 WebSocket 连接的心跳线程"""
    global ws, should_reconnect
    while True:
        with ws_lock:
            if ws and ws.sock and ws.sock.connected:
                try:
                    ws.send('{"type": "ping"}')
                except Exception as e:
                    print(f"发送心跳消息时出错: {e}")
            else:
                if should_reconnect:
                    print("尝试重新连接...")
                    try:
                        if ws:
                            ws.close()  # 确保当前连接已关闭
                        ws = websocket.WebSocketApp(url,
                                                    header=headers,
                                                    on_open=on_open,
                                                    on_message=on_message,
                                                    on_error=on_error,
                                                    on_close=on_close)
                        ws_thread = threading.Thread(target=ws.run_forever)
                        ws_thread.daemon = True
                        ws_thread.start()
                        should_reconnect = False
                    except Exception as e:
                        print(f"重新连接失败: {e}")
        time.sleep(10)  # 每10秒发送一次ping消息


if __name__ == "__main__":
    with ws_lock:
        ws = websocket.WebSocketApp(url,
                                    header=headers,
                                    on_open=on_open,
                                    on_message=on_message,
                                    on_error=on_error,
                                    on_close=on_close)
        # 启动心跳线程
        keep_alive_thread = threading.Thread(target=keep_alive)
        keep_alive_thread.daemon = True
        keep_alive_thread.start()
        ws.run_forever()
