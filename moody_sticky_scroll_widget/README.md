# Moody Sticky Scroll Widget

A Drupal module that provides a mobile-friendly widget with a sticky image on the left and scrolling text content on the right.

## Features

- **Mobile-First Design**: Responsive layout that stacks vertically on mobile and displays side-by-side on larger screens
- **Sticky Image Behavior**: Image stays fixed in place while the user scrolls through text content (on desktop)
- **Flexible Content**: Rich text editor for content with support for formatting, headings, lists, and links
- **Media Integration**: Uses Drupal's media library for image management

## Usage

1. Enable the module:
   ```
   drush en moody_sticky_scroll_widget
   ```

2. Add the block to a layout:
   - Go to Structure > Block layout
   - Click "Place block" in the desired region
   - Search for "Moody Sticky Scroll Widget"
   - Configure the block with:
     - **Text Content**: Rich text content that will scroll (supports HTML formatting)
     - **Fixed Image**: Select an image from the media library that will remain fixed during scrolling

3. The widget will automatically:
   - Display the image on the left (desktop) or top (mobile)
   - Keep the image sticky/fixed while scrolling through text content
   - Adapt layout based on screen size

## Technical Details

### Responsive Breakpoints
- **Mobile (< 768px)**: Vertical stack layout with image on top
- **Tablet (768px - 991px)**: Side-by-side layout with 40% image width
- **Desktop (≥ 992px)**: Side-by-side layout with 35% image width and increased spacing

### Styling
The module includes compiled CSS with:
- Sticky positioning for images on desktop
- Responsive typography and spacing
- Styled headings, paragraphs, lists, and links
- Box shadow and border radius on images

### Development

To modify styles:
1. Edit `scss/moody-sticky-scroll-widget.scss`
2. Compile SCSS to CSS:
   ```bash
   cd moody_sticky_scroll_widget
   npm install  # First time only
   npm run scss
   ```

## Requirements

- Drupal 10 or 11
- UTExas Media module (for media library integration)

## Author

Moody College of Communication
