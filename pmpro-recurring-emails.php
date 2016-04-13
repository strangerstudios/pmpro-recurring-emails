<?php
/*
Plugin Name: PMPro Recurring Payment Warning
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-recurring-emails/
Description: Sends out email message(s) X days before a recurring payment is made, to warn/remind members.
Version: .3
Author: Stranger Studios, Thomas Sjolshagen <thomas@eighty20results.com>
Author URI: http://www.strangerstudios.com
*/
/*
	We want to send a reminder to email to members N days before their membership renews.
	
	This plugin is meant to be used with recurring membership levels in PMPro. Normally
	an email is sent when the recurring payment goes through. We want to send an extra
	email X days before this.

    The email template, # of messages & days before sending can be configured
    w/the pmpro_upcoming_recurring_payment_reminder filter.
*/

//run our cron at the same time as the expiration warning emails
add_action("pmpro_cron_expiration_warnings", "pmpror_recurring_emails", 30);

/*
	New expiration email function.
	Set the $emails array to include the days you want to send warning emails.
	e.g. array(30,60,90) sends emails 30, 60, and 90 days before renewal.
*/
function pmpror_recurring_emails()
{
    global $wpdb;

    //get todays date for later calculations
    $today = date_i18n("Y-m-d", current_time("timestamp"));

    /*
        This filter will set set how many days before you want to send, and the template to use
    */
    $emails = apply_filters('pmpro_upcoming_recurring_payment_reminder', array(
        7 => 'membership_recurring'
    ));
    sort($emails, SORT_NUMERIC);

    //array to store ids of folks we sent emails to so we don't email them twice
    $sent_emails = array();

    foreach ($emails as $days => $template) {
        //look for memberships that are going to renew within a configurable amount of time (1 week by default), but we haven't emailed them yet about it.
        $sqlQuery = $wpdb->prepare("
				SELECT mo.user_id, MAX(mo.timestamp), mu.cycle_number, mu.cycle_period, um.meta_value
				FROM {$wpdb->pmpro_membership_orders} AS mo
				  LEFT JOIN {$wpdb->pmpro_memberships_users} AS mu ON mu.user_id = mo.user_id AND mu.membership_id = mo.membership_id AND mu.status = 'active'
				  LEFT JOIN {$wpdb->usermeta} AS um ON um.user_id = mu.user_id AND um.meta_key = %s
				WHERE mu.cycle_number > 0 AND (
				  (um.meta_value IS NULL AND mo.timestamp BETWEEN (
						CASE mu.cycle_period
						WHEN 'Day'
						  THEN DATE_SUB(%s, INTERVAL mu.cycle_number DAY)
						WHEN 'Week'
						  THEN DATE_SUB(%s, INTERVAL mu.cycle_number WEEK)
						WHEN 'Month'
						  THEN DATE_SUB(%s, INTERVAL mu.cycle_number MONTH)
						WHEN 'Year'
						  THEN DATE_SUB(%s, INTERVAL mu.cycle_number YEAR)
						END
				  	) AND (
						CASE mu.cycle_period
						WHEN 'Day'
						  THEN DATE_ADD(DATE_SUB(%s, INTERVAL mu.cycle_number DAY), INTERVAL %d DAY)
						WHEN 'Week'
						  THEN DATE_ADD(DATE_SUB(%s, INTERVAL mu.cycle_number WEEK), INTERVAL %d DAY)
						WHEN 'Month'
						  THEN DATE_ADD(DATE_SUB(%s, INTERVAL mu.cycle_number MONTH), INTERVAL %d DAY)
						WHEN 'Year'
						  THEN DATE_ADD(DATE_SUB(%s, INTERVAL mu.cycle_number YEAR), INTERVAL %d DAY)
						END
				  	)
				  ) OR (um.meta_value BETWEEN %s AND DATE_ADD(%s, INTERVAL %d DAY)))
				GROUP BY mo.user_id
				ORDER BY mo.timestamp DESC",
            "pmpro_recurring_notice_{$days}", // for meta_key to lookup
            "{$today} 23:59:59", // for Day w/date
            "{$today} 23:59:59", // for Week w/date
            "{$today} 23:59:59", // for Month w/date
            "{$today} 23:59:59", // for Year w/date
            "{$today} 23:59:59", // for Day w/date & interval
            $days,                 // for Day w/date & interval
            "{$today} 23:59:59", // for Week w/date & interval
            $days,                 // for Week w/date & interval
            "{$today} 23:59:59", // for Month w/date & interval
            $days,                 // for Month w/date & interval
            "{$today} 23:59:59", // for Year w/date & interval
            $days,                 // for Year w/date & interval
            "{$today} 23:59:59", // for um.metavalue
            "{$today} 23:59:59", // for um.metavalue date_add
            $days // for um.metavalue date + interval
        );

        /*		$sqlQuery = "SELECT mo.user_id, MAX(mo.timestamp), mu.cycle_number, mu.cycle_period, um.meta_value
                             FROM $wpdb->pmpro_membership_orders mo
                                LEFT JOIN $wpdb->pmpro_memberships_users mu ON mu.user_id = mo.user_id AND mu.membership_id = mo.membership_id AND mu.status = 'active'
                                LEFT JOIN $wpdb->usermeta um ON um.user_id = mu.user_id AND um.meta_key = 'pmpro_recurring_notice_" . $days . "'
                             WHERE mu.cycle_number > 0 AND
                                CASE mu.cycle_period
                                    WHEN 'Day' THEN DATE_SUB(DATE_ADD(mo.timestamp, INTERVAL mu.cycle_number DAY), INTERVAL " . $days . " DAY)
                                    WHEN 'Week' THEN DATE_SUB(DATE_ADD(mo.timestamp, INTERVAL mu.cycle_number WEEK), INTERVAL " . $days . " DAY)
                                    WHEN 'Month' THEN DATE_SUB(DATE_ADD(mo.timestamp, INTERVAL mu.cycle_number MONTH), INTERVAL " . $days . " DAY)
                                    WHEN 'Year' THEN DATE_SUB(DATE_ADD(mo.timestamp, INTERVAL mu.cycle_number YEAR), INTERVAL " . $days . " DAY)
                                    ELSE '9999-99-99'
                                 END
                                <= '" . $today . "' AND
                                (um.meta_value IS NULL OR DATE_ADD(um.meta_value, INTERVAL " . $days . " Day) <= '" . $today . "')
                             GROUP BY mo.user_id
                             ORDER BY mo.timestamp DESC";
                */

        if (WP_DEBUG) {
            error_log("SQL used to fetch user list: {$sqlQuery}");
        }

        $recurring_soon = $wpdb->get_results($sqlQuery);

        foreach ($recurring_soon as $e) {
            if (!in_array($e->user_id, $sent_emails)) {
                //send an email
                $pmproemail = new PMProEmail();
                $euser = get_userdata($e->user_id);

                //make sure we have the current membership level data
                $euser->membership_level = pmpro_getMembershipLevelForUser($euser->ID);

                //some standard fields
                $pmproemail->email = $euser->user_email;
                $pmproemail->subject = sprintf(__("Your membership at %s will renew soon", "pmpro"), get_option("blogname"));
                $pmproemail->template = $template;
                $pmproemail->data = array("subject" => $pmproemail->subject, "name" => $euser->display_name, "user_login" => $euser->user_login, "sitename" => get_option("blogname"), "membership_id" => $euser->membership_level->id, "membership_level_name" => $euser->membership_level->name, "siteemail" => pmpro_getOption("from_email"), "login_link" => wp_login_url(), "enddate" => date(get_option('date_format'), $euser->membership_level->enddate), "display_name" => $euser->display_name, "user_email" => $euser->user_email);

                //cancel link
                $pmproemail->data['cancel_link'] = wp_login_url(pmpro_url("cancel"));

                //get last order
                $lastorder = new MemberOrder();
                $lastorder->getLastMemberOrder($euser->ID);

                //figure out billing info
                if (!empty($lastorder->id)) {
                    //set renewal date
                    $pmproemail->data['renewaldate'] = date(get_option("date_format"), pmpro_next_payment($euser->ID));

                    //update billing info
                    $billinginfo = "";

                    //get card type and last4
                    if (!empty($lastorder->cardtype) && !empty($lastorder->accountnumber)) {
                        $billinginfo .= $lastorder->cardtype . ": " . $lastorder->accountnumber . "<br />";

                        if (!empty($lastorder->expirationmonth) && !empty($lastorder->expirationyear)) {
                            $billinginfo .= "Expires: " . $lastorder->expirationmonth . "/" . $lastorder->expirationyear . "<br />";

                            //check if expiring soon
                            $now = current_time("timestamp");
                            $expires = strtotime($lastorder->expirationyear . "-" . $lastorder->expirationmonth . "-01");
                            $daysleft = ($expires - $now) * 3600 * 24;
                            if ($daysleft < 60)
                                $billinginfo .= "Please make sure your billing information is up to date.";
                        }
                    } elseif (!empty($lastorder->payment_type)) {
                        $billinginfo .= "Payment Type: " . $lastorder->payment_type;
                    }

                    if (!empty($billinginfo))
                        $pmproemail->data['billinginfo'] = "<p>" . $billinginfo . "</p>";
                    else
                        $pmproemail->data['billinginfo'] = "";

                    //set body
                    $pmproemail->body = pmpro_loadTemplate($template, 'local', 'emails', 'html');

                    //send the email
                    $pmproemail->sendEmail();

                    //notify script
                    printf(__("Membership renewing email sent to %s.<br />", "pmpro"), $euser->user_email);

                    //remember so we don't send twice
                    $sent_emails[] = $euser->ID;
                } else {
                    //shouldn't get here, but if no order found, just continue
                    printf(__("Couldn't find the last order for %s. ", "pmpro"), $euser->user_email);
                }
            }

            //update user meta so we don't email them again
            foreach ($emails as $d) {
                if (intval($d) >= intval($days)) {
                    update_user_meta($e->user_id, "pmpro_recurring_notice_" . $d, date("Y-m-d 00:00:00", strtotime("+" . (intval($d) + 1) . " Days", strtotime($today))));
                }
            }
        }
    }
}

/**
 * Add message template to the Email templates add-on (if installed).
 *
 * @param $templates - The previously defined template aray
 * @return mixed - (possibly) updated template array
 *
 */
function pmprore_add_to_templates($templates) {

    $re_emails = apply_filters('pmpro_upcoming_recurring_payment_reminder', array(
        7 => 'membership_recurring'
    ));

    $site = get_option('blogname');

    foreach( $re_emails as $days => $templ ) {

        $templates["{$templ}"] = array(
            'subject' => __("Happening soon: The recurring payment for your membership at {$site}", "pmprore"),
            'description' => __("Membership level recurring payment message for {$site}", "pmprore"),
            'body' => file_get_contents( plugin_dir_url(__FILE__) . "/emails/{$templ}.html" ),
        );
    }

    return $templates;
}
add_filter('pmproet_templates', 'pmprore_add_to_templates', 10, 1);