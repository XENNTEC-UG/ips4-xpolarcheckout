<?php

namespace IPS\xpolarcheckout\modules\front\webhook;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

/**
 * Polar webhook controller
 */
class _webhook extends \IPS\Dispatcher\Controller
{
    /**
     * Execute
     *
     * @return void
     */
    public function execute()
    {
        parent::execute();
    }

    /**
     * Process incoming webhook events.
     *
     * @return void
     */
    protected function manage()
    {
        $body = @\file_get_contents( 'php://input' );
        $payload = \json_decode( $body, TRUE );

        if ( !\is_array( $payload ) )
        {
            $this->logForensicEvent( 'invalid_payload', 400, 'unknown', NULL, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_PAYLOAD', 400 );
            return;
        }

        $eventType = $this->resolveEventType( $payload );
        $eventId = $this->extractWebhookEventId( $payload );

        $signature = isset( $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ) ? (string) $_SERVER['HTTP_WEBHOOK_SIGNATURE'] : '';
        $timestamp = isset( $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ) ? (string) $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] : '';
        $webhookId = isset( $_SERVER['HTTP_WEBHOOK_ID'] ) ? (string) $_SERVER['HTTP_WEBHOOK_ID'] : '';

        if ( $signature === '' )
        {
            $this->logForensicEvent( 'missing_signature', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'MISSING_SIGNATURE', 403 );
            return;
        }

        $transaction = $this->resolveTransactionFromPayload( $payload, $eventType );
        if ( !$transaction )
        {
            \IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
            return;
        }

        $settings = \json_decode( $transaction->method->settings, TRUE );
        if ( !\is_array( $settings ) )
        {
            \IPS\Output::i()->sendOutput( 'INVALID_GATEWAY_SETTINGS', 400 );
            return;
        }

        $secret = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
        if ( !$this->checkSignature( $signature, $body, $secret, $eventType, $eventId, $timestamp, $webhookId ) )
        {
            return;
        }

        if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
        {
            \IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
            return;
        }

        $lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
        if ( !$lockAcquired )
        {
            \IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
            return;
        }

        try
        {
            $eventObject = $this->getEventObject( $payload );

            switch ( $eventType )
            {
                case 'order.created':
                    $this->storeGatewayOrderId( $transaction, $eventObject, TRUE );
                    $this->applyGatewayPendingTransition( $transaction, 'order_created' );
                    break;

                case 'order.paid':
                    $this->storeGatewayOrderId( $transaction, $eventObject, TRUE );
                    $this->applyPaidTransition( $transaction );
                    break;

                case 'order.updated':
                case 'order.refunded':
                    $this->storeGatewayOrderId( $transaction, $eventObject, TRUE );
                    $this->applyOrderStatusTransition( $transaction, $eventObject );
                    break;

                case 'refund.updated':
                    $this->applyRefundStatusTransition( $transaction, $eventObject );
                    break;

                case 'checkout.updated':
                    $this->applyCheckoutStatusTransition( $transaction, $eventObject );
                    break;

                case 'refund.created':
                default:
                    break;
            }

            $this->persistPolarSnapshot( $transaction, $payload, $eventType );
            $this->markWebhookEventProcessed( $transaction, $eventId, $eventType );

            \IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
            return;
        }
        catch ( \Throwable $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_webhook' );
            \IPS\Output::i()->sendOutput( 'UNABLE_TO_PROCESS_EVENT', 500 );
            return;
        }
        finally
        {
            $this->releaseTransactionProcessingLock( (int) $transaction->id );
        }
    }

    /**
     * Resolve event type from payload.
     *
     * @param array $eventPayload
     * @return string
     */
    protected function resolveEventType( array $eventPayload )
    {
        if ( isset( $eventPayload['type'] ) && \is_string( $eventPayload['type'] ) )
        {
            return $eventPayload['type'];
        }

        if ( isset( $eventPayload['event'] ) && \is_string( $eventPayload['event'] ) )
        {
            return $eventPayload['event'];
        }

        return 'unknown';
    }

