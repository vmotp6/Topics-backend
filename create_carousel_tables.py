#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
輪播系統資料庫表創建腳本
"""

import pymysql
import sys
import os

# 添加backend目錄到Python路徑
sys.path.append(os.path.join(os.path.dirname(__file__), 'backend'))

from config import DB_CONFIG

def create_carousel_tables():
    """創建輪播相關的資料庫表"""
    
    # 創建輪播項目表的SQL
    create_carousel_items_sql = """
    CREATE TABLE IF NOT EXISTS carousel_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL COMMENT '輪播標題',
        description TEXT COMMENT '輪播描述',
        image_url VARCHAR(500) NOT NULL COMMENT '圖片URL',
        button_text VARCHAR(100) COMMENT '按鈕文字',
        button_link VARCHAR(500) COMMENT '按鈕連結',
        display_order INT DEFAULT 0 COMMENT '顯示順序',
        is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用 (1:啟用, 0:停用)',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '創建時間',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='輪播項目表';
    """
    
    # 創建輪播設定表的SQL
    create_carousel_settings_sql = """
    CREATE TABLE IF NOT EXISTS carousel_settings (
        id INT PRIMARY KEY DEFAULT 1,
        auto_slide_interval INT DEFAULT 5000 COMMENT '自動輪播間隔時間(毫秒)',
        enable_auto_slide TINYINT(1) DEFAULT 1 COMMENT '是否啟用自動輪播 (1:啟用, 0:停用)',
        enable_controls TINYINT(1) DEFAULT 1 COMMENT '是否顯示控制按鈕 (1:顯示, 0:隱藏)',
        enable_indicators TINYINT(1) DEFAULT 1 COMMENT '是否顯示指示點 (1:顯示, 0:隱藏)',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '創建時間',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='輪播設定表';
    """
    
    # 插入預設輪播項目的SQL
    insert_carousel_items_sql = """
    INSERT IGNORE INTO carousel_items (title, description, image_url, button_text, button_link, display_order, is_active) VALUES
    ('歡迎來到康寧大學招生平台', '連結學術研究與產業發展，創造雙贏的產學合作機會', 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2071&q=80', '了解更多', 'QA.php', 1, 1),
    ('AI智能產學合作', '運用最新的人工智慧技術，為您的產學合作項目提供智能建議與分析', 'https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80', '體驗AI服務', 'AI.php', 2, 1),
    ('專業團隊支持', '我們擁有豐富的產學合作經驗，為您提供全方位的專業服務與支持', 'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80', '聯繫我們', 'chat_settings.php', 3, 1),
    ('創新合作模式', '探索創新的產學合作模式，促進學術研究與產業應用的深度融合', 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80', '探索合作', 'QA.php', 4, 1);
    """
    
    # 插入預設輪播設定的SQL
    insert_carousel_settings_sql = """
    INSERT IGNORE INTO carousel_settings (id, auto_slide_interval, enable_auto_slide, enable_controls, enable_indicators) VALUES
    (1, 5000, 1, 1, 1);
    """
    
    try:
        # 建立資料庫連線
        conn = pymysql.connect(**DB_CONFIG)
        print("[OK] 資料庫連線成功")
        
        with conn.cursor() as cursor:
            # 創建輪播項目表
            print("[INFO] 創建輪播項目表...")
            cursor.execute(create_carousel_items_sql)
            print("[OK] 輪播項目表創建成功")
            
            # 創建輪播設定表
            print("[INFO] 創建輪播設定表...")
            cursor.execute(create_carousel_settings_sql)
            print("[OK] 輪播設定表創建成功")
            
            # 插入預設輪播項目
            print("[INFO] 插入預設輪播項目...")
            cursor.execute(insert_carousel_items_sql)
            print("[OK] 預設輪播項目插入成功")
            
            # 插入預設輪播設定
            print("[INFO] 插入預設輪播設定...")
            cursor.execute(insert_carousel_settings_sql)
            print("[OK] 預設輪播設定插入成功")
            
            # 提交事務
            conn.commit()
            
        print("\n[SUCCESS] 輪播系統設置完成！")
        print("[INFO] 已創建的表:")
        print("   - carousel_items (輪播項目表)")
        print("   - carousel_settings (輪播設定表)")
        print("\n[INFO] 預設數據已插入，包含4個輪播項目和基本設定")
        print("\n[INFO] 現在您可以:")
        print("   1. 重啟後台API服務器")
        print("   2. 訪問後台管理頁面進行輪播管理")
        print("   3. 前台首頁將自動從API載入輪播數據")
        
    except pymysql.Error as e:
        print(f"[ERROR] 資料庫操作錯誤：{e}")
    except Exception as e:
        print(f"[ERROR] 未知錯誤：{e}")
    finally:
        if 'conn' in locals():
            conn.close()

if __name__ == "__main__":
    create_carousel_tables()
