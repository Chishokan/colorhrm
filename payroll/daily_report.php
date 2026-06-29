<?php
// 報告（日報）。Googleフォーム「報告フォーム」の代替。講師が1日の終わりに提出。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$staffId = (int)($user['staff_id'] ?? 0);

if ($staffId <= 0) {
  render_header('報告（日報）', $user, 'daily_report.php');
  echo '<div class="container py-4"><div class="alert alert-warning">このアカウントは講師（staff）に紐付いていないため、報告できません。管理者にお問い合わせください。</div></div>';
  render_footer();
  exit;
}

$me = db()->prepare("SELECT name FROM staff WHERE id=? LIMIT 1");
$me->execute([$staffId]);
$myName = (string)($me->fetchColumn() ?: '');

$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
  csrf_check();
  if (!daily_reports_table_exists()) {
    $err = '日報テーブル（daily_reports）がありません。管理者が migrations/022_daily_reports.sql を実行してください。';
  } else {
    $type   = in_array($_POST['report_type'] ?? '', ['通常', '講習'], true) ? $_POST['report_type'] : '通常';
    $wdate  = trim($_POST['work_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wdate)) { $wdate = date('Y-m-d'); }
    $parent = trim($_POST['parent_share'] ?? '');
    $sover  = in_array($_POST['shift_over'] ?? '', ['シフト超過', 'シフト超過なし'], true) ? $_POST['shift_over'] : '';
    if ($parent === '') { $err = '「保護者共有したいこと」を入力してください（なければ「なし」）。'; }
    elseif ($sover === '') { $err = '「シフト超過申請」を選択してください。'; }
    else {
      db()->prepare("INSERT INTO daily_reports
          (tenant_id,staff_id,report_type,work_date,new_trial,irregular,no_improve,parent_share,break_time,shift_over,shift_over_detail,work_end,work_content)
          VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $staffId, $type, $wdate,
          trim($_POST['new_trial'] ?? ''), trim($_POST['irregular'] ?? ''), trim($_POST['no_improve'] ?? ''),
          $parent, trim($_POST['break_time'] ?? ''), $sover, trim($_POST['shift_over_detail'] ?? ''),
          trim($_POST['work_end'] ?? ''), trim($_POST['work_content'] ?? ''),
        ]);
      $flash = '報告を送信しました。ありがとうございます！';
    }
  }
}

// 自分の今月の報告
$month = valid_month($_GET['m'] ?? date('Y-m'));
$mine = [];
if (daily_reports_table_exists()) {
  $q = db()->prepare("SELECT * FROM daily_reports WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date DESC, id DESC");
  $q->execute([$staffId, $month]);
  $mine = $q->fetchAll();
}

