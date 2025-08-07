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

### 1. 啟動 Python API 伺服器
```bash
cd Topics-backend
python api.py
```

### 2. 啟動 XAMPP
- 啟動 Apache 服務
- 啟動 MySQL 服務

### 3. 訪問系統
- 登入頁面：`http://localhost/Topics-backend/login.php`
- 管理介面：`http://localhost/Topics-backend/index.php`

## API 端點

### 管理員登入
```
POST /admin/login
```

### 獲取所有使用者
```
GET /admin/users
```

### 獲取統計資料
```
GET /admin/stats
```

### 刪除使用者
```
DELETE /admin/users/{user_id}
```

### 搜尋使用者
```
GET /admin/users/search?q={query}
```

## 功能特色

- ✅ 現代化的後台管理介面
- ✅ 響應式設計，支援手機瀏覽
- ✅ 側邊欄收合功能
- ✅ 即時搜尋功能
- ✅ 使用者統計儀表板
- ✅ 安全的登入驗證
- ✅ 前後端分離架構

## 預設管理員帳號

- 帳號：`admin`
- 密碼：`admin123`

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