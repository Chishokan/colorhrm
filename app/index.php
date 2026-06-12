<?php
require __DIR__ . '/auth.php';
require_login();
$user = current_user();

$t0 = microtime(true);
$rows = db()->query("SELECT * FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();
$ms = round((microtime(true) - $t0) * 1000, 1);

function color_style($c) {
  $map = [
    'WHITE'  => 'background:#e0e0e0;color:#555',
    'GREEN'  => 'background:#c8e6c9;color:#2e7d32',
    'BLUE'   => 'background:#bbdefb;color:#1565c0',
    'YELLOW' => 'background:#fff9c4;color:#f57f17',
    'RED'    => 'background:#ffcdd2;color:#c62828',
  ];
  return $map[$c] ?? 'background:#eee;color:#555';
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Color HRM 講師一覧</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <nav class="navbar navbar-dark bg-dark px-3">
    <span class="navbar-brand">🎓 Color HRM</span>
    <span class="text-white-50 ms-auto me-3 small"><?= h($user['display_name'] ?: $user['email']) ?>（<?= h($user['role']) ?>）</span>
    <a href="logout.php" class="btn btn-sm btn-outline-light">ログアウト</a>
  </nav>

  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">講師一覧（<?= count($rows) ?>名）</h2>
      <span class="badge bg-success">DB取得 <?= $ms ?> ms</span>
    </div>

    <div class="row g-3">
      <?php foreach ($rows as $s): ?>
        <div class="col-md-3 col-sm-6">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="mb-0"><?= h($s['name']) ?></h6>
                <span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span>
              </div>
              <div class="small text-muted">
                <?= h($s['departments']) ?> / <?= h($s['school']) ?><br>
                <?php if ($s['target_rank']): ?>
                  目標: <span class="badge" style="<?= color_style($s['target_rank']) ?>"><?= h($s['target_rank']) ?></span><br>
                <?php endif; ?>
                <?php if ($s['mentor']): ?>メンター: <?= h($s['mentor']) ?><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="text-muted small mt-4">
      ※ この画面は MySQL から直接表示しているため即時に開きます（Google認証・承認画面もありません）。。
    </p>
  </div>
</body>
</html>
