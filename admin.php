<?php
$envPath = dirname(__DIR__, 2) . '/env/compass.php';
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
$pdo->exec("CREATE TABLE IF NOT EXISTS compass_consultations (id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(100) NULL, consultation_type VARCHAR(20) NOT NULL, input_text MEDIUMTEXT NOT NULL, extra_text MEDIUMTEXT NULL, prompt_text MEDIUMTEXT NULL, response_json MEDIUMTEXT NULL, pulse_rate INT NULL, level_badge VARCHAR(255) NULL, shared TINYINT(1) NOT NULL DEFAULT 0, share_token VARCHAR(64) NULL, shared_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_share_token (share_token), INDEX idx_created_at (created_at))");
$pdo->exec("INSERT INTO compass_settings (id, model_name) VALUES (1, 'models/gemini-2.5-flash') ON DUPLICATE KEY UPDATE id=id");

$currentModel = (string)($pdo->query('SELECT model_name FROM compass_settings WHERE id=1')->fetchColumn() ?: 'models/gemini-2.5-flash');
$availableModels = fetchGeminiModels((string)$Gemini_API_Key);
if (!in_array($currentModel, $availableModels, true)) { $availableModels[] = $currentModel; sort($availableModels, SORT_NATURAL); }

$page = $_GET['page'] ?? 'api';
if (!in_array($page, ['api','history','shared','errors'], true)) $page = 'api';
$listPage = max(1, (int)($_GET['p'] ?? 1));

/* ─── POST処理 ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['admin_action'] ?? '';

    // モデル保存
    if ($action === 'save_model') {
        $model = trim((string)($_POST['model_name'] ?? ''));
        if ($model !== '' && in_array($model, $availableModels, true)) {
            $stmt = $pdo->prepare('UPDATE compass_settings SET model_name=:model WHERE id=1');
            $stmt->execute(['model' => $model]);
            header('Location: admin.php?page=api&saved=1'); exit;
        }
        header('Location: admin.php?page=api&saved=0'); exit;
    }

    // 削除
    if ($action === 'delete_record') {
        $id = (int)($_POST['record_id'] ?? 0);
        $tbl = $_POST['record_table'] ?? '';
        $redirect = 'admin.php?page=' . h($page) . '&p=' . $listPage;
        if ($id > 0 && $tbl === 'compass_consultations') {
            // 共有中は削除不可
            $row = $pdo->prepare('SELECT shared FROM compass_consultations WHERE id=:id');
            $row->execute(['id' => $id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r && (int)$r['shared'] === 1) {
                header('Location: ' . $redirect . '&err=shared'); exit;
            }
            $pdo->prepare('DELETE FROM compass_consultations WHERE id=:id')->execute(['id' => $id]);
        } elseif ($id > 0 && $tbl === 'compass_error_logs') {
            $pdo->prepare('DELETE FROM compass_error_logs WHERE id=:id')->execute(['id' => $id]);
        }
        // 詳細から削除した場合は一覧へ
        header('Location: ' . $redirect); exit;
    }

    // 共有無効化
    if ($action === 'disable_share') {
        $id = (int)($_POST['record_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE compass_consultations SET shared=0, share_token=NULL, shared_at=NULL WHERE id=:id')->execute(['id' => $id]);
        }
        $detailId = (int)($_POST['detail_id'] ?? 0);
        if ($detailId > 0) {
            header('Location: admin.php?page=' . h($page) . '&p=' . $listPage . '&id=' . $detailId); exit;
        }
        header('Location: admin.php?page=' . h($page) . '&p=' . $listPage); exit;
    }
}

/* ─── 統計 ─── */
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

/* ─── 一覧データ ─── */
$perPage = 30; $offset = ($listPage - 1) * $perPage;
$historyTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations')->fetchColumn();
$sharedTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations WHERE shared=1')->fetchColumn();
$errorTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_error_logs')->fetchColumn();
$historyRows = []; $sharedRows = []; $errorRows = [];
if ($page === 'history') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,response_json,shared,created_at FROM compass_consultations ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $historyRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'shared') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,response_json,share_token,shared,created_at,shared_at FROM compass_consultations WHERE shared=1 ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $sharedRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'errors') { $stmt=$pdo->prepare('SELECT id,model_name,message,detail,http_status,created_at FROM compass_error_logs ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $errorRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }

/* ─── 詳細データ ─── */
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

/* ─── ヘルパー：入力データJSON生成 ─── */
function buildInputJson(array $row, array $json): array {
    $isLine = ($row['consultation_type'] ?? '') === 'line';
    $data = [
        'consultation_type' => $row['consultation_type'] ?? '',
        'input_text' => $row['input_text'] ?? '',
        'extra_text' => $row['extra_text'] ?? '',
    ];
    // snapshotから取れる入力パラメータを追加
    $snapshotKeys = ['partner','rel','meetVal','replyLen','mood','scene','duration','tension','attitude','date','type'];
    foreach ($snapshotKeys as $k) {
        if (isset($json[$k])) $data[$k] = $json[$k];
    }
    return $data;
}
function buildOutputJson(array $json): array {
    $keys = ['pulseRate','levelBadge','psychology','advice','radar','radarInterpretation','matrix','matrixInterpretation','lang','langInterpretation','approaches','usedModel','consultationId'];
    $out = [];
    foreach ($keys as $k) { if (isset($json[$k])) $out[$k] = $json[$k]; }
    return $out;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Compass Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="compass.css">
<style>
/* ── Admin固有スタイル ── */
:root {
  --nav-w: 200px;
  --admin-header-h: 68px;
  --mobile-nav-h: 56px;
}

/* ── Admin Header ── */
#admin-header {
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 50;
  height: var(--admin-header-h);
  padding: calc(var(--safe-top) + 10px) 20px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(252,249,251,0.92);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,0.6);
  box-shadow: 0 2px 12px rgba(176,136,249,0.06);
}

.admin-view-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 16px;
  height: 36px;
  background: var(--surface2);
  border: 2px solid var(--border);
  border-radius: 18px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--text2);
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1);
  box-shadow: var(--shadow-sm);
  white-space: nowrap;
}
.admin-view-btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
.admin-view-btn:active { transform: scale(0.93); }

