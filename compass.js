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
  replyLen: 50,       // 0〜100
  meetVal: 50,        // 0〜100
  mood: ['いつも通りな感じ'],  // 複数選択のため配列
  scene: '二人きりで遊んでいた',
  attitude: ['やさしいけどなんか緊張してる感じがした'], // 複数選択のため配列
  duration: 50,       // 0〜100
  tension: 50,        // 0〜100
  partner: localStorage.getItem('c_partner') || '彼氏',
  tone: localStorage.getItem('c_tone') || '普通',
  apiKey: 'server-side',
  model: localStorage.getItem('c_model') || 'models/gemini-2.5-flash',
  history: JSON.parse(localStorage.getItem('c_hist') || '[]'),
  viewMode: 'mobile',
  currentResult: null,
};
const deviceId = (() => {
  const key = 'c_device_id';
  let id = localStorage.getItem(key);
  if (!id) {
    id = 'dev_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem(key, id);
  }
  return id;
})();

const $ = id => document.getElementById(id);

/* ─── 初期化 ─── */

renderHistoryAll();
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

  const summaryPanel = $('input-summary-panel');
  if (summaryPanel) summaryPanel.classList.add('hidden');

  if (t === 'line' || t === 'sit') {
    $('result-panel').classList.add('hidden');
    $('loading-panel').classList.add('hidden');
    if (t === 'line') {
      $('submit-wrap').classList.remove('hidden');
    } else {
      $('submit-wrap-sit').classList.remove('hidden');
    }
  } else if (t === 'hist') {
    $('result-panel').classList.add('hidden');
    $('loading-panel').classList.add('hidden');
  }

  }

/* ─── チップグループ ─── */
function initCustomChip(groupId, stateKey, inputId) {
  const grp = $(groupId);
  const input = $(inputId);
  if (!grp || !input) return;

  const otherChip = grp.querySelector('.chip[data-val="その他"]');
  const hasPresetMatch = () => Array.from(grp.querySelectorAll('.chip')).some(c => c.dataset.val === state[stateKey]);

  const updateInputUI = () => {
    const isOtherSelected = state[stateKey] === 'その他';
    const isCustomValue = state[stateKey] && !hasPresetMatch();
    const shouldShow = isOtherSelected || isCustomValue;
    input.classList.toggle('hidden', !shouldShow);

    if (isCustomValue) input.value = state[stateKey];
    if (!shouldShow) input.value = '';
  };

  grp.querySelectorAll('.chip').forEach(chip => {
    chip.addEventListener('click', () => {
      updateInputUI();
      if (chip === otherChip) input.focus();
    });
  });

  input.addEventListener('input', () => {
    const v = input.value.trim();
    state[stateKey] = v || 'その他';
    localStorage.setItem('c_' + stateKey, state[stateKey]);
  });

  updateInputUI();
}

function initChips(groupId, stateKey, colorClass) {
  const grp = $(groupId);
  if (!grp) return;
  grp.querySelectorAll('.chip').forEach(c => {
    // Reflect initial state
    if (c.dataset.val === state[stateKey]) {
      c.className = 'chip ' + colorClass;
    } else {
      c.className = 'chip';
    }

    c.onclick = () => {
      grp.querySelectorAll('.chip').forEach(x => { x.className = 'chip'; });
      c.className = 'chip ' + colorClass;
      state[stateKey] = c.dataset.val;
      if (stateKey === 'partner') {
        localStorage.setItem('c_partner', state.partner);
      }
    };
  });
}

function initMultiChips(groupId, stateKey, colorClass) {
  const grp = $(groupId);
  if (!grp) return;

  const updateUI = () => {
    grp.querySelectorAll('.chip').forEach(c => {
      const val = c.dataset.val;
      if (state[stateKey].includes(val)) {
        c.className = 'chip ' + colorClass;
      } else {
        c.className = 'chip';
      }
    });
  };

  grp.querySelectorAll('.chip').forEach(c => {
    c.onclick = () => {
      const val = c.dataset.val;
      if (val === 'わからない') {
        if (state[stateKey].includes('わからない')) {
          state[stateKey] = [];
        } else {
          state[stateKey] = ['わからない'];
        }
      } else {
        state[stateKey] = state[stateKey].filter(x => x !== 'わからない');
        if (state[stateKey].includes(val)) {
          state[stateKey] = state[stateKey].filter(x => x !== val);
        } else {
          state[stateKey].push(val);
        }
      }

      if (state[stateKey].length === 0) {
        state[stateKey] = ['わからない'];
      }
      updateUI();
    };
  });

  updateUI();
}

