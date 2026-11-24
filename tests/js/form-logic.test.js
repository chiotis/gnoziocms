/*
 * Forms on the site with conditions and steps (themes/default/assets/js/form-logic.js), run as shipped in jsdom on the markup
 * components/form.twig draws. Checked: a part shown only for an answer starts hidden with its controls disabled (so they are
 * neither checked nor sent) and appears for the answer (is, is not, among ticked boxes, a ticked box, answered); a part that
 * depends on a hidden one is hidden too; a step is shown at a time with where the visitor is, Next waits for the step's
 * required fields (a list of boxes where one must be ticked too), Back goes back, a step hidden by its condition is skipped
 * and the send button is on the last step shown; after the server found a mistake the step that has it is opened; and a
 * form with neither is left alone.
 *
 *   node tests/js/form-logic.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch (error) {
  console.log('FAIL jsdom is not installed: run  npm ci --prefix tests/js');
  process.exit(1);
}

let failed = 0;
function check(label, ok, detail) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 400)));
}
function eq(label, actual, expected) { check(label, actual === expected, JSON.stringify(actual) + ' expected ' + JSON.stringify(expected)); }
const script = fs.readFileSync(path.join(__dirname, '..', '..', 'themes', 'default', 'assets', 'js', 'form-logic.js'), 'utf8');
const cond = (c) => ' data-condition="' + JSON.stringify(c).replace(/"/g, '&quot;') + '"';
const nav = '<div class="form-step-nav" data-form-step-nav hidden><button type="button" class="form-step-back" data-form-back>Back</button><button type="button" class="form-step-next" data-form-next>Next</button></div>';

function load(body) {
  const dom = new JSDOM('<!doctype html><body>' + body + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true });
  dom.window.HTMLElement.prototype.scrollIntoView = function () {};
  dom.window.scrollTo = function () {};
  dom.window.eval(script);
  // The page is still loading when the script runs here; the browser would start it on DOMContentLoaded.
  dom.window.GnozioFormLogic.init();
  return dom.window;
}
function shown(w, selector) {
  const el = w.document.querySelector(selector);
  if (!el) return 'missing';
  for (let n = el; n && n !== w.document.body; n = n.parentElement) {
    if (n.hidden || n.style.display === 'none') return false;
  }
  return true;
}
function set(w, selector, value) {
  const el = w.document.querySelector(selector);
  if (el.type === 'checkbox' || el.type === 'radio') el.checked = value; else el.value = value;
  el.dispatchEvent(new w.Event('change', { bubbles: true }));
}
function progress(w) { return w.document.querySelector('[data-form-progress-text]').textContent; }
function next(w) { w.document.querySelector('.form-step.is-current [data-form-next]').click(); }
function back(w) { w.document.querySelector('.form-step.is-current [data-form-back]').click(); }

// ---- conditions
const plain = '<form class="form-grid" data-form-logic data-msg-required="Required.">'
  + '<div class="form-field"><select name="topic"><option value="">Select</option><option value="sales">Sales</option><option value="support">Support</option></select></div>'
  + '<div class="form-field" id="company"' + cond({ field: 'topic', op: 'is', value: 'sales' }) + '><input name="company" required></div>'
  + '<fieldset id="products"' + cond({ field: 'topic', op: 'not', value: 'sales' }) + '><label><input type="checkbox" name="products[]" value="app">App</label><label><input type="checkbox" name="products[]" value="web">Web</label></fieldset>'
  + '<div class="form-field" id="version"' + cond({ field: 'products', op: 'is', value: 'app' }) + '><input name="version"></div>'
  + '<div class="form-field"><label><input type="checkbox" name="call" value="1">Call me</label></div>'
  + '<div class="form-field" id="phone"' + cond({ field: 'call', op: 'filled' }) + '><input name="phone" required></div>'
  + '<input type="hidden" name="source" value="ad"' + cond({ field: 'topic', op: 'empty' }) + '>'
  + '<button type="submit" data-form-submit>Send</button></form>';
let w = load(plain);
eq('a part shown only for an answer starts hidden, its control disabled', [shown(w, '#company'), w.document.querySelector('[name=company]').disabled].join(), 'false,true');
eq('a part shown when a field is not some answer starts shown', shown(w, '#products'), true);
eq('a hidden value shown only while nothing is chosen is sent at first', w.document.querySelector('[name=source]').disabled, false);
set(w, '[name=topic]', 'sales');
eq('the answer shows its part and enables it, and hides what is not for it', [shown(w, '#company'), w.document.querySelector('[name=company]').disabled, shown(w, '#products'), w.document.querySelector('[name=source]').disabled].join(), 'true,false,false,true');
set(w, '[name=topic]', 'support');
set(w, '[name="products[]"][value=app]', true);
eq('a box among the ticked ones shows its part', shown(w, '#version'), true);
set(w, '[name=topic]', 'sales');
eq('a part that depends on a hidden one is hidden too, though its box is still ticked', [shown(w, '#products'), shown(w, '#version'), w.document.querySelector('[name=version]').disabled].join(), 'false,false,true');
set(w, '[name=topic]', 'support');
eq('and comes back with it', [shown(w, '#version'), w.document.querySelector('[name=version]').disabled].join(), 'true,false');
set(w, '[name=call]', true);
eq('a ticked box shows what is for it', shown(w, '#phone'), true);
eq('a form without steps keeps its send button and no steps', [shown(w, '[data-form-submit]'), w.document.querySelectorAll('.is-current').length].join(), 'true,0');

// ---- steps
const stepped = (errorInThird) => '<form class="form-grid" data-form-logic data-msg-required="Required.">'
  + '<div class="form-progress" data-form-progress data-text="Step {n} of {count}" hidden><p data-form-progress-text></p><span><span data-form-progress-bar></span></span></div>'
  + '<div class="form-step" data-form-step id="s1"><div class="form-field"><select name="topic" required><option value="">Select</option><option value="sales">Sales</option><option value="support">Support</option></select></div>' + nav + '</div>'
  + '<div class="form-step" data-form-step id="s2"' + cond({ field: 'topic', op: 'is', value: 'support' }) + '><h3 class="form-step-title" tabindex="-1">Support</h3><div class="form-field"><input name="order" required></div>' + nav + '</div>'
  + '<div class="form-step" data-form-step id="s3"><h3 class="form-step-title" tabindex="-1">Rating</h3>'
  + '<fieldset class="form-rating"><label class="form-rating-star"><input type="radio" name="stars" value="1" required></label><label class="form-rating-star"><input type="radio" name="stars" value="2" required></label></fieldset>'
  + '<fieldset data-required-group><label><input type="checkbox" name="likes[]" value="a">A</label><label><input type="checkbox" name="likes[]" value="b">B</label></fieldset>'
  + (errorInThird ? '<small class="form-error" id="e">Required.</small>' : '') + nav + '</div>'
  + '<button type="submit" data-form-submit>Send</button></form>';
w = load(stepped(false));
eq('one step at a time, with where the visitor is', [shown(w, '#s1'), shown(w, '#s2'), shown(w, '#s3'), progress(w)].join(), 'true,false,false,Step 1 of 2');
eq('the send button waits for the last step, Back is not on the first', [shown(w, '[data-form-submit]'), shown(w, '#s1 [data-form-back]'), shown(w, '#s1 [data-form-next]')].join(), 'false,false,true');
next(w);
eq('Next waits for the step\'s required fields', progress(w), 'Step 1 of 2');
set(w, '[name=topic]', 'support');
eq('a step shown for the answer is counted', progress(w), 'Step 1 of 3');
next(w);
eq('Next goes on, with the step\'s title, and focus on it', [progress(w), w.document.activeElement && w.document.activeElement.textContent].join('|'), 'Step 2 of 3: Support|Support');
back(w);
eq('Back goes back', progress(w), 'Step 1 of 3');
set(w, '[name=topic]', 'sales');
next(w);
eq('a step hidden by its condition is skipped', [progress(w), shown(w, '#s3'), shown(w, '#s2')].join(), 'Step 2 of 2: Rating,true,false');
eq('the last step has the send button and no Next', [shown(w, '[data-form-submit]'), shown(w, '#s3 [data-form-next]'), shown(w, '#s3 [data-form-back]')].join(), 'true,false,true');
eq('the bar follows', w.document.querySelector('[data-form-progress-bar]').style.width, '100%');

w = load(stepped(true));
eq('after a mistake found by the server the step that has it is opened', [shown(w, '#s3'), shown(w, '#s1'), progress(w)].join(), 'true,false,Step 2 of 2: Rating');

// ---- a required list of boxes on a step that is not the last
const groups = '<form class="form-grid" data-form-logic data-msg-required="Pick one.">'
  + '<div class="form-progress" data-form-progress data-text="Step {n} of {count}" hidden><p data-form-progress-text></p><span><span data-form-progress-bar></span></span></div>'
  + '<div class="form-step" data-form-step><fieldset data-required-group><label><input type="checkbox" name="likes[]" value="a">A</label></fieldset>' + nav + '</div>'
  + '<div class="form-step" data-form-step><input name="x">' + nav + '</div>'
  + '<button type="submit" data-form-submit>Send</button></form>';
w = load(groups);
next(w);
eq('a required list of boxes with none ticked keeps the step, with the message', [progress(w), w.document.querySelector('[name="likes[]"]').validationMessage].join('|'), 'Step 1 of 2|Pick one.');
set(w, '[name="likes[]"]', true);
next(w);
eq('one ticked lets the visitor on', progress(w), 'Step 2 of 2');

// ---- a form with neither
w = load('<form class="form-grid"><input name="a" required><button type="submit">Send</button></form>');
eq('a form that does not ask for it is left alone', w.document.querySelector('form').hasAttribute('data-form-logic-ready'), false);

console.log(failed ? '\n' + failed + ' FAILED' : '\nALL PASSED');
process.exit(failed ? 1 : 0);
