(function () {
  'use strict';

  const API_SESSION = 'api/auth-session.php';
  const API_LOGOUT = 'api/auth-logout.php';

  async function fetchSession() {
    const res = await fetch(API_SESSION, { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      return { logged_in: false, user: null };
    }
    return data.data || { logged_in: false, user: null };
  }

  async function logout() {
    const res = await fetch(API_LOGOUT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      throw new Error(data.message || 'خطا در خروج');
    }
  }

  function renderAuthNav(container, session) {
    if (!container) return;

    container.innerHTML = '';

    if (session.logged_in && session.user) {
      const name = document.createElement('span');
      name.className = 'nav__auth-name';
      name.textContent = session.user.name || session.user.phone || 'کاربر';

      const logoutLink = document.createElement('a');
      logoutLink.href = '#';
      logoutLink.className = 'nav__link nav__link--logout';
      logoutLink.textContent = 'خروج';
      logoutLink.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
          await logout();
          window.location.href = 'index.html';
        } catch (err) {
          alert(err.message || 'خطا در خروج');
        }
      });

      container.append(name, logoutLink);
      return;
    }

    const loginLink = document.createElement('a');
    loginLink.href = 'login.htm';
    loginLink.className = 'nav__link nav__link--login';
    loginLink.textContent = 'ورود';
    container.appendChild(loginLink);
  }

  async function initAuthNav(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return null;

    try {
      const session = await fetchSession();
      renderAuthNav(container, session);
      return session;
    } catch {
      renderAuthNav(container, { logged_in: false, user: null });
      return { logged_in: false, user: null };
    }
  }

  async function redirectIfLoggedIn(redirectTo) {
    try {
      const session = await fetchSession();
      if (session.logged_in) {
        window.location.replace(redirectTo || 'index.html');
        return true;
      }
    } catch {
      /* ignore */
    }
    return false;
  }

  window.MzprAuth = {
    fetchSession,
    logout,
    initAuthNav,
    redirectIfLoggedIn,
  };
})();
