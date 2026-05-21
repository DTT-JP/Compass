<?php
$envPath = dirname(__DIR__, 3) . '/env/compass.php';
if (!is_file($envPath)) { http_response_code(500); echo 'env.php が見つかりません'; exit; }
require_once $envPath;

function db(): PDO {
    global $Compass_DB_Host, $Compass_DB_Name, $Compass_DB_User, $Compass_DB_Pass;
    return new PDO("mysql:host={$Compass_DB_Host};dbname={$Compass_DB_Name};charset=utf8mb4", $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fetchGeminiModels(string $apiKey): array {
    if ($apiKey === '') return [];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) return [];
    $json = json_decode($resp, true);
    if (!is_array($json) || !isset($json['models']) || !is_array($json['models'])) return [];
    $models = [];
    foreach ($json['models'] as $m) { $n = (string)($m['name'] ?? ''); if (str_starts_with($n, 'models/')) $models[] = $n; }
    $models = array_values(array_unique($models)); sort($models, SORT_NATURAL); return $models;
}

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_settings (id TINYINT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_usage_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_error_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NULL, message TEXT NOT NULL, detail TEXT NULL, request_body MEDIUMTEXT NULL, response_body MEDIUMTEXT NULL, http_status INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_consultations (id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(100) NULL, consultation_type VARCHAR(20) NOT NULL, input_text MEDIUMTEXT NOT NULL, extra_text MEDIUMTEXT NULL, prompt_text MEDIUMTEXT NULL, response_json MEDIUMTEXT NULL, pulse_rate INT NULL, level_badge VARCHAR(255) NULL, shared TINYINT(1) NOT NULL DEFAULT 0, share_token VARCHAR(64) NULL, shared_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_share_token (share_token), INDEX idx_device_id (device_id), INDEX idx_created_at (created_at))");
$pdo->exec("INSERT INTO compass_settings (id, model_name) VALUES (1, 'models/gemini-2.5-flash') ON DUPLICATE KEY UPDATE id=id");

// AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $action = (string)($input['action'] ?? '');

    if ($action === 'delete_consultation') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'invalid id']); exit; }
        $stmt = $pdo->prepare('SELECT shared FROM compass_consultations WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'not found']); exit; }
        if ((int)$row['shared']) { echo json_encode(['error' => 'shared']); exit; }
        $pdo->prepare('DELETE FROM compass_consultations WHERE id=:id')->execute(['id' => $id]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'disable_share') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'invalid id']); exit; }
        $pdo->prepare('UPDATE compass_consultations SET shared=0, share_token=NULL, shared_at=NULL WHERE id=:id')->execute(['id' => $id]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'delete_error') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'invalid id']); exit; }
        $pdo->prepare('DELETE FROM compass_error_logs WHERE id=:id')->execute(['id' => $id]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['error' => 'unknown action']); exit;
}

$currentModel = (string)($pdo->query('SELECT model_name FROM compass_settings WHERE id=1')->fetchColumn() ?: 'models/gemini-2.5-flash');
$availableModels = fetchGeminiModels((string)$Gemini_API_Key);
if (!in_array($currentModel, $availableModels, true)) { $availableModels[] = $currentModel; sort($availableModels, SORT_NATURAL); }

$page = $_GET['page'] ?? 'api';
if (!in_array($page, ['api','history','shared','errors'], true)) $page = 'api';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    $action = $_POST['admin_action'] ?? '';
    if ($action === 'save_model') {
        $model = trim((string)($_POST['model_name'] ?? ''));
        if ($model !== '' && in_array($model, $availableModels, true)) {
            $stmt = $pdo->prepare('UPDATE compass_settings SET model_name=:model WHERE id=1');
            $stmt->execute(['model' => $model]);
            header('Location: index.php?page=api&saved=1'); exit;
        }
        header('Location: index.php?page=api&saved=0'); exit;
    }
}

$now = new DateTimeImmutable('now');
$today = $now->format('Y-m-d'); $startWeek = $now->modify('monday this week')->format('Y-m-d'); $startMonth = $now->format('Y-m-01');
$total = (int)$pdo->query('SELECT COUNT(*) FROM compass_usage_logs')->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at)=:d'); $stmt->execute(['d'=>$today]); $todayCount = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at)>=:d'); $stmt->execute(['d'=>$startWeek]); $weekCount = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE DATE(created_at)>=:d'); $stmt->execute(['d'=>$startMonth]); $monthCount = (int)$stmt->fetchColumn();