initChips('partner-group', 'partner', 'selected');
initCustomChip('partner-group', 'partner', 'partner-custom');
initChips('rel-group', 'rel', 'selected');
initMultiChips('mood-group', 'mood', 'selected');
initChips('scene-group', 'scene', 'selected-purple');
initCustomChip('scene-group', 'scene', 'scene-custom');
initMultiChips('attitude-group', 'attitude', 'selected-purple');

/* ─── スライダーの日本語ラベルマッピング ─── */
function getMeetLabel(val) {
  if (val < 15) return 'まだ会ったことない';
  if (val < 35) return '数回会ったくらい（2〜5回）';
  if (val < 65) return '何度か会ってる（6〜15回）';
  if (val < 85) return 'かなり頻繁に会ってる';
  return 'いつも一緒なくらい！（しょっちゅう会う）';
}

function getSpeedLabel(val) {
  if (val < 15) return 'かなり遅い（数日以上）';
  if (val < 35) return '遅い（1日以上）';
  if (val < 45) return 'やや遅い（数時間）';
  if (val < 55) return '普通（半日くらい）';
  if (val < 65) return 'やや早い（1〜2時間）';
  if (val < 85) return '早い（数十分）';
  return 'かなり早い（即レス）';
}

function getDurationLabel(val) {
  if (val < 15) return '少しの間だけ（30分未満）';
  if (val < 35) return 'ちょっとした時間（30分〜1時間）';
  if (val < 55) return '1〜2時間くらい';
  if (val < 75) return '半日くらい（3〜5時間）';
  if (val < 90) return '長時間（6〜8時間）';
  return '丸一日中！（9時間以上）';
}

function getTensionLabel(val) {
  if (val < 15) return 'かなり低い（どんより）';
  if (val < 35) return '低い（静か・落ち着いている）';
  if (val < 45) return 'やや低い（ちょっとクール）';
  if (val < 55) return '普通（何とも言えない）';
  if (val < 65) return 'やや高い（少し楽しそう）';
  if (val < 85) return '高い（盛り上がっている）';
  return 'かなり高い（テンションMAX！）';
}

function initSliderWithLabel(sliderId, valId, stateKey, labelFn) {
  const el = $(sliderId);
  const vEl = $(valId);
  if (!el) return;
  el.value = state[stateKey];
  vEl.textContent = labelFn(state[stateKey]);
  el.oninput = () => {
    state[stateKey] = parseInt(el.value);
    vEl.textContent = labelFn(state[stateKey]);
  };
}

initSliderWithLabel('meet-slider', 'meet-val', 'meetVal', getMeetLabel);
initSliderWithLabel('speed-slider', 'speed-val', 'replyLen', getSpeedLabel);
initSliderWithLabel('duration-slider', 'duration-val', 'duration', getDurationLabel);
initSliderWithLabel('tension-slider', 'tension-val', 'tension', getTensionLabel);

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
  $('modal').classList.add('open');
};
$('tone-select').value = state.tone;
$('modal').onclick = e => { if (e.target === $('modal')) $('modal').classList.remove('open'); };

