<?php
session_start();

// 銷毀所有 session 資料
session_destroy();

// 跳轉到登入頁面
header("Location: login.php");
exit;