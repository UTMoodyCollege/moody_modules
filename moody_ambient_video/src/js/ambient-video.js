/**
 * @file
 * Provides constrained ambient video behavior.
 */
(function (Drupal, once) {

  Drupal.behaviors.UtLaAmbientVideoBehavior = {
    attach: function (context) {
      once('moody-ambient-video-constrained', '.moody-ambient-video.constrained', context).forEach(function (block) {
        var overflowContainer = block.querySelector('#hidden-overflow-container');
        var videoWrapper = block.querySelector('#video-wrapper');
        var video = block.querySelector('#moody-video');
        var fallbackImage = block.querySelector('#fallback-image');
        var playButton = block.querySelector('#play-pause');

        if (!overflowContainer || !videoWrapper || !video || !fallbackImage || !playButton) {
          return;
        }

        var breakpoint = 900;
        var isShortMode = block.classList.contains('moody-ambient-video-short');
        var isVimeo = video.tagName === 'IFRAME';
        var vimeoPaused = false;
        var currentWidth;
        var currentHeight;
        var resizeTimer;

        var getHeaderHeight = function () {
          var brandbar = document.getElementById('brandbar');
          var header = document.querySelector('header');
          return (brandbar ? brandbar.clientHeight : 0) + (header ? header.clientHeight : 0);
        };

        var setWrapperSize = function () {
          currentHeight = window.innerHeight;
          currentWidth = document.documentElement.clientWidth;
          var availableHeight = currentHeight - getHeaderHeight();

          if (isShortMode) {
            var shortNaturalHeight = Math.round((currentWidth * 9) / 16);
            overflowContainer.style.height = Math.min(availableHeight, shortNaturalHeight, 500) + 'px';
          }
          else {
            overflowContainer.style.height = availableHeight + 'px';
          }

          overflowContainer.style.width = currentWidth + 'px';
          overflowContainer.style.overflow = 'hidden';
        };

        var setVideoSize = function () {
          var containerHeight = overflowContainer.getBoundingClientRect().height || (currentHeight - getHeaderHeight());
          var videoHeight;
          var videoWidth;

          if ((currentWidth / containerHeight) > 1.7777777778) {
            videoWidth = currentWidth;
            videoHeight = (currentWidth * 9) / 16;
            videoWrapper.style.width = videoWidth + 'px';
            videoWrapper.style.height = videoHeight + 'px';
            videoWrapper.style.marginLeft = 0;
            videoWrapper.style.marginTop = '-' + ((videoHeight - containerHeight) / 2) + 'px';
          }
          else {
            videoHeight = containerHeight;
            videoWidth = (videoHeight * 16) / 9;
            videoWrapper.style.width = videoWidth + 'px';
            videoWrapper.style.height = videoHeight + 'px';
            videoWrapper.style.marginTop = 0;
            videoWrapper.style.marginLeft = '-' + ((videoWidth - currentWidth) / 2) + 'px';
          }
        };

        var addVideoSource = function () {
          var videoUrl = video.getAttribute('data-src');
          if (!videoUrl) {
            return;
          }

          if (isVimeo && !video.getAttribute('src')) {
            video.src = videoUrl;
          }
          else if (!isVimeo && video.querySelector('source') === null) {
            var source = document.createElement('source');
            source.src = videoUrl;
            source.type = 'video/mp4';
            video.appendChild(source);
          }
        };

        var addFallbackImage = function () {
          var fallbackUrl = fallbackImage.getAttribute('data-src');
          if (!fallbackImage.getAttribute('src') && fallbackUrl) {
            fallbackImage.src = fallbackUrl;
          }
        };

        var showTextfields = function () {
          var elements = [
            block.querySelector('.homepage-hero__video .headline'),
            block.querySelector('#video-controls'),
            block.querySelector('#scroll-hint')
          ];
          elements.forEach(function (element) {
            if (element) {
              element.style.opacity = 1;
            }
          });
        };

        playButton.addEventListener('click', function () {
          var paused = isVimeo ? vimeoPaused : video.paused;
          if (paused) {
            if (isVimeo) {
              video.contentWindow.postMessage(JSON.stringify({ method: 'play' }), '*');
              vimeoPaused = false;
            }
            else {
              video.play();
            }
            playButton.setAttribute('aria-label', 'Pause video');
            playButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><path d="M25,50A25.007,25.007,0,0,1,15.269,1.965,25.006,25.006,0,0,1,34.731,48.035,24.844,24.844,0,0,1,25,50Zm3.907-37.5a.71.71,0,0,0-.781.6V36.9a.71.71,0,0,0,.781.6h4.688a.71.71,0,0,0,.781-.6V13.1a.71.71,0,0,0-.781-.6Zm-12.5,0a.71.71,0,0,0-.781.6V36.9a.71.71,0,0,0,.781.6h4.688a.71.71,0,0,0,.781-.6V13.1a.71.71,0,0,0-.781-.6Z" /></svg>';
          }
          else {
            if (isVimeo) {
              video.contentWindow.postMessage(JSON.stringify({ method: 'pause' }), '*');
              vimeoPaused = true;
            }
            else {
              video.pause();
            }
            playButton.setAttribute('aria-label', 'Play video');
            playButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><path d="M25,50A25.007,25.007,0,0,1,15.268,1.965,25.006,25.006,0,0,1,34.731,48.035,24.844,24.844,0,0,1,25,50ZM21.363,12.5c-.166,0-.265.121-.265.323,0,.028,0,10.862,0,12.1s0,12.069,0,12.1a.382.382,0,0,0,.1.278.308.308,0,0,0,.22.088.291.291,0,0,0,.21-.084L33.011,25.239a.43.43,0,0,0,0-.566L21.627,12.611A.38.38,0,0,0,21.363,12.5Z" /></svg>';
          }
        });

        var update = function () {
          setWrapperSize();
          if (currentWidth > breakpoint) {
            addVideoSource();
            setVideoSize();
          }
          else {
            addFallbackImage();
          }
          showTextfields();
        };

        update();

        window.addEventListener('resize', function () {
          var resizedWidth = document.documentElement.clientWidth;
          if (currentWidth !== resizedWidth) {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(update, 500);
            currentWidth = resizedWidth;
          }
        });
      });
    }
  };

})(Drupal, once);
