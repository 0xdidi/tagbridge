# Tagbridge – Deep Integration for PostHog

An independent PostHog integration and modular integration platform for WordPress. Connect the platforms you use, configure global privacy rules once, and send clean browser and server-side events with zero code.

Tagbridge is not affiliated with, endorsed by, or sponsored by PostHog.

This is the developer README. End-user documentation lives in `readme.txt`.

> **Note on the name:** This project was previously called **HogPress**. It was
> renamed to **Tagbridge** to avoid any potential WordPress or PostHog trademark
> issues. The old `hogpress` repository is kept as an archive; all further
> development happens here. The plugin is published on WordPress.org at
> https://wordpress.org/plugins/tagbridge/.

## Architecture

Three layers with strict boundaries:

- **`src/Core/`**: platform-agnostic PHP with **zero WordPress function calls** (enforced by `CorePurityTest`). Plain data in, plain data out: normalized `Event` value objects, `Schema`, `Host` resolution, `Identity\Resolver`, `ServerClient`, and `Privacy` policy/consent rules.
- **`src/Platform/`**: WordPress platform shell: `Plugin`, lazy `Registry`, upcoming `Catalog`, `Options` & `GlobalSettings`, `PrivacyGate` (WP Consent API, DNT, role exclusions), shared `EventBus`, shared Event Sources (`WordPressSource`, `WooCommerceSource`), and the Integrations Hub + Privacy admin shell (`SettingsPage`, `Notices`).
- **`src/Modules/`**: self-contained integration modules (currently `PostHog/`). Disabled modules never instantiate or load their runtime classes.

## Requirements

- **Runtime:** PHP 8.2+, WordPress 5.8+. WooCommerce is optional.
- **Dev:** PHP 8.2+ and Composer, Node 18+, and Docker / OrbStack (for `wp-env`).

## Local development

```bash
composer install   # PHP dev tools + posthog-php
npm install        # wp-env + build tooling
npm run env:start  # WordPress at http://localhost:8888 (admin / password)
```

### Quality checks

```bash
composer lint       # PHPCS (WordPress Coding Standards)
composer lint:fix   # auto-fix
composer test:unit  # PHPUnit unit tests (Core, Platform, Modules)
```

### Build the distributable ZIP

```bash
bin/build-zip.sh    # produces dist/tagbridge.zip (production files only)
```

## Repository layout

```
tagbridge.php            Main plugin file: header, guards, bootstrap
uninstall.php            Uninstall cleanup
readme.txt               WordPress.org readme
src/Core/                Platform-agnostic logic (no WP calls)
src/Platform/            WordPress platform shell (EventBus, Sources, PrivacyGate, Registry, Admin Hub)
src/Modules/             Self-contained integration modules (PostHog, ...)
assets/                  Admin CSS/JS and bundled Sora font
languages/               Translation template (.pot)
tests/                   PHPUnit tests
```

## Security

### Custom host and SSRF (known, accepted tradeoff)

When the PostHog region is set to "Self-hosted or reverse proxy," the admin
supplies a custom host URL. The server then makes requests to that URL: a
validation POST (`Modules\PostHog\Connection\Validator`) and, when server-side
events are enabled, event delivery via posthog-php. A server fetching an
admin-supplied URL is the classic shape of a Server-Side Request Forgery (SSRF)
vector: a crafted internal URL (e.g. a cloud metadata endpoint like
`http://169.254.169.254/...`) could be reached from inside the host's network.

This is **accepted as-is** for now, because:

- Only users with `manage_options` can set the custom host. Such a user can
  already install plugins and run arbitrary PHP, so this grants no new
  capability on a single-site install.
- The custom host is a documented, advertised feature. Hardening with
  `wp_safe_remote_*` / `wp_http_validate_url()` would block private/internal IPs
  and break legitimate self-hosted or reverse-proxy installs on internal
  networks.

If a stronger threat model is needed later (e.g. multisite with semi-trusted
site admins), the recommended middle ground is to reject only the cloud-metadata
link-local range (`169.254.169.254`) in the validator before saving: this
removes the highest-value SSRF target without breaking internal self-hosted
hosts.

## License

GPL-2.0-or-later.
