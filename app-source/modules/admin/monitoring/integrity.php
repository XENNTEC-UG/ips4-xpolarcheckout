<?php

namespace IPS\xpolarcheckout\modules\admin\monitoring;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

/**
 * Polar checkout ACP integrity panel.
 */
class _integrity extends \IPS\Dispatcher\Controller
{
    /**
     * Execute.
     *
     * @return void
     */
    public function execute()
    {
        \IPS\Dispatcher::i()->checkAcpPermission( 'integrity_view' );
        parent::execute();
    }

    /**
     * Render integrity dashboard.
     *
     * @return void
     */
    protected function manage()
    {
        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_title' );

        $stats = $this->collectIntegrityStats();
        $replayNowUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=runReplay', 'admin' )->csrf();
        $dryRunUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=dryRunReplay', 'admin' )->csrf();

        \IPS\Output::i()->output = $this->renderDashboard( $stats, $replayNowUrl, $dryRunUrl );
    }

    /**
     * Build dashboard markup.
     *
     * @param array         $stats
     * @param \IPS\Http\Url $replayUrl
     * @param \IPS\Http\Url $dryRunUrl
     * @return string
     */
    protected function renderDashboard( array $stats, $replayUrl, $dryRunUrl )
    {
        $h = '';

        $h .= '<style>
            .xpc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin-bottom:20px; }
            .xpc-card { background:rgba(128,128,128,0.06); border:1px solid rgba(128,128,128,0.15); border-radius:8px; padding:20px; }
            .xpc-card-label { font-size:12px; font-weight:600; text-transform:uppercase; opacity:.55; margin-bottom:8px; }
            .xpc-card-value { font-size:28px; font-weight:700; }
            .xpc-card-sub { font-size:12px; opacity:.45; margin-top:4px; }
            .xpc-card--ok { border-left:4px solid #22c55e; }
            .xpc-card--warn { border-left:4px solid #f59e0b; }
            .xpc-card--err { border-left:4px solid #ef4444; }
            .xpc-tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
            .xpc-tag--ok { background:rgba(34,197,94,0.15); color:#22c55e; }
            .xpc-tag--warn { background:rgba(245,158,11,0.15); color:#d97706; }
            .xpc-tag--err { background:rgba(239,68,68,0.15); color:#ef4444; }
            .xpc-section { margin-bottom:24px; }
            .xpc-section-title { font-size:15px; font-weight:600; margin-bottom:12px; padding-bottom:8px; border-bottom:2px solid rgba(128,128,128,0.15); }
            .xpc-table { width:100%; border-collapse:collapse; font-size:13px; }
            .xpc-table th { text-align:left; padding:10px 12px; background:rgba(128,128,128,0.06); border-bottom:2px solid rgba(128,128,128,0.15); }
            .xpc-table td { padding:10px 12px; border-bottom:1px solid rgba(128,128,128,0.08); }
            .xpc-empty { opacity:.5; font-size:13px; padding:16px 0; }
            .xpc-actions { display:flex; gap:12px; align-items:center; margin-bottom:16px; }
        </style>';

        $h .= '<div class="xpc-grid">';

        $configured = (bool) $stats['gateway_webhook_configured'];
        $h .= '<div class="xpc-card ' . ( $configured ? 'xpc-card--ok' : 'xpc-card--err' ) . '">'
            . '<div class="xpc-card-label">Webhook</div>'
            . '<div class="xpc-card-value"><span class="xpc-tag ' . ( $configured ? 'xpc-tag--ok' : 'xpc-tag--err' ) . '">' . ( $configured ? 'Configured' : 'Not configured' ) . '</span></div>'
            . '<div class="xpc-card-sub">URL + secret</div>'
            . '</div>';

        $environment = ( isset( $stats['gateway_environment'] ) && $stats['gateway_environment'] === 'production' ) ? 'production' : 'sandbox';
        $environmentClass = ( $environment === 'production' ) ? 'xpc-card--warn' : 'xpc-card--ok';
        $environmentTagClass = ( $environment === 'production' ) ? 'xpc-tag--warn' : 'xpc-tag--ok';
        $h .= '<div class="xpc-card ' . $environmentClass . '">'
            . '<div class="xpc-card-label">Environment</div>'
            . '<div class="xpc-card-value"><span class="xpc-tag ' . $environmentTagClass . '">' . $this->escape( \mb_strtoupper( $environment ) ) . '</span></div>'
            . '<div class="xpc-card-sub">Gateway settings mode</div>'
            . '</div>';

        $replayHealthy = (bool) $stats['replay_recent_run'];
        $h .= '<div class="xpc-card ' . ( $replayHealthy ? 'xpc-card--ok' : 'xpc-card--warn' ) . '">'
            . '<div class="xpc-card-label">Replay Task</div>'
            . '<div class="xpc-card-value"><span class="xpc-tag ' . ( $replayHealthy ? 'xpc-tag--ok' : 'xpc-tag--warn' ) . '">' . ( $replayHealthy ? 'Healthy' : 'Stale' ) . '</span></div>'
            . '<div class="xpc-card-sub">Last run: ' . $this->escape( $this->formatTimestamp( $stats['replay_last_run_at'] ) ) . '</div>'
            . '</div>';

        $errors24h = (int) $stats['webhook_error_count_24h'];
        $h .= '<div class="xpc-card ' . ( $errors24h === 0 ? 'xpc-card--ok' : 'xpc-card--err' ) . '">'
            . '<div class="xpc-card-label">Errors (24h)</div>'
            . '<div class="xpc-card-value">' . $errors24h . '</div>'
            . '<div class="xpc-card-sub">Webhook processing</div>'
            . '</div>';

        $mismatch30 = (int) $stats['mismatch_count_30d'];
        $h .= '<div class="xpc-card ' . ( $mismatch30 === 0 ? 'xpc-card--ok' : 'xpc-card--warn' ) . '">'
            . '<div class="xpc-card-label">Mismatches (30d)</div>'
            . '<div class="xpc-card-value">' . $mismatch30 . '</div>'
            . '<div class="xpc-card-sub">' . (int) $stats['mismatch_count_all_time'] . ' all time</div>'
            . '</div>';

        $h .= '</div>';

        $h .= '<div class="xpc-section">';
        $h .= '<div class="xpc-section-title">Webhook Replay</div>';
        $h .= '<div class="xpc-actions">';
        $h .= '<a href="' . $this->escape( (string) $replayUrl ) . '" class="ipsButton ipsButton_primary ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_now' ) ) . '</a>';
        $h .= '<a href="' . $this->escape( (string) $dryRunUrl ) . '" class="ipsButton ipsButton_alternate ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run' ) ) . '</a>';
        $h .= '</div>';

        $h .= '<table class="xpc-table">';
        $h .= '<tr><td style="width:240px;font-weight:600;">Events processed (last run)</td><td>' . $this->escape( (string) $stats['replay_last_replayed_count'] ) . '</td></tr>';
        $h .= '<tr><td style="font-weight:600;">Last event cursor</td><td>' . $this->escape( $this->formatTimestamp( $stats['replay_last_event_created'] ) ) . '</td></tr>';
        $h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_lookback' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_lookback'] ) . 's</td></tr>';
        $h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_overlap' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_overlap'] ) . 's</td></tr>';
        $h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_max_events' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_max_events'] ) . '</td></tr>';
        $h .= '</table>';
        $h .= '</div>';

        $h .= $this->renderWebhookEndpointSection( $stats );

        $h .= '<div class="xpc-section">';
        $h .= '<div class="xpc-section-title">Recent Webhook Errors</div>';
        $h .= $this->renderWebhookErrorTable( $stats['recent_webhook_errors'] );
        $h .= '</div>';

        $h .= '<div class="xpc-section">';
        $h .= '<div class="xpc-section-title">Provider vs IPS Total Mismatches</div>';
        $h .= $this->renderMismatchTable( $stats['recent_mismatch_rows'] );
        $h .= '</div>';

        return $h;
    }

    /**
     * Run replay task now.
     *
     * @return void
     */
    protected function runReplay()
    {
        \IPS\Session::i()->csrfCheck();
        $redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

        try
        {
            $result = $this->executeReplayTaskNow();
            \IPS\Session::i()->log( 'acplogs__xpolarcheckout_integrity_replay' );
            \IPS\Output::i()->redirect( $redirectUrl, $result['message'] );
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_integrity_replay' );
            \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_integrity_replay_failed' );
        }
    }

    /**
     * Run replay dry-run.
     *
     * @return void
     */
    protected function dryRunReplay()
    {
        \IPS\Session::i()->csrfCheck();
        $redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

        try
        {
            $task = new \IPS\xpolarcheckout\tasks\webhookReplay;
            $result = $task->execute( TRUE );
            \IPS\Session::i()->log( 'acplogs__xpolarcheckout_integrity_dry_run' );

            $count = isset( $result['count'] ) ? (int) $result['count'] : 0;
            $message = ( $count > 0 )
                ? \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run_result', FALSE, array( 'sprintf' => array( $count ) ) )
                : \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run_none' );

            \IPS\Output::i()->redirect( $redirectUrl, $message );
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_integrity_replay' );
            \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_integrity_replay_failed' );
        }
    }

    /**
     * Execute replay task and map message key.
     *
     * @return array
     */
    protected function executeReplayTaskNow()
    {
        $task = new \IPS\xpolarcheckout\tasks\webhookReplay;
        $result = $task->execute();

        return array(
            'message' => $result ? 'xpolarcheckout_integrity_replay_success' : 'xpolarcheckout_integrity_replay_no_events',
            'result' => $result,
        );
    }

    /**
     * Build integrity stats model.
     *
     * @return array
     */
    protected function collectIntegrityStats()
    {
        $stats = array(
            'gateway_webhook_configured' => FALSE,
            'gateway_environment' => 'sandbox',
            'replay_last_run_at' => NULL,
            'replay_last_event_created' => NULL,
            'replay_last_replayed_count' => 0,
            'replay_recent_run' => FALSE,
            'replay_config_lookback' => 3600,
            'replay_config_overlap' => 300,
            'replay_config_max_events' => 100,
            'webhook_error_count_24h' => 0,
            'mismatch_count_all_time' => 0,
            'mismatch_count_30d' => 0,
            'recent_webhook_errors' => array(),
            'recent_mismatch_rows' => array(),
            'webhook_endpoint' => NULL,
            'webhook_events_missing' => array(),
            'webhook_events_extra' => array(),
            'webhook_endpoint_url_match' => FALSE,
        );

        $gatewaySettings = $this->loadGatewaySettings();
        if ( \is_array( $gatewaySettings ) )
        {
            $stats['gateway_webhook_configured'] = !empty( $gatewaySettings['webhook_url'] ) && !empty( $gatewaySettings['webhook_secret'] );
            if ( isset( $gatewaySettings['environment'] ) && \is_string( $gatewaySettings['environment'] ) )
            {
                $stats['gateway_environment'] = ( $gatewaySettings['environment'] === 'production' ) ? 'production' : 'sandbox';
            }

            if ( isset( $gatewaySettings['replay_lookback'] ) )
            {
                $stats['replay_config_lookback'] = \max( 300, \min( 86400, (int) $gatewaySettings['replay_lookback'] ) );
            }
            if ( isset( $gatewaySettings['replay_overlap'] ) )
            {
                $stats['replay_config_overlap'] = \max( 60, \min( 1800, (int) $gatewaySettings['replay_overlap'] ) );
            }
            if ( isset( $gatewaySettings['replay_max_events'] ) )
            {
                $stats['replay_config_max_events'] = \max( 10, \min( 100, (int) $gatewaySettings['replay_max_events'] ) );
            }

            $endpoint = \IPS\xpolarcheckout\XPolarCheckout::fetchWebhookEndpoint( $gatewaySettings );
            if ( $endpoint !== NULL && \is_array( $endpoint ) )
            {
                $stats['webhook_endpoint'] = $endpoint;

                $providerEvents = ( isset( $endpoint['enabled_events'] ) && \is_array( $endpoint['enabled_events'] ) ) ? $endpoint['enabled_events'] : array();
                $requiredEvents = \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS;

                $stats['webhook_events_missing'] = \array_values( \array_diff( $requiredEvents, $providerEvents ) );
                $stats['webhook_events_extra'] = \array_values( \array_diff( $providerEvents, $requiredEvents ) );

                $expectedUrl = !empty( $gatewaySettings['webhook_url'] ) ? $gatewaySettings['webhook_url'] : '';
                $stats['webhook_endpoint_url_match'] = ( isset( $endpoint['url'] ) && $endpoint['url'] === $expectedUrl );
            }
        }

        if ( isset( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) && \is_array( \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state ) )
        {
            $replayState = \IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state;
            $stats['replay_last_run_at'] = ( isset( $replayState['last_run_at'] ) && \is_numeric( $replayState['last_run_at'] ) ) ? (int) $replayState['last_run_at'] : NULL;
            $stats['replay_last_event_created'] = ( isset( $replayState['last_event_created'] ) && \is_numeric( $replayState['last_event_created'] ) ) ? (int) $replayState['last_event_created'] : NULL;
            $stats['replay_last_replayed_count'] = ( isset( $replayState['last_replayed_count'] ) && \is_numeric( $replayState['last_replayed_count'] ) ) ? (int) $replayState['last_replayed_count'] : 0;
            $stats['replay_recent_run'] = ( $stats['replay_last_run_at'] !== NULL && ( \time() - $stats['replay_last_run_at'] ) <= $stats['replay_config_lookback'] );
        }

        $dayAgo = \time() - 86400;
        $monthAgo = \time() - ( 30 * 86400 );

        try
        {
            $stats['webhook_error_count_24h'] = (int) \IPS\Db::i()->select(
                'COUNT(*)',
                'core_log',
                array( '( category=? OR category=? ) AND time>?', 'xpolarcheckout_webhook', 'xpolarcheckout_snapshot', $dayAgo )
            )->first();

            foreach ( \IPS\Db::i()->select(
                'time,category,message',
                'core_log',
                array( '( category=? OR category=? ) AND time>?', 'xpolarcheckout_webhook', 'xpolarcheckout_snapshot', $dayAgo ),
                'id DESC',
                10
            ) as $row )
            {
                $stats['recent_webhook_errors'][] = $row;
            }
        }
        catch ( \Exception $e ) {}

        $mismatchWhere = "JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.has_total_mismatch'))='true'";
        try
        {
            $stats['mismatch_count_all_time'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_transactions', $mismatchWhere )->first();
            $stats['mismatch_count_30d'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_transactions', array( "{$mismatchWhere} AND t_date>?", $monthAgo ) )->first();

            $fields = "t_id,t_invoice,t_date,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.amount_total_display')) AS provider_total_display,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.ips_invoice_total_display')) AS ips_total_display,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.total_mismatch_display')) AS mismatch_display";
            foreach ( \IPS\Db::i()->select( $fields, 'nexus_transactions', $mismatchWhere, 't_id DESC', 10 ) as $row )
            {
                $stats['recent_mismatch_rows'][] = $row;
            }
        }
        catch ( \Exception $e ) {}

        return $stats;
    }

    /**
     * Resolve active gateway settings.
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
     * Sync endpoint events from UI.
     *
     * @return void
     */
    protected function syncEvents()
    {
        \IPS\Session::i()->csrfCheck();
        $redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

        try
        {
            $gatewaySettings = $this->loadGatewaySettings();
            if ( !\is_array( $gatewaySettings ) || empty( $gatewaySettings['access_token'] ) )
            {
                \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_not_found' );
                return;
            }

            $endpoint = \IPS\xpolarcheckout\XPolarCheckout::fetchWebhookEndpoint( $gatewaySettings );
            if ( $endpoint === NULL || !isset( $endpoint['id'] ) )
            {
                \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_not_found' );
                return;
            }

            \IPS\xpolarcheckout\XPolarCheckout::syncWebhookEvents( $gatewaySettings, $endpoint['id'] );

            if ( empty( $gatewaySettings['webhook_endpoint_id'] ) )
            {
                $this->backfillEndpointId( $endpoint['id'] );
            }

            \IPS\Session::i()->log( 'acplogs__xpolarcheckout_webhook_sync' );
            \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_success' );
        }
        catch ( \Exception $e )
        {
            \IPS\Log::log( $e, 'xpolarcheckout_webhook_sync' );
            \IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_failed' );
        }
    }

    /**
     * Backfill endpoint id for legacy settings.
     *
     * @param string $endpointId
     * @return void
     */
    protected function backfillEndpointId( $endpointId )
    {
        foreach ( \IPS\nexus\Gateway::roots() as $gateway )
        {
            if ( $gateway instanceof \IPS\xpolarcheckout\XPolarCheckout )
            {
                $settings = \json_decode( $gateway->settings, TRUE );
                if ( \is_array( $settings ) )
                {
                    $settings['webhook_endpoint_id'] = $endpointId;
                    $gateway->settings = \json_encode( $settings );
                    $gateway->save();
                }
                break;
            }
        }
    }

    /**
     * Render webhook endpoint section.
     *
     * @param array $stats
     * @return string
     */
    protected function renderWebhookEndpointSection( array $stats )
    {
        $h = '<div class="xpc-section">';
        $h .= '<div class="xpc-section-title">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_endpoint' ) ) . '</div>';

        $endpoint = $stats['webhook_endpoint'];
        if ( $endpoint === NULL )
        {
            $h .= '<p class="xpc-empty">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_sync_not_found' ) ) . '</p>';
            $h .= '</div>';
            return $h;
        }

        $h .= '<table class="xpc-table">';
        $h .= '<tr><td style="width:240px;font-weight:600;">Endpoint ID</td><td>' . $this->escape( isset( $endpoint['id'] ) ? (string) $endpoint['id'] : '-' ) . '</td></tr>';
        $h .= '<tr><td style="font-weight:600;">URL</td><td>' . $this->escape( isset( $endpoint['url'] ) ? (string) $endpoint['url'] : '-' ) . '</td></tr>';
        $h .= '<tr><td style="font-weight:600;">URL match</td><td>' . ( $stats['webhook_endpoint_url_match'] ? '<span class="xpc-tag xpc-tag--ok">Match</span>' : '<span class="xpc-tag xpc-tag--warn">Mismatch</span>' ) . '</td></tr>';
        $h .= '</table>';

        $providerEvents = ( isset( $endpoint['enabled_events'] ) && \is_array( $endpoint['enabled_events'] ) ) ? $endpoint['enabled_events'] : array();
        $h .= '<table class="xpc-table" style="margin-top:12px;">';
        $h .= '<thead><tr><th>Required Event</th><th>Registered</th></tr></thead><tbody>';
        foreach ( \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS as $event )
        {
            $registered = \in_array( $event, $providerEvents, TRUE );
            $h .= '<tr><td>' . $this->escape( $event ) . '</td><td>' . ( $registered ? '<span class="xpc-tag xpc-tag--ok">&#10003;</span>' : '<span class="xpc-tag xpc-tag--err">&#10005;</span>' ) . '</td></tr>';
        }
        $h .= '</tbody></table>';

        if ( \count( $stats['webhook_events_extra'] ) )
        {
            $h .= '<table class="xpc-table" style="margin-top:12px;">';
            $h .= '<thead><tr><th>Extra Events</th></tr></thead><tbody>';
            foreach ( $stats['webhook_events_extra'] as $extraEvent )
            {
                $h .= '<tr><td>' . $this->escape( $extraEvent ) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        if ( \count( $stats['webhook_events_missing'] ) > 0 )
        {
            $syncUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=syncEvents', 'admin' )->csrf();
            $h .= '<div class="xpc-actions" style="margin-top:16px;">';
            $h .= '<a href="' . $this->escape( (string) $syncUrl ) . '" class="ipsButton ipsButton_primary ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_sync_events' ) ) . '</a>';
            $h .= '</div>';
        }

        $h .= '</div>';
        return $h;
    }

    /**
     * Render recent webhook error table.
     *
     * @param array $rows
     * @return string
     */
    protected function renderWebhookErrorTable( array $rows )
    {
        if ( !\count( $rows ) )
        {
            return '<p class="xpc-empty">No webhook processing errors in the last 24 hours.</p>';
        }

        $output = '<table class="xpc-table">';
        $output .= '<thead><tr><th>Time (UTC)</th><th>Category</th><th>Message</th></tr></thead><tbody>';
        foreach ( $rows as $row )
        {
            $message = isset( $row['message'] ) ? (string) $row['message'] : '';
            $message = \trim( \preg_replace( '/\s+/', ' ', $message ) );
            if ( \mb_strlen( $message ) > 200 )
            {
                $message = \mb_substr( $message, 0, 197 ) . '...';
            }

            $category = isset( $row['category'] ) ? (string) $row['category'] : '';
            $category = \str_replace( 'xpolarcheckout_', '', $category );

            $output .= '<tr>'
                . '<td style="white-space:nowrap;">' . $this->escape( $this->formatTimestamp( isset( $row['time'] ) ? (int) $row['time'] : NULL ) ) . '</td>'
                . '<td><span class="xpc-tag xpc-tag--err">' . $this->escape( $category ) . '</span></td>'
                . '<td>' . $this->escape( $message ) . '</td>'
                . '</tr>';
        }
        $output .= '</tbody></table>';

        return $output;
    }

    /**
     * Render recent mismatch rows table.
     *
     * @param array $rows
     * @return string
     */
    protected function renderMismatchTable( array $rows )
    {
        if ( !\count( $rows ) )
        {
            return '<p class="xpc-empty">No provider-vs-IPS total mismatches detected.</p>';
        }

        $output = '<table class="xpc-table">';
        $output .= '<thead><tr><th>Transaction</th><th>Invoice</th><th>Date</th><th>Provider Total</th><th>IPS Total</th><th>Difference</th></tr></thead><tbody>';
        foreach ( $rows as $row )
        {
            $txUrl = \IPS\Http\Url::internal( 'app=nexus&module=payments&controller=transactions&do=view&id=' . (int) $row['t_id'], 'admin' );
            $invUrl = \IPS\Http\Url::internal( 'app=nexus&module=payments&controller=invoices&do=view&id=' . (int) $row['t_invoice'], 'admin' );

            $output .= '<tr>'
                . '<td><a href="' . $this->escape( (string) $txUrl ) . '">#' . $this->escape( (string) $row['t_id'] ) . '</a></td>'
                . '<td><a href="' . $this->escape( (string) $invUrl ) . '">#' . $this->escape( (string) $row['t_invoice'] ) . '</a></td>'
                . '<td style="white-space:nowrap;">' . $this->escape( $this->formatTimestamp( isset( $row['t_date'] ) ? (int) $row['t_date'] : NULL ) ) . '</td>'
                . '<td>' . $this->escape( isset( $row['provider_total_display'] ) ? (string) $row['provider_total_display'] : '-' ) . '</td>'
                . '<td>' . $this->escape( isset( $row['ips_total_display'] ) ? (string) $row['ips_total_display'] : '-' ) . '</td>'
                . '<td><span class="xpc-tag xpc-tag--warn">' . $this->escape( isset( $row['mismatch_display'] ) ? (string) $row['mismatch_display'] : '-' ) . '</span></td>'
                . '</tr>';
        }
        $output .= '</tbody></table>';

        return $output;
    }

    /**
     * Format timestamp for ACP.
     *
     * @param int|NULL $timestamp
     * @return string
     */
    protected function formatTimestamp( $timestamp )
    {
        if ( $timestamp === NULL || $timestamp <= 0 )
        {
            return '-';
        }

        return \gmdate( 'Y-m-d H:i:s', (int) $timestamp ) . ' UTC';
    }

    /**
     * Escape helper.
     *
     * @param string $value
     * @return string
     */
    protected function escape( $value )
    {
        return \htmlspecialchars( (string) $value, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
    }
}
