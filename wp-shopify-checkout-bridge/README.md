Routes WordPress traffic to Shopify checkout with full session roaming.

## What changed in v1.13.3

- **Removed the plugin's extra "Buy Now" button.** Themes that already provide a
  working Add to Cart / Buy Now are used as-is; the plugin no longer injects its
  own button. Checkout still hands off to Shopify via the "Pay via Shopify"
  gateway at Place Order.

## What changed in v1.13.2

- **Faster checkout redirect.** v1.12.4 re-resolved every line item's SKU
  against the Shopify Admin API on each Place Order (one API call per item),
  which made the hand-off slow for multi-item carts. Checkout now uses the
  stored variant ids directly — a single Cart API call — and only falls back to
  re-resolving SKUs (in one batched call) if Shopify actually rejects a stale
  id, then retries once. Normal checkouts are now one round-trip.

## What changed in v1.13.1

- **Checkout now registers as Direct traffic, not a self-referral.** Previously
  Shopify logged your WordPress domain as the referrer for every sale. Because
  WordPress is your own first-party front-end, the plugin now sends
  `Referrer-Policy: no-referrer` (plus a no-referrer meta tag) on the cart and
  checkout pages, so the hop to Shopify carries no referrer and Shopify records
  **Direct** instead of your domain — the same idea as an analytics
  "referral exclusion". Genuine ad UTMs live in the URL, so paid-traffic
  attribution is unaffected. Toggle via *Settings → "Report checkout as Direct
  traffic"* (on by default).

## What changed in v1.13.0

- **Shopify orders now look like organic Online Store sales.** By default the
  plugin no longer attaches its technical metadata to the Shopify order — there's
  no order **note** and no **Additional details** attributes (`source`,
  `wpsb_sid`, `wpsb_wc_order_id`, `wpsb_return_to`, `item_numbers`, UTMs). The
  order shows just the customer, line items and totals, exactly like a normal
  sale. The WooCommerce order is still marked paid — the order poll now matches
  the Shopify order to its WooCommerce order by **customer email + total** (only
  when there is a single unambiguous unpaid match).
- **Opt-in metadata.** A new setting, *Attach reconciliation data to Shopify
  orders* (Settings → Storefront / Conversion), restores the previous behavior
  for anyone who wants the exact WooCommerce order id / UTMs stored on the
  Shopify order for precise matching.

  Note: the line-item **name** in the Shopify order is the Shopify product's
  title. If your Shopify products are titled "item 1/2/3", orders show those; to
  show real names, give the Shopify products real titles.

## What changed in v1.12.4

- **Fixes "merchandise ... does not exist" at Shopify checkout after a
  re-import.** When Shopify recreates a variant (e.g. re-importing the product
  CSV), its variant id changes; the plugin used to check out with the stale
  stored id, which Shopify rejects. Now the checkout **re-resolves each line's
  SKU to its current Shopify variant** at Place Order (via the Admin API),
  refreshing the stored id on the fly — a stale id can no longer break checkout.
- **"Link to Shopify by SKU" now refreshes ALL products**, not just unlinked
  ones, so it also corrects stale links from a re-import, and **clears** a stored
  link whose SKU no longer matches any Shopify variant (so a dead id is never
  used at checkout).

## What changed in v1.12.3

- **Native "Buy Now" button.** The single-product page now shows a **Buy Now**
  button beside the theme's native **Add to Cart**. Add to Cart stays 100% the
  theme's own button; Buy Now adds the item to the WooCommerce cart (native,
  respecting the chosen quantity) and jumps straight to the native checkout,
  where the **Pay via Shopify** gateway redirects to Shopify for payment. So the
  whole browse → add/buy → checkout experience is the native WooCommerce one,
  and only the payment step leaves for Shopify.

## What changed in v1.12.2

