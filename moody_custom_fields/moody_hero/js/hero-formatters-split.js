(function ($, Drupal, drupalSettings) {
  "use strict";

  /**
   * @file
   *
   * Hero formatter split progressive enhancement definition.
   *
   * To facilitate the usage of more than 10 image styles/view modes for the
   * hero, we create 2 different HTML select elements: one for the style,
   * and one for anchor positioning. The combination of both decide
   * which of the original image styles gets picked. This choice then
   * modifies the now hidden original select element. Drupal then saves
   * things as it normally would. This is purely a user facing change,
   * not a data storage change.
   *
   * A visual preview carousel is also rendered above the custom selects so
   * editors can see a thumbnail for each hero style. Preview images are loaded
   * from {module}/preview-images/{style_value}.jpg and fall back gracefully to
   * a labelled placeholder when no image file exists yet.
   */

  /**
   * Ordered list of base hero styles shown in the preview carousel.
   * Each entry maps to an option in the hero_style custom select and to a
   * preview image at {modulePath}/preview-images/{value}.jpg.
   */
  var CAROUSEL_STYLES = [
    { value: 'default',          label: 'Default' },
    { value: 'moody_hero_1',     label: 'Style 1' },
    { value: 'moody_hero_2',     label: 'Style 2' },
    { value: 'moody_hero_3',     label: 'Style 3' },
    { value: 'moody_hero_4',     label: 'Style 4' },
    { value: 'moody_hero_5',     label: 'Style 5' },
    { value: 'moody_hero_6',     label: 'Style 6' },
    { value: 'moody_hero_6_short', label: 'Style 6 Short' },
    { value: 'moody_hero_7',     label: 'Style 7' },
    { value: 'moody_hero_8',     label: 'Style 8' },
  ];

  /** Valid anchor suffixes (excludes style-name suffixes such as "_short"). */
  var ANCHOR_VALUES = ['left', 'right'];

  /** Number of carousel cards visible at one time. */
  var CARDS_VISIBLE = 3;

  /** Current scroll offset (index of first visible card). */
  var carouselOffset = 0;

  /**
   * Define a Drupal behavior to create custom hero select elements.
   */
  Drupal.behaviors.moodyHeroCustomSelectors = {
    attach: function (context, settings) {
      // 1. Hero styles may be accessed via field formatter or view mode.
      // Determine which of these is active, or exit if neither.
      var form_mode = getFormMode();
      if (form_mode === null) {
        return;
      }
      // Determine original select HTML element, depending on the form mode.
      var form_mode_dom =
        (form_mode === "formatter" ? ".js-form-item-settings-formatter-type"
          : ".js-form-item-settings-view-mode");
      // If there is no hero default selector, we are not on a hero
      // configuration form.
      $(form_mode_dom).each(function () {
        if ($(form_mode_dom)
          .find("select option[value=moody_hero_1_left]").length === null) {
          return;
        }
      });
      // Set the formatter type which differ between formatter and view mode.
      var formatter_type =
        (form_mode === "view_mode" ? "settings[" + form_mode + "]"
          : "settings[" + form_mode + "][type]");
      // Set jquery element for original hidden select element.
      var original_select_element = $("select[name='" + formatter_type + "']");
      var current_formatter_style = original_select_element.val();
      // Since formatter and view mode don't have a consistent value for the
      // default select element, we attempt to set the value to "default" if the
      // current style is indeed set to any of the default values.
      var default_style = ((current_formatter_style === undefined
        || current_formatter_style === "full"
        || current_formatter_style === "moody_hero") ? "default"
        : current_formatter_style);
      // Access helper function to split the current formatter into custom
      // style and anchor position.
      var style_and_anchor = getStyleAndAnchorValue(default_style);

      // 2. Create custom select HTML elements and preview carousel if not
      // already present.
      createSelectors(form_mode_dom);

      // 3. Set values of custom select HTML elements.
      // Update the value in the style custom select element.
      $("select[name='hero_style']")
        .val(default_style !== "default" ? style_and_anchor.style
          : default_style);
      // Update the value in the anchor custom select element.
      if (default_style !== "default") {
        $("select[name='anchor_position']").val(style_and_anchor.anchor);
      }
      // Convert default_style if "default" is set but we use a formatter form
      // mode.
      default_style = (form_mode === "formatter"
        && default_style === "default" ? "moody_hero"
        : default_style);
      // Update the values in the original hidden select element.
      original_select_element.val(default_style);
      // Toggle anchor select element if current hero don't use anchor.
      toggleAnchorSelectElement(default_style);

      // Sync the carousel to highlight the active card and scroll it into view.
      var carousel_style = (default_style === "moody_hero" ? "default"
        : default_style);
      syncCarouselActiveCard(carousel_style);
      scrollCarouselToActive(carousel_style);
      updateCarouselNavButtons();

      // 4. Watch for changes on the custom select elements, and keep
      // the original select element in sync.

      // Watch the hero style custom select element.
      $("select[name='hero_style']", context).change(function () {
        var hero_style = "";
        $("select[name='hero_style'] option:selected").each(function () {
          // Get the hero style and convert to moody_hero if form mode is set
          // to formatter.
          hero_style = (form_mode === "formatter"
            && $("select[name='hero_style'] option:selected").val() === "default"
            ? "moody_hero"
            : $("select[name='hero_style'] option:selected").val());
        });
        updateSelectors(original_select_element, "", hero_style);
        // Keep carousel in sync with the text select.
        var carousel_val = (hero_style === "moody_hero" ? "default" : hero_style);
        syncCarouselActiveCard(carousel_val);
        scrollCarouselToActive(carousel_val);
        updateCarouselNavButtons();
      });

      // Watch the hero anchor custom select element.
      $("select[name='anchor_position']", context).change(function () {
        var anchor;
        $("select[name='anchor_position'] option:selected").each(function () {
          anchor = "_" + $("select[name='anchor_position'] option:selected")
            .val();
        });
        updateSelectors(original_select_element, anchor, "");
      });
    }
  };



  /**
   * Create the custom select HTML elements and preview carousel, and append
   * them to the form. The carousel is inserted first so it appears at the
   * top of the hero settings area.
   *
   * @param {string} form_mode_dom The parent element where we create and set
   *     the new selectors into.
   */
  function createSelectors(form_mode_dom) {
    var hero_style_selector =
      `<div class="js-form-item form-item js-form-type-select
      form-item-hero-style js-form-item-hero-style">
        <label for="edit-hero-style">Select Hero Style</label>
        <select data-drupal-selector="edit-hero-style"
        aria-describedby="edit-hero-style-description" id="edit-hero-style"
        name="hero_style" class="form-select">
          <option value="default">
            Default: Large media with optional caption and credit line
          </option>
          <option value="moody_hero_1">
            Style 1: Bold heading &amp; subheading on burnt orange background
          </option>
          <option value="moody_hero_2">
            Style 2: Bold heading on dark background, anchored at base of media
          </option>
          <option value="moody_hero_3">
            Style 3: White bottom pane with heading, subheading and burnt orange
            call to action
          </option>
          <option value="moody_hero_4">
            Style 4: Centered image with dark bottom pane containing heading,
            subheading and call-to-action
          </option>
          <option value="moody_hero_5">
            Style 5: Medium image, floated right, with large heading,
            subheading and burnt orange call-to-action
          </option>
          <option value="moody_hero_6">
            Style 6: Taller image with extra bold headline, subheadline, call-to-action, text color and overlay options.
          </option>
          <option value="moody_hero_6_short">
            Style 6 Short: Short image and extra bold headline
          </option>
          <option value="moody_hero_7">
            Style 7: Taller image with extra bold headline and configurable text color, overlay and text position
          </option>
          <option value="moody_hero_8">
            Style 8: Shorter image with extra bold headline and configurable text color, overlay and text position
          </option>
        </select>
      </div>`
      ;
    var anchor_position_selector =
      `<div class="js-form-item form-item js-form-type-select
      form-item-anchor-position js-form-item-anchor-position">
        <label for="edit-anchor-position">Image anchor position</label>
        <select data-drupal-selector="edit-anchor-position"
        aria-describedby="edit-anchor-position-description"
        id="edit-anchor-position" name="anchor_position" class="form-select">
          <option value="center">Center</option>
          <option value="left">Left</option>
          <option value="right">Right</option>
        </select>
        <div id="edit-anchor-position-description" class="description">
          Set what part of the image should be the focal anchor.
        </div>
      </div>`
      ;
    // We loop through each element within the parent DOM.
    $(form_mode_dom).each(function () {
      // Check if hero style default selector is present.
      if ($(form_mode_dom)
        .find("select option[value=moody_hero_1_left]").length) {
        // Validate custom selectors exist and create them if they don't.
        if ($("#edit-hero-style").length === 0) {
          var carousel_html = buildCarouselHtml();
          $(form_mode_dom)
            .after(anchor_position_selector)
            .after(hero_style_selector)
            .after(carousel_html);
          // Hide the original selector after appending the custom ones.
          $(form_mode_dom).hide();
          // Bind carousel navigation and card-click events.
          bindCarouselEvents();
        }
      }
    });
  }

  /**
   * Build the HTML string for the preview carousel.
   *
   * @return {string} The carousel HTML.
   */
  function buildCarouselHtml() {
    var module_path = (drupalSettings.moodyHero && drupalSettings.moodyHero.modulePath)
      ? drupalSettings.moodyHero.modulePath
      : '';

    var cards_html = '';
    for (var i = 0; i < CAROUSEL_STYLES.length; i++) {
      var style = CAROUSEL_STYLES[i];
      var img_src = module_path + '/preview-images/' + style.value + '.jpg';
      cards_html +=
        '<div class="moody-hero-carousel-card" data-style="' + style.value + '">' +
          '<div class="card-image">' +
            '<img src="' + img_src + '" alt="' + style.label + ' preview"' +
              ' onload="this.nextElementSibling.style.display=\'none\'"' +
              ' onerror="this.style.display=\'none\'">' +
            '<div class="card-placeholder">(preview image)</div>' +
          '</div>' +
          '<div class="card-label">' + style.label + '</div>' +
        '</div>';
    }

    return '<div class="moody-hero-preview-carousel">' +
        '<button type="button" class="moody-hero-carousel-nav moody-hero-carousel-prev"' +
          ' aria-label="Previous styles">&#8592;</button>' +
        '<div class="moody-hero-carousel-track-wrapper">' +
          '<div class="moody-hero-carousel-track">' + cards_html + '</div>' +
        '</div>' +
        '<button type="button" class="moody-hero-carousel-nav moody-hero-carousel-next"' +
          ' aria-label="Next styles">&#8594;</button>' +
      '</div>';
  }

  /**
   * Bind click events to the carousel navigation buttons and cards.
   * Called once immediately after the carousel is inserted into the DOM.
   */
  function bindCarouselEvents() {
    // Card click — select that hero style.
    $(document).on('click', '.moody-hero-carousel-card', function () {
      var style_value = $(this).data('style');
      $("select[name='hero_style']").val(style_value).trigger('change');
    });

    // Previous button.
    $(document).on('click', '.moody-hero-carousel-prev', function () {
      if (carouselOffset > 0) {
        carouselOffset--;
        updateCarouselTransform();
        updateCarouselNavButtons();
      }
    });

    // Next button.
    $(document).on('click', '.moody-hero-carousel-next', function () {
      var max_offset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
      if (carouselOffset < max_offset) {
        carouselOffset++;
        updateCarouselTransform();
        updateCarouselNavButtons();
      }
    });
  }

  /**
   * Highlight the carousel card that corresponds to the given style value.
   *
   * @param {string} style_value The base hero style value (no anchor suffix).
   */
  function syncCarouselActiveCard(style_value) {
    $('.moody-hero-carousel-card').removeClass('is-active');
    $('.moody-hero-carousel-card[data-style="' + style_value + '"]')
      .addClass('is-active');
  }

  /**
   * Shift the carousel track so the active card is visible.
   *
   * @param {string} style_value The base hero style value.
   */
  function scrollCarouselToActive(style_value) {
    var active_index = findStyleIndex(style_value);
    var max_offset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
    // Try to centre the active card in the visible window.
    var desired = active_index - Math.floor(CARDS_VISIBLE / 2);
    carouselOffset = Math.max(0, Math.min(desired, max_offset));
    updateCarouselTransform();
  }

  /**
   * Apply CSS transform to slide the carousel track to the current offset.
   */
  function updateCarouselTransform() {
    var offset_pct = -(carouselOffset * (100 / CARDS_VISIBLE));
    $('.moody-hero-carousel-track')
      .css('transform', 'translateX(' + offset_pct + '%)');
  }

  /**
   * Enable or disable the carousel navigation buttons based on the current
   * offset and the total number of cards.
   */
  function updateCarouselNavButtons() {
    var max_offset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
    $('.moody-hero-carousel-prev').prop('disabled', carouselOffset <= 0);
    $('.moody-hero-carousel-next').prop('disabled', carouselOffset >= max_offset);
  }

  /**
   * Return the index of a style value in CAROUSEL_STYLES, or 0 if not found.
   *
   * @param {string} style_value
   * @return {number}
   */
  function findStyleIndex(style_value) {
    for (var i = 0; i < CAROUSEL_STYLES.length; i++) {
      if (CAROUSEL_STYLES[i].value === style_value) {
        return i;
      }
    }
    return 0;
  }

  /**
   * Update the values and state of the select elements.
   *
   * @param {string} original_select_element This variable defines the HTML
   *    original hidden select element.
   * @param {string} anchor (Optional) The anchor value which could be "center",
   *    "left" or "right". Also used to validate the selector visibility.
   * @param {string} hero_style (Optional) The hero style that will be massaged
   *    and define the official formatter/view mode value. Will also be used to
   *    validate and massage the hero_style value.
   */
  function updateSelectors(original_select_element, anchor = "", hero_style = "") {
    // If no hero style passed as argument, get the current value.
    hero_style = ((hero_style === "")
      ? $("select[name='hero_style'] option:selected").val()
      : hero_style);
    // If no anchor passed as argument, get the current value
    anchor = ((anchor === "")
      ? "_" + $("select[name='anchor_position'] option:selected").val()
      : anchor);
    // Massage the custom anchor position value.
    toggleAnchorSelectElement(hero_style);
    var disabled_anchor = ($("#edit-anchor-position").prop("disabled") ? true
      : false);
    // Don't add suffix if anchor is center or anchor select is disabled.
    anchor = ((anchor === "_center" || disabled_anchor) ? ""
      : anchor);
    // Update original formatter select element value.
    original_select_element.val(hero_style + anchor);
  }

  /**
   * Return either formatter or view_mode depending on context the
   * moody_hero field is being used.
   * This will vary between node, inline block, reusable block, etc.
   * @return {string} Returns the form_mode if valid, or null if not
   */
  function getFormMode() {
    var form_mode = null;
    // Check if the layout sidebar has a formatter or view mode, if not, exit.
    if ($("#drupal-off-canvas")
      .has(".js-form-item-settings-formatter-type").length
      || $("#layout-builder-modal")
        .has(".js-form-item-settings-formatter-type").length) {
      form_mode = "formatter";
    }
    else if ($("#drupal-off-canvas")
      .has(".js-form-item-settings-view-mode").length
      || $("#layout-builder-modal")
        .has(".js-form-item-settings-view-mode").length) {
      form_mode = "view_mode";
    }
    else if ($(".block-form").has(".js-form-item-settings-view-mode").length
      || $("#layout-builder-modal")
        .has(".js-form-item-settings-view-mode").length) {
      form_mode = "view_mode";
    }
    return form_mode;
  }

  /**
   * Return the current base style and anchor values from the default Drupal
   * select element value.
   *
   * Anchor suffixes are strictly "_left" or "_right". Any other trailing
   * segment (e.g. "_short" in "moody_hero_6_short") is treated as part of
   * the style name, not as an anchor.
   *
   * @param {string} default_style Should contain a value similar to
   *    "moody_hero_X[_anchor]" where X identifies the style and the optional
   *    anchor is "left" or "right".
   * @return {object} An object with "style" and "anchor" properties.
   */
  function getStyleAndAnchorValue(default_style) {
    // Split and return an array.
    var default_style_split = default_style.split("moody_hero_");
    // Will return the style suffix after "moody_hero_", e.g. "1_left",
    // "6_short", "7", or fall back to "moody_hero" when not split.
    var style_suffix_parts = (default_style_split[1] !== undefined
      ? default_style_split[1].split("_")
      : ["moody_hero"]);
    // Only treat the last segment as an anchor if it is a known anchor value.
    var last_segment = style_suffix_parts[style_suffix_parts.length - 1];
    var anchorDefined = (style_suffix_parts.length > 1)
      && (ANCHOR_VALUES.indexOf(last_segment) !== -1);
    // Re-assemble the base style name (without anchor suffix when present).
    var base_suffix = anchorDefined
      ? style_suffix_parts.slice(0, -1).join("_")
      : default_style_split[1];
    return {
      "style": "moody_hero_" + base_suffix,
      "anchor": anchorDefined ? last_segment : "center",
    };
  }

  /**
   * Toggle anchor select element state depending on selected hero style.
   * Anchor positioning is irrelevant for Default, Style 4, and Style 6 Short.
   *
   * @param {string} hero_style The current hero style.
   */
  function toggleAnchorSelectElement(hero_style) {
    if (hero_style === "default" || hero_style === "moody_hero"
      || hero_style === "moody_hero_4"
      || hero_style === "moody_hero_6_short") {
      $("#edit-anchor-position").prop("disabled", true);
    } else {
      $("#edit-anchor-position").removeAttr("disabled");
    }
  }

})(jQuery, Drupal, drupalSettings);
