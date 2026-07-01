<?php
// 給与計算＋振込一覧（admin/staff）。確定シフト（shift_days）×時給表（pay_rates）から月次集計。
//   授業給与=round(授業分/60×授業時給)、運営給与=round(運営分/60×運営時給)、交通費=GAS準拠。
//   CSVダウンロード（?export=csv）とテキストコピーに対応。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();
$scope = scoped_staff_ids($user); // null=全員 / 配列=担当教室の講師ID

$month = valid_month($_GET['m'] ?? date('Y-m'));

// 明細の発行（PDF出力）とメール送信を分離（POST）
//   issue_all/issue_one … 明細を発行（スナップショット作成）※メールは送らない
//   notify_all/notify_one … 発行済み明細の通知メールを送信
$flash = ''; $err = '';
$_act = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_act, ['issue_all', 'issue_one', 'notify_all', 'notify_one'], true)) {
  csrf_check();
  if (!payslips_table_exists()) {
    $err = '給与明細テーブル（payslips）がありません。migrations/011_payslips.sql を実行してください。';
  } else {
    $onlyId = in_array($_act, ['issue_one', 'notify_one'], true) ? (int)($_POST['staff_id'] ?? 0) : null;
    if ($onlyId !== null && $scope !== null && !in_array($onlyId, $scope, true)) {
      $err = '担当教室外の講師は操作できません。';
    } elseif (in_array($_act, ['issue_all', 'issue_one'], true)) {
      // 発行のみ（メールなし）
      $issued = issue_payslips($month, (int)$user['id'], $onlyId, $scope);
      $flash = count($issued) . '件の明細を発行しました（PDF出力可・メールは未送信）。';
    } else {
      // メール送信のみ（発行済みが対象）
      $where = "p.month=?"; $params = [$month];
      if ($onlyId !== null) { $where .= " AND p.staff_id=?"; $params[] = $onlyId; }
      elseif ($scope !== null) {
        if ($scope) { $where .= " AND p.staff_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")"; $params = array_merge($params, $scope); }
        else { $where .= " AND 1=0"; }
      }
      $q = db()->prepare("SELECT p.staff_id, s.name, s.email FROM payslips p JOIN staff s ON s.id=p.staff_id WHERE $where");
      $q->execute($params);
      $note = db()->prepare("UPDATE payslips SET notified_at=NOW() WHERE staff_id=? AND month=?");
      $mailed = 0; $noemail = 0;
      foreach ($q->fetchAll() as $r) {
        if (trim((string)$r['email']) === '') { $noemail++; continue; }
        if (send_payslip_notice($r['email'], $r['name'], $month)) { $note->execute([(int)$r['staff_id'], $month]); $mailed++; }
      }
      $flash = "{$mailed}件にメール送信しました" . ($noemail ? "（メール未登録 {$noemail}件はスキップ）" : '') . '。';
    }
  }
}

// 立替金の保存（講師×月の手入力。発行済み明細に反映するには再発行が必要）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_act === 'save_advance') {
  csrf_check();
  $sid = (int)($_POST['staff_id'] ?? 0);
  $amt = max(0, (int)($_POST['amount'] ?? 0));
  if ($scope !== null && !in_array($sid, $scope, true)) { $err = '担当教室外の講師は操作できません。'; }
  elseif (!staff_advances_table_exists()) { $err = '立替金テーブル（staff_advances）がありません。migrations/017_staff_advances.sql を実行してください。'; }
  else { set_staff_advance($sid, $month, $amt); $flash = '立替金を保存しました（発行済み明細に反映するには「再発行」してください）。'; }
}

$rows  = compute_month_payroll($month);
if ($scope !== null) { $rows = array_values(array_filter($rows, fn($r) => in_array((int)$r['staff']['id'], $scope, true))); }

// 発行済み明細（月内）を staff_id で引けるように
$slipBy = [];
if (payslips_table_exists()) {
  $ps = db()->prepare("SELECT * FROM payslips WHERE month=?");
  $ps->execute([$month]);
  foreach ($ps->fetchAll() as $p) { $slipBy[(int)$p['staff_id']] = $p; }
}

// CSVダウンロード（ヘッダ出力のため render より前で処理）
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="payroll_' . $month . '.csv"');
  echo "\xEF\xBB\xBF"; // Excel 用 BOM
  $out = fopen('php://output', 'w');
  fputcsv($out, ['講師', 'カラー', '部門', '勤務日数', '授業分', '運営分', '授業時給', '運営時給', '授業給与', '運営給与', '交通費', '立替金', '合計']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['staff']['name'], $r['staff']['color_rank'], $r['staff']['departments'],
      $r['days'], $r['class_min'], $r['ops_min'], $r['class_rate'], $r['ops_rate'],
      $r['class_pay'], $r['ops_pay'], $r['transport'], ($r['advance'] ?? 0), $r['total'],
    ]);
  }
  fclose($out);
  exit;
}

$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));

$sumClass = $sumOps = $sumTrans = $sumAdv = $sumTotal = 0;
foreach ($rows as $r) { $sumClass += $r['class_pay']; $sumOps += $r['ops_pay']; $sumTrans += $r['transport']; $sumAdv += ($r['advance'] ?? 0); $sumTotal += $r['total']; }

// コピー用TSV（振込連携などへの貼り付け用）
$tsv = "講師\tカラー\t勤務日数\t授業給与\t運営給与\t交通費\t立替金\t合計\n";
foreach ($rows as $r) {
  $tsv .= $r['staff']['name'] . "\t" . $r['staff']['color_rank'] . "\t" . $r['days'] . "\t"
        . $r['class_pay'] . "\t" . $r['ops_pay'] . "\t" . $r['transport'] . "\t" . ($r['advance'] ?? 0) . "\t" . $r['total'] . "\n";
}

