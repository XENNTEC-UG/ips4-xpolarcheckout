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

        $apiBase = static::resolveApiBase( $settings );
        $amountMinor = $this->moneyToMinorUnit( $transaction->amount );

        $externalCustomerId = $transaction->member ? (string) (int) $transaction->member->member_id : '';
        $payload = array(
            'products' => array( $defaultProductId ),
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
                        'price_currency' => \mb_strtolower( (string) $transaction->amount->currency ),
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
        $settings['webhook_secret'] = isset( $settings['webhook_secret'] ) ? \trim( (string) $settings['webhook_secret'] ) : '';
        $settings['replay_lookback'] = isset( $settings['replay_lookback'] ) ? \max( 300, \min( 86400, (int) $settings['replay_lookback'] ) ) : 3600;
        $settings['replay_overlap'] = isset( $settings['replay_overlap'] ) ? \max( 60, \min( 1800, (int) $settings['replay_overlap'] ) ) : 300;
        $settings['replay_max_events'] = isset( $settings['replay_max_events'] ) ? \max( 10, \min( 100, (int) $settings['replay_max_events'] ) ) : 100;

        if ( $settings['access_token'] === '' || $settings['default_product_id'] === '' )
        {
            throw new \DomainException( 'xpolarcheckout_missing_required_settings' );
        }

        if ( empty( $settings['webhook_url'] ) )
        {
            $settings['webhook_url'] = (string) \IPS\Http\Url::internal( 'app=xpolarcheckout&module=webhook&controller=webhook', 'front' );
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
        if ( empty( $settings['access_token'] ) || empty( $settings['webhook_endpoint_id'] ) )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/webhooks/endpoints/' . $settings['webhook_endpoint_id'] )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $settings['access_token'],
                    'Accept' => 'application/json',
                ) )
                ->get()
                ->decodeJson();

            return \is_array( $response ) ? $response : NULL;
        }
        catch ( \Exception $e )
        {
            return NULL;
        }
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
        return array(
            'id' => (string) $endpointId,
            'enabled_events' => static::REQUIRED_WEBHOOK_EVENTS,
        );
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
