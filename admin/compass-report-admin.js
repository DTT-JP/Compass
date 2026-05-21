/* ============================================================
   compass-report-admin.js
   詳細分析レポートの描画・表示ロジック（admin用）
   compass-report.js から必要部分のみ抽出
   ============================================================ */

'use strict';

/* ── レーダーチャート描画 ── */
function drawRadarChart(canvas, scores) {
  const size = Math.min(canvas.parentElement.offsetWidth, 300);
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  const cx = size / 2, cy = size / 2;
  const r = size * 0.38;
  const labels = ['親密性', 'ときめき', '誠実さ', '承認欲求', '心のゆとり'];
  const vals = [
    scores.intimacy,
    scores.passion,
    scores.commitment,
    scores.status,
    scores.safety
  ];
  const n = 5;
  const step = (Math.PI * 2) / n;
  const startAngle = -Math.PI / 2;

  ctx.clearRect(0, 0, size, size);

  // グリッド
  const levels = [20, 40, 60, 80, 100];
  levels.forEach(lv => {
    ctx.beginPath();
    for (let i = 0; i < n; i++) {
      const a = startAngle + i * step;
      const rr = (lv / 100) * r;
      const x = cx + rr * Math.cos(a);
      const y = cy + rr * Math.sin(a);
      i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    }
    ctx.closePath();
    ctx.strokeStyle = 'rgba(164, 158, 168, 0.2)';
    ctx.lineWidth = 1;
    ctx.stroke();
    if (lv % 40 === 0) {
      ctx.fillStyle = 'rgba(164, 158, 168, 0.35)';
      ctx.font = `${Math.round(size * 0.035)}px sans-serif`;
      ctx.fillText(lv, cx + 4, cy - (lv / 100) * r + 3);
    }
  });

  // 軸
  for (let i = 0; i < n; i++) {
    const a = startAngle + i * step;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + r * Math.cos(a), cy + r * Math.sin(a));
    ctx.strokeStyle = 'rgba(164, 158, 168, 0.25)';
    ctx.lineWidth = 1;
    ctx.stroke();
  }

  // データ面
  const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r);
  grad.addColorStop(0, 'rgba(255, 107, 139, 0.35)');
  grad.addColorStop(1, 'rgba(167, 139, 250, 0.25)');
  ctx.beginPath();
  for (let i = 0; i < n; i++) {
    const a = startAngle + i * step;
    const rr = (vals[i] / 100) * r;
    const x = cx + rr * Math.cos(a);
    const y = cy + rr * Math.sin(a);
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  }
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();
  ctx.strokeStyle = 'rgba(255, 107, 139, 0.8)';
  ctx.lineWidth = 2;
  ctx.stroke();

  // ノード
  for (let i = 0; i < n; i++) {
    const a = startAngle + i * step;
    const rr = (vals[i] / 100) * r;
    ctx.beginPath();
    ctx.arc(cx + rr * Math.cos(a), cy + rr * Math.sin(a), 5, 0, Math.PI * 2);
    ctx.fillStyle = '#ff6b8b';
    ctx.fill();
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2;
    ctx.stroke();
  }

  // ラベル
  const labelR = r + size * 0.11;
  const fs = Math.max(10, Math.round(size * 0.042));
  ctx.font = `700 ${fs}px 'M PLUS Rounded 1c', sans-serif`;
  ctx.fillStyle = '#4a454d';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  for (let i = 0; i < n; i++) {
    const a = startAngle + i * step;
    const x = cx + labelR * Math.cos(a);
    const y = cy + labelR * Math.sin(a);
    ctx.fillText(labels[i], x, y);
  }
}

