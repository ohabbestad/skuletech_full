// youtube.js — embed YouTube-videoar automatisk frå data-yt-attributt
//
// Bruk i HTML:
//   <div class="yt-video" data-yt=""></div>
//
// Når Ole har lasta opp videoen til YouTube, limer han inn URL-en:
//   <div class="yt-video" data-yt="https://youtu.be/dQw4w9WgXcQ"></div>
//
// Støttar alle vanlege YouTube-URL-format:
//   https://www.youtube.com/watch?v=VIDEO_ID
//   https://youtu.be/VIDEO_ID
//   https://www.youtube.com/embed/VIDEO_ID
//   Berre VIDEO_ID direkte

(function () {
  function hentYtId(input) {
    if (!input) return null;
    input = input.trim();

    // youtube.com/watch?v=ID
    var m = input.match(/[?&]v=([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];

    // youtu.be/ID
    m = input.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];

    // youtube.com/embed/ID
    m = input.match(/embed\/([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];

    // Berre ID (11 teikn, YouTube-format)
    if (/^[a-zA-Z0-9_-]{11}$/.test(input)) return input;

    return null;
  }

  var plasshalderbanner = `
    <div class="rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50 p-10 text-center">
      <div style="font-size:3rem;line-height:1;margin-bottom:0.75rem;">🎬</div>
      <div style="font-weight:600;color:#92400e;font-size:1.125rem;">Video kjem snart</div>
      <div style="font-size:0.875rem;color:#b45309;margin-top:0.5rem;">Denne videoen er ikkje klar enno. Les gjerne forklaringa under og prøv oppgåvene!</div>
    </div>`;

  function byggEmbed(id) {
    var src = 'https://www.youtube.com/embed/' + id
      + '?rel=0&modestbranding=1&color=white';
    return '<div style="position:relative;padding-bottom:56.25%;height:0;border-radius:1rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.12);">'
      + '<iframe src="' + src + '" '
      + 'title="YouTube-video" '
      + 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
      + 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
      + 'allowfullscreen>'
      + '</iframe>'
      + '</div>';
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.yt-video').forEach(function (div) {
      var ytInput = div.getAttribute('data-yt') || '';
      var id = hentYtId(ytInput);
      div.innerHTML = id ? byggEmbed(id) : plasshalderbanner;
    });
  });
})();
