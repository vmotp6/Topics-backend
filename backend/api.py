from flask import Flask, request, jsonify
from flask_cors import CORS
import pymysql
from datetime import datetime
from config import DB_CONFIG

app = Flask(__name__)
CORS(app)

def get_db_connection():
    """建立資料庫連線"""
    return pymysql.connect(**DB_CONFIG)

# 管理員登入驗證
@app.route('/admin/login', methods=['POST'])
def admin_login():
    username = request.form.get('username')
    password = request.form.get('password')
    
    # 預設管理員帳號密碼
    if username == 'a' and password == 'a':
        return jsonify({
            "message": "登入成功",
            "username": username,
            "role": "admin"
        }), 200
    else:
        return jsonify({"message": "帳號或密碼錯誤"}), 401

# 獲取所有使用者資料
@app.route('/admin/users', methods=['GET'])
def get_all_users():
    try:
        # 獲取排序參數
        sort_by = request.args.get('sort_by', 'id')  # 預設按ID排序
        sort_order = request.args.get('sort_order', 'desc')  # 預設降序
        
        # 驗證排序欄位
        allowed_sort_fields = ['id', 'username', 'name', 'email', 'role', 'status']
        if sort_by not in allowed_sort_fields:
            sort_by = 'id'
        
        # 驗證排序方向
        if sort_order not in ['asc', 'desc']:
            sort_order = 'desc'
        
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            sql = f"""
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.role,
                    u.status
                FROM user u
                ORDER BY u.{sort_by} {sort_order.upper()}
            """
            cursor.execute(sql)
            users = cursor.fetchall()
            
            return jsonify({
                "users": users,
                "sort_by": sort_by,
                "sort_order": sort_order
            }), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗"}), 500
    finally:
        if conn:
            conn.close()

# 搜尋使用者
@app.route('/admin/users/search', methods=['GET'])
def search_users():
    query = request.args.get('q', '')
    
    if not query or len(query) < 2:
        return jsonify({"message": "搜尋關鍵字至少需要2個字元"}), 400
    
    try:
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            sql = """
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.role,
                    u.status
                FROM user u
                WHERE u.username LIKE %s 
                   OR u.name LIKE %s 
                   OR u.email LIKE %s
                ORDER BY u.id DESC
            """
            search_term = f"%{query}%"
            cursor.execute(sql, (search_term, search_term, search_term))
            users = cursor.fetchall()
            
            return jsonify({"users": users}), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "搜尋失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "搜尋失敗"}), 500
    finally:
        if conn:
            conn.close()

# 獲取統計資料
@app.route('/admin/stats', methods=['GET'])
def get_stats():
    try:
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 總使用者數
            cursor.execute("SELECT COUNT(*) FROM user")
            total_users = cursor.fetchone()[0]
            
            # 學生數
            cursor.execute("SELECT COUNT(*) FROM user WHERE role = 'student'")
            total_students = cursor.fetchone()[0]
            
            # 老師數
            cursor.execute("SELECT COUNT(*) FROM user WHERE role = 'teacher'")
            total_teachers = cursor.fetchone()[0]
            
            # 狀態統計
            cursor.execute("SELECT COUNT(*) FROM user WHERE status = 0")
            disabled_users = cursor.fetchone()[0]
            
            cursor.execute("SELECT COUNT(*) FROM user WHERE status = 1")
            enabled_users = cursor.fetchone()[0]
            
            # 今日新增使用者（簡化為總數）
            today_new_users = total_users
            
            return jsonify({
                "total_users": total_users,
                "total_students": total_students,
                "total_teachers": total_teachers,
                "today_new_users": today_new_users,
                "disabled_users": disabled_users,
                "enabled_users": enabled_users
            }), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取統計資料失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取統計資料失敗"}), 500
    finally:
        if conn:
            conn.close()

# 刪除使用者
@app.route('/admin/users/<int:user_id>', methods=['DELETE'])
def delete_user(user_id):
    try:
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查使用者是否存在
            cursor.execute("SELECT id FROM user WHERE id = %s", (user_id,))
            user = cursor.fetchone()
            
            if not user:
                return jsonify({"message": "使用者不存在"}), 404
            
            # 刪除使用者
            cursor.execute("DELETE FROM user WHERE id = %s", (user_id,))
            conn.commit()
            
            return jsonify({"message": "使用者刪除成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "刪除失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "刪除失敗"}), 500
    finally:
        if conn:
            conn.close()

# 獲取單一使用者詳細資料
@app.route('/admin/users/<int:user_id>', methods=['GET'])
def get_user_detail(user_id):
    try:
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            sql = """
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.role,
                    u.status
                FROM user u
                WHERE u.id = %s
            """
            cursor.execute(sql, (user_id,))
            user = cursor.fetchone()
            
            if not user:
                return jsonify({"message": "使用者不存在"}), 404
            
            return jsonify({"user": user}), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗"}), 500
    finally:
        if conn:
            conn.close()

