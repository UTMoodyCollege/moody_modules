(function (Drupal, once) {
  const DESKTOP_BREAKPOINT = 992;

  function getGsap() {
    return window.gsap || null;
  }

  function getScrollTrigger() {
    return window.ScrollTrigger || null;
  }

  function buildPinnedShowcase(showcase) {
    const gsap = getGsap();
    const ScrollTrigger = getScrollTrigger();

    if (!gsap || !ScrollTrigger || window.innerWidth < DESKTOP_BREAKPOINT) {
      return;
    }

    const mediaPin = showcase.querySelector('.showcase-media-pin');
    const text = showcase.querySelector('.showcase-text');

    if (!mediaPin || !text) {
      return;
    }

    gsap.registerPlugin(ScrollTrigger);

    ScrollTrigger.create({
      trigger: showcase,
      pin: mediaPin,
      start: 'top top',
      end: () => {
        const distance = text.offsetHeight - mediaPin.offsetHeight;
        return distance > 0 ? '+=' + distance : 'bottom bottom';
      },
      pinSpacing: false,
      invalidateOnRefresh: true,
      scrub: false,
    });
  }

  Drupal.behaviors.moodyShowcaseFixedImageScroll = {
    attach(context) {
      once('moody-showcase-fixed-image-scroll', '.moody-showcase--fixed-image-scroll', context)
        .forEach(buildPinnedShowcase);
    }
  };
})(Drupal, once);
