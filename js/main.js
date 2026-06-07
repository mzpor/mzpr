(function () {
  'use strict';

  const header = document.getElementById('header');
  const nav = document.getElementById('nav');
  const menuToggle = document.getElementById('menuToggle');
  const navLinks = document.querySelectorAll('.nav__link');
  const contactForm = document.getElementById('contactForm');

  // اسکرول هدر
  window.addEventListener('scroll', () => {
    header.classList.toggle('scrolled', window.scrollY > 50);
  });

  // منوی موبایل
  menuToggle.addEventListener('click', () => {
    nav.classList.toggle('open');
    menuToggle.classList.toggle('active');
  });

  navLinks.forEach(link => {
    link.addEventListener('click', () => {
      nav.classList.remove('open');
      menuToggle.classList.remove('active');
    });
  });

  // لینک ورود در منو active نشود
  const loginNavLink = document.querySelector('.nav__link--login');

  // هایلایت لینک فعال
  const sections = document.querySelectorAll('section[id]');

  function highlightNav() {
    const scrollY = window.scrollY + 100;

    sections.forEach(section => {
      const top = section.offsetTop;
      const height = section.offsetHeight;
      const id = section.getAttribute('id');

      if (scrollY >= top && scrollY < top + height) {
        navLinks.forEach(link => {
          if (link === loginNavLink) return;
          link.classList.remove('active');
          if (link.getAttribute('href') === `#${id}`) {
            link.classList.add('active');
          }
        });
      }
    });
  }

  window.addEventListener('scroll', highlightNav);

  // انیمیشن fade-in
  const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -40px 0px'
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, observerOptions);

  document.querySelectorAll(
    '.category-card, .product-card, .craft-step, .feature, .gallery__item, .about__content, .about__images'
  ).forEach(el => {
    el.classList.add('fade-in');
    observer.observe(el);
  });

  // فرم تماس
  contactForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = contactForm.querySelector('button[type="submit"]');
    const originalText = btn.textContent;
    btn.textContent = 'درخواست ارسال شد ✓';
    btn.style.background = '#5c3d2e';
    btn.disabled = true;

    setTimeout(() => {
      btn.textContent = originalText;
      btn.disabled = false;
      contactForm.reset();
    }, 3000);
  });

  // دکمه علاقه‌مندی
  document.querySelectorAll('.product-card__btn').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.classList.toggle('liked');
      const svg = btn.querySelector('svg');
      if (btn.classList.contains('liked')) {
        btn.style.background = '#5c3d2e';
        btn.style.color = '#fff';
        btn.style.borderColor = '#5c3d2e';
        svg.setAttribute('fill', 'currentColor');
      } else {
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
        svg.setAttribute('fill', 'none');
      }
    });
  });
})();
