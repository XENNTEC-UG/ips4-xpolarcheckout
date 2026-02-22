<?php
/**
 * @brief		Stripe Checkout Gateway
 * @author      https://xenntec.com/
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
	/* !Features (Each gateway will override) */
	const SUPPORTS_REFUNDS = TRUE;
	const SUPPORTS_PARTIAL_REFUNDS = TRUE;
	const STRIPE_VERSION = '2026-01-28.clover';

	/**
	 * @brief	Webhook event types required by this gateway. Single source of truth.
	 */
	const REQUIRED_WEBHOOK_EVENTS = array(
		'charge.dispute.closed',
		'charge.dispute.created',
		'charge.refunded',
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
	);
    
    /* !Payment Gateway */
	
	/**
	 * Authorize
	 *
	 * @param	\IPS\nexus\Transaction					$transaction	Transaction
	 * @param	array|\IPS\nexus\Customer\CreditCard	$values			Values from form OR a stored card object if this gateway supports them
	 * @param	\IPS\nexus\Fraud\MaxMind\Request|NULL	$maxMind		*If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made	
	 * @return	\IPS\DateTime|NULL		Auth is valid until or NULL to indicate auth is good forever
	 * @throws	\LogicException			Message will be displayed to user
	 */
	public function auth( \IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL )
	{
		try {

			$settings = json_decode( $this->settings, TRUE );
			$transaction->save();
			$taxEnabled = isset( $settings['tax_enable'] ) ? !empty( $settings['tax_enable'] ) : TRUE;
			$taxBehavior = ( isset( $settings['tax_behavior'] ) AND \in_array( $settings['tax_behavior'], array( 'exclusive', 'inclusive' ), TRUE ) ) ? $settings['tax_behavior'] : 'exclusive';

			$customer = \IPS\nexus\Customer::loggedIn();

			$customerEmail = $transaction->invoice->member->member_id ? $transaction->invoice->member->email : $transaction->invoice->guest_data['member']['email'];

			$previousPurchases = 0;
			try
			{
				$previousPurchases = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_invoices', array( 'i_member=? AND i_status=?', $transaction->member->member_id, 'paid' ) )->first();
			}
			catch ( \Exception $e ) {}

			//
			// Creating session
			//
			$sessionBody = array(
				'mode' => 'payment',
				'success_url' => (string) $transaction->url()->setQueryString( 'pending', 1 ),
				'cancel_url' => (string) $transaction->invoice->checkoutUrl()->setQueryString( array( '_step' => 'checkout_pay', 'err' => $transaction->member->language()->get('gateway_err')  ) ),
				'currency' => $transaction->amount->currency,
				'client_reference_id' => $customer->member_id,
				'payment_intent_data' => array(
					'description' => $transaction->invoice->title,
					'receipt_email' => $customerEmail,
					'statement_descriptor_suffix' => \mb_substr( 'INV-' . $transaction->invoice->id, 0, 22 ),
					'metadata' => array(
						'ips_transaction_id'  => (string) $transaction->id,
						'ips_invoice_id'      => (string) $transaction->invoice->id,
						'ips_member_id'       => (string) $transaction->member->member_id,
						'customer_email'      => $customerEmail,
						'customer_ip'         => \IPS\Request::i()->ipAddress(),
						'product_description' => \mb_substr( (string) $transaction->invoice->title, 0, 500 ),
						'registration_ip'     => (string) $transaction->member->ip_address,
						'previous_purchases'  => (string) $previousPurchases,
					),
				),
				'metadata'             => array(
		               "transaction"       => $transaction->id,
		               "invoice"           => $transaction->invoice->id,
		               "customer"          => $transaction->member->member_id,
		               "customerEmail"     => $customerEmail,
		               "gateway"          => $this->id,
		           )
			);

			// Payment method(s) — empty or 'all' means Stripe decides
			$methods = array();
			if ( isset( $settings['method'] ) AND $settings['method'] !== '' AND $settings['method'] !== 'all' )
			{
				$methods = \is_array( $settings['method'] ) ? $settings['method'] : \explode( ',', $settings['method'] );
			}
			if ( \count( $methods ) )
			{
				$sessionBody['payment_method_types'] = \array_values( $methods );
			}

			// Collect shipping address for afterpay_clearpay and affirm
			if ( \in_array( 'affirm', $methods, TRUE ) )
			{
				$sessionBody['shipping_address_collection'] = array( 'allowed_countries' => array( 'CA', 'US' ) );
			}
			if ( \in_array( 'afterpay_clearpay', $methods, TRUE ) )
			{
				$sessionBody['shipping_address_collection'] = array( 'allowed_countries' => array( 'AU', 'CA', 'GB', 'NZ', 'US' ) );
			}

			// B5: Phone number collection (required field when enabled — feeds Radar for fraud signals)
			if ( !empty( $settings['phone_collection_enabled'] ) )
			{
				$sessionBody['phone_number_collection'] = array( 'enabled' => 'true' );
			}

			$sessionBody = $this->applyCheckoutTaxConfiguration( $sessionBody, $taxEnabled, $settings );

			$sessionBody['invoice_creation'] = array( 'enabled' => 'true' );

			// Add invoice line items (one per Nexus invoice item) with fallback to a single summary line.
			$sessionBody['line_items'] = $this->buildStripeLineItems( $transaction, $taxEnabled, $taxBehavior );

			// Handle invoice discounts (coupons, gateway discounts, etc.)
			$discountInfo = $this->calculateInvoiceDiscount( $transaction );

			if ( $discountInfo['amount_minor'] > 0 )
			{
				// Verify: positive line items minus discount must equal transaction amount
				$lineItemsTotal = 0;
				foreach ( $sessionBody['line_items'] as $li )
				{
					$lineItemsTotal += $li['price_data']['unit_amount'] * $li['quantity'];
				}
				$transactionMinor = $this->moneyToStripeMinorUnit( $transaction->amount );

				if ( ( $lineItemsTotal - $discountInfo['amount_minor'] ) === $transactionMinor )
				{
					// Math verified — use detailed items + Stripe coupon for proper display
					try
					{
						$couponId = $this->createOneTimeStripeCoupon( $discountInfo, $transaction->amount->currency, $settings );
						$sessionBody['discounts'] = array( array( 'coupon' => $couponId ) );
					}
					catch ( \Exception $e )
					{
						// Keep checkout available even if Stripe coupon creation fails.
						\IPS\Log::log( $e, 'xpolarcheckout_coupon' );
						unset( $sessionBody['discounts'] );
						$sessionBody['line_items'] = $this->buildDiscountSafetyFallbackLineItems( $transaction, $taxEnabled, $taxBehavior, $transactionMinor );
					}
				}
				else
				{
					// Mismatch safety fallback — single summary line with correct transaction amount
					$sessionBody['line_items'] = $this->buildDiscountSafetyFallbackLineItems( $transaction, $taxEnabled, $taxBehavior, $transactionMinor );
				}
			}

			$sessionBody['customer'] = $this->getCustomer( $transaction, $settings );

			// B4: Require TOS consent on Stripe Checkout page (admin must set TOS URL in Stripe Dashboard)
			if ( !empty( $settings['tos_consent_enabled'] ) )
			{
				$sessionBody['consent_collection'] = array( 'terms_of_service' => 'required' );
			}

			// B8: Request 3D Secure on all card transactions where supported (frictionless preferred)
			if ( !empty( $settings['threeds_enabled'] ) )
			{
				$sessionBody['payment_method_options'] = array( 'card' => array( 'request_three_d_secure' => 'any' ) );
			}

			// B11: Custom text shown above the Pay button on Stripe Checkout page (max 1200 chars)
			if ( !empty( $settings['custom_checkout_text'] ) )
			{
				$sessionBody['custom_text'] = array( 'submit' => array( 'message' => \mb_substr( (string) $settings['custom_checkout_text'], 0, 1200 ) ) );
			}

			$session = \IPS\Http\Url::external( 'https://api.stripe.com/v1/checkout/sessions' )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
				->post( $sessionBody )
				->decodeJson();

			if ( isset( $session['url'] ) )
			{
				$jsonHexFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
				$publishableJs = \json_encode( isset( $settings['publishable'] ) ? (string) $settings['publishable'] : '', $jsonHexFlags );
				$sessionIdJs = \json_encode( isset( $session['id'] ) ? (string) $session['id'] : '', $jsonHexFlags );
				if ( $publishableJs === FALSE OR $sessionIdJs === FALSE )
				{
					throw new \DomainException( 'Unable to safely encode Stripe checkout payload.' );
				}
				?>
	            <script type='text/javascript'>
	                $( document ).ready( function() {
	                    var stripe = Stripe( <?php echo $publishableJs; ?> );
	                    stripe.redirectToCheckout( { sessionId : <?php echo $sessionIdJs; ?> } );
	                } );
	            </script>
	            <?php
	            exit;
			}
			throw new \DomainException( $session['error']['message'] );
			
		} catch ( \Exception $e ) {
			
			throw new \DomainException( $e->getMessage() );
		}	
	}

	/**
	 * Build Stripe Checkout line items from Nexus invoice items.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @param	bool					$taxEnabled		Whether Stripe automatic tax is enabled
	 * @param	string					$taxBehavior	Tax behavior (`exclusive` or `inclusive`)
	 * @return	array
	 */
	protected function buildStripeLineItems( \IPS\nexus\Transaction $transaction, $taxEnabled, $taxBehavior )
	{
		$lineItems = array();

		foreach ( $transaction->invoice->items as $invoiceItem )
		{
			if ( !isset( $invoiceItem->price ) OR !( $invoiceItem->price instanceof \IPS\nexus\Money ) )
			{
				continue;
			}

			$quantity = isset( $invoiceItem->quantity ) ? (int) $invoiceItem->quantity : 1;
			if ( $quantity < 1 )
			{
				$quantity = 1;
			}

			$unitAmount = $this->moneyToStripeMinorUnit( $invoiceItem->price );
			if ( $unitAmount <= 0 )
			{
				continue;
			}

			$itemName = isset( $invoiceItem->name ) ? \trim( (string) $invoiceItem->name ) : '';
			if ( $itemName === '' )
			{
				$itemName = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_payment_invoice', false, array( 'sprintf' => array( $transaction->invoice->id ) ) );
			}
			\IPS\Member::loggedIn()->language()->parseOutputForDisplay( $itemName );

			$stripeItem = array(
				'price_data' => array(
					'currency' => $invoiceItem->price->currency,
					'product_data' => array(
						'name' => $itemName
					),
					'unit_amount' => $unitAmount,
				),
				'quantity' => $quantity
			);

			$lineItems[] = $this->applyLineItemTaxBehavior( $stripeItem, $taxEnabled, $taxBehavior );
		}

		// Keep checkout resilient when an invoice has no usable item rows.
		if ( !\count( $lineItems ) )
		{
			$fallbackAmount = $this->moneyToStripeMinorUnit( $transaction->invoice->amountToPay() );
			$fallbackName = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_payment_invoice', false, array( 'sprintf' => array( $transaction->invoice->id ) ) );
			\IPS\Member::loggedIn()->language()->parseOutputForDisplay( $fallbackName );

			$fallbackItem = array(
				'price_data' => array(
					'currency' => $transaction->invoice->amountToPay()->currency,
					'product_data' => array(
						'name' => $fallbackName
					),
					'unit_amount' => $fallbackAmount,
				),
				'quantity' => 1
			);

			$lineItems[] = $this->applyLineItemTaxBehavior( $fallbackItem, $taxEnabled, $taxBehavior );
		}

		return $lineItems;
	}

	/**
	 * Build single-line fallback payload for discount/coupon edge cases.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @param	bool					$taxEnabled		Whether automatic tax is enabled
	 * @param	string					$taxBehavior	Tax behavior
	 * @param	int|NULL				$amountMinor	Known transaction minor amount
	 * @return	array
	 */
	protected function buildDiscountSafetyFallbackLineItems( \IPS\nexus\Transaction $transaction, $taxEnabled, $taxBehavior, $amountMinor = NULL )
	{
		if ( !\is_int( $amountMinor ) )
		{
			$amountMinor = $this->moneyToStripeMinorUnit( $transaction->amount );
		}

		$fallbackName = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_payment_invoice', false, array( 'sprintf' => array( $transaction->invoice->id ) ) );
		\IPS\Member::loggedIn()->language()->parseOutputForDisplay( $fallbackName );
		$fallbackItem = array(
			'price_data' => array(
				'currency'     => $transaction->amount->currency,
				'product_data' => array( 'name' => $fallbackName ),
				'unit_amount'  => $amountMinor,
			),
			'quantity' => 1
		);

		return array( $this->applyLineItemTaxBehavior( $fallbackItem, $taxEnabled, $taxBehavior ) );
	}

	/**
	 * Apply tax/billing configuration to Checkout Session payload.
	 *
	 * @param	array	$sessionBody	Checkout Session payload
	 * @param	bool	$taxEnabled		Whether automatic tax is enabled
	 * @param	array	$settings		Gateway settings
	 * @return	array
	 */
	protected function applyCheckoutTaxConfiguration( array $sessionBody, $taxEnabled, array $settings )
	{
		if ( !$taxEnabled AND !empty( $settings['address_collection'] ) )
		{
			$sessionBody['billing_address_collection'] = 'required';
		}

		if ( $taxEnabled )
		{
			$sessionBody['automatic_tax'] = array( 'enabled' => 'true' );
			$sessionBody['customer_update'] = array( 'address' => 'auto', 'name' => 'auto' );
			$sessionBody['billing_address_collection'] = 'required';

			if ( !empty( $settings['tax_id_collection'] ) )
			{
				$sessionBody['tax_id_collection'] = array( 'enabled' => 'true' );
			}
		}

		return $sessionBody;
	}

	/**
	 * Apply Stripe line-item tax behavior when automatic tax is enabled.
	 *
	 * @param	array	$stripeItem	Stripe line item payload
	 * @param	bool	$taxEnabled	Whether automatic tax is enabled
	 * @param	string	$taxBehavior	Expected `exclusive` or `inclusive`
	 * @return	array
	 */
	protected function applyLineItemTaxBehavior( array $stripeItem, $taxEnabled, $taxBehavior )
	{
		if ( $taxEnabled )
		{
			$normalizedBehavior = \in_array( $taxBehavior, array( 'exclusive', 'inclusive' ), TRUE ) ? $taxBehavior : 'exclusive';
			$stripeItem['price_data']['tax_behavior'] = $normalizedBehavior;
		}

		return $stripeItem;
	}

	/**
	 * Calculate total discount from negative invoice items (coupons, gateway discounts, etc.).
	 *
	 * IPS Nexus resolves all discount types (percentage, fixed, product-specific, gateway)
	 * into fixed negative-amount line items. This method sums them for Stripe.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @return	array	Keys: 'amount_minor' (int, positive), 'names' (array of strings)
	 */
	protected function calculateInvoiceDiscount( \IPS\nexus\Transaction $transaction )
	{
		$discountMinor = 0;
		$discountNames = array();

		foreach ( $transaction->invoice->items as $invoiceItem )
		{
			if ( !isset( $invoiceItem->price ) OR !( $invoiceItem->price instanceof \IPS\nexus\Money ) )
			{
				continue;
			}

			$unitAmount = $this->moneyToStripeMinorUnit( $invoiceItem->price );
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
	 * Create a one-time Stripe coupon for invoice discounts.
	 *
	 * @param	array	$discountInfo	Result from calculateInvoiceDiscount()
	 * @param	string	$currency		Currency code
	 * @param	array	$settings		Gateway settings
	 * @return	string	Stripe coupon ID
	 * @throws	\RuntimeException
	 */
	protected function createOneTimeStripeCoupon( array $discountInfo, $currency, array $settings )
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
		// Stripe coupon name max length is 40 chars.
		if ( \mb_strlen( $couponName ) > 40 )
		{
			$couponName = \rtrim( \mb_substr( $couponName, 0, 40 ) );
		}

		$response = \IPS\Http\Url::external( 'https://api.stripe.com/v1/coupons' )
			->request( 20 )
			->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
			->post( array(
				'amount_off' => $discountInfo['amount_minor'],
				'currency'   => $currency,
				'duration'   => 'once',
				'name'       => $couponName,
			) )
			->decodeJson();

		if ( !isset( $response['id'] ) )
		{
			throw new \RuntimeException( 'Failed to create Stripe coupon for invoice discount.' );
		}

		return $response['id'];
	}

	/**
	 * Convert Money to Stripe minor-unit integer safely (no float math).
	 *
	 * @param	\IPS\nexus\Money	$money	Money object
	 * @return	int
	 */
	protected function moneyToStripeMinorUnit( \IPS\nexus\Money $money )
	{
		$decimals = \IPS\nexus\Money::numberOfDecimalsForCurrency( $money->currency );
		$multiplier = new \IPS\Math\Number( '1' . \str_repeat( '0', $decimals ) );
		$minor = $money->amount->multiply( $multiplier );

		return (int) (string) $minor;
	}

	/**
	 * Check the gateway can process this...
	 *
	 * @param	$amount			\IPS\nexus\Money		The amount
	 * @param	$billingAddress	\IPS\GeoLocation|NULL	The billing address, which may be NULL if one if not provided
	 * @param	$customer		\IPS\nexus\Customer		The customer (Default NULL value is for backwards compatibility - it should always be provided.)
	 * @param	array			$recurrings				Details about recurring costs
	 * @return	bool
	 */
	public function checkValidity( \IPS\nexus\Money $amount, \IPS\GeoLocation $billingAddress = NULL, \IPS\nexus\Customer $customer = NULL, $recurrings = array() )
	{
		return parent::checkValidity( $amount, $billingAddress, $customer, $recurrings );
	}

	/**
	 * Resolve or create Stripe customer id for this transaction member.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @param	array					$settings		Gateway settings
	 * @return	string
	 */
	public function getCustomer( $transaction, $settings )
	{
		$customer = \IPS\nexus\Customer::loggedIn();

		// Get client or creating new
		try {

			$profiles = $transaction->member->cm_profiles;
			if( !isset( $profiles[ $this->id ] ) )
			{
				throw new \Exception("Error Processing Request", 1);
			}

			// check if customer exists
			$client = \IPS\Http\Url::external( 'https://api.stripe.com/v1/customers/' . $profiles[ $this->id ] )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
				->get()
				->decodeJson();

			if( isset( $client['deleted'] ) AND $client['deleted'] == 1 )
			{
				throw new \Exception("Error Processing Request", 1);
			}

			$customerId = $profiles[ $this->id ];
			
		} catch ( \Exception $e ) {

			// creating one
		    $clientObject = array(
				'name' => $customer->cm_first_name . ' ' . $customer->cm_last_name,
				'email' => $customer->email,
				'metadata' => array(
					'ips_member_id'   => (string) $customer->member_id,
					'account_created' => $customer->joined ? \date( 'Y-m-d', $customer->joined->getTimestamp() ) : NULL,
				),
			);

		    // Add address, if need for calculating
			if( $settings['tax_enable'] )
			{
				try {
				
					$address = \IPS\Db::i()->select( '*', 'nexus_customer_addresses', array( '`member`=?', \IPS\Member::loggedIn()->member_id ) )->first();
			
					$address = json_decode( $address['address'] );
				    $addressArray = array(
				        'city'          => $address->city,
				        'country'       => $address->country,
				        'line1'         => isset( $address->addressLines[0] ) ? $address->addressLines[0] : NULL,
				        'line2'         => isset( $address->addressLines[1] ) ? $address->addressLines[1] : NULL,
				        'postal_code'   => $address->postalCode,
				        'state'         => $address->region,
				    );

				    // Add to client
				    $clientObject[ 'address' ] = $addressArray;

				} catch ( \Exception $e ) {}
			}

		    $client = \IPS\Http\Url::external( 'https://api.stripe.com/v1/customers' )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
				->post( $clientObject )
				->decodeJson();

			$customerId = $client['id'];

			// Save customer
			$profiles[ $this->id ] = $client['id'];
			$transaction->member->cm_profiles = $profiles;
	        $transaction->member->save();
		}

		return $customerId;
	}
    
    /* !ACP Configuration */
	
	/**
	 * Settings
	 *
	 * @param	\IPS\Helpers\Form	$form	The form
	 * @return	void
	 */
	public function settings( &$form )
	{
		$settings = $this->buildSettingsWithLegacyDefaults();
		$storedSettings = json_decode( $this->settings, TRUE );
		if ( !\is_array( $storedSettings ) )
		{
			$storedSettings = array();
		}

		$form->addHeader('xpolarcheckout_credits');
		$form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_publishable', isset( $settings['publishable'] ) ? $settings['publishable'] : NULL, TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_secret', isset( $settings['secret'] ) ? $settings['secret'] : NULL, TRUE ) );

		$form->addHeader('xpolarcheckout_webhook');
		$form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_webhook_url', isset( $storedSettings['webhook_url'] ) ? $storedSettings['webhook_url'] : NULL, FALSE, array( 'disabled' => isset( $storedSettings['webhook_url'] ) ? FALSE : TRUE ) ) );
		$form->add( new \IPS\Helpers\Form\Text( 'xpolarcheckout_webhook_secret', isset( $storedSettings['webhook_secret'] ) ? $storedSettings['webhook_secret'] : NULL, FALSE, array( 'disabled' => isset( $storedSettings['webhook_secret'] ) ? FALSE : TRUE ) ) );

		$form->addHeader('xpolarcheckout_tax');
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_tax_enable', isset( $settings['tax_enable'] ) ? $settings['tax_enable'] : TRUE, FALSE, array('togglesOn' => array('tax_behavior', 'tax_id_collection') ) ) );
		$form->add( new \IPS\Helpers\Form\Select( 'xpolarcheckout_tax_behavior', isset( $settings['tax_behavior'] ) ? $settings['tax_behavior'] : 'exclusive', FALSE, array( 'options' => array('inclusive' => \IPS\Member::loggedIn()->language()->get('xpolarcheckout_tax_behavior_inclusive'), 'exclusive' => \IPS\Member::loggedIn()->language()->get('xpolarcheckout_tax_behavior_exclusive') ) ), NULL, NULL, NULL, 'tax_behavior' ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_tax_id_collection', isset( $settings['tax_id_collection'] ) ? $settings['tax_id_collection'] : FALSE, FALSE, array(), NULL, NULL, NULL, 'tax_id_collection' ) );

		/* Tax readiness — read-only summary from last refresh */
		if ( isset( $storedSettings['tax_readiness_status'] ) )
		{
			$trStatus = (string) $storedSettings['tax_readiness_status'];
			$trChecked = isset( $storedSettings['tax_readiness_last_checked'] ) ? (int) $storedSettings['tax_readiness_last_checked'] : 0;
			$trRegCount = isset( $storedSettings['tax_readiness_registrations_count'] ) ? (int) $storedSettings['tax_readiness_registrations_count'] : 0;
			$trRegSummary = isset( $storedSettings['tax_readiness_registrations_summary'] ) ? (string) $storedSettings['tax_readiness_registrations_summary'] : '';

			$statusLabel = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_' . $trStatus );
			$checkedLabel = $trChecked > 0 ? \gmdate( 'Y-m-d H:i:s', $trChecked ) . ' UTC' : '-';
			$regLabel = $trRegCount . ( $trRegSummary !== '' ? ' (' . $trRegSummary . ')' : '' );

			$summaryHtml = '<strong>' . \htmlspecialchars( $statusLabel, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ) . '</strong>';
			$summaryHtml .= ' &mdash; ' . \htmlspecialchars( $regLabel, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ) . ' ' . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_registrations_label' );
			$summaryHtml .= '<br><small>' . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_last_checked' ) . ': ' . \htmlspecialchars( $checkedLabel, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ) . '</small>';

			if ( $trStatus === 'not_collecting' OR $trStatus === 'error' )
			{
				$summaryHtml .= '<br><span style="color:#d97706;">' . \htmlspecialchars( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_warning' ), ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ) . '</span>';
			}

			$form->addHtml( '<div class="ipsMessage ipsMessage_info" id="xpolarcheckout_tax_readiness_summary">' . $summaryHtml . '</div>' );
		}

		$form->addHeader('xpolarcheckout_replay');
		$form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_lookback', isset( $settings['replay_lookback'] ) ? (int) $settings['replay_lookback'] : 3600, FALSE, array( 'min' => 300, 'max' => 86400 ) ) );
		$form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_overlap', isset( $settings['replay_overlap'] ) ? (int) $settings['replay_overlap'] : 300, FALSE, array( 'min' => 60, 'max' => 1800 ) ) );
		$form->add( new \IPS\Helpers\Form\Number( 'xpolarcheckout_replay_max_events', isset( $settings['replay_max_events'] ) ? (int) $settings['replay_max_events'] : 100, FALSE, array( 'min' => 10, 'max' => 100 ) ) );

		$form->addHeader('xpolarcheckout_methods');
		$methodDefault = array();
		if ( isset( $settings['method'] ) AND $settings['method'] !== '' AND $settings['method'] !== 'all' )
		{
			$methodDefault = \is_array( $settings['method'] ) ? $settings['method'] : \explode( ',', $settings['method'] );
		}
		$form->add( new \IPS\Helpers\Form\Select( 'xpolarcheckout_method', $methodDefault, FALSE, array(
			'multiple'	=> TRUE,
			'noDefault'	=> TRUE,
			'options'	=> array(
				'card' 				=> 'xpolarcheckout_methods_card',
				'acss_debit' 		=> 'xpolarcheckout_methods_acss_debit',
				'affirm'			=> 'xpolarcheckout_methods_affirm',
				'afterpay_clearpay'	=> 'xpolarcheckout_methods_afterpay_clearpay',
				'alipay'			=> 'xpolarcheckout_methods_alipay',
				'au_becs_debit'		=> 'xpolarcheckout_methods_au_becs_debit',
				'bacs_debit'		=> 'xpolarcheckout_methods_bacs_debit',
				'bancontact'		=> 'xpolarcheckout_methods_bancontact',
				'blik'				=> 'xpolarcheckout_methods_blik',
				'boleto'			=> 'xpolarcheckout_methods_boleto',
				'cashapp'			=> 'xpolarcheckout_methods_cashapp',
				'customer_balance'	=> 'xpolarcheckout_methods_customer_balance',
				'eps'				=> 'xpolarcheckout_methods_eps',
				'fpx'				=> 'xpolarcheckout_methods_fpx',
				'giropay'			=> 'xpolarcheckout_methods_giropay',
				'grabpay'			=> 'xpolarcheckout_methods_grabpay',
				'ideal'				=> 'xpolarcheckout_methods_ideal',
				'klarna'			=> 'xpolarcheckout_methods_klarna',
				'konbini'			=> 'xpolarcheckout_methods_konbini',
				'link'				=> 'xpolarcheckout_methods_link',
				'oxxo'				=> 'xpolarcheckout_methods_oxxo',
				'p24'				=> 'xpolarcheckout_methods_p24',
				'paynow'			=> 'xpolarcheckout_methods_paynow',
				'pix'				=> 'xpolarcheckout_methods_pix',
				'promptpay'			=> 'xpolarcheckout_methods_promptpay',
				'sepa_debit'		=> 'xpolarcheckout_methods_sepa_debit',
				'sofort'			=> 'xpolarcheckout_methods_sofort',
				'us_bank_account'	=> 'xpolarcheckout_methods_us_bank_account',
				'wechat_pay'		=> 'xpolarcheckout_methods_wechat_pay',
			)
		) ) );

		$form->addHeader('xpolarcheckout_fraud_protection');
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_dispute_ban', isset( $settings['dispute_ban'] ) ? $settings['dispute_ban'] : TRUE, FALSE, array() ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_phone_collection_enabled', isset( $settings['phone_collection_enabled'] ) ? $settings['phone_collection_enabled'] : FALSE, FALSE, array() ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_tos_consent_enabled', isset( $settings['tos_consent_enabled'] ) ? $settings['tos_consent_enabled'] : FALSE, FALSE, array() ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_threeds_enabled', isset( $settings['threeds_enabled'] ) ? $settings['threeds_enabled'] : FALSE, FALSE, array() ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'xpolarcheckout_custom_checkout_text', isset( $settings['custom_checkout_text'] ) ? $settings['custom_checkout_text'] : '', FALSE, array( 'maxLength' => 1200 ) ) );

		$form->addHeader('xpolarcheckout_countries');
		$form->add( new \IPS\Helpers\Form\YesNo( 'xpolarcheckout_address_collection', isset( $settings['address_collection'] ) ? $settings['address_collection'] : FALSE, FALSE, array() ) );
	}
	
	/**
	 * Test Settings
	 *
	 * @return	void
	 * @throws	\InvalidArgumentException
	 */
	public function testSettings( $settings )
	{
		if ( !\is_array( $settings ) )
		{
			$settings = array();
		}

		$settings = $this->applyLegacySettingDefaults( $settings );

		if ( !isset( $settings['tax_enable'] ) )
		{
			$settings['tax_enable'] = TRUE;
		}

		if ( !empty( $settings['tax_enable'] ) )
		{
			if ( !isset( $settings['tax_behavior'] ) OR !\in_array( $settings['tax_behavior'], array( 'exclusive', 'inclusive' ), TRUE ) )
			{
				$settings['tax_behavior'] = 'exclusive';
			}
		}

		if ( empty( $settings['webhook_url'] ) AND empty( $settings['webhook_secret'] ) )
		{
			$url = (string) \IPS\Http\Url::internal( 'app=xpolarcheckout&module=webhook&controller=webhook', 'front' );

			$webhook = \IPS\Http\Url::external( 'https://api.stripe.com/v1/webhook_endpoints' )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
				->post( array( 'url' => $url, 'enabled_events' => self::REQUIRED_WEBHOOK_EVENTS, 'api_version' => self::STRIPE_VERSION ) )
				->decodeJson();

			$settings['webhook_url'] = $webhook['url'];
			$settings['webhook_secret'] = $webhook['secret'];
			$settings['webhook_endpoint_id'] = isset( $webhook['id'] ) ? $webhook['id'] : NULL;
		}

		/* Best-effort tax readiness refresh — never blocks gateway save */
		if ( !empty( $settings['secret'] ) )
		{
			try
			{
				$settings = static::applyTaxReadinessSnapshotToSettings( $settings );
			}
			catch ( \Exception $e )
			{
				\IPS\Log::log( $e, 'xpolarcheckout_tax' );
			}
		}

		return $settings;
	}

	/**
	 * Collect lightweight alert stats for AdminNotification checks.
	 *
	 * Shared between the integrity panel and the integrityMonitor task.
	 * Only local DB queries — no external API calls.
	 *
	 * @return	array
	 */
	public static function collectAlertStats()
	{
		$stats = array(
			'webhook_error_count_24h' => 0,
			'replay_recent_run' => FALSE,
			'mismatch_count_30d' => 0,
			'webhook_events_missing' => array(),
			'tax_readiness_status' => 'unknown',
			'replay_config_lookback' => 3600,
		);

		/* Gateway settings */
		$gatewaySettings = NULL;
		foreach ( \IPS\nexus\Gateway::roots() as $gateway )
		{
			if ( $gateway instanceof static )
			{
				$gatewaySettings = \json_decode( $gateway->settings, TRUE );
				if ( !\is_array( $gatewaySettings ) )
				{
					$gatewaySettings = NULL;
				}
				break;
			}
		}

		if ( \is_array( $gatewaySettings ) )
		{
			/* Replay config */
			if ( isset( $gatewaySettings['replay_lookback'] ) )
			{
				$stats['replay_config_lookback'] = \max( 300, \min( 86400, (int) $gatewaySettings['replay_lookback'] ) );
			}

			/* Tax readiness */
			if ( isset( $gatewaySettings['tax_readiness_status'] ) )
			{
				$stats['tax_readiness_status'] = (string) $gatewaySettings['tax_readiness_status'];
			}
		}

		/* Replay state */
		if ( isset( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) && \is_array( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) )
		{
			$replayState = \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state;
			$lastRunAt = ( isset( $replayState['last_run_at'] ) && \is_numeric( $replayState['last_run_at'] ) ) ? (int) $replayState['last_run_at'] : NULL;
			$stats['replay_recent_run'] = ( $lastRunAt !== NULL && ( \time() - $lastRunAt ) <= $stats['replay_config_lookback'] );
		}

		/* DB queries */
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
			$stats['mismatch_count_30d'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_transactions', array( "{$mismatchWhere} AND t_date>?", $monthAgo ) )->first();
		}
		catch ( \Exception $e ) {}

		return $stats;
	}

	/**
	 * Fetch current webhook endpoint configuration from Stripe.
	 *
	 * @param	array	$settings	Gateway settings (must include 'secret')
	 * @return	array|NULL			Stripe webhook endpoint object, or NULL on error/not found
	 */
	public static function fetchWebhookEndpoint( array $settings )
	{
		if ( empty( $settings['secret'] ) )
		{
			return NULL;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $settings['secret'],
			'Stripe-Version' => static::STRIPE_VERSION,
		);

		try
		{
			/* Fast path: endpoint ID is stored */
			if ( !empty( $settings['webhook_endpoint_id'] ) )
			{
				$endpointById = \IPS\Http\Url::external( 'https://api.stripe.com/v1/webhook_endpoints/' . $settings['webhook_endpoint_id'] )
					->request( 20 )
					->setHeaders( $headers )
					->get()
					->decodeJson();
				if ( \is_array( $endpointById ) AND isset( $endpointById['id'] ) )
				{
					return $endpointById;
				}
			}

			/* Legacy fallback: find by URL match */
			if ( !empty( $settings['webhook_url'] ) )
			{
				$response = \IPS\Http\Url::external( 'https://api.stripe.com/v1/webhook_endpoints' )
					->setQueryString( array( 'limit' => 100 ) )
					->request( 20 )
					->setHeaders( $headers )
					->get()
					->decodeJson();

				if ( isset( $response['data'] ) AND \is_array( $response['data'] ) )
				{
					foreach ( $response['data'] as $endpoint )
					{
						if ( isset( $endpoint['url'] ) AND $endpoint['url'] === $settings['webhook_url'] )
						{
							return $endpoint;
						}
					}
				}
			}
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_webhook_sync' );
		}

		return NULL;
	}

	/**
	 * Update Stripe webhook endpoint to include all required events and current API version.
	 *
	 * @param	array	$settings		Gateway settings (must include 'secret')
	 * @param	string	$endpointId		Stripe webhook endpoint ID
	 * @return	array					Updated endpoint object
	 * @throws	\RuntimeException		On Stripe API error
	 */
	public static function syncWebhookEvents( array $settings, $endpointId )
	{
		$response = \IPS\Http\Url::external( 'https://api.stripe.com/v1/webhook_endpoints/' . $endpointId )
			->request( 20 )
			->setHeaders( array(
				'Authorization' => 'Bearer ' . $settings['secret'],
				'Stripe-Version' => static::STRIPE_VERSION,
			) )
			->post( array(
				'enabled_events' => static::REQUIRED_WEBHOOK_EVENTS,
				'api_version' => static::STRIPE_VERSION,
			) )
			->decodeJson();

		if ( !isset( $response['id'] ) )
		{
			$detail = '';
			if ( isset( $response['error']['message'] ) )
			{
				$detail = (string) $response['error']['message'];
			}
			elseif ( isset( $response['error']['type'] ) )
			{
				$detail = (string) $response['error']['type'];
			}
			throw new \RuntimeException( 'Stripe webhook endpoint sync failed' . ( $detail !== '' ? ': ' . $detail : ': unexpected response' ) );
		}

		return $response;
	}

	/**
	 * Fetch Stripe Tax readiness status and active registrations.
	 *
	 * @param	array	$settings	Gateway settings (must include 'secret')
	 * @return	array				Normalized tax readiness snapshot
	 */
	public static function fetchTaxReadiness( array $settings )
	{
		if ( empty( $settings['secret'] ) )
		{
			return static::normalizeTaxReadiness( NULL, NULL );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $settings['secret'],
			'Stripe-Version' => static::STRIPE_VERSION,
		);

		$taxSettings = NULL;
		$registrations = NULL;

		try
		{
			$taxSettings = \IPS\Http\Url::external( 'https://api.stripe.com/v1/tax/settings' )
				->request( 20 )
				->setHeaders( $headers )
				->get()
				->decodeJson();
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_tax' );
		}

		try
		{
			$regResponse = \IPS\Http\Url::external( 'https://api.stripe.com/v1/tax/registrations' )
				->setQueryString( array( 'limit' => 100 ) )
				->request( 20 )
				->setHeaders( $headers )
				->get()
				->decodeJson();
			$registrations = ( isset( $regResponse['data'] ) AND \is_array( $regResponse['data'] ) ) ? $regResponse['data'] : array();
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_tax' );
		}

		return static::normalizeTaxReadiness( $taxSettings, $registrations );
	}

	/**
	 * Normalize Stripe Tax settings + registrations into a canonical readiness snapshot.
	 *
	 * @param	array|NULL	$taxSettings	Stripe tax/settings response (NULL on error)
	 * @param	array|NULL	$registrations	Stripe tax/registrations data array (NULL on error)
	 * @return	array						Keys: status, last_checked, registrations_count, registrations_summary, details, error
	 */
	public static function normalizeTaxReadiness( $taxSettings, $registrations )
	{
		$snapshot = array(
			'tax_readiness_status' => 'unknown',
			'tax_readiness_last_checked' => \time(),
			'tax_readiness_registrations_count' => 0,
			'tax_readiness_registrations_summary' => '',
			'tax_readiness_details' => array(),
			'tax_readiness_error' => '',
		);

		/* Both API calls failed */
		if ( $taxSettings === NULL AND $registrations === NULL )
		{
			$snapshot['tax_readiness_status'] = 'error';
			$snapshot['tax_readiness_error'] = 'Failed to fetch Stripe Tax settings';
			return $snapshot;
		}

		/* Tax settings malformed */
		if ( !\is_array( $taxSettings ) OR !isset( $taxSettings['status'] ) )
		{
			$snapshot['tax_readiness_status'] = 'error';
			$snapshot['tax_readiness_error'] = 'Malformed tax settings response';
			return $snapshot;
		}

		$stripeStatus = (string) $taxSettings['status'];
		$snapshot['tax_readiness_details'] = array(
			'stripe_status' => $stripeStatus,
		);

		/* Count active registrations */
		$activeRegs = array();
		if ( \is_array( $registrations ) )
		{
			foreach ( $registrations as $reg )
			{
				if ( isset( $reg['status'] ) AND $reg['status'] === 'active' )
				{
					$activeRegs[] = $reg;
				}
			}
		}
		$snapshot['tax_readiness_registrations_count'] = \count( $activeRegs );

		/* Build short summary (e.g. "DE, DE (EU OSS), US-CA") */
		$summaryParts = array();
		foreach ( $activeRegs as $reg )
		{
			$country = isset( $reg['country'] ) ? (string) $reg['country'] : '';
			if ( $country === '' )
			{
				continue;
			}

			/* Stripe nests type and state under country_options.{lowercase_country} */
			$type = '';
			$state = '';
			$countryKey = \mb_strtolower( $country );
			if ( isset( $reg['country_options'] ) AND \is_array( $reg['country_options'] ) )
			{
				if ( isset( $reg['country_options'][ $countryKey ] ) AND \is_array( $reg['country_options'][ $countryKey ] ) )
				{
					$opts = $reg['country_options'][ $countryKey ];
					$type = isset( $opts['type'] ) ? (string) $opts['type'] : '';
					$state = isset( $opts['state'] ) ? (string) $opts['state'] : '';
				}
			}

			$label = $state !== '' ? ( $country . '-' . $state ) : $country;

			/* Append registration type qualifier for EU schemes */
			if ( $type === 'oss_union' OR $type === 'oss_non_union' )
			{
				$label .= ' (EU OSS)';
			}
			elseif ( $type === 'ioss' )
			{
				$label .= ' (IOSS)';
			}

			$summaryParts[] = $label;
		}
		$snapshot['tax_readiness_registrations_summary'] = \implode( ', ', $summaryParts );

		/* Determine canonical status */
		if ( $stripeStatus === 'active' AND \count( $activeRegs ) >= 1 )
		{
			$snapshot['tax_readiness_status'] = 'collecting';
		}
		elseif ( $stripeStatus === 'active' AND \count( $activeRegs ) === 0 )
		{
			$snapshot['tax_readiness_status'] = 'not_collecting';
		}
		elseif ( $stripeStatus === 'pending' )
		{
			$snapshot['tax_readiness_status'] = 'not_collecting';
			if ( isset( $taxSettings['status_details']['pending']['missing_fields'] ) AND \is_array( $taxSettings['status_details']['pending']['missing_fields'] ) )
			{
				$snapshot['tax_readiness_details']['missing_fields'] = $taxSettings['status_details']['pending']['missing_fields'];
			}
		}
		else
		{
			$snapshot['tax_readiness_status'] = 'unknown';
		}

		return $snapshot;
	}

	/**
	 * Refresh tax readiness snapshot and merge into gateway settings array.
	 *
	 * @param	array	$settings	Current gateway settings
	 * @return	array				Settings with tax readiness fields updated
	 */
	public static function applyTaxReadinessSnapshotToSettings( array $settings )
	{
		$snapshot = static::fetchTaxReadiness( $settings );
		foreach ( $snapshot as $key => $value )
		{
			$settings[ $key ] = $value;
		}

		return $settings;
	}

	/**
	 * Merge current gateway settings with defaults sourced from legacy StripeCheckout.
	 *
	 * @return	array
	 */
	protected function buildSettingsWithLegacyDefaults()
	{
		$settings = json_decode( $this->settings, TRUE );
		if ( !\is_array( $settings ) )
		{
			$settings = array();
		}

		return $this->applyLegacySettingDefaults( $settings );
	}

	/**
	 * Apply legacy StripeCheckout values for non-webhook fields only.
	 *
	 * @param	array	$settings	Current xpolarcheckout settings
	 * @return	array
	 */
	protected function applyLegacySettingDefaults( array $settings )
	{
		$legacySettings = $this->loadLegacyStripeCheckoutSettings();
		$migrationKeys = array( 'publishable', 'secret', 'tax_enable', 'tax_behavior', 'method', 'dispute_ban', 'address_collection' );

		foreach ( $migrationKeys as $key )
		{
			if ( !isset( $settings[ $key ] ) AND isset( $legacySettings[ $key ] ) )
			{
				$settings[ $key ] = $legacySettings[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * Read settings from the legacy StripeCheckout gateway, if present.
	 *
	 * @return	array
	 */
	protected function loadLegacyStripeCheckoutSettings()
	{
		try
		{
			$legacyRaw = \IPS\Db::i()->select(
				'm_settings',
				'nexus_paymethods',
				array( 'm_gateway=?', 'StripeCheckout' ),
				'm_id DESC',
				1
			)->first();

			$legacySettings = json_decode( $legacyRaw, TRUE );
			if ( \is_array( $legacySettings ) )
			{
				return $legacySettings;
			}
		}
		catch ( \UnderflowException $e ) {}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_legacy_settings' );
		}

		return array();
	}

	/**
	 * Refund
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction to be refunded
	 * @param	float|NULL				$amount			Amount to refund (NULL for full amount - always in same currency as transaction)
	 * @return	mixed									Gateway reference ID for refund, if applicable
	 * @throws	\Exception
 	 */
	public function refund( \IPS\nexus\Transaction $transaction, $amount = NULL, $reason = NULL )
	{
		$settings = json_decode( $this->settings, TRUE );

		try {
		
			$body = NULL;

			if ( $amount )
			{
				$refundMoney = new \IPS\nexus\Money( $amount, $transaction->currency );
				$body['amount'] = (string) $this->moneyToStripeMinorUnit( $refundMoney );
			}

			// Add intent
			$body['payment_intent'] = $transaction->gw_id;

			// Reason
			if( $reason )
			{
				$body['reason'] = $reason;
			}

			$refund = \IPS\Http\Url::external( 'https://api.stripe.com/v1/refunds' )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => self::STRIPE_VERSION ) )
				->post( $body )
				->decodeJson();
			
			return isset( $refund['id'] ) ? $refund['id'] : NULL;

		} catch ( \Exception $e ) {

			\IPS\Log::log( $e );
			throw new \RuntimeException();
			
		}
	}

	/**
	 * Refund Reasons that the gateway understands, if the gateway supports this
	 *
	 * @return	array
 	 */
	public static function refundReasons()
	{
		return array(
			'duplicate' => 'xpolarcheckout_reason_duplicate',
			'fraudulent' => 'xpolarcheckout_reason_fraudulent',
			'requested_by_customer' => 'xpolarcheckout_reason_requested_by_customer'
		);
	}

	/**
	 * URL to view transaction in gateway
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @return	\IPS\Http\Url|NULL
 	 */
	public function gatewayUrl( \IPS\nexus\Transaction $transaction )
	{
		return \IPS\Http\Url::external( "https://dashboard.stripe.com/payments/{$transaction->gw_id}" );
	}
}
