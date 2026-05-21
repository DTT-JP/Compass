/* ============================================================
   compass-admin.js
   admin詳細ページ用：ラベル変換関数 + 詳細レポート初期化
   ============================================================ */

'use strict';

/* ─── ラベル変換関数（compass.jsより移植） ─── */
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

/* ─── スコアカラー ─── */
function getScoreColor(score) {
  if (score >= 70) return 'var(--accent)';
  if (score >= 40) return 'var(--accent3)';
  return 'var(--accent2)';
}

/* ─── 入力サマリーHTMLを生成 ─── */
function buildInputSummaryHTML(data) {
  const isLine = !!data.isLine;
  const partner = data.partner || '—';
  const dateStr = data.date || data.created_at || '';
  const typeText = isLine ? 'メッセージ相談' : '言動・状況の相談';
  const badgeClass = isLine ? 'summary-type-badge line-type' : 'summary-type-badge sit-type';

  let optionsHtml = '';
  if (isLine) {
    const meetLabel = getMeetLabel(data.meetVal !== null && data.meetVal !== undefined ? data.meetVal : 50);
    const speedLabel = getSpeedLabel(data.replyLen !== null && data.replyLen !== undefined ? data.replyLen : 50);
    const moodStr = Array.isArray(data.mood) ? data.mood.join('、') : (data.mood || '—');
    optionsHtml = `
      <div class="summary-item-row"><span class="summary-item-label">ご相手</span><span class="summary-item-val">${esc(partner)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">相手との関係</span><span class="summary-item-val">${esc(data.rel || '—')}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">会った回数</span><span class="summary-item-val">${esc(meetLabel)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">返信スピード</span><span class="summary-item-val">${esc(speedLabel)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">普段と比べて</span><span class="summary-item-val">${esc(moodStr)}</span></div>`;
  } else {
    const durationLabel = getDurationLabel(data.duration !== null && data.duration !== undefined ? data.duration : 50);
    const tensionLabel = getTensionLabel(data.tension !== null && data.tension !== undefined ? data.tension : 50);
    const attitudeStr = Array.isArray(data.attitude) ? data.attitude.join('、') : (data.attitude || '—');
    optionsHtml = `
      <div class="summary-item-row"><span class="summary-item-label">ご相手</span><span class="summary-item-val">${esc(partner)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">場面・状況</span><span class="summary-item-val">${esc(data.scene || '—')}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">一緒にいた時間</span><span class="summary-item-val">${esc(durationLabel)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">場のテンション</span><span class="summary-item-val">${esc(tensionLabel)}</span></div>
      <div class="summary-item-row"><span class="summary-item-label">相手の様子</span><span class="summary-item-val">${esc(attitudeStr)}</span></div>`;
  }

  const mainInputLabel = isLine ? '気になるメッセージの内容' : '気になった言動・セリフ';
  const mainText = data.input || '';
  const extraText = data.extra || '';

  return `
    <div class="glass-card section-gap input-summary-card">
      <div class="summary-header">
        <span class="${badgeClass}">${typeText}</span>
        <span class="summary-date">${esc(dateStr)}</span>
      </div>
      <div class="summary-section">${optionsHtml}</div>
      <div class="summary-section text-section">
        <div class="summary-text-label">${mainInputLabel}</div>
        <div class="summary-text-val" style="white-space:pre-wrap;word-break:break-all">${esc(mainText)}</div>
      </div>
      ${extraText ? `
      <div class="summary-section text-section">
        <div class="summary-text-label">追加情報・背景</div>
        <div class="summary-text-val-extra" style="white-space:pre-wrap;word-break:break-all">${esc(extraText)}</div>
      </div>` : ''}
    </div>`;
}

/* ─── スコアカード + 心理・アドバイスをレンダリング ─── */
function renderResultMain(data, container) {
  const score = data.pulseRate || 0;
  const color = getScoreColor(score);

  container.innerHTML = `
    <!-- スコアカード -->
    <div class="score-card fade-up section-gap">
      <p class="score-label">脈あり・インタレスト指数</p>
      <div class="score-num" style="color:${color}">${score}%</div>
      <div class="score-track"><div class="score-fill" id="admin-res-bar" style="width:0%;background:${color}"></div></div>
      <p class="score-badge">${esc(data.levelBadge || '—')}</p>
    </div>

    <!-- 心理分析 -->
    <div class="detail-card fade-up section-gap" style="animation-delay:0.04s">
      <div class="detail-card-header">
        <div class="detail-icon pink">
          <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" fill="none" stroke-width="2" style="width:16px;height:16px;stroke:#ff6b8b">
            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
          </svg>
        </div>
        <span class="detail-card-title">本音の分析</span>
      </div>
      <p class="detail-card-body">${esc(data.psychology || '—')}</p>
    </div>

    <!-- アドバイス -->
    <div class="detail-card fade-up section-gap" style="animation-delay:0.08s">
      <div class="detail-card-header">
        <div class="detail-icon purple">
          <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" fill="none" stroke-width="2" style="width:16px;height:16px;stroke:var(--accent3)">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </div>
        <span class="detail-card-title">次の一手アドバイス</span>
      </div>
      <p class="detail-card-body">${esc(data.advice || '—')}</p>
    </div>

    <!-- 詳細レポート（radar/matrix/lang/approach） -->
    <div id="admin-advanced-report"></div>
  `;

  // スコアバーアニメーション
  setTimeout(() => {
    const bar = document.getElementById('admin-res-bar');
    if (bar) bar.style.width = score + '%';
  }, 100);

  // 詳細レポート描画
  const advContainer = document.getElementById('admin-advanced-report');
  if (advContainer && typeof renderAdvancedReport !== 'undefined') {
    renderAdvancedReport(data, advContainer);
  }
}

