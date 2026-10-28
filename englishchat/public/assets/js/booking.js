/* englishchat.co.uk – booking form (replaces the Bookly WordPress plugin).
   Steps: 1. Service  2. Time  3. Details  4. Payment  5. Done
   Talks to /api/booking.php. Times are shown in the visitor's own time zone,
   like Bookly's "use client time zone" setting. */
(function () {
  'use strict';

  var root = document.getElementById('booking-form');
  if (!root) return;

  var API = '/api/booking.php';
  var STEPS = ['Service', 'Time', 'Details', 'Payment', 'Done'];
  var COLUMN_ROWS = 10;      // slot buttons per column (first column includes the day header)
  var ITI_VERSION = '24.6.0';

  var cfg = null;
  var st = {
    step: 1,
    serviceId: 0,
    month: null,            // Date: first day of the month shown in the calendar
    selectedDate: null,     // 'YYYY-MM-DD' (visitor local)
    slots: {},              // 'YYYY-MM-DD' -> [utc seconds]
    loadedMonths: {},       // 'YYYY-MM' -> true
    monthView: false,
    columnPage: 0,
    slot: null,             // utc seconds
    details: { first_name: '', last_name: '', phone: '', email: '', notes: '', terms: false },
    payment: null,
    booking: null,          // { id, token, client_secret?, mock? }
    errors: {},
    timeError: '',
    payError: '',
    doneText: ''
  };
  var tz = (Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
  var iti = null, stripe = null, stripeCard = null;

  /* ---------- helpers ---------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function ym(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
  function parseYmd(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function fmtTime(utc) { return new Date(utc * 1000).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).toLowerCase(); }
  function fmtLongDate(utc) { return new Date(utc * 1000).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }); }
  function fmtDayTitle(dateStr) { // "Mon, Nov 02" (Bookly pads the day)
    var d = parseYmd(dateStr);
    return d.toLocaleDateString('en-US', { weekday: 'short' }) + ', ' + d.toLocaleDateString('en-US', { month: 'short' }) + ' ' + pad(d.getDate());
  }
  function fmtMonth(d) { return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }); }
  function service() { for (var i = 0; i < cfg.services.length; i++) if (cfg.services[i].id === st.serviceId) return cfg.services[i]; return null; }
  function needsPayment() { var s = service(); return s && s.price > 0; }
  function chevron(dir) { return '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="' + (dir === 'left' ? 'M11.35 1.65a.5.5 0 0 1 0 .7L5.71 8l5.64 5.65a.5.5 0 0 1-.7.7l-6-6a.5.5 0 0 1 0-.7l6-6a.5.5 0 0 1 .7 0z' : 'M4.65 1.65a.5.5 0 0 1 .7 0l6 6a.5.5 0 0 1 0 .7l-6 6a.5.5 0 0 1-.7-.7L10.29 8 4.65 2.35a.5.5 0 0 1 0-.7z') + '"/></svg>'; }

  function api(action, data) {
    var opts = data ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) } : {};
    return fetch(API + '?action=' + action, opts).then(function (r) { return r.json(); });
  }
  function apiGet(params) {
    return fetch(API + '?' + new URLSearchParams(params)).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }

  function btn(cls, label, extra) {
    return '<button type="button" class="' + cls + ' bookly-btn ladda-button"' + (extra || '') + '><span class="ladda-label">' + label + '</span><span class="ladda-spinner"></span></button>';
  }
  function loading(el, on) { if (el) { el.classList.toggle('is-loading', on); el.disabled = on; } }

  function tracker() {
    var html = '<div class="bookly-progress-tracker bookly-table">';
    for (var i = 0; i < STEPS.length; i++) html += '<div' + (i < st.step ? ' class="active"' : '') + '>' + (i + 1) + '. ' + STEPS[i] + '<div class="step"></div></div>';
    html += '</div><ol class="bookly-stepper">';
    for (i = 0; i < STEPS.length; i++) html += '<li' + (i === st.step - 1 ? ' class="bookly-step-active"' : '') + '></li>';
    return html + '</ol>';
  }

  function go(step) {
    st.step = step;
    render();
    // keep the form in view when it changes height (like Bookly's scroll-to-form)
    var top = root.getBoundingClientRect().top;
    if (top < 0 || top > window.innerHeight * 0.6) {
      var off = window.innerWidth > 1023 ? 100 : 70;
      window.scrollTo({ top: window.pageYOffset + top - off, behavior: 'smooth' });
    }
  }

  function render() {
    var html = tracker();
    if (st.step === 1) html += renderService();
    else if (st.step === 2) html += renderTime();
    else if (st.step === 3) html += renderDetails();
    else if (st.step === 4) html += renderPayment();
    else html += '<div class="bookly-box">' + st.doneText + '</div>';
    root.innerHTML = html;
    if (st.step === 2) afterTime();
    if (st.step === 3) afterDetails();
    if (st.step === 4) afterPayment();
  }

  /* ---------- 1. Service ---------- */
  function renderService() {
    var opts = '<option value="0">Select service</option>';
    cfg.services.forEach(function (s) { opts += '<option value="' + s.id + '"' + (s.id === st.serviceId ? ' selected' : '') + '>' + esc(s.label) + '</option>'; });
    return '<div class="bookly-service-step">' +
      '<div class="bookly-box bookly-bold">Please select service: </div>' +
      '<div class="bookly-table bookly-box"><div class="bookly-form-group" data-type="service"><label for="bk-service">Service</label> <div><select id="bk-service">' + opts + '</select></div>' +
      (st.errors.service ? '<div class="bookly-label-error">' + esc(st.errors.service) + '</div>' : '') + '</div></div>' +
      '<div class="bookly-box bookly-nav-steps"><div class="bookly-right">' + btn('bookly-next-step bookly-js-next-step', 'Next') + '</div></div>' +
      '</div>';
  }
  root.addEventListener('change', function (e) {
    if (e.target.id === 'bk-service') {
      var id = +e.target.value;
      if (id !== st.serviceId) { st.serviceId = id; st.slots = {}; st.loadedMonths = {}; st.selectedDate = null; st.slot = null; }
      if (id) { st.errors = {}; e.target.classList.remove('bookly-error'); var er = root.querySelector('.bookly-label-error'); if (er) er.remove(); }
    }
  });

  /* ---------- 2. Time ---------- */
  function monthRange(d) {
    var a = new Date(d.getFullYear(), d.getMonth(), 1), b = new Date(d.getFullYear(), d.getMonth() + 1, 1);
    return [Math.floor(a.getTime() / 1000), Math.floor(b.getTime() / 1000)];
  }
  function loadMonth(d) {
    var key = ym(d);
    if (st.loadedMonths[key]) return Promise.resolve({ next: null });
    var r = monthRange(d);
    return apiGet({ action: 'slots', service: st.serviceId, from: r[0], to: r[1] }).then(function (res) {
      st.loadedMonths[key] = true;
      (res.slots || []).forEach(function (t) { var k = ymd(new Date(t * 1000)); (st.slots[k] = st.slots[k] || []).push(t); });
      return res;
    });
  }
  function firstAvailableIn(d) {
    var key = ym(d), days = Object.keys(st.slots).filter(function (k) { return k.slice(0, 7) === key && st.slots[k].length; }).sort();
    return days[0] || null;
  }
  function enterTime(btnEl) {
    loading(btnEl, true);
    var start = st.selectedDate ? parseYmd(st.selectedDate) : parseYmd(cfg.date_min);
    st.month = new Date(start.getFullYear(), start.getMonth(), 1);
    st.columnPage = 0; st.monthView = false;
    loadMonth(st.month).then(function (res) {
      if (!st.selectedDate) st.selectedDate = firstAvailableIn(st.month);
      if (!st.selectedDate && res.next) {
        var n = new Date(res.next * 1000);
        st.month = new Date(n.getFullYear(), n.getMonth(), 1);
        return loadMonth(st.month).then(function () { st.selectedDate = firstAvailableIn(st.month); });
      }
    }).then(function () { go(2); }, function () { loading(btnEl, false); alert('Sorry, the booking form could not be loaded. Please try again.'); });
  }
  function changeMonth(delta) {
    st.month = new Date(st.month.getFullYear(), st.month.getMonth() + delta, 1);
    var cal = root.querySelector('.bk-cal'); if (cal) cal.classList.add('is-loading');
    loadMonth(st.month).then(function () {
      var first = firstAvailableIn(st.month);
      if (first) { st.selectedDate = first; st.columnPage = 0; }
      render();
    });
  }
  function renderCalendar() {
    var min = parseYmd(cfg.date_min), max = parseYmd(cfg.date_max);
    var m = st.month, minM = new Date(min.getFullYear(), min.getMonth(), 1), maxM = new Date(max.getFullYear(), max.getMonth(), 1);
    var html = '<div class="bk-cal" role="group" aria-label="Choose a date"><div class="bk-cal__controls">' +
      '<button type="button" class="bk-cal__btn" data-cal="prev" aria-label="Previous month"' + (m <= minM ? ' disabled' : '') + '>' + chevron('left') + '</button>' +
      '<button type="button" class="bk-cal__btn bk-cal__btn--title" data-cal="title">' + (st.monthView ? m.getFullYear() : fmtMonth(m)) + '</button>' +
      '<button type="button" class="bk-cal__btn" data-cal="next" aria-label="Next month"' + (m >= maxM ? ' disabled' : '') + '>' + chevron('right') + '</button></div>';
    if (st.monthView) {
      html += '<div class="bk-cal__months">';
      for (var i = 0; i < 12; i++) {
        var md = new Date(m.getFullYear(), i, 1), off = md < minM || md > maxM;
        html += '<button type="button" class="bk-cal__day' + (i === m.getMonth() ? ' is-selected' : '') + '" data-month="' + i + '"' + (off ? ' disabled' : '') + '>' + md.toLocaleDateString('en-US', { month: 'short' }) + '</button>';
      }
      return html + '</div></div>';
    }
    html += '<div class="bk-cal__dow">' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (d) { return '<div>' + d + '</div>'; }).join('') + '</div>';
    var first = new Date(m.getFullYear(), m.getMonth(), 1);
    var lead = (first.getDay() + 6) % 7;
    var day = new Date(m.getFullYear(), m.getMonth(), 1 - lead);
    var last = new Date(m.getFullYear(), m.getMonth() + 1, 0);
    var trail = 6 - ((last.getDay() + 6) % 7);
    var total = lead + last.getDate() + trail;
    for (var c = 0; c < total; c++) {
      if (c % 7 === 0) html += (c ? '</div>' : '') + '<div class="bk-cal__row">';
      var k = ymd(day), inMonth = day.getMonth() === m.getMonth();
      var avail = inMonth && st.slots[k] && st.slots[k].length;
      html += '<button type="button" class="bk-cal__day' + (inMonth ? '' : ' is-other') + (k === st.selectedDate ? ' is-selected' : '') + '" data-date="' + k + '"' + (avail ? '' : ' disabled') + ' aria-label="' + esc(day.toDateString()) + '">' + day.getDate() + '</button>';
      day.setDate(day.getDate() + 1);
    }
    return html + '</div></div>';
  }
  function renderTime() {
    var s = service();
    var html = '<div class="bookly-box">Below you can find a list of available time slots for <b>' + esc(s.name) + '</b> by <b>' + esc(cfg.staff) + '</b>.<br>\nClick on a time slot to proceed with booking.</div>' +
      '<div class="bookly-box bookly-label-error"' + (st.timeError ? '' : ' style="display:none"') + '>' + esc(st.timeError) + '</div>' +
      '<div class="bookly-slot-calendar">' + renderCalendar() + '</div>';
    var times = (st.selectedDate && st.slots[st.selectedDate]) || [];
    if (!times.length) {
      html += '<div class="bookly-not-time-screen">' + (Object.keys(st.slots).length ? 'No time is available for selected criteria.' : 'No time is available for selected criteria.') + '</div>';
    } else {
      var items = ['<button type="button" class="bookly-day" tabindex="-1">' + esc(fmtDayTitle(st.selectedDate)) + '</button>'];
      times.forEach(function (t) {
        items.push('<button type="button" class="bookly-hour ladda-button" data-slot="' + t + '"><span class="ladda-label"><i class="bookly-hour-icon"><span></span></i>' + esc(fmtTime(t)) + '</span><span class="ladda-spinner"></span></button>');
      });
      var cols = [];
      for (var i = 0; i < items.length; i += COLUMN_ROWS) cols.push('<div class="bookly-column">' + items.slice(i, i + COLUMN_ROWS).join('') + '</div>');
      html += '<div class="bookly-time-step"><div class="bookly-columnizer-wrap"><div class="bookly-columnizer" data-cols="' + cols.length + '">' + cols.join('') + '</div></div></div>';
    }
    html += '<div class="bookly-box bookly-nav-steps">' + btn('bookly-back-step bookly-js-back-step', 'Back') +
      '<div class="bookly-right bk-time-pager" hidden>' + btn('bookly-time-prev bookly-js-time-prev', chevron('left'), ' aria-label="Previous"') + '</div>' +
      '<div class="bookly-right bk-time-pager" hidden>' + btn('bookly-time-next bookly-js-time-next', chevron('right'), ' aria-label="Next"') + '</div></div>';
    return html;
  }
  // Shows as many slot columns as fit next to the calendar, with < > paging when needed.
  function afterTime() {
    var colz = root.querySelector('.bookly-columnizer');
    var pagers = root.querySelectorAll('.bk-time-pager');
    if (!colz) return;
    var cols = colz.querySelectorAll('.bookly-column');
    var step = root.querySelector('.bookly-time-step');
    var avail = step.getBoundingClientRect().width;
    var cal = root.querySelector('.bookly-slot-calendar');
    if (getComputedStyle(cal).float !== 'none') avail = root.getBoundingClientRect().width - cal.getBoundingClientRect().width - 20;
    var per = Math.max(1, Math.floor(avail / 127));
    var pages = Math.ceil(cols.length / per);
    if (st.columnPage >= pages) st.columnPage = pages - 1;
    cols.forEach(function (c, i) { c.style.display = Math.floor(i / per) === st.columnPage ? '' : 'none'; });
    pagers.forEach(function (p) { p.hidden = pages <= 1; });
    var prev = root.querySelector('.bookly-js-time-prev'), next = root.querySelector('.bookly-js-time-next');
    if (prev) prev.disabled = st.columnPage === 0;
    if (next) next.disabled = st.columnPage >= pages - 1;
  }
  window.addEventListener('resize', function () { if (st.step === 2) afterTime(); });

  /* ---------- 3. Details ---------- */
  var ERR_CLASS = { first_name: 'first-name', last_name: 'last-name', phone: 'user-phone', email: 'user-email', terms: 'terms' };
  function renderDetails() {
    var s = service(), d = st.details, e = st.errors;
    function field(key, id, label, input) {
      return '<div class="bookly-form-group"><label for="' + id + '">' + label + '</label><div>' + input + '</div><div class="bookly-js-' + ERR_CLASS[key] + '-error bookly-label-error">' + esc(e[key] || '') + '</div></div>';
    }
    function text(key, id, cls, type, extra) {
      return '<input id="' + id + '" class="' + cls + (e[key] ? ' bookly-error' : '') + '" type="' + type + '" value="' + esc(d[key]) + '"' + (extra || '') + '>';
    }
    return '<div class="bookly-box">You selected a booking for <b>' + esc(s.name) + '</b> at <b>' + esc(fmtTime(st.slot)) + '</b> on <b>' + esc(fmtLongDate(st.slot)) + '</b>. <br>\n' +
      'The price for the service is <b>' + esc(s.price_text) + '</b>.<br>\nPlease provide your details in the form below to proceed with booking.</div>' +
      '<div class="bookly-details-step">' +
      '<div class="bookly-box bookly-table">' +
      field('first_name', 'bk-first', 'First name', text('first_name', 'bk-first', 'bookly-js-first-name', 'text', ' autocomplete="given-name"')) +
      field('last_name', 'bk-last', 'Last name', text('last_name', 'bk-last', 'bookly-js-last-name', 'text', ' autocomplete="family-name"')) +
      '</div><div class="bookly-box bookly-table">' +
      field('phone', 'bk-phone', 'Phone', text('phone', 'bk-phone', 'bookly-js-user-phone-input bookly-user-phone', 'tel', ' autocomplete="tel"')) +
      field('email', 'bk-email', 'Email', text('email', 'bk-email', 'bookly-js-user-email', 'text', ' maxlength="255" autocomplete="email" inputmode="email"')) +
      '</div>' +
      '<div class="bookly-box"><div class="bookly-form-group"><label for="bk-notes">Notes</label><div><textarea id="bk-notes" class="bookly-js-user-notes" rows="3">' + esc(d.notes) + '</textarea></div></div></div>' +
      '<div class="bookly-box"><div class="bookly-checkbox-group"><input type="checkbox" class="bookly-js-terms" id="bk-terms"' + (d.terms ? ' checked' : '') + '>' +
      '<label class="bookly-square bookly-checkbox" for="bk-terms"><i class="bookly-icon-sm"></i></label> <label for="bk-terms">I agree to the terms of service</label></div>' +
      '<div class="bookly-js-terms-error bookly-label-error">' + esc(e.terms || '') + '</div></div>' +
      '</div>' +
      '<div class="bookly-box bookly-nav-steps">' + btn('bookly-back-step bookly-js-back-step', 'Back') + '<div class="bookly-right">' + btn('bookly-next-step bookly-js-next-step', 'Next') + '</div></div>';
  }
  function loadIti() {
    if (window.intlTelInput) return Promise.resolve();
    return new Promise(function (resolve) {
      var css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = 'https://cdn.jsdelivr.net/npm/intl-tel-input@' + ITI_VERSION + '/build/css/intlTelInput.min.css';
      document.head.appendChild(css);
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/intl-tel-input@' + ITI_VERSION + '/build/js/intlTelInputWithUtils.min.js';
      s.onload = resolve; s.onerror = resolve;   // falls back to a plain phone field
      document.head.appendChild(s);
    });
  }
  var geoCountry = null;
  function afterDetails() {
    var input = root.querySelector('#bk-phone');
    loadIti().then(function () {
      if (!window.intlTelInput || !input.isConnected) return;
      iti = window.intlTelInput(input, {
        initialCountry: 'auto',
        separateDialCode: false,
        nationalMode: true,
        geoIpLookup: function (ok) {
          if (geoCountry) return ok(geoCountry);
          fetch('https://ipapi.co/country/').then(function (r) { return r.text(); }).then(function (c) {
            geoCountry = /^[A-Z]{2}$/.test(c.trim()) ? c.trim().toLowerCase() : 'gb'; ok(geoCountry);
          }, function () { ok('gb'); });
        }
      });
    });
  }
  function readDetails() {
    var d = st.details;
    d.first_name = root.querySelector('#bk-first').value;
    d.last_name = root.querySelector('#bk-last').value;
    var phone = root.querySelector('#bk-phone').value.trim();
    d.phone = phone && iti && iti.getNumber() ? iti.getNumber() : phone;
    d.phoneValid = !phone || !iti || !iti.isValidNumber || iti.isValidNumber() !== false;
    d.phoneRaw = phone;
    d.email = root.querySelector('#bk-email').value;
    d.notes = root.querySelector('#bk-notes').value;
    d.terms = root.querySelector('#bk-terms').checked;
  }
  function showErrors(errors) {
    st.errors = errors;
    ['first_name', 'last_name', 'phone', 'email', 'terms'].forEach(function (k) {
      var el = root.querySelector('.bookly-js-' + ERR_CLASS[k] + '-error');
      if (el) el.textContent = errors[k] || '';
    });
    [['first_name', '#bk-first'], ['last_name', '#bk-last'], ['phone', '#bk-phone'], ['email', '#bk-email']].forEach(function (p) {
      var el = root.querySelector(p[1]); if (el) el.classList.toggle('bookly-error', !!errors[p[0]]);
    });
  }
  function payload(extra) {
    var d = st.details, o = { service_id: st.serviceId, start: st.slot, first_name: d.first_name, last_name: d.last_name, phone: d.phone, email: d.email, notes: d.notes, terms: d.terms, client_tz: tz };
    for (var k in extra) o[k] = extra[k];
    return o;
  }
  function detailsNext(b) {
    readDetails();
    if (!st.details.terms) { showErrors({ terms: 'You must accept our terms' }); return; }
    loading(b, true);
    api('validate', payload()).then(function (res) {
      loading(b, false);
      var errors = res.errors || {};
      if (!errors.phone && st.details.phoneRaw && !st.details.phoneValid) errors.phone = 'Invalid phone number';
      if (res.slot_unavailable) { return backToTime(res.message); }
      if (Object.keys(errors).length) return showErrors(errors);
      showErrors({});
      if (needsPayment()) {
        if (!st.payment) st.payment = cfg.payments.card ? 'card' : (cfg.payments.local ? 'local' : null);
        go(4);
      } else {
        loading(b, true);
        book(b, 'free');
      }
    }, function () { loading(b, false); alert('Error.'); });
  }
  function backToTime(message) {
    st.timeError = message || '';
    st.loadedMonths = {}; st.slots = {}; st.slot = null;
    var d = st.selectedDate;
    st.selectedDate = null;
    st.month = d ? parseYmd(d) : parseYmd(cfg.date_min);
    st.month = new Date(st.month.getFullYear(), st.month.getMonth(), 1);
    loadMonth(st.month).then(function () { st.selectedDate = (d && st.slots[d] && st.slots[d].length) ? d : firstAvailableIn(st.month); go(2); });
  }

  /* ---------- 4. Payment ---------- */
  function renderPayment() {
    var s = service(), p = cfg.payments, html = '<div class="bookly-payment-nav"><div class="bookly-box">Please tell us how you would like to pay:</div>';
    if (p.local) html += '<div class="bookly-box bookly-list"><label><input type="radio" class="bookly-js-payment" name="bk-payment" value="local"' + (st.payment === 'local' ? ' checked' : '') + '> <span>I will pay locally</span></label></div>';
    if (p.card) html += '<div class="bookly-box bookly-list"><label><input type="radio" class="bookly-js-payment" name="bk-payment" value="card"' + (st.payment === 'card' ? ' checked' : '') + '> <span>I will pay now with Credit Card <span class="bookly-js-pay">' + esc(s.price_text) + '</span></span><img src="/assets/img/payments.svg" alt="cards" width="180" height="24"></label></div>';
    if (!p.local && !p.card) html += '<div class="bookly-box">Online payment is not available at the moment. Please <a href="#contact">contact me</a> to book.</div>';
    html += '</div>';
    if (p.card) {
      html += '<div class="bookly-box bk-card" data-card-form' + (st.payment === 'card' ? '' : ' hidden') + '>';
      if (p.card === 'mock') {
        html += '<div class="bk-test-note">TEST MODE – no real payment is taken. Use 4242 4242 4242 4242 (success) or 4000 0000 0000 0002 (declined), any future date and any CVC.</div>' +
          '<div class="bookly-form-group"><label for="bk-cc">Credit Card Number</label><div><input type="text" id="bk-cc" inputmode="numeric" autocomplete="cc-number" placeholder="1234 1234 1234 1234"></div></div>' +
          '<div class="bk-card__row"><div class="bookly-form-group"><label for="bk-exp">Expiration Date</label><div><input type="text" id="bk-exp" autocomplete="cc-exp" placeholder="MM / YY"></div></div>' +
          '<div class="bookly-form-group"><label for="bk-cvc">Card Security Code</label><div><input type="text" id="bk-cvc" inputmode="numeric" autocomplete="cc-csc" placeholder="CVC"></div></div></div>';
      } else {
        html += '<div class="bookly-form-group"><label>Card details</label><div><div class="bk-stripe-el" id="bk-stripe-card"></div></div></div>';
      }
      html += '</div>';
    }
    html += '<div class="bookly-box bookly-label-error bk-pay-error"' + (st.payError ? '' : ' style="display:none"') + '>' + esc(st.payError) + '</div>';
    html += '<div class="bookly-box bookly-nav-steps">' + btn('bookly-back-step bookly-js-back-step', 'Back') + '<div class="bookly-right">' + btn('bookly-next-step bookly-js-next-step', 'Next') + '</div></div>';
    return html;
  }
  function loadStripe() {
    if (window.Stripe) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script'); s.src = 'https://js.stripe.com/v3/'; s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
    });
  }
  function afterPayment() {
    if (cfg.payments.card === 'stripe') {
      loadStripe().then(function () {
        stripe = stripe || window.Stripe(cfg.payments.stripe_publishable_key);
        var mount = root.querySelector('#bk-stripe-card');
        if (!mount) return;
        stripeCard = stripe.elements().create('card', { hidePostalCode: true, style: { base: { fontSize: '14px', color: '#011031' } } });
        stripeCard.mount(mount);
      });
    }
  }
  function payError(msg) {
    st.payError = msg || '';
    var el = root.querySelector('.bk-pay-error');
    if (el) { el.textContent = st.payError; el.style.display = st.payError ? '' : 'none'; }
  }
  function book(b, method) {
    api('book', payload({ payment: method })).then(function (res) {
      if (!res.ok) {
        loading(b, false);
        if (res.slot_unavailable) return backToTime(res.message);
        if (res.errors) { st.errors = res.errors; return go(3); }
        return st.step === 4 ? payError(res.message) : alert(res.message || 'Error.');
      }
      if (res.done) return finish(res);
      st.booking = res;
      pay(b);
    }, function () { loading(b, false); payError('Error.'); });
  }
  function pay(b) {
    var bk = st.booking;
    if (bk.mock) {
      api('pay_mock', { id: bk.id, token: bk.token, card_number: (root.querySelector('#bk-cc') || {}).value || '' }).then(function (res) {
        if (res.ok) return finish(res);
        loading(b, false); payError(res.message);
      });
      return;
    }
    var d = st.details;
    stripe.confirmCardPayment(bk.client_secret, {
      payment_method: { card: stripeCard, billing_details: { name: (d.first_name + ' ' + d.last_name).trim(), email: d.email, phone: d.phone } }
    }).then(function (result) {
      if (result.error) { loading(b, false); payError(result.error.message); return; }
      api('finalize', { id: bk.id, token: bk.token }).then(function (res) {
        if (res.ok) return finish(res);
        loading(b, false); payError(res.message);
      });
    });
  }
  function paymentNext(b) {
    payError('');
    var method = (root.querySelector('input[name="bk-payment"]:checked') || {}).value;
    if (!method) return payError('Please select a payment method.');
    loading(b, true);
    if (method === 'card' && st.booking && st.booking.id) return pay(b);  // retry the same held booking
    book(b, method);
  }
  function finish(res) {
    st.booking = null;
    st.doneText = res.processing ? 'Your payment has been accepted for processing.' : 'Thank you! Your booking is complete. An email with details of your booking has been sent to you.';
    go(5);
  }
  function releaseHold(unloading) {
    if (!(st.booking && st.booking.id)) return;
    var data = { id: st.booking.id, token: st.booking.token };
    if (unloading && navigator.sendBeacon) navigator.sendBeacon(API + '?action=release', new Blob([JSON.stringify(data)], { type: 'application/json' }));
    else api('release', data);
    st.booking = null;
  }

  /* ---------- events ---------- */
  root.addEventListener('click', function (e) {
    var t = e.target.closest('button, input[name="bk-payment"]');
    if (!t || !root.contains(t)) return;
    if (t.name === 'bk-payment') {
      st.payment = t.value;
      var cf = root.querySelector('[data-card-form]'); if (cf) cf.hidden = t.value !== 'card';
      return;
    }
    if (t.dataset.cal === 'prev') return st.monthView ? (st.month = new Date(st.month.getFullYear() - 1, st.month.getMonth(), 1), render()) : changeMonth(-1);
    if (t.dataset.cal === 'next') return st.monthView ? (st.month = new Date(st.month.getFullYear() + 1, st.month.getMonth(), 1), render()) : changeMonth(1);
    if (t.dataset.cal === 'title') { st.monthView = !st.monthView; return render(); }
    if (t.dataset.month !== undefined) { st.monthView = false; st.month = new Date(st.month.getFullYear(), +t.dataset.month, 1); return changeMonth(0); }
    if (t.dataset.date) { st.selectedDate = t.dataset.date; st.columnPage = 0; st.timeError = ''; return render(); }
    if (t.dataset.slot) {
      st.slot = +t.dataset.slot; st.timeError = '';
      loading(t, true);
      setTimeout(function () { go(3); }, 250);
      return;
    }
    if (t.classList.contains('bookly-js-time-next')) { st.columnPage++; return afterTime(); }
    if (t.classList.contains('bookly-js-time-prev')) { st.columnPage = Math.max(0, st.columnPage - 1); return afterTime(); }
    if (t.classList.contains('bookly-js-back-step')) {
      if (st.step === 3) { readDetails(); st.errors = {}; }
      if (st.step === 4) { releaseHold(); st.payError = ''; }
      return go(st.step === 2 ? 1 : st.step - 1);
    }
    if (t.classList.contains('bookly-js-next-step')) {
      if (st.step === 1) {
        if (!st.serviceId) { st.errors = { service: 'Please select a service' }; return render(); }
        st.errors = {};
        return enterTime(t);
      }
      if (st.step === 3) return detailsNext(t);
      if (st.step === 4) return paymentNext(t);
    }
  });
  window.addEventListener('pagehide', function () { releaseHold(true); });

  /* ---------- start ---------- */
  apiGet({ action: 'init' }).then(function (c) { cfg = c; render(); }, function () {
    root.innerHTML = '<div class="bookly-box">Sorry, online booking is temporarily unavailable. Please <a href="#contact">contact me</a> to book a lesson.</div>';
  });
})();
