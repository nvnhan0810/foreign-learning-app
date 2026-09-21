<script>
(function () {
  var key = 'flc-theme';
  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }
  var params = new URLSearchParams(window.location.search);
  var fromApp = params.get('flc_theme') || readCookie('flc_theme');
  if (fromApp === 'light' || fromApp === 'dark' || fromApp === 'system') {
    localStorage.setItem(key, fromApp);
  }
  var stored = localStorage.getItem(key) || @json($flcTheme ?? null) || 'system';
  if (stored !== 'light' && stored !== 'dark' && stored !== 'system') {
    stored = 'system';
  }
  var dark = stored === 'dark' || (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  var meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', dark ? '#1a2332' : '#4361ee');
})();
</script>
