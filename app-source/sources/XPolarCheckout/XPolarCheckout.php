<?php
/**
 * @brief        Polar Checkout Gateway
 * @author       https://xenntec.com/
 */

namespace IPS\xpolarcheckout;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

/**
 * X Polar Checkout Gateway
 */
class _XPolarCheckout extends \IPS\nexus\Gateway
{
    const SUPPORTS_REFUNDS = TRUE;
    const SUPPORTS_PARTIAL_REFUNDS = TRUE;
    const DEFAULT_PRESENTMENT_CURRENCY = 'eur';

    const POLAR_API_BASE_PRODUCTION = 'https://api.polar.sh/v1';
    const POLAR_API_BASE_SANDBOX = 'https://sandbox-api.polar.sh/v1';

    /**
     * @brief Webhook event types required by this gateway.
     */
    const REQUIRED_WEBHOOK_EVENTS = array(
        'order.created',
        'order.paid',
        'order.updated',
        'order.refunded',
        'refund.created',
        'refund.updated',
        'checkout.updated',
    );

    /**
     * Authorize transaction by creating a Polar checkout and redirecting the customer.
     *
     * @param   \IPS\nexus\Transaction                    $transaction    Transaction
     * @param   array|\IPS\nexus\Customer\CreditCard     $values         Values from form
     * @param   \IPS\nexus\Fraud\MaxMind\Request|NULL    $maxMind        Optional MaxMind request
     * @return  \IPS\DateTime|NULL
     * @throws  \LogicException
     */
    public function auth( \IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL )
    {
        $settings = \json_decode( $this->settings, TRUE );
        if ( !\is_array( $settings ) )
        {
            throw new \LogicException( 'xpolarcheckout_invalid_settings' );
        }

        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        $defaultProductId = isset( $settings['default_product_id'] ) ? \trim( (string) $settings['default_product_id'] ) : '';
        if ( $accessToken === '' || $defaultProductId === '' )
        {
            throw new \LogicException( 'xpolarcheckout_missing_required_settings' );
        }

        $transactionCurrency = \mb_strtolower( (string) $transaction->amount->currency );
        $presentmentCurrency = isset( $settings['presentment_currency'] ) ? \mb_strtolower( \trim( (string) $settings['presentment_currency'] ) ) : '';
        if ( $presentmentCurrency !== '' && $presentmentCurrency !== $transactionCurrency )
        {
            throw new \LogicException( 'xpolarcheckout_presentment_currency_mismatch' );
        }

        $apiBase = static::resolveApiBase( $settings );
        $amountMinor = $this->moneyToMinorUnit( $transaction->amount );

        $externalCustomerId = $transaction->member ? (string) (int) $transaction->member->member_id : '';
        $payload = array(
            'products' => array( $defaultProductId ),
            'currency' => $transactionCurrency,
            'success_url' => (string) $transaction->url()->setQueryString( 'pending', 1 ),
            'metadata' => array(
                'ips_transaction_id' => (string) (int) $transaction->id,
                'ips_invoice_id' => (string) (int) $transaction->invoice->id,
                'ips_member_id' => $externalCustomerId,
                'gateway_id' => (string) (int) $this->id,
            ),
            'prices' => array(
                $defaultProductId => array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => (int) $amountMinor,
                        'price_currency' => $transactionCurrency,
                    ),
                ),
            ),
        );

        if ( $externalCustomerId !== '' )
        {
            $payload['external_customer_id'] = $externalCustomerId;
        }

        $encoded = \json_encode( $payload );
        if ( !\is_string( $encoded ) )
        {
            throw new \LogicException( 'xpolarcheckout_payload_encode_failed' );
        }

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/checkouts/' )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ) )
                ->post( $encoded )
                ->decodeJson();
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_auth' );
            throw new \LogicException( 'gateway_err' );
        }

        $checkoutUrl = ( \is_array( $response ) && isset( $response['url'] ) && \is_string( $response['url'] ) ) ? $response['url'] : '';
        if ( $checkoutUrl === '' )
        {
            throw new \LogicException( 'gateway_err' );
        }

        \IPS\Output::i()->redirect( \IPS\Http\Url::external( $checkoutUrl ) );
        return NULL;
    }

    /**
     * Basic gateway validity checks.
     *
     * @param   \IPS\nexus\Money          $amount
     * @param   \IPS\GeoLocation|NULL      $billingAddress
     * @param   \IPS\nexus\Customer|NULL  $customer
     * @param   array                       $recurrings
     * @return  bool|string
     */
    public function checkValidity( \IPS\nexus\Money $amount, \IPS\GeoLocation $billingAddress = NULL, \IPS\nexus\Customer $customer = NULL, $recurrings = array() )
    {
        $settings = \json_decode( $this->settings, TRUE );
        if ( !\is_array( $settings ) )
        {
            return 'gateway_err';
        }

        if ( empty( $settings['access_token'] ) || empty( $settings['default_product_id'] ) )
        {
            return 'xpolarcheckout_missing_required_settings';
        }

        $presentmentCurrency = isset( $settings['presentment_currency'] ) ? \mb_strtolower( \trim( (string) $settings['presentment_currency'] ) ) : '';
        $amountCurrency = \mb_strtolower( (string) $amount->currency );
        if ( $presentmentCurrency !== '' && $presentmentCurrency !== $amountCurrency )
        {
            return 'xpolarcheckout_presentment_currency_mismatch';
        }

        return TRUE;
    }

    /**
     * Resolve customer reference payload for checkout creation.
     *
     * @param   mixed   $transaction Transaction object
     * @param   array   $settings
     * @return  array
     */
    public function getCustomer( $transaction, $settings )
    {
        $memberId = 0;
        if ( $transaction instanceof \IPS\nexus\Transaction && $transaction->member )
        {
            $memberId = (int) $transaction->member->member_id;
        }

        return array(
            'external_customer_id' => $memberId > 0 ? (string) $memberId : '',
        );
    }

    /**
     * Gateway settings form.
     *
     * @param   \IPS\Helpers\Form  $form
     * @return  void
     */
    public function settings( &$form )
    {
        $current = \json_decode( $this->settings, TRUE );
        if ( !\is_array( $current ) )
        {
            $current = array();
        }

        $form->addHeader( 'xpolarcheckout_credits' );
        $form->add( new \IPS\Helpers\Form\Select( 'xpolarcheckout_environment', isset( $current['environment'] ) ? $current['environment'] : 'sandbox', FALSE, array(
            'options' => array(
                'sandbox' => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_environment_sandbox' ),
                'production' => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_environment_production' ),
            ),
        ) ) );
        $form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_access_token', isset( $current['access_token'] ) ? $current['access_token'] : '', FALSE ) );
        $form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_default_product_id', isset( $current['default_product_id'] ) ? $current['default_product_id'] : '', FALSE ) );
        $form->add( new \IPS\Helpers\Form\Text(
            'xpolarcheckout_presentment_currency',
            isset( $current['presentment_currency'] ) ? \mb_strtoupper( (string) $current['presentment_currency'] ) : \mb_strtoupper( static::DEFAULT_PRESENTMENT_CURRENCY ),
            FALSE,
            array( 'maxLength' => 3 )
        ) );
        $form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_webhook_secret', isset( $current['webhook_secret'] ) ? $current['webhook_secret'] : '', FALSE ) );
        $form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_lookback', isset( $current['replay_lookback'] ) ? (int) $current['replay_lookback'] : 3600, FALSE, array(
            'min' => 300,
            'max' => 86400,
        ) ) );
        $form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_overlap', isset( $current['replay_overlap'] ) ? (int) $current['replay_overlap'] : 300, FALSE, array(
            'min' => 60,
            'max' => 1800,
        ) ) );
        $form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_max_events', isset( $current['replay_max_events'] ) ? (int) $current['replay_max_events'] : 100, FALSE, array(
            'min' => 10,
            'max' => 100,
        ) ) );

        if ( isset( $current['webhook_url'] ) && $current['webhook_url'] !== '' )
        {
            $safeUrl = \htmlspecialchars( (string) $current['webhook_url'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
            $form->addHtml( '<div class="ipsMessage ipsMessage_info"><strong>' . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_url' ) . ':</strong> ' . $safeUrl . '</div>' );
        }
    }

    /**
     * Validate and normalize settings.
     *
     * @param   array   $settings
     * @return  array
     * @throws  \DomainException
     */
    public function testSettings( $settings )
    {
        if ( !\is_array( $settings ) )
        {
            throw new \DomainException( 'xpolarcheckout_invalid_settings' );
        }

        $settings['environment'] = ( isset( $settings['environment'] ) && $settings['environment'] === 'production' ) ? 'production' : 'sandbox';
        $settings['access_token'] = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        $settings['default_product_id'] = isset( $settings['default_product_id'] ) ? \trim( (string) $settings['default_product_id'] ) : '';
        $settings['presentment_currency'] = isset( $settings['presentment_currency'] ) ? \mb_strtolower( \trim( (string) $settings['presentment_currency'] ) ) : static::DEFAULT_PRESENTMENT_CURRENCY;
        $settings['webhook_secret'] = isset( $settings['webhook_secret'] ) ? \trim( (string) $settings['webhook_secret'] ) : '';
        $settings['webhook_endpoint_id'] = isset( $settings['webhook_endpoint_id'] ) ? \trim( (string) $settings['webhook_endpoint_id'] ) : '';
        $settings['replay_lookback'] = isset( $settings['replay_lookback'] ) ? \max( 300, \min( 86400, (int) $settings['replay_lookback'] ) ) : 3600;
        $settings['replay_overlap'] = isset( $settings['replay_overlap'] ) ? \max( 60, \min( 1800, (int) $settings['replay_overlap'] ) ) : 300;
        $settings['replay_max_events'] = isset( $settings['replay_max_events'] ) ? \max( 10, \min( 100, (int) $settings['replay_max_events'] ) ) : 100;

        if ( $settings['access_token'] === '' || $settings['default_product_id'] === '' )
        {
            throw new \DomainException( 'xpolarcheckout_missing_required_settings' );
        }

        if ( !\preg_match( '/^[a-z]{3}$/', $settings['presentment_currency'] ) )
        {
            throw new \DomainException( 'xpolarcheckout_presentment_currency_invalid' );
        }

        try
        {
            $organization = static::syncOrganizationPresentmentCurrency( $settings, $settings['presentment_currency'] );
            if ( \is_array( $organization ) )
            {
                if ( isset( $organization['id'] ) && \is_scalar( $organization['id'] ) )
                {
                    $settings['organization_id'] = (string) $organization['id'];
                }
                if ( isset( $organization['default_presentment_currency'] ) && \is_scalar( $organization['default_presentment_currency'] ) )
                {
                    $settings['organization_default_presentment_currency'] = \mb_strtolower( (string) $organization['default_presentment_currency'] );
                }
            }
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_currency_sync' );
            throw new \DomainException( 'xpolarcheckout_presentment_currency_sync_failed' );
        }

        if ( empty( $settings['webhook_url'] ) )
        {
            $settings['webhook_url'] = (string) \IPS\Http\Url::internal( 'app=xpolarcheckout&module=webhook&controller=webhook', 'front' );
        }

        if ( $settings['webhook_endpoint_id'] === '' )
        {
            try
            {
                $createdEndpoint = static::createWebhookEndpoint( $settings );
                if ( \is_array( $createdEndpoint ) && isset( $createdEndpoint['id'] ) && \is_scalar( $createdEndpoint['id'] ) )
                {
                    $settings['webhook_endpoint_id'] = (string) $createdEndpoint['id'];

                    if ( empty( $settings['webhook_secret'] )
                        && isset( $createdEndpoint['secret'] )
                        && \is_string( $createdEndpoint['secret'] )
                        && $createdEndpoint['secret'] !== '' )
                    {
                        $settings['webhook_secret'] = $createdEndpoint['secret'];
                    }
                }
            }
            catch ( \Exception $e )
            {
                /* Non-HTTPS local development URLs cannot be registered as Polar webhook endpoints. */
                \IPS\Log::log( $e, 'xpolarcheckout_webhook_endpoint' );
            }
        }

        return $settings;
    }

    /**
     * Create refund in Polar.
     *
     * @param   \IPS\nexus\Transaction   $transaction
     * @param   \IPS\nexus\Money|NULL    $amount
     * @param   string|NULL                $reason
     * @return  mixed
     */
    public function refund( \IPS\nexus\Transaction $transaction, $amount = NULL, $reason = NULL )
    {
        $settings = \json_decode( $this->settings, TRUE );
        if ( !\is_array( $settings ) || empty( $settings['access_token'] ) )
        {
            throw new \RuntimeException( 'xpolarcheckout_missing_required_settings' );
        }

        if ( empty( $transaction->gw_id ) )
        {
            throw new \RuntimeException( 'xpolarcheckout_missing_order_id' );
        }

        $refundAmount = ( $amount instanceof \IPS\nexus\Money ) ? $amount : $transaction->amount;
        $payload = array(
            'order_id' => (string) $transaction->gw_id,
            'reason' => $this->mapRefundReason( $reason ),
            'amount' => $this->moneyToMinorUnit( $refundAmount ),
        );

        $encoded = \json_encode( $payload );
        if ( !\is_string( $encoded ) )
        {
            throw new \RuntimeException( 'xpolarcheckout_payload_encode_failed' );
        }

        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/refunds/' )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $settings['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ) )
                ->post( $encoded )
                ->decodeJson();
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_refund' );
            throw new \RuntimeException( 'gateway_err' );
        }

        return ( \is_array( $response ) && isset( $response['id'] ) ) ? $response['id'] : NULL;
    }

    /**
     * Admin link for a transaction on provider dashboard.
     *
     * @param   \IPS\nexus\Transaction   $transaction
     * @return  \IPS\Http\Url
     */
    public function gatewayUrl( \IPS\nexus\Transaction $transaction )
    {
        if ( empty( $transaction->gw_id ) )
        {
            return \IPS\Http\Url::external( 'https://dashboard.polar.sh' );
        }

        return \IPS\Http\Url::external( 'https://dashboard.polar.sh/orders/' . $transaction->gw_id );
    }

    /**
     * Alert stats used by ACP notifications.
     *
     * @return array
     */
    public static function collectAlertStats()
    {
        $stats = array(
            'webhook_error_count_24h' => 0,
            'replay_recent_run' => FALSE,
            'mismatch_count_30d' => 0,
            'mismatch_count_all_time' => 0,
            'replay_last_run_at' => NULL,
        );

        $dayAgo = \time() - 86400;
        $monthAgo = \time() - ( 30 * 86400 );

        try
        {
            $stats['webhook_error_count_24h'] = (int) \IPS\Db::i()->select(
                'COUNT(*)',
                'core_log',
                array( '( category=? OR category=? ) AND time>?', 'xpolarcheckout_webhook', 'xpolarcheckout_snapshot', $dayAgo )
            )->first();
        }
        catch ( \Exception $e ) {}

        try
        {
            $mismatchWhere = "JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.has_total_mismatch'))='true'";
            $stats['mismatch_count_all_time'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_transactions', $mismatchWhere )->first();
            $stats['mismatch_count_30d'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_transactions', array( "{$mismatchWhere} AND t_date>?", $monthAgo ) )->first();
        }
        catch ( \Exception $e ) {}

        try
        {
            if ( isset( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) && \is_array( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) )
            {
                $state = \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state;
                $stats['replay_last_run_at'] = isset( $state['last_run_at'] ) ? (int) $state['last_run_at'] : NULL;
                $stats['replay_recent_run'] = ( $stats['replay_last_run_at'] !== NULL && ( \time() - $stats['replay_last_run_at'] ) <= 3600 );
            }
        }
        catch ( \Exception $e ) {}

        return $stats;
    }

    /**
     * Fetch configured webhook endpoint details from provider.
     *
     * @param   array $settings
     * @return  array|NULL
     */
    public static function fetchWebhookEndpoint( array $settings )
    {
        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );
        $endpointId = isset( $settings['webhook_endpoint_id'] ) ? \trim( (string) $settings['webhook_endpoint_id'] ) : '';

        if ( $endpointId !== '' )
        {
            try
            {
                $response = \IPS\Http\Url::external( $apiBase . '/webhooks/endpoints/' . $endpointId )
                    ->request( 20 )
                    ->setHeaders( array(
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept' => 'application/json',
                    ) )
                    ->get()
                    ->decodeJson();

                $normalized = static::normalizeWebhookEndpointResponse( $response );
                if ( \is_array( $normalized ) && isset( $normalized['id'] ) && \is_scalar( $normalized['id'] ) )
                {
                    return $normalized;
                }
            }
            catch ( \Exception $e ) {}
        }

        $webhookUrl = isset( $settings['webhook_url'] ) ? \trim( (string) $settings['webhook_url'] ) : '';
        if ( $webhookUrl === '' )
        {
            return NULL;
        }

        return static::findWebhookEndpointByUrl( $settings, $webhookUrl );
    }

    /**
     * Discover endpoint by URL.
     *
     * @param array  $settings
     * @param string $webhookUrl
     * @return array|NULL
     */
    protected static function findWebhookEndpointByUrl( array $settings, $webhookUrl )
    {
        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        $webhookUrl = \trim( (string) $webhookUrl );
        if ( $accessToken === '' || $webhookUrl === '' )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/webhooks/endpoints' )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ) )
                ->get()
                ->decodeJson();
        }
        catch ( \Exception $e )
        {
            return NULL;
        }

        if ( !\is_array( $response ) || !isset( $response['items'] ) || !\is_array( $response['items'] ) )
        {
            return NULL;
        }

        foreach ( $response['items'] as $endpoint )
        {
            $normalized = static::normalizeWebhookEndpointResponse( $endpoint );
            if ( !\is_array( $normalized ) || !isset( $normalized['url'] ) )
            {
                continue;
            }

            if ( \trim( (string) $normalized['url'] ) === $webhookUrl )
            {
                return $normalized;
            }
        }

        return NULL;
    }

    /**
     * Sync endpoint event subscriptions.
     *
     * @param   array   $settings
     * @param   string  $endpointId
     * @return  array
     */
    public static function syncWebhookEvents( array $settings, $endpointId )
    {
        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        $endpointId = \trim( (string) $endpointId );
        if ( $accessToken === '' || $endpointId === '' )
        {
            throw new \RuntimeException( 'Missing webhook endpoint sync settings.' );
        }

        $payload = \json_encode( array(
            'format' => 'raw',
            'events' => \array_values( static::REQUIRED_WEBHOOK_EVENTS ),
            'enabled' => TRUE,
        ) );
        if ( !\is_string( $payload ) )
        {
            throw new \RuntimeException( 'Unable to encode webhook endpoint sync payload.' );
        }

        $apiBase = static::resolveApiBase( $settings );
        $response = \IPS\Http\Url::external( $apiBase . '/webhooks/endpoints/' . $endpointId )
            ->request( 20 )
            ->setHeaders( array(
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ) )
            ->patch( $payload )
            ->decodeJson();

        $normalized = static::normalizeWebhookEndpointResponse( $response );
        if ( !\is_array( $normalized ) || !isset( $normalized['id'] ) || !\is_scalar( $normalized['id'] ) )
        {
            throw new \RuntimeException( static::formatWebhookErrorMessage( $response, 'sync' ) );
        }

        return $normalized;
    }

    /**
     * Compatibility stub kept for existing controller usage.
     *
     * @param   array $settings
     * @return  array
     */
    public static function applyTaxReadinessSnapshotToSettings( array $settings )
    {
        return $settings;
    }

    /**
     * Resolve provider API base URL from settings.
     *
     * @param   array $settings
     * @return  string
     */
    protected static function resolveApiBase( array $settings )
    {
        $environment = isset( $settings['environment'] ) ? (string) $settings['environment'] : 'sandbox';
        return ( $environment === 'production' ) ? static::POLAR_API_BASE_PRODUCTION : static::POLAR_API_BASE_SANDBOX;
    }

    /**
     * Sync organization default presentment currency.
     *
     * @param array  $settings
     * @param string $currency
     * @return array|NULL
     */
    protected static function syncOrganizationPresentmentCurrency( array $settings, $currency )
    {
        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );
        $headers = array(
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );

        $organizationId = isset( $settings['organization_id'] ) ? \trim( (string) $settings['organization_id'] ) : '';
        if ( $organizationId === '' )
        {
            $list = \IPS\Http\Url::external( $apiBase . '/organizations/' )
                ->request( 20 )
                ->setHeaders( $headers )
                ->get()
                ->decodeJson();

            if ( !\is_array( $list ) || !isset( $list['items'] ) || !\is_array( $list['items'] ) || !isset( $list['items'][0]['id'] ) )
            {
                throw new \RuntimeException( 'Unable to resolve Polar organization id.' );
            }

            $organizationId = (string) $list['items'][0]['id'];
        }

        $encoded = \json_encode( array(
            'default_presentment_currency' => \mb_strtolower( (string) $currency ),
        ) );
        if ( !\is_string( $encoded ) )
        {
            throw new \RuntimeException( 'Unable to encode organization currency payload.' );
        }

        $organization = \IPS\Http\Url::external( $apiBase . '/organizations/' . $organizationId )
            ->request( 20 )
            ->setHeaders( $headers )
            ->patch( $encoded )
            ->decodeJson();

        if ( !\is_array( $organization ) )
        {
            throw new \RuntimeException( 'Unable to update Polar organization currency.' );
        }

        return $organization;
    }

    /**
     * Create Polar webhook endpoint when not yet configured.
     *
     * @param array $settings
     * @return array|NULL
     */
    protected static function createWebhookEndpoint( array $settings )
    {
        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        $webhookUrl = isset( $settings['webhook_url'] ) ? \trim( (string) $settings['webhook_url'] ) : '';

        if ( $accessToken === '' || $webhookUrl === '' || !static::isHttpsWebhookUrl( $webhookUrl ) )
        {
            return NULL;
        }

        $payload = array(
            'url' => $webhookUrl,
            'format' => 'raw',
            'events' => \array_values( static::REQUIRED_WEBHOOK_EVENTS ),
        );

        $webhookSecret = isset( $settings['webhook_secret'] ) ? \trim( (string) $settings['webhook_secret'] ) : '';
        if ( $webhookSecret !== '' && \mb_strlen( $webhookSecret ) >= 32 )
        {
            $payload['secret'] = $webhookSecret;
        }

        $encoded = \json_encode( $payload );
        if ( !\is_string( $encoded ) )
        {
            throw new \RuntimeException( 'Unable to encode webhook endpoint create payload.' );
        }

        $apiBase = static::resolveApiBase( $settings );
        $response = \IPS\Http\Url::external( $apiBase . '/webhooks/endpoints' )
            ->request( 20 )
            ->setHeaders( array(
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ) )
            ->post( $encoded )
            ->decodeJson();

        $normalized = static::normalizeWebhookEndpointResponse( $response );
        if ( !\is_array( $normalized ) || !isset( $normalized['id'] ) || !\is_scalar( $normalized['id'] ) )
        {
            throw new \RuntimeException( static::formatWebhookErrorMessage( $response, 'create' ) );
        }

        return $normalized;
    }

    /**
     * Build readable webhook API error message.
     *
     * @param mixed  $response
     * @param string $action
     * @return string
     */
    protected static function formatWebhookErrorMessage( $response, $action )
    {
        $message = 'Invalid webhook endpoint ' . $action . ' response.';
        if ( \is_array( $response ) )
        {
            if ( isset( $response['detail'] ) && \is_string( $response['detail'] ) && $response['detail'] !== '' )
            {
                return $message . ' ' . $response['detail'];
            }
            if ( isset( $response['error_description'] ) && \is_string( $response['error_description'] ) && $response['error_description'] !== '' )
            {
                return $message . ' ' . $response['error_description'];
            }
            if ( isset( $response['error'] ) && \is_string( $response['error'] ) && $response['error'] !== '' )
            {
                return $message . ' ' . $response['error'];
            }
        }

        return $message;
    }

    /**
     * Normalize webhook endpoint payload for downstream consumers.
     *
     * @param mixed $endpoint
     * @return array|NULL
     */
    protected static function normalizeWebhookEndpointResponse( $endpoint )
    {
        if ( !\is_array( $endpoint ) )
        {
            return NULL;
        }

        if ( isset( $endpoint['events'] ) && \is_array( $endpoint['events'] ) )
        {
            $normalizedEvents = array();
            foreach ( $endpoint['events'] as $event )
            {
                if ( \is_scalar( $event ) && (string) $event !== '' )
                {
                    $normalizedEvents[] = (string) $event;
                }
            }
            $endpoint['events'] = $normalizedEvents;
            $endpoint['enabled_events'] = $normalizedEvents;
        }
        elseif ( isset( $endpoint['enabled_events'] ) && \is_array( $endpoint['enabled_events'] ) )
        {
            $endpoint['events'] = $endpoint['enabled_events'];
        }

        return $endpoint;
    }

    /**
     * Determine whether webhook URL is HTTPS.
     *
     * @param string $url
     * @return bool
     */
    protected static function isHttpsWebhookUrl( $url )
    {
        if ( !\is_string( $url ) || $url === '' )
        {
            return FALSE;
        }

        $scheme = \parse_url( $url, PHP_URL_SCHEME );
        return \is_string( $scheme ) && \mb_strtolower( $scheme ) === 'https';
    }

    /**
     * Convert Money to integer minor unit.
     *
     * @param   \IPS\nexus\Money $money
     * @return  int
     */
    protected function moneyToMinorUnit( \IPS\nexus\Money $money )
    {
        $decimals = \IPS\nexus\Money::numberOfDecimalsForCurrency( $money->currency );
        $multiplier = new \IPS\Math\Number( '1' . \str_repeat( '0', $decimals ) );
        $minor = $money->amount->multiply( $multiplier );

        return (int) (string) $minor;
    }

    /**
     * Normalize refund reason to provider enum.
     *
     * @param   string|NULL $reason
     * @return  string
     */
    protected function mapRefundReason( $reason )
    {
        $normalized = \mb_strtolower( \trim( (string) $reason ) );
        if ( $normalized === 'duplicate' )
        {
            return 'duplicate';
        }
        if ( $normalized === 'fraudulent' )
        {
            return 'fraudulent';
        }
        if ( $normalized === 'requested_by_customer' || $normalized === 'customer_request' )
        {
            return 'customer_request';
        }

        return 'other';
    }
}
