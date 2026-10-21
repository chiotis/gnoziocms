/*
 * The height profile of a route linked to its map (themes/default/assets/js/route-profile.js), run as shipped in jsdom with the map's
 * own script (themes/default/blocks/map/block.js) drawing on a stand-in for Leaflet. The drawing is the real one (GeoView::profileSvg,
 * drawn by PHP). Checked: the pointer on the profile shows the distance and the height before the map is shown; once the map is
 * drawn (`geo:ready`) it also marks the same place on the line; the pointer on the map near the line marks the profile, and away from
 * it clears it; the keyboard (Home, End, arrows, Escape) moves along it as a slider; a map drawn before the script still joins; a page
 * without a profile is left alone; and the helpers (the length of a line in parts, the place at a share of it, the nearest point).
 *
 *   node tests/js/route-profile.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

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
const repo = path.join(__dirname, '..', '..');
const profileJs = fs.readFileSync(path.join(repo, 'themes', 'default', 'assets', 'js', 'route-profile.js'), 'utf8');
const mapJs = fs.readFileSync(path.join(repo, 'themes', 'default', 'blocks', 'map', 'block.js'), 'utf8');

// A straight line east along a parallel, eleven points; the profile climbs from 100 m to 200 m along it.
const line = Array.from({ length: 11 }, (_, i) => [35, 26 + i * 0.01]);
const LENGTH = 9.1;
const profile = Array.from({ length: 11 }, (_, i) => [Math.round(LENGTH * i / 10 * 1000) / 1000, 100 + i * 10]);
const svg = execFileSync('php', ['-r', 'require $argv[1]; echo GnozioCMS\\GeoView::profileSvg(json_decode($argv[2], true), "Height profile: 100–200 m, 9.1 km");', path.join(repo, 'vendor', 'autoload.php'), JSON.stringify(profile)]).toString();
check('the drawing is the one the page draws', svg.startsWith('<svg class="route-profile"'), svg.slice(0, 120));

function page(withProfile) {
  const geo = { items: [], route: { lines: [line], loop: false }, cluster: false, overlay: '' };
  return '<!doctype html><body><section class="place-map-wide">'
    + '<div class="geo" data-geo data-geo-load-mode="click" data-leaflet-js="/leaflet.js"><div class="geo-canvas" data-geo-canvas><button type="button" data-geo-load>Show map</button></div>'
    + '<script type="application/json" data-geo-data>' + JSON.stringify(geo) + '</script></div>'
    + (withProfile ? '<figure class="route-profile-figure" data-route-profile data-decimal="," data-label-hint="Move along the profile"><figcaption>Height profile</figcaption>'
      + '<div class="route-profile-track" data-route-profile-track>' + svg + '</div>'
      + '<script type="application/json" data-route-profile-data>' + JSON.stringify(profile) + '</script></figure>' : '')
    + '</section></body>';
}

// A stand-in for Leaflet: anything chains, the map remembers its handlers and projects plainly, markers remember where they are.
function fakeLeaflet(window) {
  const log = { handlers: {}, markers: [], removed: [] };
  const any = () => new Proxy(function () {}, { get: (t, k) => (k === 'then' ? undefined : any()), apply: () => any() });
  const project = (ll) => { const a = Array.isArray(ll) ? ll : [ll.lat, ll.lng]; return { x: a[1] * 10000, y: -a[0] * 10000 }; };
  const map = new Proxy({
    on(names, fn) { names.split(' ').forEach((n) => { (log.handlers[n] = log.handlers[n] || []).push(fn); }); return map; },
    latLngToContainerPoint: project,
    removeLayer(layer) { log.removed.push(layer); return map; },
    getZoom: () => 12
  }, { get: (t, k) => (k in t ? t[k] : any()) });
  const L = new Proxy({
    map: () => map,
    marker(at, options) {
      const m = { at, options, addTo() { log.markers.push(m); return m; }, setLatLng(p) { m.at = p; return m; }, bindPopup() { return m; }, getLatLng: () => at, on() { return m; } };
      return m;
    },
    divIcon: (o) => o
  }, { get: (t, k) => (k in t ? t[k] : any()) });
  window.L = L;
  return { log, map, project };
}

async function settle(window) { for (let i = 0; i < 5; i++) await new Promise((r) => window.setTimeout(r, 0)); }

(async () => {
  // ---- the profile on its own, then with the map
  const dom = new JSDOM(page(true), { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/en/routes/climb' });
  const { window } = dom;
  const doc = window.document;
  const fake = fakeLeaflet(window);
  window.eval(mapJs);
  window.eval(profileJs);
  await settle(window);
  const track = doc.querySelector('[data-route-profile-track]');
  track.getBoundingClientRect = () => ({ left: 0, top: 0, width: 640, height: 136, right: 640, bottom: 136 });
  const cursor = () => track.querySelector('.route-profile-cursor');
  const readout = () => track.querySelector('.route-profile-readout').textContent;
  const Pointer = window.PointerEvent ? 'pointermove' : 'mousemove';
  const move = (x) => track.dispatchEvent(new window.MouseEvent(Pointer, { bubbles: true, clientX: x }));

  check('the profile becomes a slider over the length of the route, with the drawing\'s label and a hint', track.getAttribute('role') === 'slider' && track.tabIndex === 0 && track.getAttribute('aria-valuemax') === '9.1' && track.getAttribute('aria-label') === 'Height profile: 100–200 m, 9.1 km. Move along the profile', track.outerHTML.slice(0, 300));
  check('nothing is marked until the pointer comes', cursor() && cursor().hidden === true);
  move(320);
  check('the pointer in the middle of the profile shows the middle of the route, with its height (decimal comma of the page)', cursor().hidden === false && readout() === '4,5 km · 150 m' && cursor().style.left === '50%', readout() + ' ' + cursor().style.left);
  check('the value is told to a screen reader', track.getAttribute('aria-valuetext') === '4,5 km · 150 m' && Math.abs(parseFloat(track.getAttribute('aria-valuenow')) - 4.55) < 0.01, track.getAttribute('aria-valuenow'));
  move(-50);
  check('past the start it stays at the start', readout() === '0,0 km · 100 m', readout());
  move(5000);
  check('past the end at the end', readout() === '9,1 km · 200 m', readout());
  check('with no map yet, nothing is put on one', fake.log.markers.length === 0);
  track.dispatchEvent(new window.MouseEvent(window.PointerEvent ? 'pointerleave' : 'mouseleave', { bubbles: true }));
  check('the pointer leaving the profile clears it', cursor().hidden === true);

  // The map is shown: block.js draws it and says so.
  let ready = null;
  doc.querySelector('[data-geo]').addEventListener('geo:ready', (e) => { ready = e.detail; });
  doc.querySelector('[data-geo-load]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await settle(window);
  check('the map\'s script says when the map is drawn, with the map and the route', ready && ready.map === fake.map && ready.route && ready.route.lines.length === 1 && doc.querySelector('[data-geo]').gnozioGeo === ready, ready);
  const routeMarkers = fake.log.markers.length;
  move(320);
  const mark = fake.log.markers[routeMarkers];
  check('the pointer on the profile now marks the same place on the map\'s line', mark && Math.abs(mark.at[0] - 35) < 1e-9 && Math.abs(mark.at[1] - 26.05) < 0.002 && mark.options.interactive === false && mark.options.keyboard === false, mark && mark.at);
  move(160);
  check('and moves with it, one mark only', fake.log.markers.length === routeMarkers + 1 && Math.abs(mark.at[1] - 26.025) < 0.002, mark && mark.at);
  track.dispatchEvent(new window.MouseEvent(window.PointerEvent ? 'pointerleave' : 'mouseleave', { bubbles: true }));
  check('leaving the profile takes the mark off the map', cursor().hidden === true && fake.log.removed.includes(mark));

  // The pointer on the map.
  const onMap = (lat, lng) => (fake.log.handlers.mousemove || []).forEach((fn) => fn({ latlng: { lat, lng } }));
  onMap(35.0005, 26.075);
  check('the pointer on the map near the line marks the profile at that place', cursor().hidden === false && readout() === '6,8 km · 175 m', readout());
  check('and the map at it', fake.log.markers.length === routeMarkers + 2 && Math.abs(fake.log.markers[routeMarkers + 1].at[1] - 26.075) < 0.002);
  onMap(35.2, 26.075);
  check('away from the line the profile is cleared', cursor().hidden === true);
  onMap(35.0, 26.03);
  (fake.log.handlers.mouseout || []).forEach((fn) => fn({}));
  check('the pointer leaving the map clears it too', cursor().hidden === true);

  // The keyboard.
  const key = (k) => { const e = new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }); track.dispatchEvent(e); return e.defaultPrevented; };
  track.focus();
  check('Home goes to the start', key('Home') && readout() === '0,0 km · 100 m', readout());
  key('ArrowRight');
  check('an arrow moves a hundredth of the way', Math.abs(parseFloat(track.getAttribute('aria-valuenow')) - 0.09) < 0.005, track.getAttribute('aria-valuenow'));
  key('End');
  check('End goes to the end, and marks it on the map', readout() === '9,1 km · 200 m' && Math.abs(fake.log.markers[fake.log.markers.length - 1].at[1] - 26.1) < 1e-6, readout());
  key('PageDown');
  check('Page Down goes back a tenth', Math.abs(parseFloat(track.getAttribute('aria-valuenow')) - 8.19) < 0.005, track.getAttribute('aria-valuenow'));
  check('other keys are left to the page', key('Tab') === false && key('a') === false);
  key('Escape');
  check('Escape clears it', cursor().hidden === true);
  key('ArrowLeft');
  track.dispatchEvent(new window.FocusEvent('blur'));
  check('leaving it with the keyboard clears it', cursor().hidden === true);

  // ---- a map drawn before the profile's script runs still joins
  const dom2 = new JSDOM(page(true), { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/en/routes/climb' });
  const fake2 = fakeLeaflet(dom2.window);
  dom2.window.eval(mapJs);
  await settle(dom2.window);
  dom2.window.document.querySelector('[data-geo-load]').dispatchEvent(new dom2.window.MouseEvent('click', { bubbles: true }));
  await settle(dom2.window);
  dom2.window.eval(profileJs);
  dom2.window.GnozioRouteProfile.init();
  const t2 = dom2.window.document.querySelector('[data-route-profile-track]');
  t2.getBoundingClientRect = () => ({ left: 0, width: 640 });
  const before = fake2.log.markers.length;
  t2.dispatchEvent(new dom2.window.MouseEvent(Pointer, { bubbles: true, clientX: 320 }));
  check('a map drawn before the profile\'s script is joined all the same', fake2.log.markers.length === before + 1);

  // ---- a route without a profile
  const dom3 = new JSDOM(page(false), { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/en/routes/level' });
  const errors = [];
  dom3.window.addEventListener('error', (e) => errors.push(e.message));
  fakeLeaflet(dom3.window);
  dom3.window.eval(profileJs);
  dom3.window.eval(mapJs);
  await settle(dom3.window);
  dom3.window.document.querySelector('[data-geo-load]').dispatchEvent(new dom3.window.MouseEvent('click', { bubbles: true }));
  await settle(dom3.window);
  check('a page with no profile is left alone, and its map is drawn as before', errors.length === 0 && dom3.window.document.querySelector('[data-geo]').gnozioGeo && !dom3.window.document.querySelector('.route-profile-cursor'), errors);

  // ---- the helpers
  const P = window.GnozioRouteProfile;
  const two = P.measure([[[35, 26], [35, 26.01]], [[36, 27], [36, 27.01]]]);
  check('the length of a line in two parts leaves out the gap between them', two.points.length === 4 && Math.abs(two.total - 2 * 0.911) < 0.02, two.total);
  check('the place at a share of the length', JSON.stringify(P.pointAt(two, 0)) === '[35,26]' && JSON.stringify(P.pointAt(two, 1)) === '[36,27.01]' && Math.abs(P.pointAt(two, 0.25)[1] - 26.005) < 1e-4, JSON.stringify(P.pointAt(two, 0.25)));
  check('half way is the start of the second part, never a point in the gap', Math.abs(P.pointAt(two, 0.5)[0] - 35) < 1e-9 || Math.abs(P.pointAt(two, 0.5)[0] - 36) < 1e-9, JSON.stringify(P.pointAt(two, 0.5)));
  const found = P.nearest(two, fake.project([36.0001, 27.005]), (lat, lng) => fake.project([lat, lng]));
  check('the nearest place on the line, in the right part, with how far it is', Math.abs(found.fraction - 0.75) < 0.01 && found.px < 2, JSON.stringify(found));
  const sc = P.scale(profile);
  check('the scale is the drawing\'s: 4 of 640 at each side, the height between a floor and a ceiling', Math.abs(sc.x(0) - 4 / 640) < 1e-9 && Math.abs(sc.x(LENGTH) - 636 / 640) < 1e-9 && sc.y(200) < sc.y(100) && Math.abs(sc.km(0.5) - LENGTH / 2) < 1e-9);
  check('the height between two samples is read between them', P.heightAt(profile, LENGTH * 0.05) === 105 && P.heightAt(profile, -1) === 100 && P.heightAt(profile, 99) === 200);

  console.log(failed ? '\n' + failed + ' FAILED' : '\nALL PASSED');
  process.exit(failed ? 1 : 0);
})();
