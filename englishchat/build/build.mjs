#!/usr/bin/env node
// Static site builder for englishchat.co.uk.
// Reads build/pages/*.html (first line = JSON front matter in an HTML comment),
// wraps each page in build/layout.html and writes the result into public/.
//
//   node build/build.mjs
//
// No dependencies; needs Node 18+.

import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const pub = join(root, 'public');
const layout = readFileSync(join(root, 'build/layout.html'), 'utf8');

// Cache-busting version: hash of the CSS/JS assets.
const assetHash = createHash('sha1');
for (const dir of ['public/assets/css', 'public/assets/js']) {
  for (const f of readdirSync(join(root, dir)).sort()) {
    const p = join(root, dir, f);
    if (statSync(p).isFile()) assetHash.update(readFileSync(p));
  }
}
const v = assetHash.digest('hex').slice(0, 8);

const NAV = [
  ['Welcome', '/', 'welcome'],
  ['English Tutoring', '/#tutoring', 'tutoring'],
  ['Booking', '/#booking', 'booking'],
  ['About Me', '/#about', 'about'],
  ['Contact', '/#contact', 'contact'],
];

function header(page) {
  const items = NAV.map(([label, href, key]) => {
    const current = page.slug === 'index' && key === 'welcome';
    return `<li${current ? ' class="current"' : ''}><a href="${href}"${current ? ' aria-current="page"' : ''}>${label}</a></li>`;
  }).join('\n          ');
  return `<header class="site-header" id="site-header">
  <div class="header-row" id="header-row">
    <div class="container">
      <nav class="main-nav" aria-label="Main">
        <ul>
          ${items}
        </ul>
      </nav>
    </div>
  </div>
</header>
<div class="header-overlay" data-menu-close></div>
<div class="mobile-header">
  <div class="container">
    <button class="mobile-switcher" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="site-header">
      <span></span><span></span><span></span>
    </button>
  </div>
</div>`;
}

const footer = `<footer class="site-footer">
  <div class="container">
    <div class="footer-bottom">
      <div class="copyright" itemscope itemtype="https://schema.org/Organization"><span itemprop="copyrightHolder">Copyright by englishchat.co.uk</span></div>
    </div>
  </div>
</footer>`;

// Icons extracted from the original site's icon fonts (Font Awesome 4 glyphs
// bundled in "Defaults" + the stmicons quote mark), as inline SVG.
const ICONS = {
  mobile: '<svg class="icon" viewBox="0 0 220 512" width="14" height="32" aria-hidden="true"><path transform="translate(0,480) scale(1,-1)" d="M133 73Q133 83 126 89Q119 96 110 96Q100 96 94 89Q87 83 87 73Q87 64 94 57Q100 50 110 50Q119 50 126 57Q133 64 133 73ZM192 119V320Q192 324 189 326Q187 329 183 329H37Q33 329 30 326Q27 324 27 320V119Q27 115 30 112Q33 110 37 110H183Q187 110 189 112Q192 115 192 119ZM137 361Q137 366 133 366H87Q82 366 82 361Q82 357 87 357H133Q137 357 137 361ZM219 366V73Q219 58 209 47Q198 37 183 37H37Q22 37 11 47Q0 58 0 73V366Q0 381 11 391Q22 402 37 402H183Q198 402 209 391Q219 381 219 366Z"/></svg>',
  at: '<svg class="icon" viewBox="0 0 448 512" width="28" height="32" aria-hidden="true"><path transform="translate(0,480) scale(1,-1)" d="M278 254Q278 285 262 302Q247 320 220 320Q202 320 185 311Q167 302 153 287Q139 271 131 248Q122 224 122 196Q122 164 137 147Q153 129 180 129Q208 129 231 148Q253 167 266 196Q278 224 278 254ZM439 219Q439 188 428 163Q418 139 400 125Q383 111 363 103Q343 96 321 95Q319 95 317 95Q314 95 312 95Q285 95 271 110Q263 120 262 134Q247 115 224 103Q202 90 175 90Q129 90 104 117Q78 145 78 194Q78 239 97 277Q116 315 148 337Q181 359 219 359Q243 359 263 349Q282 339 293 321L294 326L297 342Q297 344 298 346Q300 347 301 347H335Q336 347 339 344Q340 343 339 340L305 164Q304 157 304 151Q304 139 307 136Q311 132 320 132Q328 132 336 134Q345 135 357 140Q370 146 379 155Q389 163 395 180Q402 197 402 219Q402 303 353 353Q303 402 219 402Q182 402 148 388Q115 373 90 349Q66 324 51 290Q37 257 37 219Q37 182 51 148Q66 115 90 90Q115 66 148 51Q182 37 219 37Q285 37 335 78Q338 80 342 80Q346 80 348 77L360 63Q362 59 362 56Q361 52 358 49Q329 26 293 13Q257 0 219 0Q175 0 134 17Q94 35 64 64Q35 94 17 134Q0 175 0 219Q0 264 17 305Q35 345 64 375Q94 404 134 421Q175 439 219 439Q318 439 378 378Q439 318 439 219Z"/></svg>',
  facebook: '<svg class="icon" viewBox="0 0 448 512" width="28" height="32" aria-hidden="true"><path transform="translate(0,480) scale(1,-1)" d="M357 439Q391 439 415 415Q439 391 439 357V82Q439 48 415 24Q391 0 357 0H303V170H360L368 236H303V279Q303 295 310 303Q316 311 336 311H371V370Q353 373 320 373Q281 373 258 350Q234 327 234 285V236H177V170H234V0H82Q48 0 24 24Q0 48 0 82V357Q0 391 24 415Q48 439 82 439H357Z"/></svg>',
};

