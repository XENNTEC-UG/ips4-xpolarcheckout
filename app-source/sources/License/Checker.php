<?php
/**
 * XENNTEC License Checker — do not modify
 *
 * @package     X Polar Checkout
 * @copyright   (c) 2026 XENNTEC UG
 */

namespace IPS\xpolarcheckout\License;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * License status checker for XENNTEC apps.
 *
 * Verifies the installed license key against the XENNTEC license server and
 * caches results locally to minimise outbound requests. The cache is HMAC-signed
 * and domain-bound so it cannot be moved to another site or hand-crafted.
 */
class _Checker
{
	// -------------------------------------------------------------------------
	// Configuration tokens — replaced per-app at copy time
	// -------------------------------------------------------------------------

	/**
	 * URL of the shared license endpoint config published in the update repo.
	 * This is the ONLY hardcoded external URL in the entire system.
	 */
	private const CFG_URL = 'https://raw.githubusercontent.com/XENNTEC-UG/ips4-updates/main/license-config.json';

	/**
	 * IPS4 setting key — stores the serialised cache payload for this app.
	 */
	private const SETTING_CACHE = 'xenntec_lic_xpolarcheckout_cache';

	/**
	 * IPS4 setting key — stores the resolved endpoint config (shared across apps).
	 */
	private const SETTING_CONFIG = 'xenntec_lic_config';

	/**
	 * IPS4 setting key — stores the license key for this app.
	 */
	private const SETTING_KEY = 'xenntec_lic_xpolarcheckout_key';

	/**
	 * Scope identifier sent to the license server. Identifies which product is
	 * being verified. Replaced with the app directory name at copy time.
	 */
	private const APP_SCOPE = 'xpolarcheckout';

	/**
	 * HMAC secret used to sign the local cache. Must be replaced with a unique
	 * 32-character hex string when copying this file to a new app.
	 */
	private const HMAC_SECRET = 'c51944354a320a8e3efc810ef9cc8fe2';

	// -------------------------------------------------------------------------
	// Status constants (public — used by controllers and templates)
	// -------------------------------------------------------------------------

	public const STATUS_VALID    = 'valid';
	public const STATUS_EXPIRING = 'expiring';
	public const STATUS_GRACE    = 'grace';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_INVALID  = 'invalid';
	public const STATUS_MISSING  = 'missing';

	// -------------------------------------------------------------------------
	// Public static API
	// -------------------------------------------------------------------------

	/**
	 * Return the current license status for this app.
	 *
	 * Reads the local cache when it is still fresh; performs a remote check when
	 * the cache has expired; falls back gracefully on network errors.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function getStatus(): string
	{
		// Step 1 — no key at all
		$licenseKey = (string) \IPS\Settings::i()->{static::SETTING_KEY};
		if ( $licenseKey === '' )
		{
			return static::STATUS_MISSING;
		}

		$domain    = (string) parse_url( (string) \IPS\Settings::i()->base_url, PHP_URL_HOST );
		$cacheJson = (string) \IPS\Settings::i()->{static::SETTING_CACHE};
		$cache     = null;

		// Step 2 — decode existing cache
		if ( $cacheJson !== '' )
		{
			$decoded = json_decode( $cacheJson, true );
			if ( is_array( $decoded ) )
			{
				$cache = $decoded;
			}
		}

		// Step 3 — validate and use cache if still fresh
		if ( $cache !== null )
		{
			// Verify HMAC — protects against domain changes and tampering
			$expectedSig = hash_hmac( 'sha256', static::canonicalJson( $cache ), static::HMAC_SECRET . $domain );
			if ( !hash_equals( $expectedSig, (string) ( $cache['sig'] ?? '' ) ) )
			{
				static::clearCache();
				return static::STATUS_MISSING;
			}

			// Cache still fresh — derive status from stored data
			if ( time() < (int) $cache['cache_until'] )
			{
				return static::deriveStatus( $cache );
			}
		}

		// Step 4/5 — cache expired (or absent), need a remote check
		$config = static::loadServerConfig();
		if ( $config === null )
		{
			// Config fetch failed; fall back on existing cache
			return static::fallbackStatus( $cache );
		}

		// Step 6 — build and send the verification request
		$ts    = time();
		$nonce = bin2hex( random_bytes( 8 ) );
		$sig   = hash_hmac( 'sha256', $nonce . $ts . $domain . $licenseKey, static::HMAC_SECRET );

		$payload = json_encode( [
			'key'    => $licenseKey,
			'domain' => $domain,
			'scope'  => static::APP_SCOPE,
			'ts'     => $ts,
			'nonce'  => $nonce,
			'sig'    => $sig,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$endpoint = (string) ( $config['endpoints'][0] ?? '' );
		if ( $endpoint === '' )
		{
			return static::fallbackStatus( $cache );
		}

		try
		{
			$response = \IPS\Http\Url::external( $endpoint )
				->request( 8 )
				->setHeaders( [ 'Content-Type' => 'application/json' ] )
				->post( $payload );

			$httpCode = (int) $response->httpResponseCode;

			// Step 7 — process a successful response
			if ( $httpCode === 200 )
			{
				$body = json_decode( (string) $response, true );

				if ( is_array( $body ) && isset( $body['valid'] ) )
				{
					$expiresAt = null;

					if ( !empty( $body['license']['expirationDate'] ) )
					{
						$parsed = strtotime( (string) $body['license']['expirationDate'] );
						if ( $parsed !== false )
						{
							$expiresAt = $parsed;
						}
					}

					if ( (bool) $body['valid'] )
					{
						$newStatus = static::deriveStatusFromExpiry( $expiresAt, $config );
					}
					else
					{
						$newStatus = static::STATUS_INVALID;
					}

					static::writeCache( $newStatus, $expiresAt, $config );
					return $newStatus;
				}
			}
		}
		catch ( \IPS\Http\Request\Exception | \RuntimeException $e )
		{
			// Network error — fall through to grace logic below
		}

		// Step 8 — network failure or unreadable response
		return static::fallbackStatus( $cache );
	}

	/**
	 * Return the full decoded cache array, or null when no valid cache exists.
	 *
	 * @return array|null
	 */
	public static function getCacheData(): ?array
	{
		$cacheJson = (string) \IPS\Settings::i()->{static::SETTING_CACHE};
		if ( $cacheJson === '' )
		{
			return null;
		}

		$cache = json_decode( $cacheJson, true );
		if ( !is_array( $cache ) )
		{
			return null;
		}

		$domain      = (string) parse_url( (string) \IPS\Settings::i()->base_url, PHP_URL_HOST );
		$expectedSig = hash_hmac( 'sha256', static::canonicalJson( $cache ), static::HMAC_SECRET . $domain );

		if ( !hash_equals( $expectedSig, (string) ( $cache['sig'] ?? '' ) ) )
		{
			return null;
		}

		return $cache;
	}

