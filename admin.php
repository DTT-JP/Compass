<?php
$envPath = dirname(__DIR__, 2) . '/env/compass.php';
if (!is_file($envPath)) {
    http_response_code(500);
    echo 'env.php が見つかりません';
    exit;
}
require_once $envPath;

$adminUser = $Compass_Admin_User ?? '';
$adminPass = $Compass_Admin_Pass ?? '';
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) || $_SERVER['PHP_AUTH_USER'] !== $adminUser || $_SERVER['PHP_AUTH_PW'] !== $adminPass) {
    header('WWW-Authenticate: Basic realm="Compass Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required';
    exit;
}

function db(): PDO {
    global $Compass_DB_Host, $Compass_DB_Name, $Compass_DB_User, $Compass_DB_Pass;
    $host = $Compass_DB_Host;
    $name = $Compass_DB_Name;
    $user = $Compass_DB_User;
    $pass = $Compass_DB_Pass;
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_settings (id TINYINT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_usage_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO compass_settings (id, model_name) VALUES (1, 'models/gemini-2.5-flash') ON DUPLICATE KEY UPDATE id=id");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model = trim($_POST['model_name'] ?? 'models/gemini-2.5-flash');
    $stmt = $pdo->prepare('UPDATE compass_settings SET model_name = :model WHERE id = 1');
    $stmt->execute(['model' => $model]);
    header('Location: index.php?saved=1');
    exit;
}

$model = $pdo->query('SELECT model_name FROM compass_settings WHERE id = 1')->fetchColumn();
$counts = $pdo->query('SELECT model_name, COUNT(*) AS cnt FROM compass_usage_logs GROUP BY model_name ORDER BY cnt DESC')->fetchAll(PDO::FETCH_ASSOC);
$total = $pdo->query('SELECT COUNT(*) FROM compass_usage_logs')->fetchColumn();
?>
<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Compass Admin</title><link rel="stylesheet" href="../compass.css"></head><body class="view-mobile"><main><header><div class="logo-mark"><div class="logo-text"><h1>Compass Admin</h1><p>モデル設定・使用状況</p></div></div></header><div class="page-wrap"><div class="glass-card section-gap"><div class="card-label">モデル設定</div><?php if(isset($_GET['saved'])): ?><p class="hint">保存しました。</p><?php endif; ?><form method="post"><input name="model_name" value="<?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;"><div class="btn-submit-wrap section-gap"><button class="btn-submit" type="submit"><span>保存</span></button></div></form></div><div class="glass-card section-gap"><div class="card-label">使用回数</div><p class="hint">総リクエスト数: <?= (int)$total ?></p><ul><?php foreach($counts as $row): ?><li><?= htmlspecialchars($row['model_name'], ENT_QUOTES, 'UTF-8') ?>: <?= (int)$row['cnt'] ?></li><?php endforeach; ?></ul></div></div></main></body></html>
