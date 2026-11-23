=== Tagbridge – Deep Integration for PostHog ===
Contributors: mcgreat
Tags: analytics, posthog, tracking, events, statistics
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent PostHog integration for WordPress. Connect your project and send pageviews and events to PostHog. Not affiliated with PostHog.

== Description ==

Tagbridge is a modular integration platform for WordPress, starting with a deep, independent integration for PostHog. Enable only the integrations you use, configure privacy and consent rules once across your whole site, and send clean browser and server-side events without touching code.

Tagbridge is an independent project. It is not affiliated with, endorsed by, or sponsored by PostHog or any other listed service. Third-party product names and logos belong to their respective owners and are used solely to identify the services Tagbridge integrates with.

= What it does today =

* **Modular Integrations Hub:** Enable or disable integrations at any time with zero runtime cost when disabled. Each integration has its own isolated settings screen, plus a **Coming soon** catalog with local in-WordPress launch notifications (**Notify me**).
* **Global Privacy & Settings:** Built-in WordPress Consent API integration (`Off`, `Require consent`, or `Cookieless until consent`), Do Not Track (`DNT`) header support, and WordPress role exclusions applied across both browser scripts and server-side events.
* **PostHog Connection & Key Check:** Connect to PostHog Cloud (US or EU) or your own self-hosted or reverse-proxy host. Your project API key is checked with a live read-only test call before it is saved.
* **Client-side Tracking:** Loads PostHog (`posthog-js`) from the host you configure with toggles for pageviews, autocapture, heatmaps, session recording (with input and WooCommerce customer detail masking), optional JavaScript error capture, person profile mode, and cookieless mode.
* **Diagnostics & Feature Flags:** Verify PostHog reachability with a labeled `tagbridge_test_event` check, and gate content server-side with the PostHog Feature Flag Gutenberg block or `[tagbridge_flag]` shortcode.
* **Webhooks:** Send normalized, HMAC-SHA256 signed JSON event payloads (`X-Tagbridge-Signature`) for WordPress and WooCommerce events to Zapier, Make, n8n, or any HTTPS endpoint, with per-event switches and a live test delivery button.
* **Identity Stitching:** Tie logged-in users to one PostHog person across anonymous and logged-in sessions using a stable hashed identifier (never the raw user ID), with automatic browser reset on logout and server `$session_id` / proxy IP / user-agent enrichment.
* **Server-side Events:** Capture key events (`user_logged_in`, `user_registered`) directly from WordPress so they still arrive when browsers block tracking, with non-blocking shutdown delivery and per-event switches.
* **WooCommerce Events (when active):** HPOS-safe tracking for classic and Block Checkout (`product_viewed`, `product_added_to_cart`, `checkout_started`, `order_completed` across paid statuses with `revenue`, `shipping`, `tax`, `discount`, `coupon`, and `products[]`), idempotent order completion, and customer-safe privacy snapshots.

= On the roadmap =

Planned for future releases:

* **Upcoming Integrations:** Google Analytics 4, Meta Pixel & Conversions API, Klaviyo, Microsoft Clarity, Plausible Analytics, Intercom, and Slack & Discord Alerts.
* **PostHog Deepening:** Starter dashboards provisioned in your PostHog project.

== Installation ==

1. Install and activate the plugin.
2. In the WordPress admin, open the **Tagbridge** menu and click **Configure** on the **PostHog** integration.
3. Paste your PostHog project API key and choose your region (US, EU, or self-hosted / reverse proxy).
4. Save. Tagbridge checks the key with PostHog before saving and tells you if it worked.
5. Choose what to track (and optionally configure global consent or role exclusions under **Privacy & Settings**), then save again. PostHog will start receiving data from your site.

You can find your project API key in PostHog under Settings, Project, Project API key. It is a public key and is safe to use in the browser.

== Frequently Asked Questions ==

= Is this an official PostHog plugin? =

