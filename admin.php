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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

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
$counts = $pdo->query('SELECT model_name, COUNT(*) AS cnt FROM compass_usage_logs GROUP BY model_name ORDER BY cnt DESC')->fetchAll(PDO::FETCH_ASSOC);
$total = $pdo->query('SELECT COUNT(*) FROM compass_usage_logs')->fetchColumn();
$errorLogs = $pdo->query('SELECT id, model_name, message, detail, http_status, created_at FROM compass_error_logs ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$consultations = $pdo->query('SELECT id, device_id, consultation_type, input_text, pulse_rate, level_badge, shared, share_token, created_at FROM compass_consultations ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Compass Admin</title><link rel="stylesheet" href="compass.css"></head><body class="view-mobile"><main><header><div class="logo-mark"><div class="logo-text"><h1>Compass Admin</h1><p>モデル設定・使用状況</p></div></div></header><div class="page-wrap"><div class="glass-card section-gap"><div class="card-label">モデル設定</div><?php if(isset($_GET['saved']) && $_GET['saved'] === '1'): ?><p class="hint">保存しました。</p><?php elseif(isset($_GET['saved']) && $_GET['saved'] === '0'): ?><p class="hint">保存に失敗しました。モデルを選び直してください。</p><?php endif; ?><form method="post"><select name="model_name" style="width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;"><?php foreach($availableModels as $m): ?><option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>" <?= $m === $model ? 'selected' : '' ?>><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><div class="btn-submit-wrap section-gap"><button class="btn-submit" type="submit"><span>保存</span></button></div></form></div><div class="glass-card section-gap"><div class="card-label">使用回数</div><p class="hint">総リクエスト数: <?= (int)$total ?></p><ul><?php foreach($counts as $row): ?><li><?= htmlspecialchars($row['model_name'], ENT_QUOTES, 'UTF-8') ?>: <?= (int)$row['cnt'] ?></li><?php endforeach; ?></ul></div><div class="glass-card section-gap"><div class="card-label">相談ログ（最新100件）</div><?php if (empty($consultations)): ?><p class="hint">相談ログはありません。</p><?php else: ?><ul><?php foreach($consultations as $row): ?><li><strong>#<?= (int)$row['id'] ?></strong> [<?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?>] <?= htmlspecialchars((string)$row['consultation_type'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)$row['device_id'], ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars(mb_strimwidth((string)$row['input_text'],0,120,'...'), ENT_QUOTES, 'UTF-8') ?><br>結果: <?= (int)($row['pulse_rate'] ?? 0) ?>% <?= htmlspecialchars((string)($row['level_badge'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?php if ((int)$row['shared']===1): ?><br><small>共有URL: <a href="index.php?share=<?= htmlspecialchars((string)$row['share_token'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">index.php?share=<?= htmlspecialchars((string)$row['share_token'], ENT_QUOTES, 'UTF-8') ?></a></small><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div><div class="glass-card section-gap"><div class="card-label">エラーログ（最新100件）</div><?php if (empty($errorLogs)): ?><p class="hint">エラーログはありません。</p><?php else: ?><ul><?php foreach($errorLogs as $log): ?><li><strong>#<?= (int)$log['id'] ?></strong> [<?= htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8') ?>] <?= htmlspecialchars((string)($log['model_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8') ?> / HTTP <?= htmlspecialchars((string)($log['http_status'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?><br><?= htmlspecialchars((string)$log['message'], ENT_QUOTES, 'UTF-8') ?><?php if(!empty($log['detail'])): ?><br><small><?= nl2br(htmlspecialchars((string)$log['detail'], ENT_QUOTES, 'UTF-8')) ?></small><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div></div></main></body></html>