    /**
     * Extract event id from payload or headers.
     *
     * @param array $eventPayload
     * @return string|NULL
     */
    protected function extractWebhookEventId( array $eventPayload )
    {
        if ( isset( $_SERVER['HTTP_WEBHOOK_ID'] ) && $_SERVER['HTTP_WEBHOOK_ID'] !== '' )
        {
            return (string) $_SERVER['HTTP_WEBHOOK_ID'];
        }

        if ( isset( $eventPayload['id'] ) && \is_scalar( $eventPayload['id'] ) )
        {
            return (string) $eventPayload['id'];
        }

        return NULL;
    }

    /**
     * Resolve transaction from webhook metadata.
     *
     * @param array  $eventPayload
     * @param string $eventType
     * @return \IPS\nexus\Transaction|NULL
     */
    protected function resolveTransactionFromPayload( array $eventPayload, $eventType )
    {
        $eventObject = $this->getEventObject( $eventPayload );

        $metadata = array();
        if ( isset( $eventObject['metadata'] ) && \is_array( $eventObject['metadata'] ) )
        {
            $metadata = $eventObject['metadata'];
        }

        $candidateIds = array();
        if ( isset( $metadata['ips_transaction_id'] ) )
        {
            $candidateIds[] = (int) $metadata['ips_transaction_id'];
        }
        if ( isset( $metadata['transaction'] ) )
        {
            $candidateIds[] = (int) $metadata['transaction'];
        }

        foreach ( $candidateIds as $candidateId )
        {
            if ( $candidateId > 0 )
            {
                try
                {
                    return \IPS\nexus\Transaction::load( $candidateId );
                }
                catch ( \Exception $e ) {}
            }
        }

        $providerOrderId = NULL;
        if ( isset( $eventObject['order_id'] ) && \is_scalar( $eventObject['order_id'] ) )
        {
            $providerOrderId = (string) $eventObject['order_id'];
        }
        elseif ( isset( $eventObject['id'] ) && \is_scalar( $eventObject['id'] ) && \strpos( $eventType, 'order.' ) === 0 )
        {
            $providerOrderId = (string) $eventObject['id'];
        }

        if ( $providerOrderId !== NULL && $providerOrderId !== '' )
        {
            try
            {
                $trId = \IPS\Db::i()->select( 't_id', 'nexus_transactions', array( 't_gw_id=?', $providerOrderId ) )->first();
                return \IPS\nexus\Transaction::load( $trId );
            }
            catch ( \Exception $e ) {}
        }

        return NULL;
    }

    /**
     * Resolve provider event object payload.
     *
     * @param array $eventPayload
     * @return array
     */
    protected function getEventObject( array $eventPayload )
    {
        if ( isset( $eventPayload['data']['object'] ) && \is_array( $eventPayload['data']['object'] ) )
        {
            return $eventPayload['data']['object'];
        }

        if ( isset( $eventPayload['data'] ) && \is_array( $eventPayload['data'] ) )
        {
            return $eventPayload['data'];
        }

        return $eventPayload;
    }

    /**
     * Check if a webhook event is already processed.
     *
     * @param \IPS\nexus\Transaction  $transaction
     * @param string|NULL               $eventId
     * @return bool
     */
    protected function isWebhookEventAlreadyProcessed( \IPS\nexus\Transaction $transaction, $eventId )
    {
        if ( !$eventId )
        {
            return FALSE;
        }

        $extra = $transaction->extra;
        return ( isset( $extra['xpolarcheckout_webhook_events'] )
            && \is_array( $extra['xpolarcheckout_webhook_events'] )
            && isset( $extra['xpolarcheckout_webhook_events'][ $eventId ] ) );
    }

    /**
     * Mark webhook event as processed for idempotency.
     *
     * @param \IPS\nexus\Transaction  $transaction
     * @param string|NULL               $eventId
     * @param string|NULL               $eventType
     * @return void
     */
    protected function markWebhookEventProcessed( \IPS\nexus\Transaction $transaction, $eventId, $eventType )
    {
        if ( !$eventId )
        {
            return;
        }

        $extra = $transaction->extra;
        $processedEvents = array();
        if ( isset( $extra['xpolarcheckout_webhook_events'] ) && \is_array( $extra['xpolarcheckout_webhook_events'] ) )
        {
            $processedEvents = $extra['xpolarcheckout_webhook_events'];
        }

        $processedEvents[ $eventId ] = array(
            'type' => (string) $eventType,
            'on' => \time(),
        );

        if ( \count( $processedEvents ) > 50 )
        {
            \uasort( $processedEvents, function( $a, $b ) {
                $aTs = isset( $a['on'] ) ? (int) $a['on'] : 0;
                $bTs = isset( $b['on'] ) ? (int) $b['on'] : 0;
                return $aTs < $bTs ? -1 : 1;
            } );
            $processedEvents = \array_slice( $processedEvents, -50, NULL, TRUE );
        }

        $extra['xpolarcheckout_webhook_events'] = $processedEvents;
        $transaction->extra = $extra;
        $transaction->save();
    }