# 更新使用者資料
@app.route('/admin/users/<int:user_id>', methods=['PUT'])
def update_user(user_id):
    try:
        data = request.get_json()
        name = data.get('name')
        email = data.get('email')
        role = data.get('role')
        status = data.get('status')
        
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查使用者是否存在
            cursor.execute("SELECT id FROM user WHERE id = %s", (user_id,))
            user = cursor.fetchone()
            
            if not user:
                return jsonify({"message": "使用者不存在"}), 404
            
            # 更新使用者基本資料
            cursor.execute(
                "UPDATE user SET name = %s, email = %s, role = %s, status = %s WHERE id = %s",
                (name, email, role, status, user_id)
            )
            
            conn.commit()
            return jsonify({"message": "使用者資料更新成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "更新失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "更新失敗"}), 500
    finally:
        if conn:
            conn.close()

# 更新使用者狀態
@app.route('/admin/users/<int:user_id>/status', methods=['PUT'])
def update_user_status(user_id):
    try:
        data = request.get_json()
        status = data.get('status')
        
        if status not in [0, 1]:
            return jsonify({"message": "無效的狀態值"}), 400
        
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查使用者是否存在
            cursor.execute("SELECT id FROM user WHERE id = %s", (user_id,))
            user = cursor.fetchone()
            
            if not user:
                return jsonify({"message": "使用者不存在"}), 404
            
            # 更新使用者狀態
            cursor.execute("UPDATE user SET status = %s WHERE id = %s", (status, user_id))
            conn.commit()
            
            return jsonify({"message": "使用者狀態更新成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "狀態更新失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "狀態更新失敗"}), 500
    finally:
        if conn:
            conn.close()

# 輪播管理相關API

# 獲取所有輪播項目
@app.route('/admin/carousel', methods=['GET'])
def get_carousel_items():
    try:
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            sql = """
                SELECT 
                    id,
                    title,
                    description,
                    image_url,
                    button_text,
                    button_link,
                    display_order,
                    is_active,
                    created_at,
                    updated_at
                FROM carousel_items
                ORDER BY display_order ASC, created_at ASC
            """
            cursor.execute(sql)
            items = cursor.fetchall()
            
            return jsonify({"carousel_items": items}), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取輪播資料失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取輪播資料失敗"}), 500
    finally:
        if conn:
            conn.close()

# 創建新的輪播項目
@app.route('/admin/carousel', methods=['POST'])
def create_carousel_item():
    try:
        data = request.get_json()
        title = data.get('title')
        description = data.get('description')
        image_url = data.get('image_url')
        button_text = data.get('button_text')
        button_link = data.get('button_link')
        display_order = data.get('display_order', 0)
        is_active = data.get('is_active', 1)
        
        if not title or not image_url:
            return jsonify({"message": "標題和圖片URL為必填項目"}), 400
        
        conn = get_db_connection()
        with conn.cursor() as cursor:
            sql = """
                INSERT INTO carousel_items 
                (title, description, image_url, button_text, button_link, display_order, is_active, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
            """
            cursor.execute(sql, (title, description, image_url, button_text, button_link, display_order, is_active))
            conn.commit()
            
            new_id = cursor.lastrowid
            return jsonify({"message": "輪播項目創建成功", "id": new_id}), 201
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "創建失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "創建失敗"}), 500
    finally:
        if conn:
            conn.close()

# 更新輪播項目
@app.route('/admin/carousel/<int:item_id>', methods=['PUT'])
def update_carousel_item(item_id):
    try:
        data = request.get_json()
        title = data.get('title')
        description = data.get('description')
        image_url = data.get('image_url')
        button_text = data.get('button_text')
        button_link = data.get('button_link')
        display_order = data.get('display_order')
        is_active = data.get('is_active')
        
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查項目是否存在
            cursor.execute("SELECT id FROM carousel_items WHERE id = %s", (item_id,))
            item = cursor.fetchone()
            
            if not item:
                return jsonify({"message": "輪播項目不存在"}), 404
            
            # 更新項目
            sql = """
                UPDATE carousel_items 
                SET title = %s, description = %s, image_url = %s, button_text = %s, 
                    button_link = %s, display_order = %s, is_active = %s, updated_at = NOW()
                WHERE id = %s
            """
            cursor.execute(sql, (title, description, image_url, button_text, button_link, display_order, is_active, item_id))
            conn.commit()
            
            return jsonify({"message": "輪播項目更新成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "更新失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "更新失敗"}), 500
    finally:
        if conn:
            conn.close()

