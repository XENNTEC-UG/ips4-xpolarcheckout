<?php
/**
 * @brief        Replay webhook events task (Phase 1 placeholder)
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
 *
 * Phase 1 behavior intentionally keeps replay state healthy while
 * Polar delivery API integration is completed in later phases.
 */
class _webhookReplay extends \IPS\Task
{
    /**
     * @brief Store key for replay state
     */
    const REPLAY_STATE_STORE_KEY = 'xpolarcheckout_webhook_replay_state';

    /**
     * Execute task.
     *
     * @param bool $dryRun
     * @return mixed
     */
    public function execute( $dryRun = FALSE )
    {
        $settings = $this->loadGatewaySettings();
        if ( $settings === NULL )
        {
            return $dryRun ? array( 'count' => 0, 'events' => array() ) : NULL;
        }

        $state = $this->loadReplayState();
        $state['last_run_at'] = \time();
        $state['last_event_created'] = isset( $state['last_event_created'] ) ? (int) $state['last_event_created'] : \time();
        $state['last_event_id'] = isset( $state['last_event_id'] ) ? $state['last_event_id'] : NULL;
        $state['last_replayed_count'] = 0;
        $this->saveReplayState( $state );

        if ( $dryRun )
        {
            return array( 'count' => 0, 'events' => array() );
        }

        return NULL;
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