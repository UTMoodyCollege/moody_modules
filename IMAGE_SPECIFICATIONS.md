# Image Specifications Reference

This document provides a quick reference guide for image dimensions and aspect ratios for Moody Modules field widgets and components.

## Quick Reference Table

Use this table for a quick overview of image requirements:

| Widget/Component | Aspect Ratio | Recommended Size | Notes |
|-----------------|--------------|------------------|-------|
| **Hero** | 87:47 | 2280 x 1232 px | Minimum size for quality |
| **Subsite Hero** | ~3:1 | 1800 x 575 px | Exact size recommended |
| **Showcase (Default)** | ~0.93:1 | 900 x 970 px | Use for default style |
| **Showcase (Marketing)** | ~1.5:1 | 1000 x 666 px | Use for marketing style |
| **Quotation** | 1:1 (Square) | 500 x 500 px | Square format |
| **Flex Grid (Square)** | 1:1 (Square) | 500 x 500 px | Default square style |
| **Flex Grid (Rectangular)** | 3:2 | 750 x 500 px | Rectangular style option |
| **Focus Areas** | 1:1 (Square) | 280 x 280 px | Small square format |
| **Card** | Flexible | No specific size | No fixed dimensions |
| **Promotion** | Flexible | No specific size | No fixed dimensions |

---

## Detailed Widget Specifications

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

### Flex Grid Element
**Widget ID:** `moody_flex_grid`  
**File:** `moody_custom_fields/moody_flex_grid/src/Element/MoodyFlexGridElement.php`  
**Aspect Ratio:** 
- Default (Square): 1:1
- Rectangular Style: 3:2

**Recommended Resolution:**
- Square Style: 500x500 pixels
- Rectangular Style: 750x500 pixels (3:2 aspect ratio)

**Notes:** Image will be scaled and cropped to a 1:1 ratio by default. Ideally, upload an image of 500 x 500 pixels to maintain resolution & avoid cropping. If using the Flex Grid Rectangular Style, opt for an image with a 3:2 aspect ratio.

---

### Focus Areas Element
**Widget ID:** `moody_focus_areas`  
**File:** `moody_custom_fields/moody_focus_areas/src/Element/MoodyFocusAreaElement.php`  
**Aspect Ratio:** 1:1 (square)  
**Recommended Resolution:** 280x280 pixels  
**Notes:** Image will be scaled and cropped to a 1:1 ratio. Ideally, upload an image of 280 x 280 pixels to maintain resolution & avoid cropping.

---

### Card Widget
**Widget ID:** `moody_card_widget`  
**File:** `moody_custom_fields/moody_card/src/Plugin/Field/FieldWidget/MoodyCardWidget.php`  
**Aspect Ratio:** No specific requirement  
**Recommended Resolution:** No specific requirement  
**Notes:** No specific image dimensions specified. Image will be displayed as uploaded.

---

### Promotion Widget
**Widget ID:** `moody_promotion_widget`  
**File:** `moody_custom_fields/moody_promotion/src/Plugin/Field/FieldWidget/MoodyPromotionWidget.php`  
**Aspect Ratio:** No specific requirement  
**Recommended Resolution:** No specific requirement  
**Notes:** No specific image dimensions specified. Image will be displayed as uploaded.

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

## Summary for Content Creators

**Most Common Formats:**
- **Square images (1:1)**: 500x500 px for most square widgets, 280x280 px for Focus Areas
- **Wide images**: 2280x1232 px for Hero, 1800x575 px for Subsite Hero
- **Portrait/Tall images**: 900x970 px for Showcase default style

**General Guidelines:**
- Always upload images at or above the recommended resolution to maintain quality
- Images will be automatically scaled and cropped to fit the specified aspect ratio
- Use the "Disable image size optimization" option if you need to display animated GIFs or have specific dimension requirements

---

*Last Updated: 2025-10-17*
