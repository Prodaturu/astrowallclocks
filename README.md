# Astro Wall Clocks GitHub Pages storefront

This repository publishes the static storefront at https://prodaturu.github.io/astrowallclocks/.

The modern pages live in `site/`. `build.py` mirrors public assets and the remaining public pages from https://astrowallclocks.com/, places the modern pages over the mirror, rewrites URLs for the GitHub Pages project path, and writes `dist/`. GitHub Actions builds and publishes `dist/` on pushes to `master`.

Buy Now and Add to cart use a browser-only demo cart. The cart stores quantity in local storage and displays the ₹4,499 product price and subtotal. It does not connect to WooCommerce, process orders, or take payments. Track Order has a hosted information page with a link to the live WordPress order lookup.

To build locally, run `python3 build.py`, then serve `dist/` under the `/astrowallclocks/` path.
