# Astro Wall Clocks

This repository builds and publishes a static copy of the public pages at
`https://astrowallclocks.com/` to GitHub Pages. The build runs on every push to
`master`. The output is hosted at `https://prodaturu.github.io/astrowallclocks/`.

The site is a visual HTML copy. WordPress admin, WooCommerce cart and checkout,
accounts, and forms still run on the live WordPress site. The Buy Now link in the
snapshot goes to the live Astro Wall Clock product page and adds one clock to
the live WooCommerce cart. The snapshot does not handle payments.

The source WordPress site has some image references to an old domain that no
longer resolves. The build uses the site's own logo and neutral placeholders
for three testimonial avatars. The local snapshot restores the original light
Order Now background and struck-through comparison price.

To build locally, run `python3 build.py`, then serve `dist` with a local HTTP
server. The generated `dist` directory is ignored by Git; GitHub Actions builds
it during deployment.