/* ── テンション・マトリクス描画 ── */
function drawMatrix(canvas, coord) {
  const size = Math.min(canvas.parentElement.offsetWidth, 300);
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  const m = size * 0.08;
  const w = size - m * 2;
  const h = w;
  const ox = m, oy = m;

  ctx.clearRect(0, 0, size, size);

  const half = w / 2;

  // 4象限背景
  ctx.fillStyle = 'rgba(255,169,77,0.18)';
  ctx.beginPath(); ctx.roundRect(ox, oy, half, half, 12); ctx.fill();
  ctx.fillStyle = 'rgba(255,107,139,0.18)';
  ctx.beginPath(); ctx.roundRect(ox + half, oy, half, half, 12); ctx.fill();
  ctx.fillStyle = 'rgba(110,181,255,0.18)';
  ctx.beginPath(); ctx.roundRect(ox, oy + half, half, half, 12); ctx.fill();
  ctx.fillStyle = 'rgba(74,222,128,0.18)';
  ctx.beginPath(); ctx.roundRect(ox + half, oy + half, half, half, 12); ctx.fill();

  // 軸
  ctx.strokeStyle = 'rgba(164,158,168,0.5)';
  ctx.lineWidth = 1.5;
  ctx.setLineDash([4, 4]);
  ctx.beginPath();
  ctx.moveTo(ox + half, oy);
  ctx.lineTo(ox + half, oy + h);
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(ox, oy + half);
  ctx.lineTo(ox + w, oy + half);
  ctx.stroke();
  ctx.setLineDash([]);

  // 軸ラベル
  const fs = Math.max(9, Math.round(size * 0.038));
  ctx.font = `600 ${fs}px 'M PLUS Rounded 1c', sans-serif`;
  ctx.textAlign = 'center';
  ctx.fillStyle = 'rgba(122,115,125,0.8)';
  ctx.fillText('ネガティブ', ox + half / 2, oy + half / 2 - 6);
  ctx.fillText('ポジティブ', ox + half + half / 2, oy + half / 2 - 6);
  ctx.fillText('テンション高め', ox + half, oy + 10);
  ctx.fillText('テンション低め', ox + half, oy + h - 4);

  // 象限名
  const qfs = Math.max(8, Math.round(size * 0.032));
  ctx.font = `700 ${qfs}px 'M PLUS Rounded 1c', sans-serif`;
  ctx.fillStyle = 'rgba(255,169,77,0.9)';
  ctx.textAlign = 'center';
  ctx.fillText('🔥 モヤモヤ中', ox + half / 2, oy + half / 2 + 10);
  ctx.fillStyle = 'rgba(255,107,139,0.9)';
  ctx.fillText('💘 ときめき中', ox + half + half / 2, oy + half / 2 + 10);
  ctx.fillStyle = 'rgba(110,181,255,0.9)';
  ctx.fillText('😴 お疲れ気味', ox + half / 2, oy + half + half / 2 + 10);
  ctx.fillStyle = 'rgba(74,222,128,0.9)';
  ctx.fillText('😊 安心してる', ox + half + half / 2, oy + half + half / 2 + 10);

  // プロット点
  const px = ox + (coord.x + 100) / 200 * w;
  const py = oy + (1 - (coord.y + 100) / 200) * h;

  // 波紋
  [{ r: 18, a: 0.12 }, { r: 12, a: 0.22 }].forEach(p => {
    ctx.beginPath();
    ctx.arc(px, py, p.r, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(255,107,139,${p.a})`;
    ctx.fill();
  });
  ctx.beginPath();
  ctx.arc(px, py, 7, 0, Math.PI * 2);
  ctx.fillStyle = '#ff6b8b';
  ctx.fill();
  ctx.strokeStyle = '#fff';
  ctx.lineWidth = 2.5;
  ctx.stroke();
}

/* ── プログレスバーアニメーション ── */
function animateBars() {
  document.querySelectorAll('.lang-bar-fill[data-pct]').forEach(el => {
    const pct = el.dataset.pct;
    setTimeout(() => { el.style.width = pct + '%'; }, 100);
  });
}

/* ── 分析レポートを表示 ── */
function renderAdvancedReport(data, container) {
  container.innerHTML = '';

  container.appendChild(createRadarSection(data));
  container.appendChild(createMatrixSection(data));
  container.appendChild(createLangSection(data));
  container.appendChild(createApproachSection(data));

  setTimeout(animateBars, 200);

  setTimeout(() => {
    const radarCanvas = container.querySelector('#radar-canvas');
    if (radarCanvas && data.radar) drawRadarChart(radarCanvas, data.radar);

    const matCanvas = container.querySelector('#matrix-canvas');
    if (matCanvas && data.matrix) drawMatrix(matCanvas, data.matrix);
  }, 150);
}

function createRadarSection(data) {
  const div = document.createElement('div');
  div.className = 'radar-card fade-up section-gap';
  const r = data.radar || { intimacy: 60, passion: 50, commitment: 55, status: 45, safety: 65 };
  div.innerHTML = `
    <div class="detail-card-header">
      <div class="detail-icon pink">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" fill="none" stroke-width="2" style="width:16px;height:16px;stroke:#ff6b8b">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
      </div>
      <div>
        <div class="detail-card-title">恋愛心理プロファイル💫</div>
        <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">5軸レーダーチャート分析</div>
      </div>
    </div>
    <div class="radar-canvas-wrap">
      <canvas id="radar-canvas"></canvas>
    </div>
    <div class="radar-metrics">
      <div class="radar-metric">
        <div class="radar-metric-val" style="color:var(--accent)">${r.intimacy}</div>
        <div class="radar-metric-name">親密性</div>
      </div>
      <div class="radar-metric">
        <div class="radar-metric-val" style="color:#f97316">${r.passion}</div>
        <div class="radar-metric-name">ときめき</div>
      </div>
      <div class="radar-metric">
        <div class="radar-metric-val" style="color:var(--green)">${r.commitment}</div>
        <div class="radar-metric-name">誠実さ</div>
      </div>
      <div class="radar-metric">
        <div class="radar-metric-val" style="color:var(--accent3)">${r.status}</div>
        <div class="radar-metric-name">承認欲求</div>
      </div>
      <div class="radar-metric">
        <div class="radar-metric-val" style="color:var(--accent2)">${r.safety}</div>
        <div class="radar-metric-name">心のゆとり</div>
      </div>
    </div>
    <div class="radar-interpretation">${data.radarInterpretation || ''}</div>
  `;
  return div;
}

function createMatrixSection(data) {
  const div = document.createElement('div');
  div.className = 'matrix-card fade-up section-gap';
  div.style.animationDelay = '0.06s';
  const m = data.matrix || { x: 20, y: 30 };
  div.innerHTML = `
    <div class="detail-card-header">
      <div class="detail-icon purple">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke="var(--accent3)" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
          <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
          <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
      </div>
      <div>
        <div class="detail-card-title">テンション・マトリクス💭</div>
        <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">今の気持ちの状態をマップで見てみよう</div>
      </div>
    </div>
    <div class="matrix-canvas-wrap">
      <canvas id="matrix-canvas"></canvas>
    </div>
    <p class="matrix-pos-label">現在の座標：ポジ/ネガ ${m.x > 0 ? '+' : ''}${m.x} ／ テンション ${m.y > 0 ? '+' : ''}${m.y}</p>
    <div class="radar-interpretation" style="margin-top:12px">${data.matrixInterpretation || ''}</div>
  `;
  return div;
}

function createLangSection(data) {
  const div = document.createElement('div');
  div.className = 'lang-analysis fade-up section-gap';
  div.style.animationDelay = '0.1s';
  const l = data.lang || { selfDisclosure: 35, mirroring: 50, pronounCount: 4, emojiSync: 40 };
  const bars = [
    { name: '自己開示率（心を開いてる度）', val: l.selfDisclosure, color: 'var(--accent)', desc: '自分のこと・プライベートを話してくれてる割合' },
    { name: 'ミラーリング同調率', val: l.mirroring, color: 'var(--accent3)', desc: 'あなたの言葉遣いや絵文字に合わせてくれてる度合い' },
  ];
  const barHtml = bars.map(b => `
    <div class="lang-bar-item">
      <div class="lang-bar-header">
        <span class="lang-bar-name">${b.name}</span>
        <span class="lang-bar-num">${b.val}%</span>
      </div>
      <div class="lang-bar-track">
        <div class="lang-bar-fill" data-pct="${b.val}" style="width:0%;background:${b.color}"></div>
      </div>
      <div style="font-size:11px;color:var(--text3);font-weight:500;margin-top:4px">${b.desc}</div>
    </div>
  `).join('');

  div.innerHTML = `
    <div class="detail-card-header">
      <div class="detail-icon blue">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke="var(--accent2)" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
          <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
        </svg>
      </div>
      <div>
        <div class="detail-card-title">メッセージ心理分析📊</div>
        <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">チャットの細かいパターンを数値化してみたよ</div>
      </div>
    </div>
    <div class="lang-metrics">
      <div class="lang-metric-item">
        <div class="lang-metric-val" style="color:var(--accent)">${l.selfDisclosure}%</div>
        <div class="lang-metric-label">自己開示率</div>
      </div>
      <div class="lang-metric-item">
        <div class="lang-metric-val" style="color:var(--accent3)">${l.mirroring}%</div>
        <div class="lang-metric-label">ミラーリング率</div>
      </div>
      <div class="lang-metric-item">
        <div class="lang-metric-val" style="color:var(--green)">${l.pronounCount}</div>
        <div class="lang-metric-label">一緒系ワード数</div>
      </div>
      <div class="lang-metric-item">
        <div class="lang-metric-val" style="color:var(--accent2)">${l.emojiSync || 0}%</div>
        <div class="lang-metric-label">絵文字シンク率</div>
      </div>
    </div>
    ${barHtml}
    <div class="radar-interpretation">${data.langInterpretation || ''}</div>
  `;
  return div;
}

function createApproachSection(data) {
  const div = document.createElement('div');
  div.className = 'approach-card fade-up section-gap';
  div.style.animationDelay = '0.14s';
  const approaches = data.approaches || [];
  const itemsHtml = approaches.map(a => `
    <div class="approach-item">
      <span class="approach-law">${a.law}</span>
      <div class="approach-title">${a.title}</div>
      <div class="approach-body">${a.body}</div>
      ${a.example ? `<div class="approach-example">💬 ${a.example}</div>` : ''}
    </div>
  `).join('');
  div.innerHTML = `
    <div class="detail-card-header">
      <div class="detail-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke="var(--green)" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
          <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
        </svg>
      </div>
      <div>
        <div class="detail-card-title">次の一手アクション🚀</div>
        <div style="font-size:11px;color:var(--text3);font-weight:600;margin-top:2px">心理学テクを使った具体的な作戦</div>
      </div>
    </div>
    ${itemsHtml}
  `;
  return div;
}