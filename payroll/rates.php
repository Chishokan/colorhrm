<?php
// 時給表（WageRates）管理（admin）。カラー×部門の授業時給/運営時給。
// ColorHRM と共有する pay_rates テーブルを編集する（編集はこの給与アプリに一本化）。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'save') {
    $cr  = $_POST['class_rate'] ?? [];
    $opr = $_POST['ops_rate'] ?? [];
    $stmt = db()->prepare("UPDATE pay_rates SET class_rate=?, ops_rate=? WHERE id=?");
    foreach ($cr as $id => $v) {
      $stmt->execute([(int)$v, (int)($opr[$id] ?? 1031), (int)$id]);
    }
    $flash = '時給表を保存しました。';
  } elseif ($action === 'add') {
    $color = $_POST['color'] ?? '';
    $dept  = trim($_POST['department'] ?? '');
    if (!in_array($color, color_ranks(), true) || $dept === '') {
      $err = 'カラーと部門を指定してください。';
    } else {
      try {
        db()->prepare("INSERT INTO pay_rates (tenant_id,color,department,class_rate,ops_rate) VALUES (1,?,?,?,?)")
            ->execute([$color, $dept, (int)($_POST['new_class_rate'] ?? 1031), (int)($_POST['new_ops_rate'] ?? 1031)]);
        $flash = 'レートを追加しました。';
      } catch (Throwable $e) { $err = 'そのカラー×部門は既に存在します。'; }
    }
  } elseif ($action === 'delete') {
    db()->prepare("DELETE FROM pay_rates WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    $flash = 'レートを削除しました。';
  }
}

$rows = db()->query("SELECT * FROM pay_rates WHERE tenant_id=1 ORDER BY department, FIELD(color,'WHITE','GREEN','BLUE','YELLOW','RED')")->fetchAll();

render_header('時給表（WageRates）', $user, 'rates.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">時給表（WageRates）</h4>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
    <p class="text-muted small">授業・面談はカラー×部門の<strong>授業時給</strong>、その他業務は<strong>運営時給</strong>で計算します（GAS版 WageRates 準拠）。複数部門に所属する講師は最も高い授業時給が適用されます。</p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="card shadow-sm">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>部門</th><th>カラー</th><th style="width:140px">授業時給</th><th style="width:140px">運営時給</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="small"><?= h($r['department']) ?></td>
                  <td><span class="badge" style="<?= color_style($r['color']) ?>"><?= h($r['color']) ?></span></td>
                  <td><div class="input-group input-group-sm"><span class="input-group-text">¥</span><input name="class_rate[<?= (int)$r['id'] ?>]" type="number" value="<?= (int)$r['class_rate'] ?>" class="form-control"></div></td>
                  <td><div class="input-group input-group-sm"><span class="input-group-text">¥</span><input name="ops_rate[<?= (int)$r['id'] ?>]" type="number" value="<?= (int)$r['ops_rate'] ?>" class="form-control"></div></td>
                  <td class="text-end">
                    <button type="submit" form="del<?= (int)$r['id'] ?>" class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('削除しますか？');">削除</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-end"><button class="btn btn-success">時給表を保存</button></div>
      </div>
    </form>

    <?php foreach ($rows as $r): ?>
      <form method="post" id="del<?= (int)$r['id'] ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"></form>
    <?php endforeach; ?>

    <div class="card shadow-sm mt-3">
      <div class="card-header">レートを追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <div class="col-auto"><label class="form-label small mb-0">カラー</label>
            <select name="color" class="form-select form-select-sm"><?php foreach (color_ranks() as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-auto"><label class="form-label small mb-0">部門</label><input name="department" class="form-control form-control-sm"></div>
          <div class="col-auto"><label class="form-label small mb-0">授業時給</label><input name="new_class_rate" type="number" value="1031" class="form-control form-control-sm"></div>
          <div class="col-auto"><label class="form-label small mb-0">運営時給</label><input name="new_ops_rate" type="number" value="1031" class="form-control form-control-sm"></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-success">追加</button></div>
        </form>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
