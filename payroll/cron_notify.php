<?php
// ============================================================
// シフト確定待ちメール通知の定時実行エンドポイント。
//   毎日13:00に1回、確定待ち（申請中シフト）があれば担当スタッフへまとめて通知する。
//   ＝ログインせず確認漏れした場合のセーフティネット。
//
// 【XServer の cron 設定（推奨）】コントロールパネル → Cron設定 で：
//     実行時間: 分=0 時=13 日=* 月=* 曜日=*
//     コマンド: php /home/(ユーザー名)/chishokan.co.jp/public_html/colorhrm-pay/cron_notify.php
//   ※ サーバー上の CLI 実行はトークン不要（信頼）。
//
// 【URL から叩く場合（curl / 外部スケジューラ）】トークン必須：
//     https://chishokan.co.jp/colorhrm-pay/cron_notify.php?token=（config.php の cron_token）
//
//   テスト送信（ガード無視で即送信）: 末尾に force=1
//     CLI:  php cron_notify.php force
//     URL:  ...cron_notify.php?token=XXX&force=1
// ============================================================
require __DIR__ . '/db.php';
require __DIR__ . '/lib.php';

$cli = (PHP_SAPI === 'cli');

// 認証：CLI（サーバー上）は信頼。Web からはトークン必須。
if (!$cli) {
  header('Content-Type: text/plain; charset=utf-8');
  $token = (string)config_value('cron_token', '');
  $given = (string)($_GET['token'] ?? '');
  if ($token === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
  }
}

// force 指定（ガードを無視して送信。手動テスト用）
$force = $cli
  ? in_array('force', array_slice($argv, 1), true)
  : (($_GET['force'] ?? '') !== '');

$r = maybe_send_pending_shift_digest($force);

$line = sprintf(
  "[%s] pending_shift digest: sent=%d recipients=%d pending_staff=%d skipped=%s force=%s\n",
  date('Y-m-d H:i:s'),
  $r['sent'], $r['recipients'], $r['pending_staff'], $r['skipped'] !== '' ? $r['skipped'] : '-',
  $force ? '1' : '0'
);
echo $line;
