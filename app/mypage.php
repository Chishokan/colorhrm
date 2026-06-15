<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();

// teacher は users.staff_id 経由で自分の講師レコードに紐づく。
// admin/staff も自分に staff_id があれば閲覧可（無ければ案内を出す）。
$staffId = $user['staff_id'] ?? null;

$flash = '';
$staff = null;

if ($staffId) {
  $st = db()->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
  $st->execute([$staffId]);
  $staff = $st->fetch();
}

// 育成目標カラー（target_rank 優先、無ければ現カラー）
$targetColor = $staff ? ($staff['target_rank'] ?: $staff['color_rank']) : null;

// ------------------------------------------------------------
// 自己申告（POST）：対象研修項目を「申告中」にする
// ------------------------------------------------------------
if ($staff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'declare') {
  csrf_check();
  $itemId = (int)($_POST['item_id'] ?? 0);
  $memo   = trim($_POST['memo'] ?? '');

  // 申告対象が自分の研修項目であることを確認（target_color の一致）
  $chk = db()->prepare("SELECT id FROM training_items WHERE id = ? LIMIT 1");
  $chk->execute([$itemId]);
  if ($itemId && $chk->fetch()) {
    // uq_staff_item により upsert。差戻し後の再申告は承認情報をクリアする。
    $sql = "INSERT INTO training_progress
              (tenant_id, staff_id, training_item_id, status, memo, declared_by, declared_at)
            VALUES (?, ?, ?, '申告中', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              status='申告中', memo=VALUES(memo),
              declared_by=VALUES(declared_by), declared_at=NOW(),
              approved_by=NULL, approved_at=NULL";
    db()->prepare($sql)->execute([
      (int)$staff['tenant_id'], (int)$staff['id'], $itemId, $memo, (int)$user['id'],
    ]);
    $flash = '申告しました。承認をお待ちください。';
  }
}

// ------------------------------------------------------------
// 研修項目 + 自分の進捗を取得（部門 × 育成目標カラー）
// ------------------------------------------------------------
$items = [];
if ($staff && $targetColor) {
  $sql = "SELECT ti.*, tp.status, tp.memo AS progress_memo, tp.completed_date,
                 tp.declared_at, tp.approved_at
          FROM training_items ti
          LEFT JOIN training_progress tp
                 ON tp.training_item_id = ti.id AND tp.staff_id = ?
          WHERE ti.target_color = ?
          ORDER BY ti.department, ti.sort_order, ti.id";
  $q = db()->prepare($sql);
  $q->execute([(int)$staff['id'], $targetColor]);
  $all = $q->fetchAll();

  // 部門でフィルタ（staff.departments はカンマ区切りの場合がある。department='' は全員対象）
  $myDepts = array_map('trim', explode(',', (string)$staff['departments']));
  foreach ($all as $row) {
    if ($row['department'] === '' || in_array($row['department'], $myDepts, true)) {
      $items[] = $row;
    }
  }
}

render_header('マイページ', $user, 'mypage.php');
?>
  <div class="container py-4" style="max-width:880px">

    <?php if ($flash): ?>
      <div class="alert alert-success py-2"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if (!$staff): ?>
      <div class="alert alert-warning">
        あなたのアカウントは講師レコードに紐づいていません（<code>users.staff_id</code> 未設定）。
        管理者にお問い合わせください。
      </div>
    <?php else: ?>

      <!-- プロフィール -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h4 class="mb-1"><?= h($staff['name']) ?></h4>
              <div class="text-muted small">
                <?= h($staff['departments']) ?> / <?= h($staff['school']) ?>
                <?php if (!empty($staff['mentor'])): ?> ・ メンター: <?= h($staff['mentor']) ?><?php endif; ?>
              </div>
            </div>
            <div class="text-end">
              <div class="mb-1">現カラー:
                <span class="badge" style="<?= color_style($staff['color_rank']) ?>"><?= h($staff['color_rank']) ?></span>
              </div>
              <?php if (!empty($staff['target_rank'])): ?>
                <div>目標:
                  <span class="badge" style="<?= color_style($staff['target_rank']) ?>"><?= h($staff['target_rank']) ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- 育成達成率 -->
      <?php $gs = compute_goal_summary($staff); ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="small text-muted mb-1">目標カラー <?= h($staff['target_rank'] ?: '—') ?> までの育成達成率</div>
          <?= goal_bar_html($gs) ?>
        </div>
      </div>

      <!-- 研修進捗 -->
      <h5 class="mb-2">研修進捗
        <small class="text-muted">（育成目標カラー: <?= h($targetColor) ?>）</small>
      </h5>

      <?php if (!$items): ?>
        <div class="alert alert-light border">対象の研修項目がまだ登録されていません。</div>
      <?php else: ?>
        <div class="card shadow-sm">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>部門</th>
                <th>研修項目</th>
                <th class="text-center">必須</th>
                <th>状態</th>
                <th class="text-end">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <?php $status = $it['status'] ?: '未着手'; ?>
                <tr>
                  <td class="small text-muted"><?= h($it['department'] ?: '共通') ?></td>
                  <td>
                    <?= h($it['item_name']) ?>
                    <?php if (!empty($it['progress_memo'])): ?>
                      <div class="small text-muted">メモ: <?= h($it['progress_memo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?= $it['is_required'] ? '<span class="badge bg-dark">必須</span>' : '<span class="text-muted small">任意</span>' ?>
                  </td>
                  <td><span class="badge <?= status_badge_class($status) ?>"><?= h($status) ?></span></td>
                  <td class="text-end">
                    <?php if (in_array($status, ['未着手', '差戻し', '不合格'], true)): ?>
                      <form method="post" class="d-inline-flex gap-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="declare">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <input type="text" name="memo" class="form-control form-control-sm" style="width:140px"
                               placeholder="点数・実施日など">
                        <button class="btn btn-sm btn-primary">申告</button>
                      </form>
                    <?php elseif ($status === '申告中'): ?>
                      <span class="text-muted small">承認待ち</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
<?php render_footer(); ?>
