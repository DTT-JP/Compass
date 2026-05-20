<?php
if (isset($_GET['share']) && $_GET['share'] !== '') {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_GET['share']);
    $envPath = dirname(__DIR__, 2) . '/env/compass.php';
    require_once $envPath;
    $dbDsn = "mysql:host=" . $Compass_DB_Host . ";dbname=" . $Compass_DB_Name . ";charset=utf8mb4";
    $pdo = new PDO($dbDsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT input_text, extra_text, response_json, pulse_rate, level_badge, created_at FROM compass_consultations WHERE share_token = :token AND shared = 1 LIMIT 1");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo '共有データが見つかりません'; exit; }
    $result = json_decode((string)$row['response_json'], true) ?: [];
    ?>
    <!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Compass共有結果</title><link rel="stylesheet" href="compass.css"></head><body class="view-mobile"><main><header><div class="logo-mark"><div class="logo-text"><h1>Compass 共有結果</h1></div></div></header><div class="page-wrap"><div class="glass-card section-gap"><div class="card-label">相談内容</div><p><?= nl2br(htmlspecialchars((string)$row['input_text'], ENT_QUOTES, 'UTF-8')) ?></p><?php if(!empty($row['extra_text'])): ?><p><small><?= nl2br(htmlspecialchars((string)$row['extra_text'], ENT_QUOTES, 'UTF-8')) ?></small></p><?php endif; ?><p class="hint">作成日: <?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></p></div><div class="glass-card section-gap"><div class="card-label">分析結果（<?= (int)($row['pulse_rate'] ?? 0) ?>%）</div><p><strong><?= htmlspecialchars((string)($row['level_badge'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></p><p><?= nl2br(htmlspecialchars((string)($result['psychology'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p><hr><p><?= nl2br(htmlspecialchars((string)($result['advice'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></div></div></main></body></html>
    <?php exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_GET["action"]) && $_GET["action"] === "share") {
    header('Content-Type: application/json; charset=utf-8');
    $envPath = dirname(__DIR__, 2) . '/env/compass.php';
    require_once $envPath;
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $record = $input['record'] ?? [];
    if (!is_array($record) || empty($record['input'])) { http_response_code(400); echo json_encode(['error' => 'invalid record']); exit; }
    $dbDsn = "mysql:host=" . $Compass_DB_Host . ";dbname=" . $Compass_DB_Name . ";charset=utf8mb4";
    $pdo = new PDO($dbDsn, $Compass_DB_User, $Compass_DB_Pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO compass_consultations (device_id, consultation_type, input_text, extra_text, response_json, pulse_rate, level_badge, shared, share_token, shared_at) VALUES (:device_id,:type,:input_text,:extra_text,:response_json,:pulse_rate,:level_badge,1,:share_token,NOW())");
    $stmt->execute(['device_id'=>(string)($input['deviceId'] ?? ''),'type'=>!empty($record['isLine'])?'line':'sit','input_text'=>(string)$record['input'],'extra_text'=>(string)($record['extra'] ?? ''),'response_json'=>json_encode($record, JSON_UNESCAPED_UNICODE),'pulse_rate'=>(int)($record['pulseRate'] ?? 0),'level_badge'=>(string)($record['levelBadge'] ?? ''),'share_token'=>$token]);
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']), '/');
    echo json_encode(['url' => $base . '/index.php?share=' . $token], JSON_UNESCAPED_UNICODE); exit;
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

    $body = file_get_contents('php://input') ?: '';
    $input = json_decode($body, true);
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
        http_response_code(500);
        echo json_encode(['error' => 'Gemini_API_Key が未設定です']);
        exit;
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
        http_response_code(502);
        echo json_encode(['error' => 'APIリクエスト失敗', 'detail' => $curlErr], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($httpCode >= 400) {
        $logError($pdo, $model, 'Gemini APIエラー', null, $body, $response, $httpCode);
    }

    http_response_code($httpCode ?: 200);
    $ins = $pdo->prepare("INSERT INTO compass_usage_logs (model_name) VALUES (:model)");
    $ins->execute(['model' => $model]);
    $parsed = json_decode($response, true);
    $resultText = '';
    if (is_array($parsed) && isset($parsed['candidates'][0]['content']['parts'][0]['text'])) {
        $resultText = (string)$parsed['candidates'][0]['content']['parts'][0]['text'];
    }
    $insConsult = $pdo->prepare("INSERT INTO compass_consultations (device_id, consultation_type, input_text, extra_text, prompt_text, response_json) VALUES (:device_id,:type,:input_text,:extra_text,:prompt_text,:response_json)");
    $insConsult->execute([
        'device_id' => is_array($meta) ? (string)($meta['deviceId'] ?? '') : '',
        'type' => is_array($meta) ? (string)($meta['type'] ?? 'line') : 'line',
        'input_text' => is_array($meta) ? (string)($meta['input'] ?? '') : '',
        'extra_text' => is_array($meta) ? (string)($meta['extra'] ?? '') : '',
        'prompt_text' => $prompt,
        'response_json' => $resultText,
    ]);
    echo $response;
    exit;
}
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
<body class="view-mobile">

<main>
  <!-- ── Header ── -->
  <header>
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

  <!-- ── Page Wrap ── -->
  <div class="page-wrap">

    <!-- Segment (3 tabs) -->
    <div class="segment-wrap section-gap">
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

    <!-- ── 2カラム対応ラップ ── -->
    <div id="pc-layout">
      <div class="pc-left">

        <!-- ── LINEフォーム ── -->
        <div id="form-line">

          <!-- 1. 関係性 -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">1</span> 相手との関係って？</div>
            <div class="chip-grid" id="rel-group">
              <button class="chip" data-val="気になってる人（まだ話したことほとんどない）">気になってる人</button>
              <button class="chip" data-val="SNSやアプリで知り合った">SNS・アプリで知り合った</button>
              <button class="chip selected" data-val="何回か遊んだことある">何回か遊んだことある</button>
              <button class="chip" data-val="付き合ってる彼氏・彼女">今の彼氏・彼女</button>
            </div>
          </div>

          <!-- 2. 会った回数 + 返信スピード + 普段と比べて -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">2</span> 会ってる感じ・メッセージの特徴</div>

            <!-- 会った回数 スライダー4段階 -->
            <div style="margin-bottom:18px">
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">これまで会ったのは？</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels-4">
                  <span>まだない</span>
                  <span>数回</span>
                  <span>何度も</span>
                  <span>いつも一緒</span>
                </div>
                <input type="range" id="meet-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="meet-val">数回（2〜5回くらい）</div>
              </div>
            </div>

            <!-- 返信スピード スライダー -->
            <div style="margin-bottom:18px">
              <div class="number-row" style="margin-bottom:8px">
                <span class="number-label">返信スピードは？</span>
              </div>
              <div class="range-wrap">
                <div class="range-labels">
                  <span>数日かかる</span>
                  <span>数時間</span>
                  <span>即レス</span>
                </div>
                <input type="range" id="speed-slider" min="0" max="100" step="1" value="50">
                <div class="range-val" id="speed-val">3</div>
              </div>
            </div>

            <!-- 普段と比べて -->
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

          <!-- 3. メッセージ内容 -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">3</span> 気になるメッセージの内容</div>
            <div class="char-row">
              <p class="hint">相手の返答・会話の流れなどを詳しく！（最大50000字）</p>
              <span class="char-count" id="line-count">0/50000</span>
            </div>
            <textarea id="line-input" rows="5" maxlength="50000" placeholder="例：「来週末暇？」って聞いたら「まだわかんないや〜」とだけ返ってきた。最近なんか返信が短い気がして…"></textarea>
          </div>

          <!-- 4. 自由入力（追加情報） -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">4</span> 他に気になることがあれば（任意）</div>
            <div class="char-row">
              <p class="hint">「最近体育祭が終わったばかり」「同じクラス」など背景情報があれば</p>
              <span class="char-count" id="line-extra-count">0/50000</span>
            </div>
            <textarea id="line-extra-input" rows="3" maxlength="50000" placeholder="例：同じ部活で毎日会う　/ 文化祭で一緒のクラスの出し物を頑張った　/ LINEのやりとりは最近始まったばかり　など"></textarea>
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

          <!-- 1. シチュエーション -->
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

          <!-- 2. 一緒にいた時間 + テンション -->
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

          <!-- 3. 相手の様子 -->
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

          <!-- 4. 気になった言動 -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">4</span> 気になった言動・セリフ</div>
            <div class="char-row">
              <p class="hint">具体的なセリフや行動を教えて！（最大50000字）</p>
              <span class="char-count" id="sit-count">0/50000</span>
            </div>
            <textarea id="sit-input" rows="5" maxlength="50000" placeholder="例：「最近どう？」って聞いたら「まあまあかな〜、〇〇は？」って返してくれて、ずっと私の話を聞いてくれた。帰り際に「また一緒に帰ろ」って言ってくれた。"></textarea>
          </div>

          <!-- 5. 自由入力（追加情報） -->
          <div class="glass-card section-gap">
            <div class="card-label"><span class="card-label-num">5</span> 他に気になることがあれば（任意）</div>
            <div class="char-row">
              <p class="hint">関係の背景や最近の変化など何でも</p>
              <span class="char-count" id="sit-extra-count">0/50000</span>
            </div>
            <textarea id="sit-extra-input" rows="3" maxlength="50000" placeholder="例：告白されたことがある　/ 最近LINEの返信が早くなった　/ 共通の友達が「好きって言ってたよ」と言っていた　など"></textarea>
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

      </div><!-- /pc-left -->

      <!-- ── 右カラム（結果・ローディング） ── -->
      <div class="pc-right">

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
              <p class="loading-model" id="loading-model">モデル検出中</p>
              <p class="loading-sub" id="loading-sub">「メッセージのパターンを読み解いてるよ」</p>
            </div>
          </div>
        </div>

        <!-- Result -->
        <div id="result-panel" class="hidden">
          <div class="result-header">
            <span class="result-tag">分析レポート</span>
            <div style="display:flex; gap:8px;">
              <button id="btn-share" class="btn-copy-sm">共有URL発行</button>
              <button id="btn-copy" class="btn-copy-sm">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                コピー
              </button>
            </div>
          </div>

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

          <p class="model-line">分析モデル: <span id="res-model">—</span></p>

          <!-- 結果の下の履歴 -->
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
        </div><!-- /result-panel -->

      </div><!-- /pc-right -->
    </div><!-- /pc-layout -->

  </div><!-- /page-wrap -->
</main>

<!-- Settings Modal -->
<div id="modal" class="modal-overlay">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <p class="modal-title">API 設定</p>
    <p class="modal-sub">Gemini APIキーを入力してリアルタイム分析を使ってみよう！</p>

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
<script src="compass.js"></script>
</body>
</html>
