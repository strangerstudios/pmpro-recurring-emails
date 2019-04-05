<?php
/*
Plugin Name: Paid Memberships Pro - Recurring Emails Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-recurring-emails/
Description: Send email message(s) X days before a recurring payment is scheduled, to warn/remind members.
Version: .5.1
Author: Stranger Studios, Thomas Sjolshagen <thomas@eighty20results.com>
Author URI: http://www.strangerstudios.com
*/
/*
	We want to send a reminder to email to members N days before their membership renews.
	
	This plugin is meant to be used with recurring membership levels in PMPro. Normally
	an email is sent when the recurring payment goes through. We want to send an extra
	email N days before this.

    The email template, # of messages & days before sending can be configured
    w/the pmpro_upcoming_recurring_payment_reminder filter.
*/

//run our cron at the same time as the expiration warning emails
add_action( "pmpro_cron_expiration_warnings", "pmpror_recurring_emails", 30 );

/**
 * Manually trigger the process (test)
 */
function pmpror_init_test() {
	if ( ! empty( $_REQUEST['pmpror_test'] ) && current_user_can( 'manage_options' ) ) {

		// Do NOT send the email message(s)!
		add_filter( 'pmprorm_send_reminder_to_user', '__return_false' );

		// Process recurring email(s)
		pmpror_recurring_emails();

		// Reset send functionality
		remove_filter( 'pmprorm_send_reminder_to_user', '__return_false' );
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
 */
function pmpror_recurring_emails() {
	global $wpdb;

	//clean up errors in the memberships_users table that could cause problems
	if( function_exists( 'pmpro_cleanup_memberships_users_table' ) ) {
		pmpro_cleanup_memberships_users_table();
	}

	//get todays date for later calculations
	$today = date_i18n( "Y-m-d", current_time( "timestamp" ) );

	/**
	 *  Filter will set how many days before you want to send, and the template to use
	 *
	 * @filter  pmpro_upcoming_recurring_payment_reminder
	 *
	 * @param   array $reminders key = # of days before payment will be charged (7 => 'membership_recurring')
	 *                                  value = name of template to use w/o extension (membership_recurring.html)
	 */
	$emails = apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
		7 => 'membership_recurring'
	) );
	ksort( $emails, SORT_NUMERIC );

	//array to store ids of folks we sent emails to so we don't email them twice
	$sent_emails = array();

	foreach ( $emails as $days => $template ) {

		$recurring_soon = array();

		//look for memberships that are going to renew within a configurable amount of time (1 week by default), but we haven't emailed them yet about it.
		$sqlQuery = $wpdb->prepare( "      
				SELECT DISTINCT mo.user_id 
				FROM            wp_pmpro_membership_orders mo 
				LEFT JOIN       wp_pmpro_memberships_users mu 
				ON              mu.user_id = mo.user_id 
				AND             mu.membership_id = mo.membership_id 
				LEFT JOIN       wp_usermeta um 
				ON              um.user_id = mo.user_id 
				AND             um.meta_key = '%s' 
				WHERE           mo.timestamp = 
				                ( 
				                       SELECT Max(mo2.timestamp) 
				                       FROM   wp_pmpro_membership_orders mo2 
				                       WHERE  mo2.user_id = mo.user_id 
				                       AND    status = 'success') 
				AND             mo.status = 'success' 
				AND             mo.timestamp BETWEEN 
				                CASE mu.cycle_period 
				                                WHEN 'Day' THEN ('%s'   - INTERVAL mu.cycle_number day) 
				                                WHEN 'Week' THEN ('%s'  - INTERVAL mu.cycle_number week) 
				                                WHEN 'Month' THEN ('%s' - INTERVAL mu.cycle_number month) 
				                                WHEN 'Year' THEN ('%s'  - INTERVAL mu.cycle_number year) 
				                end 
				AND 
				                CASE mu.cycle_period 
				                                WHEN 'Day' THEN ('%s'   - INTERVAL mu.cycle_number day + INTERVAL %d day)
				                                WHEN 'Week' THEN ('%s'  - INTERVAL mu.cycle_number week + INTERVAL %d day)
				                                WHEN 'Month' THEN ('%s' - INTERVAL mu.cycle_number month + INTERVAL %d day)
				                                WHEN 'Year' THEN ('%s'  - INTERVAL mu.cycle_number year + INTERVAL %d day)
				                end 
				AND             ( 
				                                um.meta_value <= '2019-04-03' 
				                OR              um.meta_value IS NULL) 
				AND             ( 
				                                mu.enddate IS NULL 
				                OR              mu.enddate = '0000-00-00 00:00:00') 
				AND             mu.cycle_number > 0 
				AND             mu.cycle_period IS NOT NULL 
				AND             mu.status = 'active'",
			"pmpro_recurring_notice_{$days}", // for meta_key to lookup
			"{$today} 00:00:00", // for Day w/date
			"{$today} 00:00:00", // for Week w/date
			"{$today} 00:00:00", // for Month w/date
			"{$today} 00:00:00", // for Year w/date
			"{$today} 23:59:59", // for Day w/date & interval
			$days,                 // for Day w/date & interval
			"{$today} 23:59:59", // for Week w/date & interval
			$days,                 // for Week w/date & interval
			"{$today} 23:59:59", // for Month w/date & interval
			$days,                 // for Month w/date & interval
			"{$today} 23:59:59", // for Year w/date & interval
			$days,	            // for Year w/date & interval
			"{$today} 00:00:00"
		);

		if ( WP_DEBUG ) {
			error_log( "SQL used to fetch user list:" );
			error_log( $sqlQuery );
		}

		$recurring_soon = $wpdb->get_results( $sqlQuery );

		if ( is_wp_error( $recurring_soon ) ) {

			if ( WP_DEBUG ) {
				error_log( "Error while searching for users with upcoming recurring payments: " . $recurring_soon->print_error() );
			}

			return;
		}

		if ( WP_DEBUG ) {
			error_log( "Found {$wpdb->num_rows} records..." );
		}

		foreach ( $recurring_soon as $e ) {

			if ( ! in_array( $e->user_id, $sent_emails ) ) {

				if ( WP_DEBUG ) {
					error_log( "Preparing email to send for {$e->user_id}" );
				}

				//send an email
				$pmproemail = new PMProEmail();
				$euser      = get_userdata( $e->user_id );

				//make sure we have the current membership level data
				$euser->membership_level = pmpro_getMembershipLevelForUser( $euser->ID );

				//some standard fields
				$pmproemail->email    = $euser->user_email;
				$pmproemail->subject  = sprintf( __( "Your membership at %s will renew soon", "pmpro" ), get_option( "blogname" ) );
				$pmproemail->template = $template;
				$pmproemail->data     = array(
					"subject"               => $pmproemail->subject,
					"name"                  => $euser->display_name,
					"user_login"            => $euser->user_login,
					"sitename"              => get_option( "blogname" ),
					"membership_id"         => $euser->membership_level->id,
					"membership_level_name" => $euser->membership_level->name,
					"siteemail"             => pmpro_getOption( "from_email" ),
					"login_link"            => wp_login_url(),
					"enddate"               => date( get_option( 'date_format' ), $euser->membership_level->enddate ),
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

					//set body
					$pmproemail->body = pmpro_loadTemplate( $template, 'local', 'emails', 'html' );

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
						printf( __( "Membership renewing email sent to %s.<br />", "pmpro" ), $euser->user_email );

						//remember so we don't send twice
						$sent_emails[] = $euser->ID;
					} else {
						if ( WP_DEBUG ) {
							error_log( "PMProRE - What we may have sent: " . print_r( $pmproemail, true ) );
							$sent_emails[] = $euser->ID;
						}
					}

				} else {
					//shouldn't get here, but if no order found, just continue
					printf( __( "Couldn't find the last order for %s.", "pmpro" ), $euser->user_email );
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
					if ( WP_DEBUG ) {
						error_log( sprintf( "Would have updated metadata for %d: %s = %s ", $e->user_id, "pmpro_recurring_notice_{$d}", date( "Y-m-d 00:00:00", strtotime( "+" . ( intval( $d ) + 1 ) . " days", current_time( 'timestamp' ) ) ) ) );
					}
				}
			} // foreach sent emails

			if ( WP_DEBUG ) {
				error_log( "Sent emails: " . print_r( $sent_emails, true ) );
			}

		} // foreach (users to process)
	} // foreach (to-send email list)
}