$('btn-save').onclick = () => {
  state.tone = $('tone-select').value;
  localStorage.setItem('c_tone', state.tone);
  localStorage.setItem('c_partner', state.partner);
  $('modal').classList.remove('open');
};

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
  const summaryPanel = $('input-summary-panel');
  if (summaryPanel) summaryPanel.classList.add('hidden');
  ['tab-line', 'tab-sit', 'tab-hist'].forEach(id => $(id).classList.remove('active'));

  $('loading-panel').classList.remove('hidden');
    $('loading-model').textContent = 'モデル: ' + (state.model === 'demo' ? 'デモモード' : state.model.replace('models/', ''));

  const msgs = [
    'メッセージのパターンを読み解いてるよ',
    '心理学指標に照合してるよ...',
    '行動データを分析してるよ',
    'レポートを作成してるよ...'
  ];
  let idx = 0;
  const t = setInterval(() => { $('loading-sub').textContent = msgs[idx++ % msgs.length]; }, 1500);

  try {
    let data;
    if (state.model !== 'demo') {
      data = await callAPI(mainInput, extraInput, isLine);
    } else {
      await new Promise(r => setTimeout(r, 2200));
      data = demoResult(mainInput);
    }
    const historyItem = saveHistory(data, mainInput, extraInput, isLine);
    showResult(historyItem);
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

// 新しい相談ボタンは削除されました

/* ─── API呼び出し ─── */
async function callAPI(text, extra, isLine) {
  const speedLabel = getSpeedLabel(state.replyLen);
  const tensionLabel = getTensionLabel(state.tension);
  const meetLabel = getMeetLabel(state.meetVal);
  const durationLabel = getDurationLabel(state.duration);
  const moodLabel = state.mood.join('、');
  const attitudeLabel = state.attitude.join('、');

  const ctx = isLine
    ? `【お相手の属性】${state.partner}
【相手との関係性】${state.rel}
【これまで会った回数】${meetLabel}
【返信スピード】${speedLabel}
【メッセージの雰囲気（普段との比較）】${moodLabel}
【気になるメッセージの内容】${text}
${extra ? '【追加情報・背景】' + extra : ''}`
    : `【お相手の属性】${state.partner}
【シチュエーション】${state.scene}
【一緒にいた時間】${durationLabel}
【相手の態度・様子】${attitudeLabel}
【その場のテンション感】${tensionLabel}
【気になった言動・セリフ】${text}
${extra ? '【追加情報・背景】' + extra : ''}`;

  const prompt = `あなたは中高生・大学生向けの恋愛・恋心アドバイザーです。
青春の恋愛について、心理学ベースでリアルかつ人間らしい分析レポートを日本語で生成してください。

【超重要】
この分析は「恋愛占い」ではありません。
恋愛心理学・行動心理・コミュニケーション分析をベースに、
相手の行動頻度・継続性・温度差・自発性から分析してください。

【重要ルール】
- ユーザーは中学生・高校生・大学生（10代〜20代前半）です。
- 学校・部活・SNS・通学・LINE・インスタ・青春環境を踏まえてください。
- 難しい専門用語は使わず、自然な会話っぽく説明してください。
- 入力内容が意味をなさない場合は、pulseRateを0にし、
  psychology欄に「もう少し具体的に書いてみてね！」と記載してください。

- レポート全体の雰囲気は必ず「${state.tone}」に従ってください。
- toneによって「口調」「恋愛の解釈」「脈あり判定基準」「アドバイス方針」を変化させてください。

━━━━━━━━━━
【tone別の心理分析ルール】
━━━━━━━━━━

■ tone = 「優しい」
- とにかく共感・安心感を重視
- ユーザーの不安を和らげる方向で解釈
- 小さな好意サインも前向きに拾う
- 否定的な断定を避ける
- 「大丈夫」「ちゃんと気にしてると思うよ」など安心感を与える
- pulseRateはやや高めに出やすい
- 「少し勇気を出してみよう」が基本方針
- 心理学：自己開示効果 / 単純接触効果 / ミラーリング を重視
- 保健室の先輩みたいな優しい雰囲気で

■ tone = 「やや優しい」
- 基本は寄り添い重視
- でも現実も少しだけ伝える
- 「脈ありかも。でもまだ様子見かな」くらいの柔らかさ
- pulseRateは少し甘め
- 焦らず距離を縮める方向でアドバイス
- 心理学：返報性 / 好意の返報性 を重視
- 恋バナ聞いてくれる友達みたいな雰囲気

■ tone = 「普通」
- 客観性と応援のバランス
- 良い点も微妙な点も両方伝える
- pulseRateは標準的
- 「これから次第で変わりそう」が基本
- 心理学：社会的交換理論 / 相互作用頻度 を重視
- 親しみやすい友達っぽく

■ tone = 「やや厳しめ」
- 期待しすぎを防ぐ方向で分析
- 好意サインが弱い場合ははっきり指摘
- 甘い解釈をしすぎない
- pulseRateは少し低め
- 「追いすぎ注意」「温度差を見よう」が基本
- 心理学：認知的不協和 / 恋愛投資バランス を重視
- 現実を見る友達みたいな雰囲気

■ tone = 「厳しめ」
- 現実的・客観的・かなり辛口
- 曖昧な優しさを脈あり扱いしない
- 「返信が来る＝好意」とは判断しない
- ユーザー側の期待バイアスを修正する
- pulseRateはかなり厳格
- 行動量・特別扱い・継続性を最重要視
- 「誰にでも優しい可能性」を必ず考慮
- 心理学：認知バイアス修正 / 損失回避 / 投資対効果 を重視
- 恋愛経験豊富な先輩みたいな雰囲気
- ただし最後は必ず前向きに締める
- ユーザー自身を否定しない

━━━━━━━━━━
【pulseRate算出ルール】
━━━━━━━━━━

- 優しい：
  小さな好意でも加点しやすい
  曖昧な優しさも好意寄りで解釈

- 普通：
  行動量・頻度・継続性を重視

- 厳しめ：
  明確な特別扱いがない限り高得点にしない
  「誰にでも優しい可能性」を考慮

━━━━━━━━━━
【恋愛心理分析ルール】
━━━━━━━━━━

- 一回だけの出来事より「継続行動」を重視
- SNSだけでなく現実での接触行動を重視
- 「返信が早い」単独では高評価しない
- 「自分から話しかける」「覚えている」「会おうとする」は高評価
- テンションより「安定した関わり」を重視
- 恋愛あるあるだけで断定しない
- 学校・部活・同じコミュニティ特有の距離感も考慮

━━━━━━━━━━
【分析スタイル】
━━━━━━━━━━

- psychology は「本音分析」
- advice は「今後どう動けばいいか」
- radarInterpretation は「関係性の特徴」
- matrixInterpretation は「感情テンション分析」
- langInterpretation は「LINE・会話距離感分析」

- どれも友達に相談するみたいな自然な日本語で
- AIっぽい硬さは禁止
- 説教っぽくしない
- 青春感・リアル感を大事に

${ctx}

以下のJSONのみを厳密に返してください。
マークダウン禁止。
コードブロック禁止。
説明文禁止。

{
  "pulseRate": 0〜100の整数,
  "levelBadge": "1文の評価",
  "psychology": "3〜4行",
  "advice": "3〜4行",
  "radar": {
    "intimacy": 0〜100,
    "passion": 0〜100,
    "commitment": 0〜100,
    "status": 0〜100,
    "safety": 0〜100
  },
  "radarInterpretation": "1〜2文",
  "matrix": {
    "x": -100〜100,
    "y": -100〜100
  },
  "matrixInterpretation": "1〜2文",
  "lang": {
    "selfDisclosure": 0〜100,
    "mirroring": 0〜100,
    "pronounCount": 0〜20,
    "emojiSync": 0〜100
  },
  "langInterpretation": "1〜2文",
  "approaches": [
    {
      "law": "心理学効果名",
      "title": "10〜20文字",
      "body": "2〜3文",
      "example": "自然なLINE例文"
    },
    {
      "law": "心理学効果名",
      "title": "10〜20文字",
      "body": "2〜3文",
      "example": "自然なLINE例文"
    }
  ]
}`;

  let delay = 1000;
  for (let i = 0; i < 2; i++) {
    const r = await fetch(
      'index.php?action=analyze',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt, model: state.model, consultation: { deviceId, type: isLine ? 'line' : 'sit', input: text, extra, snapshot: { type: isLine ? 'メッセージ' : '言動・状況', partner: state.partner, rel: isLine ? state.rel : null, meetVal: isLine ? state.meetVal : null, replyLen: isLine ? state.replyLen : null, mood: isLine ? [...state.mood] : null, scene: !isLine ? state.scene : null, duration: !isLine ? state.duration : null, tension: !isLine ? state.tension : null, attitude: !isLine ? [...state.attitude] : null, date: new Date().toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }) } } })
      }
    );
    if (r.status === 429) { await new Promise(x => setTimeout(x, delay)); delay *= 2; continue; }
    if (!r.ok) throw new Error('API error ' + r.status);
    const d = await r.json();
    let raw = d.candidates[0].content.parts[0].text.trim();
    raw = raw.replace(/^```json\s*/i, '').replace(/```\s*$/, '').trim();
    const parsed = JSON.parse(raw);
    parsed.consultationId = d.consultationId || null;
    parsed.usedModel = state.model.replace('models/', '');
    return parsed;
  }
  throw new Error('リトライ失敗');
}