/* ── PC左サイドナビ ── */
#admin-sidenav {
  display: none;
  position: fixed;
  top: var(--admin-header-h);
  left: 0;
  bottom: 0;
  width: var(--nav-w);
  background: rgba(252,249,251,0.92);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-right: 1px solid rgba(255,255,255,0.6);
  padding: 20px 12px;
  z-index: 40;
  display: flex;
  flex-direction: column;
  gap: 6px;
  overflow-y: auto;
}

.sidenav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 16px;
  font-size: 14px;
  font-weight: 600;
  font-family: var(--font);
  color: var(--text2);
  text-decoration: none;
  transition: all 0.2s;
  cursor: pointer;
  border: none;
  background: transparent;
  width: 100%;
  text-align: left;
}
.sidenav-item svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; flex-shrink: 0; }
.sidenav-item:hover { background: rgba(255,255,255,0.7); color: var(--text); }
.sidenav-item.active { background: #fff0f3; color: var(--accent); }
.sidenav-badge {
  margin-left: auto;
  font-size: 11px;
  font-weight: 700;
  background: rgba(0,0,0,0.06);
  color: var(--text3);
  padding: 2px 7px;
  border-radius: 999px;
}
.sidenav-item.active .sidenav-badge { background: rgba(255,107,139,0.12); color: var(--accent); }

/* ── モバイル下部ナビ ── */
#admin-bottomnav {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  z-index: 40;
  height: calc(var(--mobile-nav-h) + var(--safe-bottom));
  padding-bottom: var(--safe-bottom);
  background: rgba(252,249,251,0.95);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(255,255,255,0.6);
  box-shadow: 0 -4px 20px rgba(176,136,249,0.1);
  display: flex;
  align-items: stretch;
}
.bottomnav-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 8px 4px;
  font-size: 10px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--text3);
  text-decoration: none;
  transition: color 0.2s;
  cursor: pointer;
  border: none;
  background: transparent;
}
.bottomnav-item svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
.bottomnav-item.active { color: var(--accent); }

/* ── メインコンテンツ ── */
#admin-main {
  padding-top: var(--admin-header-h);
}

/* PC表示時 */
body.view-pc #admin-sidenav { display: flex; }
body.view-pc #admin-bottomnav { display: none !important; }
body.view-pc #admin-main {
  margin-left: var(--nav-w);
  padding: calc(var(--admin-header-h) + 16px) 32px 48px;
}

/* モバイル表示時 */
body.view-mobile #admin-sidenav { display: none !important; }
body.view-mobile #admin-bottomnav { display: flex; }
body.view-mobile #admin-main {
  margin-left: 0;
  padding: calc(var(--admin-header-h) + 12px) 16px calc(var(--mobile-nav-h) + var(--safe-bottom) + 24px);
}

/* ── 統計グリッド ── */
.metric-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
.metric {
  background: rgba(255,255,255,0.6);
  border: 2px solid #fff;
  border-radius: 16px;
  padding: 16px 12px;
  text-align: center;
}
.metric .label { font-size: 11px; color: var(--text3); font-weight: 700; margin-bottom: 6px; }
.metric .num { font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }
@media (max-width: 600px) { .metric-grid { grid-template-columns: repeat(2,1fr); } }

/* ── カレンダー ── */
.calendar { display: grid; grid-template-columns: repeat(7,1fr); gap: 6px; }
.cal-day {
  background: rgba(255,255,255,0.5);
  border: 2px solid #fff;
  border-radius: 12px;
  padding: 6px;
  min-height: 60px;
  font-size: 11px;
}
.cal-day.header { min-height: auto; padding: 4px 6px; font-weight: 700; color: var(--text2); background: transparent; border-color: transparent; }
.cal-day .cnt { font-size: 14px; font-weight: 700; color: var(--text); margin-top: 4px; }

