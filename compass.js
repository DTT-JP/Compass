/* ============================================================
   compass.js  — メインロジック
   ============================================================ */
'use strict';

/* ─── state ─── */
const state = {
  tab: 'line',
  rel: '何回かデート済',
  speed: '数時間以内に来る',
  replyLen: 3,       // 1〜5
  emojiFreq: 2,      // 1〜5
  meetCount: 3,      // 会った回数
  scene: '二人でのデート中',
  attitude: '優しいが少し緊張している',
  duration: 60,      // 会った時間（分）
  tension: 3,        // 1〜5
  apiKey: localStorage.getItem('c_key') || '',
  model: localStorage.getItem('c_model') || 'demo',
  history: JSON.parse(localStorage.getItem('c_hist') || '[]'),
  viewMode: 'mobile', // 'mobile' | 'desktop'
};

const $ = id => document.getElementById(id);

/* ─── 初期化 ─── */
updateBanner();
renderHistory();
if (state.apiKey) loadModels(state.apiKey);
detectViewMode();

/* ─── ビュー切り替え ─── */
function detectViewMode() {
  const isWide = window.innerWidth >= 768;
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
  rebuildLayout();
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
  rebuildLayout();
}

function rebuildLayout() {
  const isDesktop = state.viewMode === 'desktop';
  const pcLayout = $('pc-layout');
  if (!pcLayout) return;

  if (isDesktop) {
    pcLayout.classList.add('pc-layout');
    if ($('rel-group')) $('rel-group').classList.add('pc-4col');
  } else {
    pcLayout.classList.remove('pc-layout');
    if ($('rel-group')) $('rel-group').classList.remove('pc-4col');
  }
}

window.addEventListener('resize', () => {
  detectViewMode();
});

/* ─── タブ切り替え ─── */
$('tab-line').onclick = () => switchTab('line');
$('tab-sit').onclick = () => switchTab('sit');