/* ─── デモ結果 ─── */
function demoResult(text) {
  const hi = ['好き', '楽しみ', 'かわいい', '空いてる', 'ご飯', '一緒', '遊ぼ', '会いたい'].some(w => text.includes(w));
  const lo = ['忙しい', '無理', '既読', 'ごめん', '遅い'].some(w => text.includes(w));

  const p = state.partner || '相手';
  let toneSuffix = '';
  let toneAdvice = '';

  if (state.tone === '優しい' || state.tone === 'やや優しい') {
    toneSuffix = ' 大丈夫、あなたのペースでゆっくり寄り添っていけば、きっと気持ちは伝わるよ♡';
    toneAdvice = ' 焦らず、温かい気持ちで相手に接してみてね。応援してるよ！';
  } else if (state.tone === '厳しめ' || state.tone === 'やや厳しめ') {
    toneSuffix = ' ただ、現実をしっかり見つめないと、都合のいい関係になってしまう可能性もあるから気をつけて。';
    toneAdvice = ' 少し冷静になって、自分の時間を大切にしてみるのもいいかもしれない。客観的な態度を忘れずに。';
  }

  if (hi && !lo) return {
    pulseRate: 84,
    levelBadge: 'かなり意識してるかも♡！',
    psychology: `${p}はあなたのことをかなり気にしているよ！積極的に話しかけたり、あなたを気にかける言動がたくさん見られる。このまま自然に仲を深めていける感じがするね！${toneSuffix}`,
    advice: `${p}の好意に素直に反応してOK♪「一緒にいると楽しい」ってことを自然に伝えてみよう。次の放課後や週末に「一緒にどこか行かない？」って誘うのも今がチャンスかも！${toneAdvice}`,
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
    psychology: `${p}は今、部活や勉強で忙しいか、気持ちを整理中かもしれないよ。そっけなさは必ずしもあなたのことが嫌いなわけじゃなくて、今の自分のペースを守りたいサインの可能性もある。${toneSuffix}`,
    advice: `焦らずに数日こちらからの連絡をちょっとお休みしてみよう。引いてみると「あれ、なんで来ないんだろ」って気にしてくれることもあるよ。自分の時間も楽しみながら待ってみて！${toneAdvice}`,
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
    psychology: `今は「話しやすくて好きな人」ポジションにいる感じ。悪い印象はゼロで、${p}とこれから恋愛に発展できる余地はたっぷりあるよ！焦らなくて大丈夫！${toneSuffix}`,
    advice: `共通の趣味や${p}が好きな話題を見つけて、相手が自分から話してくれるきっかけを作ってみよう。放課後や部活後に「一緒に帰ろ」って誘うくらいのカジュアルな距離詰めがいいかも！${toneAdvice}`,
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
  state.currentResult = data;
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

  // 入力内容サマリーの描画
  if (data.input) {
    renderInputSummary(data);
  } else {
    const summaryPanel = $('input-summary-panel');
    if (summaryPanel) summaryPanel.classList.add('hidden');
  }

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

function showSharedMode(item) {
  showResult(item);
  $('form-line').classList.add('hidden');
  $('form-sit').classList.add('hidden');
  $('form-hist').classList.add('hidden');
  $('submit-wrap').classList.add('hidden');
  $('submit-wrap-sit').classList.add('hidden');
  $('loading-panel').classList.add('hidden');
  ['tab-line', 'tab-sit', 'tab-hist'].forEach(id => $(id).classList.remove('active'));
}

/* ─── 入力内容サマリーの描画 ─── */
function renderInputSummary(item) {
  const container = $('input-summary-panel');
  if (!container) return;

  container.innerHTML = '';
  
  const card = document.createElement('div');
  card.className = 'glass-card section-gap input-summary-card';

  const typeText = item.isLine ? 'メッセージ相談' : '言動・状況の相談';
  const badgeClass = item.isLine ? 'summary-type-badge line-type' : 'summary-type-badge sit-type';
  const dateStr = item.date || '';

  let optionsHtml = '';
  if (item.isLine) {
    const meetLabel = getMeetLabel(item.meetVal !== null ? item.meetVal : 50);
    const speedLabel = getSpeedLabel(item.replyLen !== null ? item.replyLen : 50);
    const moodStr = Array.isArray(item.mood) ? item.mood.join('、') : (item.mood || '—');
    
    optionsHtml = `
      <div class="summary-item-row">
        <span class="summary-item-label">ご相手</span>
        <span class="summary-item-val">${item.partner || '—'}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">相手との関係</span>
        <span class="summary-item-val">${item.rel || '—'}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">会った回数</span>
        <span class="summary-item-val">${meetLabel}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">返信スピード</span>
        <span class="summary-item-val">${speedLabel}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">普段と比べて</span>
        <span class="summary-item-val">${moodStr}</span>
      </div>
    `;
  } else {
    const durationLabel = getDurationLabel(item.duration !== null ? item.duration : 50);
    const tensionLabel = getTensionLabel(item.tension !== null ? item.tension : 50);
    const attitudeStr = Array.isArray(item.attitude) ? item.attitude.join('、') : (item.attitude || '—');
    
    optionsHtml = `
      <div class="summary-item-row">
        <span class="summary-item-label">ご相手</span>
        <span class="summary-item-val">${item.partner || '—'}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">場面・状況</span>
        <span class="summary-item-val">${item.scene || '—'}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">一緒にいた時間</span>
        <span class="summary-item-val">${durationLabel}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">場のテンション</span>
        <span class="summary-item-val">${tensionLabel}</span>
      </div>
      <div class="summary-item-row">
        <span class="summary-item-label">相手の様子</span>
        <span class="summary-item-val">${attitudeStr}</span>
      </div>
    `;
  }

  const mainInputLabel = item.isLine ? '気になるメッセージの内容' : '気になった言動・セリフ';
  
  card.innerHTML = `
    <div class="summary-header">
      <span class="${badgeClass}">${typeText}</span>
      <span class="summary-date">${dateStr}</span>
    </div>
    
    <div class="summary-section">
      ${optionsHtml}
    </div>

    <div class="summary-section text-section">
      <div class="summary-text-label">${mainInputLabel}</div>
      <div class="summary-text-val"></div>
    </div>

    ${item.extra ? `
    <div class="summary-section text-section">
      <div class="summary-text-label">追加情報・背景</div>
      <div class="summary-text-val-extra"></div>
    </div>
    ` : ''}
  `;

  card.querySelector('.summary-text-val').textContent = item.input || '';
  if (item.extra) {
    card.querySelector('.summary-text-val-extra').textContent = item.extra;
  }

  container.appendChild(card);
  container.classList.remove('hidden');
}

/* ─── リセット ─── */
function resetView() {
  $('result-panel').classList.add('hidden');
  const summaryPanel = $('input-summary-panel');
  if (summaryPanel) summaryPanel.classList.add('hidden');

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
    $('line-count').textContent = '0/50000';
    $('line-extra-input').value = '';
    $('line-extra-count').textContent = '0/50000';
  } else {
    $('submit-wrap-sit').classList.remove('hidden');
    $('sit-input').value = '';
    $('sit-count').textContent = '0/50000';
    $('sit-extra-input').value = '';
    $('sit-extra-count').textContent = '0/50000';
  }

  
  // スクロールトップ
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ─── コピー ─── */
function buildFullReportText(data) {
  if (!data) return '';
  let text = `[Compass 分析結果]
分析モデル: ${data.usedModel || 'Demo'}
脈あり・好意指数: ${data.pulseRate}% (${data.levelBadge})

◆ 本音の分析
${data.psychology}

◆ 次の一手アドバイス
${data.advice}
`;

  // 恋愛心理プロファイル
  if (data.radar) {
    text += `
◆ 恋愛心理プロファイル💫
・親密性: ${data.radar.intimacy}
・ときめき: ${data.radar.passion}
・誠実さ: ${data.radar.commitment}
・承認欲求: ${data.radar.status}
・心のゆとり: ${data.radar.safety}
解説: ${data.radarInterpretation || ''}
`;
  }

  // テンション・マトリクス
  if (data.matrix) {
    text += `
◆ テンション・マトリクス
座標: ポジ/ネガ ${data.matrix.x > 0 ? '+' : ''}${data.matrix.x} ／ テンション ${data.matrix.y > 0 ? '+' : ''}${data.matrix.y}
解説: ${data.matrixInterpretation || ''}
`;
  }

  // メッセージ心理分析
  if (data.lang) {
    text += `
◆ メッセージ心理分析📊
・自己開示率: ${data.lang.selfDisclosure}%
・ミラーリング同調率: ${data.lang.mirroring}%
・一緒系ワード数: ${data.lang.pronounCount}
・絵文字シンク率: ${data.lang.emojiSync || 0}%
解説: ${data.langInterpretation || ''}
`;
  }

  // アプローチ
  if (data.approaches && data.approaches.length > 0) {
    text += `
◆ 次の一手アクション🚀
`;
    data.approaches.forEach((app, idx) => {
      text += `${idx + 1}. [${app.law}] ${app.title}
説明: ${app.body}
${app.example ? `メッセージ例: ${app.example}\n` : ''}`;
    });
  }

  return text.trim();
}

function copyCardContent(type) {
  const data = state.currentResult;
  if (!data) return '';
  let txt = '';
  
  if (type === 'psych') {
    txt = `◆ 本音の分析\n${data.psychology}`;
  } else if (type === 'advice') {
    txt = `◆ 次の一手アドバイス\n${data.advice}`;
  } else if (type === 'radar') {
    txt = `◆ 恋愛心理プロファイル💫
・親密性: ${data.radar.intimacy}
・ときめき: ${data.radar.passion}
・誠実さ: ${data.radar.commitment}
・承認欲求: ${data.radar.status}
・心のゆとり: ${data.radar.safety}
解説: ${data.radarInterpretation || ''}`;
  } else if (type === 'matrix') {
    txt = `◆ テンション・マトリクス
座標: ポジ/ネガ ${data.matrix.x > 0 ? '+' : ''}${data.matrix.x} ／ テンション ${data.matrix.y > 0 ? '+' : ''}${data.matrix.y}
解説: ${data.matrixInterpretation || ''}`;
  } else if (type === 'lang') {
    txt = `◆ メッセージ心理分析📊
・自己開示率: ${data.lang.selfDisclosure}%
・ミラーリング同調率: ${data.lang.mirroring}%
・一緒系ワード数: ${data.lang.pronounCount}
・絵文字シンク率: ${data.lang.emojiSync || 0}%
解説: ${data.langInterpretation || ''}`;
  } else if (type === 'approach') {
    txt = `◆ 次の一手アクション🚀\n`;
    data.approaches.forEach((app, idx) => {
      txt += `${idx + 1}. [${app.law}] ${app.title}
説明: ${app.body}
${app.example ? `メッセージ例: ${app.example}\n` : ''}`;
    });
  }
  return txt.trim();
}

function performCopy(text, buttonEl, isGlobal = false) {
  navigator.clipboard.writeText(text).then(() => {
    if (isGlobal) {
      const originalHTML = buttonEl.innerHTML;
      buttonEl.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--accent);fill:none;stroke-width:2.5;"><polyline points="20 6 9 17 4 12"/></svg> コピー完了';
      setTimeout(() => {
        buttonEl.innerHTML = originalHTML;
      }, 2000);
    } else {
      const originalHTML = buttonEl.innerHTML;
      buttonEl.innerHTML = '<svg viewBox="0 0 24 24" style="stroke:var(--green);fill:none;stroke-width:2.5;"><polyline points="20 6 9 17 4 12"/></svg>';
      buttonEl.classList.add('copied');
      setTimeout(() => {
        buttonEl.innerHTML = originalHTML;
        buttonEl.classList.remove('copied');
      }, 2000);
    }
  });
}

$('btn-copy').onclick = function() {
  const txt = buildFullReportText(state.currentResult);
  if (txt) performCopy(txt, this, true);
};
$('btn-share').onclick = async function() {
  if (!state.currentResult) return;
  const enabled = !state.currentResult.shared;
  if (!confirm(enabled ? 'この分析結果を共有ONにしてURLを発行しますか？' : '共有をOFFにしますか？')) return;
  const r = await fetch('index.php?action=share-toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ deviceId, consultationId: state.currentResult.consultationId, enabled, record: state.currentResult })
  });
  if (!r.ok) { alert('URL発行に失敗しました'); return; }
  const d = await r.json();
  state.currentResult.shared = !!d.enabled;
  if (d.enabled && d.url) {
    await navigator.clipboard.writeText(d.url);
    alert('共有URLを発行しました。クリップボードにコピー済みです。\n' + d.url);
  } else {
    alert('共有をOFFにしました。');
  }
};

