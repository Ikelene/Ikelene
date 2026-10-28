# englishchat.co.uk, without WordPress

This is a copy of https://englishchat.co.uk that doesn't use WordPress. It has the same
pages, content, images, layout, animations and features, built as plain HTML/CSS/JS
plus a small PHP backend for the booking form and the contact form.

| On the old WordPress site | Here |
|---|---|
| Pearl theme + WPBakery page builder | Static HTML pages (`public/`), generated from `build/pages/` |
| Bookly (+ Pro + Stripe) booking form | `public/assets/js/booking.js` + `public/api/booking.php` |
| Bookly admin (appointments, approve/reject) | `/admin/` (password protected) + Approve/Reject links in the e-mail |
| Contact Form 7 + reCAPTCHA v3 | `public/assets/js/contact.js` + `public/api/contact.php` |
| Ultimate Addons / lightGallery / sticky header JS | `public/assets/js/site.js` (no jQuery) |
| Google Fonts, icon fonts | Self-hosted Poppins + Merriweather, inline SVG icons |

There are no WordPress files and no third-party PHP libraries. The browser loads
three things from outside the site: intl-tel-input (the phone field), and Stripe.js
and reCAPTCHA if you set those up.

## What was copied

- **All 17 pages**: home, About, Terms & Conditions, Confirm/Reject Appointment, Under
  Construction, Video, and the leftover theme demo pages (Shop, Cart, Checkout, My account,
  News, News Grid, 4 testimonials). The demo pages are copied exactly as they looked, raw
  `[woocommerce_…]` shortcodes included, and are marked `noindex`. You can delete them from
  `build/pages/`. The 404 page ("Oops!") is copied too.
- **Behaviour**:
  - Sticky header: fixed and hidden past 200px, slides in navy past 400px (desktop only).
  - Transparent header over the hero on the home page.
  - Mobile off-canvas menu with overlay and an animated burger icon.
  - 1-second smooth scrolling for the menu links, offset by the header height.
  - The welcome photo and Louise's photo fade on hover to show a second photo.
  - Hover colours on buttons and links.
  - Video lightbox (YouTube).
  - A sticky footer on short pages.
  - The theme's own breakpoints (768 / 992 / 1024 / 1200).
- **Booking form**: the same 5 steps (Service → Time → Details → Payment → Done), the same
  6 services, prices and durations, and the same availability:
  - Monday–Thursday 16:00–21:00, Friday 09:00–17:00.
  - Slots every 15 minutes.
  - At least 24 hours' notice, at most 365 days ahead.
  - Times shown in the visitor's own time zone.
  - The same wording, validation messages, calendar and slot columns, desktop progress bar
    and mobile stepper.
  - New bookings are *pending*. Louise gets an e-mail with **Approve / Reject** buttons, and
    they land on `/confirm-appointment/` and `/reject-appointment/` as before.
- **Contact form**: the same fields, limits, validation messages, success/error messages and
  spinner. It also has a honeypot, rate limiting and optional reCAPTCHA v3.
- `/welcome#…` links and old `/wp-content/uploads/…` image URLs redirect (301) to the new
  locations.

The layout was checked against the live site with Playwright at 1440, 1024, 768 and 375px
wide. Every heading, paragraph, button, image and input is within 2px of the original on
every page, and the home page differs by about 0.04% of pixels (font anti-aliasing).

### Intentional differences (easy to revert)

1. **Phones, home page:** on the original, the "Welcome to englishchat.co.uk…" paragraphs are
   cut off on the left edge. They're shown in full here (`.welcome__copy` in `site.css`).
2. Kept as-is but probably unintended: the big **"Let's chat"** heading links to
   `http://facebook`, which goes nowhere. Change it in `build/pages/index.html`.

## Folder layout

```
build/            page sources + build script (node build/build.mjs -> public/)
  pages/*.html    one file per page; first line is JSON front matter
  layout.html     shared <head>, header and footer
public/           ← the web root (upload this)
  assets/         css, js, fonts, images
  api/            booking.php, contact.php, appointment-action.php, stripe-webhook.php, config.php
  admin/          bookings admin
  .htaccess       Apache rules (HTTPS, redirects, 404, headers, caching)
private/          ← NOT web-accessible (upload next to public/, never inside it)
  config.sample.php   settings – copy to config.php
  lib/            PHP code (availability, bookings, mail, Stripe)
  data/           SQLite database + dev mail outbox (created automatically)
dev/router.php    router for PHP's built-in dev server (mirrors .htaccess)
tests/e2e.js      end-to-end tests (Playwright)
```

