(function () {
  const SIDEBAR_MAX_WIDTH = 260;
  const SIDEBAR_MIN_WIDTH = 82;
  const STORAGE_WIDTH = 'hiburim.sidebar.width';
  const STORAGE_COLLAPSED = 'hiburim.sidebar.collapsed';

  const navItems = [
    { page: 'home', href: 'index.html', icon: 'fa-solid fa-house', label: 'בית', roles: ['volunteer', 'manager'] },
    { page: 'reports', href: 'reports.html', icon: 'fa-solid fa-file-invoice', label: 'דיווחים', roles: ['volunteer', 'manager'] },
    { page: 'my-elderly', href: 'my_elderly.html', icon: 'fa-solid fa-hand-holding-heart', label: 'הקשיש שלי', roles: ['volunteer'] },
    { page: 'chatbot', href: 'chatbot.html', icon: 'fa-solid fa-comment', label: "צ'אט דיווח", roles: ['volunteer'] },
    { section: 'ניהול עמותה', roles: ['manager'] },
    { page: 'insights', href: 'statusAI.html#overview', icon: 'fa-solid fa-chart-pie', label: 'תובנות', roles: ['manager'] },
    { page: 'ai-reports', href: 'statusAI.html#ai', icon: 'fa-solid fa-wand-magic-sparkles', label: 'דוחות AI', roles: ['manager'] },
    { page: 'people', href: 'manager_people.html', icon: 'fa-solid fa-people-arrows', label: 'מתנדבים וקשישים', roles: ['manager'] },
    { page: 'calendar', href: 'calendar.html', icon: 'fa-solid fa-calendar', label: 'יומן', roles: ['volunteer'] },
    { page: 'profile', href: 'profile.html', icon: 'fa-solid fa-user', label: 'פרופיל', roles: ['volunteer', 'manager'] },
    { page: 'about', href: 'about.html', icon: 'fa-solid fa-circle-info', label: 'אודות', roles: ['volunteer', 'manager'] },
    { page: 'logout', href: '#logout', icon: 'fa-solid fa-right-from-bracket', label: 'התנתקות', action: 'logout' }
  ];

  let currentUser = null;
  let currentUserPromise = null;
  let supportAssetsLoaded = false;

  const filenameToPage = {
    '': 'home',
    'index.html': 'home',
    'reports.html': 'reports',
    'my_elderly.html': 'my-elderly',
    'chatbot.html': 'chatbot',
    'statusai.html': 'insights',
    'assignments.html': 'assignments',
    'manager_people.html': 'people',
    'calendar.html': 'calendar',
    'profile.html': 'profile',
    'about.html': 'about'
  };

  function currentFilename() {
    const filename = window.location.pathname.split('/').pop() || '';
    return decodeURIComponent(filename).toLowerCase();
  }

  function currentPage() {
    const explicitPage = document.body?.dataset.page || document.documentElement.dataset.page;
    if (explicitPage) return explicitPage;

    const filename = currentFilename();
    const hash = window.location.hash.replace('#', '');

    if (filename === 'statusai.html' && hash === 'ai') return 'ai-reports';
    if (filename === 'statusai.html') return 'insights';
    if (filename === 'assignments.html' && ['volunteers', 'elderly', 'assignments'].includes(hash)) return hash;

    return filenameToPage[filename] || 'home';
  }

  function normalizedRole(user) {
    if (!user) return null;
    if (user.role === 'manager' || user.role_key === 'ngo_manager') return 'manager';
    return user.role || null;
  }

  function itemAllowed(item, user) {
    if (!item.roles || !item.roles.length) return true;
    if (!user) return false;
    return item.roles.includes(normalizedRole(user));
  }

  function visibleNavItems(user) {
    if (!user) return [];

    const items = [];

    navItems.forEach((item, index) => {
      if (item.section) {
        const hasVisibleItems = navItems.slice(index + 1).some(nextItem => {
          if (nextItem.section) return false;
          return itemAllowed(nextItem, user);
        });

        if (itemAllowed(item, user) && hasVisibleItems) {
          items.push(item);
        }

        return;
      }

      if (itemAllowed(item, user)) {
        items.push(item);
      }
    });

    return items;
  }

  function navLink(item, activePage, mobile) {
    const active = item.page === activePage;
    const className = mobile ? 'mobile-link' : 'nav-link';
    const activeClass = active ? ' active' : '';
    const current = active ? ' aria-current="page"' : '';
    const action = item.action ? ` data-action="${item.action}"` : '';
    const label = mobile ? `<span>${item.label}</span>` : `<span class="nav-text">${item.label}</span>`;

    return `<a href="${item.href}" class="${className}${activeClass}" data-page="${item.page}"${action}${current}><i class="${item.icon}"></i>${label}</a>`;
  }

  function sidebarMarkup(activePage, user) {
    const items = visibleNavItems(user);

    return `
      <div class="sidebar-header">
        <button class="sidebar-toggle" type="button" aria-label="כיווץ תפריט" aria-expanded="true" title="כיווץ תפריט">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="sidebar-logo">
          <i class="fa-solid fa-hands-holding-circle"></i>
          <span>חיבורים</span>
        </div>
      </div>
      <nav class="sidebar-nav" aria-label="ניווט ראשי">
        ${items.map(item => (
          item.section
            ? `<div class="nav-section-label">${item.section}</div>`
            : navLink(item, activePage, false)
        )).join('')}
      </nav>
      <div class="sidebar-resize-handle" role="separator" aria-orientation="vertical" aria-label="שינוי רוחב תפריט"></div>
    `;
  }

  function mobileMarkup(activePage, user) {
    return visibleNavItems(user)
      .filter(item => !item.section)
      .map(item => navLink(item, activePage, true))
      .join('');
  }

  function updateActive() {
    const activePage = currentPage();
    document.querySelectorAll('.nav-link[data-page], .mobile-link[data-page]').forEach(link => {
      const active = link.dataset.page === activePage;
      link.classList.toggle('active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  function currentPageAllowed(user) {
    const filename = currentFilename();

    if (filename === 'login.html') {
      return true;
    }

    if (filename === 'assignments.html') {
      return ['volunteer', 'manager'].includes(normalizedRole(user));
    }

    const activePage = currentPage();
    return visibleNavItems(user).some(item => !item.section && item.page === activePage);
  }

  function redirectIfPageForbidden(user) {
    if (!user || currentPageAllowed(user)) {
      return;
    }

    window.location.href = 'index.html';
  }

  function clampWidth(width) {
    const numberWidth = Number(width);
    if (!Number.isFinite(numberWidth)) return SIDEBAR_MAX_WIDTH;
    return Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, Math.round(numberWidth)));
  }

  function isSmallScreen() {
    return window.matchMedia('(max-width: 920px)').matches;
  }

  function getStoredValue(key) {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function setStoredValue(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (error) {
      // Sidebar preferences are nice-to-have; the UI should still work without storage.
    }
  }

  function savedWidth() {
    return clampWidth(getStoredValue(STORAGE_WIDTH) || SIDEBAR_MAX_WIDTH);
  }

  function savedCollapsed() {
    return getStoredValue(STORAGE_COLLAPSED) === 'true';
  }

  function setSidebarWidth(sidebar, width) {
    const nextWidth = clampWidth(width);
    sidebar.style.setProperty('--sidebar-width', `${nextWidth}px`);
    sidebar.dataset.width = String(nextWidth);
    sidebar.classList.toggle('is-resized', nextWidth < SIDEBAR_MAX_WIDTH && nextWidth > SIDEBAR_MIN_WIDTH);
    return nextWidth;
  }

  function setCollapsed(sidebar, collapsed, options = {}) {
    const nextCollapsed = Boolean(collapsed);
    sidebar.classList.toggle('is-collapsed', nextCollapsed);
    sidebar.classList.toggle('is-expanded-mobile', !nextCollapsed);

    const toggle = sidebar.querySelector('.sidebar-toggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', String(!nextCollapsed));
      toggle.setAttribute('aria-label', nextCollapsed ? 'הרחבת תפריט' : 'כיווץ תפריט');
      toggle.setAttribute('title', nextCollapsed ? 'הרחבת תפריט' : 'כיווץ תפריט');
    }

    if (nextCollapsed) {
      setSidebarWidth(sidebar, SIDEBAR_MIN_WIDTH);
    } else {
      const width = options.width || savedWidth() || SIDEBAR_MAX_WIDTH;
      setSidebarWidth(sidebar, width);
    }

    if (!options.silent) {
      setStoredValue(STORAGE_COLLAPSED, String(nextCollapsed));
    }
  }

  function widthFromPointer(sidebar, event) {
    const rect = sidebar.getBoundingClientRect();
    const direction = getComputedStyle(sidebar).direction;
    if (direction === 'rtl') {
      return rect.right - event.clientX;
    }

    return event.clientX - rect.left;
  }

  function bindSidebarControls(sidebar) {
    const toggle = sidebar.querySelector('.sidebar-toggle');
    const handle = sidebar.querySelector('.sidebar-resize-handle');

    setCollapsed(sidebar, savedCollapsed() || isSmallScreen(), {
      silent: true,
      width: savedWidth()
    });

    toggle?.addEventListener('click', () => {
      setCollapsed(sidebar, !sidebar.classList.contains('is-collapsed'));
    });

    handle?.addEventListener('pointerdown', event => {
      if (isSmallScreen()) return;

      event.preventDefault();
      handle.setPointerCapture?.(event.pointerId);
      sidebar.classList.add('is-resizing');
      document.body.classList.add('sidebar-resize-active');

      const move = moveEvent => {
        const width = setSidebarWidth(sidebar, widthFromPointer(sidebar, moveEvent));
        const collapsed = width <= SIDEBAR_MIN_WIDTH + 4;
        sidebar.classList.toggle('is-collapsed', collapsed);
        sidebar.classList.toggle('is-expanded-mobile', !collapsed);

        const toggleButton = sidebar.querySelector('.sidebar-toggle');
        if (toggleButton) {
          toggleButton.setAttribute('aria-expanded', String(!collapsed));
          toggleButton.setAttribute('aria-label', collapsed ? 'הרחבת תפריט' : 'כיווץ תפריט');
          toggleButton.setAttribute('title', collapsed ? 'הרחבת תפריט' : 'כיווץ תפריט');
        }
      };

      const stop = stopEvent => {
        handle.releasePointerCapture?.(stopEvent.pointerId);
        sidebar.classList.remove('is-resizing');
        document.body.classList.remove('sidebar-resize-active');

        const width = clampWidth(sidebar.dataset.width);
        const collapsed = width <= SIDEBAR_MIN_WIDTH + 4;
        if (collapsed) {
          setCollapsed(sidebar, true);
        } else {
          setStoredValue(STORAGE_COLLAPSED, 'false');
          setStoredValue(STORAGE_WIDTH, String(width));
        }

        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);
        window.removeEventListener('pointercancel', stop);
      };

      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', stop);
      window.addEventListener('pointercancel', stop);
    });

    window.addEventListener('resize', () => {
      if (isSmallScreen() && !sidebar.classList.contains('is-collapsed')) {
        setCollapsed(sidebar, true, { silent: true });
      }
    });
  }

  function renderSidebar() {
    const activePage = currentPage();
    const sidebar = document.querySelector('[data-sidebar]') || document.querySelector('.sidebar');
    if (sidebar) {
      sidebar.className = 'sidebar';
      sidebar.innerHTML = sidebarMarkup(activePage, currentUser);
      bindSidebarControls(sidebar);
    }

    let mobileNav = document.querySelector('[data-mobile-nav]') || document.querySelector('.mobile-nav');
    const wrapper = document.querySelector('.dashboard-wrapper');
    if (!mobileNav && wrapper) {
      mobileNav = document.createElement('nav');
      mobileNav.className = 'mobile-nav';
      mobileNav.dataset.mobileNav = '';
      wrapper.appendChild(mobileNav);
    }

    if (mobileNav) {
      mobileNav.className = 'mobile-nav';
      mobileNav.setAttribute('aria-label', 'ניווט תחתון');
      mobileNav.innerHTML = mobileMarkup(activePage, currentUser);
    }

    bindGlobalNavActions();
  }

  async function loadCurrentUser() {
    if (currentUserPromise) return currentUserPromise;

    currentUserPromise = fetch('./api/me.php', { cache: 'no-store' })
      .then(response => {
        if (response.status === 401 || response.status === 403) {
          redirectToLogin();
          return null;
        }

        return response.ok ? response.json() : null;
      })
      .then(payload => {
        currentUser = payload?.user || null;
        window.HiburimCurrentUser = currentUser;
        window.dispatchEvent(new CustomEvent('hiburim:user-ready', { detail: currentUser }));
        return currentUser;
      })
      .catch(() => {
        currentUser = null;
        return null;
      });

    return currentUserPromise;
  }

  function redirectToLogin() {
    const filename = currentFilename();
    if (filename === 'login.html') return;
    const next = encodeURIComponent(window.location.pathname.split('/').pop() + window.location.search + window.location.hash);
    window.location.href = `login.html?next=${next}`;
  }

  async function logout() {
    try {
      await fetch('./api/logout.php', { method: 'POST', cache: 'no-store' });
    } catch (error) {
      // Redirect even if the network request fails; the server will still protect APIs.
    }

    try {
      sessionStorage.removeItem('mp_activeConversation');
    } catch (error) {
      // Optional local cleanup.
    }

    window.location.href = 'login.html';
  }

  function bindGlobalNavActions() {
    document.querySelectorAll('[data-action="logout"]').forEach(link => {
      if (link.dataset.boundAction === 'true') return;
      link.dataset.boundAction = 'true';
      link.addEventListener('click', event => {
        event.preventDefault();
        logout();
      });
    });
  }

  function ensureSupportChatbotAssets() {
    if (supportAssetsLoaded) return;
    supportAssetsLoaded = true;

    if (!document.querySelector('link[data-support-chatbot-css]')) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'assets/css/support-chatbot.css';
      link.dataset.supportChatbotCss = '';
      document.head.appendChild(link);
    }

    if (!document.querySelector('script[data-support-chatbot-js]')) {
      const script = document.createElement('script');
      script.src = 'assets/js/support-chatbot.js';
      script.defer = true;
      script.dataset.supportChatbotJs = '';
      document.body.appendChild(script);
    }
  }

  window.HiburimSidebar = {
    render: renderSidebar,
    updateActive,
    getCurrentUser: loadCurrentUser
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      renderSidebar();
      loadCurrentUser().then(user => {
        redirectIfPageForbidden(user);
        renderSidebar();
        if (user) ensureSupportChatbotAssets();
      });
    });
  } else {
    renderSidebar();
    loadCurrentUser().then(user => {
      redirectIfPageForbidden(user);
      renderSidebar();
      if (user) ensureSupportChatbotAssets();
    });
  }

  window.addEventListener('hashchange', updateActive);
})();
