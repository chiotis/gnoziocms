/* GnozioCMS default theme: colour mode, navigation, accessible mobile drawer, sticky header. */
(function () {
  const root = document.documentElement;
  const modeKey = 'faroscms-mode';
  const paletteKey = 'faroscms-palette';
  const media = window.matchMedia('(prefers-color-scheme: dark)');

  const storage = {
    get(key) {
      try { return localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
      try { localStorage.setItem(key, value); } catch (e) { /* private mode */ }
    },
  };

  /* Colour mode ---------------------------------------------------------- */

  const storedPalette = storage.get(paletteKey);
  if (storedPalette) {
    root.dataset.theme = storedPalette;
  }

  let mode = storage.get(modeKey) || root.dataset.mode || 'system';
  const modeButtons = document.querySelectorAll('[data-theme-toggle]');

  function isDark(activeMode) {
    return activeMode === 'dark' || (activeMode === 'system' && media.matches);
  }

  function applyMode() {
    const darkOn = isDark(mode);
    root.dataset.mode = mode;
    root.classList.toggle('dark', darkOn);
    root.classList.toggle('light', !darkOn);
    modeButtons.forEach((button) => {
      button.setAttribute('aria-checked', darkOn ? 'true' : 'false');
      button.classList.toggle('is-dark', darkOn);
    });
  }

  applyMode();
  media.addEventListener('change', applyMode);

  modeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      mode = isDark(mode) ? 'light' : 'dark';
      storage.set(modeKey, mode);
      applyMode();
    });
  });

  /* Parallax in the title area ---------------------------------------------- */

  const parallaxAreas = Array.from(document.querySelectorAll('[data-parallax]'));
  if (parallaxAreas.length) {
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)');
    let waiting = false;

    const place = () => {
      waiting = false;
      parallaxAreas.forEach((area) => {
        const box = area.getBoundingClientRect();
        if (box.bottom < 0 || box.top > window.innerHeight) return;
        // The picture follows the page at a third of its speed, never further than the room it was given.
        const room = box.height * 0.15;
        const shift = Math.max(-room, Math.min(room, -box.top * 0.3));
        area.style.setProperty('--parallax-y', shift.toFixed(1) + 'px');
      });
    };
    const ask = () => {
      if (!waiting) {
        waiting = true;
        window.requestAnimationFrame(place);
      }
    };
    const start = () => {
      parallaxAreas.forEach((area) => area.classList.add('is-parallax'));
      place();
      window.addEventListener('scroll', ask, { passive: true });
      window.addEventListener('resize', ask);
    };
    const stop = () => {
      window.removeEventListener('scroll', ask);
      window.removeEventListener('resize', ask);
      parallaxAreas.forEach((area) => {
        area.classList.remove('is-parallax');
        area.style.removeProperty('--parallax-y');
      });
    };
    if (!calm.matches) start();
    calm.addEventListener('change', (event) => (event.matches ? stop() : start()));
  }

  /* Desktop dropdowns ----------------------------------------------------- */

  const menuItems = document.querySelectorAll('.site-nav .nav-item.has-children');
  if (window.matchMedia('(hover: hover)').matches) {
    menuItems.forEach((item) => {
      let closeTimer = null;
      const open = () => {
        if (closeTimer) {
          window.clearTimeout(closeTimer);
          closeTimer = null;
        }
        item.classList.add('is-open');
      };
      const closeWithDelay = () => {
        if (closeTimer) {
          window.clearTimeout(closeTimer);
        }
        closeTimer = window.setTimeout(() => {
          item.classList.remove('is-open');
          closeTimer = null;
        }, 180);
      };
      item.addEventListener('mouseenter', open);
      item.addEventListener('mouseleave', closeWithDelay);
      item.addEventListener('focusin', open);
      item.addEventListener('focusout', closeWithDelay);
    });
  }

  // Escape closes an open submenu and puts focus back on its parent link (WCAG 1.4.13).
  menuItems.forEach((item) => {
    item.addEventListener('keydown', (event) => {
      const list = item.querySelector(':scope > .nav-list');
      // The submenu can be shown by hover, focus, or the is-open class; Escape closes it in every case.
      if (event.key !== 'Escape' || !list || window.getComputedStyle(list).display === 'none') return;
      item.classList.remove('is-open');
      item.classList.add('is-dismissed');
      const parent = item.querySelector(':scope > .nav-link');
      if (parent && item.contains(document.activeElement)) parent.focus();
      event.stopPropagation();
    });
    const rearm = () => item.classList.remove('is-dismissed');
    item.addEventListener('mouseleave', rearm);
    item.addEventListener('focusout', (event) => {
      if (!item.contains(event.relatedTarget)) rearm();
    });
  });

  /* Mobile drawer: dialog semantics, focus moves in, stays in, and returns to the opener. */

  const openButtons = document.querySelectorAll('[data-mobile-open]');
  const mobileClose = document.querySelector('[data-mobile-close]');
  const mobileOverlay = document.querySelector('[data-mobile-overlay]');
  const mobileDrawer = document.querySelector('[data-mobile-drawer]');
  const mobileToggles = document.querySelectorAll('[data-mobile-toggle]');
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
  let opener = null;

  const drawerIsOpen = () => document.body.classList.contains('mobile-menu-open');

  // While the menu is open the page behind it cannot be reached by keyboard, pointer, or screen reader.
  const pageParts = () => document.querySelectorAll('.skip-link, .site-header, .header-spacer, main, .site-footer, .mobile-bar');
  const setPageInert = (inert) => pageParts().forEach((part) => { part.inert = inert; });

  const openMobileMenu = (event) => {
    if (!mobileDrawer) return;
    opener = event && event.currentTarget ? event.currentTarget : openButtons[0];
    document.body.classList.add('mobile-menu-open');
    mobileDrawer.setAttribute('aria-hidden', 'false');
    setPageInert(true);
    openButtons.forEach((button) => button.setAttribute('aria-expanded', 'true'));
    window.setTimeout(() => {
      (mobileClose || mobileDrawer).focus();
    }, 30);
  };

  const closeMobileMenu = (restoreFocus = true) => {
    if (!mobileDrawer || !drawerIsOpen()) return;
    document.body.classList.remove('mobile-menu-open');
    mobileDrawer.setAttribute('aria-hidden', 'true');
    setPageInert(false);
    openButtons.forEach((button) => button.setAttribute('aria-expanded', 'false'));
    if (restoreFocus && opener) opener.focus();
  };

  openButtons.forEach((button) => button.addEventListener('click', openMobileMenu));
  if (mobileClose) mobileClose.addEventListener('click', () => closeMobileMenu());
  if (mobileOverlay) mobileOverlay.addEventListener('click', () => closeMobileMenu());

  if (mobileDrawer) {
    mobileDrawer.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      const focusable = Array.from(mobileDrawer.querySelectorAll(focusableSelector))
        .filter((el) => el.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    mobileDrawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => closeMobileMenu(false));
    });
  }

  mobileToggles.forEach((button) => {
    const parent = button.closest('.mobile-item');
    if (!parent) return;
    const setOpen = (open) => {
      parent.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      const icon = button.querySelector('span');
      if (icon) icon.textContent = open ? '−' : '+';
    };
    if (parent.classList.contains('is-trail') || parent.classList.contains('is-active')) {
      setOpen(true);
    }
    button.addEventListener('click', () => setOpen(!parent.classList.contains('is-open')));
  });

  window.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (drawerIsOpen()) {
      closeMobileMenu();
      return;
    }
    menuItems.forEach((item) => item.classList.remove('is-open'));
  });

  // Close the drawer when the screen grows past the point where its opener is shown.
  window.addEventListener('resize', () => {
    if (drawerIsOpen() && opener && opener.offsetParent === null) {
      closeMobileMenu(false);
    }
  });

  /* Header: sticky modes (on_scroll: slides in after 200px; always: fixed from the start; off). */

  const headerEl = document.querySelector('.site-header');
  const headerSpacer = document.querySelector('[data-header-spacer]');
  if (headerEl && headerSpacer) {
    const stickyMode = headerEl.dataset.sticky || 'on_scroll';
    const overlays = document.body.classList.contains('has-header-overlay');
    let sticky = false;

    const syncHeaderHeight = () => {
      root.style.setProperty('--header-height', headerEl.offsetHeight + 'px');
    };

    // The height the header takes in the page, its margin included (a floating header has one).
    const outerHeight = () => headerEl.offsetHeight + (parseFloat(getComputedStyle(headerEl).marginTop) || 0);

    // Smart: the header leaves while the page is read downwards and returns when it is scrolled back up. It stays while
    // something in it has the focus or the phone menu is open.
    const smart = headerEl.hasAttribute('data-smart');
    let lastY = window.scrollY;
    const syncSmart = () => {
      const y = window.scrollY;
      const delta = y - lastY;
      lastY = y;
      if (!smart) return;
      if (y <= 200 || delta < -6) {
        headerEl.classList.remove('is-tucked');
      } else if (delta > 6 && !headerEl.contains(document.activeElement) && !document.body.classList.contains('mobile-menu-open')) {
        headerEl.classList.add('is-tucked');
      }
    };

    const syncScroll = () => {
      // A transparent header turns solid as soon as the page moves under it.
      document.body.classList.toggle('header-solid', window.scrollY > 8);
      syncSmart();
      if (stickyMode !== 'on_scroll') return;
      const shouldStick = window.scrollY > 200;
      if (shouldStick === sticky) return;
      sticky = shouldStick;
      document.body.classList.toggle('header-sticky', sticky);
      // An overlaying header takes no space, so nothing needs to be held open.
      headerSpacer.style.height = sticky && !overlays ? outerHeight() + 'px' : '0px';
    };

    syncHeaderHeight();
    syncScroll();

    window.addEventListener('scroll', syncScroll, { passive: true });
    window.addEventListener('resize', () => {
      syncHeaderHeight();
      if (sticky && !overlays) {
        headerSpacer.style.height = outerHeight() + 'px';
      }
    });
  }
})();
