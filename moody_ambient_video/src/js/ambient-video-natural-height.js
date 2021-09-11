/**
 * @file
 * Placeholder file for custom theme behaviors.
 *
 */
(function ($, Drupal, debounce) {

  /**
   * Use this behavior as a template for custom Javascript.
   */
  Drupal.behaviors.UtLaAmbientVideoNaturalHeightBehavior = {
    attach: function (context, settings) {

      // Set variables.
      var video = document.getElementById('moody-video');
      var fallbackImage = document.getElementById('fallback-image');
      var breakpoint = 900;
      var videoUrl = window.drupalSettings.ambientVideo.ambientVideoUrl;
      var fallbackUrl = window.drupalSettings.ambientVideo.ambientVideoFallback;
      var currentWidth;

      // Function to add the video_url to the video source tag.
      var addVideoSource = function () {
        if (video.querySelector('source') === null) {
          var source = document.createElement('source');
          source.src = videoUrl;
          source.type = 'video/mp4';
          video.appendChild(source);
        }
      }

      // Function to add the image URL to the img src of the fallback image
      var addFallbackImage = function () {
        if (!fallbackImage.src) {
          fallbackImage.src = fallbackUrl;
        }
      }

      // Function to show text fields hidden on load
      var showTextfields = function () {
        var heroText = document.querySelector('.homepage-hero__video .headline');
        var videoControls = document.getElementById('video-controls');
        var scrollHint = document.getElementById('scroll-hint');
        heroText.style.opacity = 1;
        videoControls.style.opacity = 1;
        scrollHint.style.opacity = 1;
      }

      // Wire up the play and pause buttons on the video.
      var video = document.getElementById('moody-video');
      var playButton = document.getElementById('play-pause');

      // Event listener for the play/pause button
      playButton.addEventListener('click', function () {
        if (video.paused == true) {
          video.play();
          playButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><path d="M25,50A25.007,25.007,0,0,1,15.269,1.965,25.006,25.006,0,0,1,34.731,48.035,24.844,24.844,0,0,1,25,50Zm3.907-37.5a.71.71,0,0,0-.781.6V36.9a.71.71,0,0,0,.781.6h4.688a.71.71,0,0,0,.781-.6V13.1a.71.71,0,0,0-.781-.6Zm-12.5,0a.71.71,0,0,0-.781.6V36.9a.71.71,0,0,0,.781.6h4.688a.71.71,0,0,0,.781-.6V13.1a.71.71,0,0,0-.781-.6Z" /></svg>';
        } else {
          video.pause();
          playButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><path d="M25,50A25.007,25.007,0,0,1,15.268,1.965,25.006,25.006,0,0,1,34.731,48.035,24.844,24.844,0,0,1,25,50ZM21.363,12.5c-.166,0-.265.121-.265.323,0,.028,0,10.862,0,12.1s0,12.069,0,12.1a.382.382,0,0,0,.1.278.308.308,0,0,0,.22.088.291.291,0,0,0,.21-.084L33.011,25.239a.43.43,0,0,0,0-.566L21.627,12.611A.38.38,0,0,0,21.363,12.5Z" /></svg>';
        }
      });

      window.addEventListener('load', function () {
        if (document.documentElement.clientWidth > breakpoint) {
          addVideoSource();
          showTextfields();
        }
        else {
          addFallbackImage();
          showTextfields();
        }
      });

      // Verify window width has changed for logic to run to prevent styling issues related to iOS
      // scroll triggering resize event. Update currentWidth to resized width if it has changed.
      window.addEventListener('resize', function () {
        if (currentWidth != document.documentElement.clientWidth) {
          setTimeout(function () {
            if (currentWidth > breakpoint) {
              addVideoSource();
            }
            else {
              addFallbackImage();
            }
          }, 500)
          currentWidth = document.documentElement.clientWidth;
        }
      });

      // Fade out and set scrollTo on scroll-hint.
      // Fade out scroll-hint if someone starts scrolling
      $(window).on('scroll', function () {
        $('#scroll-hint').fadeOut();
      });
      // Scroll to university-stories on scroll-hint click
      $('#scroll-hint').on('click touchstart', function (e) {
        e.preventDefault();
        var targetOffset = $("#scroll-to-here").offset().top;
        $('html, body').animate({ scrollTop: targetOffset }, 1000);
      });

    }
  };

})(jQuery, Drupal, Drupal.debounce);
