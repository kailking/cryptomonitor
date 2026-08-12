#coding=utf-8
import  json ,redis, os
# print data2

pool = redis.ConnectionPool(host = '127.0.0.1' , port = 6379, password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
r = redis.Redis(host = '127.0.0.1' , port = 6379, db = 3 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)


platforms = [1,2,3,4,8,9,10,5]


for platform in platforms:
    key1 = "%s_%d_%d"%('BTCUSDT', platform,1)
    data = r.get(key1)
    if not data:
        print(platform)
        print('reboot')
        if platform == 4:
            os.system('sudo supervisorctl restart gate_socket:*')
        elif platform == 6:
            os.system('sudo supervisorctl restart aex_socket:*')
        elif platform == 5:
            os.system('sudo supervisorctl restart mexc_socket:*')
        elif platform == 8:
            os.system('sudo supervisorctl restart kucoin_socket:*')
            os.system('sudo supervisorctl restart kucoin_socket_2:*')
            os.system('sudo supervisorctl restart kucoin_socket_3:*')
        elif platform == 9:
            os.system('sudo supervisorctl restart coinex_socket:*')
            os.system('sudo supervisorctl restart coinex_socket_2:*')
            os.system('sudo supervisorctl restart coinex_socket_3:*')
        elif platform == 10:
            os.system('sudo supervisorctl restart lbank_socket:*')
        elif platform == 2:
            os.system('sudo supervisorctl restart biance_socket:*')
        else:
            os.system('sudo supervisorctl restart all')
            exit()
    else:
        print('platform %d is running'%(platform,))
        
r2 = redis.Redis(host = '127.0.0.1' , port = 6379, db = 4 , password = __import__('os').getenv('REDIS_PASSWORD') or None,decode_responses=True)
key1 = "%s_%d_%d"%('BTCUSD',12,1)
data = r2.get(key1)
# print(data)
if not data:
    print(12)
    print('reboot')
    os.system('sudo supervisorctl restart ftx_socket:*')
    os.system('sudo supervisorctl restart ftx_socket_2:*')
    os.system('sudo supervisorctl restart ftx_socket_3:*')
    exit()
else:
    print('platform %d is running'%(12,))
# 
# print(13)
# print('reboot')
# os.system('sudo supervisorctl restart df_socket:*')
# key1 = "%s_%d_%d"%('BTCUSDT',13,1)
# data = r2.get(key1)
# # print(data)
# if not data:
    
#     exit()
# else:
#     print('platform %d is running'%(13,))
