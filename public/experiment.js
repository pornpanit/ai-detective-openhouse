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
  const initFn = (typeof initJsPsych === "function") ? initJsPsych : jsPsychModule.initJsPsych;
  const ctxKey = "aidtect_ctx";
  const outboxKey = "aidtect_outbox";

  const S = { lang: null, session: null, plan: null, quitRequest: false, consentVal: 0,
              birth_year: 0, gender: "" };

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
    sid: S.session, lang: S.lang, birth_year: S.birth_year, gender: S.gender }));
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
    b.onclick = () => { S.quitRequest = true; jsPsych.finishTrial({ quit_interrupt: true }); };
    document.body.appendChild(b);
  };
  const hideQuit = () => { document.getElementById("quit-btn")?.remove(); trialMode(false); };

  async function quitFlow() {           // quit path: consent + short debrief only
    hideQuit(); await flushOutbox(); clearCtx();
    const root = document.querySelector(".jspsych-content-wrapper");
    root.innerHTML = `<div class="jspsych-content"><div class="card"><div class="case-tag">${T("title")}</div>
      <h2>${T("quit_debrief")}</h2><p>${T("consent_q")}</p><p class="hint">${T("consent_what")}</p>
      <div><button class="jspsych-btn" id="c-yes">${T("consent_yes")}</button>
      <button class="jspsych-btn jspsych-btn--secondary" id="c-no">${T("consent_no")}</button></div></div></div>`;
    const send = async (v) => {
      try {
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "consent", consent: v });
        await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "quit" });
      } catch { /* best effort; trials already saved */ }
      root.innerHTML = `<div class="jspsych-content"><div class="card"><h2>${T("debrief_h")}</h2><p>${T("debrief_1")}</p>
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
    if (st.completed) return playedScreen();
    S.plan = st.plan;
    resumeScreen(st.saved);
  }

  function fullScreenCard(inner) {
    const root = document.querySelector(".jspsych-content-wrapper");
    root.innerHTML = `<div class="jspsych-content">${inner}</div>`;
    scrollTop();
    return root;
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
          <option value="Non-binary">${T("gender_nb")}</option></select></label></p></div>`,
      on_finish: (d) => {                       // SYNC — bootstrap happens on prepare screen
        S.birth_year = Number(d.response.yob);
        S.gender = d.response.gender || "";
      },
    };
    jsPsych.run([langTrial, intro, demographics]).then(() => { if (!S.quitRequest) prepareScreen(); });
  }

  /* ---- prepare screen: visible bootstrap with retry ---- */
  async function prepareScreen() {
    const root = document.querySelector(".jspsych-content-wrapper");
    const render = (html) => { root.innerHTML = `<div class="jspsych-content">${html}</div>`; scrollTop(); };
    render(`<div class="card"><h2>${T("loading")}</h2><p class="hint">${T("prep_sub")}</p></div>`);
    try {
      const res = await postJSON(`${API}/session.php`, {
        lang: S.lang, birth_year: S.birth_year, gender: S.gender,
        device: navigator.userAgent.slice(0, 250),
        screen: `${screen.width}x${screen.height}`,
        repeat_tag: rtTag.ensure(),
      });
      if (!res || !res.ok) throw new Error("bootstrap failed");
      S.session = res.session_id; S.plan = res.plan;
      saveCtx();
      stage2({ practice: [], r1: [], r2: [] });
    } catch (e) {
      render(`<div class="card"><h2>${T("prep_err")}</h2><p class="hint">${T("prep_retry")}</p>
        <button class="jspsych-btn" id="prep-retry">${T("retry")}</button></div>`);
      document.getElementById("prep-retry").onclick = prepareScreen;
    }
  }

  /* ================= STAGE 2 (game; resumable) ================= */
  function stage2(saved) {
    const done = {
      practice: new Set(saved.practice || []),
      r1: new Set(saved.r1 || []),
      r2: new Set(saved.r2 || []),
    };
    const jsPsych = initFn();
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

    // ---- preload gate ----
    const files = [
      ...plan.practice.flatMap((p) => (p.pair ? [p.pair.ai.file, p.pair.real.file] : [p.single.file])),
      ...plan.round1.flatMap((t) => [t.ai.file, t.real.file]),
      ...plan.round2.map((t) => t.file),
    ].map(img);
    timeline.push({
      type: jsPsychPreload,
      images: files,
      show_progress_bar: true,
      message: () => `<div class="card"><h2>${T("loading")}</h2><p class="hint">${fmt(T("loading_sub"), { n: files.length })}</p></div>`,
      error_message: () => `<p>${T("save_retry")}</p>`,
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

    // ---- alias ----
    timeline.push({
      type: jsPsychSurveyHtmlForm,
      button_label: () => T("continue"),
      html: () => `<div class="card"><h2>${T("alias_h")}</h2>
        <p><label>${T("alias_label")}<br><input type="text" name="alias" maxlength="16" autocomplete="off"></label></p></div>`,
      on_load: scrollTop,
      on_finish: async (d) => {
        const alias = (d.response.alias || "").trim();
        if (!/^[\w\u0E00-\u0E7F ]{3,16}$/.test(alias)) return;
        try { await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "alias", alias }); } catch {}
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

    // ---- familiarity survey (shown regardless of consent answer; required) ----
    const opts = (arr) => [`<option value="">${T("select")}</option>`, ...arr.map((l, i) => `<option value="${i}">${l}</option>`)].join("");
    const q = (id, text, arr) => `<p><label>${text}<br><select name="${id}" required>${opts(arr)}</select></label></p>`;
    timeline.push({
      type: jsPsychSurveyHtmlForm,
      button_label: () => T("continue"),
      html: () => `<div class="card"><h2>${T("survey_h")}</h2><p class="hint">${T("survey_sub")}</p>
        ${q("f1", T("q1"), T("opt_freq"))}${q("f2", T("q2"), T("opt_platforms"))}${q("f3", T("q3"), T("opt_time"))}
        ${q("f4", T("q4"), T("opt_ai"))}${q("f5", T("q5"), T("opt_ai"))}</div>`,
      on_load: scrollTop,
      on_finish: async (d) => {
        const f = (v) => (v === "" || v === undefined ? null : Number(v));
        try {
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "survey",
            f1: f(d.response.f1), f2: f(d.response.f2), f3: f(d.response.f3), f4: f(d.response.f4), f5: f(d.response.f5) });
          await postJSON(`${API}/finalize.php`, { session_id: S.session, action: "finish" });
          await flushOutbox();
          clearCtx();
        } catch {}
      },
    });

    // ---- debrief ----
    timeline.push({
      type: jsPsychHtmlButtonResponse,
      stimulus: () => `<div class="card"><h2>${T("debrief_h")}</h2><p>${T("debrief_1")}</p><p>${T("debrief_2")}</p>
        <p>${T("debrief_3")}</p>
        <p><a class="plain" href="leaderboard.html?lang=${S.lang}">${T("see_board")}</a> ·
           <a class="plain" href="contact.html?lang=${S.lang}">${T("prize_link")}</a></p></div>`,
      choices: [() => T("done_btn")],
      on_load: scrollTop,
    });

    jsPsych.run(timeline).then(() => hideQuit());
  }

  startup();
})();
