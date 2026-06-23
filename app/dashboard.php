<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$thisMonth = (int)date('n');

// ---- 承認待ち（申告中）を教室別に ----
$pendingApprovals = [];
try {
  $hasCr = staff_has_column('classrooms');
  $pendingApprovals = db()->query(
    "SELECT tp.id, tp.declared_at, s.id AS staff_id, s.name AS staff_name, "
    . ($hasCr ? "s.classrooms" : "'' AS classrooms")
    . ", ti.item_name, ti.type
       FROM training_progress tp
       JOIN staff s ON s.id = tp.staff_id
       JOIN training_items ti ON ti.id = tp.training_item_id
      WHERE tp.status = '申告中'
      ORDER BY tp.declared_at")->fetchAll();
} catch (Throwable $e) { $pendingApprovals = []; }
$pendingByRoom = [];
foreach ($pendingApprovals as $p) {
  $rooms = classroom_list($p['classrooms'] ?? '');
  if (!$rooms) { $rooms = ['（教室未設定）']; }
  foreach ($rooms as $rm) { $pendingByRoom[$rm][] = $p; }
}
ksort($pendingByRoom);

// ---- 採用サマリー（現状は非表示。show_recruitment で再有効化）----
$appliedThisMonth = $hiredTotal = $pendingConvert = $unscreened = $noInitial = 0;
$todo = [];
if (show_recruitment()) {
  $st = db()->prepare("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND applied_month = ?");
  $st->execute([$thisMonth]);
  $appliedThisMonth = (int)$st->fetchColumn();
  $hiredTotal     = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND selection_result='採用'")->fetchColumn();
  $pendingConvert = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND selection_result='採用' AND converted_to_staff=0")->fetchColumn();
  $unscreened     = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND (selection_result IS NULL OR selection_result='')")->fetchColumn();
  $noInitial      = (int)db()->query("SELECT COUNT(*) FROM candidates WHERE tenant_id=1 AND initial_response=0")->fetchColumn();
  $todo = db()->query(
    "SELECT id, no, name, department, selection_result, initial_response
       FROM candidates
      WHERE tenant_id=1 AND ((selection_result IS NULL OR selection_result='') OR initial_response=0)
      ORDER BY applied_month DESC, applied_day DESC LIMIT 20")->fetchAll();
}

// ---- 育成（在籍講師）の集計 ----
$activeStaff = db()->query("SELECT * FROM staff WHERE tenant_id=1 AND is_active=1 ORDER BY name")->fetchAll();
$staffTotal  = count($activeStaff);
// カラー別人数
$byColor = array_fill_keys(color_ranks(), 0);
foreach ($activeStaff as $s) { if (isset($byColor[$s['color_rank']])) { $byColor[$s['color_rank']]++; } }
// 教室別×カラー マトリクス
$schoolColor = [];
foreach ($activeStaff as $s) {
  $sc = $s['school'] !== '' ? $s['school'] : '（未設定）';
  if (!isset($schoolColor[$sc])) { $schoolColor[$sc] = array_fill_keys(color_ranks(), 0); }
  if (isset($schoolColor[$sc][$s['color_rank']])) { $schoolColor[$sc][$s['color_rank']]++; }
}
ksort($schoolColor);
// 目標進捗 要注意リスト（期限超過で未達 or 達成率低）
$today = date('Y-m-d');
$alerts = [];
foreach ($activeStaff as $s) {
  $gs = compute_goal_summary($s);
  if (!$gs['hasGoal']) continue;
  $overdue = !empty($s['target_date']) && $s['target_date'] < $today;
  if ($overdue && $gs['rate'] < 100) {
    $alerts[] = ['s' => $s, 'gs' => $gs, 'level' => 'danger', 'reason' => '目標期限超過'];
  } elseif ($gs['rate'] < 50) {
    $alerts[] = ['s' => $s, 'gs' => $gs, 'level' => 'warning', 'reason' => '達成率が低い'];
  }
}
usort($alerts, fn($a, $b) => $a['gs']['rate'] <=> $b['gs']['rate']);

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

    <!-- 承認待ち（教室別）：一番上 -->
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>研修 承認待ち（教室別）</span>
        <span class="badge bg-warning text-dark"><?= count($pendingApprovals) ?> 件</span>
      </div>
      <div class="card-body">
        <?php if (!$pendingApprovals): ?>
          <div class="text-muted">承認待ちの申告はありません。</div>
        <?php else: ?>
          <?php foreach ($pendingByRoom as $room => $list): ?>
            <div class="mb-3">
              <div class="fw-bold small text-primary mb-1">教室: <?= h($room) ?> <span class="badge bg-warning text-dark"><?= count($list) ?></span></div>
              <ul class="list-group list-group-flush">
                <?php foreach ($list as $p): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-center py-1 flex-wrap gap-1">
                    <span><a href="training.php?staff_id=<?= (int)$p['staff_id'] ?>"><?= h($p['staff_name']) ?></a> ・ <?= h($p['item_name']) ?> <span class="badge bg-light text-dark border"><?= h(training_type_label($p['type'])) ?></span></span>
                    <a href="training.php?staff_id=<?= (int)$p['staff_id'] ?>" class="btn btn-sm btn-outline-success">承認へ</a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (show_recruitment()): ?>
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
    <?php endif; ?>

    <!-- 育成サマリー -->
    <h5 class="mt-4 mb-2">育成（在籍講師 <?= $staffTotal ?>名）</h5>
    <div class="row g-3">
      <div class="col-md-5">
        <div class="card shadow-sm h-100">
          <div class="card-header">カラー別人数</div>
          <div class="card-body">
            <?php foreach (color_ranks() as $cr): ?>
              <?php $n = $byColor[$cr]; $pct = $staffTotal ? round($n / $staffTotal * 100) : 0; ?>
              <div class="d-flex align-items-center mb-2">
                <span class="badge me-2" style="<?= color_style($cr) ?>;width:64px"><?= h($cr) ?></span>
                <div class="progress flex-grow-1" style="height:16px">
                  <div class="progress-bar bg-secondary" style="width:<?= $pct ?>%"></div>
                </div>
                <span class="ms-2 small text-muted" style="width:32px"><?= $n ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-md-7">
        <div class="card shadow-sm h-100">
          <div class="card-header">目標進捗 要注意リスト <span class="badge bg-warning text-dark"><?= count($alerts) ?></span></div>
          <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light"><tr><th>講師</th><th>現/目標</th><th>達成率</th><th>理由</th></tr></thead>
              <tbody>
                <?php if (!$alerts): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">要注意の講師はいません。</td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($alerts, 0, 15) as $a): ?>
                  <tr>
                    <td><a href="staff_detail.php?id=<?= (int)$a['s']['id'] ?>"><?= h($a['s']['name']) ?></a></td>
                    <td class="small">
                      <span class="badge" style="<?= color_style($a['s']['color_rank']) ?>"><?= h($a['s']['color_rank']) ?></span>
                      →<span class="badge" style="<?= color_style($a['s']['target_rank']) ?>"><?= h($a['s']['target_rank']) ?></span>
                    </td>
                    <td><span class="badge bg-<?= $a['level'] ?>"><?= (int)$a['gs']['rate'] ?>%</span></td>
                    <td class="small text-muted"><?= h($a['reason']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mt-3">
      <div class="card-header">教室別 × カラー</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 text-center">
          <thead class="table-light">
            <tr><th class="text-start">教室</th>
              <?php foreach (color_ranks() as $cr): ?><th><span class="badge" style="<?= color_style($cr) ?>"><?= h($cr) ?></span></th><?php endforeach; ?>
              <th>計</th></tr>
          </thead>
          <tbody>
            <?php foreach ($schoolColor as $sc => $counts): ?>
              <tr><td class="text-start"><?= h($sc) ?></td>
                <?php $rowTotal = 0; foreach (color_ranks() as $cr): $rowTotal += $counts[$cr]; ?>
                  <td><?= $counts[$cr] ?: '<span class="text-muted">·</span>' ?></td>
                <?php endforeach; ?>
                <td class="fw-bold"><?= $rowTotal ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
