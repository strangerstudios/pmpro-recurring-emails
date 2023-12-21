<?php
/*
Plugin Name: Paid Memberships Pro - Recurring Emails Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-recurring-emails/
Description: Send email message(s) X days before a recurring payment is scheduled, to warn/remind members.
Version: 1.0
Author: Stranger Studios, Thomas Sjolshagen <thomas@eighty20results.com>
Author URI: http://www.strangerstudios.com
Text Domain: pmpro-recurring-emails
Domain Path: /languages
*/

define( 'PMPROR_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load the languages folder for translations.
 *
 * @since 1.0
 */
function pmpror_load_plugin_text_domain() {
	load_plugin_textdomain( 'pmpro-recurring-emails', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmpror_load_plugin_text_domain' );

/**
 * Manually trigger the process (test)
 *
 * @since 1.0
 */
function pmpror_init_test() {
	if ( ! empty( $_REQUEST['pmpror_test'] ) && current_user_can( 'manage_options' ) ) {

		// Do NOT send the email message(s)!
		add_filter( 'pmprorm_send_reminder_to_user', '__return_false' );

		// Process recurring email(s)
		pmpror_recurring_emails();

		// Reset send functionality
		remove_filter( 'pmprorm_send_reminder_to_user', '__return_false' );
		
		echo '<p>';
		esc_html_e( 'Finished test.', 'pmpro-recurring-emails' );
		echo '</p>';
		
		exit;
	}
}
add_action( 'init', 'pmpror_init_test' );

/**
 * Generate and send reminder(s) of upcoming payment event to members w/recurring membership subscription plans
 *
 * Use pmpro_upcoming_recurring_payment_reminder filter to set array of days (key) and
 * template name (value) (<template_name>.html) to use when sending reminder.
 *
 * Use pmprorm_send_reminder_to_user filter to disable sending notice to all/any individual user(s)
 *
 * @since 1.0
 */
function pmpror_recurring_emails() {
	global $wpdb;

	// Clean up errors in the memberships_users table that could cause problems.
	if ( function_exists( 'pmpro_cleanup_memberships_users_table' ) ) {
		pmpro_cleanup_memberships_users_table();
	}

	/**
	 * Filter will set how many days before you want to send, and the template to use.
	 *
	 * @filter  pmpro_upcoming_recurring_payment_reminder
	 *
	 * @param   array $reminders key = # of days before payment will be charged (7 => 'membership_recurring')
	 *                                  value = name of template to use w/o extension (membership_recurring.html)
	 */
	$emails = apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
		7 => 'membership_recurring',
	) );
	ksort( $emails, SORT_NUMERIC );

	// Make sure we have the subscriptions table.
	if ( ! class_exists( 'PMPro_Subscription' ) ) {
		// Legacy support for PMPro < 3.0.
		pmpror_recurring_emails_legacy( $emails );
		return;
	}

	// Loop through each reminder and send reminders, keeping track of the previous $days value.
	$previous_days = 0;
	foreach ( $emails as $days => $template ) {
		// Get all subscriptions that will renew between $previous_days and $days.
		// Also need to check that we haven't already sent this reminder by checking subscription meta.
		// Can't actually use the PMPro_Subscriptions class here, because it doesn't support searching by next payment date
		$sqlQuery = $wpdb->prepare(
			"SELECT subscription.*
			FROM {$wpdb->pmpro_subscriptions} subscription
			LEFT JOIN {$wpdb->pmpro_subscriptionmeta} last_next_payment_date 
				ON subscription.id = last_next_payment_date.pmpro_subscription_id
				AND last_next_payment_date.meta_key = 'pmprorm_last_next_payment_date'
			LEFT JOIN {$wpdb->pmpro_subscriptionmeta} last_days
				ON subscription.id = last_days.pmpro_subscription_id
				AND last_days.meta_key = 'pmprorm_last_days'
			WHERE subscription.status = 'active'
			AND subscription.next_payment_date >= %s
			AND subscription.next_payment_date < %s
			AND ( last_next_payment_date.meta_value IS NULL
				OR last_next_payment_date.meta_value != subscription.next_payment_date
				OR last_days.meta_value > %d
			)",
			date_i18n( 'Y-m-d', strtotime( "+{$previous_days} days", current_time( 'timestamp' ) ) ),
			date_i18n( 'Y-m-d', strtotime( "+{$days} days", current_time( 'timestamp' ) ) ),
			$days
		);
		pmpror_log( 'SQL used to fetch upcoming renewal payments:' );
		pmpror_log( $sqlQuery );

		// Run the query.
		$subscriptions_to_notify = $wpdb->get_results( $sqlQuery );

		// Make sure that the query was successful.
		if ( is_wp_error( $subscriptions_to_notify ) ) {
			pmpror_log( 'Error fetching upcoming renewal payments: ' . $subscriptions_to_notify->print_error() );
			continue;
		}

		pmpror_log( 'Found ' . count( $subscriptions_to_notify ) . ' upcoming renewal payments.' );

		// Loop through each subscription and send reminder.
		foreach ( $subscriptions_to_notify as $subscription_to_notify ) {
			// Get the subscription object.
			$subscription_obj = new PMPro_Subscription( $subscription_to_notify->id );
			pmpror_log( 'Preparing to send reminder for subscription ID ' . $subscription_obj->get_id() . ' and user ID ' . $subscription_obj->get_user_id() );

			// Get the user.
			$user = get_userdata( $subscription_obj->get_user_id() );
			if ( empty( $user ) ) {
				// No user. Let's log an error, update the metadata for the subscription and continue.
				pmpror_log( 'No user found for subscription ID ' . $subscription_obj->get_id() . ' and user ID ' . $subscription_obj->get_user_id() );
				update_pmpro_subscription_meta( $subscription_obj->get_id(), 'pmprorm_last_next_payment_date', $subscription_obj->get_next_payment_date( 'Y-m-d H:i:s', false ) );
				update_pmpro_subscription_meta( $subscription_obj->get_id(), 'pmprorm_last_days', $days );
				continue;
			}

			/**
			 * @filter      pmprorm_send_reminder_to_user
			 *
			 * @param       boolean $send_mail - Whether to send mail or not (true by default)
			 * @param       WP_User $user - User object being processed
			 * @param       MembershipOrder $lastorder - Deprecated. Now passing null.
			 */
			$send_emails = apply_filters( 'pmprorm_send_reminder_to_user', true, $user, null );
			if ( true === $send_emails ) {
				// Get the level info.
				$membership_level = pmpro_getLevel( $subscription_obj->get_membership_level_id() );

				// Send the email.
				$pmproemail = new PMProEmail();
				$pmproemail->email    = $user->user_email;
				$pmproemail->template = $template;
				$pmproemail->data     = array(
					'subject'               => $pmproemail->subject,
					'name'                  => $user->display_name,
					'user_login'            => $user->user_login,
					'sitename'              => get_option( 'blogname' ),
					'membership_id'         => $subscription_obj->get_membership_level_id(),
					'membership_level_name' => empty( $membership_level ) ? sprintf( __( '[Deleted level #%d]', 'pmpro-recurring-emails' ), $subscription_obj->get_membership_level_id() ) : $membership_level->name,
					'membership_cost'       => $subscription_obj->get_cost_text(),
					'billing_amount'        => pmpro_formatPrice( $subscription_obj->get_billing_amount() ),
					'renewaldate'           => date_i18n( get_option( 'date_format' ), $subscription_obj->get_next_payment_date() ),
					'siteemail'             => get_option( "pmpro_from_email" ),
					'login_link'            => wp_login_url(),
					'display_name'          => $user->display_name,
					'user_email'            => $user->user_email,
					'cancel_link'           => wp_login_url( pmpro_url( 'cancel' ) ),
					'billinginfo'           => '' // Deprecated.
				);
				$pmproemail->sendEmail();
				pmpror_log( 'Sent reminder email to user ID ' . $subscription_obj->get_user_id() );

				// Update the subscription meta to prevent duplicate emails.
				update_pmpro_subscription_meta( $subscription_obj->get_id(), 'pmprorm_last_next_payment_date', $subscription_obj->get_next_payment_date( 'Y-m-d H:i:s', false ) );
				update_pmpro_subscription_meta( $subscription_obj->get_id(), 'pmprorm_last_days', $days );
			} else {
				// If we're not actually sending, log the email that we would have sent.
				pmpror_log( sprintf( __( 'Membership renewing email was disabled for user ID %d.', "pmpro" ), $subscription_obj->get_user_id() ) );
			}
		}

		// Update the previous days value.
		$previous_days = $days;
	}
	pmpror_output_log();
}
add_action( 'pmpro_cron_expiration_warnings', 'pmpror_recurring_emails', 30 );

/**
 * Legacy support for PMPro < 3.0.
 *
 * @since 1.0
 *
 * @param array $emails Array of emails to be sent from pmpro_upcoming_recurring_payment_reminder filter.
 */
function pmpror_recurring_emails_legacy( $emails ) {
	global $wpdb;

	//get todays date for later calculations
	$today = date_i18n( "Y-m-d", current_time( "timestamp" ) );

	//array to store ids of folks we sent emails to so we don't email them twice
	$sent_emails = array();

	foreach ( $emails as $days => $template ) {

		$recurring_soon = array();

		//look for memberships that are going to renew within a configurable amount of time (1 week by default), but we haven't emailed them yet about it.
		$sqlQuery = $wpdb->prepare( "      
			SELECT DISTINCT mo.user_id 
			FROM $wpdb->pmpro_membership_orders mo
				LEFT JOIN $wpdb->pmpro_memberships_users mu			-- to check for recurring
					ON mu.user_id = mo.user_id
					AND mu.membership_id = mo.membership_id
				LEFT JOIN $wpdb->usermeta um						-- to check if we've already emailed
					ON um.user_id = mo.user_id
					AND um.meta_key = '%s'
			WHERE mo.timestamp = ( SELECT Max(mo2.timestamp)	-- make sure it's the latest order
									FROM   $wpdb->pmpro_membership_orders mo2
									WHERE  mo2.user_id = mo.user_id
									AND    status = 'success' )
				AND mo.status = 'success'						-- only successful orders
				AND mo.timestamp BETWEEN						-- recurring soon
					CASE mu.cycle_period
						WHEN 'Day' THEN ('%s' - INTERVAL mu.cycle_number DAY)
						WHEN 'Week' THEN ('%s' - INTERVAL mu.cycle_number WEEK)
						WHEN 'Month' THEN ('%s' - INTERVAL mu.cycle_number MONTH)
						WHEN 'Year' THEN ('%s' - INTERVAL mu.cycle_number YEAR)
					END
					AND
					CASE mu.cycle_period
						WHEN 'Day' THEN ('%s' - INTERVAL mu.cycle_number DAY + INTERVAL %d DAY)
						WHEN 'Week' THEN ('%s' - INTERVAL mu.cycle_number WEEK + INTERVAL %d DAY)
						WHEN 'Month' THEN ('%s' - INTERVAL mu.cycle_number MONTH + INTERVAL %d DAY)
						WHEN 'Year' THEN ('%s' - INTERVAL mu.cycle_number YEAR + INTERVAL %d DAY)
					END
				AND (um.meta_value <= '%s' OR um.meta_value IS NULL)	-- check if we've already emailed
				AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00')	-- no enddate
				AND mu.cycle_number > 0											-- recurring
				AND mu.cycle_period IS NOT NULL									-- recurring
				AND mu.status = 'active'										-- active subs only
				",
			"pmpro_recurring_notice_{$days}", // for meta_key to lookup			
			"{$today} 00:00:00",			  // for Day w/date
			"{$today} 00:00:00",			  // for Week w/date
			"{$today} 00:00:00",			  // for Month w/date
			"{$today} 00:00:00",			  // for Year w/date			
			"{$today} 23:59:59", 			  // for Day w/date & interval
			$days,                 			  // for Day w/date & interval
			"{$today} 23:59:59", 			  // for Week w/date & interval
			$days,                 			  // for Week w/date & interval
			"{$today} 23:59:59", 			  // for Month w/date & interval
			$days,                 			  // for Month w/date & interval
			"{$today} 23:59:59", 			  // for Year w/date & interval
			$days,            				  // for Year w/date & interval
			"{$today} 00:00:00"				  // for meta_value to lookup
		);
		pmpror_log( "SQL used to fetch user list:" );
		pmpror_log( $sqlQuery );

		$recurring_soon = $wpdb->get_results( $sqlQuery );

		if ( is_wp_error( $recurring_soon ) ) {
			pmpror_log( "Error while searching for users with upcoming recurring payments: " . $recurring_soon->print_error() );
			continue;
		}

		pmpror_log( "Found {$wpdb->num_rows} records..." );
	
		foreach ( $recurring_soon as $e ) {

			if ( ! in_array( $e->user_id, $sent_emails ) ) {

				pmpror_log( "Preparing email to send for {$e->user_id}" );

				//send an email
				$pmproemail = new PMProEmail();
				$euser      = get_userdata( $e->user_id );
				
				// Make sure we have a user.
				if ( empty( $euser ) ) {
					continue;
				}
				
				//make sure we have the current membership level data
				$membership_level = pmpro_getMembershipLevelForUser( $euser->ID );

				//some standard fields
				$pmproemail->email    = $euser->user_email;
				$pmproemail->template = $template;
				$pmproemail->data     = array(
					"subject"               => $pmproemail->subject,
					"name"                  => $euser->display_name,
					"user_login"            => $euser->user_login,
					"sitename"              => get_option( "blogname" ),
					"membership_id"         => $membership_level->id,
					"membership_level_name" => $membership_level->name,
					"membership_cost"       => pmpro_getLevelCost( $membership_level ),
					"billing_amount"        => pmpro_formatPrice( $membership_level->billing_amount ),
					"siteemail"             => get_option( "pmpro_from_email" ),
					"login_link"            => wp_login_url(),
					"display_name"          => $euser->display_name,
					"user_email"            => $euser->user_email,
					"cancel_link"           => wp_login_url( pmpro_url( "cancel" ) )
				);

				//get last order
				$lastorder = new MemberOrder();
				$lastorder->getLastMemberOrder( $euser->ID );

				//figure out billing info
				if ( ! empty( $lastorder->id ) ) {
					//set renewal date
					$pmproemail->data['renewaldate'] = date( get_option( "date_format" ), pmpro_next_payment( $euser->ID ) );

					//update billing info
					$billinginfo = "";

					//get card type and last4
					if ( ! empty( $lastorder->cardtype ) && ! empty( $lastorder->accountnumber ) ) {
						$billinginfo .= $lastorder->cardtype . ": " . $lastorder->accountnumber . "<br />";

						if ( ! empty( $lastorder->expirationmonth ) && ! empty( $lastorder->expirationyear ) ) {
							$billinginfo .= "Expires: " . $lastorder->expirationmonth . "/" . $lastorder->expirationyear . "<br />";

							//check if expiring soon
							$now      = current_time( "timestamp" );
							$expires  = strtotime( $lastorder->expirationyear . "-" . $lastorder->expirationmonth . "-01" );
							$daysleft = ( $expires - $now ) * DAY_IN_SECONDS;
							if ( $daysleft < 60 ) {
								$billinginfo .= "Please make sure your billing information is up to date.";
							}
						}
					} elseif ( ! empty( $lastorder->payment_type ) ) {
						$billinginfo .= "Payment Type: " . $lastorder->payment_type;
					}

					if ( ! empty( $billinginfo ) ) {
						$pmproemail->data['billinginfo'] = "<p>" . $billinginfo . "</p>";
					} else {
						$pmproemail->data['billinginfo'] = "";
					}

					/**
					 * @filter      pmprorm_send_reminder_to_user
					 *
					 * @param       boolean $send_mail - Whether to send mail or not (true by default)
					 * @param       WP_User $user - User object being processed
					 * @param       MembershipOrder $lastorder - order object for previous order saved
					 */
					$send_emails = apply_filters( 'pmprorm_send_reminder_to_user', true, $euser, $lastorder );

					if ( true === $send_emails ) {
						//send the email
						$pmproemail->sendEmail();

						//notify script
						pmpror_log( sprintf( __( "Membership renewing email sent to user ID %d.<br />", "pmpro-recurring-emails" ), $euser->ID ) );

						//remember so we don't send twice
						$sent_emails[] = $euser->ID;
					} else {
						pmpror_log( sprintf( __( "Membership renewing email was disabled for user ID %d.<br />", "pmpro-recurring-emails" ), $euser->ID ) );
						$sent_emails[] = $euser->ID;
					}

				} else {
					//shouldn't get here, but if no order found, just continue
					pmpror_log( sprintf( __( "Couldn't find the last order for user id %d.", "pmpro-recurring-emails" ), $euser->ID ) );
				}
			}

			//update user meta so we don't email them again
			foreach ( $emails as $d => $t ) {
				// update the meta value/key if we're looking at a reminder/template to be sent at or before the one being sent
				if ( true === $send_emails && intval( $d ) >= intval( $days ) ) {
					update_user_meta(
						$e->user_id,
						"pmpro_recurring_notice_{$d}",
						date( "Y-m-d 00:00:00", strtotime( "+" . ( intval( $d ) + 1 ) . " days", current_time( 'timestamp' ) ) )

					);
				} else {
					pmpror_log( sprintf( "Would have updated metadata for %d: %s = %s ", $e->user_id, "pmpro_recurring_notice_{$d}", date( "Y-m-d 00:00:00", strtotime( "+" . ( intval( $d ) + 1 ) . " days", current_time( 'timestamp' ) ) ) ) );
				}
			} // foreach sent emails

			pmpror_log( "Sent emails: " . print_r( $sent_emails, true ) );
		} // foreach (users to process)
	} // foreach (to-send email list)
	pmpror_output_log();
}

/**
 * Add the Recurring Email template to email templates.
 *
 * @param array $templates that can be edited.
 */
function pmpror_template_callback( $templates ) {
	ob_start();
	?>
	<p>Thank you for your membership to !!sitename!!.</p>

	<p>This is just a reminder that your !!membership_level_name!! membership will automatically renew on !!renewaldate!!.</p>

	<p>Account: !!display_name!! (!!user_email!!)</p>

	<p>If for some reason you do not want to renew your membership you can cancel by clicking here: !!cancel_link!!</p>
	<?php
	$templates['membership_recurring'] = array(
		'subject' => esc_html( sprintf( __( 'Your membership at %s will renew soon', 'pmpro-recurring-emails' ), get_option( 'blogname' ) ) ),
		'description' => esc_html__( 'Membership Recurring', 'pmpro-recurring-emails' ),
		'body' => ob_get_clean(),
		'help_text' => __( 'This email is sent when a subscription is approaching its renewal date. The additional placeholders !!renewaldate!! and !!billing_amount!! can be used to print the date that the subscription will renew and the renewal price.', 'pmpro-gift-levels' )
	);
	
	return $templates;
}
add_filter( 'pmproet_templates', 'pmpror_template_callback');

/**
 * Add a log entry to the PMPro Recurring Emails log.
 *
 * @since 1.0
 *
 * @param string $message The message to log.
 */
function pmpror_log( $message ) {
	global $pmpror_logstr;
	$pmpror_logstr .= "\t" . $message . "\n";
}

/**
 * Output the PMProEWEE log to an email or log file
 * depending on the value of the PMPROEEWE_DEBUG constant.
 *
 * @since 1.0
 */
function pmpror_output_log() {
	global $pmpror_logstr;

	$pmpror_logstr = "Logged On: " . date_i18n("m/d/Y H:i:s") . "\n" . $pmpror_logstr . "\n-------------\n";

	//log in file or email?
	if ( defined( 'PMPROR_DEBUG' ) && PMPROR_DEBUG === 'log' ) {
		// Output to log file.
		$logfile = apply_filters( 'pmpror_logfile', PMPROR_DIR . '/logs/pmpror.txt' );
		$loghandle = fopen( $logfile, "a+" );
		fwrite( $loghandle, $pmpror_logstr );
		fclose( $loghandle );
	} elseif ( defined( 'PMPROR_DEBUG' ) && false !== PMPROR_DEBUG ) {
		// Send via email.
		$log_email = strpos( PMPROR_DEBUG, '@' ) ? PMPROR_DEBUG : get_option( 'admin_email' );
		wp_mail( $log_email, get_option( 'blogname' ) . ' PMPro Recurring Emails Debug Log', nl2br( esc_html( $pmpror_logstr ) ) );
	}
}

/**
 * Function to add links to the plugin row meta.
 *
 * @since 1.0
 */
function pmpro_recurring_emails_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-recurring-emails.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'http://paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links     = array_merge( $links, $new_links );
	}

	return $links;
}

add_filter( 'plugin_row_meta', 'pmpro_recurring_emails_plugin_row_meta', 10, 2 );
