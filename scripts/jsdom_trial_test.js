/* jsdom click-through test: does one jsPsych 7.3.4 trial replace the previous
   one's DOM (expected) or accumulate (bug)? Prints node counts after each click. */
const { JSDOM } = require("jsdom");
const fs = require("fs");

const dom = new JSDOM("<!doctype html><html><body></body></html>", {
  runScripts: "outside-only", pretendToBeVisual: true, url: "http://localhost/",
});
const w = dom.window;
w.eval(fs.readFileSync("public/lib/jspsych.js", "utf8"));
w.eval(fs.readFileSync("public/lib/plugin-html-button-response.js", "utf8"));
w.eval(`
  const jsPsych = jsPsychModule.initJsPsych({
    on_trial_start: () => {
      const c = document.getElementById("jspsych-content");
      if (c) c.innerHTML = "";
    },
  });
  jsPsych.run([
    { type: jsPsychHtmlButtonResponse, stimulus: "TRIAL-ONE", choices: ["A"] },
    { type: jsPsychHtmlButtonResponse, stimulus: "TRIAL-TWO", choices: ["B"] },
  ]).then(() => { window.__done = true; });
`);

const click = () => w.document.querySelector("#jspsych-html-button-response-btngroup button")?.click();
const counts = (label) => {
  const s = w.document.querySelectorAll("#jspsych-html-button-response-stimulus").length;
  const b = w.document.querySelectorAll("#jspsych-html-button-response-btngroup").length;
  const text = w.document.getElementById("jspsych-content")?.textContent.trim().slice(0, 30);
  console.log(label, "→ stimulus nodes:", s, "| button groups:", b, "| text:", JSON.stringify(text));
  return s;
};

setTimeout(() => {
  counts("trial 1 shown");
  click();
  setTimeout(() => {
    const s = counts("after trial 1 answered");
    click();
    setTimeout(() => {
      const s2 = counts("after trial 2 answered");
      console.log(s2 > 1 ? "RESULT: STACKING (bug reproduced)" : "RESULT: REPLACING (normal)");
      process.exit(s2 > 1 ? 2 : 0);
    }, 150);
  }, 150);
}, 300);
