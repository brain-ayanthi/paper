# Documentation screenshots

Drop the real screenshots referenced by the user guide into **this folder**.

The documentation pages reference images by path, for example:

```
/assets/documentation/images/cashier-overview.png
```

Until a real file exists at that path, the guide shows a tidy "Screenshot
placeholder" box that prints the expected filename — so you always know exactly
which image to capture and where it goes. Replace the placeholders simply by
saving a PNG (or JPG/WebP) with the matching name here.

## Recommended capture settings

- **Width:** ~1400px (retina-friendly, crisp on large screens).
- **Format:** PNG for UI screenshots; WebP is fine too.
- **Theme:** capture in light mode for consistency (the guide itself supports
  both light and dark).
- **Privacy:** blur or use demo data — avoid real customer names, phone
  numbers, card details or addresses.

## Naming

Use the exact filenames already referenced in the HTML pages (kebab-case),
e.g. `dashboard-overview.png`, `products-list.png`, `shifts-close.png`.
Search the `public/documentation/*.html` files for `images/` to see every
placeholder path the guide expects.
