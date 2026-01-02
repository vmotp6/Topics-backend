<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = 'AI 模型管理';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 模型管理 - Topics 後台管理系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
            overflow-x: hidden;
        }
        
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        .main-content {
            overflow-x: hidden;
        }
        
        .content {
            padding: 24px;
            width: 100%;
        }
        
        .status-card {
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .status-healthy {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .status-unhealthy {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
            color: white;
        }
        
        .model-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .training-data-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            white-space: pre-wrap;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: white;
        }
        
        .btn-success:hover {
            background: #73d13d;
            border-color: #73d13d;
        }
        
        .btn-info {
            background: #13c2c2;
            border-color: #13c2c2;
            color: white;
        }
        
        .btn-info:hover {
            background: #36cfc9;
            border-color: #36cfc9;
        }
        
        .btn-outline-danger {
            color: #ff4d4f;
            border-color: #ff4d4f;
        }
        
        .btn-outline-danger:hover {
            background: #ff4d4f;
            border-color: #ff4d4f;
            color: white;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / AI 模型管理
                    </div>
                </div>

                <!-- Ollama狀態 -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="status-card" id="ollama-status">
                            <h4><i class="fas fa-heartbeat"></i> Ollama 服務狀態</h4>
                            <div id="status-content">檢查中...</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="status-card bg-info text-white">
                            <h4><i class="fas fa-server"></i> 服務資訊</h4>
                            <p>URL: http://localhost:11434</p>
                            <p>預設模型: llama2</p>
                        </div>
                    </div>
                </div>

                <!-- 標籤頁 -->
                <ul class="nav nav-tabs" id="ollamaTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="models-tab" data-bs-toggle="tab" data-bs-target="#models-panel" type="button">
                            <i class="fas fa-brain"></i> 模型管理
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="training-tab" data-bs-toggle="tab" data-bs-target="#training-panel" type="button">
                            <i class="fas fa-upload"></i> 資料餵入
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="test-tab" data-bs-toggle="tab" data-bs-target="#test-panel" type="button">
                            <i class="fas fa-flask"></i> 模型測試
                        </button>
                    </li>
                </ul>

                <!-- 標籤頁內容 -->
                <div class="tab-content" id="ollamaTabsContent">
                    <!-- 模型管理 -->
                    <div class="tab-pane fade show active" id="models-panel" role="tabpanel">
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5>可用模型</h5>
                                <button class="btn btn-primary" onclick="refreshModels()">
                                    <i class="fas fa-sync"></i> 重新整理
                                </button>
                            </div>
                            <div id="models-list">
                                <!-- 模型列表將在這裡動態載入 -->
                            </div>
                        </div>
                    </div>

                    <!-- 資料餵入 -->
                    <div class="tab-pane fade" id="training-panel" role="tabpanel">
                        <div class="mt-3">
                            <div class="training-data-card">
                                <h5><i class="fas fa-upload"></i> 上傳訓練資料</h5>
                                <p class="text-muted">您可以上傳問答對或文檔來訓練自定義模型</p>
                                
                                <form id="upload-form">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">資料類型</label>
                                                <select class="form-select" id="data-type" required>
                                                    <option value="qa">問答對 (Q&A)</option>
                                                    <option value="text">純文字文檔</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">模型名稱</label>
                                                <input type="text" class="form-control" id="model-name" placeholder="例如: kning-university-qa" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">資料內容</label>
                                        <textarea class="form-control" id="data-content" rows="10" placeholder="請輸入訓練資料..." required></textarea>
                                        <div class="form-text">
                                            <strong>問答對格式：</strong><br>
                                            Q: 康寧大學有哪些科系？<br>
                                            A: 康寧大學共有7個科系：資訊管理科、企業管理科、護理科、幼保科、應用外語科、視光科、動畫科。<br><br>
                                            Q: 如何申請入學？<br>
                                            A: 申請流程包括：查看招生簡章、準備相關文件、線上報名並繳費、參加面試或筆試、等待錄取通知。
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-upload"></i> 上傳資料
                                        </button>
                                        <button type="button" class="btn btn-info" onclick="createModel()">
                                            <i class="fas fa-plus"></i> 創建模型
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- 模型測試 -->
                    <div class="tab-pane fade" id="test-panel" role="tabpanel">
                        <div class="mt-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">選擇模型</label>
                                        <select class="form-select" id="test-model">
                                            <option value="llama2">llama2 (預設)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">使用上下文</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="use-context" checked>
                                            <label class="form-check-label" for="use-context">
                                                從資料庫獲取相關資訊
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">測試問題</label>
                                <textarea class="form-control" id="test-question" rows="3" placeholder="輸入測試問題...">康寧大學有哪些科系？</textarea>
                            </div>
                            
                            <button class="btn btn-primary" onclick="testModel()">
                                <i class="fas fa-play"></i> 測試模型
                            </button>
                            
                            <div class="mt-4">
                                <h6>測試結果</h6>
                                <div id="test-result" class="code-block" style="display: none;">
                                    <!-- 測試結果將在這裡顯示 -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script>
    $(document).ready(function() {
        checkOllamaHealth();
        loadModels();
        
        // 綁定表單提交
        $('#upload-form').submit(function(e) {
            e.preventDefault();
            uploadTrainingData();
        });
    });
    
    function checkOllamaHealth() {
        $.get('../../Topics-frontend/backend/api/ollama/ollama_api.php?action=check_health')
            .done(function(response) {
                if (response.success) {
                    $('#ollama-status').removeClass('status-unhealthy').addClass('status-healthy');
                    $('#status-content').html(`
                        <h5><i class="fas fa-check-circle"></i> 服務正常</h5>
                        <p>已載入 ${response.models.length} 個模型</p>
                    `);
                } else {
                    $('#ollama-status').removeClass('status-healthy').addClass('status-unhealthy');
                    $('#status-content').html(`
                        <h5><i class="fas fa-exclamation-triangle"></i> 服務異常</h5>
                        <p>${response.message}</p>
                    `);
                }
            })
            .fail(function() {
                $('#ollama-status').removeClass('status-healthy').addClass('status-unhealthy');
                $('#status-content').html(`
                    <h5><i class="fas fa-times-circle"></i> 連接失敗</h5>
                    <p>無法連接到Ollama服務</p>
                `);
            });
    }
    
    function loadModels() {
        $.get('../../Topics-frontend/backend/api/ollama/ollama_api.php?action=get_models')
            .done(function(response) {
                if (response.success) {
                    displayModels(response.models);
                    updateModelSelects(response.models);
                }
            })
            .fail(function() {
                $('#models-list').html('<div class="alert alert-warning">載入模型失敗</div>');
            });
    }
    
    function displayModels(models) {
        let html = '';
        models.forEach(model => {
            const size = model.size ? (model.size / 1024 / 1024 / 1024).toFixed(1) + ' GB' : '未知';
            const modified = model.modified_at ? new Date(model.modified_at).toLocaleDateString() : '未知';
            
            html += `
                <div class="model-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-primary">${model.name}</h6>
                            <p class="text-muted mb-1">大小: ${size}</p>
                            <p class="text-muted mb-0">修改時間: ${modified}</p>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteModel('${model.name}')">
                                <i class="fas fa-trash"></i> 刪除
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        $('#models-list').html(html || '<div class="alert alert-info">暫無可用模型</div>');
    }
    
    function updateModelSelects(models) {
        let options = '';
        models.forEach(model => {
            options += `<option value="${model.name}">${model.name}</option>`;
        });
        $('#test-model').html(options);
    }
    
    function uploadTrainingData() {
        const dataType = $('#data-type').val();
        const dataContent = $('#data-content').val();
        
        if (!dataContent.trim()) {
            alert('請輸入訓練資料');
            return;
        }
        
        $.post('../../Topics-frontend/backend/api/ollama/ollama_api.php', {
            action: 'upload_training_data',
            data_type: dataType,
            data_content: dataContent
        })
        .done(function(response) {
            if (response.success) {
                alert(`訓練資料上傳成功！共處理 ${response.count} 條資料`);
                $('#data-content').val('');
            } else {
                alert('上傳失敗: ' + response.error);
            }
        })
        .fail(function() {
            alert('上傳失敗，請稍後再試');
        });
    }
    
    function createModel() {
        const modelName = $('#model-name').val();
        const dataContent = $('#data-content').val();
        
        if (!modelName.trim() || !dataContent.trim()) {
            alert('請填寫模型名稱和訓練資料');
            return;
        }
        
        if (!confirm(`確定要創建模型 "${modelName}" 嗎？這可能需要幾分鐘時間。`)) {
            return;
        }
        
        // 解析訓練資料
        const trainingData = parseTrainingData(dataContent, $('#data-type').val());
        
        $.post('../../Topics-frontend/backend/api/ollama/ollama_api.php', {
            action: 'create_model',
            model_name: modelName,
            training_data: JSON.stringify(trainingData)
        })
        .done(function(response) {
            if (response.success) {
                alert('模型創建成功！');
                loadModels();
            } else {
                alert('創建失敗: ' + response.error);
            }
        })
        .fail(function() {
            alert('創建失敗，請稍後再試');
        });
    }
    
    function parseTrainingData(content, type) {
        const data = [];
        
        if (type === 'qa') {
            const lines = content.split('\n');
            let currentQA = {};
            
            lines.forEach(line => {
                line = line.trim();
                if (!line) return;
                
                if (line.match(/^Q[：:]\s*(.+)$/)) {
                    if (currentQA.question) {
                        data.push(currentQA);
                    }
                    currentQA = {
                        question: line.replace(/^Q[：:]\s*/, ''),
                        answer: ''
                    };
                } else if (line.match(/^A[：:]\s*(.+)$/)) {
                    currentQA.answer = line.replace(/^A[：:]\s*/, '');
                } else if (currentQA.question && currentQA.answer) {
                    currentQA.answer += '\n' + line;
                }
            });
            
            if (currentQA.question) {
                data.push(currentQA);
            }
        } else {
            const paragraphs = content.split('\n\n');
            paragraphs.forEach(paragraph => {
                paragraph = paragraph.trim();
                if (paragraph) {
                    data.push({ content: paragraph });
                }
            });
        }
        
        return data;
    }
    
    function testModel() {
        const model = $('#test-model').val();
        const question = $('#test-question').val();
        const useContext = $('#use-context').is(':checked');
        
        if (!question.trim()) {
            alert('請輸入測試問題');
            return;
        }
        
        $('#test-result').show().html('正在處理中...');
        
        $.post('../../Topics-frontend/backend/api/ollama/ollama_api.php', {
            action: 'ask_question',
            question: question,
            model: model,
            use_context: useContext
        })
        .done(function(response) {
            if (response.success) {
                let result = `問題: ${question}\n\n`;
                result += `回答: ${response.answer}\n\n`;
                result += `模型: ${response.model}\n`;
                result += `使用上下文: ${response.context_used ? '是' : '否'}\n`;
                result += `回應時間: ${response.response_time_ms}ms`;
                
                $('#test-result').html(result);
            } else {
                $('#test-result').html('測試失敗: ' + response.error);
            }
        })
        .fail(function() {
            $('#test-result').html('測試失敗，請檢查Ollama服務狀態');
        });
    }
    
    function deleteModel(modelName) {
        if (!confirm(`確定要刪除模型 "${modelName}" 嗎？`)) {
            return;
        }
        
        $.post('../../Topics-frontend/backend/api/ollama/ollama_api.php', {
            action: 'delete_model',
            model_name: modelName
        })
        .done(function(response) {
            if (response.success) {
                alert('模型刪除成功！');
                loadModels();
            } else {
                alert('刪除失敗: ' + response.error);
            }
        })
        .fail(function() {
            alert('刪除失敗，請稍後再試');
        });
    }
    
    function refreshModels() {
        loadModels();
    }
    </script>
</body>
</html>

