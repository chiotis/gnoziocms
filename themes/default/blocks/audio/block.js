/* Audio: the list of tracks becomes one player. The track playing shows on top with its picture, title and speaker, a play and
   pause button, a position bar and the time; the previous and next tracks; with Podcast controls (data-podcast) back 15 s,
   forward 30 s and the speed. A row of the list plays its track. With data-continuous the next track follows when one ends.
   Only one player on the page plays at a time. The lock screen and headphones show and control it (Media Session). Without
   this script each track is a native player. */
(function () {
  var players = document.querySelectorAll('[data-audio]');
  if (!players.length) return;

  var svg = function (path) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor">' + path + '</svg>';
  };
  var ICONS = {
    play: svg('<path d="M8 5.5v13a.6.6 0 0 0 .9.5l10.4-6.5a.6.6 0 0 0 0-1L8.9 5a.6.6 0 0 0-.9.5Z"/>'),
    pause: svg('<rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>'),
    prev: svg('<rect x="5" y="5" width="2.2" height="14" rx="1"/><path d="M19 6v12a.6.6 0 0 1-.9.5L9 12.5a.6.6 0 0 1 0-1l9.1-6A.6.6 0 0 1 19 6Z"/>'),
    next: svg('<rect x="16.8" y="5" width="2.2" height="14" rx="1"/><path d="M5 6v12a.6.6 0 0 0 .9.5L15 12.5a.6.6 0 0 0 0-1L5.9 5.5A.6.6 0 0 0 5 6Z"/>')
  };
  var SPEEDS = [1, 1.25, 1.5, 1.75, 2, 0.75];

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'text') node.textContent = attrs[key];
      else if (key === 'html') node.innerHTML = attrs[key];
      else node.setAttribute(key, attrs[key]);
    });
    (children || []).forEach(function (child) { node.appendChild(child); });
    return node;
  }

  function time(seconds) {
    if (!isFinite(seconds) || seconds < 0) seconds = 0;
    var s = Math.floor(seconds % 60), m = Math.floor(seconds / 60) % 60, h = Math.floor(seconds / 3600);
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  players.forEach(function (root) {
    var rows = Array.prototype.slice.call(root.querySelectorAll('.audio-track'));
    if (!rows.length) return;
    var label = function (name, fallback) { return root.getAttribute('data-label-' + name) || fallback; };
    var continuous = root.hasAttribute('data-continuous');
    var podcast = root.hasAttribute('data-podcast');
    var audio = new Audio();
    audio.preload = 'none';
    var current = -1;
    var speed = 0;
    var dragging = false;

    // The track playing.
    var cover = el('div', { class: 'audio-now-cover' });
    var title = el('p', { class: 'audio-now-title' });
    var artist = el('p', { class: 'audio-now-artist' });
    var play = el('button', { type: 'button', class: 'audio-play', 'aria-label': label('play', 'Play'), html: ICONS.play });
    var seek = el('input', { type: 'range', class: 'audio-seek', min: '0', max: '0', step: '1', value: '0', 'aria-label': label('seek', 'Position in the track') });
    var at = el('span', { class: 'audio-at', text: '0:00' });
    var total = el('span', { class: 'audio-total', text: '' });
    var prev = el('button', { type: 'button', class: 'audio-btn', 'aria-label': label('previous', 'Previous track'), html: ICONS.prev });
    var next = el('button', { type: 'button', class: 'audio-btn', 'aria-label': label('next', 'Next track'), html: ICONS.next });
    var back = el('button', { type: 'button', class: 'audio-btn', 'aria-label': label('back', 'Back 15 seconds'), text: '−15' });
    var forward = el('button', { type: 'button', class: 'audio-btn', 'aria-label': label('forward', 'Forward 30 seconds'), text: '+30' });
    var rate = el('button', { type: 'button', class: 'audio-btn', 'aria-label': label('speed', 'Playing speed') + ': 1×', text: '1×' });
    var controls = el('div', { class: 'audio-controls' });
    if (rows.length > 1) controls.appendChild(prev);
    if (podcast) { controls.appendChild(back); controls.appendChild(forward); }
    if (rows.length > 1) controls.appendChild(next);
    if (podcast) controls.appendChild(rate);
    var now = el('div', { class: 'audio-now' }, [
      cover,
      el('div', { class: 'audio-now-main' }, [
        el('div', { class: 'audio-now-head' }, [play, el('div', { class: 'audio-now-text' }, [title, artist])]),
        el('div', { class: 'audio-progress' }, [at, seek, total]),
        controls
      ])
    ]);
    root.insertBefore(now, root.firstChild);

    // Each row plays its track; the native players are no longer needed.
    rows.forEach(function (row, i) {
      var native = row.querySelector('.audio-native');
      if (native) native.remove();
      var button = el('button', { type: 'button', class: 'audio-track-play', 'aria-label': label('play', 'Play') + ': ' + row.getAttribute('data-title') });
      button.addEventListener('click', function () {
        if (i === current) toggle(); else { load(i); start(); }
      });
      row.insertBefore(button, row.firstChild);
    });
    root.classList.add('is-enhanced');

    function load(i) {
      current = (i + rows.length) % rows.length;
      var row = rows[current];
      audio.src = row.getAttribute('data-src');
      audio.playbackRate = SPEEDS[speed];
      title.textContent = row.getAttribute('data-title');
      artist.textContent = row.getAttribute('data-artist') || '';
      artist.hidden = !artist.textContent;
      var thumb = row.querySelector('.audio-thumb');
      cover.innerHTML = thumb ? thumb.innerHTML : '';
      var length = row.querySelector('[data-length]');
      total.textContent = length && length.textContent.trim() ? length.textContent.trim() : '';
      at.textContent = '0:00';
      seek.value = '0';
      seek.max = '0';
      rows.forEach(function (r, n) {
        r.classList.toggle('is-current', n === current);
        if (n === current) r.setAttribute('aria-current', 'true'); else r.removeAttribute('aria-current');
      });
    }

    // What the lock screen and the headphones show: the track of the player that plays.
    function describe() {
      if (!('mediaSession' in navigator) || !window.MediaMetadata) return;
      var img = cover.querySelector('img');
      navigator.mediaSession.metadata = new window.MediaMetadata({
        title: title.textContent,
        artist: artist.textContent,
        artwork: img ? [{ src: img.currentSrc || img.src }] : []
      });
    }

    function start() {
      // Another player of the page stops.
      root.dispatchEvent(new CustomEvent('audio:start', { bubbles: true }));
      describe();
      var playing = audio.play();
      if (playing && playing.catch) playing.catch(function () { sync(); });
    }

    function toggle() {
      if (current < 0) load(0);
      if (audio.paused) start(); else audio.pause();
    }

    function sync() {
      var paused = audio.paused;
      play.innerHTML = paused ? ICONS.play : ICONS.pause;
      play.setAttribute('aria-label', paused ? label('play', 'Play') : label('pause', 'Pause'));
      root.classList.toggle('is-playing', !paused);
    }

    play.addEventListener('click', toggle);
    prev.addEventListener('click', function () {
      // Early in a track, the previous one; later, the start of this one.
      if (audio.currentTime > 3) { audio.currentTime = 0; return; }
      var wasPlaying = !audio.paused;
      load(current - 1);
      if (wasPlaying) start();
    });
    next.addEventListener('click', function () {
      var wasPlaying = !audio.paused;
      load(current + 1);
      if (wasPlaying) start();
    });
    back.addEventListener('click', function () { audio.currentTime = Math.max(0, audio.currentTime - 15); });
    forward.addEventListener('click', function () { audio.currentTime = Math.min(audio.duration || Infinity, audio.currentTime + 30); });
    rate.addEventListener('click', function () {
      speed = (speed + 1) % SPEEDS.length;
      audio.playbackRate = SPEEDS[speed];
      rate.textContent = SPEEDS[speed] + '×';
      rate.setAttribute('aria-label', label('speed', 'Playing speed') + ': ' + SPEEDS[speed] + '×');
    });

    seek.addEventListener('input', function () { dragging = true; at.textContent = time(Number(seek.value)); });
    seek.addEventListener('change', function () {
      dragging = false;
      if (current < 0) load(0);
      audio.currentTime = Number(seek.value);
    });

    audio.addEventListener('play', sync);
    audio.addEventListener('pause', sync);
    audio.addEventListener('loadedmetadata', function () {
      seek.max = String(Math.floor(audio.duration) || 0);
      total.textContent = time(audio.duration);
      var length = rows[current] && rows[current].querySelector('[data-length]');
      if (length && !length.textContent.trim()) length.textContent = time(audio.duration);
    });
    audio.addEventListener('timeupdate', function () {
      if (dragging) return;
      seek.value = String(Math.floor(audio.currentTime));
      at.textContent = time(audio.currentTime);
      seek.setAttribute('aria-valuetext', time(audio.currentTime) + ' / ' + time(audio.duration));
    });
    audio.addEventListener('ended', function () {
      if (continuous && current < rows.length - 1) { load(current + 1); start(); } else sync();
    });

    document.addEventListener('audio:start', function (event) {
      if (event.target !== root && !audio.paused) audio.pause();
    });

    if ('mediaSession' in navigator) {
      root.addEventListener('audio:start', function () {
        var handlers = {
          play: function () { start(); },
          pause: function () { audio.pause(); },
          previoustrack: rows.length > 1 ? function () { prev.click(); } : null,
          nexttrack: rows.length > 1 ? function () { next.click(); } : null,
          seekbackward: function () { back.click(); },
          seekforward: function () { forward.click(); }
        };
        Object.keys(handlers).forEach(function (action) {
          try { navigator.mediaSession.setActionHandler(action, handlers[action]); } catch (e) { /* not offered by this browser */ }
        });
      });
    }

    load(0);
    sync();
  });
})();
