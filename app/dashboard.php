<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$thisMonth = (int)date('n');

// サマリー（candidates は応募月のみ保持＝年は持たないため「今月」は applied_month で判定）
$st = db()->prepare("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND applied_month = ?");
$st->execute([$thisMonth]);
$appliedThisMonth = (int)$st->fetchColumn();

$hiredTotal     = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND selection_result='採用'")->fetchColumn();
$pendingConvert = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND selection_result='採用' AND converted_to_staff=0")->fetchColumn();
$unscreened     = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND (selection_result IS NULL OR selection_result='')")->fetchColumn();
$noInitial      = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND initial_response=0")->fetchColumn();

// 要対応リスト（未選考 or 初期対応未）
$todo = db()->query(
  "SELECT id, no, name, department, selection_result, initial_response
     FROM candidates
    WHERE tenant_id=1 AND ((selection_result IS NULL OR selection_result='') OR initial_response=0)
    ORDER BY applied_month DESC, applied_day DESC LIMIT 20")->fetchAll();

function stat_card($label, $value, $href, $cls = 'bg-primary') {
  $v = (int)$value;
  return '<div class="col-md-3 col-6"><a href="' . h($href) . '" class="text-decoration-none">'
       . '<div class="card shadow-sm text-white ' . $cls . '"><div class="card-body text-center">'
       . '<div class="display-6">' . $v . '</div><div class="small">' . h($label) . '</div>'
       . '</div></div></a></div>';
}

render_header('ダッシュボード', $user, 'dashboard.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">ダッシュボード <span class="text-muted small">（<?= $thisMonth ?>月）</span></h4>

    <div class="row g-3 mb-4">
      <?= stat_card('今月の応募', $appliedThisMonth, 'candidates.php', 'bg-primary') ?>
      <?= stat_card('採用（累計）', $hiredTotal, 'candidates.php?selection_result=' . rawurlencode('採用'), 'bg-success') ?>
      <?= stat_card('講師化待ち', $pendingConvert, 'candidates.php?selection_result=' . rawurlencode('採用'), 'bg-info') ?>
      <?= stat_card('要対応', $unscreened + $noInitial, 'candidates.php', 'bg-warning') ?>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>要対応の応募者 <span class="text-muted small">（未選考 or 初期対応未）</span></span>
        <a href="candidate.php" class="btn btn-sm btn-primary">＋ 新規応募者</a>
      </div>
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No</th><th>氏名</th><th>部署</th><th>選考結果</th><th>状態</th></tr></thead>
        <tbody>
          <?php if (!$todo): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">要対応の応募者はありません。</td></tr>
          <?php endif; ?>
          <?php foreach ($todo as $c): ?>
            <tr>
              <td class="text-muted small"><?= (int)$c['no'] ?></td>
              <td><a href="candidate.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></td>
              <td class="small"><?= h($c['department']) ?></td>
              <td><span class="badge <?= selection_badge_class($c['selection_result']) ?>"><?= h($c['selection_result'] ?: '未選考') ?></span></td>
              <td class="small"><?= !$c['initial_response'] ? '<span class="badge bg-warning text-dark">初期対応未</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php render_footer(); ?>
