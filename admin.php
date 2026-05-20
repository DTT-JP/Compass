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
$navBase = 'admin.php?page=' . $page;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['admin_action'] ?? '';
    if ($action === 'save_model') {
        $model = trim((string)($_POST['model_name'] ?? ''));
        if ($model !== '' && in_array($model, $availableModels, true)) {
            $stmt = $pdo->prepare('UPDATE compass_settings SET model_name=:model WHERE id=1');
            $stmt->execute(['model' => $model]);
            header('Location: admin.php?page=api&saved=1'); exit;
        }
        header('Location: admin.php?page=api&saved=0'); exit;
    }
    if ($action === 'delete_consultation') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        if ($id > 0) { $stmt = $pdo->prepare('DELETE FROM compass_consultations WHERE id=:id'); $stmt->execute(['id' => $id]); }
        header('Location: ' . $navBase . '&saved=1'); exit;
    }
    if ($action === 'delete_share_url') {
        $id = (int)($_POST['consultation_id'] ?? 0);
        if ($id > 0) { $stmt = $pdo->prepare('UPDATE compass_consultations SET shared=0, share_token=NULL, shared_at=NULL WHERE id=:id'); $stmt->execute(['id' => $id]); }
        header('Location: admin.php?page=shared&saved=1'); exit;
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
$stmt->execute(['ym' => $monthDate->format('Y-m')]);
$dailyMap = []; foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $dailyMap[$r['day']] = (int)$r['cnt'];
$year = (int)$monthDate->format('Y');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM compass_usage_logs WHERE YEAR(created_at)=:y'); $stmt->execute(['y'=>$year]); $yearTotal=(int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT MONTH(created_at) m, COUNT(*) cnt FROM compass_usage_logs WHERE YEAR(created_at)=:y GROUP BY MONTH(created_at)'); $stmt->execute(['y'=>$year]);
$monthlyMap = array_fill(1,12,0); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $monthlyMap[(int)$r['m']] = (int)$r['cnt'];

$listPage = max(1, (int)($_GET['p'] ?? 1)); $perPage = 50; $offset = ($listPage - 1) * $perPage;
$historyTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations')->fetchColumn();
$sharedTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_consultations WHERE shared=1')->fetchColumn();
$errorTotal = (int)$pdo->query('SELECT COUNT(*) FROM compass_error_logs')->fetchColumn();