- **Fixes "Link by SKU" never reaching most products.** The link pass used to
  look up only the 50 lowest-ID unlinked products (`ORDER BY id ASC LIMIT 50`),
  so a higher-ID product could never be attempted — and if the first 50 all had
  non-matching SKUs, the window never advanced and the same product stayed
  unreachable no matter how many times you clicked. The linker now processes
  **every** unlinked product in one pass: with an Admin token it fetches
  Shopify's full SKU→variant map once (paginated) and matches all products in
  memory (no per-product API call, no ID window); without an Admin token it uses
  the Storefront SKU search per product, bounded to 100 lookups.

## What changed in v1.12.1

- **Fixes "Link by SKU" reporting "no Shopify variant found" for a SKU that
  clearly exists.** The Admin SKU lookup requested a variant field
  (`availableForSale`) that is Storefront-only on some Admin API versions; when
  the pinned version rejected it, the whole GraphQL query errored and the linker
  silently swallowed it as "not found". The query now uses only version-safe
  Admin fields (`inventoryQuantity` / `inventoryPolicy`), and — importantly — the
  linker now **surfaces the real Shopify API error** in the admin notice instead
  of masking it, so a bad token or missing `read_products` scope is obvious.
  SKU comparison is an exact, trimmed, case-sensitive match. Set `WP_DEBUG` to
  log the raw request/response for each SKU lookup.

## What changed in v1.12.0

- **Native WordPress checkout, pay on Shopify.** The checkout page is no longer
  intercepted. Shoppers now see WooCommerce's **full native checkout** — product
  **images from the WordPress media library**, titles, prices, quantities,
  subtotal/tax/shipping/total, and the billing/shipping form. Payment is a new
  WooCommerce gateway, **"Pay via Shopify"**: on **Place Order**, WooCommerce
  validates the form and creates the order, then the shopper is redirected to
  the Shopify hosted checkout to pay. Enable it under **WooCommerce → Settings →
  Payments**.
- **Shopify receives only SKU, price, quantity, customer info, and
  `source: Online Store`.** The Shopify cart is built from the SKU-matched
  variant; the buyer's email + shipping address are prefilled via checkout URL
  params. **No product images or descriptions are ever sent to Shopify** — they
  stay in WordPress. The matched SKUs ride along as an `item_numbers` cart
  attribute.
- **Order reconciliation.** The WooCommerce order id is carried in the Shopify
  cart attributes (`wpsb_wc_order_id`). The order-poll cron marks the matching
  WooCommerce order **paid** once Shopify reports the payment (requires the Admin
  token with `read_orders`).
- **Push to Shopify strips images + descriptions.** WordPress → Shopify push now
  sends only title, SKU and price, so Shopify products stay image/description-free
  by design.

## What changed in v1.11.4

- **Fixes "Link" reporting `linked 0` while products sit unconnected.** The
  linker's product-selection query is rewritten as explicit SQL: it now selects
  every product the status card counts as unlinked (variant id missing **or**
  empty) and reliably clears the per-run "already tried" markers, so a product
  that was tried in an earlier run (e.g. before it existed in Shopify) is
  retried instead of being skipped forever. The push-panel list uses the same
  definition, so the card, the push list, and the linker never disagree.

## What changed in v1.11.3

- **Link by SKU (fallback).** "Link to Shopify by name or SKU" now tries the
  product name / handle first and, if that doesn't match, falls back to matching
  by **SKU** via the Storefront product search. When it links on SKU it selects
  the exact variant carrying that SKU, so the buy button checks out the right
  item even when the WooCommerce product name differs from the Shopify one.

## What changed in v1.11.2

- **Connection status card.** Settings → Shopify Products now opens with a
  "Shopify connection status" card showing, at a glance, how many WooCommerce
  products are **linked to Shopify** (checkout-ready), how many are **not linked
  yet**, and how many products exist in the Shopify store — with a green
  "All products connected ✓" badge when everything is wired up. "Linked" counts
  products that carry a real Shopify variant, so the number reflects true
  checkout-readiness, not just a matching name.

