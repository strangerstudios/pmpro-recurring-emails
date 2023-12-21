<?php
/*
Plugin Name: Paid Memberships Pro - Recurring Emails Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-recurring-emails/
Description: Send email message(s) X days before a recurring payment is scheduled, to warn/remind members.
Version: 0.5.5
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
		
		echo '<p>';
		esc_html_e( 'Finished test. Check your PHP error log for details.', 'pmpro-recurring-emails' );
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
 */
function pmpror_recurring_emails() {
	global $wpdb;

	// Make sure we have the subscriptions table.
	if ( ! class_exists( 'PMPro_Subscription' ) ) {
		// Legacy support for PMPro < 3.0.
		pmpror_recurring_emails_legacy();
		return;
	}

	// Clean up errors in the memberships_users table that could cause problems.
	if ( function_exists( 'pmpro_cleanup_memberships_users_table' ) ) {
		pmpro_cleanup_memberships_users_table();
	}

	/**
	 * Filter will set how many days before you want to send, and the template to use
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

		// If testing, log the query.
		if ( WP_DEBUG ) {
			error_log( 'SQL used to fetch upcoming renewal payments:' );
			error_log( $sqlQuery );
		}

		// Run the query.
		$subscriptions_to_notify = $wpdb->get_results( $sqlQuery );

		// Make sure that the query was successful.
		if ( is_wp_error( $subscriptions_to_notify ) ) {
			if ( WP_DEBUG ) {
				error_log( 'Error fetching upcoming renewal payments: ' . $subscriptions_to_notify->print_error() );
			}
			return;
		}

		// If testing, log the number of records found.
		if ( WP_DEBUG ) {
			error_log( 'Found ' . count( $subscriptions_to_notify ) . ' upcoming renewal payments.' );
		}

		// Loop through each subscription and send reminder.
		foreach ( $subscriptions_to_notify as $subscription_to_notify ) {
			// If  testing, log that we are preparing to send a reminder for this subscription ID and user ID.
			if ( WP_DEBUG ) {
				error_log( 'Preparing to send reminder for subscription ID ' . $subscription_to_notify->id . ' and user ID ' . $subscription_to_notify->user_id );
			}

			// Send an email.
			$pmproemail = new PMProEmail();
			$user       = get_userdata( $subscription_to_notify->user_id );
			
			// Make sure we have a user.
			if ( empty( $user ) ) {
				// No user. Let's log an error, update the metadata for the subscription and continue.
				if ( WP_DEBUG ) {
					error_log( 'No user found for subscription ID ' . $subscription_to_notify->id . ' and user ID ' . $subscription_to_notify->user_id );
				}
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_next_payment_date', $subscription_to_notify->next_payment_date );
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_days', $days );
				continue;
			}

			// For sites that just upgraded to 3.0, we want to try to pull information from their old meta keys.
			$recurring_notice_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user->ID, 'pmpro_recurring_notice_%' ) );
			if ( ! empty( $recurring_notice_meta ) ) {
				// Each meta key is in the format pmpro_recurring_notice_{$days}, and each meta value is {date of last reminder sent} + {$days}.
				// This means that if the meta  value is greater than our sub's next payment date, we've already sent a reminder for the subscription's next payment date.
				// To translate this into our new format, we need to know:
				//     1. Whether we have already sent any reminders for the current subscription next payment date (ie old meta value > new next payment date).
				//     2. If so, the minimum $old_days value that fits this criteria.
				$lowest_days_sent = null;
				foreach ( $recurring_notice_meta as $meta ) {
					// If the old meta value is greater than the new next payment date, we've already sent a reminder for the subscription's next payment date.
					if ( $meta->meta_value > $subscription_to_notify->next_payment_date ) {
						// Get the $days value from the old meta key.
						$old_days = str_replace( 'pmpro_recurring_notice_', '', $meta->meta_key );
						if ( is_null( $lowest_days_sent ) || $old_days < $lowest_days_sent ) {
							$lowest_days_sent = $old_days;
						}
					}

					// Delete the old user meta.
					delete_user_meta( $user->ID, $meta->meta_key );
				}

				// If $lowest_days is less than or equal to $days, we've already sent this reminder. Set the subscription meta and continue.
				if ( null  !== $lowest_days_sent && $lowest_days_sent <= $days ) {
					update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_next_payment_date', $subscription_to_notify->next_payment_date );
					update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_days', $lowest_days_sent );

					// If testing, log that we are skipping this reminder.
					if ( WP_DEBUG ) {
						error_log( 'Skipping reminder for subscription ID ' . $subscription_to_notify->id . ' and user ID ' . $subscription_to_notify->user_id . ' because it has already been sent before 3.0 migration.' );
					}

					continue;
				}

				// Otherwise, continue sending reminder.
			}
			
			// Make sure we have the current membership level data if the user has the level.
			$membership_level = pmpro_getSpecificMembershipLevelForUser( $user->ID, $subscription_to_notify->membership_level_id );
			if ( empty( $membership_level) ) {
				// No membership level. Let's log an error, update the metadata for the subscription and continue.
				if ( WP_DEBUG ) {
					error_log( 'No membership level found for subscription ID ' . $subscription_to_notify->id . ' and user ID ' . $subscription_to_notify->user_id );
				}
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_next_payment_date', $subscription_to_notify->next_payment_date );
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_days', $days );
				continue;
			}

			// Get the cost text for this subscription.
			$billing_amount = $subscription_to_notify->billing_amount;
			$cycle_number   = $subscription_to_notify->cycle_number;
			$cycle_period   = $subscription_to_notify->cycle_period;
			if ( $cycle_number == 1 ) {
				$cost_text = sprintf( __( '%1$s per %2$s', 'pmpro-recurring-emails' ), pmpro_formatPrice( $billing_amount ), $cycle_period );
			} else {
				$cost_text = sprintf( __( '%1$s every %2$s %3$ss', 'pmpro-recurring-emails' ), pmpro_formatPrice( $billing_amount ), $cycle_number, $cycle_period );
			}

			//some standard fields
			$pmproemail->email    = $user->user_email;
			$pmproemail->subject  = sprintf( __( 'Your membership at %s will renew soon', 'pmpro-recurring-emails' ), get_option( 'blogname' ) );
			$pmproemail->template = $template;
			$pmproemail->data     = array(
				'subject'               => $pmproemail->subject,
				'name'                  => $user->display_name,
				'user_login'            => $user->user_login,
				'sitename'              => get_option( 'blogname' ),
				'membership_id'         => $membership_level->id,
				'membership_level_name' => $membership_level->name,
				'membership_cost'       => $cost_text,
				'billing_amount'        => pmpro_formatPrice( $billing_amount ),
				'renewaldate'          => date_i18n( get_option( 'date_format' ), strtotime( $subscription_to_notify->next_payment_date ) ),
				'siteemail'             => get_option( "pmpro_from_email" ),
				'login_link'            => wp_login_url(),
				'enddate'               => date( get_option( 'date_format' ), $membership_level->enddate ),
				'display_name'          => $user->display_name,
				'user_email'            => $user->user_email,
				'cancel_link'           => wp_login_url( pmpro_url( 'cancel' ) ),
				'billinginfo'           => '' // Deprecated.
			);

			//set body
			$pmproemail->body = pmpro_loadTemplate( $template, 'local', 'emails', 'html' );

			/**
			 * @filter      pmprorm_send_reminder_to_user
			 *
			 * @param       boolean $send_mail - Whether to send mail or not (true by default)
			 * @param       WP_User $user - User object being processed
			 * @param       MembershipOrder $lastorder - Deprecated. Now passing null.
			 */
			$send_emails = apply_filters( 'pmprorm_send_reminder_to_user', true, $user, null );
			if ( true === $send_emails ) {
				//send the email
				$pmproemail->sendEmail();

				// Update the subscription meta to prevent duplicate emails.
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_next_payment_date', $subscription_to_notify->next_payment_date );
				update_pmpro_subscription_meta( $subscription_to_notify->id, 'pmprorm_last_days', $days );

				// If testing, log that we sent the email.
				if ( WP_DEBUG ) {
					error_log( 'Sent reminder email to user ID ' . $subscription_to_notify->user_id );
				}
			} else {
				// If testing, log the email that we would have sent.
				if ( WP_DEBUG ) {
					error_log( 'Would have sent the following email to user ID ' . $subscription_to_notify->user_id . ': ' . print_r( $pmproemail, true ) );
				}
			}
		}
	}
}