$historyRows = []; $sharedRows = []; $errorRows = [];
if ($page === 'history') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,pulse_rate,level_badge,created_at FROM compass_consultations ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $historyRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'shared') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,pulse_rate,level_badge,share_token,created_at,shared_at FROM compass_consultations WHERE shared=1 ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $sharedRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'errors') { $stmt=$pdo->prepare('SELECT id,model_name,message,detail,http_status,request_body,response_body,created_at FROM compass_error_logs ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $errorRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }

$detailId = max(0,(int)($_GET['id'] ?? 0)); $detailRow = null;
if ($detailId > 0 && in_array($page, ['history','shared','errors'], true)) {
    if ($page === 'errors') { $stmt=$pdo->prepare('SELECT * FROM compass_error_logs WHERE id=:id'); }
    elseif ($page === 'shared') { $stmt=$pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id AND shared=1'); }
    else { $stmt=$pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id'); }
    $stmt->execute(['id'=>$detailId]); $detailRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$firstWeekday=(int)$monthDate->format('w'); $daysInMonth=(int)$monthDate->format('t');
?>
<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Compass Admin</title><link rel="stylesheet" href="compass.css">
<style>
body{background:#f3f5f8}.admin-shell{max-width:1280px;margin:0 auto;padding:20px}.admin-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.admin-head-left{display:flex;align-items:center;gap:12px}.admin-nav{display:flex;gap:10px;flex-wrap:wrap}.admin-nav a{padding:10px 16px;border-radius:10px;background:#fff;border:1px solid #d7dce5;text-decoration:none;color:#2c3440;font-weight:700}.admin-nav a.active{background:#2f6feb;color:#fff;border-color:#2f6feb}.panel{background:#fff;border:1px solid #d7dce5;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(19,30,46,.06)}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.kpi{background:#f7faff;border:1px solid #d7e7ff;border-radius:10px;padding:10px}.kpi .n{font-size:26px;font-weight:800}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.day{border:1px solid #e2e6ef;border-radius:8px;padding:8px;min-height:62px}.muted{color:#6b7685}.list{display:flex;flex-direction:column;gap:10px}.row{border:1px solid #e2e6ef;border-radius:10px;padding:12px;background:#fbfcff}.row a{text-decoration:none;color:#1a4fd8;font-weight:700}.pager{display:flex;gap:8px;align-items:center;margin-top:10px}.btn{background:#2f6feb;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer}.btn-toggle{background:#fff;color:#2c3440;border:1px solid #d7dce5;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer}body.view-mobile .admin-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}body.view-mobile .admin-nav a{text-align:center}body.view-mobile .admin-shell{padding:12px}body.view-mobile .grid2,body.view-mobile .stats{grid-template-columns:1fr}body.view-pc .layout{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}body.view-pc .side-nav{position:sticky;top:16px}body.view-pc .side-nav .admin-nav{display:flex;flex-direction:column}body.view-pc .side-nav .admin-nav a{text-align:left}@media (max-width:900px){body.view-pc .layout{grid-template-columns:1fr}body.view-pc .side-nav .admin-nav{flex-direction:row;flex-wrap:wrap}}
</style></head><body>
<div class="admin-shell">
<div class="admin-header"><div class="admin-head-left"><div><h1>Compass 管理画面</h1><div class="muted">PC/モバイル切り替え対応</div></div></div><button id="btn-view-toggle" type="button" class="btn-toggle">PC表示</button></div>
<div class="layout"><aside class="side-nav"><nav class="admin-nav">
<a class="<?= $page==='api'?'active':'' ?>" href="admin.php?page=api">API</a>
<a class="<?= $page==='history'?'active':'' ?>" href="admin.php?page=history">履歴</a>
<a class="<?= $page==='shared'?'active':'' ?>" href="admin.php?page=shared">共有</a>
<a class="<?= $page==='errors'?'active':'' ?>" href="admin.php?page=errors">エラー</a>
</nav></aside><section class="main-pane"><br>
<?php if ($page==='api'): ?>
<div class="grid2"><section class="panel"><h3>モデル設定</h3><?php if(($_GET['saved'] ?? '')==='1'): ?><p>保存しました。</p><?php elseif(($_GET['saved'] ?? '')==='0'): ?><p>保存失敗</p><?php endif; ?><form method="post"><input type="hidden" name="admin_action" value="save_model"><select name="model_name" style="width:100%;padding:10px;margin:8px 0"><?php foreach($availableModels as $m): ?><option value="<?= h($m) ?>" <?= $m===$currentModel?'selected':'' ?>><?= h($m) ?></option><?php endforeach; ?></select><button class="btn" type="submit">保存</button></form></section>
<section class="panel"><h3>全モデル合計の使用回数</h3><div class="stats"><div class="kpi"><div>今日</div><div class="n"><?= $todayCount ?></div></div><div class="kpi"><div>今週</div><div class="n"><?= $weekCount ?></div></div><div class="kpi"><div>今月</div><div class="n"><?= $monthCount ?></div></div><div class="kpi"><div>総計</div><div class="n"><?= $total ?></div></div></div></section></div><br>
<section class="panel"><h3>月間カレンダー（<?= h($monthDate->format('Y年n月')) ?>）</h3><div class="pager"><a href="admin.php?page=api&ym=<?= h($prevYm) ?>">←先月</a><strong><?= h($monthDate->format('Y年n月')) ?></strong><a href="admin.php?page=api&ym=<?= h($nextYm) ?>">来月→</a></div><div class="calendar"><?php foreach(['日','月','火','水','木','金','土'] as $w): ?><div class="day"><strong><?= $w ?></strong></div><?php endforeach; ?><?php for($i=0;$i<$firstWeekday;$i++): ?><div class="day"></div><?php endfor; ?><?php for($d=1;$d<=$daysInMonth;$d++): $dk=$monthDate->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT); ?><div class="day"><div><?= $d ?>日</div><div><strong><?= (int)($dailyMap[$dk] ?? 0) ?>回</strong></div></div><?php endfor; ?></div></section><br>
<section class="panel"><h3><?= $year ?>年集計</h3><p>年間合計: <strong><?= $yearTotal ?>回</strong></p><div class="stats"><?php for($m=1;$m<=12;$m++): ?><div class="kpi"><div><?= $m ?>月</div><div class="n" style="font-size:20px"><?= $monthlyMap[$m] ?></div></div><?php endfor; ?></div></section>
<?php endif; ?>

<?php if ($page==='history' || $page==='shared' || $page==='errors'): ?>
<section class="panel">
<?php if ($detailRow): ?>
<h3>詳細 #<?= (int)$detailRow['id'] ?></h3><p><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>">← 一覧に戻る</a></p><pre style="white-space:pre-wrap;background:#f7f9fc;border:1px solid #e2e6ef;padding:12px;border-radius:8px"><?= h(json_encode($detailRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
<?php else: ?>
<h3><?= $page==='history'?'履歴一覧':($page==='shared'?'共有一覧':'エラー一覧') ?></h3>
<div class="list">
<?php $rows = $page==='history'?$historyRows:($page==='shared'?$sharedRows:$errorRows); foreach($rows as $r): ?>
<div class="row">
<div><strong>#<?= (int)$r['id'] ?></strong> <span class="muted"><?= h((string)$r['created_at']) ?></span></div>
<div><?= h(mb_strimwidth((string)($r['input_text'] ?? $r['message'] ?? ''), 0, 120, '...')) ?></div>
<a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>&id=<?= (int)$r['id'] ?>">詳細を見る</a>
<?php if ($page==='shared'): ?><div><a target="_blank" href="index.php?share=<?= h((string)$r['share_token']) ?>">共有URL</a></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php $totalRows = $page==='history'?$historyTotal:($page==='shared'?$sharedTotal:$errorTotal); $maxPage=max(1,(int)ceil($totalRows/$perPage)); ?>
<div class="pager"><?php if($listPage>1): ?><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage-1 ?>">←前へ</a><?php endif; ?><span><?= $listPage ?>/<?= $maxPage ?></span><?php if($listPage<$maxPage): ?><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage+1 ?>">次へ→</a><?php endif; ?></div>
<?php endif; ?>
</section>
<?php endif; ?>
</section></div></div><script>
(()=>{const key="compass_admin_view";const b=document.body;const btn=document.getElementById("btn-view-toggle");const set=(v)=>{b.classList.remove("view-mobile","view-pc");b.classList.add(v);if(btn)btn.textContent=v==="view-pc"?"モバイル表示":"PC表示";localStorage.setItem(key,v);};const saved=localStorage.getItem(key);if(saved==="view-pc"||saved==="view-mobile"){set(saved);}else{set(window.innerWidth>=1024?"view-pc":"view-mobile");}btn&&btn.addEventListener("click",()=>set(b.classList.contains("view-pc")?"view-mobile":"view-pc"));})();
</script></body></html>
