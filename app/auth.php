<?php
// 独自認証（セッションベース）
session_start();
require_once __DIR__ . '/db.php';

function current_user() {
  return $_SESSION['user'] ?? null;
}

function require_login() {
  if (!current_user()) {
    header('Location: login.php');
    exit;
  }
}

function login_attempt($email, $password) {
  $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
  $stmt->execute([trim($email)]);
  $u = $stmt->fetch();
  if ($u && password_verify($password, $u['password_hash'])) {
    unset($u['password_hash']);
    $_SESSION['user'] = $u;
    return true;
  }
  return false;
}

function logout() {
  $_SESSION = [];
  session_destroy();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
