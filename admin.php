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
if ($page === 'history') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,response_json,created_at FROM compass_consultations ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $historyRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'shared') { $stmt=$pdo->prepare('SELECT id,consultation_type,device_id,input_text,response_json,share_token,created_at,shared_at FROM compass_consultations WHERE shared=1 ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $sharedRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
if ($page === 'errors') { $stmt=$pdo->prepare('SELECT id,model_name,message,detail,http_status,request_body,response_body,created_at FROM compass_error_logs ORDER BY id DESC LIMIT :l OFFSET :o'); $stmt->bindValue(':l',$perPage,PDO::PARAM_INT); $stmt->bindValue(':o',$offset,PDO::PARAM_INT); $stmt->execute(); $errorRows=$stmt->fetchAll(PDO::FETCH_ASSOC); }

$detailId = max(0,(int)($_GET['id'] ?? 0)); $detailRow = null; $detailJson=[];
if ($detailId > 0 && in_array($page, ['history','shared','errors'], true)) {
    $stmt = $page === 'errors' ? $pdo->prepare('SELECT * FROM compass_error_logs WHERE id=:id') : ($page === 'shared' ? $pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id AND shared=1') : $pdo->prepare('SELECT * FROM compass_consultations WHERE id=:id'));
    $stmt->execute(['id'=>$detailId]); $detailRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailRow && $page !== 'errors') { $detailJson = json_decode((string)($detailRow['response_json'] ?? ''), true) ?: []; }
}
$firstWeekday=(int)$monthDate->format('w'); $daysInMonth=(int)$monthDate->format('t');
?>
<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><title>Compass Admin</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="compass.css">
<style>
main{max-width:1200px;margin:0 auto}.admin-wrap{padding:0 16px 84px}.admin-grid{display:grid;grid-template-columns:250px minmax(0,1fr);gap:16px}.admin-list .history-item{cursor:default}.admin-list .history-item a{color:var(--accent3);font-weight:700;text-decoration:none}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.metric{background:rgba(255,255,255,.5);border:2px solid #fff;border-radius:16px;padding:12px}.metric .n{font-size:26px;font-weight:700}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}.day{background:rgba(255,255,255,.55);border:2px solid #fff;border-radius:14px;padding:8px;min-height:68px}.month-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.month-cell{border-radius:12px;padding:10px;color:#fff}.detail-area pre{max-height:420px;overflow:auto;max-width:100%}.json-actions{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.json-actions button,.json-actions a{font-size:12px;padding:8px 12px;border-radius:10px;border:2px solid #fff;background:rgba(255,255,255,.8);cursor:pointer;text-decoration:none;color:var(--text2);font-weight:700}.preview-frame{width:100%;height:520px;border:2px solid #fff;border-radius:14px;background:#fff}.pc-vertical-toggle{width:40px;height:84px;writing-mode:vertical-rl;text-orientation:mixed;padding:8px 0}.mobile-bottom-toggle{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(env(safe-area-inset-bottom,0px) + 12px);z-index:40;box-shadow:var(--shadow-md)}body.view-pc .mobile-bottom-toggle{display:none}@media (max-width:980px){.admin-grid{grid-template-columns:1fr}.month-grid{grid-template-columns:repeat(3,1fr)}.metric-grid{grid-template-columns:repeat(2,1fr)}.header-actions{display:none}}
</style></head><body class="view-mobile"><main>
<header><div class="logo-mark"><div class="logo-icon"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><path d="M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M16.3 7.7l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg></div><div class="logo-text"><h1>Compass Admin</h1><p>運用ダッシュボード</p></div></div><div class="header-actions"><button id="btn-view-toggle-pc" class="btn-icon pc-vertical-toggle">PC表示</button></div></header>
<div class="admin-wrap"><div class="admin-grid"><aside><div class="segment-wrap"><a class="seg-btn <?= $page==='api'?'active':'' ?>" href="admin.php?page=api">API</a><a class="seg-btn <?= $page==='history'?'active':'' ?>" href="admin.php?page=history">履歴</a><a class="seg-btn <?= $page==='shared'?'active':'' ?>" href="admin.php?page=shared">共有</a><a class="seg-btn <?= $page==='errors'?'active':'' ?>" href="admin.php?page=errors">エラー</a></div></aside><section>
<?php if($page==='api'): ?>
<div class="glass-card section-gap"><div class="card-label">モデル設定</div><form method="post"><input type="hidden" name="admin_action" value="save_model"><select name="model_name" style="width:100%;padding:12px;border-radius:14px;border:2px solid #fff"><?php foreach($availableModels as $m): ?><option value="<?= h($m) ?>" <?= $m===$currentModel?'selected':'' ?>><?= h($m) ?></option><?php endforeach; ?></select><div class="btn-submit-wrap"><button class="btn-submit" type="submit"><span>保存</span></button></div></form></div>
<div class="glass-card section-gap"><div class="card-label">API利用サマリー</div><div class="metric-grid"><div class="metric"><div>今日</div><div class="n"><?= $todayCount ?></div></div><div class="metric"><div>今週</div><div class="n"><?= $weekCount ?></div></div><div class="metric"><div>今月</div><div class="n"><?= $monthCount ?></div></div><div class="metric"><div>総計</div><div class="n"><?= $total ?></div></div></div></div>
<div class="glass-card section-gap"><div class="history-header" style="padding:0 0 10px;border:none"><div class="history-title">月間カレンダー（<?= h($monthDate->format('Y年n月')) ?>）</div><div class="pager"><a href="admin.php?page=api&ym=<?= h($prevYm) ?>">←先月</a> <a href="admin.php?page=api&ym=<?= h($nextYm) ?>">来月→</a></div></div><div class="calendar"><?php foreach(['日','月','火','水','木','金','土'] as $w): ?><div class="day"><strong><?= $w ?></strong></div><?php endforeach; ?><?php for($i=0;$i<$firstWeekday;$i++): ?><div class="day"></div><?php endfor; ?><?php for($d=1;$d<=$daysInMonth;$d++): $dk=$monthDate->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT); $cnt=(int)($dailyMap[$dk] ?? 0); $ratio=$maxDaily>0?$cnt/$maxDaily:0; $alpha=0.08+($ratio*0.78); ?><div class="day" style="background:rgba(110,181,255,<?= number_format($alpha,2,'.','') ?>)"><div><?= $d ?>日</div><div><strong><?= $cnt ?>回</strong></div></div><?php endfor; ?></div></div>
<div class="glass-card section-gap"><div class="card-label"><?= $year ?>年 月別利用回数</div><div class="month-grid"><?php for($m=1;$m<=12;$m++): $cnt=$monthlyMap[$m]; $r=$maxMonth>0?$cnt/$maxMonth:0; $alpha=0.18+($r*0.72); ?><div class="month-cell" style="background:rgba(167,139,250,<?= number_format($alpha,2,'.','') ?>)"><div><?= $m ?>月</div><strong><?= $cnt ?>回</strong></div><?php endfor; ?></div></div>
<?php endif; ?>
<?php if($page==='history' || $page==='shared' || $page==='errors'): ?>
<div class="history-section admin-list"><div class="history-header"><div class="history-title"><?= $detailRow ? '詳細表示' : ($page==='errors'?'エラー一覧':'履歴一覧') ?></div><?php if($detailRow): ?><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>">一覧へ戻る</a><?php endif; ?></div>
<?php if($detailRow): ?><div class="history-item detail-area">
<?php if($page==='errors'): ?><pre><?= h(json_encode($detailRow, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
<?php else: $rawJson = (string)($detailRow['response_json'] ?? ''); if($rawJson===''){ $rawJson = json_encode($detailJson, JSON_UNESCAPED_UNICODE); } $score=(int)($detailJson['score'] ?? 0); $badge=(string)($detailJson['badge'] ?? ($detailJson['level_badge'] ?? '判定なし')); $summary=(string)($detailJson['summary'] ?? $detailJson['oneLine'] ?? '詳細テキストなし'); ?><div class="glass-card section-gap"><div class="card-label">index.php準拠プレビュー（共有はしない）</div><div class="score-card"><div class="score-label">推定脈あり度</div><div class="score-num" style="color:var(--accent)"><?= $score ?></div><div class="score-badge"><?= h($badge) ?></div></div><div class="detail-card"><div class="detail-card-header"><div class="detail-card-title">概要</div></div><div class="detail-card-body"><?= nl2br(h($summary)) ?></div></div><div class="json-actions"><button type="button" disabled title="管理画面では共有しません">共有は無効</button><?php if(!empty($detailRow['share_token'])): ?><a target="_blank" href="index.php?share=<?= h((string)$detailRow['share_token']) ?>">既存共有ページを開く</a><?php endif; ?></div></div><div class="detail-card"><div class="detail-card-header"><div class="detail-card-title">入力文</div></div><div class="detail-card-body"><?= nl2br(h((string)($detailRow['input_text'] ?? ''))) ?></div></div>
<?php if(!empty($detailRow['extra_text'])): ?><div class="detail-card"><div class="detail-card-header"><div class="detail-card-title">補足</div></div><div class="detail-card-body"><?= nl2br(h((string)$detailRow['extra_text'])) ?></div></div><?php endif; ?>
<div class="detail-card"><div class="detail-card-header"><div class="detail-card-title">生JSON</div></div><div class="json-actions"><button type="button" onclick="copyJson()">コピー</button><button type="button" onclick="downloadJson()">ダウンロード</button></div><div class="detail-card-body"><pre id="raw-json"><?= h($rawJson) ?></pre></div></div>

<?php endif; ?></div>
<?php else: $rows = $page==='history'?$historyRows:($page==='shared'?$sharedRows:$errorRows); foreach($rows as $r): ?><div class="history-item"><div><strong>#<?= (int)$r['id'] ?></strong> <span class="model-line"><?= h((string)$r['created_at']) ?></span></div><div><?= h(mb_strimwidth((string)($r['input_text'] ?? $r['message'] ?? ''), 0, 120, '...')) ?></div><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage ?>&id=<?= (int)$r['id'] ?>">詳細を見る</a><?php if($page==='shared'): ?> / <a target="_blank" href="index.php?share=<?= h((string)$r['share_token']) ?>">共有URL</a><?php endif; ?></div><?php endforeach; $totalRows = $page==='history'?$historyTotal:($page==='shared'?$sharedTotal:$errorTotal); $maxPage=max(1,(int)ceil($totalRows/$perPage)); ?><div class="history-item"><div class="pager"><?php if($listPage>1): ?><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage-1 ?>">←前へ</a><?php endif; ?><span><?= $listPage ?>/<?= $maxPage ?></span><?php if($listPage<$maxPage): ?><a href="admin.php?page=<?= h($page) ?>&p=<?= $listPage+1 ?>">次へ→</a><?php endif; ?></div></div><?php endif; ?>
</div><?php endif; ?>
</section></div></div><button id="btn-view-toggle-mobile" class="btn-icon mobile-bottom-toggle">表示切替</button></main><script>(()=>{const key='compass_admin_view';const b=document.body;const pcBtn=document.getElementById('btn-view-toggle-pc');const moBtn=document.getElementById('btn-view-toggle-mobile');const set=v=>{b.classList.remove('view-mobile','view-pc');b.classList.add(v);if(pcBtn)pcBtn.textContent=v==='view-pc'?'モバイル表示':'PC表示';if(moBtn)moBtn.textContent=v==='view-pc'?'モバイルへ':'PCへ';localStorage.setItem(key,v);};set(localStorage.getItem(key)|| (window.innerWidth>=1024?'view-pc':'view-mobile'));pcBtn&&pcBtn.addEventListener('click',()=>set(b.classList.contains('view-pc')?'view-mobile':'view-pc'));moBtn&&moBtn.addEventListener('click',()=>set(b.classList.contains('view-pc')?'view-mobile':'view-pc'));})();function copyJson(){const el=document.getElementById('raw-json');if(!el)return;navigator.clipboard.writeText(el.textContent||'');}function downloadJson(){const el=document.getElementById('raw-json');if(!el)return;const blob=new Blob([el.textContent||''],{type:'application/json'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='compass-detail.json';a.click();URL.revokeObjectURL(a.href);}</script></body></html>
