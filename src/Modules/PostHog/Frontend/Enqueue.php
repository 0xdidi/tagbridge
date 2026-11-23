<?php
/**
 * Front-end injection of posthog-js.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Frontend;

use Tagbridge\Core\Privacy\ConsentCategory;
use Tagbridge\Core\Privacy\Policy;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\Settings;
use Tagbridge\Platform\Privacy\PrivacyGate;
use Tagbridge\Platform\Settings\GlobalSettings;

/**
 * Enqueues / prints the posthog-js snippet on the front end.
 */
final class Enqueue {

	/**
	 * One-shot cookie set on logout so the next page load runs posthog.reset().
	 *
	 * @var string
	 */
	const RESET_COOKIE = 'tagbridge_reset';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'print_snippet' ), 1 );
		add_action( 'wp_logout', array( $this, 'flag_reset_on_logout' ) );
		add_action( 'init', array( $this, 'register_cookie_info' ) );
	}

	/**
	 * Register the posthog-js cookie with the WP Consent API when active.
	 *
	 * @return void
	 */
	public function register_cookie_info() {
		if ( ! function_exists( 'wp_add_cookie_info' ) || ! Settings::is_configured() ) {
			return;
		}

		wp_add_cookie_info(
			'ph_' . Settings::project_api_key() . '_posthog',
			'PostHog',
			ConsentCategory::STATISTICS,
			__( '1 year', 'tagbridge' ),
			__( 'Stores an anonymous visitor ID and session state for analytics and session replay.', 'tagbridge' ),
			__( 'Anonymous ID, session ID', 'tagbridge' )
		);
	}

	/**
	 * Set a short-lived flag cookie on logout so the next page load resets PostHog.
	 *
	 * @return void
	 */
	public function flag_reset_on_logout() {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::RESET_COOKIE,
			'1',
			array(
				'expires'  => time() + HOUR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Print the posthog-js loader and init, respecting global privacy policies.
	 *
	 * @return void
	 */
	public function print_snippet() {
		if ( ! Settings::is_configured() ) {
			return;
		}

		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		if ( PrivacyGate::is_user_excluded() || PrivacyGate::is_dnt_blocked() ) {
			return;
		}

		$key          = Settings::project_api_key();
		$respect_dnt  = GlobalSettings::respect_dnt();
		$consent_mode = GlobalSettings::consent_mode();
		$client       = Settings::client();
		$reset        = $this->reset_call();

		if ( Policy::CONSENT_REQUIRE === $consent_mode ) {
			$init_js = $this->loader() . "\n" . $this->init_call( $key, $this->build_config( false ) );
			if ( '' !== $reset ) {
				$init_js .= "\n" . $reset;
			}
			if ( PrivacyGate::has_consent( ConsentCategory::STATISTICS ) ) {
				$identify = $this->identify_call();
				if ( '' !== $identify ) {
					$init_js .= "\n" . $identify;
				}
			}
			$stub = $this->consent_wait_stub( $init_js, $respect_dnt );
			wp_print_inline_script_tag( $stub, array( 'id' => 'tagbridge-posthog-consent' ) );
			return;
		}

		if ( Policy::CONSENT_COOKIELESS === $consent_mode ) {
			$user_cookieless = ! empty( $client['cookieless'] );
			$config          = $this->build_config( true );
			$identify        = PrivacyGate::has_consent( ConsentCategory::STATISTICS ) ? $this->identify_call() : '';
			$js              = $this->loader() . "\n" . $this->cookieless_consent_init_call( $key, $config, ! empty( $client['session_recording'] ), $user_cookieless, $identify );
			if ( '' !== $reset ) {
				$js .= "\n" . $reset;
			}
			if ( $respect_dnt ) {
				$js = $this->wrap_dnt_guard( $js );
			}
			wp_print_inline_script_tag( $js, array( 'id' => 'tagbridge-posthog-js' ) );
			return;
		}

		$js = $this->loader() . "\n" . $this->init_call( $key, $this->build_config( false ) );
		if ( '' !== $reset ) {
			$js .= "\n" . $reset;
		}

		$identify = $this->identify_call();
		if ( '' !== $identify ) {
			$js .= "\n" . $identify;
		}

		if ( $respect_dnt ) {
			$js = $this->wrap_dnt_guard( $js );
		}

		wp_print_inline_script_tag( $js, array( 'id' => 'tagbridge-posthog-js' ) );
	}

	/**
	 * Build a cache-safe WP Consent API listener that loads PostHog once consent is granted.
	 *
	 * @param string $init_js     PostHog loader and init JavaScript.
	 * @param bool   $respect_dnt Whether to honor navigator.doNotTrack client-side.
	 * @return string
	 */
	private function consent_wait_stub( $init_js, $respect_dnt ) {
		$dnt_guard   = $respect_dnt
			? 'if(navigator.doNotTrack==="1"||window.doNotTrack==="1"){return;}'
			: '';
		$cookie_name = wp_json_encode( $this->consent_cookie_name() );
		$category    = wp_json_encode( ConsentCategory::STATISTICS );

		return '(function(){'
			. 'var ck=' . $cookie_name . ',cat=' . $category . ';'
			. 'function hasCookie(){var m=document.cookie.match(new RegExp("(?:^|; )"+ck+"=([^;]*)"));return !!m&&decodeURIComponent(m[1])==="allow";}'
			. 'function hasConsent(){return typeof window.wp_has_consent==="function"?!!window.wp_has_consent(cat):hasCookie();}'
			. 'function boot(){if(window.__tagbridgePostHogLoaded){return;}' . $dnt_guard . 'window.__tagbridgePostHogLoaded=true;' . $init_js . '}'
			. 'if(hasConsent()){boot();}'
			. 'document.addEventListener("DOMContentLoaded",function(){if(hasConsent()){boot();}});'
			. 'document.addEventListener("wp_listen_for_consent_change",function(e){'
			. 'if(!e.detail||typeof e.detail[cat]==="undefined"){return;}'
			. 'if(e.detail[cat]==="allow"){if(!window.__tagbridgePostHogLoaded){boot();}else if(window.posthog&&window.posthog.opt_in_capturing){window.posthog.opt_in_capturing();}}'
			. 'else if(e.detail[cat]==="deny"){if(window.posthog&&window.posthog.opt_out_capturing){window.posthog.opt_out_capturing();}}'
			. '});'
			. '})();';
	}

	/**
	 * Build a cache-safe init call for Cookieless until consent mode.
	 *
	 * Starts in memory with session recording off when unconsented, and upgrades
	 * persistence/replay in the browser once consent is granted.
	 *
	 * @param string              $key             Project API key.
	 * @param array<string,mixed> $config          Initial memory-mode config.
	 * @param bool                $replay_enabled  Whether session recording is enabled in settings.
	 * @param bool                $user_cookieless Whether cookieless mode is explicitly forced in settings.
	 * @param string              $identify_js     Optional posthog.identify(...) call for consented logged-in users.
	 * @return string
	 */
	private function cookieless_consent_init_call( $key, array $config, $replay_enabled, $user_cookieless, $identify_js = '' ) {
		if ( $replay_enabled ) {
			$config['disable_session_recording'] = true;
		}

		$options = array(
			'category'    => ConsentCategory::STATISTICS,
			'cookie'      => $this->consent_cookie_name(),
			'persistence' => $user_cookieless ? 'memory' : 'localStorage+cookie',
			'replay'      => $replay_enabled,
		);

		$identify_block = '' !== $identify_js ? 'if(on){' . $identify_js . '}' : '';

		$js = <<<'JS'
(function(k,c,o){
function hasCookie(){var m=document.cookie.match(new RegExp('(?:^|; )'+o.cookie+'=([^;]*)'));return !!m&&decodeURIComponent(m[1])==='allow';}
function hasConsent(){return typeof window.wp_has_consent==='function'?!!window.wp_has_consent(o.category):hasCookie();}
var on=hasCookie();
if(on){c.persistence=o.persistence;if(o.replay){c.disable_session_recording=false;}}
posthog.init(k,c);%s
function apply(v){if(v===on){return;}on=v;
if(v){if(window.posthog&&window.posthog.set_config){window.posthog.set_config({persistence:o.persistence});}if(o.replay&&window.posthog&&window.posthog.startSessionRecording){window.posthog.startSessionRecording();}if(window.posthog&&window.posthog.opt_in_capturing){window.posthog.opt_in_capturing();}}
else{if(window.posthog&&window.posthog.stopSessionRecording){window.posthog.stopSessionRecording();}if(window.posthog&&window.posthog.set_config){window.posthog.set_config({persistence:'memory'});}}}
document.addEventListener('wp_listen_for_consent_change',function(e){var d=e&&e.detail||{};if(d[o.category]==='allow'){apply(true);}else if(d[o.category]==='deny'){apply(false);}});
function check(){if(typeof window.wp_has_consent==='function'){apply(hasConsent());}}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',check);}else{check();}
})(%s,%s,%s);
JS;

		return sprintf( $js, $identify_block, wp_json_encode( $key ), wp_json_encode( $config ), wp_json_encode( $options ) );
	}

	/**
	 * WP Consent API cookie name for statistics.
	 *
	 * @return string
	 */
	private function consent_cookie_name() {
		$prefix = 'wp_consent';
		if ( function_exists( 'apply_filters' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$prefix = (string) apply_filters( 'wp_consent_cookie_prefix', $prefix );
		}

		return $prefix . '_' . ConsentCategory::STATISTICS;
	}

	/**
	 * Wrap inline script in a client-side Do Not Track check (for cached pages).
	 *
	 * @param string $js Inline JavaScript.
	 * @return string
	 */
	private function wrap_dnt_guard( $js ) {
		return "(function(){\nif(navigator.doNotTrack===\"1\"||window.doNotTrack===\"1\"){return;}\n" . $js . "\n})();";
	}

	/**
	 * Build posthog.reset() for a just-logged-out visitor, or '' if none.
	 *
	 * @return string
	 */
	private function reset_call() {
		if ( empty( $_COOKIE[ self::RESET_COOKIE ] ) || is_user_logged_in() ) {
			return '';
		}

		$path  = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$clear = self::RESET_COOKIE . '=; path=' . $path . '; max-age=0';

		return 'posthog.reset();document.cookie=' . wp_json_encode( $clear ) . ';';
	}

	/**
	 * Build the posthog.identify(...) call for the logged-in user, or '' if none.
	 *
	 * @return string
	 */
	private function identify_call() {
		$identity = Identity::current_user_identity();
		if ( null === $identity || '' === $identity['distinct_id'] ) {
			return '';
		}

		$args = wp_json_encode( $identity['distinct_id'] );
		if ( ! empty( $identity['properties'] ) ) {
			$args .= ',' . wp_json_encode( $identity['properties'] );
		}

		return 'posthog.identify(' . $args . ');';
	}

	/**
	 * Build the posthog.init config from settings.
	 *
	 * @param bool $force_cookieless Whether privacy policy requires memory-only persistence.
	 * @return array<string,mixed>
	 */
	private function build_config( $force_cookieless = false ) {
		$client = Settings::client();

		$config = array(
			'api_host'                  => Settings::host(),
			'defaults'                  => '2026-01-30',
			'autocapture'               => (bool) $client['autocapture'],
			'capture_heatmaps'          => ! empty( $client['heatmaps'] ),
			'capture_pageview'          => (bool) $client['pageviews'],
			'disable_session_recording' => empty( $client['session_recording'] ),
			'person_profiles'           => (string) $client['person_profiles'],
		);

		if ( ! empty( $client['capture_exceptions'] ) ) {
			$config['capture_exceptions'] = true;
		}

		if ( $force_cookieless || ! empty( $client['cookieless'] ) ) {
			$config['persistence'] = 'memory';
		}

		if ( ! empty( $client['session_recording'] ) ) {
			$config['session_recording'] = array( 'maskAllInputs' => true );

			$mask_selector = $this->mask_text_selector();
			if ( '' !== $mask_selector ) {
				$config['session_recording']['maskTextSelector'] = $mask_selector;
			}
		}

		/**
		 * Filter the posthog-js init configuration before it is printed.
		 *
		 * @param array<string,mixed> $config The configuration array.
		 */
		return (array) apply_filters( 'tagbridge_posthog_js_config', $config );
	}

	/**
	 * CSS selector for elements masked in session replay.
	 *
	 * @return string
	 */
	private function mask_text_selector() {
		$default = '.woocommerce-customer-details';

		/**
		 * Filter the session-replay text-masking selector.
		 *
		 * @param string $default Comma-separated CSS selector, or '' to disable.
		 */
		return (string) apply_filters( 'tagbridge_posthog_mask_text_selector', $default );
	}

	/**
	 * Build the posthog.init( token, config ) call as a JS string.
	 *
	 * @param string              $key    Project API key.
	 * @param array<string,mixed> $config Init configuration.
	 * @return string
	 */
	private function init_call( $key, array $config ) {
		return 'posthog.init(' . wp_json_encode( $key ) . ',' . wp_json_encode( $config ) . ');';
	}

	/**
	 * The official posthog-js array loader snippet.
	 *
	 * @return string
	 */
	private function loader() {
		return <<<'JS'
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
JS;
	}
}