$ym = $_GET['ym'] ?? $now->format('Y-m'); if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) $ym = $now->format('Y-m');
$monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01') ?: $now->modify('first day of this month');
$prevYm = $monthDate->modify('-1 month')->format('Y-m'); $nextYm = $monthDate->modify('+1 month')->format('Y-m');
$stmt = $pdo->prepare('SELECT DATE(created_at) day, COUNT(*) cnt FROM compass_usage_logs WHERE DATE_FORMAT(created_at, "%Y-%m")=:ym GROUP BY DATE(created_at)');
$stmt->execute(['ym' => $monthDate->format('Y-m')]); $dailyMap=[]; $maxDaily=0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $c=(int)$r['cnt']; $dailyMap[$r['day']]=$c; if($c>$maxDaily)$maxDaily=$c; }
$year = (int)$monthDate->format('Y');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE YEAR(created_at)=:y'); $stmt->execute(['y'=>$year]); $yearTotal=(int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT MONTH(created_at) m, COUNT(*) cnt FROM compass_usage_logs WHERE YEAR(created_at)=:y GROUP BY MONTH(created_at)'); $stmt->execute(['y'=>$year]);
$monthlyMap = array_fill(1,12,0); $maxMonth=0; foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$c=(int)$r['cnt'];$monthlyMap[(int)$r['m']]=$c;if($c>$maxMonth)$maxMonth=$c;}

$listPage = max(1, (int)($_GET['p'] ?? 1)); $perPage = 30; $offset = ($listPage - 1) * $perPage;
$historyTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations')->fetchColumn();
$sharedTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations WHERE shared=1')->fetchColumn();
$errorTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_error_logs')->fetchColumn();
$historyRows = []; $sharedRows = []; $errorRows = [];
if ($page === 'history') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,extra_text,response_json,shared,share_token,created_at FROM compass_consultations ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $historyRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'shared') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,extra_text,response_json,share_token,shared,created_at,shared_at FROM compass_consultations WHERE shared=1 ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $sharedRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'errors') { $stmt=$pdo->prepare('SELECT id,model_name,message,detail,http_status,request_body,response_body,created_at FROM compass_error_logs ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $errorRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }

$detailId = max(0,(int)($_GET['id'] ?? 0)); $detailRow = null; $detailJson=[];
if ($detailId > 0 && in_array($page, ['history','shared','errors'], true)) {
    if ($page === 'errors') {
        $stmt = $pdo->prepare('SELECT * FROM compass_error_logs WHERE id=:id');
    } elseif ($page === 'shared') {
        $stmt = $pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id AND shared=1');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id');
    }
    $stmt->execute(['id'=>$detailId]); $detailRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailRow && $page !== 'errors') {
        $detailJson = json_decode((string)($detailRow['response_json'] ?? ''), true) ?: [];
    }
}

$firstWeekday=(int)$monthDate->format('w'); $daysInMonth=(int)$monthDate->format('t');
?>
<!doctype html><html lang="ja"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Compass Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="compass-admin.css">
<style>
/* ── Admin overrides ── */
#main-header { max-width: 100%; padding: 10px 24px 10px; }
body.admin-body main { padding-top: var(--header-h) !important; }

/* Admin layout */
.admin-outer {
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - var(--header-h));
}

/* Mobile: bottom nav */
.admin-nav-mobile {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  z-index: 40;
  display: flex;
  padding: 8px 12px calc(8px + var(--safe-bottom));
  gap: 6px;
  background: rgba(252,249,251,0.95);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(255,255,255,0.6);
  box-shadow: 0 -4px 20px rgba(176,136,249,0.1);
}
.admin-nav-mobile .seg-btn {
  flex-direction: column;
  gap: 3px;
  font-size: 10px;
  padding: 6px 4px;
}
.admin-nav-mobile .seg-btn svg { width: 18px; height: 18px; }

