# 資料庫配置檔案
# 集中管理所有資料庫連線設定

# 資料庫主機設定
DB_HOST = '100.79.58.120'  # 遠端資料庫主機
DB_USER = 'root'
DB_PASSWORD = ''
DB_NAME = 'topics_good'
DB_CHARSET = 'utf8mb4'

# 資料庫連線配置字典
DB_CONFIG = {
    'host': DB_HOST,
    'user': DB_USER,
    'password': DB_PASSWORD,
    'database': DB_NAME,
    'charset': DB_CHARSET
}

# API 服務器設定
API_HOST = '0.0.0.0'  # 允許所有IP連線
API_PORT = 5001

# 前端服務器設定
FRONTEND_HOST = '0.0.0.0'  # 允許所有IP連線
FRONTEND_PORT = 5000