document.addEventListener('click', e => {
  const btn = e.target.closest('.btn-copy-card-sm');
  if (!btn) return;
  
  let type = btn.getAttribute('data-copy-type');
  if (!type && btn.id === 'btn-copy-psych') type = 'psych';
  if (!type && btn.id === 'btn-copy-advice') type = 'advice';
  
  if (!type) return;
  
  const txt = copyCardContent(type);
  if (txt) performCopy(txt, btn, false);
});

/* ─── 履歴 ─── */
function saveHistory(data, input, extra, isLine) {
  const item = {
    id: Date.now(),
    type: isLine ? 'メッセージ' : '言動・状況',
    input,
    extra: extra || '',
    isLine,
    partner: state.partner,
    rel: isLine ? state.rel : null,
    meetVal: isLine ? state.meetVal : null,
    replyLen: isLine ? state.replyLen : null,
    mood: isLine ? [...state.mood] : null,
    scene: !isLine ? state.scene : null,
    duration: !isLine ? state.duration : null,
    tension: !isLine ? state.tension : null,
    attitude: !isLine ? [...state.attitude] : null,
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
    consultationId: data.consultationId || null,
    shared: false,
    date: new Date().toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  };
  state.history.unshift(item);
  if (state.history.length > 10) state.history.pop();
  localStorage.setItem('c_hist', JSON.stringify(state.history));
  renderHistoryAll();
  return item;
}