render_header('給与計算', $user, 'payroll.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">給与計算・振込一覧</h4>
      <div class="d-flex gap-2 align-items-center">
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
        </div>
        <a class="btn btn-sm btn-success" href="?m=<?= h($month) ?>&export=csv">CSVダウンロード</a>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="copyTsv()">コピー</button>
        <form method="post" class="d-inline" onsubmit="return confirm('<?= h($month) ?> の給与明細を対象者全員に発行します（メールは送りません）。よろしいですか？');">
          <?= csrf_field() ?><input type="hidden" name="action" value="issue_all">
          <button class="btn btn-sm btn-primary">全員に発行</button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('<?= h($month) ?> の発行済み明細について、対象者全員にメール送信します。よろしいですか？');">
          <?= csrf_field() ?><input type="hidden" name="action" value="notify_all">
          <button class="btn btn-sm btn-outline-primary">全員にメール送信</button>
        </form>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
    <?php if (!staff_advances_table_exists()): ?><div class="alert alert-warning py-2 small">立替金を保存するには <code>migrations/017_staff_advances.sql</code> を実行してください（未実施の間は立替金は0で計算されます）。</div><?php endif; ?>
    <p class="text-muted small">確定シフト（シフト申請・確定で確定したもの）と時給表から計算します。<strong>拘束6時間超は60分の休憩を運営時間から自動控除（シフトごとに変更可）</strong>。授業給与=round(授業分/60×授業時給)、運営給与=round(運営分/60×運営時給)、交通費は講師の区分で算定（徒歩/定期=0／公共交通=1日額×対象日数で月8日以下は半額・9日以上は全額／車・バイク=≤5日:日数×200・超過:切上げ(日数/5)×1000）。送迎等で<strong>交通費なしの日</strong>は「打刻・確定シフト」で日別に指定。<strong>立替金</strong>は講師ごとに手入力（合計に加算）。</p>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>講師</th><th>カラー</th><th class="text-end">勤務日数</th>
              <th class="text-end">授業</th><th class="text-end">運営</th>
              <th class="text-end">授業給与</th><th class="text-end">運営給与</th>
              <th class="text-end">交通費</th><th class="text-end" style="min-width:120px">立替金</th><th class="text-end">合計</th><th>明細</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr<?= empty($r['staff']['use_payroll']) ? ' class="table-warning"' : '' ?>>
                <td><?= h($r['staff']['name']) ?><?= empty($r['staff']['use_payroll']) ? ' <span class="badge bg-secondary">対象外</span>' : '' ?></td>
                <td><span class="badge" style="<?= color_style($r['staff']['color_rank']) ?>"><?= h($r['staff']['color_rank']) ?></span></td>
                <td class="text-end"><?= (int)$r['days'] ?>日</td>
                <td class="text-end small text-muted"><?= h(fmt_hm($r['class_min'])) ?></td>
                <td class="text-end small text-muted"><?= h(fmt_hm($r['ops_min'])) ?></td>
                <td class="text-end">¥<?= number_format($r['class_pay']) ?></td>
                <td class="text-end">¥<?= number_format($r['ops_pay']) ?></td>
                <td class="text-end">¥<?= number_format($r['transport']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-flex gap-1 justify-content-end align-items-center">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_advance"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>">
                    <input type="number" name="amount" value="<?= (int)($r['advance'] ?? 0) ?>" min="0" step="1" class="form-control form-control-sm text-end" style="width:84px" title="立替金（円）">
                    <button class="btn btn-sm btn-outline-secondary px-2">保存</button>
                  </form>
                </td>
                <td class="text-end fw-bold">¥<?= number_format($r['total']) ?></td>
                <td class="text-nowrap">
                  <?php $sl = $slipBy[(int)$r['staff']['id']] ?? null; ?>
                  <?php if ($sl): ?>
                    <a href="payslip_pdf.php?id=<?= (int)$sl['id'] ?>" target="_blank" class="badge bg-success text-decoration-none">PDF</a>
                    <?php if (!empty($sl['notified_at'])): ?><span class="badge bg-info text-dark" title="<?= h($sl['notified_at']) ?>">通知済</span><?php endif; ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="notify_one"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>"><button class="btn btn-sm btn-link p-0">メール送信</button></form>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="issue_one"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>"><button class="btn btn-sm btn-link p-0 text-muted">再発行</button></form>
                  <?php else: ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="issue_one"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>"><button class="btn btn-sm btn-outline-primary">発行</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="text-center text-muted py-4">この月の確定シフトはありません。<a href="shifts_admin.php?m=<?= h($month) ?>">シフト申請・確定</a>で確定してください。</td></tr>
            <?php else: ?>
              <tr class="table-light fw-bold">
                <td colspan="5" class="text-end">合計</td>
                <td class="text-end">¥<?= number_format($sumClass) ?></td>
                <td class="text-end">¥<?= number_format($sumOps) ?></td>
                <td class="text-end">¥<?= number_format($sumTrans) ?></td>
                <td class="text-end">¥<?= number_format($sumAdv) ?></td>
                <td class="text-end">¥<?= number_format($sumTotal) ?></td>
                <td></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <textarea id="tsv" class="d-none"><?= h($tsv) ?></textarea>
  </div>
  <script>
    function copyTsv(){
      var t=document.getElementById('tsv');
      navigator.clipboard.writeText(t.value).then(function(){ alert('コピーしました（表計算ソフトに貼り付けできます）。'); },
        function(){ t.classList.remove('d-none'); t.select(); document.execCommand('copy'); t.classList.add('d-none'); alert('コピーしました。'); });
    }
  </script>
<?php render_footer(); ?>
