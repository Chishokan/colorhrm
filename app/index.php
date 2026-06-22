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
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="mb-0">講師一覧（<?= count($rows) ?>名）
        <span class="badge bg-light text-secondary border align-middle d-none d-md-inline" style="font-weight:normal">DB <?= $ms ?>ms</span>
      </h2>
      <div class="d-flex flex-wrap gap-2">
        <?php if (in_array($user['role'] ?? '', ['admin', 'staff'], true)): ?>
          <a href="staff_new.php" class="btn btn-sm btn-success">＋ 講師を追加</a>
        <?php endif; ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">CSV入出力</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="staff_io.php?export=csv">CSVエクスポート（DL）</a></li>
              <li><a class="dropdown-item" href="staff_io.php">CSVインポート</a></li>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <?php foreach ($rows as $s): ?>
        <div class="col-md-3 col-sm-6">
          <div class="card h-100 shadow-sm position-relative" style="cursor:pointer">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="d-flex gap-2 align-items-center">
                  <?php if (!empty($s['photo_file'])): ?>
                    <img src="photo_view.php?id=<?= (int)$s['id'] ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px" class="border">
                  <?php endif; ?>
                  <h6 class="mb-0"><a href="staff_detail.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none stretched-link"><?= h($s['name']) ?></a></h6>
                </div>
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
              <?php if (in_array($user['role'] ?? '', ['admin', 'staff'], true)): ?>
                <div class="mt-2 position-relative" style="z-index:2">
                  <a href="training.php?staff_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">研修進捗</a>
                </div>
              <?php endif; ?>
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
