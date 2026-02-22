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
        if ( !$this->checkSignature( $signature, $body, $secret, $eventType, $eventId, $timestamp ) )
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
                    if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_PAID && $transaction->status !== \IPS\nexus\Transaction::STATUS_REFUSED )
                    {
                        $transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
                        $extra = $transaction->extra;
                        $extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING, 'on' => \time(), 'noteRaw' => 'order_created' );
                        $transaction->extra = $extra;
                        $transaction->save();
                    }
                    break;

                case 'order.paid':
                    if ( isset( $eventObject['id'] ) && \is_scalar( $eventObject['id'] ) )
                    {
                        $transaction->gw_id = (string) $eventObject['id'];
                        $transaction->save();
                    }

                    $maxMind = NULL;
                    if ( \IPS\Settings::i()->maxmind_key )
                    {
                        $maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
                        $maxMind->setTransaction( $transaction );
                    }

                    $transaction->checkFraudRulesAndCapture( $maxMind );
                    break;

                case 'order.refunded':
                    $transaction->status = $this->determineRefundTransactionStatus( $eventObject );
                    $transaction->save();
                    break;

                case 'refund.updated':
                    if ( isset( $eventObject['status'] ) && $eventObject['status'] === 'succeeded' )
                    {
                        $transaction->status = $this->determineRefundTransactionStatus( $eventObject );
                        $transaction->save();
                    }
                    break;

                case 'order.updated':
                case 'checkout.updated':
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
     * Determine refund status from payload.
     *
     * @param array $eventObject
     * @return string
     */
    protected function determineRefundTransactionStatus( array $eventObject )
    {
        $amount = isset( $eventObject['amount'] ) && \is_numeric( $eventObject['amount'] ) ? (int) $eventObject['amount'] : NULL;
        $amountRefunded = isset( $eventObject['amount_refunded'] ) && \is_numeric( $eventObject['amount_refunded'] ) ? (int) $eventObject['amount_refunded'] : NULL;

        if ( $amount !== NULL && $amountRefunded !== NULL && $amountRefunded > 0 && $amountRefunded < $amount )
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

        $snapshot = array(
            'event_type' => (string) $eventType,
            'event_id' => $this->extractWebhookEventId( $eventPayload ),
            'provider_status' => isset( $eventObject['status'] ) ? (string) $eventObject['status'] : NULL,
            'order_id' => isset( $eventObject['id'] ) ? (string) $eventObject['id'] : ( isset( $eventObject['order_id'] ) ? (string) $eventObject['order_id'] : NULL ),
            'currency' => isset( $eventObject['currency'] ) ? (string) $eventObject['currency'] : NULL,
            'amount_total_minor' => isset( $eventObject['amount'] ) && \is_numeric( $eventObject['amount'] ) ? (int) $eventObject['amount'] : NULL,
            'amount_refunded_minor' => isset( $eventObject['amount_refunded'] ) && \is_numeric( $eventObject['amount_refunded'] ) ? (int) $eventObject['amount_refunded'] : NULL,
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
     * @return bool
     */
    protected function checkSignature( $signature, $body, $secret, $eventType = NULL, $eventId = NULL, $timestamp = NULL )
    {
        if ( $secret === '' )
        {
            $this->logForensicEvent( 'missing_secret', 403, $eventType, $eventId, $body );
            \IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
            return FALSE;
        }

        $rawPayload = ( $timestamp !== NULL && $timestamp !== '' ) ? ( $timestamp . '.' . $body ) : $body;
        $computed = \hash_hmac( 'sha256', (string) $rawPayload, $secret );

        $tokens = \preg_split( '/[,\s]+/', (string) $signature );
        $matched = FALSE;
        foreach ( $tokens as $token )
        {
            $candidate = \trim( (string) $token );
            if ( $candidate === '' )
            {
                continue;
            }

            if ( \strpos( $candidate, '=' ) !== FALSE )
            {
                $parts = \explode( '=', $candidate, 2 );
                $candidate = isset( $parts[1] ) ? $parts[1] : '';
            }

            if ( $candidate !== '' && \hash_equals( $computed, $candidate ) )
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