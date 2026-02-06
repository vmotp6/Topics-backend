<?php
/**
 * 定時任務/自動化腳本使用的 Token 設定
 *
 * 說明：
 * - 用於在「未登入」情況下，仍能由 Windows 工作排程器 / cron 觸發公告發布腳本
 * - 請將 token 改成不可猜測的字串，並妥善保存
 */

// TODO: 請改成你自己的 token（例如一段長一點的隨機字串）
define('CA_PUBLISH_TOKEN', 'CHANGE_ME');




