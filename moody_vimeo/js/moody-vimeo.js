/**
 * @file
 * Moody Vimeo admin UI copy helpers and browser uploads.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  var CHUNK_SIZE = 8 * 1024 * 1024;

  Drupal.behaviors.moodyVimeo = {
    attach: function (context) {
      once('moody-vimeo-copy', '.moody-vimeo-copy-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (event) {
          if (btn.tagName === 'A') {
            event.preventDefault();
          }

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
          }
          else {
            Drupal.moodyVimeo.fallbackCopy(text, btn);
          }
        });
      });

      once('moody-vimeo-upload-form', 'form#moody-vimeo-video-upload', context).forEach(function (form) {
        Drupal.moodyVimeo.attachUploadForm(form);
      });
    }
  };

  Drupal.moodyVimeo = Drupal.moodyVimeo || {};

  Drupal.moodyVimeo.attachUploadForm = function (form) {
    var settings = drupalSettings.moodyVimeo && drupalSettings.moodyVimeo.browserUpload;
    if (!settings) {
      return;
    }

    var submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
    var methodInputs = form.querySelectorAll('input[name="upload_method"]');
    var fileInput = form.querySelector('input[name="files[video_file]"]') || form.querySelector('#edit-video-file');
    var status = form.querySelector('.moody-vimeo-upload-status');
    var message = status ? status.querySelector('.moody-vimeo-upload-status__message') : null;
    var progress = status ? status.querySelector('.moody-vimeo-upload-status__progress') : null;
    var meta = status ? status.querySelector('.moody-vimeo-upload-status__meta') : null;

    if (!submitButton || !fileInput || !status || !message || !progress || !meta) {
      return;
    }

    form.addEventListener('submit', function (event) {
      if (Drupal.moodyVimeo.getSelectedMethod(methodInputs) !== 'file') {
        return;
      }

      event.preventDefault();
      if (form.dataset.moodyVimeoUploading === 'true') {
        return;
      }

      var file = fileInput.files && fileInput.files[0];
      if (!file) {
        Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('Select a video file to upload.'), 0, '', true);
        return;
      }

      if (!Drupal.moodyVimeo.isAllowedFile(file, settings.allowedExtensions || [])) {
        Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('That file type is not allowed.'), 0, '', true);
        return;
      }

      var payload = {
        name: (form.querySelector('input[name="name"]') || {}).value || '',
        description: (form.querySelector('textarea[name="description"]') || {}).value || '',
        privacy: (form.querySelector('select[name="privacy"]') || {}).value || 'nobody',
        fileName: file.name,
        fileSize: file.size,
        fileType: file.type || ''
      };

      if (!payload.name.trim()) {
        Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('Enter a video title before uploading.'), 0, '', true);
        return;
      }

      form.dataset.moodyVimeoUploading = 'true';
      submitButton.disabled = true;
      Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('Creating Vimeo upload session...'), 0, Drupal.t('Preparing direct browser upload.'), false);

      Drupal.moodyVimeo.createUploadSession(settings, payload)
        .then(function (session) {
          Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('Uploading to Vimeo...'), 0, Drupal.t('0% complete'), false);
          return Drupal.moodyVimeo.uploadFileInChunks(file, session.uploadLink, function (loaded, total) {
            var percent = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
            Drupal.moodyVimeo.setUploadStatus(
              status,
              message,
              meta,
              progress,
              Drupal.t('Uploading to Vimeo...'),
              percent,
              Drupal.formatString('@percent% complete (@loaded of @total)', {
                '@percent': percent,
                '@loaded': Drupal.moodyVimeo.formatBytes(loaded),
                '@total': Drupal.moodyVimeo.formatBytes(total)
              }),
              false
            );
          }).then(function () {
            return session;
          });
        })
        .then(function (session) {
          Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, Drupal.t('Upload complete. Vimeo is now processing your video.'), 100, Drupal.t('Redirecting to the video page...'), false);
          window.location.assign(session.detailUrl || session.listUrl || form.getAttribute('action') || window.location.href);
        })
        .catch(function (error) {
          Drupal.moodyVimeo.setUploadStatus(status, message, meta, progress, error && error.message ? error.message : Drupal.t('Upload failed.'), 0, Drupal.t('No file data was streamed through Drupal.'), true);
        })
        .finally(function () {
          form.dataset.moodyVimeoUploading = 'false';
          submitButton.disabled = false;
        });
    });
  };

  Drupal.moodyVimeo.getSelectedMethod = function (inputs) {
    var selected = 'url';
    inputs.forEach(function (input) {
      if (input.checked) {
        selected = input.value;
      }
    });
    return selected;
  };

  Drupal.moodyVimeo.isAllowedFile = function (file, extensions) {
    if (!extensions.length) {
      return true;
    }

    var parts = file.name.toLowerCase().split('.');
    var extension = parts.length > 1 ? parts.pop() : '';
    return extensions.indexOf(extension) !== -1;
  };

  Drupal.moodyVimeo.createUploadSession = function (settings, payload) {
    return fetch(settings.sessionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Moody-Vimeo-Token': settings.token
      },
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        if (!response.ok || !data.uploadLink) {
          throw new Error(data.message || Drupal.t('Could not create a Vimeo upload session.'));
        }
        return data;
      });
    });
  };

  Drupal.moodyVimeo.uploadFileInChunks = async function (file, uploadLink, onProgress) {
    var offset = await Drupal.moodyVimeo.getTusOffset(uploadLink);

    while (offset < file.size) {
      var nextOffset = Math.min(offset + CHUNK_SIZE, file.size);
      offset = await Drupal.moodyVimeo.uploadChunk(file, uploadLink, offset, nextOffset, onProgress);
    }
  };

  Drupal.moodyVimeo.getTusOffset = function (uploadLink) {
    return fetch(uploadLink, {
      method: 'HEAD',
      headers: {
        'Tus-Resumable': '1.0.0'
      }
    }).then(function (response) {
      if (!response.ok) {
        return 0;
      }

      var offset = parseInt(response.headers.get('Upload-Offset') || '0', 10);
      return Number.isFinite(offset) ? offset : 0;
    }).catch(function () {
      return 0;
    });
  };

  Drupal.moodyVimeo.uploadChunk = function (file, uploadLink, start, end, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('PATCH', uploadLink, true);
      xhr.setRequestHeader('Tus-Resumable', '1.0.0');
      xhr.setRequestHeader('Upload-Offset', String(start));
      xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');

      xhr.upload.onprogress = function (event) {
        if (event.lengthComputable && typeof onProgress === 'function') {
          onProgress(start + event.loaded, file.size);
        }
      };

      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          var returnedOffset = parseInt(xhr.getResponseHeader('Upload-Offset') || String(end), 10);
          resolve(Number.isFinite(returnedOffset) ? returnedOffset : end);
          return;
        }

        Drupal.moodyVimeo.getTusOffset(uploadLink).then(function (offset) {
          if (offset > start) {
            resolve(offset);
            return;
          }
          reject(new Error(Drupal.t('Vimeo rejected a file chunk at @offset bytes.', {'@offset': start})));
        });
      };

      xhr.onerror = function () {
        Drupal.moodyVimeo.getTusOffset(uploadLink).then(function (offset) {
          if (offset > start) {
            resolve(offset);
            return;
          }
          reject(new Error(Drupal.t('The browser lost connection while uploading to Vimeo.')));
        });
      };

      xhr.send(file.slice(start, end));
    });
  };

  Drupal.moodyVimeo.setUploadStatus = function (status, message, meta, progress, text, percent, detail, isError) {
    status.hidden = false;
    status.classList.toggle('is-error', !!isError);
    message.textContent = text;
    meta.textContent = detail || '';
    progress.value = percent || 0;
  };

  Drupal.moodyVimeo.formatBytes = function (bytes) {
    if (!bytes) {
      return '0 B';
    }

    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    var value = bytes / Math.pow(1024, index);
    return value.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
  };

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
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      document.execCommand('copy');
      Drupal.moodyVimeo.flashButton(btn);
    }
    catch (e) {
      console.warn('Moody Vimeo: clipboard copy failed', e);
    }
    document.body.removeChild(ta);
  };

}(Drupal, drupalSettings, once));