    /**
     * Acquire transaction lock.
     *
     * @param int $transactionId
     * @return bool
     */
    protected function acquireTransactionProcessingLock( $transactionId )
    {
        try
        {
            $lockName = $this->buildTransactionProcessingLockName( $transactionId );
            $result = \IPS\Db::i()->select( "GET_LOCK('" . $lockName . "', 0)" )->first();
            return (int) $result === 1;
        }
        catch ( \Throwable $e )
        {
            return FALSE;
        }
    }

    /**
     * Release transaction lock.
     *
     * @param int $transactionId
     * @return void
     */
    protected function releaseTransactionProcessingLock( $transactionId )
    {
        try
        {
            $lockName = $this->buildTransactionProcessingLockName( $transactionId );
            \IPS\Db::i()->select( "RELEASE_LOCK('" . $lockName . "')" )->first();
        }
        catch ( \Throwable $e ) {}
    }

    /**
     * Build lock name.
     *
     * @param int $transactionId
     * @return string
     */
    protected function buildTransactionProcessingLockName( $transactionId )
    {
        return 'xpolarcheckout_tx_' . (int) $transactionId;
    }

    /**
     * Store gateway order id on transaction where available.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param array                    $eventObject
     * @param bool                     $allowPrimaryId
     * @return void
     */
    protected function storeGatewayOrderId( \IPS\nexus\Transaction $transaction, array $eventObject, $allowPrimaryId = FALSE )
    {
        $orderId = $this->extractOrderId( $eventObject, $allowPrimaryId );
        if ( !$orderId || (string) $transaction->gw_id === $orderId )
        {
            return;
        }

        $transaction->gw_id = $orderId;
        $transaction->save();
    }

    /**
     * Extract provider order id from event payload object.
     *
     * @param array $eventObject
     * @param bool  $allowPrimaryId
     * @return string|NULL
     */
    protected function extractOrderId( array $eventObject, $allowPrimaryId = FALSE )
    {
        if ( isset( $eventObject['order_id'] ) && \is_scalar( $eventObject['order_id'] ) && (string) $eventObject['order_id'] !== '' )
        {
            return (string) $eventObject['order_id'];
        }

        if ( $allowPrimaryId && isset( $eventObject['id'] ) && \is_scalar( $eventObject['id'] ) && (string) $eventObject['id'] !== '' )
        {
            return (string) $eventObject['id'];
        }

        return NULL;
    }

    /**
     * Add status-history entry to transaction metadata.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param string                   $status
     * @param string                   $noteRaw
     * @return void
     */
    protected function appendHistoryStatus( \IPS\nexus\Transaction $transaction, $status, $noteRaw )
    {
        $extra = $transaction->extra;
        if ( !isset( $extra['history'] ) || !\is_array( $extra['history'] ) )
        {
            $extra['history'] = array();
        }

        $extra['history'][] = array(
            's' => $status,
            'on' => \time(),
            'noteRaw' => (string) $noteRaw,
        );
        $transaction->extra = $extra;
    }

    /**
     * Determine whether current status should be treated as terminal.
     *
     * @param string $status
     * @return bool
     */
    protected function isTerminalTransactionStatus( $status )
    {
        return \in_array( (string) $status, array(
            \IPS\nexus\Transaction::STATUS_PAID,
            \IPS\nexus\Transaction::STATUS_REFUSED,
            \IPS\nexus\Transaction::STATUS_PART_REFUNDED,
            \IPS\nexus\Transaction::STATUS_REFUNDED,
        ), TRUE );
    }

