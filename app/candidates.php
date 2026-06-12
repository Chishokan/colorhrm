<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

// フィルタ（CandidateService.gs#list を踏襲：department / selection_result / assignee / keyword）
$fDept     = trim($_GET['department'] ?? '');
$fResult   = $_GET['selection_result'] ?? '';
$fAssignee = trim($_GET['assignee'] ?? '');
$fKeyword  = trim($_GET['q'] ?? '');

$where  = ['tenant_id = 1'];
$params = [];
if ($fDept !== '')     { $where[] = 'department = ?';       $params[] = $fDept; }
if ($fResult !== '')   { $where[] = 'selection_result = ?'; $params[] = $fResult; }
if ($fAssignee !== '') { $where[] = 'assignee = ?';         $params[] = $fAssignee; }
if ($fKeyword !== '')  { $where[] = '(name LIKE ? OR note LIKE ?)'; $params[] = "%$fKeyword%"; $params[] = "%$fKeyword%"; }

$sql = "SELECT * FROM candidates WHERE " . implode(' AND ', $where)
     . " ORDER BY applied_month DESC, applied_day DESC, no DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// フィルタ用の候補値（部署・担当者）
$depts     = db()->query("SELECT DISTINCT department FROM candidates WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$assignees = db()->query("SELECT DISTINCT assignee FROM candidates WHERE assignee <> '' ORDER BY assignee")->fetchAll(PDO::FETCH_COLUMN);

render_header('採用 応募者一覧', $user, 'candidates.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">応募者一覧 <span class="text-muted small">（<?= count($rows) ?>件）</span></h4>
      <a href="candidate.php" class="btn btn-sm btn-primary">＋ 新規応募者</a>
    </div>

    <!-- フィルタ -->
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-md-3">
        <label class="form-label small mb-0">キーワード（氏名・備考）</label>
        <input name="q" value="<?= h($fKeyword) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-0">部署</label>
        <select name="department" class="form-select form-select-sm">
          <option value="">すべて</option>
          <?php foreach ($depts as $d): ?>
            <option value="<?= h($d) ?>" <?= $d === $fDept ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-0">選考結果</label>
        <select name="selection_result" class="form-select form-select-sm">
          <option value="">すべて</option>
          <?php foreach (candidate_selection_results() as $r): ?>
            <option value="<?= h($r) ?>" <?= $r === $fResult ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-0">担当者</label>
        <select name="assignee" class="form-select form-select-sm">
          <option value="">すべて</option>
          <?php foreach ($assignees as $a): ?>
            <option value="<?= h($a) ?>" <?= $a === $fAssignee ? 'selected' : '' ?>><?= h($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-sm btn-outline-primary">絞り込み</button>
        <a href="candidates.php" class="btn btn-sm btn-link">クリア</a>
      </div>
    </form>

    <!-- 一覧 -->
    <div class="card shadow-sm">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>No</th><th>応募</th><th>氏名</th><th>部署/校舎</th><th>担当</th>
            <th>選考結果</th><th>状態</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-3">該当する応募者がいません。</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td class="text-muted small"><?= (int)$c['no'] ?></td>
              <td class="small"><?= $c['applied_month'] !== null ? h($c['applied_month']) . '/' . h($c['applied_day']) : '—' ?></td>
              <td><a href="candidate.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
                  <?php if ($c['age'] !== null): ?><span class="text-muted small">（<?= (int)$c['age'] ?>）</span><?php endif; ?></td>
              <td class="small"><?= h($c['department']) ?><?php if ($c['school']): ?> / <?= h($c['school']) ?><?php endif; ?></td>
              <td class="small"><?= h($c['assignee']) ?></td>
              <td><span class="badge <?= selection_badge_class($c['selection_result']) ?>"><?= h($c['selection_result'] ?: '未選考') ?></span></td>
              <td class="small">
                <?php if ($c['converted_to_staff']): ?><span class="badge bg-success">講師化済</span><?php endif; ?>
                <?php if (!$c['initial_response']): ?><span class="badge bg-warning text-dark">初期対応未</span><?php endif; ?>
              </td>
              <td class="text-end"><a href="candidate.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary">詳細</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php render_footer(); ?>
