<?php
/**
 * @brief        Replay Polar webhook events task
 * @author       <a href='https://xenntec.com/'>XENNTEC UG</a>
 * @copyright    (c) 2026 XENNTEC UG
 * @package      Invision Community
 * @subpackage   X Polar Checkout
 * @since        16 Feb 2026
 */

namespace IPS\xpolarcheckout\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

/**
 * Replay webhook events task.
 */
class _webhookReplay extends \IPS\Task
{
    /**
     * @brief Store key for replay state.
     */
    const REPLAY_STATE_STORE_KEY = 'xpolarcheckout_webhook_replay_state';

    /**
     * @brief Replay defaults / hard limits.
     */
    const DEFAULT_LOOKBACK_SECONDS = 3600;
    const REPLAY_OVERLAP_SECONDS = 300;
    const MAX_EVENTS_PER_RUN = 100;
    const MAX_PAGES_PER_RUN = 10;
    const MAX_RUNTIME_SECONDS = 120;

    /**
     * Execute.
     *
     * @param bool $dryRun If TRUE, fetch and filter replay candidates without forwarding or state mutation.
     * @return mixed
     * @throws \IPS\Task\Exception
     */
    public function execute( $dryRun = FALSE )
    {
        $settings = $this->loadGatewaySettings();
        if ( $settings === NULL )
        {
            return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
        }

        if ( empty( $settings['access_token'] ) || empty( $settings['webhook_secret'] ) )
        {
            return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
        }

        $lookback = isset( $settings['replay_lookback'] ) ? (int) $settings['replay_lookback'] : static::DEFAULT_LOOKBACK_SECONDS;
        $overlap = isset( $settings['replay_overlap'] ) ? (int) $settings['replay_overlap'] : static::REPLAY_OVERLAP_SECONDS;
        $maxEvents = isset( $settings['replay_max_events'] ) ? (int) $settings['replay_max_events'] : static::MAX_EVENTS_PER_RUN;

        $lookback = \max( 300, \min( 86400, $lookback ) );
        $overlap = \max( 60, \min( 1800, $overlap ) );
        $maxEvents = \max( 10, \min( static::MAX_EVENTS_PER_RUN, $maxEvents ) );

        $state = $this->loadReplayState();
        $windowStart = $this->resolveReplayWindowStart( $state, $lookback, $overlap );
        $windowEnd = \time();

        try
        {
            $deliveries = $this->fetchWebhookDeliveriesPaginated( $settings, $windowStart, $windowEnd, $maxEvents );
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

        $candidates = $this->extractReplayCandidates( $deliveries, $maxEvents );
        if ( !\count( $candidates ) )
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

        $replayed = 0;
        $maxCreated = (int) $windowStart;
        $maxEventId = NULL;
        $dryRunEvents = array();

        foreach ( $candidates as $candidate )
        {
            $event = $candidate['webhook_event'];
            $eventId = (string) $event['id'];
            $eventType = (string) $event['type'];
            $payload = (string) $event['payload'];
            $eventCreatedTs = $this->safeUnixTimestamp( isset( $event['created_at'] ) ? $event['created_at'] : NULL );

            if ( $dryRun )
            {
                $dryRunEvents[] = array(
                    'id' => $eventId,
                    'type' => $eventType,
                    'created' => $eventCreatedTs,
                    'delivery_id' => (string) $candidate['delivery_id'],
                    'http_code' => $candidate['http_code'],
                    'succeeded' => (bool) $candidate['succeeded'],
                );
            }
            else
            {
                $this->forwardEventToWebhook( $payload, $eventId, $settings['webhook_secret'] );
            }

            $replayed++;
            if ( $eventCreatedTs >= $maxCreated )
            {
                $maxCreated = $eventCreatedTs;
                $maxEventId = $eventId;
            }
        }

        if ( $dryRun )
        {
            return array(
                'count' => $replayed,
                'events' => $dryRunEvents,
            );
        }

        $this->saveReplayState( array(
            'last_run_at' => \time(),
            'last_event_created' => \max( $maxCreated, (int) $windowStart ),
            'last_event_id' => $maxEventId,
            'last_replayed_count' => $replayed,
        ) );

        return $replayed ? "Replayed {$replayed} Polar webhook event(s)." : NULL;
    }

    /**
     * Cleanup.
     *
     * @return void
     */
    public function cleanup()
    {
    }

    /**
     * Resolve gateway settings for active XPolarCheckout gateway.
     *
     * @return array|NULL
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
     * Resolve replay window start.
     *
     * @param array $state
     * @param int   $lookback
     * @param int   $overlap
     * @return int
     */
    protected function resolveReplayWindowStart( array $state, $lookback = 3600, $overlap = 300 )
    {
        if ( isset( $state['last_event_created'] ) && \is_numeric( $state['last_event_created'] ) )
        {
            return \max( 0, (int) $state['last_event_created'] - $overlap );
        }

        return \max( 0, \time() - $lookback );
    }

    /**
     * Fetch webhook deliveries from Polar API with pagination.
     *
     * @param array $settings
     * @param int   $windowStart
     * @param int   $windowEnd
     * @param int   $perPage
     * @return array
     * @throws \IPS\Task\Exception
     */
    protected function fetchWebhookDeliveriesPaginated( array $settings, $windowStart, $windowEnd, $perPage = 100 )
    {
        $allDeliveries = array();
        $baseUrl = $this->resolveApiBase( $settings ) . '/webhooks/deliveries';
        $startedAt = \time();
        $maxPage = 1;

        for ( $page = 1; $page <= static::MAX_PAGES_PER_RUN && $page <= $maxPage; $page++ )
        {
            if ( ( \time() - $startedAt ) >= static::MAX_RUNTIME_SECONDS )
            {
                break;
            }

            $query = array(
                'start_timestamp' => \gmdate( 'Y-m-d\TH:i:s\Z', (int) $windowStart ),
                'end_timestamp' => \gmdate( 'Y-m-d\TH:i:s\Z', (int) $windowEnd ),
                'succeeded' => 'false',
                'page' => $page,
                'limit' => $perPage,
            );
            if ( !empty( $settings['webhook_endpoint_id'] ) )
            {
                $query['endpoint_id'] = (string) $settings['webhook_endpoint_id'];
            }

            try
            {
                $response = \IPS\Http\Url::external( $baseUrl )
                    ->setQueryString( $query )
                    ->request( 20 )
                    ->setHeaders( array(
                        'Authorization' => 'Bearer ' . $settings['access_token'],
                        'Accept' => 'application/json',
                    ) )
                    ->get()
                    ->decodeJson();
            }
            catch ( \Exception $e )
            {
                throw new \IPS\Task\Exception( $this, 'Unable to fetch Polar webhook deliveries: ' . $e->getMessage() );
            }

            if ( !\is_array( $response ) || !isset( $response['items'] ) || !\is_array( $response['items'] ) )
            {
                break;
            }

            foreach ( $response['items'] as $delivery )
            {
                if ( \is_array( $delivery ) )
                {
                    $allDeliveries[] = $delivery;
                }
            }

            if ( isset( $response['pagination']['max_page'] ) && \is_numeric( $response['pagination']['max_page'] ) )
            {
                $maxPage = (int) $response['pagination']['max_page'];
            }
        }

        return $allDeliveries;
    }

    /**
     * Extract replayable candidates from webhook deliveries.
     *
     * @param array $deliveries
     * @param int   $maxEvents
     * @return array
     */
    protected function extractReplayCandidates( array $deliveries, $maxEvents )
    {
        $requiredTypes = \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS;
        $byEventId = array();

        foreach ( $deliveries as $delivery )
        {
            if ( !isset( $delivery['webhook_event'] ) || !\is_array( $delivery['webhook_event'] ) )
            {
                continue;
            }

            $event = $delivery['webhook_event'];
            if ( empty( $event['id'] ) || empty( $event['type'] ) )
            {
                continue;
            }

            $eventType = (string) $event['type'];
            if ( !\in_array( $eventType, $requiredTypes, TRUE ) )
            {
                continue;
            }

            if ( !isset( $event['payload'] ) || !\is_string( $event['payload'] ) || $event['payload'] === '' )
            {
                continue;
            }

            if ( isset( $event['is_archived'] ) && $event['is_archived'] )
            {
                continue;
            }

            $eventId = (string) $event['id'];
            $created = $this->safeUnixTimestamp( isset( $event['created_at'] ) ? $event['created_at'] : NULL );
            $deliveryCreated = $this->safeUnixTimestamp( isset( $delivery['created_at'] ) ? $delivery['created_at'] : NULL );

            $candidate = array(
                'delivery_id' => isset( $delivery['id'] ) ? (string) $delivery['id'] : '',
                'replay_sort_ts' => $created > 0 ? $created : $deliveryCreated,
                'http_code' => isset( $delivery['http_code'] ) && \is_numeric( $delivery['http_code'] ) ? (int) $delivery['http_code'] : NULL,
                'succeeded' => !empty( $delivery['succeeded'] ),
                'webhook_event' => $event,
            );

            if ( !isset( $byEventId[ $eventId ] ) || $candidate['replay_sort_ts'] > $byEventId[ $eventId ]['replay_sort_ts'] )
            {
                $byEventId[ $eventId ] = $candidate;
            }
        }

        $candidates = \array_values( $byEventId );
        \usort( $candidates, function( $a, $b ) {
            $aTs = isset( $a['replay_sort_ts'] ) ? (int) $a['replay_sort_ts'] : 0;
            $bTs = isset( $b['replay_sort_ts'] ) ? (int) $b['replay_sort_ts'] : 0;
            if ( $aTs === $bTs )
            {
                $aId = isset( $a['delivery_id'] ) ? (string) $a['delivery_id'] : '';
                $bId = isset( $b['delivery_id'] ) ? (string) $b['delivery_id'] : '';
                return \strcmp( $aId, $bId );
            }
            return $aTs < $bTs ? -1 : 1;
        } );

        if ( \count( $candidates ) > $maxEvents )
        {
            $candidates = \array_slice( $candidates, 0, $maxEvents );
        }

        return $candidates;
    }

    /**
     * Forward one replay payload to local webhook endpoint with valid Standard Webhooks headers.
     *
     * @param string $payload
     * @param string $eventId
     * @param string $webhookSecret
     * @return void
     * @throws \IPS\Task\Exception
     */
    protected function forwardEventToWebhook( $payload, $eventId, $webhookSecret )
    {
        if ( !\is_string( $payload ) || $payload === '' )
        {
            throw new \IPS\Task\Exception( $this, 'Unable to replay empty webhook payload.' );
        }

        $timestamp = (string) \time();
        $secretBytes = $this->normalizeWebhookSecret( $webhookSecret );
        $signedPayload = (string) $eventId . '.' . $timestamp . '.' . $payload;
        $signature = \base64_encode( \hash_hmac( 'sha256', $signedPayload, $secretBytes, TRUE ) );

        $headers = array(
            'Content-Type' => 'application/json',
            'webhook-id' => (string) $eventId,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,' . $signature,
        );

        $webhookUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=webhook&controller=webhook', 'front' );
        $allowInsecureTls = ( \defined( '\IPS\IN_DEV' ) && \IPS\IN_DEV );

        try
        {
            $request = \IPS\Http\Url::external( (string) $webhookUrl )
                ->request( 20 )
                ->setHeaders( $headers )
                ->sslCheck( !$allowInsecureTls );
            $response = $request->post( $payload );
        }
        catch ( \Exception $e )
        {
            throw new \IPS\Task\Exception( $this, 'Replay delivery failed: ' . $e->getMessage() );
        }

        $statusCode = (int) $response->httpResponseCode;
        if ( $statusCode !== 200 )
        {
            throw new \IPS\Task\Exception( $this, 'Replay delivery returned HTTP ' . $statusCode . ' with body: ' . (string) $response );
        }
    }

    /**
     * Normalize webhook secret to raw bytes for HMAC.
     *
     * @param string $secret
     * @return string
     */
    protected function normalizeWebhookSecret( $secret )
    {
        $material = \trim( (string) $secret );
        if ( \strpos( $material, 'whsec_' ) === 0 )
        {
            $material = (string) \substr( $material, 6 );
        }

        if ( \ctype_xdigit( $material ) && ( \strlen( $material ) % 2 ) === 0 )
        {
            $hexDecoded = \hex2bin( $material );
            if ( $hexDecoded !== FALSE )
            {
                return $hexDecoded;
            }
        }

        $decoded = \base64_decode( $material, TRUE );
        if ( $decoded !== FALSE )
        {
            return $decoded;
        }

        return $material;
    }

    /**
     * Resolve API base URL from settings.
     *
     * @param array $settings
     * @return string
     */
    protected function resolveApiBase( array $settings )
    {
        $environment = isset( $settings['environment'] ) ? (string) $settings['environment'] : 'sandbox';
        return ( $environment === 'production' ) ? 'https://api.polar.sh/v1' : 'https://sandbox-api.polar.sh/v1';
    }

    /**
     * Parse arbitrary timestamp value into unix epoch.
     *
     * @param mixed $value
     * @return int
     */
    protected function safeUnixTimestamp( $value )
    {
        if ( \is_numeric( $value ) )
        {
            return (int) $value;
        }

        if ( \is_string( $value ) && $value !== '' )
        {
            $ts = \strtotime( $value );
            if ( $ts !== FALSE )
            {
                return (int) $ts;
            }
        }

        return 0;
    }

    /**
     * Load replay state from datastore.
     *
     * @return array
     */
    protected function loadReplayState()
    {
        if ( isset( \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} ) && \is_array( \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} ) )
        {
            return \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY};
        }

        return array();
    }

    /**
     * Save replay state to datastore.
     *
     * @param array $state
     * @return void
     */
    protected function saveReplayState( array $state )
    {
        \IPS\Data\Store::i()->{static::REPLAY_STATE_STORE_KEY} = $state;
    }
}
