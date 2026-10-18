/*
 * GnozioCMS theme audit: run it in the browser console of a LOCAL or staging copy of the site.
 *
 *   1. Start the site (php -S 127.0.0.1:8087 -t public public/index.php) and open any page.
 *   2. Paste this whole file into the browser console (or copy it to custom/assets/js/ and add a
 *      <script> by hand while you work; never leave it on a live site).
 *   3. Run one of:
 *        await themeAudit.pages(['/', '/en/', '/about'])          accessibility (axe-core), light and dark
 *        await themeAudit.pages(['/'], {width: 375, menu: true})  the same on a phone, with the menu open
 *        await themeAudit.reflow(['/', '/en/services'])           no sideways scrolling at 320 px
 *        await themeAudit.tokens()                                contrast of the main colour pairs, all palettes
 *        await themeAudit.rows(['/a-page-with-widths'])           blocks that share a row (a Width under 100%): desktop, tablet and phone,
 *                                                                 nothing wider than its column, a phone shows every block full width
 *        await themeAudit.containers(['/a-page-with-a-container'])  containers (rows of columns): no column wider than the container or
 *                                                                 sticking out of it, no sideways scrolling, and a column that is set to
 *                                                                 stack to one is full width on that screen
 *
 * `pages` loads axe-core from cdnjs into the page being audited, so it needs internet access and is
 * a development tool only. Results are plain objects; an empty `violations` list means clean.
 * "review" items are things a tool cannot judge (mostly text over photos): look at them by eye.
 */
