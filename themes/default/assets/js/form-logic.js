/*
 * Forms on the site that show a part only for some answers, or that come in steps (components/form.twig).
 *
 * A part with data-condition='{"field":"…","op":"is|not|filled|empty","value":"…"}' is shown only while the field it names is
 * shown and its answer meets the test; a hidden part is out of the layout and its controls are disabled, so they are neither
 * checked by the browser nor sent. The server decides again from what was sent (FormConditions), so this is a convenience,
 * never the rule.
 *
 * A form with [data-form-step] parts is shown one step at a time: Next checks the step's required fields first, Back goes
 * back, a step that is hidden by its condition is skipped, and the button that sends the form is on the last step shown.
 * After the server found mistakes, the first step with one is opened. Without this script every step is shown, one under
 * the other, and the form works as one page.
 */
(function () {
  'use strict';

  function controlsOf(form, name) {
    return Array.prototype.filter.call(form.elements, function (el) {
      return el.name === name || el.name === name + '[]';
    });
  }

  /** The answer of a field as a list of values ([] when nothing is answered, or the field is hidden). */
  function answer(form, name) {
    var list = [];
    controlsOf(form, name).forEach(function (el) {
      if (el.disabled) return;
      if (el.type === 'radio' || el.type === 'checkbox') {
        if (el.checked) list.push(el.type === 'checkbox' && el.name === name ? '1' : String(el.value).trim());
        return;
      }
      if (el.type === 'file') return;
      var value = String(el.value || '').trim();
      if (value !== '') list.push(value);
    });
    return list.filter(function (v) { return v !== ''; });
  }

  function met(form, condition) {
    if (!condition || !condition.field) return true;
    var list = answer(form, condition.field);
    var wanted = String(condition.value === undefined ? '' : condition.value).trim();
    switch (condition.op) {
      case 'filled': return list.length > 0;
      case 'empty': return list.length === 0;
      case 'not': return list.indexOf(wanted) === -1;
      default: return list.indexOf(wanted) !== -1;
    }
  }

  function parse(el) {
    try { return JSON.parse(el.getAttribute('data-condition') || 'null'); } catch (e) { return null; }
  }

  /** The controls of a hidden part are disabled (and so neither checked nor sent); the ones it disabled come back with it. */
  function syncDisabled(form) {
    Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function (control) {
      var hide = !!control.closest('[data-condition][hidden]');
      if (hide && !control.disabled) {
        control.disabled = true;
        control.setAttribute('data-logic-disabled', '');
      } else if (!hide && control.hasAttribute('data-logic-disabled')) {
        control.disabled = false;
        control.removeAttribute('data-logic-disabled');
      }
    });
  }

  /** Decides every part in the order of the form, again while something changes (a part may depend on one that depends on another). */
  function applyConditions(form) {
    var parts = Array.prototype.slice.call(form.querySelectorAll('[data-condition]'));
    for (var round = 0; round < 6; round++) {
      var changed = false;
      parts.forEach(function (el) {
        var shown = met(form, parse(el));
        if (shown === el.hidden) { el.hidden = !shown; changed = true; }
      });
      syncDisabled(form);
      if (!changed) break;
    }
  }

  function setup(form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-form-step]'));
    var submit = form.querySelector('[data-form-submit]');
    var progress = form.querySelector('[data-form-progress]');
    var progressText = form.querySelector('[data-form-progress-text]');
    var progressBar = form.querySelector('[data-form-progress-bar]');
    var requiredText = form.getAttribute('data-msg-required') || 'This field is required.';
    var current = 0;

    function shownSteps() { return steps.filter(function (step) { return !step.hidden; }); }

    function showStep(index, focus) {
      var visible = shownSteps();
      if (!visible.length) return;
      index = Math.max(0, Math.min(index, visible.length - 1));
      current = index;
      steps.forEach(function (step) { step.classList.toggle('is-current', step === visible[index]); step.style.display = step === visible[index] ? '' : 'none'; });
      visible.forEach(function (step, i) {
        var nav = step.querySelector('[data-form-step-nav]');
        if (!nav) return;
        nav.hidden = false;
        nav.querySelector('[data-form-back]').hidden = i === 0;
        nav.querySelector('[data-form-next]').hidden = i === visible.length - 1;
        nav.hidden = i === 0 && i === visible.length - 1;
      });
      if (submit) submit.hidden = index !== visible.length - 1;
      if (progress) {
        progress.hidden = visible.length < 2;
        var title = visible[index].querySelector('.form-step-title');
        var text = (progress.getAttribute('data-text') || 'Step {n} of {count}').replace('{n}', String(index + 1)).replace('{count}', String(visible.length));
        if (progressText) progressText.textContent = text + (title ? ': ' + title.textContent.trim() : '');
        if (progressBar) progressBar.style.width = Math.round((index + 1) / visible.length * 100) + '%';
      }
      if (focus) {
        var target = visible[index].querySelector('.form-step-title') || visible[index].querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
        if (target) {
          target.focus({ preventScroll: true });
          var top = (progress && !progress.hidden ? progress : visible[index]).getBoundingClientRect().top + window.pageYOffset - 24;
          if (top < window.pageYOffset) window.scrollTo({ top: top, behavior: 'smooth' });
        }
      }
    }

    /** Whether the step can be left: the browser's own checks of its shown fields, and a list of boxes where one must be ticked. */
    function stepValid(step) {
      var controls = Array.prototype.filter.call(step.querySelectorAll('input, select, textarea'), function (el) { return !el.disabled && el.type !== 'hidden' && el.type !== 'file'; });
      for (var i = 0; i < controls.length; i++) {
        if (!controls[i].checkValidity()) { controls[i].reportValidity(); return false; }
      }
      var groups = step.querySelectorAll('fieldset[data-required-group]');
      for (var g = 0; g < groups.length; g++) {
        if (groups[g].closest('[hidden]')) continue;
        var boxes = groups[g].querySelectorAll('input[type="checkbox"]:not([disabled])');
        if (boxes.length && !groups[g].querySelector('input[type="checkbox"]:checked')) {
          boxes[0].setCustomValidity(requiredText);
          boxes[0].reportValidity();
          boxes[0].addEventListener('change', function clear() { boxes[0].setCustomValidity(''); boxes[0].removeEventListener('change', clear); });
          return false;
        }
      }
      return true;
    }

    applyConditions(form);
    form.addEventListener('input', function () { applyConditions(form); if (steps.length) showStep(Math.min(current, shownSteps().length - 1), false); });
    form.addEventListener('change', function () { applyConditions(form); if (steps.length) showStep(Math.min(current, shownSteps().length - 1), false); });

    if (!steps.length) return;
    form.addEventListener('click', function (e) {
      var next = e.target.closest('[data-form-next]');
      var back = e.target.closest('[data-form-back]');
      if (next) {
        e.preventDefault();
        if (stepValid(shownSteps()[current])) showStep(current + 1, true);
      } else if (back) {
        e.preventDefault();
        showStep(current - 1, true);
      }
    });
    // Enter in a field of a step that is not the last moves on instead of sending the form.
    form.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
      var visible = shownSteps();
      if (current < visible.length - 1 && visible[current].contains(e.target)) {
        e.preventDefault();
        if (stepValid(visible[current])) showStep(current + 1, true);
      }
    });

    // After the server found mistakes, the first step that has one is opened.
    var start = 0;
    var visible = shownSteps();
    for (var i = 0; i < visible.length; i++) {
      if (visible[i].querySelector('[aria-invalid="true"], small.form-error')) { start = i; break; }
    }
    showStep(start, false);
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-form-logic]'), function (form) {
      if (form.hasAttribute('data-form-logic-ready')) return;
      form.setAttribute('data-form-logic-ready', '');
      setup(form);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  if (typeof window !== 'undefined') window.GnozioFormLogic = { init: init, applyConditions: applyConditions, met: met };
}());
