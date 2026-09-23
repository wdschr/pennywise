(function () {
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
  }

  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') || 'dark';
  }

  function persist(theme) {
    try {
      window.localStorage.setItem('bp_theme', theme);
    } catch (e) { /* private browsing / blocked storage: ignore */ }
    // Also set a cookie so the server can render the right theme on the
    // very first paint (login/installer pages) without a flash of the
    // wrong theme.
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'bp_theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax' + secure;
  }

  function syncToServer(theme) {
    var meta = document.querySelector('meta[name="bp-authenticated"]');
    if (!meta || meta.content !== '1') {
      return;
    }
    var token = document.querySelector('meta[name="bp-csrf"]');
    fetch('/api/theme.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': token ? token.content : '',
      },
      body: 'theme=' + encodeURIComponent(theme),
    }).catch(function () { /* best effort */ });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      persist(next);
      syncToServer(next);
      btn.textContent = next === 'dark' ? '☀ Light mode' : '☾ Dark mode';
    });
    btn.textContent = currentTheme() === 'dark' ? '☀ Light mode' : '☾ Dark mode';
  });
})();