function switchTab(t) {
  state.tab = t;
  $('tab-line').classList.toggle('active', t === 'line');
  $('tab-sit').classList.toggle('active', t === 'sit');
  $('form-line').classList.toggle('hidden', t !== 'line');
  $('form-sit').classList.toggle('hidden', t !== 'sit');
  const resultHidden = $('result-panel').classList.contains('hidden');
  if (resultHidden) {
    const lineWrap = $('submit-wrap');
    const sitWrap = $('submit-wrap-sit');
    if (lineWrap) lineWrap.classList.toggle('hidden', t !== 'line');
    if (sitWrap) sitWrap.classList.toggle('hidden', t !== 'sit');
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
initChips('scene-group', 'scene', 'selected-purple');
initChips('attitude-group', 'attitude', 'selected-purple');

/* ─── ステッパー ─── */
function initStepper(minusId, plusId, valId, stateKey, min, max) {
  const update = () => {
    $(valId).textContent = state[stateKey];
  };
  $(minusId).onclick = () => {
    if (state[stateKey] > min) { state[stateKey]--; update(); }
  };
  $(plusId).onclick = () => {
    if (state[stateKey] < max) { state[stateKey]++; update(); }
  };
  update();
}
initStepper('meet-minus', 'meet-plus', 'meet-val', 'meetCount', 0, 50);

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
initSlider('emoji-slider', 'emoji-val', 'emojiFreq', '');
initSlider('duration-slider', 'duration-val', 'duration', '分');
initSlider('tension-slider', 'tension-val', 'tension', '');

/* ─── 文字数カウンター ─── */
$('line-input').oninput = () => $('line-count').textContent = $('line-input').value.length + '/500';
$('sit-input').oninput = () => $('sit-count').textContent = $('sit-input').value.length + '/500';

/* ─── 設定モーダル ─── */
$('btn-settings').onclick = () => {
  $('key-input').value = state.apiKey;
  $('modal').classList.add('open');
  if (state.apiKey) loadModels(state.apiKey);
};
$('modal').onclick = e => { if (e.target === $('modal')) $('modal').classList.remove('open'); };

$('btn-verify').onclick = () => {
  const k = $('key-input').value.trim();
  if (!k) { setVerifyMsg('APIキーを入力してください', 'err'); return; }
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
    if (!models.length) throw new Error('利用可能モデルなし');
    const sel = $('model-select');
    sel.innerHTML = '';
    models.forEach(m => {
      const o = document.createElement('option');
      o.value = m.name;
      let label = m.displayName || m.name.replace('models/', '');
      if (m.name.includes('2.5-flash')) label += ' ★推奨';
      o.textContent = label;
      if (m.name === state.model) o.selected = true;
      sel.appendChild(o);
    });
    if (!sel.value && models.length) sel.value = models[0].name;
    setVerifyMsg('✓ 接続成功：' + models.length + ' モデルを取得', 'ok');
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

/* ─── 送信 ─── */
$('btn-submit').onclick = async () => {
  const input = state.tab === 'line' ? $('line-input').value.trim() : $('sit-input').value.trim();
  if (!input) { alert('気になった内容を入力してください'); return; }

  ['form-line', 'form-sit', 'submit-wrap', 'result-panel'].forEach(id => $(id).classList.add('hidden'));
  $('loading-panel').classList.remove('hidden');
  $('loading-model').textContent = 'モデル: ' + (state.model === 'demo' ? 'デモモード' : state.model.replace('models/', ''));

  const msgs = ['「言葉のパターンを読み解いています♡」', '「心理学指標に照合中...」', '「行動データを分析しています♪」', '「レポートを生成中です...」'];
  let idx = 0;
  const t = setInterval(() => { $('loading-sub').textContent = msgs[idx++ % msgs.length]; }, 1500);

  try {
    let data;
    if (state.apiKey && state.model !== 'demo') {
      data = await callAPI(input);
    } else {
      await new Promise(r => setTimeout(r, 2200));
      data = demoResult(input);
    }
    saveHistory(data, input);
    showResult(data);
  } catch (e) {
    alert('エラー: ' + e.message);
    resetView();
  } finally {
    clearInterval(t);
    $('loading-panel').classList.add('hidden');
  }
};

/* ─── API呼び出し ─── */
async function callAPI(text) {
  const isLine = state.tab === 'line';

  const speedLabel = ['とても遅い(数日)', '遅め(1日以上)', '普通(数時間)', '早め(1時間以内)', 'ほぼ即レス'][state.replyLen - 1];
  const emojiLabel = ['絵文字なし', 'たまに使う', '普通に使う', '多め', '多用する'][state.emojiFreq - 1];
  const tensionLabel = ['かなり低め', 'やや低め', '普通', 'やや高め', 'かなり高め'][state.tension - 1];

  const ctx = isLine
    ? `【関係性】${state.rel}
【会った回数】約${state.meetCount}回
【返信スピード】${speedLabel}
【絵文字の使用頻度】${emojiLabel}
【チャット内容・気になったメッセージ】${text}`
    : `【シチュエーション】${state.scene}
【会っていた時間】約${state.duration}分
【彼の態度・様子】${state.attitude}
【その場のテンション感】${tensionLabel}
【気になった言動・セリフ】${text}`;

  const prompt = `あなたは女性向けの恋愛・男性心理アドバイザーです。以下の情報をもとに、心理学に基づいた詳細な分析レポートを日本語で生成してください。
  【制約事項】
- 入力情報が意味をなさない文字列（記号の羅列や「あいうえお」等）や、分析に足る具体的なエピソードを含まない場合、pulseRateを0にし、psychology欄に「入力内容が分析不能です。具体的なエピソードを入力してください。」と記載してください。
- 以下の情報をもとに、詳細な分析レポートを生成してください。

${ctx}

以下のJSONのみを厳密に返してください（マークダウンなし、コードブロックなし）：

{
  "pulseRate": 0〜100の整数（脈あり・インタレスト指数）,
  "levelBadge": "1文の評価（例：強い関心あり♡）",
  "psychology": "本音分析（3〜4行）",
  "advice": "具体的アドバイス（3〜4行）",
  "radar": {
    "intimacy": 0〜100（親密性：自己開示・共感頻度から算出）,
    "passion": 0〜100（情熱・執着：返信速度・質問数・嫉妬・気遣いから算出）,
    "commitment": 0〜100（誠実度・コミット：予定調整・丁寧さ・態度から算出）,
    "status": 0〜100（承認欲求：自慢・頑張りアピールから算出）,
    "safety": 0〜100（心理的余裕：リラックス度・ストレスなさから算出）
  },
  "radarInterpretation": "レーダーチャートの形から読み取れる関係性の課題を1〜2文で具体的に",
  "matrix": {
    "x": -100〜100（Valence：ネガ=-100, ポジ=+100）,
    "y": -100〜100（Activation：低=-100, 高=+100）
  },
  "matrixInterpretation": "現在のテンション状態と、そこから読み取れるアプローチのヒントを1〜2文で",
  "lang": {
    "selfDisclosure": 0〜100（自己開示率%）,
    "mirroring": 0〜100（ミラーリング同調率%）,
    "pronounCount": 0〜20（「俺たち」「〇〇ちゃんは？」などの二人称ワード出現数）,
    "emojiSync": 0〜100（絵文字シンク率%）
  },
  "langInterpretation": "言語分析から読み取れる心理的距離の変化を1〜2文で",
  "approaches": [
    {
      "law": "ツァイガルニク効果",
      "title": "アプローチタイトル（10〜20文字）",
      "body": "なぜ有効か・どう使うかの説明（2〜3文）",
      "example": "具体的なメッセージ例（1文、自然な日本語で）"
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
    // JSON抽出（念のため```json...```を除去）
    raw = raw.replace(/^```json\s*/i, '').replace(/```\s*$/, '').trim();
    const parsed = JSON.parse(raw);
    parsed.usedModel = state.model.replace('models/', '');
    return parsed;
  }
  throw new Error('リトライ失敗');
}

/* ─── デモ結果 ─── */
function demoResult(text) {
  const hi = ['デート', '好き', '楽しみ', '可愛い', '空いてる', 'ご飯', '会い', '一緒'].some(w => text.includes(w));
  const lo = ['忙しい', '無理', '既読', 'ごめん', '遅い', '仕事'].some(w => text.includes(w));

  if (hi && !lo) return {
    pulseRate: 84,
    levelBadge: '強い関心あり♡ あなたを意識しています',
    psychology: '彼はあなたに対して強い好奇心と好意を持っています。積極的にコミュニケーションを取りたい気持ちが言葉や行動に滲み出ていますよ。距離を縮めようとするサインが随所に見られます。',
    advice: '彼の関心に素直に応えつつ、「〇〇くんといると楽しいな」など気持ちをやわらかく伝えることで一気に距離が縮まります。次のデートの提案もほぼOKをもらえる状態です♡',
    radar: { intimacy: 78, passion: 82, commitment: 71, status: 55, safety: 74 },
    radarInterpretation: '親密性・情熱ともに高いバランスの良い状態です。誠実度も十分あるため、関係を一歩前に進めやすいフェーズに入っています。',
    matrix: { x: 65, y: 50 },
    matrixInterpretation: '高揚・追いかけモードにいます。あなたに会いたい気持ちが強く、アクティブに行動したい状態。この波に乗ってアプローチを積極化するのがベストです。',
    lang: { selfDisclosure: 62, mirroring: 71, pronounCount: 7, emojiSync: 68 },
    langInterpretation: '自己開示率が高く心を開いている状態。ミラーリングも強いため無意識の好意が数値に現れています。二人称ワードが多いのは「一緒にいる感覚」を楽しんでいる証です。',
    approaches: [
      {
        law: 'ゲイン・ロス効果（ギャップ萌え）',
        title: 'あえて少しそっけなく返してドキドキさせる',
        body: '今は好感度が高い分、あなたの反応を予測しています。たまに素っ気ない返信をすることで「あれ？」と気にさせ、追いかけたい気持ちを刺激できます。',
        example: 'いつもより返信を短くして「今ちょっと忙しい〜またね」で切り上げてみると効果的です'
      },
      {
        law: 'ツァイガルニク効果',
        title: '話を途中で切り上げて執着させる',
        body: '完結した会話より「続き気になる！」という状態が彼の頭の中を占拠します。楽しい話の途中であえて「あ、ごめん続きは今度話す！」と打ち切ると翌日も彼があなたのことを考えます。',
        example: '「実はちょっとびっくりすることあったんだけど〜あ、長くなるから今度！笑」'
      }
    ],
    usedModel: 'Demo'
  };

  if (lo) return {
    pulseRate: 28,
    levelBadge: '今は少し距離を置いているかも',
    psychology: '彼は今、忙しさや心理的な余裕のなさから距離を置いている可能性があります。そっけなさは今の彼自身のペースを守りたいサインかもしれません。',
    advice: '焦らず数日間こちらからの連絡をお休みして彼にスペースをあげましょう。引いてみることで関係がリセットされやすくなります。',
    radar: { intimacy: 35, passion: 32, commitment: 48, status: 40, safety: 28 },
    radarInterpretation: '全体的に低い値ですが、誠実度だけが比較的維持されています。悪意はなく、彼自身が余裕のない状態にあるサインです。',
    matrix: { x: -20, y: -55 },
    matrixInterpretation: '停滞・お疲れモードにいます。エネルギーが低く、恋愛に使えるメンタルリソースが少ない状態です。今は追いかけるより回復を待つ戦略が有効です。',
    lang: { selfDisclosure: 18, mirroring: 25, pronounCount: 1, emojiSync: 15 },
    langInterpretation: '自己開示が少なく心理的な壁を感じています。ミラーリングも弱まっており、今は距離を感じる状態です。二人称ワードがほぼない点も疎遠の兆候です。',
    approaches: [
      {
        law: 'アンダードッグ効果',
        title: '少し弱みを見せて守りたいと思わせる',
        body: '距離が開いている今は、少し「頼りたい」サインを出すことで彼の保護本能を刺激できます。完璧でいようとせず、ちょっとした困りごとを相談してみましょう。',
        example: '「最近なんか疲れちゃって笑、こういう時って〇〇くんならどうする？」'
      },
      {
        law: '単純接触効果（ザイアンス効果）',
        title: '存在を思い出させる軽いスタンプ作戦',
        body: '文章より心理的ハードルが低いスタンプや短い反応だけを送ることで、相手の警戒心を下げながらも存在を認識させ続けることができます。',
        example: '気の利いたスタンプひとつだけ送って返信を期待しない姿勢で'
      }
    ],
    usedModel: 'Demo'
  };

  return {
    pulseRate: 56,
    levelBadge: '友人以上の好感 — これからに期待♪',
    psychology: '現時点では「話しやすくて素敵な人」というポジション。悪い印象はなく、これから恋愛に発展する余地は十分あります！',
    advice: '共通の趣味や、彼が得意な話題を見つけて彼が自分から語りたくなるきっかけを作ると自然に会話が弾みますよ。',
    radar: { intimacy: 55, passion: 52, commitment: 60, status: 48, safety: 62 },
    radarInterpretation: '誠実度と心理的余裕は高く、安定した関係を築きやすい土台があります。情熱と親密性をもう少し高めることで恋愛モードへシフトできます。',
    matrix: { x: 30, y: -10 },
    matrixInterpretation: '安心・リラックスモード寄りの状態です。居心地は良いと感じていますが、まだドキドキ感は少なめ。刺激を加えることで恋愛感情に火をつけられます。',
    lang: { selfDisclosure: 42, mirroring: 48, pronounCount: 4, emojiSync: 44 },
    langInterpretation: '自己開示率・ミラーリング共に中程度で、気を許していますが恋愛的な意識はまだ薄め。二人称ワードを増やすような話題を振ることが次のステップです。',
    approaches: [
      {
        law: 'ツァイガルニク効果',
        title: '話を途中で切り上げて「続き」が気になる状態を作る',
        body: '会話をきれいに終わらせず、「あ、それ面白い！続き今度聞かせて〜」とあえて宙ぶらりんにすることで、次に会う理由と彼があなたを考える時間を作れます。',
        example: '「それすごい気になるけど！笑 続きは次会った時に教えて絶対」'
      },
      {
        law: 'アンダードッグ効果 × 吊り橋効果',
        title: '少し共通の「ドキドキ体験」を作る',
        body: '安心ポジションから抜け出すには、一緒に多少のスリルを味わうことが有効です。絶叫系・謎解き・カラオケなど少しテンションが上がる場所でのデートを提案してみましょう。',
        example: '「怖いの得意？笑 謎解きか絶叫系でどっちか行きたいんだけど一緒に行かない？」'
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

  // スクロール
  setTimeout(() => {
    $('result-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 100);
}

/* ─── リセット ─── */
function resetView() {
  $('result-panel').classList.add('hidden');
  if (state.tab === 'line') {
    $('form-line').classList.remove('hidden');
    $('submit-wrap').classList.remove('hidden');
    $('line-input').value = '';
    $('line-count').textContent = '0/500';
  } else {
    $('form-sit').classList.remove('hidden');
    const sitWrap = $('submit-wrap-sit');
    if (sitWrap) sitWrap.classList.remove('hidden');
    $('sit-input').value = '';
    $('sit-count').textContent = '0/500';
  }
}

/* ── 送信時に両方のsubmit-wrapを隠す ── */
(function () {
  const orig = $('btn-submit').onclick;
  $('btn-submit').addEventListener('click', () => {
    const sitWrap = $('submit-wrap-sit');
    if (sitWrap) sitWrap.classList.add('hidden');
  }, true);
})();

$('btn-reset').onclick = resetView;

/* ─── コピー ─── */
$('btn-copy').onclick = () => {
  const txt = `[Compass 分析結果]\nモデル: ${$('res-model').textContent}\n指数: ${$('res-score').textContent}（${$('res-badge').textContent}）\n\n◆ 心理分析:\n${$('res-psych').textContent}\n\n◆ 次の一手:\n${$('res-advice').textContent}`;
  navigator.clipboard.writeText(txt).then(() => {
    $('btn-copy').innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--accent);fill:none;stroke-width:2;"><polyline points="20 6 9 17 4 12"/></svg> コピー完了';
    setTimeout(() => {
      $('btn-copy').innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> コピー';
    }, 2000);
  });
};

/* ─── 履歴 ─── */
function saveHistory(data, input) {
  state.history.unshift({
    id: Date.now(),
    type: state.tab === 'line' ? 'LINE' : '言動',
    input,
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
    langInterpretation: data.langInterpretation,
    usedModel: data.usedModel || 'Demo',
    date: new Date().toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  });
  if (state.history.length > 5) state.history.pop();
  localStorage.setItem('c_hist', JSON.stringify(state.history));
  renderHistory();
}

function renderHistory() {
  const el = $('history-list');
  if (!state.history.length) { el.innerHTML = '<p class="history-empty">履歴はありません</p>'; return; }
  el.innerHTML = '';
  state.history.forEach(item => {
    const cls = item.pulseRate >= 70 ? 'history-score-high' : item.pulseRate >= 40 ? 'history-score-mid' : 'history-score-low';
    const div = document.createElement('div');
    div.className = 'history-item';
    div.innerHTML = `
      <div class="history-item-top">
        <span class="history-meta">${item.date} · ${item.type}</span>
        <span class="history-score-badge ${cls}">${item.pulseRate}%</span>
      </div>
      <p class="history-text">「${item.input}」</p>
      <p class="history-model">モデル: ${item.usedModel}</p>`;
    div.onclick = () => {
      showResult(item);
      ['form-line', 'form-sit', 'submit-wrap'].forEach(id => $(id).classList.add('hidden'));
    };
    el.appendChild(div);
  });
}

$('btn-clear').onclick = () => {
  if (confirm('履歴をすべて削除しますか？')) {
    state.history = [];
    localStorage.removeItem('c_hist');
    renderHistory();
  }
};


