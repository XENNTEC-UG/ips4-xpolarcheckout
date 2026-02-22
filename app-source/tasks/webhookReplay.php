<?php
/**
 * @brief		Replay Stripe webhook events task
 * @author		<a href='https://xenntec.com/'>XENNTEC UG</a>
 * @copyright	(c) 2026 XENNTEC UG
 * @package		Invision Community
 * @subpackage	X Polar Checkout
 * @since		16 Feb 2026
 */

namespace IPS\xpolarcheckout\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Replay Stripe webhook events task
 */
class _webhookReplay extends \IPS\Task
{
	/**
	 * @brief	Store key for replay state
	 */
	const REPLAY_STATE_STORE_KEY = 'xpolarcheckout_webhook_replay_state';

	/**
	 * Stripe events to replay are defined in the gateway constant:
	 * \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS
	 */

	/**
	 * @brief	Default replay lookback window in seconds (fallback when no gateway setting)
	 */
	const DEFAULT_LOOKBACK_SECONDS = 3600;

	/**
	 * @brief	Event overlap in seconds to avoid missing boundary events (fallback)
	 */
	const REPLAY_OVERLAP_SECONDS = 300;

	/**
	 * @brief	Maximum events pulled per API page (fallback)
	 */
	const MAX_EVENTS_PER_RUN = 100;

	/**
	 * @brief	Maximum pagination pages per run (hard guardrail)
	 */
	const MAX_PAGES_PER_RUN = 10;

	/**
	 * @brief	Maximum runtime per run in seconds (hard guardrail)
	 */
	const MAX_RUNTIME_SECONDS = 120;

	/**
	 * Execute
	 *
	 * @param	bool	$dryRun		If TRUE, fetch and filter events without forwarding or saving state
	 * @return	mixed				String message (normal), array (dry-run), or NULL
	 * @throws	\IPS\Task\Exception
	 */
	public function execute( $dryRun = FALSE )
	{
		$settings = $this->loadGatewaySettings();
		if ( $settings === NULL )
		{
			return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
		}

		if ( empty( $settings['secret'] ) OR empty( $settings['webhook_secret'] ) )
		{
			return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
		}

		/* Resolve configurable values with safe clamping */
		$lookback = isset( $settings['replay_lookback'] ) ? (int) $settings['replay_lookback'] : static::DEFAULT_LOOKBACK_SECONDS;
		$overlap = isset( $settings['replay_overlap'] ) ? (int) $settings['replay_overlap'] : static::REPLAY_OVERLAP_SECONDS;
		$maxEvents = isset( $settings['replay_max_events'] ) ? (int) $settings['replay_max_events'] : static::MAX_EVENTS_PER_RUN;

		$lookback = \max( 300, \min( 86400, $lookback ) );
		$overlap = \max( 60, \min( 1800, $overlap ) );
		$maxEvents = \max( 10, \min( 100, $maxEvents ) );

		$state = $this->loadReplayState();
		$windowStart = $this->resolveReplayWindowStart( $state, $lookback, $overlap );

		/* Wrap Stripe API call so that state is always saved (prevents false "stale" alerts when API is unreachable) */
		try
		{
			$events = $this->fetchStripeEventsPaginated( $settings['secret'], $windowStart, $maxEvents );
		}
		catch ( \IPS\Task\Exception $e )
		{
			if ( !$dryRun )
			{
				$this->saveReplayState( array(
					'last_run_at' => \time(),
					'last_event_created' => isset( $state['last_event_created'] ) ? $state['last_event_created'] : $windowStart,
					'last_event_id' => isset( $state['last_event_id'] ) ? $state['last_event_id'] : NULL,
					'last_replayed_count' => 0,
				) );
			}

			throw $e;
		}

		if ( !\count( $events ) )
		{
			if ( !$dryRun )
			{
				$this->saveReplayState( array(
					'last_run_at' => \time(),
					'last_event_created' => \max( $windowStart, \time() ),
					'last_event_id' => isset( $state['last_event_id'] ) ? $state['last_event_id'] : NULL,
					'last_replayed_count' => 0,
				) );
			}

			return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
		}

		\usort( $events, function( $a, $b ) {
			$aCreated = isset( $a['created'] ) ? (int) $a['created'] : 0;
			$bCreated = isset( $b['created'] ) ? (int) $b['created'] : 0;
			if ( $aCreated === $bCreated )
			{
				$aId = isset( $a['id'] ) ? (string) $a['id'] : '';
				$bId = isset( $b['id'] ) ? (string) $b['id'] : '';
				return \strcmp( $aId, $bId );
			}
			return $aCreated < $bCreated ? -1 : 1;
		} );

		$replayed = 0;
		$maxCreated = (int) $windowStart;
		$maxEventId = NULL;
		$dryRunEvents = array();

		foreach ( $events as $event )
		{
			if ( !isset( $event['type'] ) OR !\in_array( $event['type'], \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS, TRUE ) )
			{
				continue;
			}

			if ( $dryRun )
			{
				$dryRunEvents[] = array(
					'id' => isset( $event['id'] ) ? (string) $event['id'] : '',
					'type' => (string) $event['type'],
					'created' => isset( $event['created'] ) ? (int) $event['created'] : 0,
				);
			}
			else
			{
				$this->forwardEventToWebhook( $event, $settings['webhook_secret'] );
			}

			$replayed++;

			$created = isset( $event['created'] ) ? (int) $event['created'] : 0;
			if ( $created >= $maxCreated )
			{
				$maxCreated = $created;
				$maxEventId = isset( $event['id'] ) ? $event['id'] : $maxEventId;
			}
		}

		if ( $dryRun )
		{
			return array( 'count' => $replayed, 'events' => $dryRunEvents );
		}

		$this->saveReplayState( array(
			'last_run_at' => \time(),
			'last_event_created' => \max( $maxCreated, (int) $windowStart ),
			'last_event_id' => $maxEventId,
			'last_replayed_count' => $replayed,
		) );

		return $replayed ? "Replayed {$replayed} Stripe webhook event(s)." : NULL;
	}

