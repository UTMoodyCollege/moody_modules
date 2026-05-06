/**
 * @file
 * Moody Vimeo admin UI — copy-to-clipboard helpers.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.moodyVimeo = {
    attach: function (context) {
      once('moody-vimeo-copy', '.moody-vimeo-copy-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var text = btn.dataset.copy || '';
          if (!text) {
            return;
          }

          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
              Drupal.moodyVimeo.flashButton(btn);
            }).catch(function () {
              Drupal.moodyVimeo.fallbackCopy(text, btn);
            });
          } else {
            Drupal.moodyVimeo.fallbackCopy(text, btn);
          }
        });
      });
    }
  };

  Drupal.moodyVimeo = Drupal.moodyVimeo || {};

  /**
   * Briefly changes the button label to indicate the copy succeeded.
   *
   * @param {HTMLElement} btn
   */
  Drupal.moodyVimeo.flashButton = function (btn) {
    var original = btn.textContent;
    btn.textContent = Drupal.t('Copied!');
    btn.classList.add('moody-vimeo-copy-btn--success');
    setTimeout(function () {
      btn.textContent = original;
      btn.classList.remove('moody-vimeo-copy-btn--success');
    }, 1800);
  };

  /**
   * Fallback copy using a temporary textarea (for browsers without Clipboard API).
   *
   * @param {string} text
   * @param {HTMLElement} btn
   */
  Drupal.moodyVimeo.fallbackCopy = function (text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity  = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      document.execCommand('copy');
      Drupal.moodyVimeo.flashButton(btn);
    } catch (e) {
      console.warn('Moody Vimeo: clipboard copy failed', e);
    }
    document.body.removeChild(ta);
  };

}(Drupal, once));
