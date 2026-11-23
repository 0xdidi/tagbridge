# Tagbridge – Deep Integration for PostHog

An independent PostHog integration and modular integration platform for WordPress. Connect the platforms you use, configure global privacy rules once, and send clean browser and server-side events with zero code.

Tagbridge is not affiliated with, endorsed by, or sponsored by PostHog or any listed third-party service.

This is the developer README. End-user documentation lives in `readme.txt`.

> **Note on the name:** This project was previously called **HogPress**. It was
> renamed to **Tagbridge** to avoid any potential WordPress or PostHog trademark
> issues. The old `hogpress` repository is kept as an archive; all further
> development happens here. The plugin is published on WordPress.org at
> https://wordpress.org/plugins/tagbridge/.

## Architecture

Three layers with strict boundaries:

- **`src/Core/`**: platform-agnostic PHP with **zero WordPress function calls** (enforced by `CorePurityTest`). Plain data in, plain data out: normalized `Event` value objects (RFC 4122 UUIDv4 `event_id`), `Schema`, `Host` resolution, `Identity\Resolver` (`ph_*` cookie parsing, `$sesid` freshness bounds, HMAC `wp_` user IDs), `ServerClient` (`posthog-php` wrapper with feature flags), and `Privacy` policy/consent rules.
- **`src/Platform/`**: WordPress platform shell: `Plugin`, lazy `Registry`, `Catalog` (upcoming integrations + local watchlist), `Options` & `GlobalSettings`, `PrivacyGate` (WP Consent API, DNT, role exclusions), `Http\RequestContext` (proxy-safe IP and user agent), shared `EventBus`, shared Event Sources (`WordPressSource`, `WooCommerceSource`), and the Integrations Hub + Privacy admin shell (`SettingsPage`, `Notices`).
- **`src/Modules/`**: self-contained integration modules (`PostHog/`, `Webhooks/`). Disabled modules never instantiate or load their runtime classes.

## Requirements

- **Runtime:** PHP 8.2+, WordPress 5.8+. WooCommerce is optional.
- **Dev:** PHP 8.2+ and Composer, Node 18+, and Docker / OrbStack (for `wp-env`).

## Local development

```bash
composer install   # PHP dev tools + posthog-php
npm install        # wp-env + build tooling
npm run env:start  # WordPress at http://localhost:8888 (admin / password)
```

`wp-env` bind-mounts this repository directory directly into `wp-content/plugins/tagbridge`. Edits take effect immediately without uploading `dist/tagbridge.zip`.

### Quality checks

```bash
composer lint       # PHPCS (WordPress Coding Standards)
composer lint:fix   # auto-fix formatting
composer test:unit  # PHPUnit unit tests (Core, Platform, Modules)
```

Coding conventions enforced by CI (`.github/workflows/ci.yml`):

- WordPress Coding Standards (tabs, `array()` syntax, Yoda conditions, `'tagbridge'` text domain).
- Zero WordPress function calls inside `src/Core/` (`CorePurityTest`).
- Unit tests run on PHP 8.2, 8.3, and 8.4 via Brain\Monkey.

### Build the distributable ZIP

```bash
bin/build-zip.sh    # produces dist/tagbridge.zip (production files only)
```

### Release

Pushing a version tag (`git tag vX.Y.Z && git push origin vX.Y.Z`) automatically runs lint and unit tests, verifies version headers in `tagbridge.php` and `readme.txt`, builds `dist/tagbridge.zip`, and publishes the GitHub Release via `.github/workflows/release.yml`.

To publish or update a GitHub Release locally, or stage a WordPress.org SVN release:

```bash
bin/release-github.sh   # build dist/tagbridge.zip and publish GitHub Release for current tag
bin/deploy-svn.sh       # stage trunk, tag, and .wordpress-org assets in ~/Projects/tagbridge-svn
```

## Extending Tagbridge

### Adding a module

