// nav-global.js — Liten "Tilbake til SkuleTech"-knapp, berre på index-sidene
// Bruk: <script src="../js/nav-global.js"></script>
// Finn automatisk rett relativ sti til rota.

(function () {
  'use strict';

  var pathname = window.location.pathname;
  var rootDirName = 'skuletech';
  var rootIdx = pathname.toLowerCase().lastIndexOf('/' + rootDirName + '/');
  var subPath = rootIdx !== -1
    ? pathname.slice(rootIdx + rootDirName.length + 2)
    : pathname;

  var slashes = (subPath.match(/\//g) || []).length;
  var depth   = Math.max(0, slashes);
  var prefix  = depth > 0 ? '../'.repeat(depth) : '';

  var html = ''
    + '<a href="' + prefix + 'index.html" id="skuletech-home-btn" '
    + 'title="Tilbake til SkuleTech" '
    + 'style="'
    + 'position:fixed;bottom:18px;left:18px;z-index:9999;'
    + 'display:flex;align-items:center;gap:8px;'
    + 'text-decoration:none;'
    + 'background:rgba(15,23,42,0.82);'
    + 'backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);'
    + 'border:1px solid rgba(255,255,255,0.12);'
    + 'border-radius:10px;'
    + 'padding:7px 13px 7px 10px;'
    + 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
    + 'box-shadow:0 4px 16px rgba(0,0,0,0.35);'
    + 'transition:background 0.2s,border-color 0.2s,transform 0.15s;'
    + '"'
    + ' onmouseover="this.style.background=\'rgba(15,23,42,0.97)\';this.style.borderColor=\'rgba(255,255,255,0.28)\';this.style.transform=\'translateY(-2px)\'"'
    + ' onmouseout="this.style.background=\'rgba(15,23,42,0.82)\';this.style.borderColor=\'rgba(255,255,255,0.12)\';this.style.transform=\'translateY(0)\'"'
    + '>'
    + '<img src="' + prefix + 'koding/bilete/Logo.png" alt="" style="height:20px;width:auto;flex-shrink:0;" />'
    + '<span style="font-size:0.75rem;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;white-space:nowrap;">← Framsida</span>'
    + '</a>';

  document.addEventListener('DOMContentLoaded', function () {
    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper.firstElementChild);
  });

})();