	/**
	 * Save a license key and immediately perform a remote verification.
	 *
	 * @param  string $licenseKey The key entered by the site administrator.
	 * @return string             The resulting status after verification.
	 */
	public static function activate( string $licenseKey ): string
	{
		\IPS\Settings::i()->changeValues( [ static::SETTING_KEY => trim( $licenseKey ) ] );
		static::clearCache();
		return static::getStatus();
	}

	/**
	 * Erase the local license cache.
	 *
	 * The next call to getStatus() will perform a fresh remote check.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		\IPS\Settings::i()->changeValues( [ static::SETTING_CACHE => '' ] );
	}

	/**
	 * Entry point called by the hourly IPS4 Task.
	 *
	 * Delegates entirely to getStatus(), which already handles cache expiry and
	 * will only perform a remote request when one is actually due.
	 *
	 * @return void
	 */
	public static function runCheck(): void
	{
		static::getStatus();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Derive a STATUS_* value from a decoded cache array.
	 *
	 * @param  array $cache Decoded cache payload.
	 * @return string
	 */
	private static function deriveStatus( array $cache ): string
	{
		if ( ( $cache['status'] ?? '' ) === static::STATUS_INVALID )
		{
			return static::STATUS_INVALID;
		}

		$expiresAt = isset( $cache['expires_at'] ) ? (int) $cache['expires_at'] : null;

		return static::deriveStatusFromExpiry( $expiresAt, [] );
	}

	/**
	 * Derive a STATUS_* value from an expiry timestamp and server config.
	 *
	 * @param  int|null $expiresAt  Unix timestamp of license expiry, or null for perpetual.
	 * @param  array    $config     Server config array (used for expiry_warning_days).
	 * @return string
	 */
	private static function deriveStatusFromExpiry( ?int $expiresAt, array $config ): string
	{
		if ( $expiresAt === null )
		{
			return static::STATUS_VALID;
		}

		if ( $expiresAt < time() )
		{
			return static::STATUS_EXPIRED;
		}

		$warningDays = (int) ( $config['expiry_warning_days'] ?? 7 );
		if ( ( $expiresAt - time() ) <= ( $warningDays * 86400 ) )
		{
			return static::STATUS_EXPIRING;
		}

		return static::STATUS_VALID;
	}

	/**
	 * Determine the appropriate fallback status when the license server is
	 * unreachable.
	 *
	 * @param  array|null $cache Previously decoded cache, or null if none existed.
	 * @return string
	 */
	private static function fallbackStatus( ?array $cache ): string
	{
		if ( $cache !== null )
		{
			// Still within the grace window — give benefit of the doubt
			if ( time() < (int) $cache['grace_until'] )
			{
				return static::STATUS_GRACE;
			}

			// Grace window elapsed — honour whatever the last known state was
			if ( ( $cache['status'] ?? '' ) === static::STATUS_INVALID )
			{
				return static::STATUS_INVALID;
			}

			$expiresAt = isset( $cache['expires_at'] ) ? (int) $cache['expires_at'] : null;
			if ( $expiresAt !== null && $expiresAt < time() )
			{
				return static::STATUS_EXPIRED;
			}
		}

		// No prior cache at all — benefit of the doubt
		return static::STATUS_GRACE;
	}

	/**
	 * Write a fresh cache entry to the IPS4 settings table.
	 *
	 * @param  string   $status    One of the STATUS_* constants.
	 * @param  int|null $expiresAt Unix timestamp of license expiry, or null.
	 * @param  array    $config    Server config array.
	 * @return void
	 */
	private static function writeCache( string $status, ?int $expiresAt, array $config ): void
	{
		$domain    = (string) parse_url( (string) \IPS\Settings::i()->base_url, PHP_URL_HOST );
		$graceDays = (int) ( $config['grace_days'] ?? 7 );

		$cache = [
			'status'      => $status,
			'domain'      => $domain,
			'app'         => static::APP_SCOPE,
			'expires_at'  => $expiresAt,
			'checked_at'  => time(),
			'cache_until' => static::nextCheckTime( $expiresAt, $config ),
			'grace_until' => time() + ( $graceDays * 86400 ),
		];

		$cache['sig'] = hash_hmac( 'sha256', static::canonicalJson( $cache ), static::HMAC_SECRET . $domain );

		\IPS\Settings::i()->changeValues( [ static::SETTING_CACHE => json_encode( $cache ) ] );
	}

	/**
	 * Calculate the unix timestamp of the next required remote check.
	 *
	 * Checks are scheduled more frequently as the license approaches expiry.
	 *
	 * @param  int|null $expiresAt Unix timestamp of license expiry, or null.
	 * @param  array    $config    Server config array.
	 * @return int                 Unix timestamp of next required check.
	 */
	private static function nextCheckTime( ?int $expiresAt, array $config ): int
	{
		$warningDays = (int) ( $config['expiry_warning_days'] ?? 7 );

		if ( $expiresAt !== null )
		{
			$remaining = $expiresAt - time();

			if ( $remaining <= 86400 )
			{
				// Less than one day remaining — check every hour
				return time() + 3600;
			}

			if ( $remaining <= ( $warningDays * 86400 ) )
			{
				// Within the warning window — check every hour
				return time() + 3600;
			}
		}

		// Perpetual license or far from expiry — check daily
		return time() + 86400;
	}

	/**
	 * Produce a deterministic JSON string suitable for HMAC signing.
	 *
	 * The 'sig' field is excluded so the signature covers only the payload.
	 * Keys are sorted alphabetically so insertion order does not matter.
	 *
	 * @param  array $data Payload to encode.
	 * @return string      Canonical JSON string.
	 */
	private static function canonicalJson( array $data ): string
	{
		unset( $data['sig'] );
		ksort( $data );
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Load (and if necessary refresh) the shared license server config.
	 *
	 * The config is cached in a dedicated IPS4 setting keyed by SETTING_CONFIG.
	 * Its own 'cache_hours' field controls how long it remains valid.
	 *
	 * @return array|null Decoded config array, or null on failure.
	 */
	private static function loadServerConfig(): ?array
	{
		$raw = (string) \IPS\Settings::i()->{static::SETTING_CONFIG};
		if ( $raw !== '' )
		{
			$stored = json_decode( $raw, true );
			if ( is_array( $stored ) && isset( $stored['config'], $stored['fetched_at'] ) )
			{
				$cacheHours = (int) ( $stored['config']['cache_hours'] ?? 24 );
				if ( time() < ( (int) $stored['fetched_at'] + ( $cacheHours * 3600 ) ) )
				{
					return $stored['config'];
				}
			}
		}

		// Fetch a fresh copy
		try
		{
			$response = \IPS\Http\Url::external( static::CFG_URL )->request( 5 )->get();
			if ( (int) $response->httpResponseCode === 200 )
			{
				$config = json_decode( (string) $response, true );
				if ( is_array( $config ) && !empty( $config['endpoints'] ) )
				{
					\IPS\Settings::i()->changeValues( [
						static::SETTING_CONFIG => json_encode( [
							'config'     => $config,
							'fetched_at' => time(),
						] ),
					] );
					return $config;
				}
			}
		}
		catch ( \IPS\Http\Request\Exception | \RuntimeException $e )
		{
			// Config fetch failed; return null so caller can fall back
		}

		// Return whatever we had stored (even if stale) rather than null
		if ( isset( $stored['config'] ) && is_array( $stored['config'] ) )
		{
			return $stored['config'];
		}

		return null;
	}
}