// {{sp 260 100 100 [md]}} -> responsive spacer (desktop / tablet / phone heights,
// optional separate height for 992–1199px).
function macros(html) {
  return html
    .replace(/\{\{sp (\d+) (\d+) (\d+)(?: (\d+))?\}\}/g, (_, lg, sm, xs, md) =>
      `<div class="sp" style="--lg:${lg}px;--sm:${sm}px;--xs:${xs}px${md ? `;--md:${md}px` : ''}" aria-hidden="true"></div>`)
    .replace(/\{\{icon:(\w+)\}\}/g, (_, n) => ICONS[n]);
}

function esc(s) { return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

const pagesDir = join(root, 'build/pages');
let count = 0;
for (const file of readdirSync(pagesDir).filter(f => f.endsWith('.html')).sort()) {
  const raw = readFileSync(join(pagesDir, file), 'utf8');
  const m = raw.match(/^<!--\s*(\{[\s\S]*?\})\s*-->\n?/);
  if (!m) throw new Error(`${file}: missing front matter`);
  const fm = JSON.parse(m[1]);
  const slug = file.replace(/\.html$/, '');
  const page = { slug, ...fm };
  const meta = [
    fm.description ? `<meta name="description" content="${esc(fm.description)}">` : '',
    `<meta name="robots" content="${fm.noindex ? 'noindex, follow' : 'max-image-preview:large'}">`,
    fm.canonical !== false ? `<link rel="canonical" href="https://englishchat.co.uk${fm.path ?? '/' + slug.replace(/__/g, '/') + '/'}">` : '',
    `<meta property="og:title" content="${esc(fm.title)}">`,
    `<meta property="og:site_name" content="englishchat.co.uk">`,
    `<meta property="og:type" content="website">`,
  ].filter(Boolean).join('\n');
  const out = layout
    .replaceAll('{{v}}', v)
    .replace('{{title}}', fm.title)
    .replace('{{meta}}', meta)
    .replace('{{bodyClass}}', fm.bodyClass ?? '')
    .replace('{{styles}}', (fm.styles ?? []).map(s => `<link rel="stylesheet" href="${s}?v=${v}">`).join('\n'))
    .replace('{{scripts}}', (fm.scripts ?? []).map(s => /^https?:/.test(s) ? `<script src="${s}" defer></script>` : `<script src="${s}?v=${v}" defer></script>`).join('\n'))
    .replace('{{header}}', fm.bare ? '' : header(page))
    .replace('{{footer}}', fm.bare ? '' : footer)
    .replace('{{content}}', macros(raw.slice(m[0].length)).trim());
  // index -> /index.html, 404 -> /404.html, a__b -> /a/b/index.html
  const target = slug === 'index' || slug === '404'
    ? join(pub, `${slug}.html`)
    : join(pub, ...slug.split('__'), 'index.html');
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, out);
  count++;
}
console.log(`Built ${count} pages (asset version ${v}).`);
