// End-to-end tests against a running dev server.
//   php -S 127.0.0.1:8080 -t public dev/router.php   (with config env=development)
//   node tests/e2e.js [baseUrl]
// Requires Playwright (preinstalled in many CI images, or `npm i -D playwright`).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const MAIL = path.join(__dirname, '..', 'private', 'data', 'mail');

let failures = 0;
function check(cond, msg) { console.log((cond ? '  ✓ ' : '  ✗ ') + msg); if (!cond) failures++; }
function mails() { return fs.existsSync(MAIL) ? fs.readdirSync(MAIL).filter(f => f.endsWith('.json')).sort().map(f => JSON.parse(fs.readFileSync(path.join(MAIL, f)))) : []; }

async function pickService(p, id) {
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForSelector('#bk-service');
  await p.selectOption('#bk-service', String(id));
  await p.click('.bookly-js-next-step');
  await p.waitForSelector('.bookly-hour');
}
async function fillDetails(p, who) {
  await p.waitForSelector('#bk-first');
  await p.waitForTimeout(800); // intl-tel-input
  await p.fill('#bk-first', who.first); await p.fill('#bk-last', who.last);
  await p.fill('#bk-phone', who.phone); await p.fill('#bk-email', who.email);
  await p.fill('#bk-notes', 'Automated test booking');
}

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, timezoneId: 'Europe/London' });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));

  console.log('Booking: validation');
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForSelector('#bk-service');
  await p.click('.bookly-js-next-step');
  check((await p.textContent('.bookly-label-error')).includes('Please select a service'), 'service required');
  await p.selectOption('#bk-service', '6');
  await p.click('.bookly-js-next-step');
  await p.waitForSelector('.bookly-hour');
  check((await p.textContent('.bookly-box')).includes('Free consultation - online'), 'time step names the service');
  const firstSlotText = (await p.textContent('.bookly-hour')).trim();
  await p.click('.bookly-hour >> nth=0');
  await p.waitForSelector('#bk-first');
  await p.click('.bookly-js-next-step');
  check((await p.textContent('.bookly-js-terms-error')) === 'You must accept our terms', 'terms required first');
  await p.click('label.bookly-checkbox');
  await p.click('.bookly-js-next-step');
  await p.waitForFunction(() => document.querySelector('.bookly-js-first-name-error').textContent.length > 0);
  check((await p.textContent('.bookly-js-first-name-error')) === 'Please tell us your first name', 'first name required');
  check((await p.textContent('.bookly-js-user-email-error')) === 'Please tell us your email', 'email required');
  await p.fill('#bk-email', 'not-an-email');
  await p.click('.bookly-js-next-step');
  await p.waitForFunction(() => document.querySelector('.bookly-js-user-email-error').textContent === 'Invalid email');
  check(true, 'invalid email rejected');

  console.log('Booking: free consultation');
  const before = mails().length;
  await fillDetails(p, { first: 'Free', last: 'Tester', phone: '+44 7700 900123', email: 'free@example.com' });
  await p.click('.bookly-js-next-step');
  await p.waitForFunction(() => /Thank you! Your booking is complete/.test(document.querySelector('#booking-form').textContent), null, { timeout: 15000 });
  check(true, 'done step shown (payment step skipped for £0)');
  check((await p.$$eval('.bookly-progress-tracker > div.active', e => e.length)) === 5, 'all 5 steps active');
  const m1 = mails().slice(before);
  check(m1.length === 2, 'two e-mails sent (customer + Louise)');
  const staffMail = m1.find(m => /Louise@/i.test(m.to));
  check(staffMail && /\+447700900123/.test(staffMail.text), 'phone stored in international format');
  const approveUrl = staffMail && (staffMail.text.match(/Approve: (\S+)/) || [])[1];
  check(!!approveUrl, 'Louise\'s e-mail has an Approve link');

  console.log('Booking: slot is no longer offered');
  await pickService(p, 6);
  const firstNow = (await p.textContent('.bookly-hour')).trim();
  check(firstNow !== firstSlotText, `booked slot (${firstSlotText}) removed, first slot now ${firstNow}`);
  await pickService(p, 2);
  const hours = await p.$$eval('.bookly-hour', e => e.map(x => x.textContent.trim()));
  const [hh, mm] = firstSlotText.split(' ')[0].split(':').map(Number);
  const minus30 = `${hh}:${String(mm >= 30 ? mm - 30 : mm + 30).padStart(2, '0')} pm`.replace(/^(\d+):(\d\d) pm$/, (m0, h0, m1) => mm >= 30 ? m0 : `${h0 - 1}:${m1} pm`);
  check(!hours.includes(firstSlotText) && !hours.includes(minus30), `1h lessons overlapping it (${minus30}, ${firstSlotText}) are not offered`);

  console.log('Booking: approve link');
  const confirmedMails = () => mails().filter(m => /confirmed/i.test(m.subject) && m.to === 'free@example.com').length;
  const confirmedBefore = confirmedMails();
  const scan = await ctx.request.get(approveUrl);   // what an e-mail link scanner does (no JS)
  check(scan.status() === 200 && /<form method="post"/.test(await scan.text()), 'link scanners (plain GET) do not change the booking');
  check(confirmedMails() === confirmedBefore, '…so no confirmation e-mail yet');
  const r = await p.goto(approveUrl);
  await p.waitForURL(/confirm-appointment/);
  check(p.url().includes('/confirm-appointment/'), 'approve redirects to /confirm-appointment/');
  check((await p.textContent('h4')).includes('Appointment Confirmed'), 'confirmation page shown');
  check(confirmedMails() === confirmedBefore + 1, 'customer told it is confirmed');
  const bad = await p.goto(approveUrl.replace(/s=[0-9a-f]+/, 's=deadbeef'));
  check(bad.status() === 403, 'tampered approve link rejected (403)');

  console.log('Booking: paid lesson by card (test mode)');
  await pickService(p, 2);
  await p.click('.bookly-hour >> nth=1');
  await fillDetails(p, { first: 'Card', last: 'Tester', phone: '+44 7700 900456', email: 'card@example.com' });
  await p.click('label.bookly-checkbox');
  await p.click('.bookly-js-next-step');
  await p.waitForSelector('.bookly-payment-nav');
  check((await p.textContent('.bookly-payment-nav')).includes('I will pay now with Credit Card £30.00'), 'payment step offers card £30.00');
  await p.fill('#bk-cc', '4000 0000 0000 0002');
  await p.click('.bookly-js-next-step');
  await p.waitForFunction(() => document.querySelector('.bk-pay-error').textContent.includes('declined'));
  check(true, 'declined card shows error');
  await p.fill('#bk-cc', '4242 4242 4242 4242');
  await p.click('.bookly-js-next-step');
  await p.waitForFunction(() => /Thank you! Your booking is complete/.test(document.querySelector('#booking-form').textContent), null, { timeout: 15000 });
  check(true, 'successful card payment completes booking');
  const paid = mails().filter(m => /New booking/.test(m.subject)).pop();
  check(paid && /Paid by card/.test(paid.text), 'Louise\'s e-mail says paid by card');
  const rejectUrl = (paid.text.match(/Reject: (\S+)/) || [])[1];
  await p.goto(rejectUrl);
  await p.waitForURL(/reject-appointment/);
  check(p.url().includes('/reject-appointment/') && (await p.textContent('h4')).includes('Appointment Rejected'), 'reject link lands on /reject-appointment/');

  console.log('Contact form');
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.click('.cf__submit');
  await p.waitForSelector('.cf__tip');
  check((await p.$$eval('.cf__tip', t => t.map(x => x.textContent))).join('|') === 'The field is required.|The field is required.|The field is required.', 'required tips on all 3 fields');
  check((await p.textContent('.cf__response')) === 'One or more fields have an error. Please check and try again.', 'validation summary shown');
  await p.fill('[name="your-name"]', 'Contact Tester');
  await p.fill('[name="your-email"]', 'bad@');
  await p.fill('[name="your-message"]', 'Hello Louise');
  await p.click('.cf__submit');
  await p.waitForFunction(() => document.querySelector('.cf__tip') && document.querySelector('.cf__tip').textContent.includes('invalid'));
  check(true, 'invalid e-mail tip');
  await p.fill('[name="your-email"]', 'contact@example.com');
  await p.click('.cf__submit');
  await p.waitForFunction(() => document.querySelector('#contact-form').dataset.status === 'sent');
  check((await p.textContent('.cf__response')) === 'Thank you for your message. It has been sent.', 'sent message shown');
  check((await p.inputValue('[name="your-name"]')) === '', 'form reset after sending');
  check(mails().some(m => /Message from Contact Tester/.test(m.subject)), 'message e-mailed to Louise');

  console.log('Site behaviour');
  await p.evaluate(() => scrollTo(0, 0));
  await p.click('.main-nav a[href="/#booking"]');
  await p.waitForTimeout(1300);
  const y = await p.evaluate(() => [scrollY, Math.round(document.getElementById('booking').getBoundingClientRect().top + scrollY - 84)]);
  check(Math.abs(y[0] - y[1]) <= 2, `smooth-scrolls to #booking (${y[0]} vs ${y[1]})`);
  check(await p.$eval('#header-row', e => e.classList.contains('is-sticked')), 'sticky header after scrolling');
  await p.evaluate(() => scrollTo(0, 100)); await p.waitForTimeout(200);
  check(!(await p.$eval('#header-row', e => e.classList.contains('is-going-sticky'))), 'header resets near the top');
  const w = await p.goto(BASE + '/welcome');
  check(p.url() === BASE + '/', '/welcome redirects to /');
  const nf = await p.goto(BASE + '/does-not-exist/');
  check(nf.status() === 404 && (await p.textContent('h2')) === 'Oops!', '404 page');
  await p.goto(BASE + '/video-element/');
  await p.click('.video-cover');
  await p.waitForSelector('.lightbox iframe');
  check((await p.getAttribute('.lightbox iframe', 'src')).includes('youtube.com/embed/R4PurgzdxvY'), 'video lightbox opens YouTube');
  await p.keyboard.press('Escape'); await p.waitForTimeout(400);
  check(!(await p.$('.lightbox')), 'Esc closes lightbox');

  const m = await b.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true });
  const mp = await m.newPage();
  await mp.goto(BASE + '/', { waitUntil: 'networkidle' });
  await mp.click('.mobile-switcher'); await mp.waitForTimeout(400);
  check(await mp.evaluate(() => document.body.classList.contains('menu-open') && document.getElementById('site-header').getBoundingClientRect().left === 0), 'mobile menu opens');
  await mp.click('.main-nav a[href="/#about"]'); await mp.waitForTimeout(1300);
  check(await mp.evaluate(() => !document.body.classList.contains('menu-open') && scrollY > 2000), 'menu link closes menu and scrolls');

  check(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await b.close();
  console.log(failures ? `\n${failures} FAILED` : '\nAll checks passed');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
