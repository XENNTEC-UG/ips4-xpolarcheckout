<?php

namespace IPS\xpolarcheckout\modules\admin\monitoring;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Stripe Checkout ACP integrity panel.
 */
class _integrity extends \IPS\Dispatcher\Controller
{
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'integrity_view' );
		parent::execute();
	}

	/**
	 * Render integrity dashboard.
	 *
	 * @return	void
	 */
	protected function manage()
	{
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_title' );
		$stats = $this->collectIntegrityStats();
		$replayNowUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=runReplay', 'admin' )->csrf();
		$dryRunUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=dryRunReplay', 'admin' )->csrf();

		$content = $this->renderDashboard( $stats, $replayNowUrl, $dryRunUrl );

		\IPS\Output::i()->output = $content;
	}

	/**
	 * Build the full dashboard HTML.
	 *
	 * @param	array			$stats		Integrity stats
	 * @param	\IPS\Http\Url	$replayUrl	CSRF-protected replay URL
	 * @param	\IPS\Http\Url	$dryRunUrl	CSRF-protected dry-run URL
	 * @return	string
	 */
	protected function renderDashboard( array $stats, $replayUrl, $dryRunUrl )
	{
		$h = '';

		// Inline styles — theme-agnostic: inherits ACP text/bg colors,
		// only uses explicit color for semantic status indicators (green/yellow/red).
		$h .= '<style>
			.xsc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; }
			.xsc-card { background: rgba(128,128,128,0.06); border: 1px solid rgba(128,128,128,0.15); border-radius: 8px; padding: 20px; }
			.xsc-card-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.55; margin-bottom: 8px; }
			.xsc-card-value { font-size: 28px; font-weight: 700; line-height: 1.2; }
			.xsc-card-sub { font-size: 12px; opacity: 0.45; margin-top: 4px; }
			.xsc-card--ok { border-left: 4px solid #22c55e; }
			.xsc-card--warn { border-left: 4px solid #f59e0b; }
			.xsc-card--err { border-left: 4px solid #ef4444; }
			.xsc-card--neutral { border-left: 4px solid #6366f1; }
			.xsc-section { margin-bottom: 24px; }
			.xsc-section-title { font-size: 15px; font-weight: 600; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid rgba(128,128,128,0.15); }
			.xsc-empty { opacity: 0.45; font-size: 13px; padding: 16px 0; }
			.xsc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
			.xsc-table th { text-align: left; padding: 10px 12px; background: rgba(128,128,128,0.06); border-bottom: 2px solid rgba(128,128,128,0.15); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.6; }
			.xsc-table td { padding: 10px 12px; border-bottom: 1px solid rgba(128,128,128,0.08); }
			.xsc-table tr:hover td { background: rgba(128,128,128,0.04); }
			.xsc-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
			.xsc-tag--ok { background: rgba(34,197,94,0.15); color: #22c55e; }
			.xsc-tag--err { background: rgba(239,68,68,0.15); color: #ef4444; }
			.xsc-tag--warn { background: rgba(245,158,11,0.15); color: #d97706; }
			.xsc-actions { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
		</style>';

		// --- Status cards ---
		$h .= '<div class="xsc-grid">';

		// Webhook config
		$webhookClass = $stats['gateway_webhook_configured'] ? 'xsc-card--ok' : 'xsc-card--err';
		$webhookLabel = $stats['gateway_webhook_configured'] ? 'Configured' : 'Not configured';
		$webhookTag = $stats['gateway_webhook_configured'] ? 'xsc-tag--ok' : 'xsc-tag--err';
		$h .= '<div class="xsc-card ' . $webhookClass . '">'
			. '<div class="xsc-card-label">Webhook</div>'
			. '<div class="xsc-card-value"><span class="xsc-tag ' . $webhookTag . '">' . $webhookLabel . '</span></div>'
			. '<div class="xsc-card-sub">URL + signing secret</div>'
			. '</div>';

		// Replay task
		$replayClass = $stats['replay_recent_run'] ? 'xsc-card--ok' : 'xsc-card--warn';
		$replayTag = $stats['replay_recent_run'] ? 'xsc-tag--ok' : 'xsc-tag--warn';
		$replayLabel = $stats['replay_recent_run'] ? 'Healthy' : 'Stale';
		$replayTime = $this->formatTimestamp( $stats['replay_last_run_at'] );
		$h .= '<div class="xsc-card ' . $replayClass . '">'
			. '<div class="xsc-card-label">Replay Task</div>'
			. '<div class="xsc-card-value"><span class="xsc-tag ' . $replayTag . '">' . $replayLabel . '</span></div>'
			. '<div class="xsc-card-sub">Last run: ' . $this->escape( $replayTime ) . '</div>'
			. '</div>';

		// Webhook errors
		$errorCount = (int) $stats['webhook_error_count_24h'];
		$errorClass = $errorCount === 0 ? 'xsc-card--ok' : 'xsc-card--err';
		$h .= '<div class="xsc-card ' . $errorClass . '">'
			. '<div class="xsc-card-label">Errors (24h)</div>'
			. '<div class="xsc-card-value">' . $errorCount . '</div>'
			. '<div class="xsc-card-sub">Webhook + snapshot log entries</div>'
			. '</div>';

		// Mismatches
		$mismatch30 = (int) $stats['mismatch_count_30d'];
		$mismatchAll = (int) $stats['mismatch_count_all_time'];
		$mismatchClass = $mismatch30 === 0 ? 'xsc-card--ok' : 'xsc-card--warn';
		$h .= '<div class="xsc-card ' . $mismatchClass . '">'
			. '<div class="xsc-card-label">Mismatches (30d)</div>'
			. '<div class="xsc-card-value">' . $mismatch30 . '</div>'
			. '<div class="xsc-card-sub">' . $mismatchAll . ' all time</div>'
			. '</div>';

		// Endpoint events
		$endpointFound = ( $stats['webhook_endpoint'] !== NULL );
		$missingCount = \count( $stats['webhook_events_missing'] );
		if ( !$endpointFound )
		{
			$endpointClass = 'xsc-card--err';
			$endpointTag = 'xsc-tag--err';
			$endpointLabel = $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_endpoint_not_found' ) );
		}
		elseif ( $missingCount > 0 )
		{
			$endpointClass = 'xsc-card--warn';
			$endpointTag = 'xsc-tag--warn';
			$endpointLabel = $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_events_drift', FALSE, array( 'sprintf' => array( $missingCount ) ) ) );
		}
		else
		{
			$endpointClass = 'xsc-card--ok';
			$endpointTag = 'xsc-tag--ok';
			$endpointLabel = $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_events_ok' ) );
		}
		$h .= '<div class="xsc-card ' . $endpointClass . '">'
			. '<div class="xsc-card-label">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_endpoint' ) ) . '</div>'
			. '<div class="xsc-card-value"><span class="xsc-tag ' . $endpointTag . '">' . $endpointLabel . '</span></div>'
			. '<div class="xsc-card-sub">Stripe endpoint events</div>'
			. '</div>';

		// Tax readiness
		$trStatus = $stats['tax_readiness_status'];
		if ( $trStatus === 'collecting' )
		{
			$trClass = 'xsc-card--ok';
			$trTag = 'xsc-tag--ok';
		}
		elseif ( $trStatus === 'error' )
		{
			$trClass = 'xsc-card--err';
			$trTag = 'xsc-tag--err';
		}
		else
		{
			$trClass = 'xsc-card--warn';
			$trTag = 'xsc-tag--warn';
		}
		$trLabel = $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_' . $trStatus ) );
		$trRegCount = (int) $stats['tax_readiness_registrations_count'];
		$h .= '<div class="xsc-card ' . $trClass . '">'
			. '<div class="xsc-card-label">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness' ) ) . '</div>'
			. '<div class="xsc-card-value"><span class="xsc-tag ' . $trTag . '">' . $trLabel . '</span></div>'
			. '<div class="xsc-card-sub">' . $trRegCount . ' ' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_registrations_label' ) ) . '</div>'
			. '</div>';

		$h .= '</div>'; // .xsc-grid

		// --- Replay details ---
		$h .= '<div class="xsc-section">';
		$h .= '<div class="xsc-section-title">Webhook Replay</div>';

		$h .= '<div class="xsc-actions">';
		$h .= '<a href="' . $this->escape( (string) $replayUrl ) . '" class="ipsButton ipsButton_primary ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_now' ) ) . '</a>';
		$h .= '<a href="' . $this->escape( (string) $dryRunUrl ) . '" class="ipsButton ipsButton_alternate ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run' ) ) . '</a>';
		$h .= '<span style="font-size:12px;opacity:0.5;">Runs automatically every 15 minutes via task scheduler</span>';
		$h .= '</div>';

		$h .= '<table class="xsc-table">';
		$h .= '<tr><td style="width:240px;font-weight:600;">Events processed (last run)</td><td>' . $this->escape( (string) $stats['replay_last_replayed_count'] ) . '</td></tr>';
		$h .= '<tr><td style="font-weight:600;">Last event cursor</td><td>' . $this->escape( $this->formatTimestamp( $stats['replay_last_event_created'] ) ) . '</td></tr>';
		$h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_lookback' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_lookback'] ) . 's</td></tr>';
		$h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_overlap' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_overlap'] ) . 's</td></tr>';
		$h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_replay_max_events' ) ) . '</td><td>' . $this->escape( (string) $stats['replay_config_max_events'] ) . '</td></tr>';
		$h .= '</table>';
		$h .= '</div>'; // .xsc-section

		// --- Webhook Endpoint ---
		$h .= $this->renderWebhookEndpointSection( $stats );

		// --- Tax Readiness ---
		$h .= $this->renderTaxReadinessSection( $stats );

		// --- Webhook errors ---
		$h .= '<div class="xsc-section">';
		$h .= '<div class="xsc-section-title">Recent Webhook Errors</div>';
		$h .= $this->renderWebhookErrorTable( $stats['recent_webhook_errors'] );
		$h .= '</div>';

		// --- Mismatch table ---
		$h .= '<div class="xsc-section">';
		$h .= '<div class="xsc-section-title">Stripe vs IPS Total Mismatches</div>';
		$h .= $this->renderMismatchTable( $stats['recent_mismatch_rows'] );
		$h .= '</div>';

		return $h;
	}

	/**
	 * Manually trigger webhook replay task now.
	 *
	 * @return	void
	 */
	protected function runReplay()
	{
		\IPS\Session::i()->csrfCheck();
		$redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

		try
		{
			$result = $this->executeReplayTaskNow();
			\IPS\Session::i()->log( 'acplogs__xpolarcheckout_integrity_replay' );

			\IPS\Output::i()->redirect(
				$redirectUrl,
				$result['message']
			);
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_integrity_replay' );
			\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_integrity_replay_failed' );
		}
	}

	/**
	 * Run webhook replay in dry-run mode (fetch and filter only, no forwarding).
	 *
	 * @return	void
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
			if ( $count > 0 )
			{
				$message = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run_result', FALSE, array( 'sprintf' => array( $count ) ) );
			}
			else
			{
				$message = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_integrity_replay_dry_run_none' );
			}

			\IPS\Output::i()->redirect( $redirectUrl, $message );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_integrity_replay' );
			\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_integrity_replay_failed' );
		}
	}

	/**
	 * Execute replay task and map to language message key.
	 *
	 * @return	array{message:string,result:mixed}
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
	 * Build data model for the panel.
	 *
	 * @return	array
	 */
	protected function collectIntegrityStats()
	{
		$stats = array(
			'gateway_webhook_configured' => FALSE,
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
			'webhook_api_version_match' => FALSE,
			'webhook_endpoint_status' => NULL,
			'tax_readiness_status' => 'unknown',
			'tax_readiness_last_checked' => NULL,
			'tax_readiness_registrations_count' => 0,
			'tax_readiness_registrations_summary' => '',
			'tax_readiness_error' => '',
		);

		$gatewaySettings = $this->loadGatewaySettings();
		if ( \is_array( $gatewaySettings ) )
		{
			$stats['gateway_webhook_configured'] = !empty( $gatewaySettings['webhook_url'] ) && !empty( $gatewaySettings['webhook_secret'] );

			/* Replay config from gateway settings */
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
				$stats['replay_config_max_events'] = \max( 10, \min( 500, (int) $gatewaySettings['replay_max_events'] ) );
			}

			/* Tax readiness snapshot from stored settings */
			if ( isset( $gatewaySettings['tax_readiness_status'] ) )
			{
				$stats['tax_readiness_status'] = (string) $gatewaySettings['tax_readiness_status'];
				$stats['tax_readiness_last_checked'] = isset( $gatewaySettings['tax_readiness_last_checked'] ) ? (int) $gatewaySettings['tax_readiness_last_checked'] : NULL;
				$stats['tax_readiness_registrations_count'] = isset( $gatewaySettings['tax_readiness_registrations_count'] ) ? (int) $gatewaySettings['tax_readiness_registrations_count'] : 0;
				$stats['tax_readiness_registrations_summary'] = isset( $gatewaySettings['tax_readiness_registrations_summary'] ) ? (string) $gatewaySettings['tax_readiness_registrations_summary'] : '';
				$stats['tax_readiness_error'] = isset( $gatewaySettings['tax_readiness_error'] ) ? (string) $gatewaySettings['tax_readiness_error'] : '';
			}

			$endpoint = \IPS\xpolarcheckout\XPolarCheckout::fetchWebhookEndpoint( $gatewaySettings );
			if ( $endpoint !== NULL AND \is_array( $endpoint ) )
			{
				$stats['webhook_endpoint'] = $endpoint;
				$stats['webhook_endpoint_status'] = isset( $endpoint['status'] ) ? (string) $endpoint['status'] : NULL;

				$stripeEvents = ( isset( $endpoint['enabled_events'] ) AND \is_array( $endpoint['enabled_events'] ) ) ? $endpoint['enabled_events'] : array();
				$requiredEvents = \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS;

				$stats['webhook_events_missing'] = \array_values( \array_diff( $requiredEvents, $stripeEvents ) );
				$stats['webhook_events_extra'] = \array_values( \array_diff( $stripeEvents, $requiredEvents ) );

				$expectedUrl = !empty( $gatewaySettings['webhook_url'] ) ? $gatewaySettings['webhook_url'] : '';
				$stats['webhook_endpoint_url_match'] = ( isset( $endpoint['url'] ) AND $endpoint['url'] === $expectedUrl );

				$stats['webhook_api_version_match'] = ( isset( $endpoint['api_version'] ) AND $endpoint['api_version'] === \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION );
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

		$dayAgo = time() - 86400;
		$monthAgo = time() - ( 30 * 86400 );

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

			$fields = "t_id,t_invoice,t_date,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.amount_total_display')) AS stripe_total_display,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.ips_invoice_total_display')) AS ips_total_display,JSON_UNQUOTE(JSON_EXTRACT(t_extra, '$.xpolarcheckout_snapshot.total_mismatch_display')) AS mismatch_display";
			foreach ( \IPS\Db::i()->select( $fields, 'nexus_transactions', $mismatchWhere, 't_id DESC', 10 ) as $row )
			{
				$stats['recent_mismatch_rows'][] = $row;
			}
		}
		catch ( \Exception $e ) {}

		return $stats;
	}

	/**
	 * Resolve active XPolarCheckout gateway settings.
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
	 * Sync Stripe webhook endpoint events to match code requirements.
	 *
	 * @return	void
	 */
	protected function syncEvents()
	{
		\IPS\Session::i()->csrfCheck();
		$redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

		try
		{
			$gatewaySettings = $this->loadGatewaySettings();
			if ( !\is_array( $gatewaySettings ) OR empty( $gatewaySettings['secret'] ) )
			{
				\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_not_found' );
				return;
			}

			$endpoint = \IPS\xpolarcheckout\XPolarCheckout::fetchWebhookEndpoint( $gatewaySettings );
			if ( $endpoint === NULL OR !isset( $endpoint['id'] ) )
			{
				\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_webhook_sync_not_found' );
				return;
			}

			\IPS\xpolarcheckout\XPolarCheckout::syncWebhookEvents( $gatewaySettings, $endpoint['id'] );

			/* Backfill endpoint ID for legacy installs */
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
	 * Backfill webhook_endpoint_id into gateway settings for legacy installs.
	 *
	 * @param	string	$endpointId	Stripe endpoint ID
	 * @return	void
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
	 * Render the webhook endpoint detail section.
	 *
	 * @param	array	$stats	Integrity stats
	 * @return	string
	 */
	protected function renderWebhookEndpointSection( array $stats )
	{
		$h = '<div class="xsc-section">';
		$h .= '<div class="xsc-section-title">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_endpoint' ) ) . '</div>';

		$endpoint = $stats['webhook_endpoint'];
		if ( $endpoint === NULL )
		{
			$h .= '<p class="xsc-empty">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_sync_not_found' ) ) . '</p>';
			$h .= '</div>';
			return $h;
		}

		/* Info table */
		$h .= '<table class="xsc-table">';

		$epId = isset( $endpoint['id'] ) ? (string) $endpoint['id'] : '-';
		$h .= '<tr><td style="width:240px;font-weight:600;">Endpoint ID</td><td>' . $this->escape( $epId ) . '</td></tr>';

		$epUrl = isset( $endpoint['url'] ) ? (string) $endpoint['url'] : '-';
		$urlBadge = $stats['webhook_endpoint_url_match']
			? '<span class="xsc-tag xsc-tag--ok">Match</span>'
			: '<span class="xsc-tag xsc-tag--err">Mismatch</span>';
		$h .= '<tr><td style="font-weight:600;">URL</td><td>' . $this->escape( $epUrl ) . ' ' . $urlBadge . '</td></tr>';

		$epStatus = $stats['webhook_endpoint_status'] !== NULL ? $stats['webhook_endpoint_status'] : '-';
		$statusTag = ( $epStatus === 'enabled' ) ? 'xsc-tag--ok' : 'xsc-tag--warn';
		$h .= '<tr><td style="font-weight:600;">Status</td><td><span class="xsc-tag ' . $statusTag . '">' . $this->escape( $epStatus ) . '</span></td></tr>';

		$epApiVer = isset( $endpoint['api_version'] ) ? (string) $endpoint['api_version'] : '-';
		$apiVerBadge = $stats['webhook_api_version_match']
			? '<span class="xsc-tag xsc-tag--ok">Match</span>'
			: '<span class="xsc-tag xsc-tag--warn">Mismatch</span>';
		$h .= '<tr><td style="font-weight:600;">API Version</td><td>' . $this->escape( $epApiVer ) . ' ' . $apiVerBadge . '</td></tr>';

		$h .= '</table>';

		/* Events checklist */
		$stripeEvents = ( isset( $endpoint['enabled_events'] ) AND \is_array( $endpoint['enabled_events'] ) ) ? $endpoint['enabled_events'] : array();
		$h .= '<table class="xsc-table" style="margin-top:12px;">';
		$h .= '<thead><tr><th>Required Event</th><th>Registered</th></tr></thead><tbody>';
		foreach ( \IPS\xpolarcheckout\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS as $event )
		{
			$registered = \in_array( $event, $stripeEvents, TRUE );
			$icon = $registered
				? '<span class="xsc-tag xsc-tag--ok">&#10003;</span>'
				: '<span class="xsc-tag xsc-tag--err">&#10005;</span>';
			$h .= '<tr><td>' . $this->escape( $event ) . '</td><td>' . $icon . '</td></tr>';
		}
		$h .= '</tbody></table>';

		/* Extra events (informational) */
		if ( \count( $stats['webhook_events_extra'] ) )
		{
			$h .= '<table class="xsc-table" style="margin-top:12px;">';
			$h .= '<thead><tr><th>Extra Events (not required by code)</th></tr></thead><tbody>';
			foreach ( $stats['webhook_events_extra'] as $extra )
			{
				$h .= '<tr><td>' . $this->escape( $extra ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		}

		/* Sync button — shown when events are missing OR API version mismatch */
		$missingCount = \count( $stats['webhook_events_missing'] );
		if ( $missingCount > 0 OR !$stats['webhook_api_version_match'] )
		{
			$syncUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=syncEvents', 'admin' )->csrf();
			$h .= '<div class="xsc-actions" style="margin-top:16px;">';
			$h .= '<a href="' . $this->escape( (string) $syncUrl ) . '" class="ipsButton ipsButton_primary ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_webhook_sync_events' ) ) . '</a>';
			$h .= '</div>';
		}

		$h .= '</div>';
		return $h;
	}

	/**
	 * Render tax readiness detail section.
	 *
	 * @param	array	$stats	Integrity stats
	 * @return	string
	 */
	protected function renderTaxReadinessSection( array $stats )
	{
		$h = '<div class="xsc-section">';
		$h .= '<div class="xsc-section-title">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness' ) ) . '</div>';

		$trStatus = $stats['tax_readiness_status'];
		$trLastChecked = $stats['tax_readiness_last_checked'];
		$trRegCount = (int) $stats['tax_readiness_registrations_count'];
		$trRegSummary = $stats['tax_readiness_registrations_summary'];
		$trError = $stats['tax_readiness_error'];

		$h .= '<table class="xsc-table">';

		/* Status */
		$statusLabel = \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_' . $trStatus );
		if ( $trStatus === 'collecting' )
		{
			$statusTag = 'xsc-tag--ok';
		}
		elseif ( $trStatus === 'error' )
		{
			$statusTag = 'xsc-tag--err';
		}
		else
		{
			$statusTag = 'xsc-tag--warn';
		}
		$h .= '<tr><td style="width:240px;font-weight:600;">Status</td><td><span class="xsc-tag ' . $statusTag . '">' . $this->escape( $statusLabel ) . '</span></td></tr>';

		/* Last checked */
		$h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_last_checked' ) ) . '</td><td>' . $this->escape( $this->formatTimestamp( $trLastChecked ) ) . '</td></tr>';

		/* Registrations */
		$regDisplay = $trRegCount . ( $trRegSummary !== '' ? ' (' . $trRegSummary . ')' : '' );
		$h .= '<tr><td style="font-weight:600;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_registrations_label' ) ) . '</td><td>' . $this->escape( $regDisplay ) . '</td></tr>';

		/* Error message if present */
		if ( $trError !== '' )
		{
			$h .= '<tr><td style="font-weight:600;">Error</td><td><span class="xsc-tag xsc-tag--err">' . $this->escape( $trError ) . '</span></td></tr>';
		}

		$h .= '</table>';

		/* Warning for not_collecting or error */
		if ( $trStatus === 'not_collecting' OR $trStatus === 'error' OR $trStatus === 'unknown' )
		{
			$h .= '<p style="margin-top:12px;color:#d97706;font-size:13px;">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_warning' ) ) . '</p>';
		}

		/* Refresh button */
		$refreshUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity&do=refreshTaxReadiness', 'admin' )->csrf();
		$h .= '<div class="xsc-actions" style="margin-top:16px;">';
		$h .= '<a href="' . $this->escape( (string) $refreshUrl ) . '" class="ipsButton ipsButton_primary ipsButton_verySmall">' . $this->escape( \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_tax_readiness_refresh' ) ) . '</a>';
		$h .= '</div>';

		$h .= '</div>';
		return $h;
	}

	/**
	 * Refresh Stripe Tax readiness status via live API call.
	 *
	 * @return	void
	 */
	protected function refreshTaxReadiness()
	{
		\IPS\Session::i()->csrfCheck();
		$redirectUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );

		try
		{
			$gatewaySettings = $this->loadGatewaySettings();
			if ( !\is_array( $gatewaySettings ) OR empty( $gatewaySettings['secret'] ) )
			{
				\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_tax_readiness_refresh_failed' );
				return;
			}

			$updatedSettings = \IPS\xpolarcheckout\XPolarCheckout::applyTaxReadinessSnapshotToSettings( $gatewaySettings );

			/* Persist updated settings to gateway */
			foreach ( \IPS\nexus\Gateway::roots() as $gateway )
			{
				if ( $gateway instanceof \IPS\xpolarcheckout\XPolarCheckout )
				{
					$current = \json_decode( $gateway->settings, TRUE );
					if ( \is_array( $current ) )
					{
						$taxKeys = array( 'tax_readiness_status', 'tax_readiness_last_checked', 'tax_readiness_registrations_count', 'tax_readiness_registrations_summary', 'tax_readiness_details', 'tax_readiness_error' );
						foreach ( $taxKeys as $tk )
						{
							if ( isset( $updatedSettings[ $tk ] ) )
							{
								$current[ $tk ] = $updatedSettings[ $tk ];
							}
						}
						$gateway->settings = \json_encode( $current );
						$gateway->save();
					}
					break;
				}
			}

			\IPS\Session::i()->log( 'acplogs__xpolarcheckout_tax_readiness_refresh' );
			\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_tax_readiness_refresh_success' );
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_tax' );
			\IPS\Output::i()->redirect( $redirectUrl, 'xpolarcheckout_tax_readiness_refresh_failed' );
		}
	}

	/**
	 * Render recent webhook errors table.
	 *
	 * @param	array	$rows	Error rows
	 * @return	string
	 */
	protected function renderWebhookErrorTable( array $rows )
	{
		if ( !\count( $rows ) )
		{
			return '<p class="xsc-empty">No webhook or snapshot processing errors in the last 24 hours.</p>';
		}

		$output = '<table class="xsc-table">';
		$output .= '<thead><tr><th>Time (UTC)</th><th>Category</th><th>Message</th></tr></thead><tbody>';
		foreach ( $rows as $row )
		{
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';
			$message = \trim( \preg_replace( '/\s+/', ' ', $message ) );
			if ( \mb_strlen( $message ) > 200 )
			{
				$message = \mb_substr( $message, 0, 197 ) . '...';
			}

			$cat = isset( $row['category'] ) ? (string) $row['category'] : '';
			$catShort = \str_replace( 'xpolarcheckout_', '', $cat );

			$output .= '<tr>'
				. '<td style="white-space:nowrap;">' . $this->escape( $this->formatTimestamp( isset( $row['time'] ) ? (int) $row['time'] : NULL ) ) . '</td>'
				. '<td><span class="xsc-tag xsc-tag--err">' . $this->escape( $catShort ) . '</span></td>'
				. '<td>' . $this->escape( $message ) . '</td>'
				. '</tr>';
		}
		$output .= '</tbody></table>';

		return $output;
	}

	/**
	 * Render recent mismatch rows table.
	 *
	 * @param	array	$rows	Mismatch rows
	 * @return	string
	 */
	protected function renderMismatchTable( array $rows )
	{
		if ( !\count( $rows ) )
		{
			return '<p class="xsc-empty">No Stripe-vs-IPS total mismatches detected.</p>';
		}

		$output = '<table class="xsc-table">';
		$output .= '<thead><tr><th>Transaction</th><th>Invoice</th><th>Date</th><th>Stripe Total</th><th>IPS Total</th><th>Difference</th></tr></thead><tbody>';
		foreach ( $rows as $row )
		{
			$txUrl = \IPS\Http\Url::internal( 'app=nexus&module=payments&controller=transactions&do=view&id=' . (int) $row['t_id'], 'admin' );
			$invUrl = \IPS\Http\Url::internal( 'app=nexus&module=payments&controller=invoices&do=view&id=' . (int) $row['t_invoice'], 'admin' );
			$date = isset( $row['t_date'] ) ? $this->formatTimestamp( (int) $row['t_date'] ) : '-';

			$output .= '<tr>'
				. '<td><a href="' . $this->escape( (string) $txUrl ) . '">#' . $this->escape( (string) $row['t_id'] ) . '</a></td>'
				. '<td><a href="' . $this->escape( (string) $invUrl ) . '">#' . $this->escape( (string) $row['t_invoice'] ) . '</a></td>'
				. '<td style="white-space:nowrap;">' . $this->escape( $date ) . '</td>'
				. '<td>' . $this->escape( isset( $row['stripe_total_display'] ) ? (string) $row['stripe_total_display'] : '-' ) . '</td>'
				. '<td>' . $this->escape( isset( $row['ips_total_display'] ) ? (string) $row['ips_total_display'] : '-' ) . '</td>'
				. '<td><span class="xsc-tag xsc-tag--warn">' . $this->escape( isset( $row['mismatch_display'] ) ? (string) $row['mismatch_display'] : '-' ) . '</span></td>'
				. '</tr>';
		}
		$output .= '</tbody></table>';

		return $output;
	}

	/**
	 * Format unix timestamp for ACP display.
	 *
	 * @param	int|NULL	$timestamp	Unix timestamp
	 * @return	string
	 */
	protected function formatTimestamp( $timestamp )
	{
		if ( $timestamp === NULL OR $timestamp <= 0 )
		{
			return '-';
		}

		return \gmdate( 'Y-m-d H:i:s', (int) $timestamp ) . ' UTC';
	}

	/**
	 * Escape HTML.
	 *
	 * @param	string	$value	Value
	 * @return	string
	 */
	protected function escape( $value )
	{
		return \htmlspecialchars( (string) $value, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
	}
}
