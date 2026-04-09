(() => {
  /* ── Form submit loading state ── */
  const forms = document.querySelectorAll('form');
  forms.forEach((form) => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"], button:not([type])');
      if (!btn || btn.dataset.noLoading === '1') return;
      btn.dataset.originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = 'Saving...';
      setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
      }, 2200);
    });
  });

  /* ── Alert auto-dismiss ── */
  const alerts = document.querySelectorAll('.alert[data-auto-dismiss="true"]');
  alerts.forEach((alert) => {
    setTimeout(() => {
      alert.classList.add('alert-fade-out');
      setTimeout(() => alert.remove(), 300);
    }, 2800);
  });

  /* ── Sidebar toggle ── */
  const shell      = document.getElementById('appShell');
  const sidebar    = document.getElementById('appSidebar');
  const overlay    = document.getElementById('sbOverlay');
  const toggleBtn  = document.getElementById('sbToggleBtn');
  const closeBtn   = document.getElementById('sbCloseBtn');

  if (!shell || !sidebar || !toggleBtn) return;

  const MOBILE_BP = 768;
  const LS_KEY    = 'sb_collapsed';

  function isMobile() {
    return window.innerWidth < MOBILE_BP;
  }

  /* Restore desktop collapsed state from localStorage */
  if (!isMobile() && localStorage.getItem(LS_KEY) === '1') {
    shell.classList.add('sb-collapsed');
  }

  function openMobile() {
    shell.classList.add('sb-open');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeMobile() {
    shell.classList.remove('sb-open');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  function toggleDesktop() {
    const isCollapsed = shell.classList.toggle('sb-collapsed');
    localStorage.setItem(LS_KEY, isCollapsed ? '1' : '0');
  }

  toggleBtn.addEventListener('click', () => {
    if (isMobile()) {
      if (shell.classList.contains('sb-open')) {
        closeMobile();
      } else {
        openMobile();
      }
    } else {
      toggleDesktop();
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', closeMobile);
  }

  if (overlay) {
    overlay.addEventListener('click', closeMobile);
  }

  /* Clean up mobile state on window resize */
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (!isMobile()) {
        closeMobile();
      }
    }, 100);
  });

  /* Close sidebar on nav link click (mobile) */
  sidebar.querySelectorAll('a[href]').forEach((link) => {
    link.addEventListener('click', () => {
      if (isMobile()) closeMobile();
    });
  });
})();
