<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
if (current_user()) { header('Location: ' . home_url_for(current_user())); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (login_attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
    // teacher はマイページ、admin/staff は講師一覧へ
    header('Location: ' . home_url_for(current_user()));
    exit;
  }
  $err = 'メールアドレスまたはパスワードが違います';
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Color HRM ログイン</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container" style="max-width:380px;margin-top:12vh">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <?php if (logo_url() !== ''): ?>
          <img src="<?= h(logo_url()) ?>" alt="智翔館" style="max-height:64px" class="mb-2">
        <?php else: ?>
          <h4 class="mb-1">🎓 Color HRM</h4>
        <?php endif; ?>
        <p class="text-muted small mb-3">智翔館グループ 講師キャリアパスシステム</p>
        <?php if ($err): ?>
          <div class="alert alert-danger py-2"><?= h($err) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-2">
            <label class="form-label">メールアドレス</label>
            <input name="email" type="email" class="form-control" autofocus required>
          </div>
          <div class="mb-3">
            <label class="form-label">パスワード</label>
            <input name="password" type="password" class="form-control" required>
          </div>
          <button class="btn btn-primary w-100">ログイン</button>
        </form>
      </div>
    </div>
    <p class="text-muted small mt-2 text-center">Googleログイン不要・独自認証</p>
  </div>
</body>
</html>