    /**
     * Apply gateway-pending transition when safe.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param string                   $reason
     * @return void
     */
    protected function applyGatewayPendingTransition( \IPS\nexus\Transaction $transaction, $reason )
    {
        if ( $this->isTerminalTransactionStatus( $transaction->status ) )
        {
            return;
        }

        if ( $transaction->status === \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING )
        {
            return;
        }

        $transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
        $this->appendHistoryStatus( $transaction, \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING, (string) $reason );
        $transaction->save();
    }

    /**
     * Apply paid capture transition using IPS fraud/capture pipeline.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @return void
     */
    protected function applyPaidTransition( \IPS\nexus\Transaction $transaction )
    {
        if ( $transaction->status === \IPS\nexus\Transaction::STATUS_PAID
            || $transaction->status === \IPS\nexus\Transaction::STATUS_PART_REFUNDED
            || $transaction->status === \IPS\nexus\Transaction::STATUS_REFUNDED )
        {
            return;
        }

        $maxMind = NULL;
        if ( \IPS\Settings::i()->maxmind_key )
        {
            $maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
            $maxMind->setTransaction( $transaction );
        }

        $transaction->checkFraudRulesAndCapture( $maxMind );
    }

    /**
     * Apply transitions based on order status.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param array                    $eventObject
     * @return void
     */
    protected function applyOrderStatusTransition( \IPS\nexus\Transaction $transaction, array $eventObject )
    {
        $status = isset( $eventObject['status'] ) ? \mb_strtolower( (string) $eventObject['status'] ) : '';

        if ( $status === 'paid' )
        {
            $this->applyPaidTransition( $transaction );
            return;
        }

        if ( $status === 'pending' )
        {
            $this->applyGatewayPendingTransition( $transaction, 'order_pending' );
            return;
        }

        if ( $status === 'refunded' || $status === 'partially_refunded' )
        {
            $refundStatus = $this->determineRefundTransactionStatus( $eventObject, $transaction );
            if ( $transaction->status !== $refundStatus )
            {
                $transaction->status = $refundStatus;
                $this->appendHistoryStatus( $transaction, $refundStatus, 'order_refund_status' );
                $transaction->save();
            }
            return;
        }

        if ( isset( $eventObject['paid'] ) && $eventObject['paid'] )
        {
            $this->applyPaidTransition( $transaction );
            return;
        }

        $refundedAmount = $this->extractRefundedAmountMinor( $eventObject );
        if ( $refundedAmount !== NULL && $refundedAmount > 0 )
        {
            $refundStatus = $this->determineRefundTransactionStatus( $eventObject, $transaction );
            if ( $transaction->status !== $refundStatus )
            {
                $transaction->status = $refundStatus;
                $this->appendHistoryStatus( $transaction, $refundStatus, 'order_refunded_amount' );
                $transaction->save();
            }
        }
    }

    /**
     * Apply transitions based on checkout status updates.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param array                    $eventObject
     * @return void
     */
    protected function applyCheckoutStatusTransition( \IPS\nexus\Transaction $transaction, array $eventObject )
    {
        $status = isset( $eventObject['status'] ) ? \mb_strtolower( (string) $eventObject['status'] ) : '';
        switch ( $status )
        {
            case 'open':
            case 'confirmed':
                $this->applyGatewayPendingTransition( $transaction, 'checkout_' . $status );
                break;

            case 'succeeded':
                $this->applyPaidTransition( $transaction );
                break;

            case 'failed':
            case 'expired':
                if ( !$this->isTerminalTransactionStatus( $transaction->status ) )
                {
                    $transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
                    $this->appendHistoryStatus( $transaction, \IPS\nexus\Transaction::STATUS_REFUSED, 'checkout_' . $status );
                    $transaction->save();
                }
                break;

            default:
                break;
        }
    }

    /**
     * Apply transitions based on refund status updates.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param array                    $eventObject
     * @return void
     */
    protected function applyRefundStatusTransition( \IPS\nexus\Transaction $transaction, array $eventObject )
    {
        $status = isset( $eventObject['status'] ) ? \mb_strtolower( (string) $eventObject['status'] ) : '';
        if ( $status !== 'succeeded' )
        {
            return;
        }

        $refundStatus = $this->determineRefundTransactionStatus( $eventObject, $transaction );
        if ( $transaction->status === $refundStatus )
        {
            return;
        }

        if ( $transaction->status === \IPS\nexus\Transaction::STATUS_REFUNDED && $refundStatus === \IPS\nexus\Transaction::STATUS_PART_REFUNDED )
        {
            return;
        }

        $transaction->status = $refundStatus;
        $this->appendHistoryStatus( $transaction, $refundStatus, 'refund_succeeded' );
        $transaction->save();
    }

