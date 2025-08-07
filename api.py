from flask import Flask, request, jsonify
from flask_cors import CORS
import pymysql
from datetime import datetime

app = Flask(__name__)
CORS(app)

# 連接資料庫
db = pymysql.connect(
    host="localhost",
    user="root",
    password="",
    database="topics_good",
    charset="utf8mb4"
)

# 管理員登入驗證
@app.route('/admin/login', methods=['POST'])
def admin_login():
    username = request.form.get('username')
    password = request.form.get('password')
    
    # 檢查是否為管理員帳號
    if username == 'admin' and password == 'admin123':
        return jsonify({
            "message": "登入成功",
            "username": username,
            "role": "admin"
        }), 200
    else:
        return jsonify({"message": "管理員帳號或密碼錯誤"}), 401

# 獲取所有使用者資料
@app.route('/admin/users', methods=['GET'])
def get_all_users():
    try:
        with db.cursor() as cursor:
            sql = "SELECT id, username, name, email, role, created_at FROM user ORDER BY id DESC"
            cursor.execute(sql)
            users = cursor.fetchall()
            
            user_list = []
            for user in users:
                user_dict = {
                    "id": user[0],
                    "username": user[1],
                    "name": user[2],
                    "email": user[3],
                    "role": user[4],
                    "created_at": user[5].strftime('%Y-%m-%d %H:%M:%S') if user[5] else 'N/A'
                }
                user_list.append(user_dict)
            
            return jsonify({
                "users": user_list,
                "total": len(user_list)
            }), 200

    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗，請稍後再試。"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取使用者資料失敗，發生未知錯誤。"}), 500

# 獲取使用者統計資料
@app.route('/admin/stats', methods=['GET'])
def get_user_stats():
    try:
        with db.cursor() as cursor:
            # 總使用者數
            sql_total = "SELECT COUNT(*) FROM user"
            cursor.execute(sql_total)
            total_users = cursor.fetchone()[0]
            
            # 學生數
            sql_students = "SELECT COUNT(*) FROM user WHERE role = 'student'"
            cursor.execute(sql_students)
            total_students = cursor.fetchone()[0]
            
            # 老師數
            sql_teachers = "SELECT COUNT(*) FROM user WHERE role = 'teacher'"
            cursor.execute(sql_teachers)
            total_teachers = cursor.fetchone()[0]
            
            return jsonify({
                "total_users": total_users,
                "total_students": total_students,
                "total_teachers": total_teachers
            }), 200

    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "獲取統計資料失敗，請稍後再試。"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "獲取統計資料失敗，發生未知錯誤。"}), 500

# 刪除使用者
@app.route('/admin/users/<int:user_id>', methods=['DELETE'])
def delete_user(user_id):
    try:
        with db.cursor() as cursor:
            # 檢查使用者是否存在
            sql_check = "SELECT COUNT(*) FROM user WHERE id = %s"
            cursor.execute(sql_check, (user_id,))
            if cursor.fetchone()[0] == 0:
                return jsonify({"message": "使用者不存在"}), 404
            
            # 刪除使用者
            sql_delete = "DELETE FROM user WHERE id = %s"
            cursor.execute(sql_delete, (user_id,))
            db.commit()
            
            return jsonify({"message": "使用者刪除成功"}), 200

    except pymysql.Error as e:
        db.rollback()
        print(f"資料庫刪除錯誤：{e}")
        return jsonify({"message": "刪除失敗，請稍後再試。"}), 500
    except Exception as e:
        db.rollback()
        print(f"未知錯誤：{e}")
        return jsonify({"message": "刪除失敗，發生未知錯誤。"}), 500

# 搜尋使用者
@app.route('/admin/users/search', methods=['GET'])
def search_users():
    query = request.args.get('q', '')
    
    try:
        with db.cursor() as cursor:
            sql = """
                SELECT id, username, name, email, role, created_at 
                FROM user 
                WHERE username LIKE %s OR name LIKE %s OR email LIKE %s
                ORDER BY id DESC
            """
            search_term = f"%{query}%"
            cursor.execute(sql, (search_term, search_term, search_term))
            users = cursor.fetchall()
            
            user_list = []
            for user in users:
                user_dict = {
                    "id": user[0],
                    "username": user[1],
                    "name": user[2],
                    "email": user[3],
                    "role": user[4],
                    "created_at": user[5].strftime('%Y-%m-%d %H:%M:%S') if user[5] else 'N/A'
                }
                user_list.append(user_dict)
            
            return jsonify({
                "users": user_list,
                "total": len(user_list)
            }), 200

    except pymysql.Error as e:
        print(f"資料庫查詢錯誤：{e}")
        return jsonify({"message": "搜尋失敗，請稍後再試。"}), 500
    except Exception as e:
        print(f"未知錯誤：{e}")
        return jsonify({"message": "搜尋失敗，發生未知錯誤。"}), 500

# 啟動伺服器
if __name__ == '__main__':
    app.run(debug=True, port=5001) 