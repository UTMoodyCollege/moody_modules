# Image Specifications Reference

This document provides a reference for the recommended image dimensions and aspect ratios for each Field Widget implementation in the Moody Modules custom fields.

## Field Widget Image Specifications

### Hero Widget
**Widget ID:** `moody_hero`  
**File:** `moody_custom_fields/moody_hero/src/Plugin/Field/FieldWidget/MoodyHeroWidget.php`  
**Aspect Ratio:** 87:47  
**Recommended Resolution:** Minimum 2280x1232 pixels  
**Notes:** Image will be scaled and cropped to maintain the 87:47 ratio. Upload an image with a minimum resolution of 2280x1232 pixels to maintain quality and avoid cropping.

---

### Quotation Widget
**Widget ID:** `moody_quotation_widget`  
**File:** `moody_custom_fields/moody_quotation/src/Plugin/Field/FieldWidget/MoodyQuotationWidget.php`  
**Aspect Ratio:** 1:1 (square)  
**Recommended Resolution:** 500x500 pixels  
**Notes:** Upload an image of 500 x 500 pixels to maintain resolution & avoid cropping.

---

### Showcase Widget
**Widget ID:** `moody_showcase_widget`  
**File:** `moody_custom_fields/moody_showcase/src/Plugin/Field/FieldWidget/MoodyShowcaseWidget.php`  
**Aspect Ratio:** Varies by formatter  
**Recommended Resolution:**
- Default Style: 900x970 pixels
- Marketing Style Formatter: 1000x666 pixels

**Notes:** If using image, upload an image of 900 x 970 pixels to maintain resolution & avoid cropping. If using the Moody Showcase Marketing Style formatter, opt for an image with dimensions of 1000 x 666 pixels to maintain resolution & avoid cropping.

---

### Subsite Hero Widget
**Widget ID:** `moody_subsite_hero`  
**File:** `moody_custom_fields/moody_subsite_hero/src/Plugin/Field/FieldWidget/MoodySubsiteHeroWidget.php`  
**Aspect Ratio:** Approximately 3.13:1  
**Recommended Resolution:** 1800x575 pixels  
**Notes:** Image will be scaled and cropped to 1800 x 575 pixels. Upload an image with a resolution of 1800 x 575 pixels to maintain quality and avoid cropping.

---

## Additional Information

### Image Optimization
Some widgets provide an option to "Disable image size optimization" which allows you to:
- Display animated GIFs
- Use specific image dimensions requirements

This option is available in:
- Hero Widget
- Subsite Hero Widget

### Supported Media Types
Most widgets use the Media Library with `utexas_image` bundle. The Showcase Widget also supports `utexas_video_external` for external videos.

---

*Last Updated: 2025-10-16*
