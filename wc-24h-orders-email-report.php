<?php
/**
 * Plugin Name: WooCommerce 24h Orders Email Report
 * Plugin URI: https://github.com/DeepVoid/wc-24h-orders-email-report
 * Text Domain: woocommerce-24h-orders-email-report
 * Description: Invia automaticamente via email il report degli ordini ricevuti nelle ultime 24 ore, con destinatari e orario configurabili.
 * Version: 1.1.9
 * Author: Alex Vannini - DeepVoid
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 10.2
 * License: GPLv2 or later
 * Copyright (C) 2026 Alex Vannini - DeepVoid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestisce impostazioni, pianificazione e invio del report giornaliero WooCommerce.
 */
final class CB_WC_24H_Orders_Email_Report {

	const VERSION    = '1.1.9';
	const AS_GROUP   = 'cb-wc-24h-report';
	const OPTION_KEY = 'cb_wc_24h_report_settings';
	const CRON_HOOK  = 'cb_wc_24h_report_send';

	/** Registra tutti gli hook WordPress usati dal plugin. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_scheduled_report' ) );
		add_action( 'admin_post_cb_wc_24h_test_report', array( __CLASS__, 'handle_test_report' ) );
		add_action( 'admin_post_cb_wc_24h_send_now', array( __CLASS__, 'handle_send_now' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'reschedule_after_settings_update' ), 10, 3 );
	}

	/** Pianifica il primo invio quando il plugin viene attivato. */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::schedule_next_run();
	}

	/** Rimuove tutte le pianificazioni quando il plugin viene disattivato. */
	public static function deactivate() {
		self::unschedule();
	}

	/**
	 * Restituisce i valori iniziali della configurazione del plugin.
	 *
	 * @return array Impostazioni predefinite.
	 */
	public static function defaults() {
		$statuses = array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' );

		return array(
			'recipients'          => get_option( 'admin_email' ),
			'time'                => '08:00',
			'statuses'            => $statuses,
			'send_empty'          => 'yes',
			'include_order_link'  => 'yes',
		);
	}

	/**
	 * Legge le impostazioni salvate e completa eventuali valori mancanti.
	 *
	 * @return array Impostazioni effettive del report.
	 */
	public static function settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Registra l'opzione del plugin e la relativa callback di sanitizzazione. */
	public static function register_settings() {
		register_setting(
			'cb_wc_24h_report',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Convalida e normalizza i dati inviati dalla pagina impostazioni.
	 *
	 * @param array $input Valori inviati dal form amministrativo.
	 * @return array Valori sicuri da memorizzare.
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$output   = array();

		$raw_recipients = isset( $input['recipients'] ) ? (string) $input['recipients'] : '';
		$emails         = preg_split( '/[\s,;]+/', $raw_recipients, -1, PREG_SPLIT_NO_EMPTY );
		$valid_emails   = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$valid_emails[] = $email;
			}
		}

		$output['recipients'] = implode( "\n", array_unique( $valid_emails ) );

		$time = isset( $input['time'] ) ? sanitize_text_field( $input['time'] ) : $defaults['time'];
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = $defaults['time'];
		}
		$output['time'] = $time;

		$available_statuses = array_keys( wc_get_order_statuses() );
		$selected_statuses  = isset( $input['statuses'] ) && is_array( $input['statuses'] ) ? $input['statuses'] : array();
		$output['statuses'] = array_values( array_intersect( array_map( 'sanitize_key', $selected_statuses ), $available_statuses ) );

		$output['send_empty']         = ! empty( $input['send_empty'] ) ? 'yes' : 'no';
		$output['include_order_link'] = ! empty( $input['include_order_link'] ) ? 'yes' : 'no';

		return $output;
	}

	/** Aggiunge la pagina del report nel sottomenu di WooCommerce. */
	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Report ordini 24 ore',
			'Report ordini 24 ore',
			'manage_woocommerce',
			'cb-wc-24h-report',
			array( __CLASS__, 'settings_page' )
		);
	}

	/** Visualizza il form delle impostazioni e lo stato della pianificazione. */
	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'cb-wc-24h-report' ) );
		}

		$settings = self::settings();
		$next_run = self::next_scheduled_timestamp();
		$notice   = isset( $_GET['cb_report_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['cb_report_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1>WooCommerce – Report ordini ultime 24 ore</h1>
			<h3>Plugin di Alex Vannini - Ver. <?php echo self::VERSION ?></h3>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p>Il report contiene gli ordini <strong>creati nelle ultime 24 ore</strong> rispetto al momento dell'invio.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cb_wc_24h_report' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cb-recipients">Destinatari email</label></th>
						<td>
							<textarea id="cb-recipients" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recipients]" rows="6" cols="60" class="large-text code"><?php echo esc_textarea( $settings['recipients'] ); ?></textarea>
							<p class="description">Un indirizzo per riga, oppure indirizzi separati da virgola o punto e virgola.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cb-time">Orario invio</label></th>
						<td>
							<input id="cb-time" type="time" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[time]" value="<?php echo esc_attr( $settings['time'] ); ?>" />
							<p class="description">Usa il fuso orario configurato in WordPress.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Stati ordine inclusi</th>
						<td>
							<?php foreach ( wc_get_order_statuses() as $status_key => $status_label ) : ?>
								<label style="display:block;margin:4px 0;">
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[statuses][]"
										value="<?php echo esc_attr( $status_key ); ?>"
										<?php checked( in_array( $status_key, (array) $settings['statuses'], true ) ); ?> />
									<?php echo esc_html( $status_label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">Per impostazione predefinita sono inclusi Pending, Processing, On hold e Completed.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Nessun ordine</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[send_empty]" value="yes" <?php checked( $settings['send_empty'], 'yes' ); ?> />
								Invia comunque una email quando non ci sono ordini nelle ultime 24 ore.
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Numero ordine</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[include_order_link]" value="yes" <?php checked( $settings['include_order_link'], 'yes' ); ?> />
								Rendi cliccabile il numero ordine per gli utenti autorizzati al backoffice.
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Salva impostazioni' ); ?>
			</form>

			<hr />

			<h2>Invio manuale</h2>
			<p>Puoi usare questi pulsanti per verificare il report senza aspettare l'orario programmato.</p>

			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cb_wc_24h_test_report' ), 'cb_wc_24h_test_report' ) ); ?>">
					Invia report di test
				</a>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cb_wc_24h_send_now' ), 'cb_wc_24h_send_now' ) ); ?>" style="margin-left:8px;">
					Invia report adesso ai destinatari
				</a>
			</p>

			<h2>Programmazione</h2>
			<p>
				<?php if ( $next_run ) : ?>
					Prossimo invio previsto: <strong><?php echo esc_html( wp_date( 'd/m/Y H:i', $next_run ) ); ?></strong>.
				<?php else : ?>
					<strong>Evento non programmato.</strong> Salva nuovamente le impostazioni per riprogrammarlo.
				<?php endif; ?>
			</p>
			<p>
				Scheduler utilizzato:
				<strong><?php echo esc_html( self::action_scheduler_available() ? 'Action Scheduler (WooCommerce)' : 'WP-Cron fallback' ); ?></strong>.
				<?php if ( self::action_scheduler_available() ) : ?>
					Puoi monitorare il job in <strong>WooCommerce &gt; Stato &gt; Scheduled Actions</strong>.
				<?php endif; ?>
			</p>

			<div class="notice notice-warning inline">
				<p><strong>Nota:</strong> Action Scheduler rende il job più robusto e tracciabile rispetto a un semplice evento WP-Cron, ma il runner standard utilizza comunque WP-Cron/loopback. Per un'esecuzione strettamente controllata dal server è consigliabile affiancare un cron di sistema o WP-CLI.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Ripianifica l'invio dopo il salvataggio dell'opzione del plugin.
	 *
	 * @param mixed  $old_value Valore dell'opzione prima del salvataggio.
	 * @param mixed  $value     Nuovo valore dell'opzione.
	 * @param string $option    Nome dell'opzione aggiornata.
	 */
	public static function reschedule_after_settings_update( $old_value, $value, $option ) {
		if ( self::OPTION_KEY !== $option ) {
			return;
		}
		self::schedule_next_run();
	}

	/** Verifica che siano disponibili tutte le API Action Scheduler necessarie. */
	private static function action_scheduler_available() {
		return function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_next_scheduled_action' );
	}

	/** Elimina gli eventi Action Scheduler e gli eventuali eventi WP-Cron legacy. */
	private static function unschedule() {
		if ( self::action_scheduler_available() ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), self::AS_GROUP );
		}

		// Rimuove gli eventi WP-Cron legacy creati da precedenti release del plugin
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Calcola il prossimo orario locale e programma l'invio giornaliero.
	 * Usa Action Scheduler quando disponibile, altrimenti WP-Cron.
	 */
	public static function schedule_next_run() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::unschedule();

		$settings = self::settings();
		$parts    = explode( ':', $settings['time'] );
		$hour     = isset( $parts[0] ) ? absint( $parts[0] ) : 8;
		$minute   = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

		$now  = current_datetime();
		$next = $now->setTime( $hour, $minute, 0 );

		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+1 day' );
		}

		if ( self::action_scheduler_available() ) {
			$result = as_schedule_cron_action(
				$next->getTimestamp(),
				sprintf( '%d %d * * *', $minute, $hour ),
				self::CRON_HOOK,
				array(),
				self::AS_GROUP,
				true
			);

			if ( 0 !== $result ) {
				return;
			}

			error_log( '[CB WC 24h Report] Action Scheduler failed; falling back to WP-Cron.' );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
		}
	}

	/** Garantisce che esista un'azione pianificata in Action Scheduler. */
	public static function ensure_action_scheduler_event() {
		if ( ! self::action_scheduler_available() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP )
			: (bool) as_next_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP );

		if ( ! $scheduled ) {
			self::schedule_next_run();
		}
	}

	/**
	 * Recupera il timestamp del prossimo invio pianificato.
	 *
	 * @return int|false Timestamp Unix o false se non esiste alcun evento.
	 */
	public static function next_scheduled_timestamp() {
		if ( self::action_scheduler_available() ) {
			$next = as_next_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP );
			if ( is_numeric( $next ) ) {
				return (int) $next;
			}
		}

		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ? (int) $next : false;
	}

	/** Callback dell'evento pianificato: invia il report ai destinatari configurati. */
	public static function send_scheduled_report() {
		self::send_report( false );
	}

	/** Gestisce l'invio di prova al solo indirizzo amministrativo, con verifica nonce. */
	public static function handle_test_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'cb-wc-24h-report' ) );
		}
		check_admin_referer( 'cb_wc_24h_test_report' );

		$settings = self::settings();
		$test_recipient = get_option( 'admin_email' );

		if ( ! is_email( $test_recipient ) ) {
			$test_recipient = '';
		}

		$result = self::send_report( true, array( $test_recipient ) );

		$message = is_wp_error( $result ) ? $result->get_error_message() : 'Report di test inviato.';
		wp_safe_redirect( add_query_arg( 'cb_report_notice', rawurlencode( $message ), admin_url( 'admin.php?page=cb-wc-24h-report' ) ) );
		exit;
	}

	/** Gestisce l'invio manuale immediato, con controllo permessi e nonce. */
	public static function handle_send_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'cb-wc-24h-report' ) );
		}
		check_admin_referer( 'cb_wc_24h_send_now' );

		$result = self::send_report( false );
		$message = is_wp_error( $result ) ? $result->get_error_message() : 'Report inviato ai destinatari configurati.';

		wp_safe_redirect( add_query_arg( 'cb_report_notice', rawurlencode( $message ), admin_url( 'admin.php?page=cb-wc-24h-report' ) ) );
		exit;
	}

	/**
	 * Cerca gli ordini dell'ultima finestra di 24 ore, compone il messaggio e lo invia.
	 *
	 * @param bool  $test                Indica se si tratta di un invio di prova.
	 * @param array $override_recipients Destinatari da usare esclusivamente in modalità test.
	 * @return true|WP_Error Esito dell'invio.
	 */
	private static function send_report( $test = false, $override_recipients = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woocommerce_missing', 'WooCommerce non è attivo.' );
		}

		$settings = self::settings();

		$recipients = $test ? array_filter( array_map( 'sanitize_email', $override_recipients ) ) : self::recipient_list( $settings['recipients'] );
		if ( empty( $recipients ) ) {
			return new WP_Error( 'no_recipients', 'Non sono configurati destinatari validi.' );
		}

		// Istante corrente in formato timestamp Unix.
		$now = time();

		// Inizio della finestra di rilevamento: 24 ore (86.400 secondi) prima dell'invio.
		$from = $now - DAY_IN_SECONDS;
		$statuses = array_values( array_filter( (array) $settings['statuses'] ) );

		$args = array(
			'limit'        => -1,
			'orderby'      => 'date',
			'order'        => 'ASC',
			// Recupera soltanto gli ordini creati tra $from e $now, estremi inclusi.
			'date_created' => $from . '...' . $now,
			'return'       => 'objects',
		);

		if ( ! empty( $statuses ) ) {
			$args['status'] = $statuses;
		}

		$orders = wc_get_orders( $args );

		if ( empty( $orders ) && 'yes' !== $settings['send_empty'] && ! $test ) {
			return true;
		}

		// imposta il subject dell'email
		$subject = sprintf(
			'Report ultime 24h – %d ordin%s',
			count( $orders ),
			1 === count( $orders ) ? 'e' : 'i'
		);

		$body = self::build_email( $orders, $settings, $from, $now, $test );

		// imposta gli header dell'email di report
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: Report ordini <noreply@campobase.net>',
		);

		// invia l'email via SMTP tramite la funzione wp_mail() di Wordpress
		$sent = wp_mail( $recipients, $subject, $body, $headers );

		if ( ! $sent ) {
			return new \WP_Error( 'mail_failed', 'WordPress non ha potuto inviare la email. Controlla la configurazione SMTP del sito.' );
		}

		return true;
	}

	/**
	 * Estrae, sanifica e deduplica gli indirizzi email inseriti dall'amministratore.
	 *
	 * @param string $raw Elenco separato da spazi, virgole, punti e virgola o nuove righe.
	 * @return string[] Indirizzi email validi e univoci.
	 */
	private static function recipient_list( $raw ) {
		$emails = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out    = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Genera il corpo HTML dell'email con riepilogo e dettaglio degli ordini.
	 *
	 * @param WC_Order[] $orders   Ordini da mostrare.
	 * @param array      $settings Impostazioni del report.
	 * @param int        $from     Timestamp iniziale dell'intervallo.
	 * @param int        $now      Timestamp finale dell'intervallo.
	 * @param bool       $test     Mostra l'etichetta di email di test.
	 * @return string HTML dell'email.
	 */
	private static function build_email( $orders, $settings, $from, $now, $test = false ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from_text = wp_date( 'd/m/Y', $from ) . ' ore ' . wp_date( 'H:i', $from );
		$to_text   = wp_date( 'd/m/Y', $now ) . ' ore ' . wp_date( 'H:i', $now );

		// Il markup viene catturato nel buffer per essere passato come corpo a wp_mail().
		ob_start();
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( $site_name ); ?> – Report ordini</title>
		</head>
		<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#222;">
			<div style="max-width:100%;margin:0 auto;padding:0px;">
				<div style="background:#fff;padding:0px;border:0px;">
					<h3 style="margin:4px 0 0px;font-size:11px;text-align: center;"><?php echo esc_html( $site_name ); ?></h3>
					<h2 style="margin:0 0 8px;font-size:13px;text-align: center;">ORDINI RICEVUTI NELLE ULTIME 24 ORE</h2>
					<p style="margin:0 0 14px;color:#666;text-align: center;">
						Periodo:<br>dal <?php echo esc_html( $from_text ); ?><br>al <?php echo esc_html( $to_text ); ?>
						<?php if ( $test ) : ?>
							<br><strong>EMAIL DI TEST</strong>
						<?php endif; ?>
					</p>

					<?php // Mostra un messaggio esplicito quando l'invio di report vuoti è abilitato. ?>
					<?php if ( empty( $orders ) ) : ?>
						<div style="padding:16px;background:#f0f0f0;border:1px solid #ddd;">
							Nessun ordine ricevuto nelle ultime 24 ore.
						</div>
					<?php else : // Genera una scheda HTML separata per ogni ordine trovato. ?>
						<?php foreach ( $orders as $order ) : ?>
							<?php
							// controllo difensivo all'inizio del ciclo foreach della funzione build_email()
							// se $order non è un oggetto WooCommerce valido di tipo WC_Order (o una sua sottoclasse), il ciclo salta in sicurezza l'elemento senza interrompere l'esecuzione, evitando qualsiasi fatal error sul server
							if ( ! is_a( $order, 'WC_Order' ) ) {
								continue;
							}
								
							$order_number = $order->get_order_number();
							$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
							if ( '' === $customer_name ) {
								$customer_name = 'Cliente ospite';
							}
							$email = $order->get_billing_email();
							$total = $order->get_formatted_order_total();
							$order_subtotal = $order->get_subtotal();
							$order_total_discount = $order->get_total_discount();
							$order_tax = $order->get_total_tax();
							$order_shipping_total = $order->get_shipping_total();
							$order_total_value = $order_subtotal + $order_shipping_total + $order_tax - $order_total_discount;
							$formatted_total_value = wc_price( $order_total_value, array( 'currency' => $order->get_currency() ) );
							
							// recupera i codici coupon eventualmente applicati all'ordine corrente
							$order_coupons = $order->get_coupons();
							foreach ( $order_coupons as $coupon ) {
								$coupon_code = $coupon->get_code();
							}
							$coupon_found = ! empty( $coupon_code ) ? '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#00cdff;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">' . esc_html( $coupon_code ) . '</div>' : '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#cccccc;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">NO</div>';
							
							//$shipping = self::shipping_methods_text( $order );
							$shipping = ! empty( self::shipping_methods_text( $order ) ) ? '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#00cdff;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">' . esc_html( self::shipping_methods_text( $order ) ) . '</div>' : '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#cccccc;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">NESSUNO</div>';

							$payment = $order->get_payment_method_title();
							if ( '' === $payment ) {
								$payment = '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#cccccc;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">NESSUNO</div>';
							} else {
								$payment = '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#00cdff;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">' . $order->get_payment_method_title() . '</div>';
							}

							// recupera lo stato di lavorazione dell'ordine e decide il colore di sfondo del badge da visualizzare nell'email
							$order_status = wc_get_order_status_name($order->get_status());
							switch ( $order_status ) {
								case 'In attesa di pagamento':
									$status_badge_bgcolor = '#ffed7b';
									break;
								case 'In lavorazione':
									$status_badge_bgcolor = '#20c200';
									break;
								case 'In sospeso':
									$status_badge_bgcolor = '#0091f8';
									break;
								case 'Completato':
									$status_badge_bgcolor = '#797979';
									break;
								case 'Fallito':
									$status_badge_bgcolor = '#fc0000';
									break;
								case 'Annullato':
									$status_badge_bgcolor = '#888888';
									break;
								default:
									$status_badge_bgcolor = '#1dae00';
									break;
							}

							// recupera il valore del campo 'invoice_selected' generato da Checkout Field Editor for WooCommerce by Themehigh nei metadati dell'ordine corrente
							$invoice_selected = $order->get_meta('invoice_selected', true);
							$invoice_requested = ! empty($invoice_selected) ? ' <div style="width:70px;text-align: center;font-size: 0.8em;display: inline-block;margin-left: 3px;background-color:#ffb500;font-color:#fff;padding:2px;border-radius:6px;font-weight:bold;">➜ FATTURA</div>' : '';

							// recupera i dati della Carta Regalo Campo Base eventualmente utilizzata per pagare l'ordine - Pimwick PW Gift Card
							$gift_cards_found = array();

							foreach ( $order->get_items( 'pw_gift_card' ) as $order_item ) {
								$pw_card_number = $order_item->get_card_number();
								if ( ! empty( $pw_card_number ) ) {
									$gift_cards_found[] = $pw_card_number;
								}
							}

							$gift_cards_found = ! empty( $gift_cards_found ) ? '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#00cdff;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">' . esc_html( implode( ', ', array_unique( $gift_cards_found ) ) ) . '</div>' : '<div style="width:100%;text-align: center;font-size:0.8em;display:inline-block;vertical-align: middle;background-color:#cccccc;color:#fff;padding:2px;border-radius:6px;font-weight:bold;">NO</div>';
							?>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 24px;border:3px solid #2cc300;;">
								<tr>
									<td colspan="2" style="background:#188f00;color:#fff;padding:10px 0px 10px 0px;font-size:14px;font-weight:bold;text-align: center;">
										<?php if ( 'yes' === $settings['include_order_link'] && current_user_can( 'manage_woocommerce' ) ) : ?>
											<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" style="color:#fff;text-decoration:none;">
												ORDINE N. <?php echo esc_html( $order_number ); ?> <div style="width:100px;text-align: center;font-size: 0.8em;display: inline-block;margin-left: 3px;background-color:<?php echo esc_attr( $status_badge_bgcolor ); ?>;font-color:#fff;padding:2px;border-radius:6px;font-weight:bold;border: 1px solid #ffffff;">➜ <?php echo wc_get_order_status_name($order->get_status()) ?></div>
											</a>
										<?php else : ?>
											ORDINE N. <?php echo esc_html( $order_number ); ?> <div style="width:100px;text-align: center;font-size: 0.8em;display: inline-block;margin-left: 3px;background-color:<?php echo esc_attr( $status_badge_bgcolor ); ?>;font-color:#fff;padding:2px;border-radius:6px;font-weight:bold;border: 1px solid #ffffff;">➜ <?php echo wc_get_order_status_name($order->get_status()) ?></div>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td style="width:110px;padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">CLIENTE</td>
									<td style="padding:10px 5px;border-bottom:1px solid #eee;text-align:center;"><b><?php echo esc_html( ucwords( $customer_name ) ); ?></b><br><?php echo esc_html( $email ); ?></td>
								</tr>
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">TOT. VALORE</td>
									<td style="padding:10px 5px;border-bottom:1px solid #eee;text-align:center;"><?php echo wp_kses_post( $formatted_total_value ); ?></td>
								</tr>
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">TOT. PAGATO</td>
									<td style="padding:10px 5px;border-bottom:1px solid #eee;text-align:center;"><?php echo wp_kses_post( $total ) . $invoice_requested ?></td>
								</tr>
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">CARTE REGALO</td>
									<td style="padding:10px 12px 10px 10px;border-bottom:1px solid #eee;text-align:center;"><?php echo wp_kses_post( $gift_cards_found ); ?></td>
								</tr>
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">CODICI SCONTO</td>
									<td style="padding:10px 12px 10px 10px;border-bottom:1px solid #eee;text-align:center;"><?php echo wp_kses_post( $coupon_found ); ?></td>
								</tr>	
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">SPEDIZIONE</td>
									<td style="padding:10px 12px 10px 10px;border-bottom:1px solid #eee;text-align:center;"><?php echo wp_kses_post( $shipping ); ?></td>
								</tr>
								<tr>
									<td style="padding:0px 10px;border-bottom:1px solid #eee;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">PAGAMENTO</td>
									<td style="padding:10px 12px 10px 10px;border-bottom:1px solid #eee;text-align:center;"><?php echo $payment; ?></td>
								</tr>
								<tr>
									<td style="padding:10px 10px;vertical-align:top;font-weight:bold;font-size: .95em;background-color: #e1e1e1;text-align: right;">PRODOTTI</td>
									<td style="padding:10px 5px;"><?php echo self::items_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								</tr>
							</table>
						<?php endforeach; ?>
					<?php endif; ?>
				<div style="margin-top:10px;padding:10px;border-radius: 8px;font-size: .95em;background-color:#ccc;text-align: center;">
					Report riservato inviato da<br>
					<b>WooCommerce 24h Orders Email Report</b> <?php echo self::VERSION ?><br>
					di <b>Alex Vannini</b> - <b>DeepVoid</b> ➜ <a href="https://github.com/DeepVoid/wc-24h-orders-email-report">[GitHub]</a><br>
					<a href="<?php echo wp_specialchars_decode( get_bloginfo( 'url' ), ENT_QUOTES ) ?>" style="text-decoration:none; font-weight:bold;"><?php echo $site_name ?></a>
				</div>
			</div>
		</body>
		</html>
		<?php

		// Restituisce il markup acquisito e chiude il buffer di output.
		return ob_get_clean();
	}

	/**
	 * Ricava le etichette univoche dei metodi di spedizione di un ordine.
	 *
	 * @param WC_Order $order Ordine da analizzare.
	 * @return string Metodi separati da virgola o messaggio di assenza spedizione.
	 */
	private static function shipping_methods_text( $order ) {
		$methods = array();

		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$label = $shipping_item->get_method_title();
			$instance = $shipping_item->get_instance_id();

			if ( $instance ) {
				//$label .= ' (' . $instance . ')';
			}

			if ( $label ) {
				$methods[] = $label;
			}
		}

		if ( empty( $methods ) ) {
			return 'Nessuna spedizione';
		}

		return implode( ', ', array_unique( $methods ) );
	}

	/**
	 * Costruisce la lista HTML di prodotti, quantità, SKU e attributi delle variazioni.
	 *
	 * @param WC_Order $order Ordine da cui estrarre le righe prodotto.
	 * @return string Lista HTML pronta per il template dell'email.
	 */
	private static function items_html( $order ) {
		$html = '<ul style="margin:0;padding-left:18px;">';

		// Elabora solo le righe prodotto, escludendo spedizioni, tasse e fee.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$name    = $item->get_name();
			$qty     = $item->get_quantity();
			$sku     = $product ? $product->get_sku() : '';
			// Per le variazioni, mostra solo il nome del prodotto padre.
			if ( $product && $product->is_type( 'variation' ) ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				if ( $parent_product ) {
					$name = $parent_product->get_name();
				}
			}

			// Traduce gli attributi tecnici della variazione in etichette leggibili.
			$variation_text = '';
			if ( $product && $product->is_type( 'variation' ) ) {
				$attributes = $product->get_variation_attributes();
				$parts      = array();

				foreach ( $attributes as $attribute_key => $attribute_value ) {
					$taxonomy = str_replace( 'attribute_', '', $attribute_key );
					$label    = wc_attribute_label( $taxonomy, $product );
					$value    = $attribute_value;

					if ( taxonomy_exists( $taxonomy ) && $attribute_value ) {
						$term = get_term_by( 'slug', $attribute_value, $taxonomy );
						if ( $term && ! is_wp_error( $term ) ) {
							$value = $term->name;
						}
					}

					$parts[] = $label . ': ' . $value;
				}

				if ( ! empty( $parts ) ) {
					// divide ogni elemento dell'array su una nuova riga
					$variation_text = implode( '<br>', array_map( 'esc_html', $parts ) );
				}
			}

			$html .= '<li style="margin-bottom:6px;">';
			$html .= '<strong>' . esc_html( $name ) . '</strong>';
			$html .= '<br>' . wp_kses_post( $variation_text );
			if ($variation_text) {
				$html .= '<br>';
			}
			$html .= 'EAN: ' . esc_html( $sku ? $sku : 'N/D' );
			$html .= '<br>Quantità: ' . esc_html( $qty );
			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}
}

