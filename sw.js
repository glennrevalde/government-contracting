/*
 * Service worker of the installed app (app/Pwa.php).
 *
 * Pages are never cached: they hold the company's private data, so every page comes from the server every time.
 * When the server cannot be reached, offline.html is shown instead of the browser's error page.
 * Only the files of assets/ that make up the screens (stylesheets, scripts, fonts and icons) are kept, so pages open
 * faster. Posted forms (saving, uploads, log-in), downloads and files are left to the browser untouched.
 *
 * Change VERSION when this file changes in a way that must clear what the devices have kept.
 */
'use strict';

const VERSION = 'v1';
const SCOPE = self.registration.scope;                       // the folder of index.php, ending in "/"
const SCOPE_PATH = new URL(SCOPE).pathname;
// several systems can run on the same server (localhost/one-system/, localhost/another/): each keeps its own caches
const ASSET_CACHE = 'gsmart-assets-' + VERSION + ':' + SCOPE_PATH;
const OFFLINE_CACHE = 'gsmart-offline-' + VERSION + ':' + SCOPE_PATH;
const OFFLINE_URL = new URL('offline.html', SCOPE).href;
const ASSETS_PATH = SCOPE_PATH + 'assets/';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(OFFLINE_CACHE)
      .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(names
        .filter((n) => n.startsWith('gsmart-') && n.endsWith(':' + SCOPE_PATH) && n !== ASSET_CACHE && n !== OFFLINE_CACHE)
        .map((n) => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }
});

/** A link that sends a file instead of a page: CSV exports, downloads, uploaded files, pictures, ZIP, Word */
function isFileLink(url) {
  if (url.searchParams.has('export') || url.searchParams.has('download') || url.searchParams.has('dl')) {
    return true;
  }
  const a = (url.searchParams.get('a') || '').toLowerCase();
  if (a && /(^|_)(download|file|files|zip|csv|word|doc|pdf|pdfview|photo|pic|image|seal|bg|template|backup|export|attachment)(_|$)/.test(a)) {
    return true;
  }
  // a file opened by its own address (not a .php page or a folder)
  const last = url.pathname.split('/').pop();
  return last.includes('.') && !/\.(php|html?)$/i.test(last);
}

/** The screen files kept on the device: stylesheets, scripts, fonts and the app icons */
function isScreenAsset(url) {
  if (!url.pathname.startsWith(ASSETS_PATH)) {
    return false;
  }
  if (url.pathname.startsWith(ASSETS_PATH + 'icons/')) {
    return true;
  }
  return /\.(css|js|woff2?|ttf|otf|eot)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;                                                  // saving, uploads, log-in: straight to the server
  }
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || !req.url.startsWith(SCOPE)) {
    return;                                                  // other sites (the CDN) and other systems on this server
  }
  if (req.headers.has('range') || (req.cache === 'only-if-cached' && req.mode !== 'same-origin')) {
    return;
  }
  if (req.mode === 'navigate') {
    if (!isFileLink(url)) {
      event.respondWith(openPage(req));
    }
    return;                                                  // downloads: the browser handles them itself
  }
  if (isScreenAsset(url)) {
    event.respondWith(screenAsset(event, req, url));
  }
  // everything else (data loaded by the pages, pictures, uploaded files) goes to the server as usual
});

/** A page: always from the server, never kept; the offline page when the server cannot be reached */
async function openPage(req) {
  try {
    return await fetch(req);
  } catch (e) {
    const cache = await caches.open(OFFLINE_CACHE);
    const offline = await cache.match(OFFLINE_URL);
    return offline || new Response('<!doctype html><meta charset="utf-8"><title>No internet connection</title><p>No internet connection. Check the connection, then try again.</p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
}

/**
 * A stylesheet, script, font or icon. The system adds the file's date to its address (?v=...) and the icon font
 * carries its own fingerprint (?...), so a kept copy of such an address is always the right one; an address
 * without one is shown from the device and refreshed in the background.
 */
async function screenAsset(event, req, url) {
  const cache = await caches.open(ASSET_CACHE);
  const kept = await cache.match(req);
  const versioned = url.search.length > 1;
  if (kept && versioned) {
    return kept;
  }
  const fresh = fetch(req).then(async (res) => {
    if (res && res.ok && res.type === 'basic') {
      await cache.put(req, res.clone());
      await dropOlderCopies(cache, url);
    }
    return res;
  }).catch(() => null);
  if (kept) {
    event.waitUntil(fresh);
    return kept;
  }
  return (await fresh) || Response.error();
}

/** Keeps one copy per file: the copies of earlier versions (another ?v=) are removed */
async function dropOlderCopies(cache, url) {
  const keys = await cache.keys();
  await Promise.all(keys
    .filter((k) => { const u = new URL(k.url); return u.pathname === url.pathname && u.search !== url.search; })
    .map((k) => cache.delete(k)));
}
