/*
 * The system as an installable app (app/Pwa.php): registers sw.js, and runs the "Install app" button of the top bar
 * and the "Install the app" box of the log-in page. When the browser offers the install, the button opens it;
 * otherwise it shows the steps for the browser in use. Inside the installed app the button and the box are hidden.
 */
(function () {
  'use strict';

  var me = document.currentScript;
  var BASE = (me && me.getAttribute('data-base')) || './';
  var NAME = (me && me.getAttribute('data-name')) || 'the system';
  var ICON = BASE + 'assets/icons/icon-192.png';

  var deferredPrompt = null;   // the browser's own install offer, kept until a button is pressed
  var installedNow = false;    // installed during this visit

  // ------------------------------------------------------------------ inside the installed app?
  function inApp() {
    var modes = ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'];
    for (var i = 0; i < modes.length; i++) {
      if (window.matchMedia && window.matchMedia('(display-mode: ' + modes[i] + ')').matches) {
        return true;
      }
    }
    return window.navigator.standalone === true;   // iPhone and iPad
  }
  if (inApp()) {
    document.documentElement.classList.add('is-app');
  }

  // ------------------------------------------------------------------ the service worker: only on https or localhost
  var host = location.hostname;
  var local = host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '::1' || /\.localhost$/.test(host);
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || local)) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE, updateViaCache: 'none' }).catch(function () { /* the system works without it */ });
    });
  }

  // ------------------------------------------------------------------ the browser's install offer
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    document.documentElement.classList.add('can-install');
  });
  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    installedNow = true;
    document.documentElement.classList.remove('can-install');
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-pwa-install]') : null;
    if (!btn) {
      return;
    }
    e.preventDefault();
    if (deferredPrompt) {
      var offer = deferredPrompt;
      deferredPrompt = null;
      document.documentElement.classList.remove('can-install');
      // a declined offer comes back on a later visit; until then the button shows the steps
      Promise.resolve(offer.prompt()).catch(function () { showSteps(); });
      return;
    }
    showSteps();
  });

  // ------------------------------------------------------------------ the steps, when the browser does not offer the install
  function device() {
    var ua = navigator.userAgent || '';
    var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var android = /Android/i.test(ua);
    var edge = /Edg(A|iOS)?\//.test(ua);
    var samsung = /SamsungBrowser/i.test(ua);
    var firefox = /Firefox\/|FxiOS/.test(ua);
    var opera = /OPR\/|OPiOS/.test(ua);
    var chrome = /Chrome\/|CriOS/.test(ua) && !edge && !samsung && !opera;
    var safari = /Safari\//.test(ua) && !/Chrome\/|CriOS|FxiOS|EdgiOS|OPiOS|OPR\//.test(ua);
    if (ios) return 'ios';
    if (android && samsung) return 'samsung';
    if (android && firefox) return 'android-firefox';
    if (android) return 'android';
    if (edge) return 'edge';
    if (firefox) return 'firefox';
    if (safari) return 'safari-mac';
    if (chrome) return 'chrome';
    return 'other';
  }

  var STEPS = {
    chrome: ['Chrome on a computer', [
      'Click the <b>Install</b> icon at the right end of the address bar (a screen with a small arrow).',
      'No icon there? Open the Chrome menu <b>&#8942;</b> &gt; <b>Cast, save and share</b> &gt; <b>Install page as app</b>.',
      'Click <b>Install</b>. The app opens in its own window and gets an icon on the desktop and in the Start menu or Launchpad.'
    ]],
    edge: ['Edge on a computer', [
      'Click <b>App available</b> at the right end of the address bar.',
      'No icon there? Open the Edge menu <b>&#8943;</b> &gt; <b>Apps</b> &gt; <b>Install this site as an app</b>.',
      'Click <b>Install</b>. The app opens in its own window and gets an icon in the Start menu.'
    ]],
    android: ['Android (Chrome or Edge)', [
      'Tap the browser menu: <b>&#8942;</b> at the top right in Chrome, <b>&#8943;</b> at the bottom in Edge.',
      'Tap <b>Install app</b> or <b>Add to Home screen</b> (in Edge: <b>Add to phone</b>).',
      'Tap <b>Install</b>. The icon appears on your home screen.'
    ]],
    samsung: ['Samsung Internet', [
      'Tap the menu <b>&#8801;</b> at the bottom right.',
      'Tap <b>Add page to</b> &gt; <b>Home screen</b>.',
      'Tap <b>Add</b>. The icon appears on your home screen.'
    ]],
    'android-firefox': ['Firefox on Android', [
      'Tap the menu <b>&#8942;</b>.',
      'Tap <b>Install</b> or <b>Add to Home screen</b>.',
      'Tap <b>Add</b>. The icon appears on your home screen.'
    ]],
    ios: ['iPhone and iPad', [
      'Open the system in <b>Safari</b> (on iOS 16.4 or newer, Chrome and Edge work too).',
      'Tap the <b>Share</b> button (a square with an arrow pointing up): at the bottom of the screen on an iPhone, at the top on an iPad.',
      'Scroll down and tap <b>Add to Home Screen</b>.',
      'Tap <b>Add</b>, then open the system from the new icon on your home screen.'
    ]],
    'safari-mac': ['Safari on a Mac', [
      'Open the <b>File</b> menu, or the <b>Share</b> button of the toolbar.',
      'Choose <b>Add to Dock</b> (macOS Sonoma or newer).',
      'Click <b>Add</b>. The app opens from the Dock and from Launchpad.'
    ]],
    firefox: ['Firefox on a computer', [
      'Firefox on a computer does not install web apps.',
      'Open the system in <b>Chrome</b> or <b>Edge</b> and install it from there.'
    ]]
  };
  var ORDER = ['chrome', 'edge', 'android', 'ios', 'samsung', 'android-firefox', 'safari-mac', 'firefox'];

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  function stepList(key) {
    var s = STEPS[key];
    return '<ol class="pwa-steps">' + s[1].map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ol>';
  }

  function showSteps() {
    var dlg = document.getElementById('pwaDialog');
    if (dlg) {
      dlg.remove();
    }
    var here = device();
    var secure = window.isSecureContext === true;
    var chromium = here === 'chrome' || here === 'edge' || here === 'android' || here === 'samsung';
    var html = '<form method="dialog" class="pwa-dialog-inner">'
      + '<div class="pwa-dialog-head"><img src="' + esc(ICON) + '" alt="" width="40" height="40">'
      + '<h2 id="pwaDialogTitle">Install ' + esc(NAME) + '</h2>'
      + '<button type="submit" class="pwa-close" aria-label="Close">&times;</button></div>'
      + '<div class="pwa-dialog-body">'
      + '<p class="pwa-lead">Install it once, then open it from an icon on this device: full screen, without the browser\'s address bar. '
      + 'Your data stays on the server, as always.</p>';
    if (installedNow) {
      html += '<p class="pwa-note pwa-ok">The app was installed. Open it from the new icon on this device.</p>';
    }
    if (!secure) {
      html += '<div class="pwa-note pwa-warn"><strong>This address cannot install apps.</strong> '
        + 'Browsers install a system only when it is opened over <b>https://</b>, or as <b>localhost</b> on the computer that runs it. '
        + 'You opened it at <code>' + esc(location.origin) + '</code>. Ask your administrator to set up https, then open the system with its https:// address.</div>';
    }
    if (STEPS[here]) {
      html += '<h3>' + esc(STEPS[here][0]) + '</h3>' + stepList(here);
      if (secure && chromium) {
        html += '<p class="pwa-small">No Install choice in the menu? The app may already be installed on this device: look for '
          + esc(NAME) + ' on the home screen, the desktop, the Start menu or the apps list.</p>';
      }
    } else {
      html += '<p>Use the browser\'s menu and look for <b>Install</b> or <b>Add to Home screen</b>. Chrome, Edge and Safari can install the app.</p>';
    }
    html += '<details class="pwa-more"><summary>Other browsers and devices</summary>';
    ORDER.forEach(function (k) {
      if (k !== here) {
        html += '<h3>' + esc(STEPS[k][0]) + '</h3>' + stepList(k);
      }
    });
    html += '<h3>Installing needs https or localhost</h3><p class="pwa-small">Every browser installs the app only from a secure address: '
      + 'https://, or http://localhost on the computer that runs the system. From a plain http:// address on the network, '
      + 'the system still works in the browser, but it cannot be installed.</p></details>';
    html += '</div><div class="pwa-dialog-foot"><button type="submit" class="btn btn-primary btn-sm">Close</button></div></form>';

    dlg = document.createElement('dialog');
    dlg.id = 'pwaDialog';
    dlg.className = 'pwa-dialog';
    dlg.setAttribute('aria-labelledby', 'pwaDialogTitle');
    dlg.innerHTML = html;
    document.body.appendChild(dlg);
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) {
        dlg.close();   // a click on the shaded background
      }
    });
    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      dlg.setAttribute('open', '');
      dlg.classList.add('pwa-dialog-fallback');
      dlg.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); dlg.remove(); });
    }
  }
})();