/* ── 月別グリッド ── */
.month-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
.month-cell {
  border-radius: 12px;
  padding: 12px 10px;
  text-align: center;
}
.month-cell .m-label { font-size: 11px; font-weight: 700; color: #333; margin-bottom: 4px; }
.month-cell .m-cnt { font-size: 20px; font-weight: 700; color: #222; }
@media (max-width: 600px) { .month-grid { grid-template-columns: repeat(3,1fr); } }

/* ── ページャー ── */
.pager {
  display: flex;
  align-items: center;
  gap: 12px;
  justify-content: center;
  padding: 12px 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--text2);
}
.pager a {
  color: var(--accent3);
  text-decoration: none;
  font-weight: 700;
  padding: 6px 14px;
  border-radius: 12px;
  background: rgba(255,255,255,0.6);
  border: 2px solid #fff;
  transition: all 0.2s;
}
.pager a:hover { background: #f3f0ff; }

/* ── 一覧テーブル ── */
.admin-table-wrap {
  background: var(--surface);
  border: 2px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  backdrop-filter: blur(30px);
  -webkit-backdrop-filter: blur(30px);
  box-shadow: var(--shadow-md);
}
.admin-table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 2px solid rgba(255,255,255,0.7);
}
.admin-table-title {
  font-size: 14px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text);
}
.admin-table-title svg { width: 16px; height: 16px; stroke: var(--text2); fill: none; stroke-width: 2; }
.admin-list-item {
  padding: 14px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.5);
  display: flex;
  align-items: center;
  gap: 12px;
}
.admin-list-item:last-child { border-bottom: none; }
.admin-list-item-body { flex: 1; min-width: 0; }
.admin-list-item-id { font-size: 11px; color: var(--text3); font-weight: 700; margin-bottom: 4px; }
.admin-list-item-text { font-size: 13px; color: var(--text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
.admin-list-item-meta { font-size: 11px; color: var(--text3); margin-top: 3px; font-weight: 600; }
.admin-list-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

/* ── ボタン群（index.phpと同じUI） ── */
.btn-admin-detail {
  padding: 7px 14px;
  background: #f3f0ff;
  border: 2px solid #d8b4fe;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--accent3);
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn-admin-detail:hover { background: #e9e4ff; }
.btn-admin-detail:active { transform: scale(0.94); }

.btn-admin-delete {
  padding: 7px 14px;
  background: #fff0f3;
  border: 2px solid #ffb3c6;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--accent);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn-admin-delete:hover { background: #ffe0e8; }
.btn-admin-delete:active { transform: scale(0.94); }
.btn-admin-delete:disabled { opacity: 0.4; cursor: not-allowed; }

.btn-admin-share-off {
  padding: 7px 14px;
  background: rgba(255,255,255,0.6);
  border: 2px solid rgba(240,230,240,0.8);
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--warn);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn-admin-share-off:hover { background: #fff8f0; border-color: #ffd09e; }
.btn-admin-share-off:active { transform: scale(0.94); }

.btn-admin-share-link {
  padding: 7px 14px;
  background: #e0f2fe;
  border: 2px solid #bae6fd;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--accent2);
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn-admin-share-link:hover { background: #c9ebfd; }

.btn-json-toggle {
  padding: 6px 12px;
  background: rgba(255,255,255,0.7);
  border: 2px solid #fff;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  color: var(--text2);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
}
.btn-json-toggle:hover { background: #fff; }
.btn-json-copy, .btn-json-dl {
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 700;
  font-family: var(--font);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.2s;
  border: 2px solid #fff;
}
.btn-json-copy { background: rgba(255,255,255,0.7); color: var(--text2); }
.btn-json-copy:hover { background: #fff; }
.btn-json-dl { background: #f3f0ff; color: var(--accent3); border-color: #d8b4fe; }
.btn-json-dl:hover { background: #e9e4ff; }

/* ── 詳細ページ ── */
.detail-back-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--accent3);
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  margin-bottom: 16px;
  padding: 8px 16px;
  background: rgba(255,255,255,0.6);
  border: 2px solid #fff;
  border-radius: 14px;
  transition: all 0.2s;
}
.detail-back-link:hover { background: #f3f0ff; }
.detail-back-link svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2.5; }

.detail-actions-bar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 20px;
  align-items: center;
}

/* ── JSONブロック ── */
.json-block {
  background: var(--surface);
  border: 2px solid var(--border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  margin-bottom: 16px;
  backdrop-filter: blur(30px);
  -webkit-backdrop-filter: blur(30px);
  box-shadow: var(--shadow-sm);
}
.json-block-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 2px solid rgba(255,255,255,0.7);
  flex-wrap: wrap;
  gap: 8px;
}
.json-block-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 8px;
}
.json-block-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.json-preview {
  padding: 16px 18px;
  font-family: 'Courier New', Courier, monospace;
  font-size: 12px;
  color: var(--text2);
  line-height: 1.6;
  background: rgba(255,255,255,0.4);
}
.json-preview pre {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-all;
}
.json-preview.collapsed pre { max-height: 80px; overflow: hidden; }
.json-expand-btn {
  display: block;
  text-align: center;
  padding: 10px;
  font-size: 12px;
  font-weight: 700;
  color: var(--accent3);
  cursor: pointer;
  border-top: 1px solid rgba(255,255,255,0.6);
  background: rgba(255,255,255,0.3);
  transition: background 0.2s;
}
.json-expand-btn:hover { background: rgba(255,255,255,0.6); }

/* ── プレビューエリア ── */
.preview-wrap {
  background: var(--surface);
  border: 2px solid var(--border);
  border-radius: var(--radius-xl);
  overflow: hidden;
  margin-bottom: 16px;
  backdrop-filter: blur(30px);
  -webkit-backdrop-filter: blur(30px);
  box-shadow: var(--shadow-md);
}
.preview-wrap .preview-header {
  padding: 14px 18px;
  border-bottom: 2px solid rgba(255,255,255,0.7);
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 8px;
}
.preview-body { padding: 16px; }

/* ── エラーバナー ── */
.admin-error-banner {
  background: #fff0f3;
  border: 2px solid #ffb3c6;
  border-radius: 14px;
  padding: 12px 16px;
  font-size: 13px;
  color: var(--accent);
  font-weight: 600;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── 設定フォーム ── */
.settings-form { display: flex; flex-direction: column; gap: 12px; }

/* ── 共有バッジ ── */
.shared-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
}
.shared-badge.on { background: #e0f2fe; color: var(--accent2); }
.shared-badge.off { background: rgba(0,0,0,0.05); color: var(--text3); }
</style>
</head>
<body class="view-mobile">

<!-- ── Admin Header ── -->
<header id="admin-header">
  <div class="logo-mark">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"/>
        <path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
        <path d="M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M16.3 7.7l-2.1 2.1M7.7 16.3l-2.1 2.1"/>
      </svg>
    </div>
    <div class="logo-text">
      <h1>Compass Admin</h1>
      <p>運用ダッシュボード</p>
    </div>
  </div>
  <div class="header-actions">
    <button id="btn-view-toggle" class="admin-view-btn">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/></svg>
      PC表示
    </button>
  </div>
</header>

<!-- ── PC左サイドナビ ── -->
<nav id="admin-sidenav">
  <a class="sidenav-item <?= $page==='api'?'active':'' ?>" href="admin.php?page=api">
    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    API統計
  </a>
  <a class="sidenav-item <?= $page==='history'?'active':'' ?>" href="admin.php?page=history">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    履歴
    <span class="sidenav-badge"><?= $historyTotal ?></span>
  </a>
  <a class="sidenav-item <?= $page==='shared'?'active':'' ?>" href="admin.php?page=shared">
    <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
    共有中
    <span class="sidenav-badge"><?= $sharedTotal ?></span>
  </a>
  <a class="sidenav-item <?= $page==='errors'?'active':'' ?>" href="admin.php?page=errors">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    エラー
    <span class="sidenav-badge"><?= $errorTotal ?></span>
  </a>
</nav>

<!-- ── モバイル下部ナビ ── -->
<nav id="admin-bottomnav">
  <a class="bottomnav-item <?= $page==='api'?'active':'' ?>" href="admin.php?page=api">
    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    API
  </a>
  <a class="bottomnav-item <?= $page==='history'?'active':'' ?>" href="admin.php?page=history">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    履歴
  </a>
  <a class="bottomnav-item <?= $page==='shared'?'active':'' ?>" href="admin.php?page=shared">
    <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
    共有
  </a>
  <a class="bottomnav-item <?= $page==='errors'?'active':'' ?>" href="admin.php?page=errors">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    エラー
  </a>
</nav>

<!-- ── メインコンテンツ ── -->
<main id="admin-main">

<?php if (isset($_GET['err']) && $_GET['err'] === 'shared'): ?>
<div class="admin-error-banner">
  <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--accent);fill:none;stroke-width:2;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  共有中のデータは削除できません。先に共有を無効化してから削除してください。
</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div class="admin-error-banner" style="background:#dcfce7;border-color:#86efac;color:var(--green)">
  <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--green);fill:none;stroke-width:2.5;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
  <?= $_GET['saved']==='1' ? 'モデル設定を保存しました' : '保存に失敗しました' ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════
     API統計ページ
════════════════════════════════ -->
<?php if ($page === 'api'): ?>

<div class="glass-card section-gap">
  <div class="card-label">モデル設定</div>
  <form method="post" class="settings-form">
    <input type="hidden" name="admin_action" value="save_model">
    <select name="model_name">
      <?php foreach($availableModels as $m): ?>
      <option value="<?= h($m) ?>" <?= $m===$currentModel?'selected':'' ?>><?= h($m) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn-submit" type="submit">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      <span>保存</span>
    </button>
  </form>
</div>

<div class="glass-card section-gap">
  <div class="card-label">API利用サマリー</div>
  <div class="metric-grid">
    <div class="metric"><div class="label">今日</div><div class="num"><?= $todayCount ?></div></div>
    <div class="metric"><div class="label">今週</div><div class="num"><?= $weekCount ?></div></div>
    <div class="metric"><div class="label">今月</div><div class="num"><?= $monthCount ?></div></div>
    <div class="metric"><div class="label">総計</div><div class="num"><?= $total ?></div></div>
  </div>
</div>

<div class="glass-card section-gap">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div class="card-label" style="margin:0">月間カレンダー（<?= h($monthDate->format('Y年n月')) ?>）</div>
    <div class="pager" style="padding:0">
      <a href="admin.php?page=api&ym=<?= h($prevYm) ?>">← 先月</a>
      <a href="admin.php?page=api&ym=<?= h($nextYm) ?>">来月 →</a>
    </div>
  </div>
  <div class="calendar">
    <?php foreach(['日','月','火','水','木','金','土'] as $w): ?>
    <div class="cal-day header"><?= $w ?></div>
    <?php endforeach; ?>
    <?php for($i=0;$i<$firstWeekday;$i++): ?><div class="cal-day"></div><?php endfor; ?>
    <?php for($d=1;$d<=$daysInMonth;$d++):
      $dk=$monthDate->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT);
      $cnt=(int)($dailyMap[$dk] ?? 0);
      $ratio=$maxDaily>0?$cnt/$maxDaily:0;
      $alpha=0.06+($ratio*0.72);
    ?>
    <div class="cal-day" style="background:rgba(110,181,255,<?= number_format($alpha,2,'.','') ?>)">
      <div><?= $d ?>日</div>
      <div class="cnt"><?= $cnt ?></div>
    </div>
    <?php endfor; ?>
  </div>
</div>

<div class="glass-card section-gap">
  <div class="card-label"><?= $year ?>年 月別利用回数（年計: <?= $yearTotal ?>回）</div>
  <div class="month-grid">
    <?php for($m=1;$m<=12;$m++):
      $cnt=$monthlyMap[$m];
      $r=$maxMonth>0?$cnt/$maxMonth:0;
      $alpha=0.15+($r*0.65);
    ?>
    <div class="month-cell" style="background:rgba(167,139,250,<?= number_format($alpha,2,'.','') ?>)">
      <div class="m-label"><?= $m ?>月</div>
      <div class="m-cnt"><?= $cnt ?>回</div>
    </div>
    <?php endfor; ?>
  </div>
</div>

<?php endif; ?>

<!-- ════════════════════════════════
     履歴 / 共有 / エラー ページ
════════════════════════════════ -->
<?php if (in_array($page, ['history','shared','errors'], true)): ?>

<?php if ($detailRow): ?>
  <!-- ── 詳細表示 ── -->
  <a class="detail-back-link" href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>">
    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
    一覧へ戻る
  </a>

  <!-- 詳細アクションボタン -->
  <div class="detail-actions-bar">
    <?php if ($page === 'shared' && (int)($detailRow['shared'] ?? 0) === 1): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="admin_action" value="disable_share">
      <input type="hidden" name="record_id" value="<?= (int)$detailRow['id'] ?>">
      <input type="hidden" name="detail_id" value="<?= $detailId ?>">
      <button type="submit" class="btn-admin-share-off" onclick="return confirm('共有を無効化しますか？')">
        <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        共有を無効化
      </button>
    </form>
    <?php endif; ?>
    <?php if (!empty($detailRow['share_token']) && $page !== 'errors'): ?>
    <a class="btn-admin-share-link" target="_blank" href="index.php?share=<?= h((string)$detailRow['share_token']) ?>">
      <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      共有URLを開く
    </a>
    <?php endif; ?>
    <?php
      $canDelete = true;
      if ($page !== 'errors' && (int)($detailRow['shared'] ?? 0) === 1) $canDelete = false;
    ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="admin_action" value="delete_record">
      <input type="hidden" name="record_id" value="<?= (int)$detailRow['id'] ?>">
      <input type="hidden" name="record_table" value="<?= $page === 'errors' ? 'compass_error_logs' : 'compass_consultations' ?>">
      <button type="submit" class="btn-admin-delete" <?= !$canDelete ? 'disabled title="共有を無効化してから削除してください"' : 'onclick="return confirm(\'このデータを削除しますか？この操作は元に戻せません\')"' ?>>
        <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        削除
      </button>
    </form>
  </div>

  <?php if ($page === 'errors'): ?>
  <!-- エラー詳細 -->
  <div class="json-block">
    <div class="json-block-header">
      <div class="json-block-title">エラー詳細</div>
    </div>
    <?php $errJson = json_encode($detailRow, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); ?>
    <div class="json-block-actions" style="padding:12px 18px 0">
      <button class="btn-json-copy" onclick="copyJsonText(this, <?= h(json_encode($errJson)) ?>)">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>コピー
      </button>
      <button class="btn-json-dl" onclick="downloadJsonText(<?= h(json_encode($errJson)) ?>, 'error-<?= (int)$detailRow['id'] ?>.json')">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>DL
      </button>
    </div>
    <div class="json-preview collapsed" id="json-err">
      <pre><?= h($errJson) ?></pre>
    </div>
    <div class="json-expand-btn" onclick="toggleJson('json-err', this)">続きを表示 ▼</div>
  </div>

  <?php else: ?>
  <!-- 履歴・共有 詳細プレビュー -->

  <?php
    // 入力サマリー用データを組み立て
    $previewItem = array_merge($detailJson, [
      'input'  => (string)($detailRow['input_text'] ?? ''),
      'extra'  => (string)($detailRow['extra_text'] ?? ''),
      'isLine' => ((string)($detailRow['consultation_type'] ?? 'line')) === 'line',
      'date'   => (string)($detailRow['created_at'] ?? ''),
    ]);

    // スコア色
    $score = (int)($detailJson['pulseRate'] ?? 0);
    $scoreColor = $score >= 70 ? 'var(--accent)' : ($score >= 40 ? 'var(--accent3)' : 'var(--accent2)');
    $barBg = $scoreColor;

    $inputJson  = json_encode(buildInputJson($detailRow, $detailJson), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    $outputJson = json_encode(buildOutputJson($detailJson), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  ?>

  <!-- プレビュー：スコアカード -->
  <div class="preview-wrap">
    <div class="preview-header">
      <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:var(--accent3);fill:none;stroke-width:2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      分析レポート プレビュー
      <span class="report-source-badge" style="margin-left:auto">#<?= (int)$detailRow['id'] ?></span>
    </div>
    <div class="preview-body">

      <!-- スコアカード -->
      <div class="score-card fade-up section-gap">
        <p class="score-label">脈あり・インタレスト指数</p>
        <div class="score-num" style="color:<?= $scoreColor ?>"><?= $score ?>%</div>
        <div class="score-track">
          <div class="score-fill" style="width:<?= $score ?>%;background:<?= $barBg ?>"></div>
        </div>
        <p class="score-badge"><?= h((string)($detailJson['levelBadge'] ?? '')) ?></p>
      </div>

      <!-- 本音の分析 -->
      <div class="detail-card fade-up section-gap">
        <div class="detail-card-header">
          <div class="detail-icon pink">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
          </div>
          <span class="detail-card-title">本音の分析</span>
        </div>
        <p class="detail-card-body"><?= h((string)($detailJson['psychology'] ?? '')) ?></p>
      </div>

      <!-- アドバイス -->
      <div class="detail-card fade-up section-gap">
        <div class="detail-card-header">
          <div class="detail-icon purple">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <span class="detail-card-title">次の一手アドバイス</span>
        </div>
        <p class="detail-card-body"><?= h((string)($detailJson['advice'] ?? '')) ?></p>
      </div>

      <!-- 高度分析（compass-report.jsで描画） -->
      <div id="admin-advanced-report"></div>

      <!-- 入力サマリー -->
      <?php
        $isLine = $previewItem['isLine'];
        $typeText = $isLine ? 'メッセージ相談' : '言動・状況の相談';
        $badgeClass = $isLine ? 'summary-type-badge line-type' : 'summary-type-badge sit-type';
        $meetLabel = isset($previewItem['meetVal']) ? getMeetLabelPHP((int)$previewItem['meetVal']) : '—';
        $speedLabel = isset($previewItem['replyLen']) ? getSpeedLabelPHP((int)$previewItem['replyLen']) : '—';
        $durationLabel = isset($previewItem['duration']) ? getDurationLabelPHP((int)$previewItem['duration']) : '—';
        $tensionLabel = isset($previewItem['tension']) ? getTensionLabelPHP((int)$previewItem['tension']) : '—';

        function getMeetLabelPHP(int $v): string {
          if ($v < 15) return 'まだ会ったことない';
          if ($v < 35) return '数回会ったくらい（2〜5回）';
          if ($v < 65) return '何度か会ってる（6〜15回）';
          if ($v < 85) return 'かなり頻繁に会ってる';
          return 'いつも一緒なくらい！';
        }
        function getSpeedLabelPHP(int $v): string {
          if ($v < 15) return 'かなり遅い（数日以上）';
          if ($v < 35) return '遅い（1日以上）';
          if ($v < 45) return 'やや遅い（数時間）';
          if ($v < 55) return '普通（半日くらい）';
          if ($v < 65) return 'やや早い（1〜2時間）';
          if ($v < 85) return '早い（数十分）';
          return 'かなり早い（即レス）';
        }
        function getDurationLabelPHP(int $v): string {
          if ($v < 15) return '少しの間だけ（30分未満）';
          if ($v < 35) return 'ちょっとした時間（30分〜1時間）';
          if ($v < 55) return '1〜2時間くらい';
          if ($v < 75) return '半日くらい（3〜5時間）';
          if ($v < 90) return '長時間（6〜8時間）';
          return '丸一日中！（9時間以上）';
        }
        function getTensionLabelPHP(int $v): string {
          if ($v < 15) return 'かなり低い（どんより）';
          if ($v < 35) return '低い（静か・落ち着いている）';
          if ($v < 45) return 'やや低い（ちょっとクール）';
          if ($v < 55) return '普通（何とも言えない）';
          if ($v < 65) return 'やや高い（少し楽しそう）';
          if ($v < 85) return '高い（盛り上がっている）';
          return 'かなり高い（テンションMAX！）';
        }
      ?>
      <div class="glass-card section-gap input-summary-card">
        <div class="summary-header">
          <span class="<?= $badgeClass ?>"><?= $typeText ?></span>
          <span class="summary-date"><?= h($previewItem['date']) ?></span>
        </div>
        <div class="summary-section">
          <?php if ($isLine): ?>
          <div class="summary-item-row"><span class="summary-item-label">ご相手</span><span class="summary-item-val"><?= h((string)($previewItem['partner'] ?? '—')) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">相手との関係</span><span class="summary-item-val"><?= h((string)($previewItem['rel'] ?? '—')) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">会った回数</span><span class="summary-item-val"><?= h($meetLabel) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">返信スピード</span><span class="summary-item-val"><?= h($speedLabel) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">普段と比べて</span><span class="summary-item-val"><?= h(is_array($previewItem['mood'] ?? null) ? implode('、', $previewItem['mood']) : ($previewItem['mood'] ?? '—')) ?></span></div>
          <?php else: ?>
          <div class="summary-item-row"><span class="summary-item-label">ご相手</span><span class="summary-item-val"><?= h((string)($previewItem['partner'] ?? '—')) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">場面・状況</span><span class="summary-item-val"><?= h((string)($previewItem['scene'] ?? '—')) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">一緒にいた時間</span><span class="summary-item-val"><?= h($durationLabel) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">場のテンション</span><span class="summary-item-val"><?= h($tensionLabel) ?></span></div>
          <div class="summary-item-row"><span class="summary-item-label">相手の様子</span><span class="summary-item-val"><?= h(is_array($previewItem['attitude'] ?? null) ? implode('、', $previewItem['attitude']) : ($previewItem['attitude'] ?? '—')) ?></span></div>
          <?php endif; ?>
        </div>
        <div class="summary-section text-section">
          <div class="summary-text-label"><?= $isLine ? '気になるメッセージの内容' : '気になった言動・セリフ' ?></div>
          <div class="summary-text-val"><?= h($previewItem['input']) ?></div>
        </div>
        <?php if (!empty($previewItem['extra'])): ?>
        <div class="summary-section text-section">
          <div class="summary-text-label">追加情報・背景</div>
          <div class="summary-text-val-extra"><?= h($previewItem['extra']) ?></div>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /preview-body -->
  </div><!-- /preview-wrap -->

  <!-- 入力の生JSON -->
  <div class="json-block">
    <div class="json-block-header">
      <div class="json-block-title">
        <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:var(--accent2);fill:none;stroke-width:2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        入力データ（生JSON）
      </div>
      <div class="json-block-actions">
        <button class="btn-json-copy" onclick="copyJsonText(this, <?= h(json_encode($inputJson)) ?>)">
          <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>コピー
        </button>
        <button class="btn-json-dl" onclick="downloadJsonText(<?= h(json_encode($inputJson)) ?>, 'input-<?= (int)$detailRow['id'] ?>.json')">
          <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>DL
        </button>
      </div>
    </div>
    <div class="json-preview collapsed" id="json-input">
      <pre><?= h($inputJson) ?></pre>
    </div>
    <div class="json-expand-btn" onclick="toggleJson('json-input', this)">続きを表示 ▼</div>
  </div>

  <!-- 出力の生JSON -->
  <div class="json-block">
    <div class="json-block-header">
      <div class="json-block-title">
        <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:var(--accent);fill:none;stroke-width:2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        出力データ（生JSON）
      </div>
      <div class="json-block-actions">
        <button class="btn-json-copy" onclick="copyJsonText(this, <?= h(json_encode($outputJson)) ?>)">
          <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>コピー
        </button>
        <button class="btn-json-dl" onclick="downloadJsonText(<?= h(json_encode($outputJson)) ?>, 'output-<?= (int)$detailRow['id'] ?>.json')">
          <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>DL
        </button>
      </div>
    </div>
    <div class="json-preview collapsed" id="json-output">
      <pre><?= h($outputJson) ?></pre>
    </div>
    <div class="json-expand-btn" onclick="toggleJson('json-output', this)">続きを表示 ▼</div>
  </div>

  <?php endif; /* errors/other */ ?>

<?php else: ?>
  <!-- ── 一覧表示 ── -->
  <?php
    $rows = $page==='history' ? $historyRows : ($page==='shared' ? $sharedRows : $errorRows);
    $totalRows = $page==='history' ? $historyTotal : ($page==='shared' ? $sharedTotal : $errorTotal);
    $maxPage = max(1, (int)ceil($totalRows/$perPage));
  ?>

  <div class="admin-table-wrap section-gap">
    <div class="admin-table-header">
      <div class="admin-table-title">
        <?php if ($page === 'history'): ?>
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        履歴一覧 <span class="sidenav-badge" style="margin-left:4px"><?= $historyTotal ?>件</span>
        <?php elseif ($page === 'shared'): ?>
        <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        共有中一覧 <span class="sidenav-badge" style="margin-left:4px"><?= $sharedTotal ?>件</span>
        <?php else: ?>
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        エラー一覧 <span class="sidenav-badge" style="margin-left:4px"><?= $errorTotal ?>件</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--text3);font-weight:600">データがありません</div>
    <?php else: ?>

    <?php foreach ($rows as $r): ?>
    <div class="admin-list-item">
      <div class="admin-list-item-body">
        <div class="admin-list-item-id">
          #<?= (int)$r['id'] ?>
          <?php if ($page !== 'errors'): ?>
          &nbsp;
          <span class="shared-badge <?= (int)($r['shared'] ?? 0) ? 'on' : 'off' ?>">
            <?= (int)($r['shared'] ?? 0) ? '共有中' : '非公開' ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="admin-list-item-text">
          <?= h(mb_strimwidth((string)($r['input_text'] ?? $r['message'] ?? ''), 0, 100, '…')) ?>
        </div>
        <div class="admin-list-item-meta"><?= h((string)$r['created_at']) ?></div>
      </div>
      <div class="admin-list-actions">
        <a class="btn-admin-detail" href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>&id=<?= (int)$r['id'] ?>">
          <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          詳細
        </a>
        <?php if ($page === 'shared' && (int)($r['shared'] ?? 0) === 1): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="admin_action" value="disable_share">
          <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="btn-admin-share-off" onclick="return confirm('共有を無効化しますか？')">無効化</button>
        </form>
        <?php endif; ?>
        <?php
          $listCanDelete = true;
          if ($page !== 'errors' && (int)($r['shared'] ?? 0) === 1) $listCanDelete = false;
        ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="admin_action" value="delete_record">
          <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="record_table" value="<?= $page === 'errors' ? 'compass_error_logs' : 'compass_consultations' ?>">
          <button type="submit" class="btn-admin-delete" <?= !$listCanDelete ? 'disabled title="共有を無効化してから削除できます"' : 'onclick="return confirm(\'削除しますか？\')"' ?>>
            <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
            削除
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="pager">
      <?php if ($listPage > 1): ?>
      <a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage-1 ?>">← 前へ</a>
      <?php endif; ?>
      <span><?= $listPage ?> / <?= $maxPage ?></span>
      <?php if ($listPage < $maxPage): ?>
      <a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage+1 ?>">次へ →</a>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>

<?php endif; /* detail/list */ ?>
<?php endif; /* page check */ ?>

</main>

<script src="compass-report.js"></script>
<script>
/* ── 表示切替 ── */
(function(){
  const key = 'compass_admin_view';
  const b = document.body;
  const btn = document.getElementById('btn-view-toggle');

  function set(v) {
    b.classList.remove('view-mobile','view-pc');
    b.classList.add(v);
    if (btn) {
      if (v === 'view-pc') {
        btn.innerHTML = '<svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>スマホ表示';
      } else {
        btn.innerHTML = '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><polyline points="8 21 12 17 16 21"/></svg>PC表示';
      }
    }
    localStorage.setItem(key, v);
  }

  const saved = localStorage.getItem(key) || (window.innerWidth >= 1024 ? 'view-pc' : 'view-mobile');
  set(saved);
  if (btn) btn.addEventListener('click', () => set(b.classList.contains('view-pc') ? 'view-mobile' : 'view-pc'));
  window.addEventListener('resize', () => {
    // auto-detect only if no manual override
    // (keep manual choice)
  });
})();

/* ── JSONトグル ── */
function toggleJson(id, btn) {
  const el = document.getElementById(id);
  if (!el) return;
  const collapsed = el.classList.contains('collapsed');
  el.classList.toggle('collapsed', !collapsed);
  btn.textContent = collapsed ? '折りたたむ ▲' : '続きを表示 ▼';
}

/* ── JSONコピー ── */
function copyJsonText(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:var(--green);fill:none;stroke-width:2.5"><polyline points="20 6 9 17 4 12"/></svg>コピー完了';
    setTimeout(() => { btn.innerHTML = orig; }, 2000);
  });
}

/* ── JSONダウンロード ── */
function downloadJsonText(text, filename) {
  const blob = new Blob([text], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ── 詳細ページ: compass-report.jsで高度分析を描画 ── */
<?php if ($detailRow && $page !== 'errors' && !empty($detailJson)): ?>
(function() {
  const data = <?= json_encode($detailJson, JSON_UNESCAPED_UNICODE) ?>;
  const container = document.getElementById('admin-advanced-report');
  if (container && typeof renderAdvancedReport === 'function') {
    renderAdvancedReport(data, container);
  }
})();
<?php endif; ?>
</script>
</body>
</html>