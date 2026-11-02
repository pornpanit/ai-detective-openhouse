/* ═══════════════════════════════════════════════════════════════════════
   AI DETECTIVE — experiment client (jsPsych 7.3.4)
   Dark classified-terminal edition: stamps, sounds, confetti, score card.
   RT SAFETY: all audio + stamp animations fire POST-response or between rounds.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  const API = "api";
  const ASSETS = "assets";
  const initFnBase = (typeof initJsPsych === "function") ? initJsPsych : jsPsychModule.initJsPsych;

  /* ─── Game root ─── */
  const gameRoot = (() => {
    let el = document.getElementById("game-root");
    if (!el) { el = document.createElement("div"); el.id = "game-root"; document.body.appendChild(el); }
    return el;
  })();
  const initFn = (opts = {}) => initFnBase({
    display_element: gameRoot,
    on_trial_start: () => {
      const c = gameRoot.querySelector("#jspsych-content");
      if (c) c.innerHTML = "";
      scrollTop();
      removeStamp();
    },
  });

  const ctxKey = "aidtect_ctx";
  const outboxKey = "aidtect_outbox";
  const S = { lang: null, session: null, plan: null, quitRequest: false, consentVal: 0,
              birth_year: 0, gender: "", alias: "", jp: null, bgLoad: null, bgDone: false, prizeJoin: false };

  /* ═══════════════════ SOUND ENGINE (WebAudio, zero files) ═══════════════════ */
  const SoundFX = {
    ctx: null,
    muted: (() => { try { return localStorage.getItem("aidtect_muted") === "1"; } catch { return false; } })(),
    init() {
      if (!this.ctx) {
        try { this.ctx = new (window.AudioContext || window.webkitAudioContext)(); }
        catch (e) { this.ctx = null; }
      }
      if (this.ctx && this.ctx.state === "suspended") this.ctx.resume();
    },
    toggle() {
      this.muted = !this.muted;
      try { localStorage.setItem("aidtect_muted", this.muted ? "1" : "0"); } catch {}
      const btn = document.getElementById("mute-btn");
      if (btn) { btn.textContent = this.muted ? T("mute_off") : T("mute_on"); btn.classList.toggle("muted", this.muted); }
      return this.muted;
    },
    play(type) {
      if (this.muted) return;
      this.init();
      if (!this.ctx) return;
      const t = this.ctx.currentTime;
      try { this[type] && this[type](t); } catch (e) { /* audio never breaks the game */ }
    },
    /* Camera shutter: filtered noise burst + click + reverb tail */
    shutter(t) {
      const c = this.ctx, dur = 0.15;
      const buf = c.createBuffer(1, c.sampleRate * dur, c.sampleRate);
      const d = buf.getChannelData(0);
      for (let i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * Math.exp(-i / (d.length * 0.12));
      const src = c.createBufferSource(); src.buffer = buf;
      const filt = c.createBiquadFilter(); filt.type = "highpass"; filt.frequency.value = 1800;
      const gain = c.createGain(); gain.gain.setValueAtTime(0.2, t); gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
      src.connect(filt); filt.connect(gain); gain.connect(c.destination);
      src.start(t); src.stop(t + dur);
      // low click
      const osc = c.createOscillator(); osc.frequency.setValueAtTime(900, t); osc.frequency.exponentialRampToValueAtTime(150, t + 0.05);
      const g2 = c.createGain(); g2.gain.setValueAtTime(0.12, t); g2.gain.exponentialRampToValueAtTime(0.001, t + 0.05);
      osc.connect(g2); g2.connect(c.destination); osc.start(t); osc.stop(t + 0.06);
      // reverb tail
      const osc2 = c.createOscillator(); osc2.type = "sine"; osc2.frequency.value = 400;
      const g3 = c.createGain(); g3.gain.setValueAtTime(0, t + 0.04);
      g3.gain.linearRampToValueAtTime(0.05, t + 0.06);
      g3.gain.exponentialRampToValueAtTime(0.001, t + 0.15);
      osc2.connect(g3); g3.connect(c.destination); osc2.start(t + 0.04); osc2.stop(t + 0.16);
    },
    /* Typewriter: 10 rapid clicks over 0.8s */
    typewriter(t) {
      const c = this.ctx;
      for (let i = 0; i < 10; i++) {
        const osc = c.createOscillator(); osc.type = "square";
        osc.frequency.setValueAtTime(1100 + Math.random() * 800, t + i * 0.08);
        const g = c.createGain();
        g.gain.setValueAtTime(0.07, t + i * 0.08);
        g.gain.exponentialRampToValueAtTime(0.001, t + i * 0.08 + 0.04);
        osc.connect(g); g.connect(c.destination);
        osc.start(t + i * 0.08); osc.stop(t + i * 0.08 + 0.05);
      }
    },
    /* Whoosh: 0.8s filtered noise sweep */
    whoosh(t) {
      const c = this.ctx, dur = 0.8;
      const buf = c.createBuffer(1, c.sampleRate * dur, c.sampleRate);
      const d = buf.getChannelData(0);
      for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
      const src = c.createBufferSource(); src.buffer = buf;
      const filt = c.createBiquadFilter(); filt.type = "bandpass"; filt.Q.value = 1.5;
      filt.frequency.setValueAtTime(150, t); filt.frequency.exponentialRampToValueAtTime(2400, t + dur * 0.6);
      filt.frequency.exponentialRampToValueAtTime(300, t + dur);
      const gain = c.createGain();
      gain.gain.setValueAtTime(0.001, t);
      gain.gain.exponentialRampToValueAtTime(0.1, t + dur * 0.3);
      gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
      src.connect(filt); filt.connect(gain); gain.connect(c.destination);
      src.start(t); src.stop(t + dur);
    },
    /* Stamp thunk: low-frequency impact + echo */
    stamp(t) {
      const c = this.ctx;
      const osc = c.createOscillator(); osc.type = "sine";
      osc.frequency.setValueAtTime(140, t); osc.frequency.exponentialRampToValueAtTime(35, t + 0.12);
      const g = c.createGain(); g.gain.setValueAtTime(0.22, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.15);
      osc.connect(g); g.connect(c.destination); osc.start(t); osc.stop(t + 0.18);
      // echo
      const osc2 = c.createOscillator(); osc2.type = "sine"; osc2.frequency.value = 80;
      const g2 = c.createGain(); g2.gain.setValueAtTime(0, t + 0.08);
      g2.gain.linearRampToValueAtTime(0.08, t + 0.1);
      g2.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
      osc2.connect(g2); g2.connect(c.destination); osc2.start(t + 0.08); osc2.stop(t + 0.35);
    },
    /* Fanfare: extended 6-note phrase */
    fanfare(t) {
      const c = this.ctx, notes = [523, 587, 659, 784, 880, 1047];
      notes.forEach((freq, i) => {
        const osc = c.createOscillator(); osc.type = "triangle"; osc.frequency.value = freq;
        const g = c.createGain();
        const start = t + i * 0.14, dur = 0.45;
        g.gain.setValueAtTime(0, start);
        g.gain.linearRampToValueAtTime(0.13, start + 0.03);
        g.gain.exponentialRampToValueAtTime(0.001, start + dur);
        osc.connect(g); g.connect(c.destination); osc.start(start); osc.stop(start + dur);
      });
    },
    /* Confetti pop: short high blip */
    pop(t) {
      const c = this.ctx;
      const osc = c.createOscillator(); osc.type = "sine";
      osc.frequency.setValueAtTime(600 + Math.random() * 1200, t);
      osc.frequency.exponentialRampToValueAtTime(200, t + 0.06);
      const g = c.createGain(); g.gain.setValueAtTime(0.08, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.08);
      osc.connect(g); g.connect(c.destination); osc.start(t); osc.stop(t + 0.1);
    },
  };

  function setupMuteToggle() {
    if (document.getElementById("mute-btn")) return;
    const btn = document.createElement("button");
    btn.id = "mute-btn";
    btn.textContent = SoundFX.muted ? T("mute_off") : T("mute_on");
    btn.classList.toggle("muted", SoundFX.muted);
    btn.onclick = () => SoundFX.toggle();
    document.body.appendChild(btn);
  }

  /* ═══════════════════ VISUAL STAMP ( Investigation 1: glow effect, Investigation 2: text stamp ) ═══════════════════ */
  /* Investigation 1 + practice pair: subtle scale-up on selected face (no layout shift) */
  function faceFlash(btnEl) {
    if (!btnEl) return;
    btnEl.style.transition = "transform .2s ease";
    btnEl.style.transform = "scale(1.04)";
    setTimeout(() => {
      btnEl.style.transform = "scale(1)";
    }, 350);
  }

  /* Investigation 2: text stamp (player's explicit choice — safe) */
  function stampOverlay(choice) {
    removeStamp();
    const el = document.createElement("div");
    el.id = "stamp-overlay";
    el.className = choice === "ai" ? "stamp-ai" : "stamp-real";
    el.textContent = choice === "ai" ? T("stamp_ai") : T("stamp_real");
    document.body.appendChild(el);
    SoundFX.play("stamp");
  }
  function removeStamp() { document.getElementById("stamp-overlay")?.remove(); }

  /* ═══════════════════ CONFETTI (canvas particles) ═══════════════════ */
  function confettiBurst(duration = 2500) {
    const canvas = document.createElement("canvas");
    canvas.id = "confetti-canvas";
    canvas.width = window.innerWidth; canvas.height = window.innerHeight;
    document.body.appendChild(canvas);
    const ctx2d = canvas.getContext("2d");
    const colors = ["#f5a623", "#f5c542", "#e8534a", "#4e8d5b", "#3f7cac", "#c8d6e5"];
    const particles = [];
    for (let i = 0; i < 80; i++) {
      particles.push({
        x: canvas.width / 2 + (Math.random() - 0.5) * 100,
        y: canvas.height / 3,
        vx: (Math.random() - 0.5) * 12,
        vy: -(Math.random() * 10 + 4),
        size: Math.random() * 6 + 3,
        color: colors[i % colors.length],
        rot: Math.random() * Math.PI * 2,
        vr: (Math.random() - 0.5) * 0.3,
        life: 1,
      });
    }
    const start = performance.now();
    let popCount = 0;
    (function animate(now) {
      const elapsed = now - start;
      ctx2d.clearRect(0, 0, canvas.width, canvas.height);
      let alive = false;
      for (const p of particles) {
        if (p.life <= 0) continue;
        alive = true;
        p.x += p.vx; p.y += p.vy;
        p.vy += 0.3; p.rot += p.vr; p.life -= 0.012;
        if (p.life > 0) {
          ctx2d.save();
          ctx2d.translate(p.x, p.y); ctx2d.rotate(p.rot);
          ctx2d.globalAlpha = Math.max(p.life, 0);
          ctx2d.fillStyle = p.color;
          ctx2d.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
          ctx2d.restore();
        }
        if (popCount < 3 && Math.random() < 0.05) { SoundFX.play("pop"); popCount++; }
      }
      if (alive && elapsed < duration) requestAnimationFrame(animate);
      else canvas.remove();
    })(start);
  }

  /* ═══════════════════ COUNT-UP ANIMATION ═══════════════════ */
  function countUp(el, target, duration = 1200) {
    const start = performance.now();
    (function tick(now) {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased) + "%";
      if (p < 1) requestAnimationFrame(tick);
    })(start);
  }

  /* ═══════════════════ SCORE CARD (canvas, 1080×1080) ═══════════════════ */
  function getRank(pct) {
    if (pct >= 90) return { key: "rank_5", num: 5 };
    if (pct >= 75) return { key: "rank_4", num: 4 };
    if (pct >= 60) return { key: "rank_3", num: 3 };
    if (pct >= 40) return { key: "rank_2", num: 2 };
    return { key: "rank_1", num: 1 };
  }

  function renderScoreCard(canvas, alias, pct) {
    const W = 1080, H = 1080;
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext("2d");
    const rank = getRank(pct);
    const rankText = T(rank.key);
    const dateStr = new Date().toLocaleDateString(S.lang === "th" ? "th-TH" : "en-US",
      { year: "numeric", month: "short", day: "numeric" });

    // background: dark gradient
    const bgGrad = ctx.createRadialGradient(W / 2, -H * 0.1, 100, W / 2, H, H);
    bgGrad.addColorStop(0, "#1a2836"); bgGrad.addColorStop(0.5, "#0d1420"); bgGrad.addColorStop(1, "#0a0e14");
    ctx.fillStyle = bgGrad; ctx.fillRect(0, 0, W, H);

    // subtle border
    ctx.strokeStyle = "rgba(245,166,35,0.3)"; ctx.lineWidth = 3;
    ctx.strokeRect(30, 30, W - 60, H - 60);
    // corner accents
    ctx.strokeStyle = "rgba(245,166,35,0.6)"; ctx.lineWidth = 5;
    const cs = 50;
    [[40, 40, 1, 1], [W - 40, 40, -1, 1], [40, H - 40, 1, -1], [W - 40, H - 40, -1, -1]].forEach(([x, y, dx, dy]) => {
      ctx.beginPath(); ctx.moveTo(x + dx * cs, y); ctx.lineTo(x, y); ctx.lineTo(x, y + dy * cs); ctx.stroke();
    });

    // scanlines
    ctx.fillStyle = "rgba(0,0,0,0.04)";
    for (let y = 0; y < H; y += 4) ctx.fillRect(0, y, W, 2);

    // header
    ctx.textAlign = "center";
    ctx.fillStyle = "#f5a623";
    ctx.font = "700 36px 'Cascadia Code', Consolas, monospace";
    ctx.fillText("🔒 CLASSIFIED — CASE REPORT", W / 2, 120);

    // detective emoji
    ctx.font = "100px serif";
    ctx.fillText("🕵️", W / 2, 260);

    // title
    ctx.fillStyle = "#c8d6e5";
    ctx.font = "700 52px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(T("title"), W / 2, 340);

    // divider
    ctx.strokeStyle = "rgba(245,166,35,0.4)"; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(W / 2 - 200, 370); ctx.lineTo(W / 2 + 200, 370); ctx.stroke();

    // codename
    ctx.fillStyle = "#5a6a7a";
    ctx.font = "400 28px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(T("alias_h"), W / 2, 430);
    ctx.fillStyle = "#c8d6e5";
    ctx.font = "700 72px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(alias, W / 2, 510);

    // score
    ctx.fillStyle = "#5a6a7a";
    ctx.font = "400 28px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(T("score_line"), W / 2, 590);
    ctx.fillStyle = "#f5c542";
    ctx.font = "800 180px 'Cascadia Code', Consolas, monospace";
    ctx.shadowColor = "rgba(245,197,66,0.4)"; ctx.shadowBlur = 40;
    ctx.fillText(pct + "%", W / 2, 760);
    ctx.shadowBlur = 0;

    // rank
    ctx.fillStyle = "#f5a623";
    ctx.font = "700 42px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    const rankStars = "★".repeat(rank.num) + "☆".repeat(5 - rank.num);
    ctx.fillText(rankStars, W / 2, 830);
    ctx.fillStyle = "#c8d6e5";
    ctx.font = "600 36px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(rankText, W / 2, 880);

    // date
    ctx.fillStyle = "#5a6a7a";
    ctx.font = "400 24px 'Segoe UI', 'Noto Sans Thai', sans-serif";
    ctx.fillText(T("card_date") + ": " + dateStr, W / 2, 930);

    // QR corner (bottom-right)
    const qrSize = 130, qrX = W - qrSize - 50, qrY = H - qrSize - 50;
    ctx.fillStyle = "#fff";
    ctx.fillRect(qrX - 8, qrY - 8, qrSize + 16, qrSize + 16);
    const qrImg = new Image();
    // draw QR async
    const iconImg = new Image();
    let loaded = 0;
    const drawQR = () => {
      ctx.drawImage(qrImg, qrX, qrY, qrSize, qrSize);
      ctx.fillStyle = "#5a6a7a";
      ctx.font = "400 18px 'Segoe UI', 'Noto Sans Thai', sans-serif";
      ctx.textAlign = "right";
      ctx.fillText(T("card_scan"), qrX - 15, qrY + qrSize / 2);
      ctx.textAlign = "center";
    };
    qrImg.onload = () => { drawQR(); };
    qrImg.src = ASSETS + "/qr.png";

    // icon (top-left)
    const iconSize = 70;
    iconImg.onload = () => ctx.drawImage(iconImg, 50, 60, iconSize, iconSize);
    iconImg.src = ASSETS + "/icon.png";

    return canvas;
  }

  function shareScoreCard(canvas) {
    canvas.toBlob((blob) => {
      if (!blob) return;
      const file = new File([blob], "ai-detective-score.png", { type: "image/png" });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: T("title"), text: T("score_sub").replace("{p}", "X") })
          .catch(() => {});
      } else {
        // fallback: download
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = "ai-detective-score.png";
        a.click();
        URL.revokeObjectURL(a.href);
      }
    }, "image/png");
  }

  function saveScoreCard(canvas) {
    const a = document.createElement("a");
    a.href = canvas.toDataURL("image/png");
    a.download = "ai-detective-score.png";
    a.click();
  }

  /* ═══════════════════ SUSPECT HEADER ═══════════════════ */
  function suspectHeader(n) {
    return `<div class="suspect-header"><span class="dot"></span>${fmt(T("suspect_label"), { n })} — ${T("suspect_status")}</div>`;
  }

  /* ═══════════════════ PERSISTENCE LAYER ═══════════════════ */
  async function postJSON(url, body, attempts = 3) {
    for (let i = 1; i <= attempts; i++) {
      try {
        const r = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
        if (!r.ok) throw new Error("http " + r.status);
        return await r.json();
      } catch (e) { if (i === attempts) throw e; await new Promise(r => setTimeout(r, 700 * i)); }
    }
  }
  const outbox = () => { try { return JSON.parse(localStorage.getItem(outboxKey) || "[]"); } catch { return []; } };
  const outboxSave = (q) => localStorage.setItem(outboxKey, JSON.stringify(q.slice(-200)));
  async function flushOutbox() {
    const rest = [];
    for (const item of outbox()) { try { await postJSON(`${API}/trial.php`, item.body); } catch { rest.push(item); } }
    outboxSave(rest);
  }
  async function saveTrial(payload) {
    try { await postJSON(`${API}/trial.php`, payload); }
    catch { outboxSave([...outbox(), { body: payload }]); }
  }

  /* ═══════════════════ SESSION CONTEXT ═══════════════════ */
  const saveCtx = () => localStorage.setItem(ctxKey, JSON.stringify({ sid: S.session, lang: S.lang, birth_year: S.birth_year, gender: S.gender, alias: S.alias }));
  const loadCtx = () => { try { return JSON.parse(localStorage.getItem(ctxKey) || "null"); } catch { return null; } };
  const clearCtx = () => localStorage.removeItem(ctxKey);

  /* ═══════════════════ BACKGROUND PRELOADER ═══════════════════ */
  function startBgPreload(plan) {
    const files = [
      ...plan.practice.flatMap(p => p.pair ? [p.pair.ai.file, p.pair.real.file] : [p.single.file]),
      ...plan.round1.flatMap(t => [t.ai.file, t.real.file]),
      ...plan.round2.map(t => t.file),
    ].map(img);
    S.bgLoad = Promise.all(files.map(src => new Promise(res => {
      const im = new Image(); im.onload = im.onerror = () => res(); im.src = src;
    })));
  }

  /* ═══════════════════ HELPERS ═══════════════════ */
  const img = (f) => `${ASSETS}/${f}`;
  const rtTag = {
    get() { return (document.cookie.match(/aidtect_rt=(\w+)/) || [])[1] || ""; },
    ensure() { if (!this.get()) { const v = Math.random().toString(36).slice(2, 10); document.cookie = `aidtect_rt=${v};max-age=7776000;path=/;SameSite=Lax`; } return this.get(); },
  };
  const scrollTop = () => window.scrollTo({ top: 0 });
  const trialMode = (on) => document.body.classList.toggle("trial-mode", on);
  const showQuit = () => {
    trialMode(true); scrollTop();
    if (document.getElementById("quit-btn")) return;
    const b = document.createElement("button");
    b.id = "quit-btn"; b.textContent = T("quit_btn");
    b.onclick = () => { S.quitRequest = true; SoundFX.play("stamp"); S.jp?.finishTrial({ quit_interrupt: true }); };
    document.body.appendChild(b);
  };
  const hideQuit = () => { document.getElementById("quit-btn")?.remove(); trialMode(false); };

  async function quitFlow() {
    hideQuit(); await flushOutbox(); clearCtx();

    // Step 1: consent question
    gameRoot.innerHTML = `<div class="jspsych-content"><div class="card"><div class="case-tag">${T("title")}</div>
      <h2>${T("quit_debrief")}</h2><p>${T("consent_q")}</p><p class="hint">${T("consent_what")}</p>
      <div><button class="jspsych-btn" id="c-yes">${T("consent_yes")}</button>
      <button class="jspsych-btn jspsych-btn--secondary" id="c-no">${T("consent_no")}</button></div></div></div>`;

    const exitPage = () => {
      gameRoot.innerHTML = `<div class="jspsych-content"><div class="card">
        <h2>🕵️ ${T("title")}</h2>
        <p><a class="plain" href="leaderboard.html?lang=${S.lang}" style="font-size:1.3rem">${T("see_board")}</a></p>
        <p><a class="plain" href="live.html">${T("leaderboard_h")}</a></p>
        </div></div>`;
    };

    const finalize = async (consentVal) => {
      try {
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "consent", consent: consentVal });
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "quit" });
      } catch {}
      exitPage();
    };

    // If YES → save consent, show survey, then exit
    document.getElementById("c-yes").onclick = async () => {
      try { await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "consent", consent: 1 }); } catch {}

      // Step 2: survey (only if consented)
      const opts = (arr) => [`<option value="">${T("select")}</option>`, ...arr.map((l, i) => `<option value="${i}">${l}</option>`)].join("");
      const qs = (id, parent, text, arr) =>
        `<p class="svq" data-q="${id}"${parent ? ` data-parent="${parent}" style="display:none"` : ""}>
          <label>${text}<br><select name="${id}"${parent ? "" : " required"}>${opts(arr)}</select></label></p>`;

      gameRoot.innerHTML = `<div class="jspsych-content"><div class="card">
        <h2>${T("survey_h")}</h2><p class="hint">${T("survey_sub")}</p>
        ${qs("q1","","q1","opt_ever")}${qs("q2","q1","q2","opt_freq5")}
        ${qs("q3","","q3","opt_ever")}${qs("q4","q3","q4","opt_freq5")}
        ${qs("q5","","q5","opt_is")}
        ${qs("q6","","q6","opt_ever")}${qs("q7","q6","q7","opt_freq5")}
        ${qs("q8","","q8","opt_fam5")}
        </div>
        <div style="text-align:center;margin-top:12px">
          <button class="jspsych-btn" id="survey-submit">${T("continue")}</button>
        </div></div>`;

      // Resolve string keys for the survey
      gameRoot.querySelectorAll("select[name^=q]").forEach(sel => {
        // Replace question text with proper i18n
        const label = sel.closest("label");
        if (label && label.childNodes[0]) {
          const qKey = sel.name;
          label.childNodes[0].textContent = T(qKey) + "\n";
        }
        // Replace option text
        [...sel.options].forEach((opt, idx) => {
          if (idx === 0) { opt.textContent = T("select"); return; }
          const optKey = sel.name === "q2" || sel.name === "q4" || sel.name === "q7" ? "opt_freq5"
                       : sel.name === "q8" ? "opt_fam5"
                       : sel.name === "q5" ? "opt_is" : "opt_ever";
          const arr = T(optKey);
          if (arr && arr[idx-1] !== undefined) opt.textContent = arr[idx-1];
        });
      });

      // Branching logic (Q2/Q4/Q7 conditional on parent)
      gameRoot.querySelectorAll("select[name^=q]").forEach(sel => {
        sel.addEventListener("change", () => {
          const isYes = sel.value === "0";
          gameRoot.querySelectorAll(`.svq[data-parent="${sel.name}"]`).forEach(row => {
            row.style.display = isYes ? "" : "none";
            row.querySelector("select").required = isYes;
            if (!isYes) row.querySelector("select").value = "";
          });
        });
      });

      // Survey submit
      document.getElementById("survey-submit").onclick = async () => {
        // Validate required selects
        const required = [...gameRoot.querySelectorAll("select[required]")];
        const missing = required.filter(s => !s.value);
        if (missing.length > 0) { missing[0].focus(); return; }

        const val = (id) => {
          const el = gameRoot.querySelector(`select[name="${id}"]`);
          return el && el.value !== "" ? Number(el.value) : null;
        };
        const yn = (v) => v === null ? null : (v === 0 ? 1 : 0);
        const fam = (v) => v === null ? null : v + 1;
        const f = {
          f1: yn(val("q1")), f2: val("q2"), f3: yn(val("q3")), f4: val("q4"),
          f5: yn(val("q5")), f6: yn(val("q6")), f7: val("q7"), f8: fam(val("q8")),
        };
        try {
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "survey", ...f });
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "quit" });
        } catch {}
        exitPage();
      };
    };

    // If NO → skip survey, exit directly
    document.getElementById("c-no").onclick = () => finalize(0);
  }

  /* ═══════════════════ STARTUP ═══════════════════ */
  async function startup() {
    await flushOutbox();
    setupMuteToggle();
    const ctx = loadCtx();
    if (!ctx || !ctx.sid) return stage1();
    let st = null;
    try { st = await (await fetch(`${API}/state.php?session_id=${ctx.sid}`)).json(); } catch { st = null; }
    if (!st || !st.ok) { clearCtx(); return stage1(); }
    S.lang = ctx.lang || "en"; window.GAME_LANG = S.lang;
    S.session = ctx.sid; S.birth_year = ctx.birth_year; S.gender = ctx.gender || ""; S.alias = ctx.alias || "";
    if (st.completed) return playedScreen();
    S.plan = st.plan;
    startBgPreload(S.plan);
    resumeScreen(st.saved);
  }

  function fullScreenCard(inner) {
    gameRoot.innerHTML = `<div class="jspsych-content">${inner}</div>`;
    scrollTop();
    return gameRoot;
  }

  function playedScreen() {
    const root = fullScreenCard(`<div class="card"><div class="case-tag">${T("title")}</div>
      <h2>${T("played_h")}</h2><p>${T("played_sub")}</p>
      <p><a class="plain" href="leaderboard.html?lang=${S.lang}">${T("see_board")}</a></p>
      <button class="jspsych-btn jspsych-btn--secondary" id="again">${T("play_again")}</button></div>`);
    root.querySelector("#again").onclick = () => { clearCtx(); location.reload(); };
  }

  function resumeScreen(saved) {
    const root = fullScreenCard(`<div class="card"><div class="case-tag">${T("title")}</div>
      <h2>${T("resume_h")}</h2><p>${T("resume_sub")}</p>
      <button class="jspsych-btn" id="cont">${T("resume_continue")}</button>
      <button class="jspsych-btn jspsych-btn--secondary" id="fresh">${T("resume_fresh")}</button></div>`);
    root.querySelector("#cont").onclick = () => stage2(saved);
    root.querySelector("#fresh").onclick = () => { clearCtx(); location.reload(); };
  }

  /* ═══════════════════ STAGE 1 (signup) ═══════════════════ */
  function stage1() {
    const jsPsych = initFn();
    S.jp = jsPsych;
    const langTrial = {
      type: jsPsychHtmlButtonResponse,
      stimulus: `<h1 style="font-size:3rem">🕵️</h1><h2>${STRINGS.en.lang_pick}</h2><h2>${STRINGS.th.lang_pick}</h2>`,
      choices: ["English", "ไทย"],
      on_finish: (d) => { S.lang = d.response === 0 ? "en" : "th"; window.GAME_LANG = S.lang; },
    };
    const intro = {
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <div class="classified-stamp">TOP SECRET</div>
        <h2>${T("intro_h")}</h2><p>${T("intro_p")}</p><p>${T("intro_p2")}</p></div>`,
      choices: [() => T("start")],
      on_load: () => { scrollTop(); SoundFX.play("typewriter"); },
    };
    const years = [];
    for (let y = 2012; y >= 1900; y--) years.push(`<option value="${y}">${S.lang === "th" ? (y + 543) + " (ค.ศ. " + y + ")" : y}</option>`);
    const demographics = {
      type: jsPsychSurveyHtmlForm,
      button_label: () => T("continue"),
      html: () => `<div class="card"><div class="case-tag">${T("title")}</div><h2>${T("demo_h")}</h2>
        <p><label>${T("yob")}<br><select name="yob" required><option value="">${T("select")}</option>${years.join("")}</select></label></p>
        <p><label>${T("gender_q")}<br><select name="gender" required><option value="">${T("select")}</option>
          <option value="Female">${T("gender_f")}</option>
          <option value="Male">${T("gender_m")}</option>
          <option value="Non-binary">${T("gender_nb")}</option></select></label></p>
        <p><label>${T("alias_label")}<br>
          <input type="text" name="alias" required maxlength="16" autocomplete="off" spellcheck="false"
                 pattern="[\\w\\u0E00-\\u0E7F ]{3,16}" title="${T("alias_err")}"></label></p>
        <p class="form-error" id="alias-status"></p></div>`,
      on_load: () => {
        const input = document.querySelector('input[name="alias"]');
        const status = document.getElementById("alias-status");
        let timer = null;
        input?.addEventListener("input", () => {
          clearTimeout(timer);
          const v = input.value.trim();
          if (!/^[\w\u0E00-\u0E7F ]{3,16}$/.test(v)) { status.textContent = v ? T("alias_err") : ""; return; }
          status.textContent = T("alias_checking");
          timer = setTimeout(async () => {
            try {
              const r = await (await fetch(`${API}/check-alias.php?alias=${encodeURIComponent(v)}`, { cache: "no-store" })).json();
              status.textContent = r && r.available ? T("alias_ok") : T("alias_taken");
            } catch { status.textContent = ""; }
          }, 400);
        });
      },
      on_finish: (d) => {
        S.birth_year = Number(d.response.yob);
        S.gender = d.response.gender || "";
        S.alias = (d.response.alias || "").trim();
      },
    };
    jsPsych.run([langTrial, intro, demographics]).then(() => { if (!S.quitRequest) prepareScreen(); });
  }

  async function prepareScreen() {
    const render = (html) => { gameRoot.innerHTML = `<div class="jspsych-content">${html}</div>`; scrollTop(); };
    render(`<div class="card"><h2>${T("loading")}</h2><p class="hint">${T("prep_sub")}</p></div>`);
    try {
      const res = await postJSON(`${API}/session.php`, {
        lang: S.lang, birth_year: S.birth_year, gender: S.gender, alias: S.alias,
        device: navigator.userAgent.slice(0, 250), screen: `${screen.width}x${screen.height}`,
        repeat_tag: rtTag.ensure(),
      });
      if (res && res.ok) {
        S.session = res.session_id; S.plan = res.plan;
        startBgPreload(S.plan); saveCtx();
        stage2({ practice: [], r1: [], r2: [] });
        return;
      }
      if (res && res.error === "alias_taken") return renameScreen(render);
      if (res && res.error === "already_played") return playedScreen();
      throw new Error("bootstrap failed");
    } catch (e) {
      render(`<div class="card"><h2>${T("prep_err")}</h2><p class="hint">${T("prep_retry")}</p>
        <button class="jspsych-btn" id="prep-retry">${T("retry")}</button></div>`);
      document.getElementById("prep-retry").onclick = prepareScreen;
    }
  }

  function renameScreen(render) {
    render(`<div class="card"><h2>${T("alias_h")}</h2><p>${T("alias_taken")}</p>
      <p><label>${T("alias_label")}<br><input type="text" id="rename" maxlength="16" value="${S.alias}"></label></p>
      <p class="form-error" id="rename-status"></p>
      <button class="jspsych-btn" id="rename-go">${T("continue")}</button></div>`);
    const input = document.getElementById("rename");
    input.focus();
    document.getElementById("rename-go").onclick = async () => {
      const v = input.value.trim();
      const status = document.getElementById("rename-status");
      if (!/^[\w\u0E00-\u0E7F ]{3,16}$/.test(v)) { status.textContent = T("alias_err"); return; }
      status.textContent = T("alias_checking");
      try {
        const r = await (await fetch(`${API}/check-alias.php?alias=${encodeURIComponent(v)}`, { cache: "no-store" })).json();
        if (!r.available) { status.textContent = T("alias_taken"); return; }
      } catch {}
      S.alias = v; prepareScreen();
    };
  }

  /* ═══════════════════ STAGE 2 (game) ═══════════════════ */
  function stage2(saved) {
    const done = { practice: new Set(saved.practice || []), r1: new Set(saved.r1 || []), r2: new Set(saved.r2 || []) };
    const jsPsych = initFn();
    S.jp = jsPsych;
    const timeline = [];
    const plan = S.plan;
    const GAP = 500; // post-trial gap for stamp display

    const quitConfirmNode = () => ({
      timeline: [{
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="card"><h2>${T("quit_h")}</h2><p>${T("quit_p")}</p></div>`,
        choices: [() => T("quit_no"), () => T("quit_yes")],
        on_finish: (d) => {
          if (d.response === 1) {
            jsPsych.endExperiment();   // end timeline (no message — quitFlow renders the exit UI)
            quitFlow();
          }
          else { S.quitRequest = false; }
        },
      }],
      conditional_function: () => S.quitRequest === true,
    });

    // ── training ──
    if (done.practice.size < 2) {
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
          <div class="classified-stamp">TRAINING</div>
          <h2>${T("brief_h")}</h2><p>${T("brief_p")}</p></div>`,
        choices: [() => T("practice_go")],
        on_load: () => { scrollTop(); SoundFX.play("typewriter"); },
      });
      const pPair = plan.practice.find(x => x.pair).pair;
      const pSingle = plan.practice.find(x => x.single).single;
      if (!done.practice.has(1)) timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_which")}</div>`,
        choices: pPair.ai_side === "left" ? [img(pPair.ai.file), img(pPair.real.file)] : [img(pPair.real.file), img(pPair.ai.file)],
        button_html: (src) => `<button class="pair-btn"><img src="${src}" alt="face"></button>`,
        on_load: () => { trialMode(true); scrollTop(); },
        on_finish: (d) => {
          trialMode(false);
          SoundFX.play("shutter");
          const btn = document.querySelector(`[data-choice="${d.response}"]`);
          faceFlash(btn);  // visual flash — no text, no correctness info
          const chosen = pPair.ai_side === "left" ? (d.response === 0 ? pPair.ai.id : pPair.real.id) : (d.response === 0 ? pPair.real.id : pPair.ai.id);
          saveTrial({ session_id: S.session, round: "practice", trial_index: 1, image_id: chosen, rt_ms: Math.round(d.rt) });
        },
        post_trial_gap: GAP,
      });
      if (!done.practice.has(2)) timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_is_ai")}</div><img class="stim-single" src="${img(pSingle.file)}">`,
        choices: [() => T("yes"), () => T("no")],
        on_load: () => { trialMode(true); scrollTop(); },
        on_finish: (d) => {
          trialMode(false);
          SoundFX.play("shutter");
          stampOverlay(d.response === 0 ? "ai" : "real");
          saveTrial({ session_id: S.session, round: "practice", trial_index: 2, image_id: pSingle.id,
                      response: d.response === 0 ? 1 : 0, rt_ms: Math.round(d.rt) });
        },
        post_trial_gap: GAP,
      });
    }

    // ── Investigation 1 intro (animated) ──
    if (done.r1.size === 0) timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <div class="classified-stamp">⚠ INTERCEPTED</div>
        <h2>${T("r1_intro_h")}</h2><p class="typewriter">${T("r1_intro_p")}</p>
        <p class="hint">${T("practice_done_p")}</p></div>`,
      choices: [() => T("r1_go")],
      on_load: () => { scrollTop(); SoundFX.play("whoosh"); setTimeout(() => SoundFX.play("typewriter"), 300); },
    });
    if (done.r1.size > 0 && done.r1.size < 12 && done.practice.size === 2) timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <div class="classified-stamp">⚠ INTERCEPTED</div>
        <h2>${T("r1_intro_h")}</h2><p>${T("r1_intro_p")}</p>
        <p class="hint">${T("practice_done_p")}</p></div>`,
      choices: [() => T("r1_go")],
      on_load: scrollTop,
    });

    // ── preload gate ──
    const files = [
      ...plan.practice.flatMap(p => p.pair ? [p.pair.ai.file, p.pair.real.file] : [p.single.file]),
      ...plan.round1.flatMap(t => [t.ai.file, t.real.file]),
      ...plan.round2.map(t => t.file),
    ].map(img);
    if (S.bgLoad) S.bgLoad.then(() => { S.bgDone = true; });
    timeline.push({
      timeline: [{
        type: jsPsychPreload, images: files, show_progress_bar: true,
        message: () => `<div class="card"><h2>${T("loading")}</h2><p class="hint">${fmt(T("loading_sub"), { n: files.length })}</p></div>`,
        error_message: () => `<p>${T("save_retry")}</p>`,
      }],
      conditional_function: () => !S.bgDone,
    });

    // ── Investigation 1: 12 × 2AFC ──
    plan.round1.forEach((t, i) => {
      if (done.r1.has(i + 1)) return;
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `${suspectHeader(i + 1)}
                         <div class="q-line">${T("q_which")}</div>
                         <div class="progress-line">${fmt(T("r1_label"), { n: i + 1 })}</div>`,
        choices: t.ai_side === "left" ? [img(t.ai.file), img(t.real.file)] : [img(t.real.file), img(t.ai.file)],
        button_html: (src) => `<button class="pair-btn"><img src="${src}" alt="face"></button>`,
        on_load: showQuit,
        on_finish: (d) => {
          hideQuit();
          if (S.quitRequest) return;
          SoundFX.play("shutter");
          const btn = document.querySelector(`[data-choice="${d.response}"]`);
          faceFlash(btn);  // golden glow on selected face — no text, no correctness info
          const chosen = t.ai_side === "left"
            ? (d.response === 0 ? t.ai.id : t.real.id) : (d.response === 0 ? t.real.id : t.ai.id);
          saveTrial({ session_id: S.session, round: "1", trial_index: i + 1, image_id: chosen, rt_ms: Math.round(d.rt) });
        },
        post_trial_gap: GAP,
      });
      timeline.push(quitConfirmNode());
    });

    // ── Investigation 2 intro ──
    if (done.r2.size === 0) timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <div class="classified-stamp">🚨 ALERT</div>
        <h2>${T("r2_intro_h")}</h2><p class="typewriter">${T("r2_intro_p")}</p>
        <p class="hint">${T("r2_p")}</p></div>`,
      choices: [() => T("r2_go")],
      on_load: () => { scrollTop(); SoundFX.play("whoosh"); setTimeout(() => SoundFX.play("typewriter"), 300); },
    });

    // ── Investigation 2: 24 × AI/Real ──
    plan.round2.forEach((t, i) => {
      if (done.r2.has(i + 1)) return;
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `${suspectHeader(i + 1)}
                         <div class="q-line">${T("q_is_ai")}</div>
                         <img class="stim-single" src="${img(t.file)}">
                         <div class="progress-line">${fmt(T("r2_label"), { n: i + 1 })}</div>`,
        choices: [() => T("yes"), () => T("no")],
        on_load: showQuit,
        on_finish: (d) => {
          hideQuit();
          if (S.quitRequest) return;
          SoundFX.play("shutter");
          stampOverlay(d.response === 0 ? "ai" : "real");
          saveTrial({ session_id: S.session, round: "2", trial_index: i + 1, image_id: t.id,
                      response: d.response === 0 ? 1 : 0, rt_ms: Math.round(d.rt) });
        },
        post_trial_gap: GAP,
      });
      timeline.push(quitConfirmNode());
    });

    // ── consent ── (FIRST: ask before showing results)
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("consent_h")}</h2><p>${T("consent_q")}</p>
        <p class="hint">${T("consent_what")}</p></div>`,
      choices: [() => T("consent_yes"), () => T("consent_no")],
      on_load: scrollTop,
      on_finish: (d) => {
        S.consentVal = d.response === 0 ? 1 : 0;
        postJSON(`${API}/finalize.php`, { session_id: S.session, action: "consent", consent: S.consentVal }).catch(() => {});
      },
    });

    // ── survey v2 (conditional: only if consented) ──
    const opts = (arr) => [`<option value="">${T("select")}</option>`, ...arr.map((l, i) => `<option value="${i}">${l}</option>`)].join("");
    const qs = (id, parent, text, arr) =>
      `<p class="svq" data-q="${id}"${parent ? ` data-parent="${parent}" style="display:none"` : ""}>
        <label>${text}<br><select name="${id}"${parent ? "" : " required"}>${opts(arr)}</select></label></p>`;
    timeline.push({
      timeline: [{
        type: jsPsychSurveyHtmlForm,
        button_label: () => T("continue"),
        html: () => `<div class="card"><h2>${T("survey_h")}</h2><p class="hint">${T("survey_sub")}</p>
          ${qs("q1", "", T("q1"), T("opt_ever"))}${qs("q2", "q1", T("q2"), T("opt_freq5"))}
          ${qs("q3", "", T("q3"), T("opt_ever"))}${qs("q4", "q3", T("q4"), T("opt_freq5"))}
          ${qs("q5", "", T("q5"), T("opt_is"))}
          ${qs("q6", "", T("q6"), T("opt_ever"))}${qs("q7", "q6", T("q7"), T("opt_freq5"))}
          ${qs("q8", "", T("q8"), T("opt_fam5"))}</div>`,
        on_load: () => {
          scrollTop();
          document.querySelectorAll("select[name^=q]").forEach((sel) => {
            sel.addEventListener("change", () => {
              const isYes = sel.value === "0";
              document.querySelectorAll(`.svq[data-parent="${sel.name}"]`).forEach((row) => {
                row.style.display = isYes ? "" : "none";
                row.querySelector("select").required = isYes;
                if (!isYes) row.querySelector("select").value = "";
              });
            });
          });
        },
        on_finish: async (d) => {
          const val = (id) => (d.response[id] === "" || d.response[id] === undefined ? null : Number(d.response[id]));
          const yn = (v) => (v === null ? null : (v === 0 ? 1 : 0));
          const fr = (v) => v;
          const fam = (v) => (v === null ? null : v + 1);
          const f = { f1: yn(val("q1")), f2: fr(val("q2")), f3: yn(val("q3")), f4: fr(val("q4")),
                      f5: yn(val("q5")), f6: yn(val("q6")), f7: fr(val("q7")), f8: fam(val("q8")) };
          try {
            await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "survey", ...f });
          } catch {}
        },
      }],
      conditional_function: () => S.consentVal === 1,
    });

    // ── prize ── (before score card — score card is the final reward)
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <h2>${T("prize_h")}</h2><p>${T("prize_sub")}</p>
        <p class="hint">${fmt(T("prize_alias_note"), { a: S.alias })}</p></div>`,
      choices: [() => T("prize_join"), () => T("prize_skip")],
      on_load: scrollTop,
      on_finish: (d) => { S.prizeJoin = d.response === 0; },
    });
    timeline.push({
      timeline: [{
        type: jsPsychSurveyHtmlForm,
        button_label: () => T("prize_join"),
        html: () => `<div class="card"><h2>${T("prize_h")}</h2>
          <p class="hint">${fmt(T("prize_alias_note"), { a: S.alias })}</p>
          <p><label>${T("prize_contact")}<br><input type="text" name="contact" required maxlength="250"></label></p></div>`,
        on_finish: async (d) => {
          try { await postJSON(`${API}/contact.php`, { alias: S.alias, contact: (d.response.contact || "").trim() }); } catch {}
        },
      }],
      conditional_function: () => S.prizeJoin === true,
    });

    // ── SCORE + CARD (canvas) — the final reward moment ──
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <div class="classified-stamp">CASE CLOSED</div>
        <h2>${T("score_h")}</h2>
        <div class="big-score" id="score">…</div>
        <div>${T("score_line")}</div>
        <p class="hint" id="score-sub"></p>
        <div id="score-card-wrap"><canvas id="score-card-canvas"></canvas></div>
        <div class="card-actions">
          <button class="jspsych-btn" id="card-save" style="display:none">${T("card_save")}</button>
          <button class="jspsych-btn jspsych-btn--secondary" id="card-share" style="display:none">${T("card_share")}</button>
        </div></div>`,
      choices: [() => T("continue")],
      on_load: async () => {
        scrollTop();
        SoundFX.play("fanfare");
        // mark session as finished (always fires, regardless of consent)
        postJSON(`${API}/finalize.php`, { session_id: S.session, action: "finish" }).catch(() => {});
        const sc = await (await fetch(`${API}/score.php?session_id=${S.session}`)).json();
        const pct = sc && sc.total ? Math.round((100 * sc.correct) / sc.total) : 0;
        countUp(document.getElementById("score"), pct, 1400);
        setTimeout(() => confettiBurst(), 1400);
        document.getElementById("score-sub").textContent = fmt(T("score_sub"), { p: pct });
        const canvas = document.getElementById("score-card-canvas");
        if (canvas) {
          renderScoreCard(canvas, S.alias, pct);
          const saveBtn = document.getElementById("card-save");
          const shareBtn = document.getElementById("card-share");
          saveBtn.style.display = ""; shareBtn.style.display = "";
          saveBtn.onclick = () => saveScoreCard(canvas);
          shareBtn.onclick = () => shareScoreCard(canvas);
        }
      },
      on_finish: async () => {
        await flushOutbox();
        clearCtx();
      },
    });

    // ── debrief ──
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("debrief_h")}</h2><p>${T("debrief_1")}</p><p>${T("debrief_2")}</p>
        <p>${T("debrief_3")}</p>
        <p><a class="plain" href="leaderboard.html?lang=${S.lang}">${T("see_board")}</a> ·
           <a class="plain" href="live.html">LIVE</a></p></div>`,
      choices: [() => T("done_btn")],
      on_load: scrollTop,
    });

    jsPsych.run(timeline).then(() => hideQuit());
  }

  startup();
})();