## What changed in v1.11.1

- **The "Taking you to secure checkout…" spinner no longer gets stuck.** It now
  clears automatically the moment the shopper comes back (Back button / restored
  page), and has an 8-second safety timeout so it can never freeze the screen.

## What changed in v1.11

- **Cart survives the round-trip.** The WooCommerce cart is no longer emptied
  when redirecting to Shopify, so a shopper who returns from Shopify without
  buying still has their items (fixes "cart is empty on return").
- **Faster checkout.** The "Checkout" click is intercepted and builds the
  Shopify checkout in the background behind a "Taking you to secure checkout…"
  spinner, instead of loading the heavy WooCommerce checkout page first. The
  server-side redirect remains as a fallback.

## What changed in v1.10

- **Native theme buttons.** The plugin no longer replaces your theme's buttons
  with its own. Your theme's **Add to cart** and **Buy now** work natively —
  products land in the WooCommerce cart and the mini-cart shows them. The
  redundant grey "Buy now — Secure checkout" button is gone.
- **Checkout still goes to Shopify.** Only the **checkout step** is intercepted:
  reaching WooCommerce checkout builds a Shopify cart from the WooCommerce cart
  items and redirects to the Shopify hosted checkout (the /cart page stays
  viewable so shoppers can review first).
- **Attribution preserved on every path.** The real acquisition UTMs
  (x.com / bing / …) are mirrored into a `wpsb_attr` cookie so the server-side
  cart → Shopify checkout can append them — so add-to-cart-then-checkout orders
  are attributed correctly too, not just the direct buy path.

## What changed in v1.9

- **Link to Shopify by name (no Admin token).** For catalogues where products
  live in WooCommerce (e.g. from a product feed) *and* were added to Shopify
  separately, the Shopify Products page now has a **"Link to Shopify by name"**
  button. It matches each WooCommerce product to its Shopify twin by
  handle/name using **only the Storefront token**, stores the variant + price +
  stock, and makes the buy button check out that exact item — no Admin token,
  no code, no duplicate products. Runs in batches; unmatched products are
  reported so you can fix their name/handle in Shopify and click again.

## What changed in v1.8

