/* englishchat.co.uk – site behaviour (no dependencies).
   - sticky header (desktop > 1024px): goes fixed off-screen after 200px,
     slides in after 400px, resets when scrolling back up above 400px
   - mobile off-canvas menu (< 1024px)
   - smooth scrolling for in-page links (1s "swing" easing, like jQuery.animate)
   - YouTube lightbox for [data-lightbox-iframe] links
*/
(function () {
  'use strict';

  var row = document.getElementById('header-row');
  var body = document.body;
  var switcher = document.querySelector('.mobile-switcher');

  /* ---------- Sticky header ---------- */
  var THRESHOLD = 400, THRESHOLD_MINUS = 200, lastScroll = 0;
  function sticky() {
    if (!row) return;
    var y = window.pageYOffset || document.documentElement.scrollTop;
    if (window.innerWidth > 1024) {
      if (y > lastScroll) {
        if (y > THRESHOLD - THRESHOLD_MINUS) row.classList.add('is-going-sticky');
        if (y > THRESHOLD) row.classList.add('is-sticked');
      } else if (y < THRESHOLD) {
        row.classList.remove('is-sticked', 'is-going-sticky');
      }
    } else {
      row.classList.remove('is-sticked', 'is-going-sticky');
    }
    lastScroll = y;
  }
  window.addEventListener('scroll', sticky, { passive: true });
  window.addEventListener('resize', sticky);
  sticky();

  /* ---------- Mobile menu ---------- */
  function setMenu(open) {
    body.classList.toggle('menu-open', open);
    if (switcher) {
      switcher.setAttribute('aria-expanded', open ? 'true' : 'false');
      switcher.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
  }
  if (switcher) switcher.addEventListener('click', function () { setMenu(!body.classList.contains('menu-open')); });
  document.querySelectorAll('[data-menu-close]').forEach(function (el) {
    el.addEventListener('click', function () { setMenu(false); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setMenu(false); closeLightbox(); }
  });

  /* ---------- Smooth scrolling ---------- */
  function headerOffset() {
    return window.innerWidth > 1023 ? 84 : 56;
  }
  function targetY(el) {
    return Math.max(0, Math.round(el.getBoundingClientRect().top + window.pageYOffset - headerOffset()));
  }
  var animating = null;
  function scrollToY(to, duration) {
    if (animating) cancelAnimationFrame(animating);
    var from = window.pageYOffset, start = null, dist = to - from;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { window.scrollTo(0, to); return; }
    function step(ts) {
      if (start === null) start = ts;
      var t = Math.min(1, (ts - start) / duration);
      var eased = 0.5 - Math.cos(t * Math.PI) / 2; // jQuery "swing"
      window.scrollTo(0, from + dist * eased);
      if (t < 1) animating = requestAnimationFrame(step); else animating = null;
    }
    animating = requestAnimationFrame(step);
  }
  window.ecScrollTo = function (el) { scrollToY(targetY(el), 1000); };

  var onHome = body.classList.contains('home');
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var url = new URL(a.getAttribute('href'), location.href);
    if (url.origin !== location.origin) return;
    var samePage = url.pathname === location.pathname || (onHome && (url.pathname === '/' || url.pathname === '/welcome'));
    if (!samePage) return;
    if (!url.hash && url.pathname === location.pathname && onHome) { // "Welcome" link on the home page
      e.preventDefault(); setMenu(false); scrollToY(0, 1000); return;
    }
    if (!url.hash) return;
    var target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
    if (!target) return;
    e.preventDefault();
    setMenu(false);
    scrollToY(targetY(target), 1000);
  });

  // Arriving from another page with a hash (e.g. /#booking): land on the section.
  if (location.hash.length > 1) {
    var landing = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (landing) {
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
      var land = function () { window.scrollTo(0, targetY(landing)); };
      window.addEventListener('load', function () { land(); setTimeout(land, 300); });
    }
  }

  /* ---------- reCAPTCHA v3 (loaded on every page, as before, when configured) ---------- */
  if (!body.classList.contains('error404')) {
    fetch('/api/config.php').then(function (r) { return r.json(); }).then(function (c) {
      if (!c.recaptcha_site_key) return;
      window.ecRecaptchaKey = c.recaptcha_site_key;
      var s = document.createElement('script');
      s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(c.recaptcha_site_key);
      s.async = true;
      document.head.appendChild(s);
    }).catch(function () {});
  }

  /* ---------- Lightbox ---------- */
  var box = null;
  function closeLightbox() {
    if (!box) return;
    var b = box; box = null;
    b.classList.remove('is-in');
    setTimeout(function () { b.remove(); }, 300);
    body.style.overflow = '';
  }
  document.querySelectorAll('[data-lightbox-iframe]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      box = document.createElement('div');
      box.className = 'lightbox';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.innerHTML = '<div class="lightbox__frame"><iframe allow="autoplay; fullscreen; picture-in-picture" allowfullscreen title="Video"></iframe></div><button class="lightbox__close" type="button" aria-label="Close">&times;</button>';
      box.querySelector('iframe').src = a.href;
      box.addEventListener('click', function (ev) { if (ev.target === box || ev.target.closest('.lightbox__close')) closeLightbox(); });
      body.appendChild(box);
      body.style.overflow = 'hidden';
      requestAnimationFrame(function () { box && box.classList.add('is-in'); });
      box.querySelector('.lightbox__close').focus();
    });
  });
})();
