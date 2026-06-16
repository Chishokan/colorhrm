<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_staff_list_access();
$user = current_user();

$t0 = microtime(true);
$rows = db()->query("SELECT * FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();
$ms = round((microtime(true) - $t0) * 1000, 1);

render_header('Color HRM 講師一覧', $user, 'index.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">講師一覧（<?= count($rows) ?>名）</h2>
      <div class="d-flex align-items-center gap-2">
        <?php if (in_array($user['role'] ?? '', ['admin', 'staff'], true)): ?>
          <a href="staff_new.php" class="btn btn-sm btn-success">＋ 講師を追加</a>
        <?php endif; ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <a href="staff_io.php?export=csv" class="btn btn-sm btn-outline-success">CSVエクスポート</a>
          <a href="staff_io.php" class="btn btn-sm btn-outline-success">CSVインポート</a>
        <?php endif; ?>
        <span class="badge bg-success">DB取得 <?= $ms ?> ms</span>
      </div>
    </div>

    <div class="row g-3">
      <?php foreach ($rows as $s): ?>
        <div class="col-md-3 col-sm-6">
          <div class="card h-100 shadow-sm position-relative" style="cursor:pointer">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="mb-0"><a href="staff_detail.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none stretched-link"><?= h($s['name']) ?></a></h6>
                <span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span>
              </div>
              <div class="small text-muted">
                <?= h($s['departments']) ?> / <?= h($s['school']) ?><br>
                <?php if ($s['target_rank']): ?>
                  目標: <span class="badge" style="<?= color_style($s['target_rank']) ?>"><?= h($s['target_rank']) ?></span><br>
                <?php endif; ?>
                <?php if ($s['mentor']): ?>メンター: <?= h($s['mentor']) ?><br><?php endif; ?>
              </div>
              <?php $gs = compute_goal_summary($s); ?>
              <div class="mt-2"><?= goal_bar_html($gs, true) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="text-muted small mt-4">
      ※ この画面は MySQL から直接表示しているため即時に開きます（Google認証・承認画面もありません）。。
    </p>
  </div>
<?php render_footer(); ?>
