/* AI Detective — experiment client (jsPsych 7.3.4)
   Startup: saved session? → resume / already-played screen : fresh flow.
   Fresh: language → briefing → demographics → prepare screen (bootstrap) →
   Stage 2: training → preload → Inv.1 (12 × 2AFC) → Inv.2 (24 × yes/no) →
   score → alias → consent → survey → debrief.
   One trial per screen (no scrolling). Quit = jsPsych-native confirm trial;
   the interrupted trial is discarded (never saved). Reload-safe: every
   answered trial is on the server before the next one starts. */
(function () {
  "use strict";
  const API = "api";
  const ASSETS = "assets";
  const initFnBase = (typeof initJsPsych === "function") ? initJsPsych : jsPsychModule.initJsPsych;

  /* Dedicated game root: every jsPsych run and every custom screen renders
     into this one container. NOTE: jsPsych 7.3.4 plugins append without
     removing previous trials (verified by scripts/jsdom_trial_test.js), so we
     clear the content element on every on_trial_start. */
  const gameRoot = (() => {
    let el = document.getElementById("game-root");
    if (!el) {
      el = document.createElement("div");
      el.id = "game-root";
      document.body.appendChild(el);
    }
    return el;
  })();
  const initFn = (opts = {}) => initFnBase({
    display_element: gameRoot,
    on_trial_start: () => {
      const c = gameRoot.querySelector("#jspsych-content");
      if (c) c.innerHTML = "";
      scrollTop();
    },
  });

  const ctxKey = "aidtect_ctx";
  const outboxKey = "aidtect_outbox";

  const S = { lang: null, session: null, plan: null, quitRequest: false, consentVal: 0,
              birth_year: 0, gender: "", alias: "", jp: null, bgLoad: null, bgDone: false };

  /* Background image preloading: start fetching every trial image the moment
     the plan arrives (during briefing/training reading time). The preload gate
     before Investigation 1 then completes instantly unless the connection is
     genuinely slow. */
  function startBgPreload(plan) {
    const files = [
      ...plan.practice.flatMap((p) => (p.pair ? [p.pair.ai.file, p.pair.real.file] : [p.single.file])),
      ...plan.round1.flatMap((t) => [t.ai.file, t.real.file]),
      ...plan.round2.map((t) => t.file),
    ].map(img);
    let done = 0;
    S.bgLoad = Promise.all(files.map((src) => new Promise((res) => {
      const im = new Image();
      im.onload = im.onerror = () => { done++; res(); };
      im.src = src;
    })));
    S.bgLoad.count = files.length;
    return S.bgLoad;
  }

  /* ---------------- persistence layer ---------------- */
  async function postJSON(url, body, attempts = 3) {
    for (let i = 1; i <= attempts; i++) {
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        });
        if (!r.ok) throw new Error("http " + r.status);
        return await r.json();
      } catch (e) {
        if (i === attempts) throw e;
        await new Promise((res) => setTimeout(res, 700 * i));
      }
    }
  }
  const outbox = () => { try { return JSON.parse(localStorage.getItem(outboxKey) || "[]"); } catch { return []; } };
  const outboxSave = (q) => localStorage.setItem(outboxKey, JSON.stringify(q.slice(-200)));
  async function flushOutbox() {
    const rest = [];
    for (const item of outbox()) {
      try { await postJSON(`${API}/trial.php`, item.body); } catch { rest.push(item); }
    }
    outboxSave(rest);
  }
  async function saveTrial(payload) {
    try { await postJSON(`${API}/trial.php`, payload); }
    catch { outboxSave([...outbox(), { body: payload }]); }
  }

  /* ---------------- session context (resume support) ---------------- */
  const saveCtx = () => localStorage.setItem(ctxKey, JSON.stringify({
    sid: S.session, lang: S.lang, birth_year: S.birth_year, gender: S.gender, alias: S.alias }));
  const loadCtx = () => { try { return JSON.parse(localStorage.getItem(ctxKey) || "null"); } catch { return null; } };
  const clearCtx = () => localStorage.removeItem(ctxKey);

  /* ---------------- helpers ---------------- */
  const img = (f) => `${ASSETS}/${f}`;
  const rtTag = {
    get() { return (document.cookie.match(/aidtect_rt=(\w+)/) || [])[1] || ""; },
    ensure() {
      if (!this.get()) {
        const v = Math.random().toString(36).slice(2, 10);
        document.cookie = `aidtect_rt=${v};max-age=7776000;path=/;SameSite=Lax`;
      }
      return this.get();
    },
  };
  const scrollTop = () => window.scrollTo({ top: 0 });
  const trialMode = (on) => document.body.classList.toggle("trial-mode", on);
  const showQuit = () => {
    trialMode(true); scrollTop();
    if (document.getElementById("quit-btn")) return;
    const b = document.createElement("button");
    b.id = "quit-btn"; b.textContent = T("quit_btn");
    b.onclick = () => { S.quitRequest = true; S.jp?.finishTrial({ quit_interrupt: true }); };
    document.body.appendChild(b);
  };
  const hideQuit = () => { document.getElementById("quit-btn")?.remove(); trialMode(false); };

  async function quitFlow() {           // quit path: consent + short debrief only
    hideQuit(); await flushOutbox(); clearCtx();
    gameRoot.innerHTML = `<div class="jspsych-content"><div class="card"><div class="case-tag">${T("title")}</div>
      <h2>${T("quit_debrief")}</h2><p>${T("consent_q")}</p><p class="hint">${T("consent_what")}</p>
      <div><button class="jspsych-btn" id="c-yes">${T("consent_yes")}</button>
      <button class="jspsych-btn jspsych-btn--secondary" id="c-no">${T("consent_no")}</button></div></div></div>`;
    const send = async (v) => {
      try {
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "consent", consent: v });
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "quit" });
      } catch { /* best effort; trials already saved */ }
      gameRoot.innerHTML = `<div class="jspsych-content"><div class="card"><h2>${T("debrief_h")}</h2><p>${T("debrief_1")}</p>
        <p><a class="plain" href="leaderboard.html?lang=${S.lang}">${T("see_board")}</a> ·
           <a class="plain" href="contact.html?lang=${S.lang}">${T("prize_link")}</a></p></div></div>`;
    };
    document.getElementById("c-yes").onclick = () => send(1);
    document.getElementById("c-no").onclick = () => send(0);
  }

  /* ================= STARTUP: resume check ================= */
  async function startup() {
    await flushOutbox();
    const ctx = loadCtx();
    if (!ctx || !ctx.sid) return stage1();
    let st = null;
    try {
      st = await (await fetch(`${API}/state.php?session_id=${ctx.sid}`)).json();
    } catch { st = null; }
    if (!st || !st.ok) { clearCtx(); return stage1(); }
    S.lang = ctx.lang || "en"; window.GAME_LANG = S.lang;
    S.session = ctx.sid; S.birth_year = ctx.birth_year; S.gender = ctx.gender || "";
    S.alias = ctx.alias || "";
    if (st.completed) return playedScreen();
    S.plan = st.plan;
    startBgPreload(S.plan);                 // resume: images may differ per progress, load all
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
      <p><a class="plain" href="leaderboard.html?lang=${S.lang}">${T("see_board")}</a> ·
         <a class="plain" href="contact.html?lang=${S.lang}">${T("prize_link")}</a></p>
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

  /* ================= STAGE 1 (fresh player) ================= */
  function stage1() {
    const jsPsych = initFn();
    S.jp = jsPsych;
    const langTrial = {
      type: jsPsychHtmlButtonResponse,
      stimulus: `<h1>🕵️</h1><h2>${STRINGS.en.lang_pick}</h2><h2>${STRINGS.th.lang_pick}</h2>`,
      choices: ["English", "ไทย"],
      on_finish: (d) => { S.lang = d.response === 0 ? "en" : "th"; window.GAME_LANG = S.lang; },
    };
    const intro = {
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div>
        <h2>${T("intro_h")}</h2><p>${T("intro_p")}</p><p>${T("intro_p2")}</p></div>`,
      choices: [() => T("start")],
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
      on_finish: (d) => {                       // SYNC — bootstrap happens on prepare screen
        S.birth_year = Number(d.response.yob);
        S.gender = d.response.gender || "";
        S.alias = (d.response.alias || "").trim();
      },
    };
    jsPsych.run([langTrial, intro, demographics]).then(() => { if (!S.quitRequest) prepareScreen(); });
  }

  /* ---- prepare screen: visible bootstrap with retry ---- */
  async function prepareScreen() {
    const render = (html) => { gameRoot.innerHTML = `<div class="jspsych-content">${html}</div>`; scrollTop(); };
    render(`<div class="card"><h2>${T("loading")}</h2><p class="hint">${T("prep_sub")}</p></div>`);
    try {
      const res = await postJSON(`${API}/session.php`, {
        lang: S.lang, birth_year: S.birth_year, gender: S.gender, alias: S.alias,
        device: navigator.userAgent.slice(0, 250),
        screen: `${screen.width}x${screen.height}`,
        repeat_tag: rtTag.ensure(),
      });
      if (res && res.ok) {
        S.session = res.session_id; S.plan = res.plan;
        startBgPreload(S.plan);             // download silently during briefing + training
        saveCtx();
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

  /* ---- race-safe alias rename (server rejected the name at bootstrap) ---- */
  function renameScreen(render) {
    render(`<div class="card"><h2>${T("alias_h")}</h2>
      <p>${T("alias_taken")}</p>
      <p><label>${T("alias_label")}<br>
      <input type="text" id="rename" maxlength="16" value="${S.alias}"></label></p>
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
      } catch { /* let the server decide */ }
      S.alias = v;
      prepareScreen();
    };
  }

  /* ================= STAGE 2 (game; resumable) ================= */
  function stage2(saved) {
    const done = {
      practice: new Set(saved.practice || []),
      r1: new Set(saved.r1 || []),
      r2: new Set(saved.r2 || []),
    };
    const jsPsych = initFn();
    S.jp = jsPsych;
    const timeline = [];
    const plan = S.plan;
    const quitConfirmNode = () => ({
      timeline: [{
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="card"><h2>${T("quit_h")}</h2><p>${T("quit_p")}</p></div>`,
        choices: [() => T("quit_no"), () => T("quit_yes")],
        on_finish: (d) => {
          if (d.response === 1) { jsPsych.endExperiment(quitFlow); }
          else { S.quitRequest = false; }
        },
      }],
      conditional_function: () => S.quitRequest === true,
    });

    // ---- training (skippable on resume) ----
    if (done.practice.size < 2) {
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="card"><h2>${T("brief_h")}</h2><p>${T("brief_p")}</p></div>`,
        choices: [() => T("practice_go")],
        on_load: scrollTop,
      });
      const pPair = plan.practice.find((x) => x.pair).pair;
      const pSingle = plan.practice.find((x) => x.single).single;
      if (!done.practice.has(1)) timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_which")}</div>`,
        choices: pPair.ai_side === "left" ? [img(pPair.ai.file), img(pPair.real.file)] : [img(pPair.real.file), img(pPair.ai.file)],
        button_html: (src) => `<button class="pair-btn"><img src="${src}" alt="face"></button>`,
        on_load: () => { trialMode(true); scrollTop(); },
        on_finish: (d) => {
          trialMode(false);
          const chosen = pPair.ai_side === "left"
            ? (d.response === 0 ? pPair.ai.id : pPair.real.id) : (d.response === 0 ? pPair.real.id : pPair.ai.id);
          saveTrial({ session_id: S.session, round: "practice", trial_index: 1, image_id: chosen, rt_ms: Math.round(d.rt) });
        },
      });
      if (!done.practice.has(2)) timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_is_ai")}</div><img class="stim-single" src="${img(pSingle.file)}">`,
        choices: [() => T("yes"), () => T("no")],
        on_load: () => { trialMode(true); scrollTop(); },
        on_finish: (d) => {
          trialMode(false);
          saveTrial({ session_id: S.session, round: "practice", trial_index: 2, image_id: pSingle.id,
                      response: d.response === 0 ? 1 : 0, rt_ms: Math.round(d.rt) });
        },
      });
    }
    // ---- Investigation 1 gateway (fresh start of round) ----
    if (done.r1.size === 0) timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("practice_done_h")}</h2><p>${T("practice_done_p")}</p></div>`,
      choices: [() => T("r1_go")],
      on_load: scrollTop,
    });

    // ---- preload gate: safety net only — background preloader (started at
    // bootstrap) has usually cached everything during briefing/training ----
    const files = [
      ...plan.practice.flatMap((p) => (p.pair ? [p.pair.ai.file, p.pair.real.file] : [p.single.file])),
      ...plan.round1.flatMap((t) => [t.ai.file, t.real.file]),
      ...plan.round2.map((t) => t.file),
    ].map(img);
    if (S.bgLoad) S.bgLoad.then(() => { S.bgDone = true; });
    timeline.push({
      timeline: [{
        type: jsPsychPreload,
        images: files,
        show_progress_bar: true,
        message: () => `<div class="card"><h2>${T("loading")}</h2><p class="hint">${fmt(T("loading_sub"), { n: files.length })}</p></div>`,
        error_message: () => `<p>${T("save_retry")}</p>`,
      }],
      conditional_function: () => !S.bgDone,   // skip entirely if background load finished
    });

    // ---- Investigation 1: 12 × 2AFC ----
    plan.round1.forEach((t, i) => {
      if (done.r1.has(i + 1)) return;
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_which")}</div>
                         <div class="progress-line">${fmt(T("r1_label"), { n: i + 1 })}</div>`,
        choices: t.ai_side === "left" ? [img(t.ai.file), img(t.real.file)] : [img(t.real.file), img(t.ai.file)],
        button_html: (src) => `<button class="pair-btn"><img src="${src}" alt="face"></button>`,
        on_load: showQuit,
        on_finish: (d) => {
          hideQuit();
          if (S.quitRequest) return;                     // interrupted by quit — do not save
          const chosen = t.ai_side === "left"
            ? (d.response === 0 ? t.ai.id : t.real.id) : (d.response === 0 ? t.real.id : t.ai.id);
          saveTrial({ session_id: S.session, round: "1", trial_index: i + 1, image_id: chosen, rt_ms: Math.round(d.rt) });
        },
      });
      timeline.push(quitConfirmNode());
    });

    // ---- Investigation 2: 24 × AI-or-real ----
    if (done.r2.size === 0) timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("r2_h")}</h2><p>${T("r2_p")}</p></div>`,
      choices: [() => T("r2_go")],
      on_load: scrollTop,
    });
    plan.round2.forEach((t, i) => {
      if (done.r2.has(i + 1)) return;
      timeline.push({
        type: jsPsychHtmlButtonResponse,
        stimulus: () => `<div class="q-line">${T("q_is_ai")}</div><img class="stim-single" src="${img(t.file)}">
                         <div class="progress-line">${fmt(T("r2_label"), { n: i + 1 })}</div>`,
        choices: [() => T("yes"), () => T("no")],
        on_load: showQuit,
        on_finish: (d) => {
          hideQuit();
          if (S.quitRequest) return;
          saveTrial({ session_id: S.session, round: "2", trial_index: i + 1, image_id: t.id,
                      response: d.response === 0 ? 1 : 0, rt_ms: Math.round(d.rt) });
        },
      });
      timeline.push(quitConfirmNode());
    });

    // ---- score (authoritative, from server) ----
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div><h2>${T("score_h")}</h2>
        <div class="big-score" id="score">…</div><div>${T("score_line")}</div><p class="hint" id="score-sub"></p></div>`,
      choices: [() => T("continue")],
      on_load: async () => {
        scrollTop();
        const sc = await (await fetch(`${API}/score.php?session_id=${S.session}`)).json();
        const pct = sc && sc.total ? Math.round((100 * sc.correct) / sc.total) : 0;
        document.getElementById("score").textContent = pct + "%";
        document.getElementById("score-sub").textContent = fmt(T("score_sub"), { p: pct });
      },
    });

    // ---- consent (post-game) ----
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("consent_h")}</h2><p>${T("consent_q")}</p>
        <p class="hint">${T("consent_what")}</p></div>`,
      choices: [() => T("consent_yes"), () => T("consent_no")],
      on_load: scrollTop,
      on_finish: (d) => {
        S.consentVal = d.response === 0 ? 1 : 0;
        postJSON(`${API}/finalize.php`,
          { session_id: S.session, action: "consent", consent: S.consentVal }).catch(() => {});
      },
    });

    // ---- familiarity survey v2: 8 questions, conditional follow-ups (Q2/Q4/Q7) ----
    const opts = (arr) => [`<option value="">${T("select")}</option>`, ...arr.map((l, i) => `<option value="${i}">${l}</option>`)].join("");
    const qs = (id, parent, text, arr) =>
      `<p class="svq" data-q="${id}"${parent ? ` data-parent="${parent}" style="display:none"` : ""}>
        <label>${text}<br><select name="${id}"${parent ? "" : " required"}>${opts(arr)}</select></label></p>`;
    timeline.push({
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
        // show/hide conditional follow-ups; keep them required only when visible
        document.querySelectorAll("select[name^=q]").forEach((sel) => {
          sel.addEventListener("change", () => {
            const isYes = sel.value === "0";              // option 0 = Yes/เคย/ใช่
            document.querySelectorAll(`.svq[data-parent="${sel.name}"]`).forEach((row) => {
              const show = isYes;
              row.style.display = show ? "" : "none";
              row.querySelector("select").required = show;
              if (!show) row.querySelector("select").value = "";
            });
          });
        });
      },
      on_finish: async (d) => {
        const val = (id) => (d.response[id] === "" || d.response[id] === undefined ? null : Number(d.response[id]));
        const yn = (v) => (v === null ? null : (v === 0 ? 1 : 0));       // Yes(idx0)->1, No(idx1)->0
        const fr = (v) => v;                                              // 0..4 as-is
        const fam = (v) => (v === null ? null : v + 1);                   // 1..5
        const f = {
          f1: yn(val("q1")), f2: fr(val("q2")), f3: yn(val("q3")), f4: fr(val("q4")),
          f5: yn(val("q5")), f6: yn(val("q6")), f7: fr(val("q7")), f8: fam(val("q8")),
        };
        try {
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "survey", ...f });
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "finish" });
          await flushOutbox();
          clearCtx();
        } catch {}
      },
    });

    // ---- prize draw offer (everyone, consent-independent): Join / Skip ----
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><div class="case-tag">${T("title")}</div><h2>${T("prize_h")}</h2>
        <p>${T("prize_sub")}</p><p class="hint">${fmt(T("prize_alias_note"), { a: S.alias })}</p></div>`,
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
          try {
            await postJSON(`${API}/contact.php`, { alias: S.alias, contact: (d.response.contact || "").trim() });
          } catch {}
        },
      }],
      conditional_function: () => S.prizeJoin === true,
    });

    // ---- debrief ----
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