/**
 * Add message template to the Email templates add-on (if installed).
 *
 * @param $templates - The previously defined template aray
 *
 * @return mixed - (possibly) updated template array
 *
 */
function pmprore_add_to_templates( $templates ) {

	$re_emails = apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
		7 => 'membership_recurring'
	) );

	$site = get_option( 'blogname' );

	foreach ( $re_emails as $days => $templ ) {

		$templates["{$templ}"] = array(
			'subject'     => __( "Happening soon: The recurring payment for your membership at {$site}", "pmprore" ),
			'description' => __( "Membership level recurring payment message for {$site}", "pmprore" ),
			'body'        => file_get_contents( plugin_dir_path( __FILE__ ) . "emails/{$templ}.html" ),
		);
	}

	return $templates;
}

add_filter( 'pmproet_templates', 'pmprore_add_to_templates', 10, 1 );

/**
 * Filter hook for the included upcoming payment warning message
 *
 * @param array $templates
 * @param string $page_name
 * @param string $type
 * @param string $where
 * @param string $ext
 *
 * @return array        -- Path to the plugin specific template file
 */
function pmprore_add_email_template( $templates, $page_name, $type = 'emails', $where = 'local', $ext = 'html' ) {

	$templates[] = plugin_dir_path( __FILE__ ) . "emails/membership_recurring.html";

	return $templates;
}

add_filter( 'pmpro_emails_custom_template_path', 'pmprore_add_email_template', 10, 5 );

/*
Function to add links to the plugin row meta
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
