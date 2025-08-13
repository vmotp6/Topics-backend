# 遠端資料庫連線設定說明

## 1. 修改 IP 地址

請將以下檔案中的 IP 地址改為您的電腦實際 IP 地址：

### 後端設定 (`Topics-backend/config.py`)
```python
REMOTE_DB_CONFIG = {
    'host': '172.16.222.109',  # 您的電腦 Wi-Fi IP 地址
    'user': 'root',
    'password': '',
    'database': 'topics_good',
    'charset': 'utf8mb4',
    'port': 3306
}
```

### 前端設定 (`Topics-frontend/backend/config.py`)
```python
REMOTE_DB_CONFIG = {
    'host': '172.16.222.109',  # 您的電腦 Wi-Fi IP 地址
    'user': 'root',
    'password': '',
    'database': 'topics_good',
    'charset': 'utf8mb4',
    'port': 3306
}
```

## 2. 查詢您的電腦 IP 地址

### Windows 系統：
1. 開啟命令提示字元 (cmd)
2. 輸入指令：`ipconfig`
3. 找到 "IPv4 地址" 欄位，通常是 `192.168.x.x` 格式

### macOS/Linux 系統：
1. 開啟終端機
2. 輸入指令：`ifconfig` 或 `ip addr`
3. 找到您的網路介面 IP 地址

## 3. MySQL 遠端連線設定

### 步驟 1：修改 MySQL 設定檔
找到 MySQL 的 `my.ini` 或 `my.cnf` 檔案，確保有以下設定：
```ini
[mysqld]
bind-address = 0.0.0.0
```

### 步驟 2：建立遠端連線使用者
在 MySQL 中執行以下 SQL 指令：
```sql
-- 建立允許遠端連線的使用者
CREATE USER 'root'@'%' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

### 步驟 3：重啟 MySQL 服務
```bash
# Windows
net stop mysql
net start mysql

# macOS/Linux
sudo systemctl restart mysql
```

## 4. 防火牆設定

### Windows 防火牆：
1. 開啟 Windows Defender 防火牆
2. 點選 "進階設定"
3. 新增輸入規則，允許端口 3306

### 或使用命令列：
```cmd
netsh advfirewall firewall add rule name="MySQL" dir=in action=allow protocol=TCP localport=3306
```

## 5. 測試連線

### 使用 MySQL 客戶端測試：
```bash
mysql -h 您的IP地址 -u root -p
```

### 或使用其他電腦測試：
```bash
telnet 您的IP地址 3306
```

## 6. 切換連線模式

在設定檔案中，您可以透過修改 `USE_REMOTE_DB` 變數來切換連線模式：

```python
# True = 使用遠端連線
# False = 使用本地連線
USE_REMOTE_DB = True
```

## 7. 注意事項

1. **安全性**：遠端連線會增加安全風險，建議：
   - 設定強密碼
   - 限制允許連線的 IP 地址
   - 使用 VPN 或 SSH 隧道

2. **網路穩定性**：確保網路連線穩定，避免資料庫連線中斷

3. **效能**：遠端連線可能比本地連線慢，建議在相同網路環境下使用

## 8. 故障排除

### 連線被拒絕：
- 檢查 IP 地址是否正確
- 確認 MySQL 服務正在運行
- 檢查防火牆設定

### 認證失敗：
- 確認使用者名稱和密碼
- 檢查使用者是否有遠端連線權限

### 連線超時：
- 檢查網路連線
- 確認 MySQL 設定允許遠端連線