## Run it locally (dev/test version)

You need PHP 8.1+ with `pdo_sqlite` and `curl`, and Node 18+ (only for the build script and
the tests).

```bash
node build/build.mjs
php -S 127.0.0.1:8080 -t public dev/router.php
```

Open http://127.0.0.1:8080. If there's no `private/config.php`, the dev defaults are used:

- E-mails aren't sent. They're saved to `private/data/mail/` and shown under
  **Admin → Outbox (dev)**.
- Card payments use a **test-mode card form**: `4242 4242 4242 4242` succeeds,
  `4000 0000 0000 0002` is declined.
- The admin is at http://127.0.0.1:8080/admin/ and the password is `englishchat`.

Tests (with the dev server running):

```bash
npm i -D playwright && npx playwright install chromium   # first time only
node tests/e2e.js http://127.0.0.1:8080
```

The 38 checks cover:

- the free booking and paid booking (declined, then successful card) flows
- that a booked slot disappears, including overlapping 1-hour slots
- the approve and reject links, and that a tampered link is refused
- the contact form's validation and sending
- smooth scrolling, the sticky header, the `/welcome` redirect, the 404 page, the lightbox and
  the mobile menu.

## Deploying to the live host

The current host runs Apache, which this is designed for. Any PHP 8.1+ host works.

1. Upload `public/` as the web root. On cPanel hosts that's usually `public_html/`. Upload
   `private/` **one level above it**, so it sits next to the web root, not inside it. If your
   host can't do that, set the environment variable `EC_PRIVATE_DIR` to wherever `private/`
   lives. `private/.htaccess` denies web access as a fallback.
2. Copy `private/config.sample.php` to `private/config.php` and set:
   - `env` → `'production'` and `site_url` → `'https://englishchat.co.uk'`
   - `secret` → a long random string: `php -r "echo bin2hex(random_bytes(32));"`
   - `admin_password_hash`: `php -r "echo password_hash('NEW PASSWORD', PASSWORD_DEFAULT);"`
   - `mail` → `'smtp'` with the mailbox details for Louise@englishchat.co.uk. `'mail'`, which
     uses PHP's mail(), also works on most hosts but lands in spam more often.
   - `payments` → see below.
   - `recaptcha`: the site key the old form used is `6LcnjoYUAAAAAJEW1kSKKxXxTntXQKqgXp6MiwUD`.
     The **secret** is in WordPress → Contact → Integration. Leave both empty to turn
     reCAPTCHA off.
3. Make `private/data/` writable by the web server.
4. **Payments.** Bookly had its Stripe add-on enabled.
   - Put the Stripe keys (Stripe Dashboard → Developers → API keys) in `payments`, with
     `'card' => 'stripe'`.
   - Add a webhook endpoint `https://englishchat.co.uk/api/stripe-webhook.php` for the events
     `payment_intent.succeeded` and `payment_intent.payment_failed`, and paste its signing
     secret into `stripe_webhook_secret`.
   - Set `'local' => true` if you want the "I will pay locally" option.
5. Back up the WordPress site, then replace it with this. Book a free consultation yourself
   to check that the e-mails arrive.

## Things to check against the old WordPress admin

From the public site I could read everything except these settings, which only exist inside
the WordPress admin. Please check them:

- **Payment options**: card only, or "pay locally" as well?
- **E-mail wording**: the texts in `private/lib/Notifications.php` follow Bookly's defaults.
  If Louise customised hers (Bookly → Email notifications), paste them in.
- **Status of new bookings**: they start as `pending` and need approving. Change
  `booking.default_status` to `'approved'` to confirm them automatically.
- **Holidays**: Bookly had no days off configured, so Christmas and bank holidays are bookable,
  the same as before. Add days off in **Admin → Days off**.
- **Existing future bookings** in Bookly need adding again. Approve them in the admin, or ask
  and an importer can be written.

## Editing content

Edit the page in `build/pages/`, run `node build/build.mjs`, and upload `public/`. Prices,
services and opening hours are set in `private/config.php`.
