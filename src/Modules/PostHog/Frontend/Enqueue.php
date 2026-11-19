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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'print_snippet' ), 1 );
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

		$decision = PrivacyGate::decision( ConsentCategory::STATISTICS );
		if ( in_array( $decision['reason'], array( 'role_excluded', 'dnt' ), true ) ) {
			return;
		}

		$key          = Settings::project_api_key();
		$respect_dnt  = GlobalSettings::respect_dnt();
		$consent_mode = GlobalSettings::consent_mode();

		if ( 'consent_denied' === $decision['reason'] ) {
			$init_js = $this->loader() . "\n" . $this->init_call( $key, $this->build_config( false ) );
			$stub    = $this->consent_wait_stub( $init_js, $respect_dnt );
			wp_print_inline_script_tag( $stub, array( 'id' => 'tagbridge-posthog-consent' ) );
			return;
		}

		$forced_cookieless = ! empty( $decision['cookieless'] );
		$js                = $this->loader() . "\n" . $this->init_call( $key, $this->build_config( $forced_cookieless ) );

		if ( ! $forced_cookieless ) {
			$identify = $this->identify_call();
			if ( '' !== $identify ) {
				$js .= "\n" . $identify;
			}
		}

		if ( $respect_dnt ) {
			$js = $this->wrap_dnt_guard( $js );
		}

		if ( Policy::CONSENT_OFF !== $consent_mode ) {
			$client          = Settings::client();
			$user_cookieless = ! empty( $client['cookieless'] );
			$js             .= "\n" . $this->consent_change_listener( $forced_cookieless && ! $user_cookieless );
		}

		wp_print_inline_script_tag( $js, array( 'id' => 'tagbridge-posthog-js' ) );
	}

	/**
	 * Build a lightweight WP Consent API listener that loads PostHog once consent is granted.
	 *
	 * @param string $init_js     PostHog loader and init JavaScript.
	 * @param bool   $respect_dnt Whether to honor navigator.doNotTrack client-side.
	 * @return string
	 */
	private function consent_wait_stub( $init_js, $respect_dnt ) {
		$dnt_guard = $respect_dnt
			? 'if(navigator.doNotTrack==="1"||window.doNotTrack==="1"){return;}'
			: '';

		return 'document.addEventListener("wp_listen_for_consent_change",function(e){'
			. 'if(!e.detail||e.detail.statistics!=="allow"||window.__tagbridgePostHogLoaded){return;}'
			. $dnt_guard
			. 'window.__tagbridgePostHogLoaded=true;'
			. $init_js
			. '});';
	}

	/**
	 * Listen for live consent changes after PostHog has initialized.
	 *
	 * @param bool $upgrade_persistence Whether to switch from memory to standard persistence on allow.
	 * @return string
	 */
	private function consent_change_listener( $upgrade_persistence ) {
		$allow_js = $upgrade_persistence
			? 'if(window.posthog){if(window.posthog.set_config){window.posthog.set_config({persistence:"localStorage+cookie"});}if(window.posthog.opt_in_capturing){window.posthog.opt_in_capturing();}}'
			: 'if(window.posthog&&window.posthog.opt_in_capturing){window.posthog.opt_in_capturing();}';

		return 'document.addEventListener("wp_listen_for_consent_change",function(e){'
			. 'if(!e.detail||typeof e.detail.statistics==="undefined"){return;}'
			. 'if(e.detail.statistics==="deny"){if(window.posthog&&window.posthog.opt_out_capturing){window.posthog.opt_out_capturing();}}'
			. 'else if(e.detail.statistics==="allow"){' . $allow_js . '}'
			. '});';
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
			'capture_pageview'          => (bool) $client['pageviews'],
			'disable_session_recording' => empty( $client['session_recording'] ),
			'person_profiles'           => (string) $client['person_profiles'],
		);

		if ( $force_cookieless || ! empty( $client['cookieless'] ) ) {
			$config['persistence'] = 'memory';
		}

		/**
		 * Filter the posthog-js init configuration before it is printed.
		 *
		 * @param array<string,mixed> $config The configuration array.
		 */
		return (array) apply_filters( 'tagbridge_posthog_js_config', $config );
	}

	/**
	 * Build the posthog.init( token, config ) call as a JS string.
	 *
	 * Both arguments are JSON-encoded, which safely escapes them for inline JS.
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
	 * This is PostHog's standard, publicly documented loader. The human-readable
	 * source lives in the posthog-js project:
	 * https://github.com/PostHog/posthog-js and https://posthog.com/docs/libraries/js
	 *
	 * It loads /static/array.js from the assets host derived from api_host (the
	 * snippet swaps ".i.posthog.com" for "-assets.i.posthog.com"; for custom
	 * hosts the string is unchanged and it loads from that host directly).
	 *
	 * @return string
	 */
	private function loader() {
		return <<<'JS'
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
JS;
	}
}
