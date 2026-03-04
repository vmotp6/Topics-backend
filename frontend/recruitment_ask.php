<?php
/**
 * 知識問答 - AI Search for KM
 * 老師輸入問題，系統從官方招生知識庫找依據並（若可）用 AI 整理回答。
 * 回答同時顯示資料建立時間與建立者。
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '知識問答';
$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$can_ask = in_array($normalized_role, ['TEA', 'ADM', 'STA', 'DI'], true);
if (!$can_ask) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>知識問答 - Topics 後台</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --success-color: #52c41a;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #e8e8e8;
            --background-color: #f5f5f5;
            --card-bg: #fff;
            --bubble-system: #f0f7ff;
            --icon-orange: #fa8c16;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard { display: flex; min-height: 100vh; }
        .content   { padding: 24px; flex: 1; display: flex; flex-direction: column;  margin-right:20px;}

        .breadcrumb {
            font-size: 14px;
            color: var(--text-secondary-color);
            margin-bottom: 16px;
        }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }

        .page-header {
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            gap: 12px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .page-title .help-icon {
            color: var(--text-secondary-color);
            font-size: 18px;
            cursor: help;
        }
        .btn-clear {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--primary-color);
            background: #fff;
            color: var(--primary-color);
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .btn-clear:hover { background: #e6f7ff; }

        .chat-area {
            flex: 1;
            background: linear-gradient(to bottom, #f8fbff, #ffffff);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            min-height: 420px;
            overflow-y: auto;
        }
        .msg {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            align-items: flex-start;
        }
        .msg.user { flex-direction: row-reverse; }
        .msg-avatar {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--primary-color);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .msg.msg-system .msg-avatar { background: var(--icon-orange); }
        .msg.user .msg-avatar       { background: var(--success-color); }

        .msg-bubble {
            padding: 14px 18px;
            border-radius: 16px;
            line-height: 1.7;
            background: #f4f9ff;
            border: 1px solid #dceeff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }

        .msg-bubble {
            transform: translateY(10px);
            opacity: 0;
            animation: fadeSlideIn 0.3s forwards;
        }
        @keyframes fadeSlideIn {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }   
        
        .msg.user .msg-bubble {
            background: linear-gradient(135deg, #e6fffb, #f6ffed);
            border-color: #b7eb8f;
        }

        .msg-files {
            margin-top: 10px;
            font-size: 14px;
            color: var(--primary-color);
        }
        .msg-files a {
            color: var(--primary-color);
            text-decoration: none;
            margin-right: 12px;
        }
        .msg-files a:hover { text-decoration: underline; }

        .input-area {
            display: flex;
            gap: 12px;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e6f4ff;
            border-radius: 14px;
            padding: 14px 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .input-area input {
            flex: 1;
            border: none;
            font-size: 15px;
            background: transparent;
        }

        .input-area input:focus {
            outline: none;
        }
        .input-area input::placeholder { color: var(--text-secondary-color); }

        .btn-send {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            border: none;
            background: var(--primary-color);
            color: #fff;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
        }
        .btn-send:hover    { background: #40a9ff; }
        .btn-send:disabled { opacity: .6; cursor: not-allowed; }

        .error-msg {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: var(--danger-color);
        }
        
    </style>
</head>
<body>
<div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <?php include 'header.php'; ?>
        <div class="content">
            <div class="breadcrumb">
                <a href="index.php">首頁</a> /
                <a href="recruitment_knowledge.php">官方招生知識庫</a> /
                知識問答
            </div>
            <div class="page-header" style="display:flex; background-color: #f0f2f5;">
                <h1 class="page-title">
                    知識問答
                    <i class="fas fa-question-circle help-icon"
                       title="輸入招生相關問題，系統會從官方招生知識庫找出依據並標示引用檔案與建立者資訊。"></i>
                </h1>
                <button type="button" class="btn-clear" id="btnClear">
                    <i class="fas fa-eraser"></i> 清空對話
                </button>
            </div>

            <div class="chat-area" id="chatArea"></div>
            <div id="errorMessage" class="error-msg" style="display:none;"></div>

            <div class="input-area">
                <input type="text" id="questionInput" placeholder="Say something.." autocomplete="off">
                <button type="button" class="btn-send" id="btnSend" title="送出">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var chatArea      = document.getElementById('chatArea');
    var questionInput = document.getElementById('questionInput');
    var btnSend       = document.getElementById('btnSend');
    var btnClear      = document.getElementById('btnClear');
    var errorEl       = document.getElementById('errorMessage');

    function addMessageTyping(role, content) {
    var msg  = document.createElement('div');
    msg.className = 'msg ' + (role === 'user' ? 'user' : 'msg-system');
    var icon = role === 'user' ? 'fa-user' : 'fa-robot';
    var html = '<div class="msg-avatar"><i class="fas ' + icon + '"></i></div>';
    html += '<div><div class="msg-bubble"></div></div>';
    msg.innerHTML = html;
    chatArea.appendChild(msg);
    chatArea.scrollTop = chatArea.scrollHeight;

    var bubble = msg.querySelector('.msg-bubble');
    var i = 0;
    function typeChar() {
        if (i < content.length) {
            bubble.innerHTML += content[i++];
            chatArea.scrollTop = chatArea.scrollHeight;
            setTimeout(typeChar, 20); // 每個字母間隔時間
        }
    }
    typeChar();
}

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function addMessage(role, content) {
        var msg  = document.createElement('div');
        msg.className = 'msg ' + (role === 'user' ? 'user' : 'msg-system');
        var icon = role === 'user' ? 'fa-user' : 'fa-robot';
        var html = '<div class="msg-avatar"><i class="fas ' + icon + '"></i></div>';
        html += '<div><div class="msg-bubble">' + escapeHtml(content) + '</div></div>';
        msg.innerHTML = html;
        chatArea.appendChild(msg);
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    function addSystemFileMessage(theme, files) {
        if (!files || files.length === 0) return;
        var msg = document.createElement('div');
        msg.className = 'msg msg-system';
        var line1 = '使用主題 "' + escapeHtml(theme) + '" 找到的檔案共有 ' + files.length + ' 個。';
        var links = files.map(function (f) {
            return '<a href="' + escapeHtml(f.file_path) + '" target="_blank" rel="noopener">' +
                   escapeHtml(f.file_original_name || f.file_path) + '</a>';
        });
        var line2 = '檔案名稱分別為 : ' + links.join('、');
        var line3 = files.map(function (f) {
            var who = f.created_by_name || '未知';
            var when = f.created_at || '';
            return escapeHtml(f.file_original_name || '') + '：由『' + escapeHtml(who) + '』於 ' + escapeHtml(when) + ' 上傳';
        }).join('；');
        var html = '<div class="msg-avatar"><i class="fas fa-folder"></i></div>';
        html += '<div>';
        html += '<div class="msg-bubble">' + line1 + '</div>';
        html += '<div class="msg-bubble" style="margin-top:8px">' + line2 + '</div>';
        if (line3) html += '<div class="msg-bubble" style="margin-top:8px;font-size:13px;color:#666">' + line3 + '</div>';
        html += '</div>';
        msg.innerHTML = html;
        chatArea.appendChild(msg);
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    function addSourceInfoMessage(sources) {
        if (!sources || sources.length === 0) return;
        var lines = sources.map(function (s, idx) {
            var who  = s.created_by_name || '未知建立者';
            var when = s.created_at || '時間未紀錄';
            var cnt  = (typeof s.file_count === 'number') ? s.file_count : 0;
            return (idx + 1) + '. 問題：「' + (s.question || '') + '」\n' +
                   '   由 ' + who + ' 於 ' + when + ' 建立，附件 ' + cnt + ' 個';
        });
        var text = '此回覆依據以下官方資料：\n' + lines.join('\n');
        addMessage('system', text);
    }

    function ask() {
        var q = questionInput.value.trim();
        if (!q) return;

        addMessage('user', q);
        questionInput.value = '';
        errorEl.style.display = 'none';

        btnSend.disabled = true;
        btnSend.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        fetch('recruitment_rag_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: q })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btnSend.disabled = false;
            btnSend.innerHTML = '<i class="fas fa-paper-plane"></i>';

           // 先顯示檔案（獨立處理）
if (data.files && data.files.length > 0) {
    addSystemFileMessage(q, data.files);
}

// 只有在「真的有文字回答」時，才顯示 AI 回覆
if (data.answer && data.answer.trim() !== '') {
    var answerText = data.answer;

    if (data.sources && data.sources.length > 0) {
        var s    = data.sources[0];
        var who  = s.created_by_name || '未知建立者';
        var when = s.created_at || '時間未紀錄';
        answerText += '\n\n— 資料由『' + who + '』於 ' + when + ' 建立';
    }

    addMessageTyping('system', answerText);
}

        })
        .catch(function () {
            btnSend.disabled = false;
            btnSend.innerHTML = '<i class="fas fa-paper-plane"></i>';
            addMessage('system', '網路或伺服器錯誤，請稍後再試。');
            errorEl.textContent = '網路或伺服器錯誤';
            errorEl.style.display = 'block';
        });
    }

    btnSend.addEventListener('click', ask);
    questionInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') ask();
    });
    btnClear.addEventListener('click', function () {
        chatArea.innerHTML = `
            <div style="text-align:center;margin-top:80px;color:#999">
                <i class="fas fa-robot" style="font-size:40px;margin-bottom:12px;"></i>
                <div style="font-size:16px;">請輸入招生相關問題</div>
                <div style="font-size:13px;margin-top:6px;">例如：學費多少？招生時間是什麼時候？</div>
            </div>`;
        errorEl.style.display = 'none';
    });
})();</script>
</body>
</html>

