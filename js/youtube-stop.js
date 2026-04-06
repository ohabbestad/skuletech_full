// youtube-stop.js — Stopp YouTube-videoer når brukaren navigerer vekk frå sida
(function () {
  'use strict';

  function stoppAlleYouTubeVideoer() {
    var iframes = document.querySelectorAll('iframe[src*="youtube.com/embed"]');
    iframes.forEach(function (iframe) {
      try {
        iframe.contentWindow.postMessage('{"event":"command","func":"stopVideo","args":""}', '*');
      } catch (e) {
        // Ignorer feil, kanskje iframe ikkje er lasta enno eller CORS
      }
    });
  }

  // Stopp videoer når sida lastar ut (navigasjon vekk)
  window.addEventListener('beforeunload', stoppAlleYouTubeVideoer);
  window.addEventListener('pagehide', stoppAlleYouTubeVideoer);

  // Stopp videoer når sida blir skjult (f.eks. bytt tab)
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stoppAlleYouTubeVideoer();
    }
  });

  // Eksporter funksjonen for manuell bruk om nødvendig
  window.stoppYouTubeVideoer = stoppAlleYouTubeVideoer;
})();