(() => {
  const AXE = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.2/axe.min.js';
  const RULES = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  async function open(path, width, mode) {
    const frame = document.createElement('iframe');
    frame.style.cssText = `position:fixed;left:-9999px;top:0;width:${width}px;height:900px;border:0`;
    document.body.appendChild(frame);
    await new Promise((resolve) => { frame.onload = resolve; frame.src = path; });
    const doc = frame.contentDocument;
    if (mode) {
      doc.documentElement.classList.toggle('dark', mode === 'dark');
      doc.documentElement.setAttribute('data-mode', mode);
    }
    // Colour changes and animations would make computed colours unreliable mid-transition.
    const style = doc.createElement('style');
    style.textContent = '*,*::before,*::after{transition:none!important;animation:none!important}';
    doc.head.appendChild(style);
    await wait(600);
    return { frame, win: frame.contentWindow, doc };
  }

  /**
   * Accessibility scan of pages with axe-core, in the modes given (default both), at a width (default 1280).
   * menu: true opens the phone menu first and scans that state (use a width under 960).
   */
  async function pages(paths, { modes = ['light', 'dark'], width = 1280, menu = false } = {}) {
    const report = {};
    for (const path of paths) {
      for (const mode of modes) {
        const { frame, win, doc } = await open(path, width, mode);
        if (menu) {
          const opener = doc.querySelector('[data-mobile-open]');
          if (!opener || opener.offsetParent === null) throw new Error(`No phone menu button at ${width}px on ${path}`);
          opener.click();
          await wait(600);
        }
        const script = doc.createElement('script');
        script.src = AXE;
        doc.head.appendChild(script);
        await new Promise((resolve, reject) => { script.onload = resolve; script.onerror = () => reject(new Error('Could not load axe-core (offline?)')); });
        const result = await win.axe.run(doc, { runOnly: { type: 'tag', values: RULES } });
        report[`${path} (${mode}${width !== 1280 ? ', ' + width + 'px' : ''}${menu ? ', menu open' : ''})`] = {
          violations: result.violations.map((v) => `${v.id} [${v.impact}] x${v.nodes.length}: ${v.nodes[0].target.join(' ').slice(0, 90)}`),
          review: result.incomplete.map((v) => `${v.id} x${v.nodes.length}`),
        };
        frame.remove();
      }
    }
    const failing = Object.fromEntries(Object.entries(report).filter(([, r]) => r.violations.length));
    return { scanned: Object.keys(report).length, failing, report };
  }

  /** Pages must not scroll sideways at a phone width (WCAG 1.4.10). Scrollable tables and strips are fine. */
  async function reflow(paths, { width = 320 } = {}) {
    const problems = {};
    for (const path of paths) {
      const { frame, doc } = await open(path, width, null);
      const over = doc.documentElement.scrollWidth - width;
      if (over > 0) {
        problems[path] = {
          over,
          offenders: [...doc.querySelectorAll('body *')]
            .filter((el) => el.getBoundingClientRect().right > width + 1 && el.getBoundingClientRect().width > 0 && !el.closest('.slider-track, .tabs-list, .table-wrap, [tabindex="0"]'))
            .slice(0, 5)
            .map((el) => `${el.tagName}.${String(el.className).slice(0, 40)}`),
        };
      }
      frame.remove();
    }
    return { scanned: paths.length, problems };
  }

  /** Blocks that share a row: at desktop, tablet and phone widths each stays inside its column, and on a phone each is as wide as the row. */
  async function rows(paths, { widths = [1280, 800, 375] } = {}) {
    const problems = {};
    let rowsSeen = 0;
    for (const path of paths) {
      for (const width of widths) {
        const { frame, doc } = await open(path, width, null);
        for (const row of doc.querySelectorAll('.block-row')) {
          rowsSeen++;
          const box = row.getBoundingClientRect();
          for (const block of row.children) {
            const rect = block.getBoundingClientRect();
            const label = `${path} @${width} ${block.className.split(' ').slice(1, 3).join('.')}`;
            const wide = [...block.querySelectorAll('*')].filter((el) => {
              const r = el.getBoundingClientRect();
              return r.width > 0 && (r.right > rect.right + 1 || r.left < rect.left - 1) && !el.closest('.slider-track, .tabs-list, .table-wrap, .gallery-list, [tabindex="0"], .sr-only, .form-honeypot');
            });
            if (wide.length) (problems[label] ||= []).push(`${wide.length} element(s) wider than the column, e.g. ${wide[0].tagName}.${String(wide[0].className).slice(0, 30)}`);
            if (rect.right > box.right + 1) (problems[label] ||= []).push('outside the row');
            if (width <= 640 && Math.abs(rect.width - box.width) > 1) (problems[label] ||= []).push(`phone: ${Math.round(rect.width)}px of ${Math.round(box.width)}px`);
          }
        }
        frame.remove();
      }
    }
    return { scanned: paths.length, rows: rowsSeen, problems };
  }

  /** Containers: at desktop, tablet and phone widths no column is wider than the container or sticks out of it, nothing inside a column is wider than the column, and a column that stacks to one fills the row. */
  async function containers(paths, { widths = [1280, 800, 375] } = {}) {
    const problems = {};
    let seen = 0;
    for (const path of paths) {
      for (const width of widths) {
        const { frame, doc } = await open(path, width, null);
        for (const row of doc.querySelectorAll('.bkc-row')) {
          seen++;
          const box = row.getBoundingClientRect();
          const stackedOne = row.className.split(' ').includes(width <= 640 ? 'bkc-p-one' : (width <= 960 ? 'bkc-t-one' : 'bkc-d-one'));
          for (const column of row.children) {
            const rect = column.getBoundingClientRect();
            const label = `${path} @${width} ${column.className.split(' ').slice(1, 3).join('.')}`;
            if (rect.right > box.right + 1 || rect.left < box.left - 1) (problems[label] ||= []).push('outside the container');
            if (stackedOne && Math.abs(rect.width - box.width) > 1) (problems[label] ||= []).push(`stacked: ${Math.round(rect.width)}px of ${Math.round(box.width)}px`);
            const wide = [...column.querySelectorAll('*')].filter((el) => {
              const r = el.getBoundingClientRect();
              return r.width > 0 && (r.right > rect.right + 1 || r.left < rect.left - 1) && !el.closest('.slider-track, .tabs-list, .table-wrap, .gallery-list, [tabindex="0"], .sr-only, .form-honeypot');
            });
            if (wide.length) (problems[label] ||= []).push(`${wide.length} element(s) wider than the column, e.g. ${wide[0].tagName}.${String(wide[0].className).slice(0, 30)}`);
          }
        }
        if (doc.documentElement.scrollWidth > doc.documentElement.clientWidth + 1) (problems[`${path} @${width}`] ||= []).push('sideways scrolling');
        frame.remove();
      }
    }
    return { scanned: paths.length, containers: seen, problems };
  }

  /** Contrast of the colour pairs the theme relies on, in every palette and both modes (AA needs 4.5). */
  async function tokens() {
    const parse = (value) => {
      let m = value.match(/rgba?\(([^)]+)\)/);
      if (m) { const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2] }; }
      m = value.match(/color\(srgb ([^)]+)\)/);
      const p = m[1].split(/[ /]+/).filter(Boolean).map(Number);
      return { r: p[0] * 255, g: p[1] * 255, b: p[2] * 255 };
    };
    const lum = ({ r, g, b }) => {
      const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)]; return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const style = document.createElement('style');
    style.textContent = '*{transition:none!important}';
    document.head.appendChild(style);
    const probe = document.createElement('div');
    probe.innerHTML = '<p data-c style="color:var(--color-text)">a</p><p data-c style="color:var(--color-muted)">a</p><p data-c style="color:var(--accent)">a</p><span data-b style="background:var(--accent)"></span><span data-b style="background:var(--accent-soft)"></span><span data-b style="background:var(--accent-hover)"></span><em data-c style="color:var(--accent-contrast)">a</em>';
    document.body.appendChild(probe);
    const root = document.documentElement;
    const saved = [root.className, root.getAttribute('data-mode'), root.getAttribute('data-theme')];
    const rows = {};
    let worst = { ratio: 99, pair: '' };
    for (const mode of ['light', 'dark']) {
      root.classList.toggle('dark', mode === 'dark');
      root.setAttribute('data-mode', mode);
      for (const palette of ['slate', 'indigo', 'emerald', 'teal', 'rose', 'amber']) {
        root.setAttribute('data-theme', palette);
        await wait(40);
        const colour = [...probe.querySelectorAll('[data-c]')].map((el) => parse(getComputedStyle(el).color));
        const bg = [...probe.querySelectorAll('[data-b]')].map((el) => parse(getComputedStyle(el).backgroundColor));
        const page = parse(getComputedStyle(document.body).backgroundColor);
        const pairs = {
          'text on page': ratio(colour[0], page),
          'muted on page': ratio(colour[1], page),
          'accent on page': ratio(colour[2], page),
          'muted on accent-soft': ratio(colour[1], bg[1]),
          'accent on accent-soft': ratio(colour[2], bg[1]),
          'button text on accent': ratio(colour[3], bg[0]),
          'button text on accent-hover': ratio(colour[3], bg[2]),
        };
        rows[`${mode} ${palette}`] = Object.fromEntries(Object.entries(pairs).map(([k, v]) => [k, +v.toFixed(2)]));
        for (const [pair, value] of Object.entries(pairs)) {
          if (value < worst.ratio) worst = { ratio: +value.toFixed(2), pair: `${pair} (${mode} ${palette})` };
        }
      }
    }
    probe.remove();
    style.remove();
    root.className = saved[0];
    saved[1] === null ? root.removeAttribute('data-mode') : root.setAttribute('data-mode', saved[1]);
    saved[2] === null ? root.removeAttribute('data-theme') : root.setAttribute('data-theme', saved[2]);
    return { worst, below45: Object.entries(rows).flatMap(([label, pairs]) => Object.entries(pairs).filter(([, v]) => v < 4.5).map(([k, v]) => `${label}: ${k} ${v}`)), rows };
  }

  window.themeAudit = { pages, reflow, rows, containers, tokens };
})();
