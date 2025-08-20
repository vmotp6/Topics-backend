# Topics 後台管理系統

## 架構說明

本系統採用前後端分離的架構：

- **前端**：PHP + HTML + CSS + JavaScript
- **後端**：Python Flask API
- **資料庫**：MySQL

## 檔案結構

```
Topics-backend/
├── api.py              # Python API 後端
├── start_api.py        # API 啟動腳本
├── requirements.txt    # Python 依賴套件
├── login.php           # 登入頁面
├── index.php           # 管理介面
└── README.md           # 說明文件
```

## 安裝需求

### Python 套件
```bash
pip install flask flask-cors pymysql
```

### 資料庫設定
- MySQL 伺服器
- 資料庫名稱：`topics_good`
- 使用者：`root`
- 密碼：`空`

## 啟動方式

### 1. 安裝 Python 依賴套件
```bash
cd Topics-backend
pip install -r requirements.txt
```

### 2. 啟動 Python API 伺服器
```bash
# 方法一：使用啟動腳本（推薦）
python start_api.py

# 方法二：直接啟動
python api.py
```

### 3. 啟動 XAMPP
- 啟動 Apache 服務
- 啟動 MySQL 服務



### 4. 訪問系統
- 登入頁面：`http://localhost/Topics-backend/login.php`
- 管理介面：`http://localhost/Topics-backend/index.php`
- API 健康檢查：`http://localhost:5001/health`

## API 端點

### 健康檢查
```
GET /health
```

### 管理員登入
```
POST /admin/login
Content-Type: application/x-www-form-urlencoded

username=admin&password=admin123
```

### 獲取所有使用者
```
GET /admin/users
```

### 獲取所有使用者 (支援排序)
```
GET /admin/users?sort_by={field}&sort_order={asc|desc}
```

支援的排序欄位：
- `id` - 用戶ID
- `username` - 帳號
- `name` - 姓名
- `email` - 電子郵件
- `role` - 角色
- `status` - 狀態

排序方向：
- `asc` - 升序 (小到大)
- `desc` - 降序 (大到小)

範例：
```
GET /admin/users?sort_by=username&sort_order=asc
GET /admin/users?sort_by=id&sort_order=desc
```

### 獲取統計資料
```
GET /admin/stats
```

### 搜尋使用者
```
GET /admin/users/search?q={query}
```

### 獲取單一使用者詳細資料
```
GET /admin/users/{user_id}
```

### 更新使用者資料
```
PUT /admin/users/{user_id}
Content-Type: application/json

{
    "name": "新姓名",
    "email": "new@email.com",
    "role": "student",
    "status": 1
}
```

### 更新使用者狀態
```
PUT /admin/users/{user_id}/status
Content-Type: application/json

{
    "status": 1
}
```

### 刪除使用者
```
DELETE /admin/users/{user_id}
```

## 功能特色

- ✅ 現代化的後台管理介面
- ✅ 響應式設計，支援手機瀏覽
- ✅ 側邊欄收合功能
- ✅ 即時搜尋功能
- ✅ 使用者統計儀表板
- ✅ 安全的登入驗證
- ✅ 前後端分離架構
- ✅ 完整的 RESTful API
- ✅ 資料庫連線管理
- ✅ 錯誤處理機制
- ✅ CORS 跨域支援
- ✅ 用戶狀態管理 (停用/啟用/待審核)
- ✅ 狀態統計顯示
- ✅ 快速狀態切換功能
- ✅ 表格排序功能 (支援所有欄位升序/降序)

## 預設管理員帳號

- 帳號：`admin`
- 密碼：`admin123`

## 用戶狀態說明

系統支援兩種用戶狀態：

- **0 = 停用**：用戶無法登入系統
- **1 = 啟用**：用戶可以正常使用系統

## 技術特色

### 前端
- 沉穩的灰色系配色
- 黑底白字的側邊欄
- 平滑的動畫效果
- 現代化的卡片式設計

### 後端
- RESTful API 設計
- 完整的錯誤處理
- 安全的資料庫操作
- CORS 跨域支援

## 注意事項

1. 確保 Python API 伺服器在 `http://localhost:5001` 運行
2. 確保 XAMPP 的 Apache 和 MySQL 服務已啟動
3. 確保資料庫連線設定正確
4. 如果遇到 CORS 錯誤，請檢查 API 伺服器是否正常運行
5. 確保 `topics_good` 資料庫中有 `user` 和 `teacher` 資料表
6. 建議使用啟動腳本 `start_api.py` 來啟動 API，它會自動檢查環境

## 故障排除

### API 無法啟動
- 檢查 Python 版本是否為 3.7 或更高
- 檢查是否已安裝所有依賴套件
- 檢查端口 5001 是否被佔用

### 資料庫連線失敗
- 檢查 XAMPP 是否已啟動
- 檢查 MySQL 服務是否正在運行
- 檢查資料庫名稱是否為 `topics_good`

### 前端無法連接到 API
- 檢查 API 是否在正確的端口運行
- 檢查瀏覽器控制台是否有 CORS 錯誤
- 檢查 API 健康檢查端點是否正常回應 