/* ─── HTML エスケープ ─── */
function esc(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ─── admin詳細ページ初期化 ─── */
function initAdminDetail(data) {
  if (!data) return;

  // 左カラム: 入力サマリー
  const leftCol = document.getElementById('admin-detail-left');
  if (leftCol) {
    leftCol.innerHTML = buildInputSummaryHTML(data);
  }

  // 右カラム: スコア + レポート
  const rightCol = document.getElementById('admin-detail-right');
  if (rightCol) {
    renderResultMain(data, rightCol);
  }
}

/* ─── JSON折りたたみ ─── */
function toggleJson(elId, btn) {
  const el = document.getElementById(elId);
  if (!el) return;
  const expanded = el.classList.toggle('expanded');
  btn.textContent = expanded ? '折りたたむ' : '続きを表示';
}

/* ─── JSONコピー ─── */
function copyJson(elId, btn) {
  const el = document.getElementById(elId);
  if (!el) return;
  navigator.clipboard.writeText(el.textContent || '').then(() => {
    const orig = btn.textContent;
    btn.textContent = 'コピーしました！';
    setTimeout(() => { btn.textContent = orig; }, 1800);
  });
}

/* ─── JSONダウンロード ─── */
function dlJson(elId, filename) {
  const el = document.getElementById(elId);
  if (!el) return;
  const blob = new Blob([el.textContent || ''], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ─── 共有無効化 ─── */
async function adminAjax(action, id) {
  const r = await fetch('admin.php?ajax=1', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, id })
  });
  return r.json();
}

async function adminDisableShare(id, btn) {
  if (!confirm('共有を無効化しますか？')) return;
  btn.disabled = true;
  try {
    const d = await adminAjax('disable_share', id);
    if (d.ok) {
      // 一覧・詳細のバッジ更新
      ['row-' + id, 'detail-actions-bar-' + id].forEach(domId => {
        const el = document.getElementById(domId);
        if (!el) return;
        const badge = el.querySelector('.shared-badge-on');
        if (badge) { badge.className = 'shared-badge-off'; badge.textContent = '非共有'; }
        el.querySelectorAll('.btn-admin-disable, .btn-admin-share-link').forEach(b => b.remove());
      });
    } else {
      alert('無効化に失敗しました: ' + (d.error || ''));
      btn.disabled = false;
    }
  } catch (e) {
    alert('エラー: ' + e.message);
    btn.disabled = false;
  }
}

async function adminDelete(id, isShared, btn) {
  if (isShared) {
    alert('共有中のデータは削除できません。先に共有を無効化してください。');
    return;
  }
  if (!confirm('#' + id + ' を削除しますか？この操作は取り消せません。')) return;
  btn.disabled = true;
  try {
    const d = await adminAjax('delete_consultation', id);
    if (d.ok) {
      const row = document.getElementById('row-' + id);
      if (row) {
        row.style.opacity = '0';
        row.style.transition = 'opacity 0.3s';
        setTimeout(() => row.remove(), 300);
      } else {
        window.location.href = window.location.href.split('&id=')[0];
      }
    } else if (d.error === 'shared') {
      alert('共有中のデータは削除できません。先に共有を無効化してください。');
      btn.disabled = false;
    } else {
      alert('削除に失敗しました: ' + (d.error || ''));
      btn.disabled = false;
    }
  } catch (e) {
    alert('エラー: ' + e.message);
    btn.disabled = false;
  }
}

async function adminDeleteError(id, btn) {
  if (!confirm('#' + id + ' を削除しますか？')) return;
  btn.disabled = true;
  try {
    const d = await adminAjax('delete_error', id);
    if (d.ok) {
      const row = document.getElementById('err-row-' + id);
      if (row) {
        row.style.opacity = '0';
        row.style.transition = 'opacity 0.3s';
        setTimeout(() => row.remove(), 300);
      } else {
        window.location.href = window.location.href.split('&id=')[0];
      }
    } else {
      alert('削除に失敗しました');
      btn.disabled = false;
    }
  } catch (e) {
    alert('エラー: ' + e.message);
    btn.disabled = false;
  }
}