    /**
     * Extract total amount (minor units) from event payload object.
     *
     * @param array $eventObject
     * @return int|NULL
     */
    protected function extractTotalAmountMinor( array $eventObject )
    {
        $keys = array( 'total_amount', 'amount_total' );
        foreach ( $keys as $key )
        {
            if ( isset( $eventObject[ $key ] ) && \is_numeric( $eventObject[ $key ] ) )
            {
                return (int) $eventObject[ $key ];
            }
        }

        return NULL;
    }

    /**
     * Extract refunded amount (minor units) from event payload object.
     *
     * @param array $eventObject
     * @return int|NULL
     */
    protected function extractRefundedAmountMinor( array $eventObject )
    {
        $keys = array( 'refunded_amount', 'amount_refunded' );
        foreach ( $keys as $key )
        {
            if ( isset( $eventObject[ $key ] ) && \is_numeric( $eventObject[ $key ] ) )
            {
                return (int) $eventObject[ $key ];
            }
        }

        if ( isset( $eventObject['status'] ) && \mb_strtolower( (string) $eventObject['status'] ) === 'succeeded'
            && isset( $eventObject['amount'] ) && \is_numeric( $eventObject['amount'] ) )
        {
            return (int) $eventObject['amount'];
        }

        return NULL;
    }

    /**
     * Convert transaction amount to minor units using currency decimals.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @return int
     */
    protected function transactionAmountMinorUnit( \IPS\nexus\Transaction $transaction )
    {
        $decimals = \IPS\nexus\Money::numberOfDecimalsForCurrency( $transaction->amount->currency );
        $multiplier = new \IPS\Math\Number( '1' . \str_repeat( '0', $decimals ) );
        $minor = $transaction->amount->amount->multiply( $multiplier );

        return (int) (string) $minor;
    }

    /**
     * Determine refund status from payload.
     *
     * @param array                  $eventObject
     * @param \IPS\nexus\Transaction|NULL $transaction
     * @return string
     */
    protected function determineRefundTransactionStatus( array $eventObject, \IPS\nexus\Transaction $transaction = NULL )
    {
        $status = isset( $eventObject['status'] ) ? \mb_strtolower( (string) $eventObject['status'] ) : '';
        if ( $status === 'partially_refunded' )
        {
            return \IPS\nexus\Transaction::STATUS_PART_REFUNDED;
        }

        $totalAmount = $this->extractTotalAmountMinor( $eventObject );
        $refundedAmount = $this->extractRefundedAmountMinor( $eventObject );

        if ( $totalAmount === NULL && $transaction )
        {
            $totalAmount = $this->transactionAmountMinorUnit( $transaction );
        }

        if ( $totalAmount !== NULL && $refundedAmount !== NULL && $refundedAmount > 0 && $refundedAmount < $totalAmount )
        {
            return \IPS\nexus\Transaction::STATUS_PART_REFUNDED;
        }

        return \IPS\nexus\Transaction::STATUS_REFUNDED;
    }

    /**
     * Persist lightweight snapshot for settlement visibility.
     *
     * @param \IPS\nexus\Transaction $transaction
     * @param array                    $eventPayload
     * @param string                   $eventType
     * @return void
     */
    protected function persistPolarSnapshot( \IPS\nexus\Transaction $transaction, array $eventPayload, $eventType )
    {
        $eventObject = $this->getEventObject( $eventPayload );
        $snapshotOrderId = $this->extractOrderId( $eventObject, \strpos( (string) $eventType, 'order.' ) === 0 );

        $snapshot = array(
            'event_type' => (string) $eventType,
            'event_id' => $this->extractWebhookEventId( $eventPayload ),
            'provider_status' => isset( $eventObject['status'] ) ? (string) $eventObject['status'] : NULL,
            'order_id' => $snapshotOrderId,
            'currency' => isset( $eventObject['currency'] ) ? (string) $eventObject['currency'] : NULL,
            'amount_total_minor' => $this->extractTotalAmountMinor( $eventObject ),
            'amount_refunded_minor' => $this->extractRefundedAmountMinor( $eventObject ),
            'captured_at_iso' => \gmdate( 'Y-m-d H:i:s' ) . ' UTC',
        );

        $extra = $transaction->extra;
        $extra['xpolarcheckout_snapshot'] = $snapshot;
        $transaction->extra = $extra;
        $transaction->save();

        try
        {
            $invoice = $transaction->invoice;
            $statusExtra = \is_array( $invoice->status_extra ) ? $invoice->status_extra : array();
            $statusExtra['xpolarcheckout_snapshot'] = $snapshot;
            $invoice->status_extra = $statusExtra;
            $invoice->save();
        }
        catch ( \Throwable $e ) {}
    }

