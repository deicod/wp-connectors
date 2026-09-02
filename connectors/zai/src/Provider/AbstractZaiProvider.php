<?php
/**
 * Shared z.ai provider scaffolding for both surfaces (GLM1 #11).
 *
 * The credentials-portal selection and the SDK-version metadata ladder were
 * duplicated between ZaiProvider and ZaiAnthropicProvider; they live here
 * once now, parameterized by each child's display name, description, and
 * settings class.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;

/**
 * Base provider: region-following credentials URL and versioned metadata.
 *
 * @since 0.2.0
 */
abstract class AbstractZaiProvider extends AbstractApiProvider {

	/**
	 * Key-management portal for the international region (z.ai).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INTL_CREDENTIALS_URL = 'https://z.ai/manage/apikey/apikey';

	/**
	 * Key-management portal for the China region (open.bigmodel.cn).
	 *
	 * Regions use separate accounts and separate API keys (SPEC §3.3), so
	 * the advertised link must follow the selected region — a China admin
	 * sent to the z.ai portal lands on an account their key can never
	 * live in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CN_CREDENTIALS_URL = 'https://open.bigmodel.cn/usercenter/apikeys';

	/**
	 * Connector ID used by core (the key option/env name derives from it).
	 *
	 * Overridden per provider child; the base value is the zai provider's
	 * (the same convention the settings and availability bases use).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVIDER_ID = 'zai';

	/**
	 * The provider's card display name.
	 *
	 * @since 0.2.0
	 *
	 * @return string Display name.
	 */
	abstract protected static function provider_display_name(): string;

	/**
	 * The provider's translated description text (untranslated fallback
	 * when __() is unavailable).
	 *
	 * @since 0.2.0
	 *
	 * @return string Description.
	 */
	abstract protected static function provider_description(): string;

	/**
	 * The currently selected region of THIS provider's settings.
	 *
	 * @since 0.2.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	abstract protected static function selected_region(): string;

	/**
	 * Builds the provider metadata constructor arguments for an SDK version.
	 *
	 * Description requires SDK >= 1.2.0 and the logo path SDK >= 1.3.0; both
	 * are appended only when the given SDK version supports them (the guard
	 * pattern from the official provider plugin, architecture record 0003).
	 * The parameter defaults to the detected SDK version and exists so tests
	 * can cover the minimum and newer metadata shapes.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $sdk_version SDK version to build for, or null for AiClient::VERSION.
	 * @return list<mixed> Positional ProviderMetadata constructor arguments.
	 */
	public static function provider_metadata_args( ?string $sdk_version = null ): array {
		$version = $sdk_version ?? AiClient::VERSION;

		$args = array(
			static::PROVIDER_ID,
			static::provider_display_name(),
			ProviderTypeEnum::cloud(),
			static::credentials_url(),
			RequestAuthenticationMethod::apiKey(),
		);

		if ( version_compare( $version, '1.2.0', '>=' ) ) {
			$args[] = static::translated_description();
		}

		if ( version_compare( $version, '1.3.0', '>=' ) ) {
			$args[] = static::provider_logo_path();
		}

		return $args;
	}

	/**
	 * The key-management URL of the currently selected region's portal.
	 *
	 * The region option is read at metadata-build time (provider
	 * registration, once per request): like the rest of the provider
	 * metadata, a region change is reflected from the next request on.
	 *
	 * @since 0.2.0
	 *
	 * @return string Credentials portal URL for the current region.
	 */
	protected static function credentials_url(): string {
		return 'cn' === static::selected_region()
			? static::CN_CREDENTIALS_URL
			: static::INTL_CREDENTIALS_URL;
	}

	/**
	 * Returns the translated provider description.
	 *
	 * @since 0.2.0
	 *
	 * @return string Description text.
	 */
	protected static function translated_description(): string {
		if ( \function_exists( '__' ) ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the text is the child's fixed description literal.
			return __( static::provider_description(), 'zai' );
		}

		return static::provider_description();
	}

	/**
	 * The provider logo path (the shared z.ai mark for both surfaces).
	 *
	 * @since 0.2.0
	 *
	 * @return string Absolute file path.
	 */
	protected static function provider_logo_path(): string {
		return dirname( __DIR__, 2 ) . '/assets/zai.svg';
	}
}
