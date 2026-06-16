<?php
// 研修コンテンツ・ビューア（受講者向け）。module_key のレッスンを順番に表示。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();

$mk = trim($_GET['module'] ?? '');
$lessons = [];
if ($mk !== '') {
  $q = db()->prepare("SELECT * FROM lessons WHERE module_key = ? ORDER BY sort_order, id");
  $q->execute([$mk]);
  $lessons = $q->fetchAll();
}

// 戻り先：別タブ（target=_blank）で開くと history.back() が効かないため、
// 参照元（同一ホスト）があればそこへ、無ければロール別の既定ページへ。
$back = (($user['role'] ?? '') === 'teacher') ? 'mypage.php' : 'lessons.php';
$ref  = $_SERVER['HTTP_REFERER'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($ref !== '' && $host !== '' && strpos($ref, '://' . $host) !== false && strpos($ref, 'lessons_view.php') === false) {
  $back = $ref;
}

render_header('研修: ' . $mk, $user, '');
?>
  <div class="container py-4" style="max-width:760px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">研修コンテンツ <span class="badge bg-dark"><?= h($mk) ?></span></h4>
      <a href="<?= h($back) ?>" class="btn btn-sm btn-outline-secondary">← 戻る</a>
    </div>
    <?php if (!$lessons): ?>
      <div class="alert alert-light border">このモジュールのコンテンツはまだありません。</div>
    <?php endif; ?>
    <?php foreach ($lessons as $i => $l): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-1"><?= ($i + 1) ?>. <?= h($l['title']) ?>
            <?php if ($l['video_duration']): ?><span class="text-muted small">（<?= h($l['video_duration']) ?>）</span><?php endif; ?>
          </h6>
          <div class="d-flex gap-3 small">
            <?php if ($l['video_url']): ?><a href="<?= h($l['video_url']) ?>" target="_blank" rel="noopener">▶ 動画を見る</a><?php endif; ?>
            <?php if ($l['material']): ?><a href="<?= h($l['material']) ?>" target="_blank" rel="noopener">📄 資料を開く</a><?php endif; ?>
          </div>
          <?php if ($l['note']): ?><div class="small text-muted mt-1"><?= nl2br(h($l['note'])) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php render_footer(); ?>