- **Automatic price & stock re-sync.** Every hour, a cron refreshes each linked
  WooCommerce product from live Shopify data — price, sale price, stock status,
  and the cached snapshot — in bounded batches, so what shoppers see on
  WordPress always matches what they pay at checkout (price mismatches are the
  #1 chargeback trigger). Products deleted in Shopify are marked out of stock,
  never deleted. A **Sync prices now** button on Settings → Shopify Products
  runs the whole catalogue on demand. The cron is cleared on deactivation.

## What changed in v1.7

Social attribution, return-to-WordPress, and a WordPress → Shopify product push.

- **X / social traffic attribution.** Visits from X (t.co / x.com), Facebook,
  Instagram, TikTok, YouTube, Pinterest, and Reddit are now labeled with their
  *real* source: the landing UTMs are captured (now including `twclid`), and if
  a social platform stripped the UTM tags the source is inferred from the
  genuine referrer (first-touch only — never fabricated). The captured UTMs are
  appended to the **Shopify checkout URL** so Shopify's own session attribution
  reads them, instead of miscounting the visit as "Direct".
- **Return to WordPress.** Every checkout carries the exact WordPress page the
  shopper came from as a `wpsb_return_to` cart attribute (same-origin
  validated). Paste the one-line snippet from **Settings → Shopify Bridge →
  Return-to-WordPress Snippet** into your Shopify theme's cart template and
  Shopify's "Return to cart" link sends shoppers straight back to that
  WordPress page. The browser Back button works natively either way.
- **WordPress → Shopify product push.** WooCommerce products that don't exist
  in Shopify yet are listed on **Settings → Shopify Products → WordPress →
  Shopify** with a one-click **Push to Shopify** (same title, description,
  price, image). The returned product/variant IDs are linked back, so the buy
  button immediately checks out the exact product. Requires the Admin token to
  include the `write_products` scope. (Note: the image must be publicly
  reachable — Shopify fetches it by URL, so localhost images won't transfer.)
- Admin REST query values are now properly URL-encoded (fixes a subtle
  ISO-timestamp issue in the order poll).

## What changed in v1.6.x

WooCommerce is the catalogue/display layer; Shopify stays the checkout.

- **Imports become WooCommerce products** (title, description, regular/sale
  price, images sideloaded into the Media Library) so your theme renders them
  natively. **Import all** runs sequentially and never times out.
- **Buy goes straight to Shopify** for the exact variant (single product +
  shop loop + a catch-all interceptor for block themes), carrying
  `wpsb_sid`/UTMs/pixels, with a visible fallback link.
- **WooCommerce cart & checkout are redirected** — if the WC cart holds
  imported items they're converted into a Shopify checkout *with the items*;
  otherwise shoppers go to the configured page.
- **Faster:** `get_product_by_handle()` cached in a transient (filterable via
  `wpsb_product_cache_ttl`, not-found results cached briefly, busted on
  re-import); `[wpsb_product]` server-rendered (no per-card AJAX); lazy images.
- **Cache-proof assets:** JS/CSS are versioned by file modification time so
  browsers/CDNs can never serve a stale script after an update.
- **Migration** moves pre-1.6 imported Pages into WooCommerce safely.

## What changed in v1.4–v1.5

- **Claude Assistant** (Settings → Shopify Assistant): health check with plain-
  English diagnostics, safe whitelisted one-click fixes, and a read-only Ask box.
  No secrets ever leave the site; every action is nonce + capability gated.
- **Shopify Products** page: browse the catalogue, one-click add, copy shortcode.

## What changed in v1.3

- **Order detection (no webhooks):** carts stamp `wpsb_sid`; a 15-minute cron
  polls the Admin API and marks carts recovered with order totals.
- **Multi-touch AI recovery:** stage machine (default 1h/24h/72h), Claude-written
  copy cached per cart, Klaviyo event push or styled `wp_mail`, HTML fallback,
  `wpsb_recovery_send` filter, List-Unsubscribe.
- **Recovery dashboard** (Settings → Shopify Recovery): KPIs, trend chart,
  recent carts, AI insights.
- **Conversion UI:** single-accent design system, quantity stepper, skeletons,
  trust row, free-shipping bar, sticky mobile bar, SVG icons, deferred pixels.

## Setup

### 1. Shopify tokens

Shopify Admin → Settings → Apps and sales channels → Develop apps → Create app:

- **Storefront API** scopes: `unauthenticated_read_product_listings`,
  `unauthenticated_read_product_inventory`, `unauthenticated_write_checkouts`,
  `unauthenticated_read_checkouts`.
- **Admin API** scopes: `read_orders` (recovery measurement) and
  `write_products` (WordPress → Shopify push).

### 2. Install & configure

Upload the zip via Plugins → Add New → Upload. Then Settings → Shopify Bridge:
domain, Storefront token, Admin token, currency, pixels, recovery options, and
the WooCommerce → Shopify redirect. WooCommerce must be active for the
catalogue features.

### 3. Products

Use **Settings → Shopify Products** to import Shopify products into WooCommerce
(or push WooCommerce products to Shopify), or place shortcodes manually:

```
[wpsb_product handle="best-product"]
[wpsb_buy_button handle="best-product" variant="gid://shopify/ProductVariant/123" text="Buy Now"]
[wpsb_cart]
```

### 4. Return-to-WordPress (one-time)

Paste the snippet from Settings → Shopify Bridge into your Shopify theme's cart
template so "Return to cart" bounces shoppers back to the WordPress page they
came from.

## Requirements

- WordPress 5.6+ · PHP 7.4+ · WooCommerce (for catalogue features)
- A Shopify plan that supports the Storefront API