/* PC: left sidebar nav */
.admin-nav-desktop {
  display: none;
  flex-direction: column;
  gap: 6px;
  width: 200px;
  flex-shrink: 0;
  align-self: flex-start;
  position: sticky;
  top: 20px;
}
.admin-nav-desktop .seg-btn {
  justify-content: flex-start;
  padding: 12px 16px;
  border-radius: 16px;
  font-size: 13px;
  gap: 10px;
  text-align: left;
}
.admin-nav-desktop .seg-btn.active {
  background: var(--surface3);
  color: var(--accent);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.admin-content-wrap {
  flex: 1;
  padding: 16px 16px calc(80px + var(--safe-bottom));
}

/* PC layout */
@media (min-width: 900px) {
  .admin-nav-mobile { display: none; }
  .admin-nav-desktop { display: flex; }
  .admin-outer {
    flex-direction: row;
    gap: 24px;
    padding: 20px 32px 40px;
    align-items: flex-start;
  }
  .admin-content-wrap {
    flex: 1;
    padding: 0;
    min-width: 0;
  }
}

/* Metrics */
.metric-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
.metric { background: rgba(255,255,255,.6); border: 2px solid #fff; border-radius: 16px; padding: 14px; box-shadow: var(--shadow-sm); }
.metric .n { font-size: 28px; font-weight: 700; color: var(--accent); }
.metric .label { font-size: 12px; color: var(--text2); font-weight: 600; margin-bottom: 4px; }
@media (max-width: 600px) { .metric-grid { grid-template-columns: repeat(2,1fr); } }

/* Calendar */
.calendar { display: grid; grid-template-columns: repeat(7,1fr); gap: 6px; }
.cal-day { background: rgba(255,255,255,.55); border: 2px solid #fff; border-radius: 12px; padding: 6px; min-height: 60px; font-size: 11px; }
.cal-day strong { font-size: 13px; display: block; margin-top: 2px; }

/* Month grid */
.month-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
.month-cell { border-radius: 12px; padding: 10px; }
.month-cell .mc-num { font-size: 20px; font-weight: 700; }
.month-cell .mc-label { font-size: 11px; font-weight: 600; }
@media (max-width: 600px) { .month-grid { grid-template-columns: repeat(3,1fr); } }

/* Pager */
.pager { display: flex; align-items: center; gap: 10px; }
.pager a, .pager span { font-size: 13px; font-weight: 700; color: var(--accent3); text-decoration: none; padding: 4px 10px; background: rgba(255,255,255,0.7); border-radius: 10px; border: 1px solid var(--border2); }
.pager span { color: var(--text2); }

/* List rows */
.admin-row {
  padding: 14px 18px;
  border-bottom: 1px solid rgba(255,255,255,0.5);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.admin-row:last-child { border-bottom: none; }
.admin-row-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.admin-row-id { font-size: 12px; font-weight: 700; color: var(--text3); }
.admin-row-date { font-size: 11px; color: var(--text3); font-weight: 600; }
.admin-row-text { font-size: 13px; color: var(--text2); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
.admin-row-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

/* Action buttons */
.btn-admin {
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  padding: 6px 12px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.btn-admin:active { transform: scale(0.94); }
.btn-admin-detail { background: #f3f0ff; color: var(--accent3); border: 1px solid #d8b4fe; }
.btn-admin-detail:hover { background: #e9e4ff; }
.btn-admin-share-link { background: #e0f2fe; color: var(--accent2); border: 1px solid #bae6fd; }
.btn-admin-share-link:hover { background: #bae6fd; }
.btn-admin-disable { background: #fff7ed; color: var(--warn); border: 1px solid #fed7aa; }
.btn-admin-disable:hover { background: #fed7aa; }
.btn-admin-delete { background: #fff0f3; color: var(--accent); border: 1px solid #ffb3c6; }
.btn-admin-delete:hover { background: #ffd6df; }

/* Shared badge */
.shared-badge-on { font-size: 11px; font-weight: 700; background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 999px; }
.shared-badge-off { font-size: 11px; font-weight: 700; background: #f1f5f9; color: var(--text3); padding: 2px 8px; border-radius: 999px; }

/* ── 詳細ページ 2カラムレイアウト ── */
.detail-layout {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  align-items: start;
}
@media (min-width: 900px) {
  .detail-layout {
    grid-template-columns: 1fr 1fr;
    gap: 24px;
  }
}

/* 詳細左カラム: 独立スクロール (PC) */
@media (min-width: 900px) {
  .detail-col-left {
    position: sticky;
    top: 16px;
    max-height: calc(100vh - var(--header-h) - 32px);
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(176,136,249,0.3) transparent;
  }
  .detail-col-left::-webkit-scrollbar { width: 4px; }
  .detail-col-left::-webkit-scrollbar-track { background: transparent; }
  .detail-col-left::-webkit-scrollbar-thumb { background: rgba(176,136,249,0.3); border-radius: 2px; }

  .detail-col-right {
    max-height: calc(100vh - var(--header-h) - 32px);
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(176,136,249,0.3) transparent;
  }
  .detail-col-right::-webkit-scrollbar { width: 4px; }
  .detail-col-right::-webkit-scrollbar-track { background: transparent; }
  .detail-col-right::-webkit-scrollbar-thumb { background: rgba(176,136,249,0.3); border-radius: 2px; }
}

/* Admin detail actions bar */
.detail-actions-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 16px;
  padding: 14px 18px;
  background: rgba(255,255,255,0.6);
  border: 2px solid #fff;
  border-radius: 20px;
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
}

/* Detail back btn */
.detail-back-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--accent3);
  background: #f3f0ff;
  border: 1px solid #d8b4fe;
  border-radius: 12px;
  padding: 8px 14px;
  cursor: pointer;
  text-decoration: none;
  margin-bottom: 16px;
  transition: all 0.2s;
}
.detail-back-btn:hover { background: #e9e4ff; }

/* ── JSON エリア ── */
.json-section-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text2);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;
  border-bottom: 2px solid rgba(255,255,255,0.7);
}

/* JSON 2カラム */
.json-cols {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
@media (min-width: 720px) {
  .json-cols {
    grid-template-columns: 1fr 1fr;
  }
}

.json-block {
  background: rgba(255,255,255,0.8);
  border: 2px solid #fff;
  border-radius: 16px;
  padding: 16px;
  overflow: hidden;
}
.json-block-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--text2);
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.json-preview {
  font-size: 11px;
  font-family: 'Courier New', monospace;
  color: var(--text2);
  white-space: pre;
  overflow: hidden;
  max-height: 80px;
  transition: max-height 0.3s ease;
  line-height: 1.5;
}
.json-preview.expanded { max-height: 1200px; overflow: auto; }
.json-actions-row { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.btn-json-toggle { font-size: 12px; font-weight: 700; font-family: var(--font); padding: 6px 14px; background: #f3f0ff; color: var(--accent3); border: 1px solid #d8b4fe; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
.btn-json-toggle:hover { background: #e9e4ff; }
.btn-json-copy { font-size: 12px; font-weight: 700; font-family: var(--font); padding: 6px 14px; background: #fff0f3; color: var(--accent); border: 1px solid #ffb3c6; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
.btn-json-copy:hover { background: #ffd6df; }
.btn-json-dl { font-size: 12px; font-weight: 700; font-family: var(--font); padding: 6px 14px; background: #e0f2fe; color: var(--accent2); border: 1px solid #bae6fd; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
.btn-json-dl:hover { background: #bae6fd; }

/* Error detail section */
.detail-section { margin-bottom: 20px; }
.detail-section-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text2);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;
  border-bottom: 2px solid rgba(255,255,255,0.7);
}

/* Model select */
select.model-select {
  width: 100%;
  padding: 14px 16px;
  border-radius: 16px;
  border: 2px solid #eee8ec;
  background: rgba(255,255,255,0.9);
  font-family: var(--font);
  font-size: 14px;
  color: var(--text);
  appearance: none;
  cursor: pointer;
  margin-bottom: 12px;
}
select.model-select:focus { border-color: #ffb3c6; outline: none; box-shadow: 0 0 0 4px rgba(255,107,139,0.1); }

.saved-msg { font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: 12px; margin-bottom: 12px; }
.saved-msg.ok { background: #dcfce7; color: #16a34a; }
.saved-msg.ng { background: #fff0f3; color: var(--accent); }

/* score-fill transition for admin */
#admin-res-bar { transition: width 1.2s cubic-bezier(0.34,1.56,0.64,1); }
</style>
</head>
<body class="view-mobile admin-body">
<main>
<header id="main-header">
  <div class="logo-mark">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><path d="M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M16.3 7.7l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg>
    </div>
    <div class="logo-text"><h1>Compass Admin</h1><p>運用ダッシュボード</p></div>
  </div>
  <div class="header-actions">
    <a href="../index.php" class="btn-icon" style="padding:0 12px;font-size:11px;font-weight:700;color:var(--text2);text-decoration:none;gap:5px;width:auto;">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      トップへ
    </a>
  </div>
</header>

<div class="admin-outer">

  <!-- Desktop sidebar nav -->
  <nav class="admin-nav-desktop">
    <?php
    $navItems = [
      'api'     => ['label'=>'API・設定', 'icon'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>'],
      'history' => ['label'=>'履歴',     'icon'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
      'shared'  => ['label'=>'共有',     'icon'=>'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>'],
      'errors'  => ['label'=>'エラー',   'icon'=>'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
    ];
    foreach ($navItems as $key => $item):
    ?>
    <a class="seg-btn <?= $page===$key?'active':'' ?>" href="index.php?page=<?= h($key) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
      <?= h($item['label']) ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Content -->
  <div class="admin-content-wrap">

    <?php if ($page === 'api'): ?>
    <!-- ── API設定ページ ── -->
    <div class="glass-card section-gap">
      <div class="card-label">モデル設定</div>
      <?php if (isset($_GET['saved'])): ?>
        <div class="saved-msg <?= $_GET['saved']==='1'?'ok':'ng' ?>"><?= $_GET['saved']==='1'?'保存しました！':'保存に失敗しました' ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="admin_action" value="save_model">
        <select name="model_name" class="model-select">
          <?php foreach ($availableModels as $m): ?>
          <option value="<?= h($m) ?>" <?= $m===$currentModel?'selected':'' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn-submit" type="submit"><span>保存</span></button>
      </form>
    </div>

    <div class="glass-card section-gap">
      <div class="card-label">API利用サマリー</div>
      <div class="metric-grid">
        <div class="metric"><div class="label">今日</div><div class="n"><?= $todayCount ?></div></div>
        <div class="metric"><div class="label">今週</div><div class="n"><?= $weekCount ?></div></div>
        <div class="metric"><div class="label">今月</div><div class="n"><?= $monthCount ?></div></div>
        <div class="metric"><div class="label">総計</div><div class="n"><?= $total ?></div></div>
      </div>
    </div>

    <div class="glass-card section-gap">
      <div class="card-label" style="justify-content:space-between">
        <span>月間カレンダー（<?= h($monthDate->format('Y年n月')) ?>）</span>
        <div class="pager">
          <a href="index.php?page=api&ym=<?= h($prevYm) ?>">← 先月</a>
          <a href="index.php?page=api&ym=<?= h($nextYm) ?>">来月 →</a>
        </div>
      </div>
      <div class="calendar">
        <?php foreach(['日','月','火','水','木','金','土'] as $w): ?>
        <div class="cal-day" style="background:rgba(255,255,255,0.3);min-height:auto;padding:4px;"><strong><?= $w ?></strong></div>
        <?php endforeach; ?>
        <?php for($i=0;$i<$firstWeekday;$i++): ?><div class="cal-day" style="background:transparent;border-color:transparent"></div><?php endfor; ?>
        <?php for($d=1;$d<=$daysInMonth;$d++):
          $dk=$monthDate->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT);
          $cnt=(int)($dailyMap[$dk] ?? 0);
          $ratio=$maxDaily>0?$cnt/$maxDaily:0;
          $alpha=0.08+($ratio*0.78);
        ?>
        <div class="cal-day" style="background:rgba(110,181,255,<?= number_format($alpha,2,'.','') ?>)">
          <div style="font-size:10px"><?= $d ?>日</div>
          <strong><?= $cnt ?></strong>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="glass-card section-gap">
      <div class="card-label"><?= $year ?>年 月別利用回数</div>
      <div class="month-grid">
        <?php for($m=1;$m<=12;$m++):
          $cnt=$monthlyMap[$m];
          $r=$maxMonth>0?$cnt/$maxMonth:0;
          $alpha=0.18+($r*0.72);
          $textColor = $alpha > 0.55 ? '#fff' : 'var(--text)';
        ?>
        <div class="month-cell" style="background:rgba(167,139,250,<?= number_format($alpha,2,'.','') ?>)">
          <div class="mc-label" style="color:<?= $textColor ?>;opacity:0.85"><?= $m ?>月</div>
          <div class="mc-num" style="color:<?= $textColor ?>"><?= $cnt ?></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <?php elseif ($page === 'history' || $page === 'shared'): ?>
    <!-- ── 履歴・共有ページ ── -->

    <?php if ($detailRow && $page !== 'errors'): ?>
    <!-- ── 詳細ビュー ── -->
    <?php
      $rJson   = $detailJson;
      $rowId   = (int)$detailRow['id'];
      $isSharedRow = (int)($detailRow['shared'] ?? 0);

      $inputData = [
        'id'                => $rowId,
        'consultation_type' => $detailRow['consultation_type'] ?? '',
        'device_id'         => $detailRow['device_id'] ?? '',
        'input_text'        => $detailRow['input_text'] ?? '',
        'extra_text'        => $detailRow['extra_text'] ?? '',
        'created_at'        => $detailRow['created_at'] ?? '',
      ];
      $snapFields = ['partner','rel','meetVal','replyLen','mood','scene','duration','tension','attitude','isLine','type','date'];
      foreach ($snapFields as $sf) {
        if (array_key_exists($sf, $rJson)) $inputData[$sf] = $rJson[$sf];
      }
      $inputJsonStr = json_encode($inputData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);

      $outputFields = ['pulseRate','levelBadge','psychology','advice','radar','radarInterpretation','matrix','matrixInterpretation','lang','langInterpretation','approaches'];
      $outputData = [];
      foreach ($outputFields as $of) {
        if (array_key_exists($of, $rJson)) $outputData[$of] = $rJson[$of];
      }
      $outputJsonStr = json_encode($outputData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);

      $jsData = array_merge($inputData, $rJson);
      $jsDataJson = json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>

    <!-- 戻るボタン -->
    <a href="index.php?page=<?= h($page) ?>&p=<?= $listPage ?>" class="detail-back-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;stroke:currentColor"><polyline points="15 18 9 12 15 6"/></svg>
      一覧へ戻る
    </a>

    <!-- 2カラムレイアウト -->
    <div class="detail-layout">

      <!-- 左カラム: adminボタン + 入力サマリー -->
      <div class="detail-col-left">
        <!-- adminボタンバー -->
        <div class="detail-actions-bar" id="detail-actions-bar-<?= $rowId ?>">
          <span style="font-size:13px;font-weight:700;color:var(--text2)">#<?= $rowId ?></span>
          <?php if ($isSharedRow): ?>
            <span class="shared-badge-on">共有中</span>
            <a href="../index.php?share=<?= h((string)$detailRow['share_token']) ?>" target="_blank" class="btn-admin btn-admin-share-link">共有URLを開く</a>
            <button class="btn-admin btn-admin-disable" onclick="adminDisableShare(<?= $rowId ?>, this)">共有を無効化</button>
          <?php else: ?>
            <span class="shared-badge-off">非共有</span>
          <?php endif; ?>
          <button class="btn-admin btn-admin-delete" onclick="adminDelete(<?= $rowId ?>, <?= $isSharedRow ?>, this)">削除</button>
        </div>

        <!-- 入力サマリー（JS で描画） -->
        <div id="admin-detail-left"></div>
      </div>

      <!-- 右カラム: スコア + レポート -->
      <div class="detail-col-right">
        <div id="admin-detail-right"></div>
      </div>

    </div><!-- /detail-layout -->

    <!-- JSON エリア（プレビューの下、全幅） -->
    <div class="glass-card section-gap" style="margin-top:8px">
      <div class="json-section-title">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;stroke:var(--text3)">
          <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
        </svg>
        生JSONデータ
      </div>
      <div class="json-cols">
        <!-- 入力JSON -->
        <div class="json-block">
          <div class="json-block-title">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;stroke:var(--accent2)">
              <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
            入力データ
          </div>
          <div class="json-preview" id="input-json-pre"><?= h($inputJsonStr) ?></div>
          <div class="json-actions-row">
            <button class="btn-json-toggle" onclick="toggleJson('input-json-pre', this)">続きを表示</button>
            <button class="btn-json-copy" onclick="copyJson('input-json-pre', this)">コピー</button>
            <button class="btn-json-dl" onclick="dlJson('input-json-pre', 'input-<?= $rowId ?>.json')">DL</button>
          </div>
        </div>
        <!-- 出力JSON -->
        <div class="json-block">
          <div class="json-block-title">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;stroke:var(--accent)">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
            出力データ（AI分析結果）
          </div>
          <div class="json-preview" id="output-json-pre"><?= h($outputJsonStr) ?></div>
          <div class="json-actions-row">
            <button class="btn-json-toggle" onclick="toggleJson('output-json-pre', this)">続きを表示</button>
            <button class="btn-json-copy" onclick="copyJson('output-json-pre', this)">コピー</button>
            <button class="btn-json-dl" onclick="dlJson('output-json-pre', 'output-<?= $rowId ?>.json')">DL</button>
          </div>
        </div>
      </div>
    </div>

    <!-- JS初期化 -->
    <script>
    window.__adminDetailData = <?= $jsDataJson ?>;
    </script>

    <?php else: ?>
    <!-- ── 一覧ビュー ── -->
    <div class="glass-card section-gap" style="padding:0;overflow:hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:2px solid rgba(255,255,255,0.7)">
        <span style="font-size:14px;font-weight:700"><?= $page==='shared'?'共有一覧':'相談履歴一覧' ?></span>
        <div class="pager">
          <?php $totalRows = $page==='history'?$historyTotal:$sharedTotal; $maxPage=max(1,(int)ceil($totalRows/$perPage)); ?>
          <?php if($listPage>1): ?><a href="index.php?page=<?= h($page) ?>&p=<?= $listPage-1 ?>">← 前へ</a><?php endif; ?>
          <span><?= $listPage ?>/<?= $maxPage ?></span>
          <?php if($listPage<$maxPage): ?><a href="index.php?page=<?= h($page) ?>&p=<?= $listPage+1 ?>">次へ →</a><?php endif; ?>
        </div>
      </div>
      <?php
      $rows = $page==='history' ? $historyRows : $sharedRows;
      foreach ($rows as $r):
        $rid = (int)$r['id'];
        $rShared = (int)($r['shared'] ?? 0);
      ?>
      <div class="admin-row" id="row-<?= $rid ?>">
        <div class="admin-row-top">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="admin-row-id">#<?= $rid ?></span>
            <span class="admin-row-date"><?= h((string)$r['created_at']) ?></span>
            <span class="<?= $rShared?'shared-badge-on':'shared-badge-off' ?>"><?= $rShared?'共有中':'非共有' ?></span>
          </div>
          <div class="admin-row-actions">
            <a href="index.php?page=<?= h($page) ?>&p=<?= $listPage ?>&id=<?= $rid ?>" class="btn-admin btn-admin-detail">詳細</a>
            <?php if ($rShared): ?>
            <a href="../index.php?share=<?= h((string)$r['share_token']) ?>" target="_blank" class="btn-admin btn-admin-share-link">URL</a>
            <button class="btn-admin btn-admin-disable" onclick="adminDisableShare(<?= $rid ?>, this)">無効化</button>
            <?php endif; ?>
            <button class="btn-admin btn-admin-delete" onclick="adminDelete(<?= $rid ?>, <?= $rShared ?>, this)">削除</button>
          </div>
        </div>
        <div class="admin-row-text">「<?= h(mb_strimwidth((string)($r['input_text']??''), 0, 100, '...')) ?>」</div>
        <?php if (!empty($r['extra_text'])): ?>
        <div class="admin-row-text" style="font-size:11px">＋ <?= h(mb_strimwidth((string)$r['extra_text'], 0, 60, '...')) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($page === 'errors'): ?>
    <!-- ── エラーページ ── -->

    <?php if ($detailRow): ?>
    <?php $eid = (int)$detailRow['id']; ?>
    <a href="index.php?page=errors&p=<?= $listPage ?>" class="detail-back-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;stroke:currentColor"><polyline points="15 18 9 12 15 6"/></svg>
      一覧へ戻る
    </a>
    <div class="detail-actions-bar">
      <span style="font-size:13px;font-weight:700;color:var(--text2)">#<?= $eid ?></span>
      <button class="btn-admin btn-admin-delete" onclick="adminDeleteError(<?= $eid ?>, this)">削除</button>
    </div>
    <div class="glass-card section-gap detail-section">
      <div class="detail-section-title">エラー詳細</div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach(['message'=>'メッセージ','detail'=>'詳細','http_status'=>'HTTPステータス','model_name'=>'モデル','created_at'=>'日時'] as $fk=>$fl): ?>
        <div class="summary-item-row"><span class="summary-item-label"><?= $fl ?></span><span class="summary-item-val"><?= h((string)($detailRow[$fk]??'')) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="json-cols">
      <?php if (!empty($detailRow['request_body'])): ?>
      <div class="json-block">
        <div class="json-block-title">リクエストBody</div>
        <div class="json-preview" id="req-json-pre"><?= h((string)$detailRow['request_body']) ?></div>
        <div class="json-actions-row">
          <button class="btn-json-toggle" onclick="toggleJson('req-json-pre', this)">続きを表示</button>
          <button class="btn-json-copy" onclick="copyJson('req-json-pre', this)">コピー</button>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($detailRow['response_body'])): ?>
      <div class="json-block">
        <div class="json-block-title">レスポンスBody</div>
        <div class="json-preview" id="res-json-pre"><?= h((string)$detailRow['response_body']) ?></div>
        <div class="json-actions-row">
          <button class="btn-json-toggle" onclick="toggleJson('res-json-pre', this)">続きを表示</button>
          <button class="btn-json-copy" onclick="copyJson('res-json-pre', this)">コピー</button>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="glass-card section-gap" style="padding:0;overflow:hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:2px solid rgba(255,255,255,0.7)">
        <span style="font-size:14px;font-weight:700">エラーログ一覧</span>
        <div class="pager">
          <?php $maxPage=max(1,(int)ceil($errorTotal/$perPage)); ?>
          <?php if($listPage>1): ?><a href="index.php?page=errors&p=<?= $listPage-1 ?>">← 前へ</a><?php endif; ?>
          <span><?= $listPage ?>/<?= $maxPage ?></span>
          <?php if($listPage<$maxPage): ?><a href="index.php?page=errors&p=<?= $listPage+1 ?>">次へ →</a><?php endif; ?>
        </div>
      </div>
      <?php foreach ($errorRows as $r): $eid=(int)$r['id']; ?>
      <div class="admin-row" id="err-row-<?= $eid ?>">
        <div class="admin-row-top">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="admin-row-id">#<?= $eid ?></span>
            <span class="admin-row-date"><?= h((string)$r['created_at']) ?></span>
            <?php if ($r['http_status']): ?><span style="font-size:11px;font-weight:700;background:#fff0f3;color:var(--accent);padding:2px 8px;border-radius:999px"><?= (int)$r['http_status'] ?></span><?php endif; ?>
          </div>
          <div class="admin-row-actions">
            <a href="index.php?page=errors&p=<?= $listPage ?>&id=<?= $eid ?>" class="btn-admin btn-admin-detail">詳細</a>
            <button class="btn-admin btn-admin-delete" onclick="adminDeleteError(<?= $eid ?>, this)">削除</button>
          </div>
        </div>
        <div class="admin-row-text"><?= h(mb_strimwidth((string)($r['message']??''), 0, 100, '...')) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /admin-content-wrap -->
</div><!-- /admin-outer -->

<!-- Mobile bottom nav -->
<nav class="admin-nav-mobile segment-wrap" style="border-radius:0;max-width:100%">
  <?php foreach ($navItems as $key => $item): ?>
  <a class="seg-btn <?= $page===$key?'active':'' ?>" href="index.php?page=<?= h($key) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
    <?= h($item['label']) ?>
  </a>
  <?php endforeach; ?>
</nav>

</main>

<script src="compass-report-admin.js"></script>
<script src="compass-admin.js"></script>
<script>
// 詳細ページ初期化
if (typeof window.__adminDetailData !== 'undefined') {
  initAdminDetail(window.__adminDetailData);
}
</script>
</body>
</html>