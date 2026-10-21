/* The height profile of a route, linked to its map (components/place-single.twig). Moving the pointer or a finger along the
   profile, or the arrow keys once it has the focus, draws a line and a dot on it with the distance and the height, and marks the
   same place on the map's line; the pointer on the map near the line marks the profile. The map is drawn later (on a click, or
   when it comes into view) by blocks/map/block.js, which says so with `geo:ready`; until then the profile works on its own.
   Without this script the profile is a drawing with its facts in its label, as before. */
(function () {
  'use strict';

  // The geometry of the drawing, as GeoView::profileSvg makes it.
  var W = 640, H = 150, LEFT = 4, TOP = 10, BOTTOM = 18;
  // How close to the line (in screen pixels) the pointer on the map has to be to count as on it.
  var NEAR_PX = 24;

  /** Where a kilometre and a height sit in the drawing, as fractions of its width and height. */
  function scale(profile) {
    var length = Math.max(0.001, profile[profile.length - 1][0]);
    var heights = profile.map(function (p) { return p[1]; });
    var min = Math.min.apply(null, heights), max = Math.max.apply(null, heights);
    var span = Math.max(20, max - min);
    var floor = min - span * 0.08, ceiling = max + span * 0.08;
    return {
      length: length,
      x: function (km) { return (LEFT + (W - 2 * LEFT) * km / length) / W; },
      y: function (m) { return (TOP + (H - TOP - BOTTOM) * (1 - (m - floor) / (ceiling - floor))) / H; },
      km: function (fraction) { return Math.min(1, Math.max(0, (fraction * W - LEFT) / (W - 2 * LEFT))) * length; }
    };
  }

  /** The height at a distance, read between the two samples around it. */
  function heightAt(profile, km) {
    if (km <= profile[0][0]) return profile[0][1];
    for (var i = 1; i < profile.length; i++) {
      if (km <= profile[i][0]) {
        var a = profile[i - 1], b = profile[i];
        var t = b[0] === a[0] ? 0 : (km - a[0]) / (b[0] - a[0]);
        return a[1] + (b[1] - a[1]) * t;
      }
    }
    return profile[profile.length - 1][1];
  }

  function haversine(a, b) {
    var r = Math.PI / 180;
    var dLat = (b[0] - a[0]) * r, dLng = (b[1] - a[1]) * r;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 12742 * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  /** The points of the map's line with the distance from the start to each; the gap between two parts of a route does not count,
      as it does not in the profile. */
  function measure(lines) {
    var points = [], total = 0;
    (lines || []).forEach(function (line, part) {
      var previous = null;
      (line || []).forEach(function (p) {
        if (!p || p.length < 2) return;
        if (previous) total += haversine(previous, p);
        points.push({ lat: p[0], lng: p[1], d: total, part: part });
        previous = p;
      });
    });
    return { points: points, total: total };
  }

  /** The place on the line at a fraction of its length. */
  function pointAt(track, fraction) {
    var pts = track.points;
    if (!pts.length) return null;
    var want = Math.min(1, Math.max(0, fraction)) * track.total;
    for (var i = 1; i < pts.length; i++) {
      if (pts[i].d >= want && pts[i].part === pts[i - 1].part) {
        var a = pts[i - 1], b = pts[i];
        var t = b.d === a.d ? 0 : (want - a.d) / (b.d - a.d);
        return [a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t];
      }
    }
    var last = pts[pts.length - 1];
    return [last.lat, last.lng];
  }

  /** The fraction of the line's length closest to a point of the screen, and how far it is (pixels): project(lat, lng) gives {x, y}. */
  function nearest(track, at, project) {
    var pts = track.points, best = null;
    var screen = pts.map(function (p) { return project(p.lat, p.lng); });
    for (var i = 0; i < pts.length; i++) {
      if (i > 0 && pts[i].part === pts[i - 1].part) {
        var a = screen[i - 1], b = screen[i];
        var dx = b.x - a.x, dy = b.y - a.y;
        var len = dx * dx + dy * dy;
        var t = len === 0 ? 0 : Math.max(0, Math.min(1, ((at.x - a.x) * dx + (at.y - a.y) * dy) / len));
        var px = Math.hypot(at.x - (a.x + dx * t), at.y - (a.y + dy * t));
        if (!best || px < best.px) best = { px: px, d: pts[i - 1].d + (pts[i].d - pts[i - 1].d) * t };
      } else if (!best) {
        best = { px: Math.hypot(at.x - screen[i].x, at.y - screen[i].y), d: pts[i].d };
      }
    }
    return best ? { fraction: track.total > 0 ? best.d / track.total : 0, px: best.px } : null;
  }

  function number(value, digits, decimal) {
    return value.toFixed(digits).replace('.', decimal || '.');
  }

  function link(figure) {
    if (figure.getAttribute('data-route-profile-ready') === '1') return;
    var track = figure.querySelector('[data-route-profile-track]');
    var dataNode = figure.querySelector('[data-route-profile-data]');
    if (!track || !dataNode) return;
    var profile;
    try { profile = JSON.parse(dataNode.textContent); } catch (e) { return; }
    if (!Array.isArray(profile) || profile.length < 2) return;
    figure.setAttribute('data-route-profile-ready', '1');
    var sc = scale(profile);
    var decimal = figure.getAttribute('data-decimal') || '.';
    var svg = track.querySelector('svg');
    var label = svg ? svg.getAttribute('aria-label') || '' : '';
    var hint = figure.getAttribute('data-label-hint') || '';

    var cursor = document.createElement('div');
    cursor.className = 'route-profile-cursor';
    cursor.hidden = true;
    var dot = document.createElement('span');
    dot.className = 'route-profile-dot';
    var readout = document.createElement('span');
    readout.className = 'route-profile-readout';
    cursor.appendChild(dot);
    cursor.appendChild(readout);
    track.appendChild(cursor);
    track.classList.add('is-live');
    // A slider over the length of the route, for the keyboard and for screen readers.
    track.tabIndex = 0;
    track.setAttribute('role', 'slider');
    track.setAttribute('aria-label', label + (hint ? '. ' + hint : ''));
    track.setAttribute('aria-valuemin', '0');
    track.setAttribute('aria-valuemax', number(sc.length, 1, '.'));
    track.setAttribute('aria-valuenow', '0');

    var geo = null, line = null, mark = null, current = null;
    var root = (figure.closest('section') || document).querySelector('[data-geo]');

    function place(km, fromMap) {
      current = Math.min(sc.length, Math.max(0, km));
      var m = heightAt(profile, current);
      var x = sc.x(current);
      cursor.hidden = false;
      cursor.style.left = (x * 100) + '%';
      cursor.classList.toggle('is-flipped', x > 0.75);
      dot.style.top = (sc.y(m) * 100) + '%';
      var text = number(current, 1, decimal) + ' km · ' + Math.round(m) + ' m';
      readout.textContent = text;
      track.setAttribute('aria-valuenow', number(current, 2, '.'));
      track.setAttribute('aria-valuetext', text);
      if (geo && line && line.points.length) {
        var at = pointAt(line, current / sc.length);
        if (at) {
          if (!mark) {
            mark = geo.L.marker(at, { icon: geo.L.divIcon({ className: 'geo-pin-wrap', html: '<span class="geo-profile-mark"></span>', iconSize: [14, 14], iconAnchor: [7, 7] }), keyboard: false, interactive: false, zIndexOffset: 1000 });
            mark.addTo(geo.map);
          } else {
            mark.setLatLng(at);
          }
        }
      }
      if (!fromMap) figure.dispatchEvent(new CustomEvent('route-profile:move', { bubbles: true, detail: { km: current } }));
    }

    function clear() {
      current = null;
      cursor.hidden = true;
      track.removeAttribute('aria-valuetext');
      if (mark && geo) { geo.map.removeLayer(mark); }
      mark = null;
    }

    function fromPointer(clientX) {
      var r = track.getBoundingClientRect();
      if (!r.width) return;
      place(sc.km((clientX - r.left) / r.width), false);
    }

    if (window.PointerEvent) {
      track.addEventListener('pointermove', function (e) { fromPointer(e.clientX); });
      track.addEventListener('pointerdown', function (e) { fromPointer(e.clientX); });
      track.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'touch' && document.activeElement !== track) clear(); });
    } else {
      track.addEventListener('mousemove', function (e) { fromPointer(e.clientX); });
      track.addEventListener('mouseleave', function () { if (document.activeElement !== track) clear(); });
      track.addEventListener('touchmove', function (e) { if (e.touches[0]) fromPointer(e.touches[0].clientX); }, { passive: true });
      track.addEventListener('touchstart', function (e) { if (e.touches[0]) fromPointer(e.touches[0].clientX); }, { passive: true });
    }
    track.addEventListener('keydown', function (e) {
      var step = sc.length / 100;
      var at = current === null ? 0 : current;
      var next = null;
      switch (e.key) {
        case 'ArrowRight': case 'ArrowUp': next = current === null ? 0 : at + step; break;
        case 'ArrowLeft': case 'ArrowDown': next = at - step; break;
        case 'PageUp': next = at + step * 10; break;
        case 'PageDown': next = at - step * 10; break;
        case 'Home': next = 0; break;
        case 'End': next = sc.length; break;
        case 'Escape': clear(); return;
        default: return;
      }
      e.preventDefault();
      place(next, false);
    });
    track.addEventListener('blur', clear);

    function join(detail) {
      if (geo || !detail || !detail.map || !detail.route) return;
      geo = detail;
      line = measure(detail.route.lines);
      if (!line.points.length || line.total <= 0) return;
      geo.map.on('mousemove', function (e) {
        if (!e.latlng) return;
        var found = nearest(line, geo.map.latLngToContainerPoint(e.latlng), function (lat, lng) { return geo.map.latLngToContainerPoint([lat, lng]); });
        if (found && found.px <= NEAR_PX) place(found.fraction * sc.length, true);
        else if (current !== null && document.activeElement !== track) clear();
      });
      geo.map.on('mouseout', function () { if (document.activeElement !== track) clear(); });
      if (current !== null) place(current, true);
    }

    if (root) {
      if (root.gnozioGeo) join(root.gnozioGeo);
      root.addEventListener('geo:ready', function (e) { join(e.detail); });
    }
  }

  function init() {
    document.querySelectorAll('[data-route-profile]').forEach(link);
  }

  window.GnozioRouteProfile = { scale: scale, heightAt: heightAt, measure: measure, pointAt: pointAt, nearest: nearest, init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
