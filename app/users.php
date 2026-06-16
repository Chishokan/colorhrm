<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';
$roles = ['admin', 'staff', 'teacher'];

// 講師の選択肢（teacher 紐付け用）
$staffOptions = db()->query("SELECT id, name, departments FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();

// ------------------------------------------------------------
// POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $email   = trim($_POST['email'] ?? '');
    $display = trim($_POST['display_name'] ?? '');
    $role    = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'teacher';
    $sid     = ($_POST['staff_id'] ?? '') !== '' ? (int)$_POST['staff_id'] : null;
    $pw      = (string)($_POST['password'] ?? '');

    if ($email === '' || $pw === '') {
      $err = 'メールアドレスとパスワードは必須です。';
    } elseif (strlen($pw) < 8) {
      $err = 'パスワードは8文字以上にしてください。';
    } else {
      // メール重複チェック
      $chk = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
      $chk->execute([$email]);
      if ($chk->fetch()) {
        $err = 'そのメールアドレスは既に登録されています。';
      } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $sql  = "INSERT INTO users (email, password_hash, role, staff_id, display_name, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)";
        db()->prepare($sql)->execute([$email, $hash, $role, $sid, $display]);
        $sent  = send_account_email($email, $display, $email, $pw, false);
        $flash = "ユーザー「{$email}」を作成しました。"
               . ($sent ? 'ログイン情報をメール送信しました。' : 'メールは送信していません（メール設定をご確認ください）。');
      }
    }

  } elseif ($action === 'update') {
    $id      = (int)($_POST['id'] ?? 0);
    $role    = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'teacher';
    $sid     = ($_POST['staff_id'] ?? '') !== '' ? (int)$_POST['staff_id'] : null;
    $active  = isset($_POST['is_active']) ? 1 : 0;
    if (isset(users_columns()['view_recruitment'])) {
      $vr = isset($_POST['view_recruitment']) ? 1 : 0;
      $vs = isset($_POST['view_staff_list']) ? 1 : 0;
      db()->prepare("UPDATE users SET role=?, staff_id=?, is_active=?, view_recruitment=?, view_staff_list=? WHERE id=?")
          ->execute([$role, $sid, $active, $vr, $vs, $id]);
    } else {
      db()->prepare("UPDATE users SET role = ?, staff_id = ?, is_active = ? WHERE id = ?")
          ->execute([$role, $sid, $active, $id]);
    }
    $flash = 'ユーザー情報を更新しました。';

  } elseif ($action === 'reset_pw') {
    $id = (int)($_POST['id'] ?? 0);
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 8) {
      $err = 'パスワードは8文字以上にしてください。';
    } else {
      db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
          ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
      $row = db()->prepare("SELECT email, display_name FROM users WHERE id = ?");
      $row->execute([$id]);
      $tgt  = $row->fetch();
      $sent = $tgt ? send_account_email($tgt['email'], $tgt['display_name'], $tgt['email'], $pw, true) : false;
      $flash = 'パスワードを変更しました。' . ($sent ? '新しいパスワードをメール送信しました。' : '');
    }
  }
}

$users = db()->query(
  "SELECT u.*, s.name AS staff_name
     FROM users u
     LEFT JOIN staff s ON s.id = u.staff_id
    ORDER BY u.role, u.email")->fetchAll();

render_header('ユーザー管理', $user, 'users.php');
?>
  <div class="container py-4">

    <div class="d-flex justify-content-end mb-2">
      <a href="staff_accounts.php" class="btn btn-sm btn-success">講師アカウント一括作成</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <!-- 新規作成 -->
    <div class="card shadow-sm mb-4">
      <div class="card-header">ユーザーを作成</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-3">
            <label class="form-label small mb-0">メール</label>
            <input name="email" type="email" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">表示名</label>
            <input name="display_name" type="text" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">ロール</label>
            <select name="role" class="form-select form-select-sm">
              <?php foreach ($roles as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">講師紐付け</label>
            <select name="staff_id" class="form-select form-select-sm">
              <option value="">（なし）</option>
              <?php foreach ($staffOptions as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">パスワード（8文字以上）</label>
            <input name="password" type="text" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-1">
            <button class="btn btn-sm btn-primary w-100">作成</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 一覧 -->
    <div class="card shadow-sm">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr><th>メール / 表示名</th><th>ロール</th><th>講師紐付け</th><th class="text-center">有効</th><th class="text-end" style="width:340px">操作</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <?= h($u['email']) ?>
                <?php if (!empty($u['display_name'])): ?><div class="small text-muted"><?= h($u['display_name']) ?></div><?php endif; ?>
              </td>
              <td colspan="3">
                <form method="post" class="row g-1 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <div class="col-auto">
                    <select name="role" class="form-select form-select-sm">
                      <?php foreach ($roles as $r): ?>
                        <option value="<?= h($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= h($r) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto">
                    <select name="staff_id" class="form-select form-select-sm">
                      <option value="">（紐付けなし）</option>
                      <?php foreach ($staffOptions as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$u['staff_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-auto form-check ms-2">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="act<?= (int)$u['id'] ?>" <?= $u['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="act<?= (int)$u['id'] ?>">有効</label>
                  </div>
                  <?php if (isset(users_columns()['view_recruitment'])): ?>
                  <div class="col-auto form-check ms-1" title="staffに採用閲覧を許可">
                    <input class="form-check-input" type="checkbox" name="view_recruitment" id="vr<?= (int)$u['id'] ?>" <?= !empty($u['view_recruitment']) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="vr<?= (int)$u['id'] ?>">採用</label>
                  </div>
                  <div class="col-auto form-check ms-1" title="teacherに講師一覧を許可">
                    <input class="form-check-input" type="checkbox" name="view_staff_list" id="vs<?= (int)$u['id'] ?>" <?= !empty($u['view_staff_list']) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="vs<?= (int)$u['id'] ?>">講師一覧</label>
                  </div>
                  <?php endif; ?>
                  <div class="col-auto">
                    <button class="btn btn-sm btn-outline-primary">保存</button>
                  </div>
                </form>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline-flex gap-1 justify-content-end">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_pw">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input name="password" type="text" class="form-control form-control-sm" style="width:150px" placeholder="新パスワード">
                  <button class="btn btn-sm btn-outline-danger">PW変更</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="text-muted small mt-3">
      ※ セキュリティTODO: 初期管理者 <code>admin@chishokan.local</code> のパスワードを <code>admin1234</code> から変更してください。
    </p>
  </div>
<?php render_footer(); ?>