function deleteHistoryItem(index) {
  const target = state.history[index];
  if (!target) return;
  if (confirm('この履歴を削除する？')) {
    state.history.splice(index, 1);
    localStorage.setItem('c_hist', JSON.stringify(state.history));
    renderHistoryAll();
  }
}

function createHistoryItem(item, index) {
  const cls = item.pulseRate >= 70 ? 'history-score-high' : item.pulseRate >= 40 ? 'history-score-mid' : 'history-score-low';
  const div = document.createElement('div');
  div.className = 'history-item';
  div.innerHTML = `
    <div class="history-item-top">
      <span class="history-meta">${item.date} · ${item.type}</span>
      <div class="history-item-actions">
        <span class="history-score-badge ${cls}">${item.pulseRate}%</span>
        <button class="btn-history-delete" type="button" aria-label="この履歴を削除">削除</button>
      </div>
    </div>
    <p class="history-text">「${item.input}」</p>
    ${item.extra ? `<p class="history-input-preview">＋ ${item.extra}</p>` : ''}
    <p class="history-model">モデル: ${item.usedModel}</p>`;
  const deleteBtn = div.querySelector('.btn-history-delete');
  deleteBtn.onclick = (e) => {
    e.stopPropagation();
    deleteHistoryItem(index);
  };

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

  const emptyMsg = '<p class="history-empty">まだ履歴はないよ</p>';
  const emptyMsg2 = '<p class="history-empty">まだ履歴はないよ<br>分析するといつでも見返せるよ！</p>';

  if (!state.history.length) {
    if (el) el.innerHTML = emptyMsg;
    if (el2) el2.innerHTML = emptyMsg2;
    return;
  }

  if (el) {
    el.innerHTML = '';
    state.history.forEach((item, index) => el.appendChild(createHistoryItem(item, index)));
  }
  if (el2) {
    el2.innerHTML = '';
    state.history.forEach((item, index) => el2.appendChild(createHistoryItem(item, index)));
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

if (window.__sharedItem) {
  showSharedMode(window.__sharedItem);
}
