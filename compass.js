/* ============================================================
   compass.js  — メインロジック（学生向け版）
   ============================================================ */
'use strict';

/* ─── 会った回数ラベル ─── */
const MEET_LABELS = [
  'まだ会ったことない',
  '数回（2〜5回くらい）',
  '何度も（6〜15回くらい）',
  'いつも一緒なくらい！'
];

/* ─── state ─── */
const state = {
  tab: 'line',
  rel: '何回か遊んだことある',
  replyLen: 3,       // 1〜5
  meetIdx: 1,        // 0〜3
  mood: 'いつも通りな感じ',  // 普段と比べてどう
  scene: '二人きりで遊んでいた',
  attitude: 'やさしいけどなんか緊張してる感じがした',
  duration: 60,
  tension: 3,
  apiKey: localStorage.getItem('c_key') || '',
  model: localStorage.getItem('c_model') || 'demo',
  history: JSON.parse(localStorage.getItem('c_hist') || '[]'),
  viewMode: 'mobile',
};

const $ = id => document.getElementById(id);

/* ─── 初期化 ─── */
updateBanner();
renderHistoryAll();
if (state.apiKey) loadModels(state.apiKey);
detectViewMode();

/* ─── ビュー切り替え ─── */
function detectViewMode() {
  const isWide = window.innerWidth >= 900;
  if (isWide) applyDesktopLayout();
  else applyMobileLayout();
}

$('btn-view-toggle').onclick = () => {
  if (state.viewMode === 'mobile') {
    applyDesktopLayout(true);
  } else {
    applyMobileLayout(true);
  }
};

function applyDesktopLayout(force = false) {
  if (!force && state.viewMode === 'desktop') return;
  state.viewMode = 'desktop';
  document.body.classList.add('view-desktop');
  document.body.classList.remove('view-mobile');
  $('btn-view-toggle').innerHTML = `
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="5" y="2" width="14" height="20" rx="2"/>
      <line x1="12" y1="18" x2="12.01" y2="18"/>
    </svg>
    スマホ表示
  `;
}

function applyMobileLayout(force = false) {
  if (!force && state.viewMode === 'mobile') return;
  state.viewMode = 'mobile';
  document.body.classList.remove('view-desktop');
  document.body.classList.add('view-mobile');
  $('btn-view-toggle').innerHTML = `
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="2" y="3" width="20" height="14" rx="2"/>
      <polyline points="8 21 12 17 16 21"/>
    </svg>
    PC表示
  `;
}

window.addEventListener('resize', () => {
  detectViewMode();
});

/* ─── タブ切り替え（3タブ） ─── */
$('tab-line').onclick = () => switchTab('line');
$('tab-sit').onclick  = () => switchTab('sit');
$('tab-hist').onclick = () => switchTab('hist');

function switchTab(t) {
  state.tab = t;
  ['line', 'sit', 'hist'].forEach(id => {
    $('tab-' + id).classList.toggle('active', id === t);
    $('form-' + id).classList.toggle('hidden', id !== t);
  });

  // 結果パネルとローディングは履歴タブでは隠す
  if (t === 'hist') {
    $('result-panel').classList.add('hidden');
    $('loading-panel').classList.add('hidden');
    $('new-consult-bar').classList.add('hidden');
  }
}

/* ─── チップグループ ─── */
function initChips(groupId, stateKey, colorClass) {
  const grp = $(groupId);
  if (!grp) return;
  grp.querySelectorAll('.chip').forEach(c => {
    c.onclick = () => {
      grp.querySelectorAll('.chip').forEach(x => { x.className = 'chip'; });
      c.className = 'chip ' + colorClass;
      state[stateKey] = c.dataset.val;
    };
  });
}
initChips('rel-group', 'rel', 'selected');
initChips('mood-group', 'mood', 'selected');
initChips('scene-group', 'scene', 'selected-purple');
initChips('attitude-group', 'attitude', 'selected-purple');