    /**
     * Log webhook forensic event.
     *
     * @param string      $failureReason
     * @param int         $httpStatus
     * @param string|NULL $eventType
     * @param string|NULL $eventId
     * @param string|NULL $body
     * @return void
     */
    protected function logForensicEvent( $failureReason, $httpStatus, $eventType = NULL, $eventId = NULL, $body = NULL )
    {
        try
        {
            $snippet = NULL;
            if ( \is_string( $body ) && $body !== '' )
            {
                $snippet = \mb_substr( $body, 0, 2000 );
            }

            \IPS\Db::i()->insert( 'xpc_webhook_forensics', array(
                'event_type' => (string) ( $eventType ?: '' ),
                'event_id' => $eventId ? (string) $eventId : NULL,
                'failure_reason' => (string) $failureReason,
                'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                'http_status' => (int) $httpStatus,
                'payload_snippet' => $snippet,
                'created_at' => \time(),
            ) );
        }
        catch ( \Throwable $e ) {}
    }

    /**
     * Verify webhook signature.
     *
     * @param string      $signature
     * @param string      $body
     * @param string      $secret
     * @param string|NULL $eventType
     * @param string|NULL $eventId
     * @param string|NULL $timestamp
     * @param string|NULL $webhookId
     * @return bool
     */
    protected function checkSignature( $signature, $body, $secret, $eventType = NULL, $eventId = NULL, $timestamp = NULL, $webhookId = NULL )
    {
        if ( $secret === '' )
        {
            $this->logForensicEvent( 'missing_secret', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        $timestampValue = (string) $timestamp;
        $webhookIdValue = (string) $webhookId;
        if ( $timestampValue === '' || $webhookIdValue === '' )
        {
            $this->logForensicEvent( 'missing_signature', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        if ( !\ctype_digit( $timestampValue ) )
        {
            $this->logForensicEvent( 'invalid_signature', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        /* Standard Webhooks recommends rejecting stale attempts to reduce replay risk. */
        if ( \abs( \time() - (int) $timestampValue ) > 300 )
        {
            $this->logForensicEvent( 'timestamp_too_old', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        $secretMaterial = (string) $secret;
        if ( \strpos( $secretMaterial, 'whsec_' ) === 0 )
        {
            $secretMaterial = (string) \substr( $secretMaterial, 6 );
        }

        $secretBytes = \base64_decode( $secretMaterial, TRUE );
        if ( $secretBytes === FALSE )
        {
            /* Local Polar CLI secrets may be provided as raw text in development. */
            $secretBytes = $secretMaterial;
        }

        $rawPayload = $webhookIdValue . '.' . $timestampValue . '.' . $body;
        $computed = \base64_encode( \hash_hmac( 'sha256', (string) $rawPayload, $secretBytes, TRUE ) );

        $tokens = \preg_split( '/\s+/', \trim( (string) $signature ) );
        $matched = FALSE;
        foreach ( $tokens as $token )
        {
            $pair = \trim( (string) $token );
            if ( $pair === '' )
            {
                continue;
            }

            $parts = \explode( ',', $pair, 2 );
            if ( \count( $parts ) !== 2 )
            {
                continue;
            }

            $version = \trim( (string) $parts[0] );
            $candidate = \trim( (string) $parts[1] );
            if ( $version === 'v1' && $candidate !== '' && \hash_equals( $computed, $candidate ) )
            {
                $matched = TRUE;
                break;
            }
        }

        if ( !$matched )
        {
            $this->logForensicEvent( 'invalid_signature', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        return TRUE;
    }
}