# 刪除輪播項目
@app.route('/admin/carousel/<int:item_id>', methods=['DELETE'])
def delete_carousel_item(item_id):
    try:
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查項目是否存在
            cursor.execute("SELECT id FROM carousel_items WHERE id = %s", (item_id,))
            item = cursor.fetchone()
            
            if not item:
                return jsonify({"message": "輪播項目不存在"}), 404
            
            # 刪除項目
            cursor.execute("DELETE FROM carousel_items WHERE id = %s", (item_id,))
            conn.commit()
            
            return jsonify({"message": "輪播項目刪除成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "刪除失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "刪除失敗"}), 500
    finally:
        if conn:
            conn.close()

# 獲取輪播設定（前台用）
@app.route('/api/carousel', methods=['GET'])
def get_carousel_settings():
    try:
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            # 獲取輪播項目
            sql = """
                SELECT 
                    id,
                    title,
                    description,
                    image_url,
                    button_text,
                    button_link,
                    display_order
                FROM carousel_items
                WHERE is_active = 1
                ORDER BY display_order ASC, created_at ASC
            """
            cursor.execute(sql)
            items = cursor.fetchall()
            
            # 獲取輪播設定
            cursor.execute("SELECT * FROM carousel_settings WHERE id = 1")
            settings = cursor.fetchone()
            
            # 如果沒有設定，使用預設值
            if not settings:
                settings = {
                    'auto_slide_interval': 5000,
                    'enable_auto_slide': 1,
                    'enable_controls': 1,
                    'enable_indicators': 1
                }
            
            return jsonify({
                "items": items,
                "settings": settings
            }), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取輪播設定失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取輪播設定失敗"}), 500
    finally:
        if conn:
            conn.close()

# 獲取輪播設定（管理員用）
@app.route('/admin/carousel/settings', methods=['GET'])
def get_carousel_settings_admin():
    try:
        conn = get_db_connection()
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:
            # 獲取輪播設定
            cursor.execute("SELECT * FROM carousel_settings WHERE id = 1")
            settings = cursor.fetchone()
            
            # 如果沒有設定，使用預設值
            if not settings:
                settings = {
                    'auto_slide_interval': 5000,
                    'enable_auto_slide': 1,
                    'enable_controls': 1,
                    'enable_indicators': 1
                }
            
            return jsonify({"settings": settings}), 200
            
    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取輪播設定失敗"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取輪播設定失敗"}), 500
    finally:
        if conn:
            conn.close()

# 更新輪播設定
@app.route('/admin/carousel/settings', methods=['PUT'])
def update_carousel_settings():
    try:
        data = request.get_json()
        auto_slide_interval = data.get('auto_slide_interval', 5000)
        enable_auto_slide = data.get('enable_auto_slide', 1)
        enable_controls = data.get('enable_controls', 1)
        enable_indicators = data.get('enable_indicators', 1)
        
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 檢查設定是否存在
            cursor.execute("SELECT id FROM carousel_settings WHERE id = 1")
            settings = cursor.fetchone()
            
            if settings:
                # 更新現有設定
                sql = """
                    UPDATE carousel_settings 
                    SET auto_slide_interval = %s, enable_auto_slide = %s, 
                        enable_controls = %s, enable_indicators = %s, updated_at = NOW()
                    WHERE id = 1
                """
                cursor.execute(sql, (auto_slide_interval, enable_auto_slide, enable_controls, enable_indicators))
            else:
                # 創建新設定
                sql = """
                    INSERT INTO carousel_settings 
                    (id, auto_slide_interval, enable_auto_slide, enable_controls, enable_indicators, created_at, updated_at)
                    VALUES (1, %s, %s, %s, %s, NOW(), NOW())
                """
                cursor.execute(sql, (auto_slide_interval, enable_auto_slide, enable_controls, enable_indicators))
            
            conn.commit()
            return jsonify({"message": "輪播設定更新成功"}), 200
            
    except pymysql.Error as e:
        conn.rollback()
        print(f"資料庫操作錯誤：{e}")
        return jsonify({"message": "設定更新失敗"}), 500
    except Exception as e:
        conn.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "設定更新失敗"}), 500
    finally:
        if conn:
            conn.close()

# 健康檢查端點
@app.route('/health', methods=['GET'])
def health_check():
    try:
        conn = get_db_connection()
        with conn.cursor() as cursor:
            cursor.execute("SELECT 1")
            cursor.fetchone()
        conn.close()
        return jsonify({"status": "healthy", "database": "connected"}), 200
    except Exception as e:
        return jsonify({"status": "unhealthy", "error": str(e)}), 500

if __name__ == '__main__':
    print("[INFO] Topics 後台管理 API 啟動中...")
    print("[INFO] API 端點：http://localhost:5001")
    print("[INFO] 資料庫：topics_good")
    app.run(host='0.0.0.0', port=5001, debug=True)