/* ─── 会った回数スライダー（4段階） ─── */
const meetSlider = $('meet-slider');
meetSlider.value = state.meetIdx;
$('meet-val').textContent = MEET_LABELS[state.meetIdx];
meetSlider.oninput = () => {
  state.meetIdx = parseInt(meetSlider.value);
  $('meet-val').textContent = MEET_LABELS[state.meetIdx];
};

/* ─── スライダー ─── */
function initSlider(sliderId, valId, stateKey, unit) {
  const el = $(sliderId);
  const vEl = $(valId);
  if (!el) return;
  el.value = state[stateKey];
  vEl.textContent = state[stateKey] + (unit || '');
  el.oninput = () => {
    state[stateKey] = parseInt(el.value);
    vEl.textContent = state[stateKey] + (unit || '');
  };
}
initSlider('speed-slider', 'speed-val', 'replyLen', '');
initSlider('duration-slider', 'duration-val', 'duration', '分');
initSlider('tension-slider', 'tension-val', 'tension', '');

/* ─── 文字数カウンター ─── */
function initCharCount(inputId, countId) {
  const el = $(inputId);
  const cnt = $(countId);
  if (!el || !cnt) return;
  el.oninput = () => { cnt.textContent = el.value.length + '/' + el.maxLength; };
}
initCharCount('line-input', 'line-count');
initCharCount('line-extra-input', 'line-extra-count');
initCharCount('sit-input', 'sit-count');
initCharCount('sit-extra-input', 'sit-extra-count');

/* ─── 設定モーダル ─── */
$('btn-settings').onclick = () => {
  $('key-input').value = state.apiKey;
  $('modal').classList.add('open');
  if (state.apiKey) loadModels(state.apiKey);
};
$('modal').onclick = e => { if (e.target === $('modal')) $('modal').classList.remove('open'); };

$('btn-verify').onclick = () => {
  const k = $('key-input').value.trim();
  if (!k) { setVerifyMsg('APIキーを入力してね', 'err'); return; }
  loadModels(k);
};

