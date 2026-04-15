# Hero Style Preview Images

Place preview images for each hero style in this directory.

Each image should be named after the view mode / formatter key it represents:

| File name                    | Hero style                    |
|------------------------------|-------------------------------|
| `default.jpg`                | Default                       |
| `moody_hero_1.jpg`           | Style 1                       |
| `moody_hero_2.jpg`           | Style 2                       |
| `moody_hero_3.jpg`           | Style 3                       |
| `moody_hero_4.jpg`           | Style 4                       |
| `moody_hero_5.jpg`           | Style 5                       |
| `moody_hero_6.jpg`           | Style 6                       |
| `moody_hero_6_short.jpg`     | Style 6 Short                 |
| `moody_hero_7.jpg`           | Style 7                       |
| `moody_hero_8.jpg`           | Style 8                       |

Images are loaded by `js/hero-formatters-split.js` using the path:

```
{module_path}/preview-images/{style_value}.jpg
```

When an image file is not present the carousel card displays a "(preview image)"
placeholder automatically — no code changes are required to add new images.

Recommended dimensions: **600 × 400 px** (3:2 aspect ratio).