	/**
	 * Cleanup
	 *
	 * @return	void
	 */
	public function cleanup()
	{
	}

	/**
	 * Resolve gateway settings for the active XPolarCheckout gateway.
	 *
	 * @return	array|NULL
	 */
	protected function loadGatewaySettings()
	{
		foreach ( \IPS\nexus\Gateway::roots() as $gateway )
		{
			if ( $gateway instanceof \IPS\xpolarcheckout\XPolarCheckout )
			{
				$settings = \json_decode( $gateway->settings, TRUE );
				return \is_array( $settings ) ? $settings : NULL;
			}
		}

		return NULL;
	}

	/**
	 * Load replay state from datastore.
	 *
	 * @return	array
	 */
	protected function loadReplayState()
	{
		if ( isset( \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} ) AND \is_array( \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} ) )
		{
			return \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY};
		}

		return array();
	}

	/**
	 * Save replay state to datastore.
	 *
	 * @param	array	$state	State
	 * @return	void
	 */
	protected function saveReplayState( array $state )
	{
		\IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} = $state;
	}

	/**
	 * Resolve replay window start timestamp.
	 *
	 * @param	array	$state		Replay state
	 * @param	int		$lookback	Lookback window in seconds
	 * @param	int		$overlap	Overlap window in seconds
	 * @return	int
	 */
	protected function resolveReplayWindowStart( array $state, $lookback = 3600, $overlap = 300 )
	{
		if ( isset( $state['last_event_created'] ) AND \is_numeric( $state['last_event_created'] ) )
		{
			return \max( 0, (int) $state['last_event_created'] - $overlap );
		}

		return \max( 0, \time() - $lookback );
	}

	/**
	 * Fetch Stripe events with pagination support.
	 *
	 * Follows `has_more` + `starting_after` until all events in the window are fetched,
	 * bounded by MAX_PAGES_PER_RUN and MAX_RUNTIME_SECONDS to prevent runaway loops.
	 *
	 * @param	string	$secret			Stripe secret key
	 * @param	int		$windowStart	Unix timestamp
	 * @param	int		$perPage		Events per API page
	 * @return	array
	 */
	protected function fetchStripeEventsPaginated( $secret, $windowStart, $perPage = 100 )
	{
		$allEvents = array();
		$startingAfter = NULL;
		$startTime = \time();

		for ( $page = 0; $page < static::MAX_PAGES_PER_RUN; $page++ )
		{
			if ( ( \time() - $startTime ) >= static::MAX_RUNTIME_SECONDS )
			{
				break;
			}

			$queryParams = array(
				'limit' => $perPage,
				'created[gte]' => (int) $windowStart,
			);
			foreach ( \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS as $i => $eventType )
			{
				$queryParams['types[' . $i . ']'] = $eventType;
			}
			if ( $startingAfter !== NULL )
			{
				$queryParams['starting_after'] = $startingAfter;
			}

			$url = \IPS\Http\Url::external( 'https://api.stripe.com/v1/events' )->setQueryString( $queryParams );

			try
			{
				$response = $url->request( 20 )
					->setHeaders( array(
						'Authorization' => 'Bearer ' . $secret,
						'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION,
					) )
					->get()
					->decodeJson();
			}
			catch ( \Exception $e )
			{
				throw new \IPS\Task\Exception( $this, 'Unable to fetch Stripe events for replay: ' . $e->getMessage() );
			}

			if ( !\is_array( $response ) OR !isset( $response['data'] ) OR !\is_array( $response['data'] ) OR !\count( $response['data'] ) )
			{
				break;
			}

			foreach ( $response['data'] as $event )
			{
				$allEvents[] = $event;
			}

			$lastEvent = \end( $response['data'] );
			$startingAfter = isset( $lastEvent['id'] ) ? (string) $lastEvent['id'] : NULL;

			if ( empty( $response['has_more'] ) OR $startingAfter === NULL )
			{
				break;
			}
		}

		return $allEvents;
	}

	/**
	 * Forward one Stripe event payload to local webhook endpoint with valid signature.
	 *
	 * @param	array	$eventPayload		Event payload
	 * @param	string	$webhookSecret	Webhook signing secret
	 * @return	void
	 */
	protected function forwardEventToWebhook( array $eventPayload, $webhookSecret )
	{
		$body = \json_encode( $eventPayload );
		if ( !\is_string( $body ) )
		{
			throw new \IPS\Task\Exception( $this, 'Unable to encode Stripe event payload for replay.' );
		}

		$timestamp = \time();
		$signature = 't=' . $timestamp . ',v1=' . \hash_hmac( 'sha256', $timestamp . '.' . $body, $webhookSecret );
		$webhookUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=webhook&controller=webhook', 'front' );
		$allowInsecureTls = ( \defined( '\IPS\IN_DEV' ) AND \IPS\IN_DEV );

		try
		{
			$request = \IPS\Http\Url::external( (string) $webhookUrl )
				->request( 20 )
				->setHeaders( array(
					'Content-Type' => 'application/json',
					'Stripe-Signature' => $signature,
				) )
				->sslCheck( !$allowInsecureTls );
			$response = $request->post( $body );
		}
		catch ( \Exception $e )
		{
			throw new \IPS\Task\Exception( $this, 'Replay delivery failed: ' . $e->getMessage() );
		}

		$statusCode = (int) $response->httpResponseCode;
		$responseBody = (string) $response;

		if ( $statusCode !== 200 )
		{
			throw new \IPS\Task\Exception( $this, 'Replay delivery returned HTTP ' . $statusCode . ' with body: ' . (string) $responseBody );
		}
	}
}