async function loadModels(key) {
  setVerifyMsg('接続中…', '');
  try {
    const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${key}`);
    if (!r.ok) throw new Error('Status ' + r.status);
    const d = await r.json();
    const models = d.models.filter(m =>
      m.supportedGenerationMethods.includes('generateContent') &&
      !m.name.includes('embedding')
    );
    if (!models.length) throw new Error('使えるモデルがないよ');
    const sel = $('model-select');
    sel.innerHTML = '';
    models.forEach(m => {
      const o = document.createElement('option');
      o.value = m.name;
      let label = m.displayName || m.name.replace('models/', '');
      if (m.name.includes('2.5-flash')) label += ' ★おすすめ';
      o.textContent = label;
      if (m.name === state.model) o.selected = true;
      sel.appendChild(o);
    });
    if (!sel.value && models.length) sel.value = models[0].name;
    setVerifyMsg('✓ 接続成功！' + models.length + ' モデルを取得したよ', 'ok');
  } catch (e) {
    setVerifyMsg('接続失敗：' + e.message, 'err');
    $('model-select').innerHTML = `
      <option value="models/gemini-2.5-flash">Gemini 2.5 Flash</option>
      <option value="models/gemini-2.0-flash">Gemini 2.0 Flash</option>
      <option value="demo">デモモード</option>`;
  }
}

function setVerifyMsg(msg, type) {
  const el = $('verify-msg');
  el.className = 'verify-msg' + (type ? ' ' + type : '');
  el.textContent = msg;
}

$('btn-save').onclick = () => {
  state.apiKey = $('key-input').value.trim();
  state.model = $('model-select').value;
  localStorage.setItem('c_key', state.apiKey);
  localStorage.setItem('c_model', state.model);
  $('modal').classList.remove('open');
  updateBanner();
};

function updateBanner() {
  const active = state.apiKey && state.model !== 'demo';
  $('api-banner').classList.toggle('hidden', active);
  $('badge-on').style.display = active ? 'block' : 'none';
}

/* ─── 送信処理 ─── */
function getFormData() {
  const isLine = state.tab === 'line';
  const mainInput = isLine ? $('line-input').value.trim() : $('sit-input').value.trim();
  const extraInput = isLine ? $('line-extra-input').value.trim() : $('sit-extra-input').value.trim();
  return { isLine, mainInput, extraInput };
}

async function doSubmit() {
  const { isLine, mainInput, extraInput } = getFormData();
  if (!mainInput) { alert('気になった内容を入力してね！'); return; }

  // フォームを隠す
  $('form-line').classList.add('hidden');
  $('form-sit').classList.add('hidden');
  $('form-hist').classList.add('hidden');
  $('submit-wrap').classList.add('hidden');
  $('submit-wrap-sit').classList.add('hidden');
  $('result-panel').classList.add('hidden');
  $('new-consult-bar').classList.add('hidden');
  ['tab-line', 'tab-sit', 'tab-hist'].forEach(id => $(id).classList.remove('active'));

  $('loading-panel').classList.remove('hidden');
  $('loading-model').textContent = 'モデル: ' + (state.model === 'demo' ? 'デモモード' : state.model.replace('models/', ''));

  const msgs = [
    'メッセージのパターンを読み解いてるよ♡',
    '心理学指標に照合してるよ...',
    '行動データを分析してるよ♪',
    'レポートを作成してるよ...'
  ];
  let idx = 0;
  const t = setInterval(() => { $('loading-sub').textContent = msgs[idx++ % msgs.length]; }, 1500);

  try {
    let data;
    if (state.apiKey && state.model !== 'demo') {
      data = await callAPI(mainInput, extraInput, isLine);
    } else {
      await new Promise(r => setTimeout(r, 2200));
      data = demoResult(mainInput);
    }
    saveHistory(data, mainInput, extraInput, isLine);
    showResult(data);
  } catch (e) {
    alert('エラーが起きたよ: ' + e.message);
    resetView();
  } finally {
    clearInterval(t);
    $('loading-panel').classList.add('hidden');
  }
}

$('btn-submit').onclick = doSubmit;
$('btn-submit-sit').onclick = doSubmit;

/* ─── 新しい相談ボタン ─── */
$('btn-new-consult').onclick = resetView;

/* ─── API呼び出し ─── */
async function callAPI(text, extra, isLine) {
  const speedLabel = ['数日かかる', '1日くらいかかる', '数時間', '1時間以内', 'ほぼ即レス'][state.replyLen - 1];
  const tensionLabel = ['かなり低め', 'やや低め', '普通', 'やや高め', 'かなり高め'][state.tension - 1];

  const ctx = isLine
    ? `【相手との関係性】${state.rel}
【これまで会った回数】${MEET_LABELS[state.meetIdx]}
【返信スピード】${speedLabel}
【メッセージの雰囲気（普段との比較）】${state.mood}
【気になるメッセージの内容】${text}
${extra ? '【追加情報・背景】' + extra : ''}`
    : `【シチュエーション】${state.scene}
【一緒にいた時間】約${state.duration}分
【相手の態度・様子】${state.attitude}
【その場のテンション感】${tensionLabel}
【気になった言動・セリフ】${text}
${extra ? '【追加情報・背景】' + extra : ''}`;

  const prompt = `あなたは中高生・大学生向けの恋愛・恋心アドバイザーです。青春の恋愛について、心理学に基づいた分析レポートを日本語で生成してください。

【重要ルール】
- ユーザーは中学生・高校生・大学生（10代〜20代前半）です。学校・部活・SNSなどの青春環境を踏まえてください。
- 入力内容が意味をなさない文字列の場合、pulseRateを0にし、psychology欄に「もう少し具体的に書いてみてね！」と記載してください。
- 恋愛を応援する温かいトーンで、難しい言葉は使わず、友達に相談するような自然な言葉遣いにしてください。

${ctx}

以下のJSONのみを厳密に返してください（マークダウンなし、コードブロックなし）：

{
  "pulseRate": 0〜100の整数（脈あり・好意指数）,
  "levelBadge": "1文の評価（例：かなり意識してるかも♡　／　友達以上な気がする！）",
  "psychology": "本音・心理の分析（3〜4行、中高大生向けの言葉で、カジュアルに）",
  "advice": "具体的アドバイス（3〜4行、青春っぽく背中を押すような内容で）",
  "radar": {
    "intimacy": 0〜100（親密性：自己開示・話しかけ頻度・共感度から算出）,
    "passion": 0〜100（ときめき・設定：テンションの高さ・笑顔・サプライズから算出）,
    "commitment": 0〜100（誠実さ：約束を守る・丁寧な対応・態度から算出）,
    "status": 0〜100（承認欲求：自分を良く見せようとする行動から算出）,
    "safety": 0〜100（心のゆとり：リラックスできてる度・自然体かどうかから算出）
  },
  "radarInterpretation": "レーダーから読み取れる関係の特徴を1〜2文、中高大生向けの言葉で",
  "matrix": {
    "x": -100〜100（Valence：ネガ=-100, ポジ=+100）,
    "y": -100〜100（Activation：低=-100, 高=+100）
  },
  "matrixInterpretation": "今のテンション状態とアドバイスを1〜2文、友達に話すような感じで",
  "lang": {
    "selfDisclosure": 0〜100（自己開示率%：自分のことを話してくれてる度合い）,
    "mirroring": 0〜100（ミラーリング同調率%：あなたに合わせてくれてる度合い）,
    "pronounCount": 0〜20（「俺たち」「一緒に」などの二人称・一緒系ワード出現数）,
    "emojiSync": 0〜100（絵文字・スタンプのノリが合ってる度合い%）
  },
  "langInterpretation": "メッセージから読み取れる距離感の変化を1〜2文、砕けた言葉で",
  "approaches": [
    {
      "law": "ツァイガルニク効果",
      "title": "アクションのタイトル（10〜20文字）",
      "body": "なぜ有効か・どう使うかの説明（2〜3文、学生でもわかりやすく）",
      "example": "具体的なメッセージ例（1文、10代〜20代が自然に使えるLINE文）"
    },
    {
      "law": "ゲイン・ロス効果 / アンダードッグ効果 / 単純接触効果 など",
      "title": "...",
      "body": "...",
      "example": "..."
    }
  ]
}`;

  let delay = 1000;
  for (let i = 0; i < 2; i++) {
    const r = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/${state.model}:generateContent?key=${state.apiKey}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          contents: [{ parts: [{ text: prompt }] }],
          generationConfig: { responseMimeType: 'application/json' }
        })
      }
    );
    if (r.status === 429) { await new Promise(x => setTimeout(x, delay)); delay *= 2; continue; }
    if (!r.ok) throw new Error('API error ' + r.status);
    const d = await r.json();
    let raw = d.candidates[0].content.parts[0].text.trim();
    raw = raw.replace(/^```json\s*/i, '').replace(/```\s*$/, '').trim();
    const parsed = JSON.parse(raw);
    parsed.usedModel = state.model.replace('models/', '');
    return parsed;
  }
  throw new Error('リトライ失敗');
}

/* ─── デモ結果 ─── */
function demoResult(text) {
  const hi = ['好き', '楽しみ', 'かわいい', '空いてる', 'ご飯', '一緒', '遊ぼ', '会いたい'].some(w => text.includes(w));
  const lo = ['忙しい', '無理', '既読', 'ごめん', '遅い'].some(w => text.includes(w));

  if (hi && !lo) return {
    pulseRate: 84,
    levelBadge: 'かなり意識してるかも♡！',
    psychology: '相手はあなたのことをかなり気にしているよ！積極的に話しかけたり、あなたのことを気にかける言動がたくさん見られる。このまま自然に仲を深めていける感じがするね！',
    advice: '相手の好意に素直に反応してOK♪「一緒にいると楽しい」ってことを自然に伝えてみよう。次の放課後や週末に「一緒にどこか行かない？」って誘うのも今がチャンスかも！',
    radar: { intimacy: 78, passion: 82, commitment: 71, status: 55, safety: 74 },
    radarInterpretation: '親密性・ときめきともに高い！誠実さもしっかりあるから、関係を一歩進めやすい状態だよ。',
    matrix: { x: 65, y: 50 },
    matrixInterpretation: '高揚・追いかけモード！あなたに会いたい気持ちが強くて、積極的に動きたい状態みたい。このノリに乗ってアプローチしてみよう！',
    lang: { selfDisclosure: 62, mirroring: 71, pronounCount: 7, emojiSync: 68 },
    langInterpretation: '自分のことをよく話してくれてる＝心を開いてるサイン。絵文字のノリも合ってて、一緒にいるの楽しんでるって伝わってくるよ！',
    approaches: [
      {
        law: 'ゲイン・ロス効果（ギャップ萌え）',
        title: 'たまにそっけなくしてドキドキさせる',
        body: '今は好感度が高いから、あなたの反応を予測してるかも。たまに短い返信にしてみると「あれ？」って気にしてくれて追いかけてくれる気持ちが生まれるよ。',
        example: '「今ちょっと忙しい〜またね！」って短く切り上げてみると効果的かも'
      },
      {
        law: 'ツァイガルニク効果',
        title: '話を途中で切って「続き気になる」状態を作る',
        body: '話を完結させずに「続きは今度！」ってするだけで、相手の頭の中にあなたが残り続けるんだ。次に会う理由も自然に作れるよ。',
        example: '「実はちょっとびっくりすることがあって〜長くなるから今度話す！笑」'
      }
    ],
    usedModel: 'Demo'
  };

  if (lo) return {
    pulseRate: 28,
    levelBadge: '今は少し距離がある感じかも',
    psychology: '相手は今、部活や勉強で忙しいか、気持ちを整理中かもしれないよ。そっけなさは必ずしもあなたのことが嫌いなわけじゃなくて、今の自分のペースを守りたいサインの可能性もある。',
    advice: '焦らずに数日こちらからの連絡をちょっとお休みしてみよう。引いてみると「あれ、なんで来ないんだろ」って気にしてくれることもあるよ。自分の時間も楽しみながら待ってみて！',
    radar: { intimacy: 35, passion: 32, commitment: 48, status: 40, safety: 28 },
    radarInterpretation: '全体的に低め。でも誠実さだけはまだあるから、相手自身が余裕をなくしてる可能性が高いかも。',
    matrix: { x: -20, y: -55 },
    matrixInterpretation: '停滞・お疲れモード。恋愛に使えるエネルギーが少ない状態みたい。回復を待ってあげるのが一番の戦略かも。',
    lang: { selfDisclosure: 18, mirroring: 25, pronounCount: 1, emojiSync: 15 },
    langInterpretation: '自分のことをあまり話してくれてない。絵文字のノリも合わなくなってきてる感じがする。少し距離を感じる状態かな。',
    approaches: [
      {
        law: 'アンダードッグ効果',
        title: '少し弱いところを見せて守りたいと思わせる',
        body: '距離が開いているとき、ちょっとした困りごとを相談すると相手の「助けてあげたい」気持ちが動くことがあるよ。完璧じゃなくていいんだ。',
        example: '「最近なんか疲れちゃって笑、〇〇ならこういうときどうする？」'
      },
      {
        law: '単純接触効果（ザイアンス効果）',
        title: '返信を求めないスタンプ1個作戦',
        body: '文章より心理的なハードルが低いスタンプや短い反応だけを送ることで、相手の警戒心を下げながらも存在を気にしてもらえるよ。',
        example: 'おもしろいスタンプ1個だけ送って、返事を期待しない姿勢で'
      }
    ],
    usedModel: 'Demo'
  };

  return {
    pulseRate: 56,
    levelBadge: '友達以上の好感あり — これから期待できる！',
    psychology: '今は「話しやすくて好きな人」ポジションにいる感じ。悪い印象はゼロで、これから恋愛に発展できる余地はたっぷりあるよ！焦らなくて大丈夫！',
    advice: '共通の趣味や相手が好きな話題を見つけて、相手が自分から話してくれるきっかけを作ってみよう。放課後や部活後に「一緒に帰ろ」って誘うくらいのカジュアルな距離詰めがいいかも！',
    radar: { intimacy: 55, passion: 52, commitment: 60, status: 48, safety: 62 },
    radarInterpretation: '誠実さと心のゆとりは高め。一緒にいて安心できる関係の土台はある。ときめき・親密性をもう少し上げるとぐっと近づけるかも！',
    matrix: { x: 30, y: -10 },
    matrixInterpretation: '安心・リラックスモード。居心地は良いけどまだドキドキ感が少ないかも。ちょっとしたサプライズで恋愛モードに切り替えられる！',
    lang: { selfDisclosure: 42, mirroring: 48, pronounCount: 4, emojiSync: 44 },
    langInterpretation: '自己開示もミラーリングも中くらい。気を許してるけどまだ恋愛的な意識は薄めかな。「二人でどこか行きたい」系の話を振ると反応が変わるかも！',
    approaches: [
      {
        law: 'ツァイガルニク効果',
        title: '話を途中で切って「続き」を楽しみにさせる',
        body: '話を完結させずに「続きは次に会ったとき！」ってすることで、次に会う理由を自然に作れて、相手があなたのことを考える時間が生まれるよ。',
        example: '「それ気になりすぎる！笑 続きは今度直接話して絶対！」'
      },
      {
        law: '吊り橋効果',
        title: '一緒にちょっとドキドキする体験をしてみる',
        body: '安心ポジションから抜け出すには、同じドキドキ体験が効果的。謎解きや絶叫系、お化け屋敷など少しスリルがある場所でのデートを提案してみよう！',
        example: '「謎解きとか好き？笑 一緒に行ってみたいんだけどどう？」'
      }
    ],
    usedModel: 'Demo'
  };
}

/* ─── 結果表示 ─── */
function showResult(data) {
  $('res-score').textContent = data.pulseRate + '%';
  $('res-bar').style.width = data.pulseRate + '%';
  $('res-badge').textContent = data.levelBadge;
  $('res-psych').textContent = data.psychology;
  $('res-advice').textContent = data.advice;
  $('res-model').textContent = data.usedModel || 'Demo';

  const score = data.pulseRate;
  const bar = $('res-bar');
  if (score >= 70) { $('res-score').style.color = 'var(--accent)'; bar.style.background = 'var(--accent)'; }
  else if (score >= 40) { $('res-score').style.color = 'var(--accent3)'; bar.style.background = 'var(--accent3)'; }
  else { $('res-score').style.color = 'var(--accent2)'; bar.style.background = 'var(--accent2)'; }

  $('result-panel').classList.remove('hidden');

  // 詳細レポート
  const advContainer = $('advanced-report');
  if (advContainer && typeof renderAdvancedReport !== 'undefined') {
    renderAdvancedReport(data, advContainer);
  }

  // 「新しい相談」バー表示
  $('new-consult-bar').classList.remove('hidden');

  // スクロール
  setTimeout(() => {
    $('result-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 100);
}

/* ─── リセット ─── */
function resetView() {
  $('result-panel').classList.add('hidden');
  $('new-consult-bar').classList.add('hidden');

  // タブを復元（lineまたはsit）
  const t = state.tab === 'hist' ? 'line' : state.tab;
  state.tab = t;
  ['line', 'sit', 'hist'].forEach(id => {
    $('tab-' + id).classList.toggle('active', id === t);
    $('form-' + id).classList.toggle('hidden', id !== t);
  });

  if (t === 'line') {
    $('submit-wrap').classList.remove('hidden');
    $('line-input').value = '';
    $('line-count').textContent = '0/500';
    $('line-extra-input').value = '';
    $('line-extra-count').textContent = '0/200';
  } else {
    $('submit-wrap-sit').classList.remove('hidden');
    $('sit-input').value = '';
    $('sit-count').textContent = '0/500';
    $('sit-extra-input').value = '';
    $('sit-extra-count').textContent = '0/200';
  }

  // スクロールトップ
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ─── コピー ─── */
$('btn-copy').onclick = () => {
  const txt = `[Compass 分析結果]\nモデル: ${$('res-model').textContent}\n指数: ${$('res-score').textContent}（${$('res-badge').textContent}）\n\n◆ 本音の分析:\n${$('res-psych').textContent}\n\n◆ 次の一手:\n${$('res-advice').textContent}`;
  navigator.clipboard.writeText(txt).then(() => {
    $('btn-copy').innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--accent);fill:none;stroke-width:2;"><polyline points="20 6 9 17 4 12"/></svg> コピー完了';
    setTimeout(() => {
      $('btn-copy').innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> コピー';
    }, 2000);
  });
};

/* ─── 履歴 ─── */
function saveHistory(data, input, extra, isLine) {
  state.history.unshift({
    id: Date.now(),
    type: isLine ? 'メッセージ' : '言動・状況',
    input,
    extra: extra || '',
    pulseRate: data.pulseRate,
    levelBadge: data.levelBadge,
    psychology: data.psychology,
    advice: data.advice,
    radar: data.radar,
    radarInterpretation: data.radarInterpretation,
    matrix: data.matrix,
    matrixInterpretation: data.matrixInterpretation,
    lang: data.lang,
    langInterpretation: data.langInterpretation,
    approaches: data.approaches,
    usedModel: data.usedModel || 'Demo',
    date: new Date().toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  });
  if (state.history.length > 10) state.history.pop();
  localStorage.setItem('c_hist', JSON.stringify(state.history));
  renderHistoryAll();
}

function createHistoryItem(item) {
  const cls = item.pulseRate >= 70 ? 'history-score-high' : item.pulseRate >= 40 ? 'history-score-mid' : 'history-score-low';
  const div = document.createElement('div');
  div.className = 'history-item';
  div.innerHTML = `
    <div class="history-item-top">
      <span class="history-meta">${item.date} · ${item.type}</span>
      <span class="history-score-badge ${cls}">${item.pulseRate}%</span>
    </div>
    <p class="history-text">「${item.input}」</p>
    ${item.extra ? `<p class="history-input-preview">＋ ${item.extra}</p>` : ''}
    <p class="history-model">モデル: ${item.usedModel}</p>`;
  div.onclick = () => {
    showResult(item);
    // フォームを隠してタブ解除
    $('form-line').classList.add('hidden');
    $('form-sit').classList.add('hidden');
    $('form-hist').classList.add('hidden');
    ['tab-line', 'tab-sit', 'tab-hist'].forEach(id => $(id).classList.remove('active'));
  };
  return div;
}

function renderHistoryAll() {
  // 結果パネル内の履歴
  const el = $('history-list');
  // 履歴タブの履歴
  const el2 = $('history-list-main');

  const emptyMsg = '<p class="history-empty">まだ履歴はないよ✨</p>';
  const emptyMsg2 = '<p class="history-empty">まだ履歴はないよ✨<br>分析するといつでも見返せるよ！</p>';

  if (!state.history.length) {
    if (el) el.innerHTML = emptyMsg;
    if (el2) el2.innerHTML = emptyMsg2;
    return;
  }

  if (el) {
    el.innerHTML = '';
    state.history.forEach(item => el.appendChild(createHistoryItem(item)));
  }
  if (el2) {
    el2.innerHTML = '';
    state.history.forEach(item => el2.appendChild(createHistoryItem(item)));
  }
}

/* 履歴削除（2箇所） */
function clearHistory() {
  if (confirm('履歴をすべて消す？')) {
    state.history = [];
    localStorage.removeItem('c_hist');
    renderHistoryAll();
  }
}
$('btn-clear').onclick = clearHistory;
$('btn-clear-result').onclick = clearHistory;