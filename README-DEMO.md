# Demo build — do not edit by hand

This branch is the **static preview** that GitHub Pages serves at
`https://mrhacker628.github.io/morning-catch/`. It exists so the site can be
shown to someone without a PHP server.

It is generated from `main` / the feature branch, not written here:

- `index.html` — the shop page, running in its built-in preview mode
  (sample fish, simulated payment, no database)
- `terms.html`, `privacy.html`, `refund.html`, `shipping.html`, `contact.html` —
  the PHP policy pages rendered once to static HTML with sample seller details
- `demo.html` — a short note on what is real in the preview and what is not
- `admin-preview.png` — a screenshot of the seller dashboard, which needs PHP
  and MySQL and so cannot run here

The seller details on these pages (name, address, phone, FSSAI number) are
**placeholders**. The real ones live in the `business` block of the config file
on the live server.

To refresh this branch, re-render the five pages against a running copy of the
site and copy them here with `.php` links rewritten to `.html`.
