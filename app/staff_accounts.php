<?php
// 講師ログインアカウントの一括作成（admin）
// 取り込んだ staff に対し、ログイン用 users（role=teacher / staff_id 紐付け）を発行する。
// GAS版 Users はパスワード無し（SSO）だったため、ここで初期パスワードを生成して配布する。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';
$created = [];  // [['name'=>, 'email'=>, 'password'=>], ...]
$skipped = [];  // ['name (理由)', ...]

// 紛らわしい文字を除いた初期パスワード
function gen_initial_password($len = 10) {
  $cs = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $s = '';
  for ($i = 0; $i < $len; $i++) { $s .= $cs[random_int(0, strlen($cs) - 1)]; }
  return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_accounts') {
  csrf_check();
  $ids = array_map('intval', (array)($_POST['staff_ids'] ?? []));
  if (!$ids) {
    $err = '対象の講師を1人以上選択してください。';
  } else {
    // 既存ユーザーのメール集合
    $usedEmails = array_map('strtolower', db()->query("SELECT email FROM users")->fetchAll(PDO::FETCH_COLUMN));
    $usedEmails = array_flip($usedEmails);

    $place = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT s.id, s.name, s.email, s.tenant_id
                           FROM staff s
                           LEFT JOIN users u ON u.staff_id = s.id
                          WHERE s.id IN ($place) AND u.id IS NULL");
    $st->execute($ids);
    $targets = $st->fetchAll();

    $ins = db()->prepare("INSERT INTO users (email, password_hash, role, staff_id, display_name, is_active)
                          VALUES (?, ?, 'teacher', ?, ?, 1)");
    db()->beginTransaction();
    try {
      foreach ($targets as $s) {
        $email = trim((string)$s['email']);
        if ($email === '') { $skipped[] = $s['name'] . '（メール未登録）'; continue; }
        if (isset($usedEmails[strtolower($email)])) { $skipped[] = $s['name'] . '（メール重複）'; continue; }
        $pw = gen_initial_password();
        $ins->execute([$email, password_hash($pw, PASSWORD_DEFAULT), (int)$s['id'], $s['name']]);
        $usedEmails[strtolower($email)] = 1;
        $created[] = ['name' => $s['name'], 'email' => $email, 'password' => $pw];
      }
      db()->commit();
      $mailed = 0;
      foreach ($created as $c) {
        if (send_account_email($c['email'], $c['name'], $c['email'], $c['password'], false)) { $mailed++; }
      }
      $flash = count($created) . '件のアカウントを作成しました'
             . ($skipped ? '（スキップ ' . count($skipped) . '件）' : '') . '。'
             . ($mailed ? " {$mailed}件にログイン情報をメール送信しました。" : '');
    } catch (Throwable $e) {
      db()->rollBack();
      $created = [];
      $err = '作成中にエラー: ' . $e->getMessage();
    }
  }
}

// アカウント未作成の在籍講師
$staff = db()->query(
  "SELECT s.id, s.name, s.departments, s.school, s.email, s.color_rank
     FROM staff s
     LEFT JOIN users u ON u.staff_id = s.id
    WHERE s.is_active = 1 AND u.id IS NULL
    ORDER BY s.name")->fetchAll();
$usedEmails = array_flip(array_map('strtolower', db()->query("SELECT email FROM users")->fetchAll(PDO::FETCH_COLUMN)));

render_header('講師アカウント一括作成', $user, 'users.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">講師アカウント一括作成</h4>
      <a href="users.php" class="btn btn-sm btn-outline-secondary">← ユーザー管理へ</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <?php if ($created): ?>
      <div class="card border-success shadow-sm mb-4">
        <div class="card-header bg-success text-white">作成したアカウントと初期パスワード（この画面でしか表示されません。必ず控えてください）</div>
        <div class="card-body">
          <table class="table table-sm">
            <thead class="table-light"><tr><th>講師</th><th>ログインID（メール）</th><th>初期パスワード</th></tr></thead>
            <tbody>
              <?php foreach ($created as $c): ?>
                <tr><td><?= h($c['name']) ?></td><td><code><?= h($c['email']) ?></code></td><td><code><?= h($c['password']) ?></code></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <label class="form-label small mb-1">配布用（コピーしてご利用ください）：</label>
          <textarea class="form-control form-control-sm" rows="4" readonly><?php
            foreach ($created as $c) { echo h($c['name'] . "\t" . $c['email'] . "\t" . $c['password'] . "\n"); }
          ?></textarea>
          <p class="text-muted small mt-2 mb-0">※ 各講師に初期パスワードを伝え、ログイン後に変更してもらってください（パスワード変更はユーザー管理から可能）。</p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($skipped): ?>
      <div class="alert alert-warning py-2 small">スキップ：<?= h(implode(' / ', $skipped)) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_accounts">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>アカウント未作成の在籍講師 <span class="text-muted small">（<?= count($staff) ?>名）</span></span>
          <div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.chk:not(:disabled)').forEach(c=>c.checked=true)">全選択</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=false)">解除</button>
            <button class="btn btn-sm btn-primary ms-2">選択した講師にアカウント作成</button>
          </div>
        </div>
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th style="width:40px"></th><th>講師</th><th>部署/校舎</th><th>カラー</th><th>ログインID（メール）</th></tr></thead>
          <tbody>
            <?php if (!$staff): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">未作成の講師はいません。</td></tr>
            <?php endif; ?>
            <?php foreach ($staff as $s): ?>
              <?php
                $email = trim((string)$s['email']);
                $dup   = $email !== '' && isset($usedEmails[strtolower($email)]);
                $ok    = $email !== '' && !$dup;
              ?>
              <tr>
                <td><input type="checkbox" class="form-check-input chk" name="staff_ids[]" value="<?= (int)$s['id'] ?>" <?= $ok ? '' : 'disabled' ?>></td>
                <td><?= h($s['name']) ?></td>
                <td class="small"><?= h($s['departments']) ?><?php if ($s['school']): ?> / <?= h($s['school']) ?><?php endif; ?></td>
                <td><span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span></td>
                <td class="small">
                  <?php if ($email === ''): ?><span class="text-danger">メール未登録（作成不可）</span>
                  <?php elseif ($dup): ?><?= h($email) ?> <span class="text-warning">（既に使用中）</span>
                  <?php else: ?><?= h($email) ?><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small mt-2">
        メール未登録の講師はログインIDが作れません。先に「ユーザー管理」で個別作成するか、staff にメールを登録してください。
      </p>
    </form>
  </div>
<?php render_footer(); ?>