/**
 * Legacy support for PMPro < 3.0.
 */
function pmpror_recurring_emails_legacy() {
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
				
				// Make sure we have a user.
				if ( empty( $euser ) ) {
					continue;
				}
				
				//make sure we have the current membership level data
				$membership_level = pmpro_getMembershipLevelForUser( $euser->ID );

				//some standard fields
				$pmproemail->email    = $euser->user_email;
				$pmproemail->subject  = sprintf( __( "Your membership at %s will renew soon", "pmpro" ), get_option( "blogname" ) );
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
					"enddate"               => date( get_option( 'date_format' ), $membership_level->enddate ),
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
						if ( WP_DEBUG ) {
							error_log( sprintf( __( "Membership renewing email sent to %s.<br />", "pmpro" ), $euser->user_email ) );
						}

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
					if ( WP_DEBUG ) {
						error_log( sprintf( __( "Couldn't find the last order for %s.", "pmpro" ), $euser->user_email ) );
					}
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
 * @param $templates - The previously defined template array
 *
 * @return mixed - (possibly) updated template array
 *
 */
function pmprore_add_to_templates( $templates ) {

	// PMPro Email Templates may be active without PMPro active.
	if ( ! function_exists( 'pmpro_loadTemplate' ) ) {
		return $templates;
	}

	$re_emails = apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
		7 => 'membership_recurring'
	) );

	$site = get_option( 'blogname' );

	foreach ( $re_emails as $days => $templ ) {
		$body = pmpro_loadTemplate( $templ, 'local', 'emails', 'html' );
		$templates["{$templ}"] = array(
			'subject'     => __( "Happening soon: The recurring payment for your membership at {$site}", "pmprore" ),
			'description' => __( "Membership level recurring payment message for {$site}", "pmprore" ),
			'body'        => $body,
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
