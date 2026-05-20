<?php
$envPath = dirname(__DIR__, 2) . '/env/compass.php';
if (!is_file($envPath)) {
    http_response_code(500);
    echo 'env.php が見つかりません';
    exit;
}
require_once $envPath;

function db(): PDO {
    global $Compass_DB_Host, $Compass_DB_Name, $Compass_DB_User, $Compass_DB_Pass;
    $dsn = "mysql:host={$Compass_DB_Host};dbname={$Compass_DB_Name};charset=utf8mb4";
    return new PDO($dsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fetchGeminiModels(string $apiKey): array {
    if (empty($apiKey)) {
        return [];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return [];
    }
    $json = json_decode($response, true);
    if (!is_array($json) || empty($json['models']) || !is_array($json['models'])) {
        return [];
    }
    $models = [];
    foreach ($json['models'] as $entry) {
        $name = $entry['name'] ?? '';
        if ($name !== '' && is_string($name) && str_starts_with($name, 'models/')) {
            $models[] = $name;
        }
    }
    $models = array_values(array_unique($models));
    sort($models, SORT_NATURAL);
    return $models;
}

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_settings (id TINYINT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_usage_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_error_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NULL, message TEXT NOT NULL, detail TEXT NULL, request_body MEDIUMTEXT NULL, response_body MEDIUMTEXT NULL, http_status INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_consultations (id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(100) NULL, consultation_type VARCHAR(20) NOT NULL, input_text MEDIUMTEXT NOT NULL, extra_text MEDIUMTEXT NULL, prompt_text MEDIUMTEXT NULL, response_json MEDIUMTEXT NULL, pulse_rate INT NULL, level_badge VARCHAR(255) NULL, shared TINYINT(1) NOT NULL DEFAULT 0, share_token VARCHAR(64) NULL, shared_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_share_token (share_token), INDEX idx_device_id (device_id), INDEX idx_created_at (created_at))");
$pdo->exec("INSERT INTO compass_settings (id, model_name) VALUES (1, 'models/gemini-2.5-flash') ON DUPLICATE KEY UPDATE id=id");

$currentModel = $pdo->query('SELECT model_name FROM compass_settings WHERE id = 1')->fetchColumn() ?: 'models/gemini-2.5-flash';
$availableModels = fetchGeminiModels($Gemini_API_Key);
if (!in_array($currentModel, $availableModels, true)) {
    $availableModels[] = $currentModel;
    sort($availableModels, SORT_NATURAL);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_action']) && $_POST['admin_action'] === 'delete_consultation') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM compass_consultations WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
        header('Location: admin.php?saved=1');
        exit;
    }
    if (isset($_POST['admin_action']) && $_POST['admin_action'] === 'delete_share_url') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE compass_consultations SET shared = 0, share_token = NULL, shared_at = NULL WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
        header('Location: admin.php?saved=1');
        exit;
    }
    $model = trim($_POST['model_name'] ?? '');
    if ($model === '' || !in_array($model, $availableModels, true)) {
        header('Location: admin.php?saved=0');
        exit;
    }
    $stmt = $pdo->prepare('UPDATE compass_settings SET model_name = :model WHERE id = 1');
    $stmt->execute(['model' => $model]);
    header('Location: admin.php?saved=1');
    exit;
}

$model = $currentModel;
$now = new DateTimeImmutable('now');
$today = $now->format('Y-m-d');
$startWeek = $now->modify('monday this week')->format('Y-m-d');
$startMonth = $now->format('Y-m-01');

$total = (int)$pdo->query('SELECT COUNT(*) FROM compass_usage_logs')->fetchColumn();
$stmtToday = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at) = :d');
$stmtToday->execute(['d' => $today]);
$todayCount = (int)$stmtToday->fetchColumn();
$stmtWeek = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at) >= :d');
$stmtWeek->execute(['d' => $startWeek]);
$weekCount = (int)$stmtWeek->fetchColumn();
$stmtMonth = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at) >= :d');
$stmtMonth->execute(['d' => $startMonth]);
$monthCount = (int)$stmtMonth->fetchColumn();

$ym = $_GET['ym'] ?? $now->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) {
    $ym = $now->format('Y-m');
}
$monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01') ?: $now->modify('first day of this month');
$prevYm = $monthDate->modify('-1 month')->format('Y-m');
$nextYm = $monthDate->modify('+1 month')->format('Y-m');

$stmtDaily = $pdo->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM compass_usage_logs WHERE DATE_FORMAT(created_at, "%Y-%m") = :ym GROUP BY DATE(created_at)');
$stmtDaily->execute(['ym' => $monthDate->format('Y-m')]);
$dailyRows = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);
$dailyMap = [];
foreach ($dailyRows as $row) { $dailyMap[$row['day']] = (int)$row['cnt']; }

$year = (int)($monthDate->format('Y'));
$stmtYearTotal = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE YEAR(created_at) = :y');
$stmtYearTotal->execute(['y' => $year]);
$yearTotal = (int)$stmtYearTotal->fetchColumn();
$stmtMonthly = $pdo->prepare('SELECT MONTH(created_at) AS m, COUNT(*) AS cnt FROM compass_usage_logs WHERE YEAR(created_at)=:y GROUP BY MONTH(created_at)');
$stmtMonthly->execute(['y' => $year]);
$monthlyRows = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);
$monthlyMap = array_fill(1, 12, 0);
foreach ($monthlyRows as $r) { $monthlyMap[(int)$r['m']] = (int)$r['cnt']; }