// Registra gli hook del plugin durante il caricamento di questo file.
CB_WC_24H_Orders_Email_Report::init();

// Collega attivazione e disattivazione alla gestione delle pianificazioni.
register_activation_hook( __FILE__, array( 'CB_WC_24H_Orders_Email_Report', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CB_WC_24H_Orders_Email_Report', 'deactivate' ) );

/**
 * Sistema di aggiornamento GitHub dinamico
 */

/**
 * L'updater è opzionale: se la libreria non è presente o non espone
 * PucFactory, il plugin continua a funzionare senza causare un fatal error.
 */
/**
 * Inizializza il controllo aggiornamenti dal repository GitHub, se la libreria è presente.
 * Gli errori vengono contenuti per non impedire il caricamento del plugin.
 */
function wc_export_inizializza_aggiornatore_github() {
    $puc_file = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

    if ( ! file_exists( $puc_file ) ) {
        return;
    }

    require_once $puc_file;

    $factory_class = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';

    if ( ! class_exists( $factory_class ) ) {
        return;
    }

    try {
			// Crea l'updater usando la factory della libreria opzionale.
			$my_update_checker = call_user_func(
            array( $factory_class, 'buildUpdateChecker' ),
            'https://github.com/DeepVoid/wc-24h-orders-email-report',
            __FILE__,
            'woocommerce-24h-orders-email-report'
        );

			if ( is_object( $my_update_checker ) ) {
				// Abilita gli asset ZIP delle release e forza il ramo principale, se supportato.
            if ( method_exists( $my_update_checker, 'getVcsApi' ) ) {
                $vcs_api = $my_update_checker->getVcsApi();

                if ( is_object( $vcs_api ) && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
                    $vcs_api->enableReleaseAssets( '/\.zip($|[?&#])/i' );
                }
            }

            if ( method_exists( $my_update_checker, 'setBranch' ) ) {
                $my_update_checker->setBranch( 'main' );
            }
        }
    } catch ( \Throwable $e ) {
        // L'aggiornamento automatico non deve mai impedire il caricamento del plugin
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                'Plugin Update Checker: ' . $e->getMessage()
            );
        }
    }
}

// Carica l'updater dopo che plugin e librerie dipendenti sono disponibili.
add_action( 'plugins_loaded', 'wc_export_inizializza_aggiornatore_github', 20 );
