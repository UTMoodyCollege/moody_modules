(function ($, Drupal, drupalSettings, once) {
  "use strict";

  var CAROUSEL_STYLES = [
    { value: "default", label: "Default" },
    { value: "flex_grid_circular_style", label: "Circular Style" },
    { value: "flex_grid_promo_style", label: "Promo Style" },
    { value: "flex_grid_rectangular_style", label: "Rectangular Style" },
    { value: "flex_grid_flip_style", label: "Flip Style" },
    { value: "flex_grid_card_style", label: "Card Style" }
  ];

  var CARDS_VISIBLE = 3;
  var carouselOffset = 0;
  var previewEventsBound = false;

  Drupal.behaviors.moodyFlexGridViewModePreview = {
    attach: function (context) {
      once("moody-flex-grid-view-mode-preview", ".js-form-item-settings-view-mode", context)
        .forEach(function (element) {
          var $sourceWrapper = $(element);
          var $select = $sourceWrapper.find("select[name='settings[view_mode]']");

          if (!$select.length || !$select.find("option[value='flex_grid_card_style']").length) {
            return;
          }

          createPreviewSelector($sourceWrapper);
          var $scope = $sourceWrapper.parent().find(".moody-flex-grid-form-enhancements").first();
          var $styleSelect = $scope.find("select[name='flex_grid_view_mode']");
          var currentValue = $select.val() || "default";

          $styleSelect.val(currentValue);
          $select.val(currentValue);
          syncCarouselActiveCard(currentValue, $scope);
          scrollCarouselToActive(currentValue, $scope);
          updateCarouselNavButtons($scope);

          $styleSelect.on("change", function () {
            var selected = $(this).val();
            $select.val(selected).trigger("change");
            syncCarouselActiveCard(selected, $scope);
            scrollCarouselToActive(selected, $scope);
            updateCarouselNavButtons($scope);
          });
        });
    }
  };

  function createPreviewSelector($sourceWrapper) {
    var $formContainer = $sourceWrapper.parent();
    if ($formContainer.find("#edit-flex-grid-view-mode").length !== 0) {
      return;
    }

    var selectHtml =
      `<div class="js-form-item form-item js-form-type-select form-item-flex-grid-view-mode js-form-item-flex-grid-view-mode">
        <label for="edit-flex-grid-view-mode">Select Flex Grid Style</label>
        <select data-drupal-selector="edit-flex-grid-view-mode" id="edit-flex-grid-view-mode" name="flex_grid_view_mode" class="form-select">
          <option value="default">Default: Standard grid layout</option>
          <option value="flex_grid_circular_style">Circular Style: Circular image-forward cards</option>
          <option value="flex_grid_promo_style">Promo Style: Promotional tiles with stronger media presence</option>
          <option value="flex_grid_rectangular_style">Rectangular Style: Rectangular media cards</option>
          <option value="flex_grid_flip_style">Flip Style: Interactive flip-card treatment</option>
          <option value="flex_grid_card_style">Card Style: Card-based content blocks</option>
        </select>
      </div>`;

    var enhancementMarkup = $(
      '<div class="moody-flex-grid-form-enhancements">' +
        buildCarouselHtml() +
        selectHtml +
      '</div>'
    );

    var $labelDisplayWrapper = $formContainer.find(".js-form-item-settings-label-display").first();
    if ($labelDisplayWrapper.length) {
      $labelDisplayWrapper.after(enhancementMarkup);
    }
    else {
      $sourceWrapper.before(enhancementMarkup);
    }

    $sourceWrapper.hide();
    bindPreviewEvents();
  }

  function buildCarouselHtml() {
    var modulePath = (drupalSettings.moodyFlexGrid && drupalSettings.moodyFlexGrid.modulePath)
      ? drupalSettings.moodyFlexGrid.modulePath
      : "";
    var cardsHtml = "";

    for (var i = 0; i < CAROUSEL_STYLES.length; i++) {
      var style = CAROUSEL_STYLES[i];
      var imgSrc = modulePath + "/preview-images/" + style.value + ".jpg";
      cardsHtml +=
        '<div class="moody-flex-grid-carousel-card" data-style="' + style.value + '">' +
          '<div class="card-image">' +
            '<img src="' + imgSrc + '" alt="' + style.label + ' preview"' +
              ' onload="this.nextElementSibling.style.display=\'none\'"' +
              ' onerror="this.style.display=\'none\'">' +
            '<div class="card-placeholder">(preview image)</div>' +
          '</div>' +
          '<div class="card-label">' + style.label + '</div>' +
        '</div>';
    }

    return '<div class="moody-flex-grid-preview-carousel">' +
      '<button type="button" class="moody-flex-grid-carousel-nav moody-flex-grid-carousel-prev" aria-label="Previous styles">&#8592;</button>' +
      '<div class="moody-flex-grid-carousel-track-wrapper">' +
        '<div class="moody-flex-grid-carousel-track">' + cardsHtml + '</div>' +
      '</div>' +
      '<button type="button" class="moody-flex-grid-carousel-nav moody-flex-grid-carousel-next" aria-label="Next styles">&#8594;</button>' +
    '</div>';
  }

  function bindPreviewEvents() {
    if (previewEventsBound) {
      return;
    }

    previewEventsBound = true;

    $(document).on("click", ".moody-flex-grid-carousel-card", function () {
      var styleValue = $(this).data("style");
      $(this).closest(".moody-flex-grid-form-enhancements")
        .find("select[name='flex_grid_view_mode']")
        .val(styleValue)
        .trigger("change");
    });

    $(document).on("click", ".moody-flex-grid-carousel-prev", function () {
      var $scope = $(this).closest(".moody-flex-grid-form-enhancements");
      if (carouselOffset > 0) {
        carouselOffset--;
        updateCarouselTransform($scope);
        updateCarouselNavButtons($scope);
      }
    });

    $(document).on("click", ".moody-flex-grid-carousel-next", function () {
      var $scope = $(this).closest(".moody-flex-grid-form-enhancements");
      var maxOffset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
      if (carouselOffset < maxOffset) {
        carouselOffset++;
        updateCarouselTransform($scope);
        updateCarouselNavButtons($scope);
      }
    });
  }

  function syncCarouselActiveCard(styleValue, $scope) {
    $scope.find(".moody-flex-grid-carousel-card").removeClass("is-active");
    $scope.find('.moody-flex-grid-carousel-card[data-style="' + styleValue + '"]').addClass("is-active");
  }

  function scrollCarouselToActive(styleValue, $scope) {
    var activeIndex = findStyleIndex(styleValue);
    var maxOffset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
    var desired = activeIndex - Math.floor(CARDS_VISIBLE / 2);
    carouselOffset = Math.max(0, Math.min(desired, maxOffset));
    updateCarouselTransform($scope);
  }

  function updateCarouselTransform($scope) {
    var offsetPct = -(carouselOffset * (100 / CARDS_VISIBLE));
    $scope.find(".moody-flex-grid-carousel-track").css("transform", "translateX(" + offsetPct + "%)");
  }

  function updateCarouselNavButtons($scope) {
    var maxOffset = CAROUSEL_STYLES.length - CARDS_VISIBLE;
    $scope.find(".moody-flex-grid-carousel-prev").prop("disabled", carouselOffset <= 0);
    $scope.find(".moody-flex-grid-carousel-next").prop("disabled", carouselOffset >= maxOffset);
  }

  function findStyleIndex(styleValue) {
    for (var i = 0; i < CAROUSEL_STYLES.length; i++) {
      if (CAROUSEL_STYLES[i].value === styleValue) {
        return i;
      }
    }
    return 0;
  }
})(jQuery, Drupal, drupalSettings, once);