$errorLogs = $pdo->query('SELECT id, model_name, message, detail, http_status, created_at FROM compass_error_logs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$consultations = $pdo->query('SELECT id, device_id, consultation_type, input_text, pulse_rate, level_badge, shared, share_token, created_at FROM compass_consultations ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$sharedConsultations = array_values(array_filter($consultations, fn($r) => (int)$r['shared'] === 1));
$privateConsultations = array_values(array_filter($consultations, fn($r) => (int)$r['shared'] !== 1));

$firstDay = $monthDate;
$firstWeekday = (int)$firstDay->format('w');
$daysInMonth = (int)$monthDate->format('t');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compass Admin</title>
<link rel="stylesheet" href="compass.css">
<style>
.admin-grid{display:grid;gap:16px}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.stat{background:var(--surface2);border:2px solid #fff;border-radius:16px;padding:12px}.stat .v{font-size:24px;font-weight:700}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.cal-cell{background:#fff;border-radius:12px;padding:8px;min-height:64px;border:1px solid #f0e9f6}.cal-off{opacity:.35}.cal-cnt{font-size:12px;color:var(--accent);font-weight:700}.month-nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.month-bars{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.m-item{background:#fff;border-radius:12px;padding:8px;border:1px solid #eee}.list-compact li{margin-bottom:12px}@media (min-width:960px){.admin-grid{grid-template-columns:1.2fr 1fr}.wide{grid-column:1 / -1}}@media (max-width:640px){.stat-grid{grid-template-columns:1fr}.month-bars{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body class="view-mobile">
<main>
<header><div class="logo-mark"><div class="logo-text"><h1>Compass Admin</h1><p>モデル設定・使用状況</p></div></div></header>
<div class="page-wrap admin-grid">
<div class="glass-card"><div class="card-label">モデル設定</div>
<?php if(isset($_GET['saved']) && $_GET['saved'] === '1'): ?><p class="hint">保存しました。</p><?php elseif(isset($_GET['saved']) && $_GET['saved'] === '0'): ?><p class="hint">保存に失敗しました。モデルを選び直してください。</p><?php endif; ?>
<form method="post"><select name="model_name" style="width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;"><?php foreach($availableModels as $m): ?><option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>" <?= $m === $model ? 'selected' : '' ?>><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><div class="btn-submit-wrap section-gap"><button class="btn-submit" type="submit"><span>保存</span></button></div></form>
</div>

<div class="glass-card"><div class="card-label">使用回数（全モデル合計）</div>
<div class="stat-grid"><div class="stat"><div>今月</div><div class="v"><?= $monthCount ?></div></div><div class="stat"><div>今週</div><div class="v"><?= $weekCount ?></div></div><div class="stat"><div>今日</div><div class="v"><?= $todayCount ?></div></div></div>
<p class="hint" style="margin-top:10px">総リクエスト数: <?= $total ?></p>
</div>

<div class="glass-card wide"><div class="card-label">月間カレンダー（<?= htmlspecialchars($monthDate->format('Y年n月'), ENT_QUOTES, 'UTF-8') ?>）</div>
<div class="month-nav"><a href="?ym=<?= htmlspecialchars($prevYm, ENT_QUOTES, 'UTF-8') ?>">← 先月</a><strong><?= htmlspecialchars($monthDate->format('Y年n月'), ENT_QUOTES, 'UTF-8') ?></strong><a href="?ym=<?= htmlspecialchars($nextYm, ENT_QUOTES, 'UTF-8') ?>">来月 →</a></div>
<div class="calendar"><?php foreach(['日','月','火','水','木','金','土'] as $wd): ?><div class="cal-cell" style="min-height:auto;font-weight:700;text-align:center"><?= $wd ?></div><?php endforeach; ?>
<?php for($i=0;$i<$firstWeekday;$i++): ?><div class="cal-cell cal-off"></div><?php endfor; ?>
<?php for($d=1;$d<=$daysInMonth;$d++): $dateKey=$monthDate->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT); $cnt=$dailyMap[$dateKey]??0; ?><div class="cal-cell"><div><?= $d ?>日</div><div class="cal-cnt"><?= $cnt ?>回</div></div><?php endfor; ?></div>
</div>

<div class="glass-card wide"><div class="card-label"><?= $year ?>年の集計</div><p class="hint">年間合計: <strong><?= $yearTotal ?>回</strong></p>
<div class="month-bars"><?php for($m=1;$m<=12;$m++): ?><div class="m-item"><?= $m ?>月: <strong><?= $monthlyMap[$m] ?></strong>回</div><?php endfor; ?></div>
</div>

<div class="glass-card"><div class="card-label">共有中の相談ログ</div><?php if (empty($sharedConsultations)): ?><p class="hint">共有中データはありません。</p><?php else: ?><ul class="list-compact"><?php foreach($sharedConsultations as $row): ?><li><strong>#<?= (int)$row['id'] ?></strong> [<?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?>]<br><?= htmlspecialchars(mb_strimwidth((string)$row['input_text'],0,80,'...'), ENT_QUOTES, 'UTF-8') ?><br><small><a href="index.php?share=<?= htmlspecialchars((string)$row['share_token'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">共有URLを開く</a></small></li><?php endforeach; ?></ul><?php endif; ?></div>

<div class="glass-card"><div class="card-label">エラーログ（最新100件）</div><?php if (empty($errorLogs)): ?><p class="hint">エラーログはありません。</p><?php else: ?><ul class="list-compact"><?php foreach($errorLogs as $log): ?><li><strong>#<?= (int)$log['id'] ?></strong> [<?= htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8') ?>] HTTP <?= htmlspecialchars((string)($log['http_status'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string)$log['message'], ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul><?php endif; ?></div>
</div></main></body></html>