No. Tagbridge is an independent project and is not affiliated with or endorsed by PostHog.

= Do I need a PostHog account? =

Yes. You need a PostHog project and its project API key. PostHog offers a free tier. See https://posthog.com.

= Does it work with self-hosted PostHog or a reverse proxy? =

Yes. Choose "Self-hosted or reverse proxy" and enter your host URL. PostHog loads from that host.

= Will it set cookies? =

By default PostHog uses its normal storage. Turn on "Cookieless mode" in PostHog settings (or use "Cookieless until consent" under Privacy & Settings) to keep visitor state in memory only, so no PostHog cookie is set.

== Screenshots ==

1. Integrations Hub: enable or disable integrations in one click, and browse upcoming modules with local in-WordPress launch alerts.
2. Connect your PostHog project: paste the project API key and choose US cloud, EU cloud, or a self-hosted / reverse-proxy host. The key is checked before saving.
3. Choose what PostHog tracks with plain-language toggles for pageviews, autocapture, session recording, cookieless mode, and person profiles.
4. Privacy & Settings: configure the WordPress Consent API, Do Not Track, and role exclusions once across every integration.

== External services ==

This plugin connects to PostHog, the analytics service you configure. It is required for the PostHog integration to do anything, because its purpose is to send your site's analytics to your PostHog project.

What is sent and when:

* When you save PostHog settings in the admin, the plugin makes one request to the PostHog feature-flags endpoint (for example https://us.i.posthog.com/flags) with your project API key to confirm the key and host are valid. This request does not record any analytics event.
* On your site's front end, after you have connected and when permitted by your consent/privacy settings, the plugin loads PostHog (posthog-js) from the host you choose (PostHog US cloud, EU cloud, or your own host). PostHog then sends visitor analytics such as pageviews and, if enabled, clicks and form interactions and session recordings, to your PostHog project. The exact data depends on the toggles you choose.
* When server-side events are enabled, your WordPress server sends those events (for example user logged in, or a completed WooCommerce order with its value and currency) directly to your configured PostHog host. This happens from your server, not the visitor's browser.

The host the data is sent to is the one you configure:

* US cloud: https://us.i.posthog.com
* EU cloud: https://eu.i.posthog.com
* Self-hosted or reverse proxy: the URL you enter

PostHog terms of service: https://posthog.com/terms
PostHog privacy policy: https://posthog.com/privacy

== Privacy ==

No analytics are sent until you enter a PostHog project API key and connect. You control what is captured with the tracking toggles, and you can turn on cookieless mode so no PostHog cookie is set.

Global controls under **Privacy & Settings** apply across all integrations:

* **WordPress Consent API:** Choose `Off`, `Require consent` (blocks scripts and server events until consent is granted, and listens for live consent changes), or `Cookieless until consent` (runs anonymously in memory without person identification until consent is granted).
* **Do Not Track:** Optionally suppress browser and server-side tracking when a visitor sends a `DNT: 1` header.
* **Exclude user roles:** Skip tracking across browser and server events for selected WordPress roles (such as administrators).
* **Local launch alerts:** Selecting **Notify me** on a Coming soon integration stores only the integration slug locally in your WordPress `tagbridge` option; zero external calls are made.

== Changelog ==

= 0.4.1 =
* Fixed "Send test webhook" and "Send test event" buttons so they submit standalone forms to their diagnostic handlers instead of triggering settings save.
* Made PostHog browser consent bootstrapping unconditionally cache-safe in both "Require consent" and "Cookieless until consent" modes.
* Fixed Webhooks endpoint URL validation so invalid URL schemes (such as `ftp://`) return an error instead of silently clearing the field.
* Preserved case sensitivity in PostHog feature flag keys (`Evaluator::sanitize_flag_key()`), added script translations for the Feature Flag block, updated per-module save button copy, and refreshed `languages/tagbridge.pot`.

= 0.4.0 =
* Webhooks integration: send normalized JSON payloads with optional HMAC-SHA256 signing (`X-Tagbridge-Signature`), per-event toggles, delivery status, and a Send test webhook button.
* PostHog Diagnostics & Feature Flags: added Diagnostics tab with live `tagbridge_test_event` check and CSP/duplicate-snippet guidance, plus server-evaluated `tagbridge/feature-flag` Gutenberg block and `[tagbridge_flag]` shortcode.
* PostHog conformance & privacy: honor `person_profiles: identified_only` on server events (`$process_person_profile => false`), link server events to Session Replay via `$session_id`, reset browser identity on logout, stop generating random UUIDs for cookieless server events, add cache-safe browser consent checks, and register the PostHog cookie via `wp_add_cookie_info()`.
* PostHog tracking & WooCommerce enrichment: added `capture_heatmaps`, optional `capture_exceptions`, session-replay PII masking, proxy-safe `$ip` and `$raw_user_agent` context, paid-status `order_completed` trigger, and enriched ecommerce properties (`revenue`, `shipping`, `tax`, `discount`, `coupon`, `category`, `sku`, `products[]`).
* Replaced the generic WordPress admin sidebar icon with the Tagbridge mark.

= 0.3.0 =
* Integrations Hub: dedicated catalog screen with instant per-integration enable/disable (zero validation overhead on toggle), isolated per-module detail views, and a Coming soon catalog with local in-WordPress launch notifications ("Notify me").
* Global Privacy & Settings: WordPress Consent API integration (`Off`, `Require consent`, `Cookieless until consent`), Do Not Track (DNT) support, and role exclusions across browser and server-side tracking.
* Shared EventBus & Event Sources: decoupled WordPress Core and WooCommerce listeners into shared platform sources with deterministic `event_id` (`$insert_id`), WooCommerce Block Checkout support, idempotent `order_completed` capture, and customer-safe exclusion/consent snapshots on checkout.
* Improved WordPress 6.7+ compatibility by deferring manifest string translation until admin screens render.

= 0.2.2 =
* Added the Tagbridge brand mark to the settings screen header. No functional changes.

= 0.2.1 =
* Redesigned the settings screen: a clearer page header, each section's status or master switch in its header, a two-column layout for tracking and identity, and refined controls. No functional changes.

= 0.2.0 =
* Identity: tie logged-in users to one PostHog person with a stable, hashed identifier. Configurable, with per-property control over email, name, and role.
* Server-side events: send events from your WordPress server (user logged in, user registered), each with an on/off toggle and a master switch. A failed or slow PostHog request never affects your pages.
* WooCommerce events (when active): product viewed, added to cart, checkout started, and order completed with order value and currency, stitched to the same person from session through to the completed order.

= 0.1.0 =
* First release: connect to PostHog (US, EU, or self-hosted / reverse proxy), validate the key before saving, and load PostHog on the front end with tracking toggles (pageviews, autocapture, session recording, person profiles, cookieless mode).

== Upgrade Notice ==

= 0.4.1 =
Fixes test webhook/event form submission, makes browser consent gating unconditionally cache-safe, fixes invalid webhook URL validation, preserves feature flag key case, and refreshes translation strings.

= 0.4.0 =
Adds the Webhooks integration, PostHog Diagnostics and server-evaluated Feature Flag block/shortcode, cache-safe consent checks, server-side person profile and session replay conformance, and enriched WooCommerce order tracking.

= 0.3.0 =
Adds the modular Integrations Hub, Coming soon launch alerts, global WordPress Consent API & role exclusions, WooCommerce Block Checkout support, and idempotent order completion tracking.

= 0.2.2 =
Adds the Tagbridge brand mark to the settings header. No functional changes.

= 0.2.1 =
A redesigned settings screen. No functional changes.

= 0.2.0 =
Adds identity stitching and server-side event capture (including WooCommerce), all configurable.

= 0.1.0 =
First release.