render_header('報告（日報）', $user, 'daily_report.php');
$ta = function ($name, $val = '', $rows = 3, $ph = '') {
  return '<textarea name="' . h($name) . '" rows="' . (int)$rows . '" class="form-control form-control-sm" placeholder="' . h($ph) . '">' . h($val) . '</textarea>';
};
?>
  <div class="container py-4" style="max-width:760px">
    <h4 class="mb-1">報告（日報）</h4>
    <p class="text-muted small mb-3">1日の業務の終わりに必ず情報共有をお願いします！（生徒1人1人の状況把握・即対応／保護者への状況共有のため）</p>
    <?php if (!daily_reports_table_exists()): ?>
      <div class="alert alert-warning py-2 small">日報テーブルがありません。<code>migrations/022_daily_reports.sql</code> を実行してください。</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="submit_report">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small mb-1">報告者</label>
              <input class="form-control form-control-sm" value="<?= h($myName) ?>" disabled>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">種別</label>
              <select name="report_type" class="form-select form-select-sm">
                <option value="通常">通常</option>
                <option value="講習">講習</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">勤務日 <span class="text-danger">*</span></label>
              <input type="date" name="work_date" value="<?= h(date('Y-m-d')) ?>" class="form-control form-control-sm" required>
            </div>

            <div class="col-12">
              <label class="form-label small mb-1">【新規体験生の状況共有】</label>
              <div class="form-text small mt-0 mb-1">コミの内容、学習状況・姿勢、本人OKの有無。※ない場合は「なし」と記載</div>
              <?= $ta('new_trial', $_POST['new_trial'] ?? '', 3, '例）日野中3 田中／サッカーで負けた。笑顔あり。英語、語順がわかっていない。本人OKとれた。') ?>
            </div>

            <div class="col-12">
              <label class="form-label small mb-1">【イレギュラー報告】</label>
              <div class="form-text small mt-0 mb-1">①生徒名 ②何があったか（宿題忘れ・遅刻・居眠り等）③理由 ④どう対応したか。※ない場合は「なし」、複数名は複数記載</div>
              <?= $ta('irregular', $_POST['irregular'] ?? '', 3) ?>
            </div>

            <div class="col-12">
              <label class="form-label small mb-1">【上記指導・対応をしたが改善が見られない生徒】</label>
              <div class="form-text small mt-0 mb-1">※ない場合は「なし」と記載</div>
              <?= $ta('no_improve', $_POST['no_improve'] ?? '', 2) ?>
            </div>

            <div class="col-12">
              <label class="form-label small mb-1">【保護者共有したいこと】 <span class="text-danger">*</span></label>
              <div class="form-text small mt-0 mb-1">①褒めた生徒 ②内容。※必ず口頭で「褒めた」ことを。嬉しい報告たくさん待ってます！</div>
              <?= $ta('parent_share', $_POST['parent_share'] ?? '', 3) ?>
            </div>

            <div class="col-md-6">
              <label class="form-label small mb-1">【休憩時間】</label>
              <input name="break_time" value="<?= h($_POST['break_time'] ?? '') ?>" class="form-control form-control-sm" placeholder="例）12:00~13:00 ／ なし">
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-1">【業務が終了した時間】</label>
              <input name="work_end" value="<?= h($_POST['work_end'] ?? '') ?>" class="form-control form-control-sm" placeholder="例）21:35（5分単位）">
            </div>

            <div class="col-md-5">
              <label class="form-label small mb-1">シフト超過申請 <span class="text-danger">*</span></label>
              <div>
                <label class="me-3 small"><input type="radio" name="shift_over" value="シフト超過なし" checked> シフト超過なし</label>
                <label class="small"><input type="radio" name="shift_over" value="シフト超過"> 超過した</label>
              </div>
            </div>
            <div class="col-md-7">
              <label class="form-label small mb-1">【シフト超過】の詳細</label>
              <input name="shift_over_detail" value="<?= h($_POST['shift_over_detail'] ?? '') ?>" class="form-control form-control-sm" placeholder="超過した場合の内容（理由・時間など）">
            </div>

            <div class="col-12">
              <label class="form-label small mb-1">【業務内容】</label>
              <div class="form-text small mt-0 mb-1">ファイル記入、宿題居残り対応、報告フォーム送信 など</div>
              <?= $ta('work_content', $_POST['work_content'] ?? '', 2) ?>
            </div>
          </div>
          <div class="text-end mt-3"><button class="btn btn-primary">報告を送信</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header">今月の自分の報告（<?= h($month) ?>）<span class="text-muted small ms-1"><?= count($mine) ?>件</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($mine as $r): ?>
          <div class="list-group-item small">
            <div><strong><?= h($r['work_date']) ?></strong> <span class="badge bg-light text-dark border"><?= h($r['report_type']) ?></span> <span class="text-muted">送信 <?= h(substr((string)$r['created_at'], 11, 5)) ?></span> <span class="badge <?= $r['shift_over'] === 'シフト超過' ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= h($r['shift_over'] ?: '—') ?></span></div>
            <?php if (trim((string)$r['parent_share']) !== ''): ?><div class="text-muted">保護者共有：<?= h(mb_strimwidth($r['parent_share'], 0, 60, '…')) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$mine): ?><div class="list-group-item text-center text-muted py-3">今月の報告はまだありません。</div><?php endif; ?>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
