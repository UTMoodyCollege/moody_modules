/**
 * Keep the page in place while Layout Builder opens or rebuilds block dialogs.
 */
(function (Drupal) {
  let openerSelector;
  document.addEventListener('click', (event) => {
    const link = event.target.closest?.('#layout-builder a');
    if (!link) return;
    const block = link.closest('[data-layout-block-uuid]');
    openerSelector = block
      ? `[data-layout-block-uuid="${CSS.escape(block.dataset.layoutBlockUuid)}"] .contextual .trigger`
      : `#layout-builder a[href="${CSS.escape(link.getAttribute('href'))}"]`;
  }, true);

  const success = Drupal.Ajax.prototype.success;
  Drupal.Ajax.prototype.success = function (response, status) {
    if (!document.getElementById('layout-builder') || !Array.isArray(response)) {
      return success.call(this, response, status);
    }
    const rebuild = response.some(command => command.command === 'insert' && command.selector === '#layout-builder');
    const opensDialog = response.some(command => ['openIframe', 'openDialog'].includes(command.command))
      && /\/layout_builder\//.test(this.url);
    if (!rebuild && !opensDialog) return success.call(this, response, status);

    // Capture before dialog focus or replacement removes the triggering block.
    const position = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
    // The iframe module restores a position captured after focus, 200ms later.
    // Restore once after the complete command queue and Drupal's refocus instead.
    const commands = rebuild ? response.filter(command => command.command !== 'scrollToBlock') : response;
    return Promise.resolve(success.call(this, commands, status)).then(() => new Promise(resolve => {
      requestAnimationFrame(() => {
        if (rebuild && !document.querySelector('.ui-dialog-content:has(iframe.lbim-dialog-iframe)')) {
          document.querySelector(openerSelector || '#layout-builder')?.focus({ preventScroll: true });
        }
        window.scrollTo(position);
        resolve();
      });
    }));
  };
})(Drupal);
