(function (Drupal, once) {
  function scrollToTarget(targetId) {
    if (!targetId) {
      return;
    }

    const target = document.getElementById(targetId);
    if (!target) {
      return;
    }

    target.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
    });
  }

  function closeMenu(wrapper) {
    const panel = wrapper.querySelector('[data-moody-mini-nav-panel]');
    const overlay = wrapper.querySelector('[data-moody-mini-nav-overlay]');
    const toggle = wrapper.querySelector('[data-moody-mini-nav-toggle]');

    if (panel) {
      panel.hidden = true;
    }
    if (overlay) {
      overlay.hidden = true;
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
  }

  function openMenu(wrapper) {
    const panel = wrapper.querySelector('[data-moody-mini-nav-panel]');
    const overlay = wrapper.querySelector('[data-moody-mini-nav-overlay]');
    const toggle = wrapper.querySelector('[data-moody-mini-nav-toggle]');

    if (panel) {
      panel.hidden = false;
    }
    if (overlay) {
      overlay.hidden = false;
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
    }
  }

  Drupal.behaviors.moodyMiniNav = {
    attach(context) {
      once('moody-mini-nav', '[data-moody-mini-nav]', context).forEach((wrapper) => {
        const toggle = wrapper.querySelector('[data-moody-mini-nav-toggle]');
        const close = wrapper.querySelector('[data-moody-mini-nav-close]');
        const overlay = wrapper.querySelector('[data-moody-mini-nav-overlay]');
        const anchorLinks = wrapper.querySelectorAll('[data-moody-mini-nav-anchor]');

        if (toggle) {
          toggle.addEventListener('click', () => {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            if (isOpen) {
              closeMenu(wrapper);
            }
            else {
              openMenu(wrapper);
            }
          });
        }

        if (close) {
          close.addEventListener('click', () => closeMenu(wrapper));
        }

        if (overlay) {
          overlay.addEventListener('click', () => closeMenu(wrapper));
        }

        anchorLinks.forEach((link) => {
          link.addEventListener('click', (event) => {
            const targetId = link.getAttribute('data-moody-mini-nav-target-id');
            if (!targetId) {
              return;
            }

            event.preventDefault();
            closeMenu(wrapper);
            scrollToTarget(targetId);
          });
        });
      });
    },
  };
})(Drupal, once);
