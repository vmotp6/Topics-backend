# 資料庫連線設定檔案
# 請根據您的網路環境修改以下設定

# 本地開發設定
LOCAL_DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'topics_good',
    'charset': 'utf8mb4',
    'port': 3306
}

# 遠端連線設定 (請修改為您的電腦 IP 地址)
REMOTE_DB_CONFIG = {
    'host': '172.16.222.109',  # 您的電腦 Wi-Fi IP 地址
    'user': 'root',
    'password': '',
    'database': 'topics_good',
    'charset': 'utf8mb4',
    'port': 3306
}

# 選擇使用哪個設定 (True = 遠端連線, False = 本地連線)
USE_REMOTE_DB = True

# 根據設定選擇資料庫配置
DB_CONFIG = REMOTE_DB_CONFIG if USE_REMOTE_DB else LOCAL_DB_CONFIG
