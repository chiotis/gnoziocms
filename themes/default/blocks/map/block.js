/* Map: the OpenStreetMap iframe is created only when the visitor asks for it. */
(function () {
  document.querySelectorAll('[data-map]').forEach(function (frame) {
    var button = frame.querySelector('[data-map-load]');
    if (!button) return;
    button.addEventListener('click', function () {
      var iframe = document.createElement('iframe');
      iframe.src = frame.getAttribute('data-map-src');
      iframe.title = frame.getAttribute('data-map-title') || 'Map';
      iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
      frame.innerHTML = '';
      frame.appendChild(iframe);
      iframe.focus();
    });
  });
})();

/* Interactive maps: the map layout of an archive, the Map block when it shows content, and the pages of a point, a route
   or a business. The markup holds a list of the places (so without JavaScript there is still a list of links) and the data
   as JSON; Leaflet is fetched only when the map is shown, which is on a click or, when the page asks, when it comes into view.
   Every text is put in the page as text, never as markup. */
(function () {
  var loading = null;

  function fetchScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function fetchStyle(href) {
    if (!href || document.querySelector('link[href="' + href + '"]')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = href;
    document.head.appendChild(l);
  }

  function leaflet(root) {
    if (window.L) return Promise.resolve();
    if (loading) return loading;
    fetchStyle(root.getAttribute('data-leaflet-css'));
    loading = fetchScript(root.getAttribute('data-leaflet-js'));
    return loading;
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function popup(item) {
    var box = el('div', 'geo-popup');
    if (item.img) {
      var img = el('img', 'geo-popup-img');
      img.src = item.img;
      img.alt = '';
      img.loading = 'lazy';
      box.appendChild(img);
    }
    var body = el('div', 'geo-popup-body');
    if (item.kind) body.appendChild(el('p', 'geo-popup-kind', item.kind + (item.area ? ' · ' + item.area : '')));
    var title = el('p', 'geo-popup-title');
    if (item.url) {
      var a = el('a', null, item.title);
      a.href = item.url;
      title.appendChild(a);
    } else {
      title.textContent = item.title;
    }
    body.appendChild(title);
    if (item.facts && item.facts.length) body.appendChild(el('p', 'geo-popup-facts', item.facts.join(' · ')));
    if (item.text) body.appendChild(el('p', 'geo-popup-text', item.text));
    box.appendChild(body);
    return box;
  }

  function pin(L, index, current) {
    return L.divIcon({
      className: 'geo-pin-wrap',
      html: '<span class="geo-pin geo-t' + (index % 4) + (current ? ' is-current' : '') + '"></span>',
      iconSize: [28, 34],
      iconAnchor: [14, 32],
      popupAnchor: [0, -30]
    });
  }

  function start(root) {
    if (root.getAttribute('data-geo-ready') === '1') return;
    root.setAttribute('data-geo-ready', '1');
    var canvas = root.querySelector('[data-geo-canvas]');
    var dataNode = root.querySelector('[data-geo-data]');
    if (!canvas || !dataNode) return;
    var data;
    try { data = JSON.parse(dataNode.textContent); } catch (e) { return; }
    leaflet(root).then(function () {
      var L = window.L;
      var needCluster = data.cluster && (data.items || []).length > 20;
      return needCluster ? fetchScript(root.getAttribute('data-cluster-js')).then(function () { fetchStyle(root.getAttribute('data-cluster-css')); }) : null;
    }).then(function () {
      draw(root, canvas, data);
    }).catch(function () {
      canvas.textContent = root.getAttribute('data-label-error') || 'The map could not be loaded.';
    });
  }

  function draw(root, canvas, data) {
    var L = window.L;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    canvas.innerHTML = '';
    canvas.classList.add('is-live');
    var side = data.overlay === 'left' || data.overlay === 'right' ? data.overlay : '';
    var panel = side ? root.querySelector('.geo-side') : null;
    var map = L.map(canvas, { zoomControl: !side, scrollWheelZoom: false, zoomAnimation: !reduce, fadeAnimation: !reduce, markerZoomAnimation: !reduce });
    if (side) {
      // The controls go to the side the list is not on.
      L.control.zoom({ position: side === 'left' ? 'topright' : 'topleft' }).addTo(map);
      map.attributionControl.setPosition(side === 'left' ? 'bottomright' : 'bottomleft');
    }
    // How much of the map the list covers (nothing when the page has put it below, on a narrow screen): what is shown is centred on the rest.
    var covered = function () {
      if (!panel || getComputedStyle(panel).position !== 'absolute') return 0;
      var r = panel.getBoundingClientRect();
      var c = canvas.getBoundingClientRect();
      return side === 'left' ? Math.max(0, r.right - c.left) : Math.max(0, c.right - r.left);
    };
    var padding = function (base) {
      var w = covered();
      return side === 'left' ? { paddingTopLeft: [base + w, base], paddingBottomRight: [base, base] } : { paddingTopLeft: [base, base], paddingBottomRight: [base + w, base] };
    };
    var centre = function (latlng, zoom) {
      map.setView(latlng, zoom, { animate: false });
      var w = covered();
      if (w) map.panBy([side === 'left' ? -w / 2 : w / 2, 0], { animate: false });
    };
    var tiles = data.tiles || {};
    L.tileLayer(tiles.url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: tiles.max_zoom || 19, attribution: tiles.attribution || '&copy; OpenStreetMap contributors' }).addTo(map);
    // The wheel scrolls the page until the map has been clicked, so a map never traps a visitor who is scrolling.
    map.on('focus click', function () { map.scrollWheelZoom.enable(); });
    map.on('blur', function () { map.scrollWheelZoom.disable(); });

    // A popup opens clear of the list.
    var popupPadding = function () {
      var o = padding(10);
      return side ? { autoPanPaddingTopLeft: o.paddingTopLeft, autoPanPaddingBottomRight: o.paddingBottomRight } : {};
    };

    var typeIndex = {};
    (data.types || []).forEach(function (t, i) { typeIndex[t.id] = i; });
    var items = data.items || [];
    var layer = (data.cluster && window.L.markerClusterGroup && items.length > 20) ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45 }) : L.layerGroup();
    layer.addTo(map);
    var lines = L.layerGroup().addTo(map);
    var shapes = {};

    items.forEach(function (item) {
      var shape = { item: item, marker: null, lines: [] };
      var index = typeIndex[item.type] || 0;
      var current = data.current && item.id === data.current;
      shape.marker = L.marker([item.lat, item.lng], { icon: pin(L, index, current), title: item.title, alt: item.title, riseOnHover: true });
      shape.marker.bindPopup(function () { return popup(item); }, Object.assign({ maxWidth: 260, minWidth: 200 }, popupPadding()));
      (item.line || []).forEach(function (line) {
        if (line.length < 2) return;
        var p = L.polyline(line, { color: getComputedStyle(root).getPropertyValue('--geo-c' + (index % 4)).trim() || '#2563eb', weight: 4, opacity: 0.85, lineJoin: 'round' });
        p.bindPopup(function () { return popup(item); }, Object.assign({ maxWidth: 260, minWidth: 200 }, popupPadding()));
        p.on('mouseover', function () { p.setStyle({ weight: 6, opacity: 1 }); });
        p.on('mouseout', function () { p.setStyle({ weight: 4, opacity: 0.85 }); });
        shape.lines.push(p);
      });
      shapes[item.id] = shape;
    });

    // The route of a page of its own: the whole line, its places, and its two ends.
    var focus = null;
    if (data.route) {
      focus = L.featureGroup();
      (data.route.lines || []).forEach(function (line) {
        L.polyline(line, { color: '#ffffff', weight: 8, opacity: 0.9, lineJoin: 'round' }).addTo(focus);
        L.polyline(line, { color: getComputedStyle(root).getPropertyValue('--geo-route').trim() || '#e11d48', weight: 4.5, opacity: 1, lineJoin: 'round' }).addTo(focus);
      });
      var firstLine = (data.route.lines || [])[0];
      var lastLine = (data.route.lines || [])[(data.route.lines || []).length - 1];
      if (firstLine && firstLine.length) {
        L.marker([firstLine[0][0], firstLine[0][1]], { icon: L.divIcon({ className: 'geo-pin-wrap', html: '<span class="geo-end is-start"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }), title: data.route.start_label || 'Start', alt: data.route.start_label || 'Start', keyboard: false }).addTo(focus);
      }
      if (lastLine && lastLine.length && !data.route.loop) {
        var end = lastLine[lastLine.length - 1];
        L.marker([end[0], end[1]], { icon: L.divIcon({ className: 'geo-pin-wrap', html: '<span class="geo-end is-finish"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }), title: data.route.end_label || 'Finish', alt: data.route.end_label || 'Finish', keyboard: false }).addTo(focus);
      }
      (data.route.waypoints || []).forEach(function (w) {
        var m = L.marker([w.lat, w.lng], { icon: L.divIcon({ className: 'geo-pin-wrap', html: '<span class="geo-wpt"></span>', iconSize: [12, 12], iconAnchor: [6, 6] }), title: w.name || '', alt: w.name || '' });
        if (w.name || w.text) m.bindPopup(function () { var b = el('div', 'geo-popup-body'); if (w.name) b.appendChild(el('p', 'geo-popup-title', w.name)); if (w.text) b.appendChild(el('p', 'geo-popup-text', w.text)); return b; });
        m.addTo(focus);
      });
      focus.addTo(map);
    }

    var query = '', typeFilter = '', catFilter = '';
    var listItems = Array.prototype.slice.call(root.querySelectorAll('[data-geo-item]'));
    var status = root.querySelector('[data-geo-status]');

    function matches(item) {
      if (typeFilter && item.type !== typeFilter) return false;
      if (catFilter && (item.cats || []).indexOf(catFilter) === -1) return false;
      if (query) {
        var hay = (item.title + ' ' + (item.area || '') + ' ' + (item.kind || '')).toLowerCase();
        if (hay.indexOf(query) === -1) return false;
      }
      return true;
    }

    function fit() {
      // The page of one place: close on the place itself (the places near it are around, not what the map is fitted to).
      if (data.current && !data.route) {
        var here = items.filter(function (item) { return item.id === data.current; })[0];
        if (here) { centre([here.lat, here.lng], data.zoom || 15); return; }
      }
      var bounds = L.latLngBounds([]);
      if (focus && focus.getLayers().length) bounds.extend(focus.getBounds());
      items.forEach(function (item) {
        if (data.route && item.id !== data.current && !data.fitAll) return;
        if (matches(item)) bounds.extend([item.lat, item.lng]);
      });
      if (bounds.isValid()) map.fitBounds(bounds, Object.assign(side ? padding(30) : { padding: [30, 30] }, { maxZoom: data.route ? 16 : 14 }));
      else centre([data.center ? data.center[0] : 38, data.center ? data.center[1] : 24], data.zoom || 6);
    }

    function apply(refit) {
      var shown = 0;
      layer.clearLayers();
      lines.clearLayers();
      items.forEach(function (item) {
        var shape = shapes[item.id];
        var on = matches(item);
        if (on) {
          shown++;
          layer.addLayer(shape.marker);
          shape.lines.forEach(function (p) { lines.addLayer(p); });
        }
      });
      listItems.forEach(function (li) {
        var item = shapes[li.getAttribute('data-geo-item')];
        li.hidden = !(item && matches(item.item));
      });
      if (status) status.textContent = shown + ' / ' + items.length;
      if (refit) fit();
    }

    // Filters: a button for each kind of entry, a menu of categories, and a search.
    var bar = root.querySelector('[data-geo-filters]');
    if (bar) {
      var types = data.types || [];
      if (types.length > 1) {
        var group = el('div', 'geo-chips');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', root.getAttribute('data-label-kinds') || 'Kind');
        var chip = function (id, label, count, index) {
          var b = el('button', 'geo-chip' + (id === '' ? ' is-active' : ''));
          b.type = 'button';
          b.setAttribute('aria-pressed', id === '' ? 'true' : 'false');
          b.setAttribute('data-type', id);
          if (id !== '') b.appendChild(el('i', 'geo-dot geo-t' + (index % 4)));
          b.appendChild(el('span', null, label));
          b.appendChild(el('small', null, String(count)));
          b.addEventListener('click', function () {
            typeFilter = id;
            Array.prototype.forEach.call(group.children, function (c) { var on = c === b; c.classList.toggle('is-active', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
            apply(true);
          });
          return b;
        };
        group.appendChild(chip('', root.getAttribute('data-label-all') || 'All', items.length, 0));
        types.forEach(function (t, i) { group.appendChild(chip(t.id, t.label, t.count, i)); });
        bar.appendChild(group);
      }
      if ((data.cats || []).length > 1) {
        var select = el('select', 'geo-select');
        select.setAttribute('aria-label', root.getAttribute('data-label-category') || 'Category');
        var all = el('option', null, root.getAttribute('data-label-categories') || 'All categories');
        all.value = '';
        select.appendChild(all);
        data.cats.forEach(function (c) { var o = el('option', null, c.label + ' (' + c.count + ')'); o.value = c.slug; select.appendChild(o); });
        select.addEventListener('change', function () { catFilter = select.value; apply(true); });
        bar.appendChild(select);
      }
      if (items.length > 6) {
        var search = el('input', 'geo-search');
        search.type = 'search';
        search.placeholder = root.getAttribute('data-label-search') || 'Search';
        search.setAttribute('aria-label', root.getAttribute('data-label-search') || 'Search');
        var timer = null;
        search.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () { query = search.value.trim().toLowerCase(); apply(true); }, 160);
        });
        bar.appendChild(search);
      }
      bar.hidden = bar.children.length === 0;
    }

    // The list and the map speak to each other: the pointer on an entry opens it on the map.
    listItems.forEach(function (li) {
      var shape = shapes[li.getAttribute('data-geo-item')];
      if (!shape) return;
      var show = li.querySelector('[data-geo-show]');
      if (show) {
        show.hidden = false;
        show.addEventListener('click', function () {
          centre(shape.marker.getLatLng(), Math.max(map.getZoom(), 13));
          if (layer.zoomToShowLayer) layer.zoomToShowLayer(shape.marker, function () { shape.marker.openPopup(); });
          else shape.marker.openPopup();
          canvas.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
        });
      }
      li.addEventListener('mouseenter', function () { var e = shape.marker.getElement && shape.marker.getElement(); if (e) e.classList.add('is-hot'); });
      li.addEventListener('mouseleave', function () { var e = shape.marker.getElement && shape.marker.getElement(); if (e) e.classList.remove('is-hot'); });
    });

    apply(false);
    fit();
    // Other scripts of the page (the height profile of a route) can join the map once it is drawn: `geo:ready` on the map's root,
    // and the same on root.gnozioGeo for one that comes later.
    root.gnozioGeo = { map: map, L: L, route: data.route || null };
    if (typeof window.CustomEvent === 'function') root.dispatchEvent(new CustomEvent('geo:ready', { bubbles: true, detail: root.gnozioGeo }));
    // The map was laid out while hidden or still growing: ask it to measure again once the page has settled.
    setTimeout(function () { map.invalidateSize(); }, 250);
  }

  function init() {
    document.querySelectorAll('[data-geo]').forEach(function (root) {
      var button = root.querySelector('[data-geo-load]');
      if (root.getAttribute('data-geo-load-mode') === 'auto' || !button) {
        if ('IntersectionObserver' in window) {
          var io = new IntersectionObserver(function (entries) {
            if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(root); }
          }, { rootMargin: '200px' });
          io.observe(root);
        } else {
          start(root);
        }
      } else {
        button.addEventListener('click', function () { start(root); });
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
