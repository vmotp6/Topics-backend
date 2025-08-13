#!/usr/bin/env python3
"""
Topics 後台管理 API 啟動腳本
"""

import os
import sys
import subprocess
import time

def check_python_version():
    """檢查Python版本"""
    if sys.version_info < (3, 7):
        print("❌ 需要 Python 3.7 或更高版本")
        sys.exit(1)
    print(f"✅ Python 版本: {sys.version}")

def install_requirements():
    """安裝依賴套件"""
    print("📦 檢查並安裝依賴套件...")
    try:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "-r", "requirements.txt"])
        print("✅ 依賴套件安裝完成")
    except subprocess.CalledProcessError:
        print("❌ 依賴套件安裝失敗")
        sys.exit(1)

def check_database():
    """檢查資料庫連線"""
    print("🔍 檢查資料庫連線...")
    try:
        import pymysql
        conn = pymysql.connect(
            host="localhost",
            user="root",
            password="",
            database="topics_good",
            charset="utf8mb4"
        )
        conn.close()
        print("✅ 資料庫連線正常")
    except Exception as e:
        print(f"❌ 資料庫連線失敗: {e}")
        print("請確保：")
        print("1. XAMPP 已啟動")
        print("2. MySQL 服務正在運行")
        print("3. topics_good 資料庫存在")
        sys.exit(1)

def start_api():
    """啟動API服務"""
    print("🚀 啟動 Topics 後台管理 API...")
    print("📍 API 端點：http://localhost:5001")
    print("📊 資料庫：topics_good")
    print("🔑 預設管理員帳號：admin / admin123")
    print("=" * 50)
    
    try:
        from api import app
        app.run(host='0.0.0.0', port=5001, debug=True)
    except KeyboardInterrupt:
        print("\n👋 API 服務已停止")
    except Exception as e:
        print(f"❌ API 啟動失敗: {e}")
        sys.exit(1)

def main():
    """主函數"""
    print("=" * 50)
    print("🎯 Topics 後台管理 API 啟動器")
    print("=" * 50)
    
    # 檢查Python版本
    check_python_version()
    
    # 安裝依賴
    install_requirements()
    
    # 檢查資料庫
    check_database()
    
    # 啟動API
    start_api()

if __name__ == "__main__":
    main()
