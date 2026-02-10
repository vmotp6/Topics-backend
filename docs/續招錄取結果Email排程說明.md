# 續招錄取結果 Email 排程說明

## 為什麼時間到了沒收到信？

**系統不會在「公告時間」到時自動寄信。**  
發送腳本必須被「執行」才會寄出，方式只有兩種：

1. **手動**：公告時間到之後，有人開啟發送頁面  
   `http://localhost/Topics-backend/frontend/send_continued_admission_result_emails.php`（需登入後台）
2. **排程**：用 Windows 工作排程器（或 cron）定期執行發送腳本，例如每 5 分鐘跑一次，時間到後的那次就會寄出

---

## 設定 Windows 工作排程器（建議）

1. 開啟 **工作排程器**（在開始搜尋「工作排程器」或 `taskschd.msc`）。
2. 右側點 **「建立基本工作」**。
3. 名稱：例如「續招錄取結果寄信」→ 下一步。
4. 觸發程序：選 **「每日」**（或「每 5 分鐘」若你的 Windows 有該選項）→ 下一步。  
   - 若只有「每日」：開始日期選今天，時間可選 00:00，並在下一步「重複工作間隔」選 **5 分鐘**、持續時間 **1 天**（或「無限期」），讓當天每 5 分鐘跑一次。
5. 動作：選 **「啟動程式」** → 下一步。
6. **程式或指令碼**：瀏覽選取  
   `C:\110534225\project\code\Topics-backend\frontend\run_send_continued_admission_emails.bat`  
   （請依你的專案實際路徑調整）。  
   **開始於**（選用）：`C:\110534225\project\code\Topics-backend\frontend`。
7. 完成後，排程會依設定每幾分鐘執行一次；**公告時間一到，下一次執行就會把到期的信寄出**。

### 注意

- 本機需已安裝 PHP，且 `php` 在系統 PATH 中（例如 XAMPP 的 `C:\xampp\php` 已加入 PATH）。  
  若未加入，可在 `.bat` 裡改為寫死路徑，例如：  
  `"C:\xampp\php\php.exe" send_continued_admission_result_emails.php`
- 排程執行時不會開啟視窗，若要確認有無執行，可看佇列表 `continued_admission_email_queue` 的 `status`、`sent_at` 是否更新。

---

## 不想設排程時

公告時間到之後，**手動開啟**下面網址一次（需先登入後台）即可寄出當次到期的信：

- 發送頁：  
  `http://localhost/Topics-backend/frontend/send_continued_admission_result_emails.php`

或到「續招 Email 診斷」頁面點「執行發送（僅發送已到公告時間的）」連結。
