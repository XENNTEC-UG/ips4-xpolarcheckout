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

    const CHECKOUT_FLOW_MODE_ALLOW_ALL = 'allow_all';
    const CHECKOUT_FLOW_MODE_SINGLE_ITEM_ONLY = 'single_item_only';

    const MULTI_ITEM_LABEL_MODE_FIRST_ITEM = 'first_item';
    const MULTI_ITEM_LABEL_MODE_INVOICE_COUNT = 'invoice_count';
    const MULTI_ITEM_LABEL_MODE_ITEM_LIST = 'item_list';

    const CHECKOUT_LABEL_CACHE_KEY = 'xpolarcheckout_checkout_label_products';
    const CHECKOUT_LABEL_CACHE_MAX = 200;

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

        /* Persist transaction so $transaction->id is available for success_url and metadata */
        $transaction->save();

        $apiBase = static::resolveApiBase( $settings );
        $amountMinor = $this->moneyToMinorUnit( $transaction->amount );
        $externalCustomerId = $transaction->member ? (string) (int) $transaction->member->member_id : '';
        $checkoutFlowMode = isset( $settings['checkout_flow_mode'] ) ? (string) $settings['checkout_flow_mode'] : static::CHECKOUT_FLOW_MODE_ALLOW_ALL;
        $multiItemLabelMode = isset( $settings['multi_item_label_mode'] ) ? (string) $settings['multi_item_label_mode'] : static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM;
        $purchaseLineCount = $this->countPayableInvoiceLines( $transaction->invoice );

        if ( $checkoutFlowMode === static::CHECKOUT_FLOW_MODE_SINGLE_ITEM_ONLY && $purchaseLineCount > 1 )
        {
            throw new \LogicException( 'xpolarcheckout_checkout_flow_single_item_only_error' );
        }

        /* Build per-product payload from invoice items */
        $products = array();
        $pricesMap = array();
        $itemNames = array();

        foreach ( $transaction->invoice->items as $invoiceItem )
        {
            $itemName = isset( $invoiceItem->name ) ? \trim( (string) $invoiceItem->name ) : '';
            if ( $itemName === '' )
            {
                $itemName = 'Invoice #' . $transaction->invoice->id;
            }

            $polarProductId = NULL;
            $ipsPackageId = isset( $invoiceItem->id ) ? (int) $invoiceItem->id : 0;

            /* Only map package-type items with a valid package ID */
            if ( $ipsPackageId > 0 && $invoiceItem instanceof \IPS\nexus\extensions\nexus\Item\Package )
            {
                $polarProductId = $this->ensurePolarProduct( $ipsPackageId, $itemName, $settings );
            }

            if ( $polarProductId === NULL )
            {
                $polarProductId = $defaultProductId;
            }

            /* Calculate line total: unit price * quantity (skip negative-amount discount items) */
            $lineAmount = $this->moneyToMinorUnit( $invoiceItem->price );
            if ( $lineAmount <= 0 )
            {
                continue;
            }
            $lineQuantity = isset( $invoiceItem->quantity ) ? \max( 1, (int) $invoiceItem->quantity ) : 1;
            $lineTotal = $lineAmount * $lineQuantity;

            if ( !isset( $pricesMap[ $polarProductId ] ) )
            {
                $products[] = $polarProductId;
                $pricesMap[ $polarProductId ] = array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => $lineTotal,
                        'price_currency' => $transactionCurrency,
                    ),
                );
            }
            else
            {
                /* Same product appears twice — add to existing price */
                $pricesMap[ $polarProductId ][0]['price_amount'] += $lineTotal;
            }

            $itemNames[] = $itemName;
        }

        /* Fallback: no items resolved */
        if ( empty( $products ) )
        {
            $products = array( $defaultProductId );
            $pricesMap = array(
                $defaultProductId => array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => (int) $amountMinor,
                        'price_currency' => $transactionCurrency,
                    ),
                ),
            );
        }

        /*
         * Polar treats multiple products as radio-button choices, not line items.
         * For multi-item invoices, consolidate into the first product with the
         * combined total so the customer pays the full invoice amount in one go.
         */
        if ( \count( $products ) > 1 )
        {
            $firstProductId = $products[0];
            $combinedAmount = 0;
            foreach ( $pricesMap as $prices )
            {
                $combinedAmount += (int) $prices[0]['price_amount'];
            }

            $consolidatedProductId = $firstProductId;
            if ( $multiItemLabelMode !== static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM )
            {
                $displayLabel = $this->buildConsolidatedCheckoutLabel( $transaction->invoice, $itemNames, $multiItemLabelMode );
                $displayProductId = $this->getOrCreateCheckoutLabelProduct( $displayLabel, $settings );
                if ( $displayProductId !== NULL )
                {
                    $consolidatedProductId = $displayProductId;
                }
            }

            $products = array( $consolidatedProductId );
            $pricesMap = array(
                $consolidatedProductId => array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => $combinedAmount,
                        'price_currency' => $transactionCurrency,
                    ),
                ),
            );
        }

        /* --- Coupon/discount forwarding to Polar --- */
        $discountInfo = $this->calculateInvoiceDiscount( $transaction );
        $polarDiscountId = NULL;
        $allowDiscountCodes = isset( $settings['allow_discount_codes'] ) && $settings['allow_discount_codes'] === '1';

        if ( $discountInfo['amount_minor'] > 0 )
        {
            /* Verify math: positive line items total minus discount must equal transaction amount */
            $lineItemsTotal = 0;
            foreach ( $pricesMap as $priceEntries )
            {
                $lineItemsTotal += (int) $priceEntries[0]['price_amount'];
            }
            $transactionMinor = $this->moneyToMinorUnit( $transaction->amount );

            if ( ( $lineItemsTotal - $discountInfo['amount_minor'] ) === $transactionMinor )
            {
                try
                {
                    $polarDiscountId = $this->createOneTimePolarDiscount( $discountInfo, $transaction->amount->currency, $settings );
                    $allowDiscountCodes = FALSE;
                }
                catch ( \Exception $e )
                {
                    \IPS\Log::log( $e, 'xpolarcheckout_coupon' );
                    /* Fallback: consolidate to transaction amount without Polar discount */
                    $fallbackProduct = $products[0];
                    $products = array( $fallbackProduct );
                    $pricesMap = array(
                        $fallbackProduct => array(
                            array(
                                'amount_type' => 'fixed',
                                'price_amount' => $transactionMinor,
                                'price_currency' => $transactionCurrency,
                            ),
                        ),
                    );
                }
            }
            else
            {
                /* Math mismatch safety: consolidate to transaction amount */
                \IPS\Log::log(
                    'Coupon math mismatch: lineTotal=' . $lineItemsTotal . ' discount=' . $discountInfo['amount_minor'] . ' txn=' . $transactionMinor,
                    'xpolarcheckout_coupon'
                );
                $fallbackProduct = $products[0];
                $products = array( $fallbackProduct );
                $pricesMap = array(
                    $fallbackProduct => array(
                        array(
                            'amount_type' => 'fixed',
                            'price_amount' => $transactionMinor,
                            'price_currency' => $transactionCurrency,
                        ),
                    ),
                );
            }
        }

        $payload = array(
            'products' => $products,
            'currency' => $transactionCurrency,
            'allow_discount_codes' => (bool) $allowDiscountCodes,
            'success_url' => (string) $transaction->url()->setQueryString( 'pending', 1 ),
            'metadata' => array(
                'ips_transaction_id' => (string) (int) $transaction->id,
                'ips_invoice_id' => (string) (int) $transaction->invoice->id,
                'ips_member_id' => $externalCustomerId,
                'gateway_id' => (string) (int) $this->id,
                'ips_item_names' => \implode( ', ', \array_slice( $itemNames, 0, 3 ) ),
            ),
            'prices' => $pricesMap,
        );

        if ( $polarDiscountId !== NULL )
        {
            $payload['discount_id'] = $polarDiscountId;
        }

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

        /* Store Polar checkout ID on the transaction for webhook correlation */
        if ( isset( $response['id'] ) && \is_string( $response['id'] ) )
        {
            $transaction->gw_id = $response['id'];
            $transaction->save();
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

        $checkoutFlowMode = isset( $settings['checkout_flow_mode'] ) ? (string) $settings['checkout_flow_mode'] : static::CHECKOUT_FLOW_MODE_ALLOW_ALL;
        if ( $checkoutFlowMode === static::CHECKOUT_FLOW_MODE_SINGLE_ITEM_ONLY )
        {
            $checkoutInvoice = $this->loadCheckoutInvoiceFromRequest();
            if ( $checkoutInvoice instanceof \IPS\nexus\Invoice && $this->countPayableInvoiceLines( $checkoutInvoice ) > 1 )
            {
                /* Return boolean FALSE here so the gateway is hidden in checkout method selector. */
                return FALSE;
            }
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
        $form->add( new \IPS\Helpers\Form\Select(
            'xpolarcheckout_checkout_flow_mode',
            isset( $current['checkout_flow_mode'] ) ? $current['checkout_flow_mode'] : static::CHECKOUT_FLOW_MODE_ALLOW_ALL,
            FALSE,
            array(
                'options' => array(
                    static::CHECKOUT_FLOW_MODE_ALLOW_ALL => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_checkout_flow_mode_allow_all' ),
                    static::CHECKOUT_FLOW_MODE_SINGLE_ITEM_ONLY => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_checkout_flow_mode_single_item_only' ),
                ),
            )
        ) );
        $form->add( new \IPS\Helpers\Form\Select(
            'xpolarcheckout_multi_item_label_mode',
            isset( $current['multi_item_label_mode'] ) ? $current['multi_item_label_mode'] : static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM,
            FALSE,
            array(
                'options' => array(
                    static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_multi_item_label_mode_first_item' ),
                    static::MULTI_ITEM_LABEL_MODE_INVOICE_COUNT => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_multi_item_label_mode_invoice_count' ),
                    static::MULTI_ITEM_LABEL_MODE_ITEM_LIST => \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_multi_item_label_mode_item_list' ),
                ),
            )
        ) );
        $form->addHeader( 'xpolarcheckout_discount_settings_header' );
        $form->add( new \IPS\Helpers\Form\YesNo(
            'xpolarcheckout_allow_discount_codes',
            isset( $current['allow_discount_codes'] ) ? (bool) $current['allow_discount_codes'] : FALSE,
            FALSE
        ) );
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
        $settings['checkout_flow_mode'] = isset( $settings['checkout_flow_mode'] ) ? (string) $settings['checkout_flow_mode'] : static::CHECKOUT_FLOW_MODE_ALLOW_ALL;
        if ( !\in_array( $settings['checkout_flow_mode'], array( static::CHECKOUT_FLOW_MODE_ALLOW_ALL, static::CHECKOUT_FLOW_MODE_SINGLE_ITEM_ONLY ), TRUE ) )
        {
            $settings['checkout_flow_mode'] = static::CHECKOUT_FLOW_MODE_ALLOW_ALL;
        }
        $settings['multi_item_label_mode'] = isset( $settings['multi_item_label_mode'] ) ? (string) $settings['multi_item_label_mode'] : static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM;
        if ( !\in_array( $settings['multi_item_label_mode'], array( static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM, static::MULTI_ITEM_LABEL_MODE_INVOICE_COUNT, static::MULTI_ITEM_LABEL_MODE_ITEM_LIST ), TRUE ) )
        {
            $settings['multi_item_label_mode'] = static::MULTI_ITEM_LABEL_MODE_FIRST_ITEM;
        }
        $settings['allow_discount_codes'] = ( isset( $settings['allow_discount_codes'] ) && $settings['allow_discount_codes'] ) ? '1' : '0';
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
     * Count payable invoice lines (positive value rows only).
     *
     * @param   \IPS\nexus\Invoice $invoice
     * @return  int
     */
    protected function countPayableInvoiceLines( \IPS\nexus\Invoice $invoice )
    {
        $count = 0;
        foreach ( $invoice->items as $invoiceItem )
        {
            try
            {
                $lineAmount = $this->moneyToMinorUnit( $invoiceItem->price );
            }
            catch ( \Exception $e )
            {
                $lineAmount = 0;
            }

            if ( $lineAmount > 0 )
            {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate total invoice discount from negative-amount items (coupons, gateway discounts).
     *
     * @param   \IPS\nexus\Transaction $transaction
     * @return  array   array( 'amount_minor' => int, 'names' => array )
     */
    protected function calculateInvoiceDiscount( \IPS\nexus\Transaction $transaction )
    {
        $discountMinor = 0;
        $discountNames = array();

        foreach ( $transaction->invoice->items as $invoiceItem )
        {
            if ( !isset( $invoiceItem->price ) || !( $invoiceItem->price instanceof \IPS\nexus\Money ) )
            {
                continue;
            }

            $unitAmount = $this->moneyToMinorUnit( $invoiceItem->price );
            if ( $unitAmount >= 0 )
            {
                continue;
            }

            $quantity = isset( $invoiceItem->quantity ) ? (int) $invoiceItem->quantity : 1;
            if ( $quantity < 1 )
            {
                $quantity = 1;
            }

            $discountMinor += \abs( $unitAmount ) * $quantity;

            $itemName = isset( $invoiceItem->name ) ? \trim( (string) $invoiceItem->name ) : '';
            if ( $itemName !== '' )
            {
                $discountNames[] = $itemName;
            }
        }

        return array( 'amount_minor' => $discountMinor, 'names' => $discountNames );
    }

    /**
     * Create a one-time Polar discount from IPS invoice coupon data.
     *
     * @param   array   $discountInfo   From calculateInvoiceDiscount()
     * @param   string  $currency       Transaction currency code
     * @param   array   $settings       Gateway settings
     * @return  string  Polar discount UUID
     * @throws  \RuntimeException
     */
    protected function createOneTimePolarDiscount( array $discountInfo, $currency, array $settings )
    {
        $couponName = \count( $discountInfo['names'] )
            ? \implode( ', ', $discountInfo['names'] )
            : \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_coupon_discount' );

        $couponName = \strip_tags( (string) $couponName );
        $couponName = \trim( (string) \preg_replace( '/\s+/', ' ', $couponName ) );
        if ( $couponName === '' )
        {
            $couponName = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_coupon_discount' );
        }
        if ( \mb_strlen( $couponName ) > 100 )
        {
            $couponName = \rtrim( \mb_substr( $couponName, 0, 100 ) );
        }

        $apiBase = static::resolveApiBase( $settings );
        $accessToken = \trim( (string) $settings['access_token'] );

        $body = \json_encode( array(
            'name'     => $couponName,
            'type'     => 'fixed',
            'amount'   => $discountInfo['amount_minor'],
            'currency' => \mb_strtolower( (string) $currency ),
            'duration' => 'once',
        ) );

        $response = \IPS\Http\Url::external( $apiBase . '/v1/discounts' )
            ->request( 20 )
            ->setHeaders( array(
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ) )
            ->post( $body )
            ->decodeJson();

        if ( !isset( $response['id'] ) || !\is_string( $response['id'] ) )
        {
            throw new \RuntimeException( 'Failed to create Polar discount for invoice coupon.' );
        }

        return $response['id'];
    }

    /**
     * Try to load the checkout invoice from the current front request context.
     *
     * @return \IPS\nexus\Invoice|NULL
     */
    protected function loadCheckoutInvoiceFromRequest()
    {
        if ( (string) \IPS\Request::i()->app !== 'nexus'
            || (string) \IPS\Request::i()->module !== 'checkout'
            || (string) \IPS\Request::i()->controller !== 'checkout' )
        {
            return NULL;
        }

        $invoiceId = isset( \IPS\Request::i()->id ) ? (int) \IPS\Request::i()->id : 0;
        if ( $invoiceId <= 0 )
        {
            return NULL;
        }

        try
        {
            return \IPS\nexus\Invoice::load( $invoiceId );
        }
        catch ( \Exception $e )
        {
            return NULL;
        }
    }

    /**
     * Build consolidated checkout label for multi-item invoices.
     *
     * @param   \IPS\nexus\Invoice $invoice
     * @param   array               $itemNames
     * @param   string              $labelMode
     * @return  string
     */
    protected function buildConsolidatedCheckoutLabel( \IPS\nexus\Invoice $invoice, array $itemNames, $labelMode )
    {
        $labelLines = array();
        $lineCount = 0;

        foreach ( $invoice->items as $invoiceItem )
        {
            try
            {
                $lineAmount = $this->moneyToMinorUnit( $invoiceItem->price );
            }
            catch ( \Exception $e )
            {
                $lineAmount = 0;
            }

            if ( $lineAmount <= 0 )
            {
                continue;
            }

            $lineCount++;
            $name = isset( $invoiceItem->name ) ? \trim( (string) $invoiceItem->name ) : '';
            if ( $name === '' )
            {
                $name = 'Item';
            }

            $quantity = isset( $invoiceItem->quantity ) ? \max( 1, (int) $invoiceItem->quantity ) : 1;

            $priceDisplay = '';
            try
            {
                $priceDisplay = (string) $invoiceItem->price;
            }
            catch ( \Exception $e ) {}

            $labelLines[] = array(
                'name' => $name,
                'quantity' => $quantity,
                'price' => $priceDisplay,
            );
        }

        if ( empty( $labelLines ) )
        {
            foreach ( $itemNames as $name )
            {
                $name = \trim( (string) $name );
                if ( $name === '' )
                {
                    continue;
                }
                $labelLines[] = array( 'name' => $name, 'quantity' => 1, 'price' => '' );
            }
            $lineCount = \count( $labelLines );
        }

        $label = '';
        if ( $labelMode === static::MULTI_ITEM_LABEL_MODE_INVOICE_COUNT )
        {
            $itemWord = ( $lineCount === 1 ) ? 'item' : 'items';
            $label = 'Invoice #' . (int) $invoice->id . ' (' . $lineCount . ' ' . $itemWord . ')';
        }
        elseif ( $labelMode === static::MULTI_ITEM_LABEL_MODE_ITEM_LIST )
        {
            $parts = array();
            foreach ( $labelLines as $line )
            {
                $part = ( $line['quantity'] > 1 ? $line['quantity'] . 'x ' : '' ) . $line['name'];
                if ( $line['price'] !== '' )
                {
                    $part .= $line['quantity'] > 1
                        ? ' (' . $line['price'] . ' each)'
                        : ' (' . $line['price'] . ')';
                }
                $parts[] = $part;
            }
            $label = \implode( ', ', $parts );
        }
        else
        {
            return '';
        }

        $label = \preg_replace( '/\s+/', ' ', \trim( (string) $label ) );
        if ( !\is_string( $label ) || $label === '' )
        {
            $label = 'Invoice #' . (int) $invoice->id;
        }
        if ( \mb_strlen( $label ) < 3 )
        {
            $label .= ' order';
        }

        return $label;
    }

    /**
     * Resolve or create a Polar product used as checkout display label.
     *
     * @param   string  $label
     * @param   array   $settings
     * @return  string|NULL
     */
    protected function getOrCreateCheckoutLabelProduct( $label, array $settings )
    {
        $label = \trim( (string) $label );
        if ( $label === '' )
        {
            return NULL;
        }

        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return NULL;
        }

        $labelHash = \sha1( \mb_strtolower( $label ) );
        $cache = $this->getCheckoutLabelProductCache();
        if ( isset( $cache[ $labelHash ]['id'] ) && \is_string( $cache[ $labelHash ]['id'] ) && $cache[ $labelHash ]['id'] !== '' )
        {
            $cache[ $labelHash ]['updated_at'] = \time();
            $this->setCheckoutLabelProductCache( $cache );
            return $cache[ $labelHash ]['id'];
        }

        $presentmentCurrency = isset( $settings['presentment_currency'] ) ? \mb_strtolower( \trim( (string) $settings['presentment_currency'] ) ) : static::DEFAULT_PRESENTMENT_CURRENCY;
        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $createPayload = \json_encode( array(
                'name' => $label,
                'prices' => array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => 999,
                        'price_currency' => $presentmentCurrency,
                    ),
                ),
                'metadata' => array(
                    'source' => 'xpolarcheckout',
                    'label_hash' => $labelHash,
                    'label_mode' => 'checkout_consolidation',
                ),
            ) );

            if ( !\is_string( $createPayload ) )
            {
                return NULL;
            }

            $response = \IPS\Http\Url::external( $apiBase . '/products/' )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ) )
                ->post( $createPayload )
                ->decodeJson();

            if ( !\is_array( $response ) || !isset( $response['id'] ) || !\is_string( $response['id'] ) || $response['id'] === '' )
            {
                return NULL;
            }

            $cache[ $labelHash ] = array(
                'id' => (string) $response['id'],
                'name' => $label,
                'updated_at' => \time(),
            );
            $this->setCheckoutLabelProductCache( $cache );

            return (string) $response['id'];
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_checkout_label_product' );
            return NULL;
        }
    }

    /**
     * Read checkout-label product cache from datastore.
     *
     * @return  array
     */
    protected function getCheckoutLabelProductCache()
    {
        $key = static::CHECKOUT_LABEL_CACHE_KEY;
        try
        {
            if ( isset( \IPS\Data\Store::i()->$key ) && \is_array( \IPS\Data\Store::i()->$key ) )
            {
                return \IPS\Data\Store::i()->$key;
            }
        }
        catch ( \Exception $e ) {}

        return array();
    }

    /**
     * Persist checkout-label product cache in datastore.
     *
     * @param   array $cache
     * @return  void
     */
    protected function setCheckoutLabelProductCache( array $cache )
    {
        \uasort( $cache, function ( $a, $b ) {
            $left = isset( $a['updated_at'] ) ? (int) $a['updated_at'] : 0;
            $right = isset( $b['updated_at'] ) ? (int) $b['updated_at'] : 0;

            if ( $left === $right )
            {
                return 0;
            }

            return ( $left > $right ) ? -1 : 1;
        } );

        if ( \count( $cache ) > static::CHECKOUT_LABEL_CACHE_MAX )
        {
            $cache = \array_slice( $cache, 0, static::CHECKOUT_LABEL_CACHE_MAX, TRUE );
        }

        $key = static::CHECKOUT_LABEL_CACHE_KEY;
        try
        {
            \IPS\Data\Store::i()->$key = $cache;
        }
        catch ( \Exception $e ) {}
    }

    /**
     * Ensure a Polar product exists for the given IPS package, creating or updating as needed.
     *
     * @param   int     $ipsPackageId   IPS Nexus package ID
     * @param   string  $productName    Current product name from IPS
     * @param   array   $settings       Gateway settings
     * @return  string|NULL             Polar product ID, or NULL on failure
     */
    protected function ensurePolarProduct( $ipsPackageId, $productName, array $settings )
    {
        $ipsPackageId = (int) $ipsPackageId;
        $productName = \trim( (string) $productName );
        if ( $ipsPackageId <= 0 || $productName === '' )
        {
            return NULL;
        }

        /* Polar product name minimum is 3 chars */
        if ( \mb_strlen( $productName ) < 3 )
        {
            $productName .= ' (item)';
        }

        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );
        $presentmentCurrency = isset( $settings['presentment_currency'] ) ? \mb_strtolower( \trim( (string) $settings['presentment_currency'] ) ) : static::DEFAULT_PRESENTMENT_CURRENCY;

        /* Check existing mapping */
        try
        {
            $row = \IPS\Db::i()->select( '*', 'xpc_product_map', array( 'ips_package_id=?', $ipsPackageId ) )->first();
        }
        catch ( \UnderflowException $e )
        {
            $row = NULL;
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_product_map' );
            return NULL;
        }

        if ( \is_array( $row ) && isset( $row['polar_product_id'] ) && $row['polar_product_id'] !== '' )
        {
            /* Name sync: PATCH only when name actually changed */
            $storedName = isset( $row['product_name'] ) ? (string) $row['product_name'] : '';
            if ( $storedName !== $productName )
            {
                try
                {
                    $patchPayload = \json_encode( array( 'name' => $productName ) );
                    if ( \is_string( $patchPayload ) )
                    {
                        \IPS\Http\Url::external( $apiBase . '/products/' . $row['polar_product_id'] )
                            ->request( 15 )
                            ->setHeaders( array(
                                'Authorization' => 'Bearer ' . $accessToken,
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                            ) )
                            ->patch( $patchPayload );
                    }

                    \IPS\Db::i()->update( 'xpc_product_map', array(
                        'product_name' => $productName,
                        'updated_at' => \time(),
                    ), array( 'map_id=?', (int) $row['map_id'] ) );

                    \IPS\Log::log( \sprintf( 'Updated Polar product name: %s (package %d)', $productName, $ipsPackageId ), 'xpolarcheckout_product_map' );
                }
                catch ( \Exception $e )
                {
                    \IPS\Log::log( $e, 'xpolarcheckout_product_map' );
                }
            }

            return (string) $row['polar_product_id'];
        }

        /* Create new Polar product */
        try
        {
            $createPayload = \json_encode( array(
                'name' => $productName,
                'prices' => array(
                    array(
                        'amount_type' => 'fixed',
                        'price_amount' => 999,
                        'price_currency' => $presentmentCurrency,
                    ),
                ),
                'metadata' => array(
                    'ips_package_id' => (string) $ipsPackageId,
                    'source' => 'xpolarcheckout',
                ),
            ) );

            if ( !\is_string( $createPayload ) )
            {
                return NULL;
            }

            $response = \IPS\Http\Url::external( $apiBase . '/products/' )
                ->request( 20 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ) )
                ->post( $createPayload )
                ->decodeJson();

            if ( !\is_array( $response ) || !isset( $response['id'] ) || !\is_string( $response['id'] ) || $response['id'] === '' )
            {
                \IPS\Log::log( \sprintf( 'Polar product create response missing id for package %d', $ipsPackageId ), 'xpolarcheckout_product_map' );
                return NULL;
            }

            $polarProductId = (string) $response['id'];
            $now = \time();

            \IPS\Db::i()->insert( 'xpc_product_map', array(
                'ips_package_id' => $ipsPackageId,
                'polar_product_id' => $polarProductId,
                'product_name' => $productName,
                'created_at' => $now,
                'updated_at' => $now,
            ) );

            return $polarProductId;
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_product_map' );
            return NULL;
        }
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
            'replay_recent_run' => NULL,
            'mismatch_count_30d' => 0,
            'mismatch_count_all_time' => 0,
            'replay_last_run_at' => NULL,
        );

        $dayAgo = \time() - 86400;
        $monthAgo = \time() - ( 30 * 86400 );

        /* Respect acknowledgment timestamp — only count errors after ack */
        $ackAt = 0;
        if ( isset( \IPS\Data\Store::i()->xpc_webhook_errors_ack_at ) )
        {
            $ackAt = (int) \IPS\Data\Store::i()->xpc_webhook_errors_ack_at;
        }
        $errorsSince = \max( $dayAgo, $ackAt );

        try
        {
            $stats['webhook_error_count_24h'] = (int) \IPS\Db::i()->select(
                'COUNT(*)',
                'core_log',
                array( '( category=? OR category=? ) AND time>?', 'xpolarcheckout_webhook', 'xpolarcheckout_snapshot', $errorsSince )
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
                $stats['replay_recent_run'] = ( $stats['replay_last_run_at'] !== NULL )
                    ? ( ( \time() - $stats['replay_last_run_at'] ) <= 3600 )
                    : NULL;
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

    /**
     * Trigger asynchronous invoice generation for a Polar order.
     *
     * Polar returns HTTP 202 (Accepted) — the invoice URL becomes available
     * when order.updated fires with is_invoice_generated = true.
     *
     * @param   string  $orderId
     * @param   array   $settings
     * @return  bool
     */
    public function triggerInvoiceGeneration( $orderId, array $settings )
    {
        $orderId = \trim( (string) $orderId );
        if ( $orderId === '' )
        {
            return FALSE;
        }

        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return FALSE;
        }

        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/orders/' . $orderId . '/invoice' )
                ->request( 10 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ) )
                ->post( '' );

            $statusCode = (int) $response->httpResponseCode;
            return ( $statusCode >= 200 && $statusCode < 300 );
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_invoice_gen' );
            return FALSE;
        }
    }

    /**
     * Fetch the generated invoice URL for a Polar order.
     *
     * Only works after is_invoice_generated = true on the order.
     *
     * @param   string  $orderId
     * @param   array   $settings
     * @return  string|NULL
     */
    public function fetchInvoiceUrl( $orderId, array $settings )
    {
        $orderId = \trim( (string) $orderId );
        if ( $orderId === '' )
        {
            return NULL;
        }

        $accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
        if ( $accessToken === '' )
        {
            return NULL;
        }

        $apiBase = static::resolveApiBase( $settings );

        try
        {
            $response = \IPS\Http\Url::external( $apiBase . '/orders/' . $orderId . '/invoice' )
                ->request( 10 )
                ->setHeaders( array(
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept'        => 'application/json',
                ) )
                ->get()
                ->decodeJson();

            if ( \is_array( $response ) && isset( $response['url'] ) && \is_string( $response['url'] ) && $response['url'] !== '' )
            {
                return $response['url'];
            }

            return NULL;
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_invoice_gen' );
            return NULL;
        }
    }
}