1. Create `src/Modules/<Name>/Module.php` implementing `ModuleInterface` (plus `ConfigurableModule` for settings/UI and `EventSubscriberInterface` to receive `EventBus` events).
2. Register the module in `Platform\Plugin::manifest()` with `'class'`, `'default'`, `'category'`, `'consent_category'`, `'icon'` (official SVG in `assets/icons/`), and `'label'` / `'description'` wrapped in `static function ()` closures so translations load after `init` (WordPress 6.7+).
3. Add a matching entry in `Catalog::all()` only if it is still **Coming soon**; modules present in `Plugin::manifest()` automatically graduate out of `Catalog::upcoming()`.

### Canonical events (`Core\Events\Schema`)

| Event | Source | Key properties |
| --- | --- | --- |
| `user_logged_in` | WordPress | User context via `wp_user` |
| `user_registered` | WordPress | User context via `wp_user` |
| `product_viewed` | WooCommerce | `product_id`, `name`, `price`, `currency`, `sku`, `category` |
| `product_added_to_cart` | WooCommerce | `product_id`, `variation_id`, `name`, `quantity`, `price`, `currency`, `sku`, `category` |
| `checkout_started` | WooCommerce | `order_id`, `value`, `revenue`, `currency`, `item_count`, `shipping`, `tax`, `discount`, `coupon`, `products[]` |
| `order_completed` | WooCommerce | Same order payload; idempotent per order across paid statuses (`processing`, `completed`) |

### Hooks and filters

- **Modules & Catalog:** `tagbridge_module_manifest`, `tagbridge_upcoming_integrations`
- **EventBus:** `tagbridge_before_dispatch_event`, `tagbridge_event`
- **Privacy & Consent:** `tagbridge_is_user_excluded`, `tagbridge_privacy_decision`, `tagbridge_consent_api_active`
- **WooCommerce:** `tagbridge_purchase_order_statuses`, `tagbridge_order_checkout_meta`
- **Request Context:** `tagbridge_server_event_ip` (resolution order: filter -> `CF-Connecting-IP` -> `X-Real-IP` -> `X-Forwarded-For` -> public `REMOTE_ADDR`)
- **PostHog:** `tagbridge_posthog_js_config`, `tagbridge_posthog_mask_text_selector`

## Repository layout

```
tagbridge.php            Main plugin file: header, guards, bootstrap
uninstall.php            Uninstall cleanup
readme.txt               WordPress.org readme
.github/workflows/       CI (PHPCS + PHPUnit) and tag-triggered GitHub Release workflows
bin/                     build-zip.sh, release-github.sh, deploy-svn.sh, render-assets.mjs
src/Core/                Platform-agnostic logic (no WP calls)
src/Platform/            WordPress shell (EventBus, Sources, Http, PrivacyGate, Registry, Admin Hub)
src/Modules/             Self-contained integration modules (PostHog/, Webhooks/)
assets/                  Admin CSS/JS, feature-flag block script, SVG icons, bundled Sora font
languages/               Translation template (.pot)
tests/                   PHPUnit unit tests
```

## Security

### Custom host, webhook endpoints, and SSRF (known, accepted tradeoff)

When PostHog uses a self-hosted / reverse-proxy URL or Webhooks sends events to an admin-configured `endpoint_url`, the WordPress server makes HTTP requests to that URL (`Modules\PostHog\Connection\Validator`, `posthog-php`, and `Modules\Webhooks\Dispatcher`). A server fetching an admin-supplied URL is the classic shape of a Server-Side Request Forgery (SSRF) vector: a crafted internal URL (such as `http://169.254.169.254/...`) could be reached from inside the host's network.

This is **accepted as-is** for now, because:

- Only users with `manage_options` can save these URLs. Such a user can already install plugins and execute PHP on a single-site install.
- Self-hosted reverse proxies and local/staging webhook receivers are documented use cases. Filtering private/internal IPs via `wp_safe_remote_*` / `wp_http_validate_url()` would break legitimate internal deployments.

If a stricter threat model is needed later (for example multisite with semi-trusted site admins), reject the cloud-metadata link-local range (`169.254.169.254`) before saving so internal reverse proxies keep working.

## License

GPL-2.0-or-later.
