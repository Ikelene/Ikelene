/* englishchat.co.uk – contact form (replaces Contact Form 7).
   Same fields, validation messages and response states as the old form. */
(function () {
  'use strict';
  var form = document.getElementById('contact-form');
  if (!form) return;
  var out = form.querySelector('.cf__response');
  var submit = form.querySelector('.cf__submit');

  function clearTips() {
    form.querySelectorAll('.cf__tip').forEach(function (t) { t.remove(); });
    form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) { el.setAttribute('aria-invalid', 'false'); el.classList.remove('is-invalid'); });
  }
  function tip(name, msg) {
    var wrap = form.querySelector('.cf__wrap[data-name="' + name + '"]');
    if (!wrap) return;
    var ctl = wrap.querySelector('.cf__control');
    ctl.setAttribute('aria-invalid', 'true');
    ctl.classList.add('is-invalid');
    var s = document.createElement('span');
    s.className = 'cf__tip';
    s.setAttribute('aria-hidden', 'true');
    s.textContent = msg;
    wrap.appendChild(s);
  }
  function setStatus(status, message) {
    form.setAttribute('data-status', status);
    out.textContent = message || '';
    out.setAttribute('aria-hidden', message ? 'false' : 'true');
  }

  // Typing into an invalid field clears its tip (like CF7).
  form.addEventListener('input', function (e) {
    var wrap = e.target.closest('.cf__wrap');
    if (!wrap) return;
    var t = wrap.querySelector('.cf__tip');
    if (t) { t.remove(); e.target.setAttribute('aria-invalid', 'false'); e.target.classList.remove('is-invalid'); }
  });

  function recaptchaToken() {
    var key = window.ecRecaptchaKey;
    if (!key || !window.grecaptcha) return Promise.resolve('');
    return new Promise(function (resolve) {
      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(key, { action: 'contactform' }).then(resolve, function () { resolve(''); });
      });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (form.getAttribute('data-status') === 'submitting') return;
    clearTips();
    setStatus('submitting', '');
    submit.disabled = true;
    var data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });
    recaptchaToken().then(function (token) {
      data.recaptcha = token;
      return fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    }).then(function (r) { return r.json(); }).then(function (res) {
      submit.disabled = false;
      if (res.status === 'mail_sent') { form.reset(); setStatus('sent', res.message); return; }
      if (res.status === 'validation_failed') {
        (res.invalid_fields || []).forEach(function (f) { tip(f.field, f.message); });
        setStatus('invalid', res.message);
        var first = form.querySelector('[aria-invalid="true"]'); if (first) first.focus();
        return;
      }
      setStatus(res.status === 'spam' ? 'spam' : 'failed', res.message);
    }).catch(function () {
      submit.disabled = false;
      setStatus('aborted', 'There was an error trying to send your message. Please try again later.');
    });
  });
})();
