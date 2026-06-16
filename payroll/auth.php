<?php
// 独自認証（セッションベース）。
// ColorHRM(/colorhrm/) と同一ドメイン配下のため PHPSESSID セッションが共有され、
// 片方でログインすればもう片方でもログイン状態になる（共通ログイン）。
// セッションのユーザー情報キー（$_SESSION['user']）/ CSRFキーも ColorHRM と一致させている。
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
