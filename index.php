<?php
if (isset($_GET['share']) && $_GET['share'] !== '') {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_GET['share']);
    $envPath = dirname(__DIR__, 2) . '/env/compass.php';
    require_once $envPath;
    $dbDsn = "mysql:host=" . $Compass_DB_Host . ";dbname=" . $Compass_DB_Name . ";charset=utf8mb4";
    $pdo = new PDO($dbDsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT response_json, shared FROM compass_consultations WHERE share_token = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $sharedNotFound = true;
    } elseif (!(int)$row['shared']) {
        $sharedDisabled = true;
    } else {
        $sharedItem = json_decode((string)$row['response_json'], true) ?: [];
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_GET["action"]) && $_GET["action"] === "share-toggle") {
    header('Content-Type: application/json; charset=utf-8');
    $envPath = dirname(__DIR__, 2) . '/env/compass.php';
    require_once $envPath;
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $consultationId = (int)($input['consultationId'] ?? 0);
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $record = $input['record'] ?? null;
    $isResave = !empty($input['isResave']);

    if ($consultationId <= 0 && !$isResave) { http_response_code(400); echo json_encode(['error' => 'invalid id']); exit; }
    $dbDsn = "mysql:host=" . $Compass_DB_Host . ";dbname=" . $Compass_DB_Name . ";charset=utf8mb4";
    $pdo = new PDO($dbDsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Re-save case: consultationId not in DB (was deleted), insert new record
    if ($isResave && is_array($record) && $enabled) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS compass_consultations (id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(100) NULL, consultation_type VARCHAR(20) NOT NULL, input_text MEDIUMTEXT NOT NULL, extra_text MEDIUMTEXT NULL, prompt_text MEDIUMTEXT NULL, response_json MEDIUMTEXT NULL, pulse_rate INT NULL, level_badge VARCHAR(255) NULL, shared TINYINT(1) NOT NULL DEFAULT 0, share_token VARCHAR(64) NULL, shared_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_share_token (share_token), INDEX idx_device_id (device_id), INDEX idx_created_at (created_at))");
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO compass_consultations (device_id, consultation_type, input_text, extra_text, response_json, shared, share_token, shared_at) VALUES (:device_id, :type, :input_text, :extra_text, :response_json, 1, :share_token, NOW())");
        $stmt->execute([
            'device_id' => (string)($input['deviceId'] ?? ''),
            'type' => (string)($record['isLine'] ?? true ? 'line' : 'sit'),
            'input_text' => (string)($record['input'] ?? ''),
            'extra_text' => (string)($record['extra'] ?? ''),
            'response_json' => json_encode($record, JSON_UNESCAPED_UNICODE),
            'share_token' => $token,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']), '/');
        echo json_encode(['enabled' => true, 'url' => $base . '/index.php?share=' . $token, 'newConsultationId' => $newId], JSON_UNESCAPED_UNICODE); exit;
    }

    $token = $enabled ? bin2hex(random_bytes(16)) : null;
    if (is_array($record)) {
        $stmt = $pdo->prepare("UPDATE compass_consultations SET shared=:shared, share_token=:share_token, shared_at=:shared_at, response_json=:response_json WHERE id=:id AND device_id=:device_id");
        $stmt->execute(['shared'=>$enabled,'share_token'=>$token,'shared_at'=>$enabled ? date('Y-m-d H:i:s') : null,'response_json'=>json_encode($record, JSON_UNESCAPED_UNICODE),'id'=>$consultationId,'device_id'=>(string)($input['deviceId'] ?? '')]);
    } else {
        $stmt = $pdo->prepare("UPDATE compass_consultations SET shared=:shared, share_token=:share_token, shared_at=:shared_at WHERE id=:id AND device_id=:device_id");
        $stmt->execute(['shared'=>$enabled,'share_token'=>$token,'shared_at'=>$enabled ? date('Y-m-d H:i:s') : null,'id'=>$consultationId,'device_id'=>(string)($input['deviceId'] ?? '')]);
    }
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']), '/');
    echo json_encode(['enabled' => (bool)$enabled, 'url' => $enabled ? ($base . '/index.php?share=' . $token) : null], JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_GET["action"]) && $_GET["action"] === "analyze") {
    header('Content-Type: application/json; charset=utf-8');

    $envPath = dirname(__DIR__, 2) . '/env/compass.php';
    if (!is_file($envPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'env.php が見つかりません']);
        exit;
    }

    require_once $envPath;

    $logError = static function(PDO $pdo, ?string $model, string $message, ?string $detail, ?string $requestBody, ?string $responseBody, ?int $httpStatus): void {
        $stmt = $pdo->prepare('INSERT INTO compass_error_logs (model_name, message, detail, request_body, response_body, http_status) VALUES (:model_name, :message, :detail, :request_body, :response_body, :http_status)');
        $stmt->execute([
            'model_name' => $model,
            'message' => $message,
            'detail' => $detail,
            'request_body' => $requestBody,
            'response_body' => $responseBody,
            'http_status' => $httpStatus,
        ]);
    };
    $respondFriendlyError = static function(int $status, string $code, string $solution): void {
        http_response_code($status);
        echo json_encode([
            'error' => [
                'code' => $code,
                'solution' => $solution,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    };
    $friendlySolutionByStatus = static function(int $status): string {
        if ($status === 429) return 'アクセスが集中しています。少し待ってから、もう一度お試しください。';
        if ($status >= 500) return '現在サーバー側で処理が混み合っています。時間をおいて再度お試しください。';
        if ($status >= 400) return '入力内容を確認して、もう一度お試しください。';
        return 'しばらく待ってから、もう一度お試しください。';
    };

    $body = file_get_contents('php://input') ?: '';
    $input = json_decode($body, true);
    if ($body !== '' && !is_array($input) && json_last_error() !== JSON_ERROR_NONE) {
        $respondFriendlyError(400, 'REQUEST_JSON_INVALID', '入力データの形式に問題があります。ページを再読み込みして、もう一度お試しください。');
    }
    $prompt = is_array($input) ? ($input['prompt'] ?? '') : '';
    $meta = is_array($input) ? ($input['consultation'] ?? []) : [];
    $dbDsn = "mysql:host=" . $Compass_DB_Host . ";dbname=" . $Compass_DB_Name . ";charset=utf8mb4";
    $pdo = new PDO($dbDsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS compass_settings (id TINYINT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compass_usage_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compass_error_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(100) NULL, message TEXT NOT NULL, detail TEXT NULL, request_body MEDIUMTEXT NULL, response_body MEDIUMTEXT NULL, http_status INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compass_consultations (id BIGINT AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(100) NULL, consultation_type VARCHAR(20) NOT NULL, input_text MEDIUMTEXT NOT NULL, extra_text MEDIUMTEXT NULL, prompt_text MEDIUMTEXT NULL, response_json MEDIUMTEXT NULL, pulse_rate INT NULL, level_badge VARCHAR(255) NULL, shared TINYINT(1) NOT NULL DEFAULT 0, share_token VARCHAR(64) NULL, shared_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_share_token (share_token), INDEX idx_device_id (device_id), INDEX idx_created_at (created_at))");
    $pdo->exec("INSERT INTO compass_settings (id, model_name) VALUES (1, 'models/gemini-2.5-flash') ON DUPLICATE KEY UPDATE id=id");
    $model = $pdo->query("SELECT model_name FROM compass_settings WHERE id=1")->fetchColumn() ?: 'models/gemini-2.5-flash';

    if (empty($Gemini_API_Key)) {
        $logError($pdo, $model, 'Gemini_API_Key が未設定です', null, $body, null, 500);
        $respondFriendlyError(500, 'SERVICE_CONFIG_ERROR', '現在システム設定を確認中です。少し時間をおいて、もう一度お試しください。');
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/' . $model . ':generateContent?key=' . rawurlencode($Gemini_API_Key);
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $logError($pdo, $model, 'APIリクエスト失敗', $curlErr, $body, null, 502);
        $respondFriendlyError(502, 'API_REQUEST_FAILED', '通信が不安定な可能性があります。通信環境を確認して、もう一度お試しください。');
    }

    if ($httpCode >= 400) {
        $logError($pdo, $model, 'Gemini APIエラー', null, $body, $response, $httpCode);
        $respondFriendlyError($httpCode, 'UPSTREAM_API_ERROR', $friendlySolutionByStatus($httpCode));
    }
    $ins = $pdo->prepare("INSERT INTO compass_usage_logs (model_name) VALUES (:model)");
    $ins->execute(['model' => $model]);
    $parsed = json_decode($response, true);
    if (!is_array($parsed)) {
        $logError($pdo, $model, 'APIレスポンスJSON解析失敗', json_last_error_msg(), $body, $response, 502);
        $respondFriendlyError(502, 'RESPONSE_JSON_INVALID', '一時的に結果の受け取りに失敗しました。少し待ってから再度お試しください。');
    }
    $resultJson = [];
    if (isset($parsed['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = (string)$parsed['candidates'][0]['content']['parts'][0]['text'];
        $rawText = preg_replace('/^```json\s*/i', '', $rawText ?? '');
        $rawText = preg_replace('/```\s*$/', '', $rawText ?? '');
        $resultJson = json_decode(trim((string)$rawText), true);
        if (!is_array($resultJson)) {
            $logError($pdo, $model, 'モデル出力JSON解析失敗', json_last_error_msg(), $body, $rawText, 502);
            $respondFriendlyError(502, 'MODEL_OUTPUT_JSON_INVALID', '結果の整形に失敗しました。しばらく待って、もう一度お試しください。');
        }
    } else {
        $logError($pdo, $model, 'APIレスポンス形式不正', 'candidates[0].content.parts[0].text が見つかりません', $body, $response, 502);
        $respondFriendlyError(502, 'API_RESPONSE_FORMAT_INVALID', '結果データの形式に問題がありました。少し時間をおいて再度お試しください。');
    }
    $snapshot = is_array($meta) ? ($meta['snapshot'] ?? []) : [];
    $item = array_merge(is_array($snapshot) ? $snapshot : [], is_array($resultJson) ? $resultJson : []);
    $item['input'] = is_array($meta) ? (string)($meta['input'] ?? '') : '';
    $item['extra'] = is_array($meta) ? (string)($meta['extra'] ?? '') : '';
    $item['isLine'] = is_array($meta) ? ((string)($meta['type'] ?? 'line') === 'line') : true;
    $insConsult = $pdo->prepare("INSERT INTO compass_consultations (device_id, consultation_type, input_text, extra_text, prompt_text, response_json) VALUES (:device_id,:type,:input_text,:extra_text,:prompt_text,:response_json)");
    $insConsult->execute([
        'device_id' => is_array($meta) ? (string)($meta['deviceId'] ?? '') : '',
        'type' => is_array($meta) ? (string)($meta['type'] ?? 'line') : 'line',
        'input_text' => is_array($meta) ? (string)($meta['input'] ?? '') : '',
        'extra_text' => is_array($meta) ? (string)($meta['extra'] ?? '') : '',
        'prompt_text' => $prompt,
        'response_json' => json_encode($item, JSON_UNESCAPED_UNICODE),
    ]);
    $consultationId = (int)$pdo->lastInsertId();
    $parsed['consultationId'] = $consultationId;
    http_response_code(200);
    echo json_encode($parsed, JSON_UNESCAPED_UNICODE);
    exit;
}

$isSharedMode = isset($sharedItem) || isset($sharedNotFound) || isset($sharedDisabled);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Compass — 気持ちインサイト</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="compass.css">
</head>
<body class="view-mobile<?= $isSharedMode ? ' shared-mode' : '' ?>">

<main>
  <!-- ── Sticky Header ── -->
  <header id="main-header">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
          <path d="M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M16.3 7.7l-2.1 2.1M7.7 16.3l-2.1 2.1"/>
        </svg>
      </div>
      <div class="logo-text">
        <h1>Compass</h1>
        <p>気持ちインサイト</p>
      </div>
    </div>
    <div class="header-actions">
      <button id="btn-back" class="btn-icon btn-back-icon hidden" aria-label="戻る">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>
      <button id="btn-view-toggle" class="btn-icon" aria-label="表示切り替え">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="3" width="20" height="14" rx="2"/>
          <polyline points="8 21 12 17 16 21"/>
        </svg>
        PC表示
      </button>
      <button id="btn-settings" class="btn-icon" aria-label="設定">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
        </svg>
      </button>
    </div>
  </header>

  <!-- ── Sticky Tab Bar (non-shared) ── -->
  <?php if (!$isSharedMode): ?>
  <div class="sticky-tab-wrap" id="sticky-tab-wrap">
    <div class="segment-wrap">
      <button id="tab-line" class="seg-btn active">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        メッセージ
      </button>
      <button id="tab-sit" class="seg-btn">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        言動・状況
      </button>
      <button id="tab-hist" class="seg-btn">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        履歴
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Page Wrap ── -->
  <div class="page-wrap">

    <!-- ── 2カラム対応ラップ ── -->
    <div id="pc-layout">

      <?php if ($isSharedMode): ?>
      <!-- Shared mode: no left column forms -->
      <div class="pc-left pc-left-shared">
        <!-- shared mode left placeholder (desktop: shows shared info) -->
        <div class="shared-info-panel glass-card section-gap">
          <div class="shared-info-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
              <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
              <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
            </svg>
          </div>
          <p class="shared-info-title">共有された分析レポート</p>
          <p class="shared-info-sub">このページは共有URLでアクセスされています</p>
        </div>
      </div>
      <?php else: ?>
      <div class="pc-left">
        <!-- Segment (desktop only inside pc-left) -->
        <div class="segment-wrap section-gap desktop-tab-wrap" id="desktop-tab-wrap">
          <button id="tab-line-d" class="seg-btn active">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            メッセージ
          </button>
          <button id="tab-sit-d" class="seg-btn">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            言動・状況
          </button>
          <button id="tab-hist-d" class="seg-btn">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            履歴
          </button>
        </div>

        <!-- ── LINEフォーム ── -->
        <div id="form-line">
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">1</span> 相手との関係って？</div>
            <div class="chip-grid" id="rel-group">
              <button class="chip" data-val="気になってる人（まだ話したことほとんどない）">気になってる人</button>
              <button class="chip" data-val="SNSやアプリで知り合った">SNS・アプリで知り合った</button>
              <button class="chip selected" data-val="何回か遊んだことある">何回か遊んだことある</button>
              <button class="chip" data-val="付き合ってる彼氏・彼女">今の彼氏・彼女</button>
            </div>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">2</span> 会ってる感じ・メッセージの特徴</div>
            <div style="margin-bottom:18px">
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">これまで会ったのは？</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels-4">
                  <span>まだない</span><span>数回</span><span>何度も</span><span>いつも一緒</span>
                </div>
                <input type="range" id="meet-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="meet-val">数回（2〜5回くらい）</div>
              </div>
            </div>
            <div style="margin-bottom:18px">
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">返信スピードは？</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels">
                  <span>数日かかる</span><span>数時間</span><span>即レス</span>
                </div>
                <input type="range" id="speed-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="speed-val">3</div>
              </div>
            </div>
            <div>
              <div class="number-row" style="margin-bottom:10px">
                <span class="number-label">メッセージ、普段と比べてどう感じる？</span>
              </div>
              <div class="chip-grid" id="mood-group">
                <button class="chip" data-val="なんか冷たい・そっけない気がする">なんか冷たい？</button>
                <button class="chip selected" data-val="いつも通りな感じ">いつも通り</button>
                <button class="chip" data-val="なんかいつもより丁寧・やさしい気がする">いつもより丁寧</button>
                <button class="chip" data-val="明らかにすごく積極的・テンション高め">すごく積極的！</button>
                <button class="chip" data-val="わからない">わからない</button>
              </div>
            </div>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">3</span> 気になるメッセージの内容</div>
            <div class="char-row">
              <p class="hint">相手の返答・会話の流れなどを詳しく！（最大50000字）</p>
              <span class="char-count" id="line-count">0/50000</span>
            </div>
            <textarea id="line-input" rows="5" maxlength="50000" placeholder="例：「来週末暇？」って聞いたら「まだわかんないや〜」とだけ返ってきた。最近なんか返信が短い気がして…"></textarea>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">4</span> 他に気になることがあれば（任意）</div>
            <div class="char-row">
              <p class="hint">「最近体育祭が終わったばかり」「同じクラス」など背景情報があれば</p>
              <span class="char-count" id="line-extra-count">0/50000</span>
            </div>
            <textarea id="line-extra-input" rows="3" maxlength="50000" placeholder="例：同じ部活で毎日会う　/ 文化祭で一緒のクラスの出し物を頑張った　など"></textarea>
          </div>

          <div class="btn-submit-wrap section-gap" id="submit-wrap">
            <button id="btn-submit" class="btn-submit">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
              <span>気持ちを分析する</span>
            </button>
          </div>
        </div>

        <!-- ── 言動フォーム ── -->
        <div id="form-sit" class="hidden">
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">1</span> どんな場面だった？</div>
            <div class="chip-grid" id="scene-group">
              <button class="chip selected-purple" data-val="二人きりで遊んでいた">二人きりで遊んでた</button>
              <button class="chip" data-val="学校・授業・休み時間">学校・授業・休み時間</button>
              <button class="chip" data-val="部活・委員会・文化祭などの活動中">部活・委員会・行事</button>
              <button class="chip" data-val="グループでの遊び・カラオケ・集まり">グループで遊んでた</button>
              <button class="chip" data-val="電話・ビデオ通話中">電話・ビデオ通話</button>
              <button class="chip" data-val="その他">その他</button>
            </div>
            <input id="scene-custom" class="custom-chip-input hidden" type="text" placeholder="場面を自由入力">
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">2</span> 時間・雰囲気はどんな感じ？</div>
            <div style="margin-bottom:18px">
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">一緒にいた時間</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels"><span>少しの間</span><span>半日</span><span>一日中</span></div>
                <input type="range" id="duration-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="duration-val">60分</div>
              </div>
            </div>
            <div>
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">その場のノリ・テンション</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels"><span>かなり低め</span><span>普通</span><span>かなり高め</span></div>
                <input type="range" id="tension-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="tension-val">3</div>
              </div>
            </div>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">3</span> そのときの相手の様子は？</div>
            <div class="chip-grid" id="attitude-group">
              <button class="chip" data-val="すごく楽しそうで笑顔が多かった">楽しそう・笑顔</button>
              <button class="chip selected-purple" data-val="やさしいけどなんか緊張してる感じがした">やさしいけど緊張してた</button>
              <button class="chip" data-val="いつも通り普通だった">いつも通り</button>
              <button class="chip" data-val="なんかよそよそしい・そっけなかった">そっけなかった</button>
              <button class="chip" data-val="わからない">わからない</button>
            </div>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">4</span> 気になった言動・セリフ</div>
            <div class="char-row">
              <p class="hint">具体的なセリフや行動を教えて！（最大50000字）</p>
              <span class="char-count" id="sit-count">0/50000</span>
            </div>
            <textarea id="sit-input" rows="5" maxlength="50000" placeholder="例：「最近どう？」って聞いたら「まあまあかな〜、〇〇は？」って返してくれて、ずっと私の話を聞いてくれた。帰り際に「また一緒に帰ろ」って言ってくれた。"></textarea>
          </div>

          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">5</span> 他に気になることがあれば（任意）</div>
            <div class="char-row">
              <p class="hint">関係の背景や最近の変化など何でも</p>
              <span class="char-count" id="sit-extra-count">0/50000</span>
            </div>
            <textarea id="sit-extra-input" rows="3" maxlength="50000" placeholder="例：告白されたことがある　/ 最近LINEの返信が早くなった　など"></textarea>
          </div>

          <div class="btn-submit-wrap section-gap" id="submit-wrap-sit">
            <button class="btn-submit" id="btn-submit-sit">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
              <span>気持ちを分析する</span>
            </button>
          </div>
        </div>

        <!-- ── 履歴タブ ── -->
        <div id="form-hist" class="hidden">
          <div class="glass-card section-gap history-panel-card">
            <div class="history-panel-header">
              <span class="history-panel-title">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                過去の相談履歴
              </span>
              <button id="btn-clear" class="btn-clear">すべて消去</button>
            </div>
            <div id="history-list-main">
              <p class="history-empty">まだ履歴はないよ<br>分析するといつでも見返せるよ！</p>
            </div>
          </div>
        </div>

        <!-- ── 入力内容サマリー ── -->
        <div id="input-summary-panel" class="hidden"></div>
      </div>
      <?php endif; ?>

      <!-- ── 右カラム（結果・ローディング） ── -->
      <div class="pc-right">

        <!-- Report Header (spans both columns on PC) -->
        <div id="report-header-bar" class="hidden report-header-bar">
          <span class="result-tag" id="report-context-tag">分析レポート</span>
          <span class="report-source-badge" id="report-source-badge"></span>
          <div style="display:flex; gap:8px; margin-left:auto;">
            <button id="btn-share" class="btn-copy-sm">共有</button>
            <button id="btn-copy" class="btn-copy-sm">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              コピー
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div id="loading-panel" class="hidden section-gap">
          <div class="loading-wrap">
            <div class="loading-panel fade-up">
              <div class="loading-ring">
                <svg viewBox="0 0 64 64">
                  <circle class="loading-ring-track" cx="32" cy="32" r="28" fill="none" stroke-width="4"/>
                  <circle class="loading-ring-fill" cx="32" cy="32" r="28" fill="none" stroke-width="4" transform="rotate(-90 32 32)"/>
                </svg>
              </div>
              <p class="loading-title">分析中...</p>
              <p class="loading-sub" id="loading-sub">入力内容を整理しています...</p>
            </div>
          </div>
        </div>

        <!-- Result -->
        <div id="result-panel" class="hidden">
          <!-- スコアカード -->
          <div class="score-card fade-up section-gap">
            <p class="score-label">脈あり・インタレスト指数</p>
            <div class="score-num" id="res-score">—</div>
            <div class="score-track"><div class="score-fill" id="res-bar" style="width:0%"></div></div>
            <p class="score-badge" id="res-badge">—</p>
          </div>

          <!-- 心理分析 -->
          <div class="detail-card fade-up section-gap" style="animation-delay:0.04s">
            <div class="detail-card-header">
              <div class="detail-icon pink">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
              </div>
              <span class="detail-card-title">本音の分析</span>
              <button id="btn-copy-psych" class="btn-copy-card-sm" aria-label="本音の分析をコピー">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              </button>
            </div>
            <p class="detail-card-body" id="res-psych">—</p>
          </div>

          <!-- アドバイス -->
          <div class="detail-card fade-up section-gap" style="animation-delay:0.08s">
            <div class="detail-card-header">
              <div class="detail-icon purple">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </div>
              <span class="detail-card-title">次の一手アドバイス</span>
              <button id="btn-copy-advice" class="btn-copy-card-sm" aria-label="アドバイスをコピー">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              </button>
            </div>
            <p class="detail-card-body" id="res-advice">—</p>
          </div>

          <!-- 詳細レポート -->
          <div id="advanced-report"></div>

          <!-- 結果の下の履歴 (non-shared only) -->
          <?php if (!$isSharedMode): ?>
          <div class="history-section section-gap">
            <div class="history-header">
              <span class="history-title">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                過去の相談
              </span>
              <button id="btn-clear-result" class="btn-clear">消去</button>
            </div>
            <div id="history-list">
              <p class="history-empty">履歴はないよ</p>
            </div>
          </div>
          <?php endif; ?>
        </div><!-- /result-panel -->

        <!-- Shared error messages -->
        <?php if (isset($sharedNotFound)): ?>
        <div class="glass-card section-gap shared-error-card">
          <div class="shared-error-icon">🔍</div>
          <p class="shared-error-title">共有データが見つかりません</p>
          <p class="shared-error-sub">このURLの共有データは存在しないか、すでに削除されています。</p>
          <a href="index.php" class="btn-submit" style="display:inline-flex;margin-top:16px;text-decoration:none">新しい相談をする</a>
        </div>
        <?php elseif (isset($sharedDisabled)): ?>
        <div class="glass-card section-gap shared-error-card">
          <div class="shared-error-icon">🚫</div>
          <p class="shared-error-title">このURLは管理者によって無効化されました</p>
          <p class="shared-error-sub">このURLの共有は管理者によって無効化されています。</p>
          <a href="index.php" class="btn-submit" style="display:inline-flex;margin-top:16px;text-decoration:none">新しい相談をする</a>
        </div>
        <?php endif; ?>

      </div><!-- /pc-right -->
    </div><!-- /pc-layout -->

  </div><!-- /page-wrap -->

  <!-- ── Shared Mode Bottom Nav ── -->
  <?php if ($isSharedMode): ?>
  <div class="shared-bottom-nav" id="shared-bottom-nav">
    <a href="index.php" class="shared-nav-btn shared-nav-new">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        <line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>
      </svg>
      新しい相談をする
    </a>
    <a href="index.php?tab=hist" class="shared-nav-btn shared-nav-hist">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
      自分の履歴を見る
    </a>
  </div>
  <?php endif; ?>
</main>


<!-- Share Settings Modal -->
<div id="share-modal" class="modal-overlay">
  <div class="modal-sheet share-modal-sheet">
    <div class="modal-handle"></div>
    <p class="modal-title">共有設定</p>
    <p class="modal-sub">モバイル/PC どちらでも使える共有URLを管理できます。</p>
    <div class="share-setting-row">
      <div>
        <p class="field-label">共有設定</p>
        <p class="share-setting-desc">共有URLを有効化/無効化します</p>
      </div>
      <label class="switch">
        <input id="share-enabled" type="checkbox">
        <span class="slider"></span>
      </label>
    </div>
    <button id="btn-share-copy" class="btn-save" type="button">URLをコピー</button>
  </div>
</div>

<!-- Settings Modal -->
<div id="modal" class="modal-overlay">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <p class="modal-title">API 設定</p>
    <p class="modal-sub">分析レポートのトーンやお相手を設定できます</p>

    <div class="modal-field">
      <p class="field-label">レポートの回答トーン</p>
      <select id="tone-select" style="width:100%; margin-bottom: 12px;">
        <option value="優しい">優しく（優しく寄り添うアドバイス）</option>
        <option value="やや優しい">やや優しく（マイルドなアドバイス）</option>
        <option value="普通" selected>普通（標準的なアドバイス）</option>
        <option value="やや厳しめ">やや厳しめ（客観的で現実的なアドバイス）</option>
        <option value="厳しめ">厳しめ（辛口でストレートなアドバイス）</option>
      </select>
    </div>

    <div class="modal-field">
      <p class="field-label">ご相手は？</p>
      <div class="chip-grid" id="partner-group">
        <button class="chip" data-val="彼氏">彼氏</button>
        <button class="chip" data-val="彼女">彼女</button>
        <button class="chip" data-val="好きな人">好きな人</button>
        <button class="chip" data-val="パートナー">パートナー</button>
        <button class="chip" data-val="片思い相手">片思い相手</button>
        <button class="chip" data-val="気になる人">気になる人</button>
        <button class="chip" data-val="友達">友達</button>
        <button class="chip" data-val="その他">その他</button>
      </div>
      <input id="partner-custom" class="custom-chip-input hidden" type="text" placeholder="ご相手を自由入力">
    </div>

    <button id="btn-save" class="btn-save">設定を保存</button>
  </div>
</div>

<script src="compass-report.js"></script>
<script>
window.__sharedItem = <?= isset($sharedItem) ? json_encode($sharedItem, JSON_UNESCAPED_UNICODE) : 'null' ?>;
window.__isSharedMode = <?= $isSharedMode ? 'true' : 'false' ?>;
window.__sharedError = <?= (isset($sharedNotFound) || isset($sharedDisabled)) ? 'true' : 'false' ?>;
window.__initialTab = <?= isset($_GET['tab']) ? json_encode($_GET['tab']) : 'null' ?>;
</script>
<script src="compass.js"></script>
</body>
</html>