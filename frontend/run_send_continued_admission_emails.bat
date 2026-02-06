@echo off
chcp 65001 >nul
cd /d "%~dp0"
REM 用命令列執行發送腳本（不需登入），排程請用此檔
REM 請確認 php 在系統 PATH 中（如 XAMPP：C:\xampp\php）
php send_continued_admission_result_emails.php
REM 若手動雙擊執行可取消下一行註解以查看結果
REM pause
