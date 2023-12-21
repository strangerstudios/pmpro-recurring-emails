=== Paid Memberships Pro - Recurring Emails Add On ===
Contributors: strangerstudios
Tags: pmpro, paid memberships pro, files, uploads, downloads, secure, protect, lock
Requires at least: 5.2
Tested up to: 6.4
Stable tag: 1.0

Sends out an email X days before a recurring payment is made to remind members.

== Description ==

Sends out an email X days before a recurring payment is made to remind members.

== Installation ==

1. Upload the `pmpro-recurring-emails` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-recurring-emails/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==
= 1.0 - 2023-12-22 =
* ENHANCEMENT: For sites running PMPro 3.0+, this plugin now uses the Subscriptions table to more accurately determine when to send emails and to add compatibility with Multiple Memberships Per User. #28 (@dparker1005)
* ENHANCEMENT: Added a new constant `PMPROR_DEBUG` to allow setting an email address to send log data to or to have the log data printed to a file. #34 (@dparker1005)
* ENHANCEMENT: Added localization support. #35 (@dparker1005)
* BUG FIX: Fixed issue where customized email template data may not be sent. #36 (@andrewlimaza, @dparker1005)
* BUG FIX: Fixed issue where the `!!billing_amount!!` email template variable would sometimes incorrectly return $0 instead of the actual billing amount. #29 (@MaximilianoRicoTabo)
* REFACTOR: Now using `get_option()` instead of `pmpro_getOption()`. #31 (@dwanjuki)

= 0.5.5 - 2022-11-29 =
* ENHANCEMENT: Added !!billing_amount!! and !!membership_cost!! as variables for this email.
* BUG FIX: Now using sprintf to error log instead of printf. This fixes an issue in certain PHP versions that was causing fatal errors resulting in multiple emails going out to the same users.

= .5.4 =
* BUG FIX: The code to disable emails from being sent while testing with the /?pmpror_test=1 URL was commented out in the last release by accident. Now disabling emails from being sent during testing again.
* ENHANCEMENT: Added a message to check the PHP error log after running a test.

= .5.3 =
* BUG FIX: Fixed fatal error when PMPro Email Templates is active but PMPro isn't.
* BUG FIX: Fixed issue with DB prefixes other than wp_. (Thanks, Kishan Gajera)

= .5.2 = 
* BUG FIX: Fixed SQL query for finding users with recurring payments.
* BUG FIX: Finding correct email templates in /emails directory.
* BUG FIX: Removed warnings and notices.

= .5.1 =
* BUG FIX: Didn't always exclude recent notices.
* BUG FIX/ENHANCEMENT: If running a current version of PMPro, will use the pmpro_cleanup_memberships_users_table() function before finding expiring emails to avoid certain issues caused by errors in the memberships_users table.

= .5 =
* FIX: Too restrictive when looking for recurring memberships to warn of upcoming payments for
* FIX: Make sure the test mode doesn't actually send any emails (record to error_log())

= .4 =
* FIX: Didn't always include the membership_recurring.html template
* ENH: Documentation for filters

= .3 =
* FIX: Would sometimes send reminder to all users, regardless of time until next payment.
* FIX: Didn't always select all the expected users for notification
* FIX: Set the start times for the time intervals correctly (midnight to midnight)
* ENH: Load the content of the plugin specific template file
* ENH: Add support for inclusion in the Email Templates add-on
* ENH: Added error logging & error checking
* ENH: WP Style
* ENH: Add filter for recurring payment settings
* ENH: Adding pmprorm_send_reminder_to_user filter
* REF: Renamed template directory for pmpro_getTemplate() function to load template(s)

= .2.1 =
* Uncommented line that actually sends the email.

= .2 =
* Fixed user meta keeping track to prevent duplicate emails.

= .1 =
* Initial release.
