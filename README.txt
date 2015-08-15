=== PMPro Sequences ===
Contributors: strangerstudios, eighty20results
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.2 or later.
Tested up to: 4.2.2
Stable tag: 2.3

Create a drip feed "Sequence" which are groups of posts/pages/CPTs where the content is revealed to members over time. This is an extension of the "drip feed content" module for Paid Memberships Pro (pmpro-series).

== Description ==
This plugin currently requires Paid Memberships Pro and started life as a complete rip-off of the pmpro_series
 plugin from strangerstudios. I needed a drip-content plugin that supported different delay type options, paginated
 lists of series posts, a way to let a user see an excerpt of the page/post, support a user defined custom post type,
 etc.

Added a features that weren't included in pmpro_series, specifically the ability to:

* Configure the sort order for the series/sequence
* Show/hide upcoming series/sequence posts
* Show/hide "You are on day XXX of your membership"
* Show "delay time" as "days since membership started" or calendar date to end user.
* Admin defined schedule (using WP-Cron) for new content alert emails to users.
* User opt-in for receiving email alerts
* Templated email alerts for new content
* Pagination of sequence lists in sequence page
* [sequence_list] shortcode for paginated sequence list
* Widget containing summary (excerpt) of most recent post in a sequence [***] for the logged in user.
* Allows 'preview' of upcoming posts in the sequence (Not sure if this is really necessary to have...)
* A settings metabox to simplify configuration (rather than only use filters)
* Filters to let the admin specify the types of posts/pages to include in a sequence, etc.

See ./email/README.txt for information on templates for the email alerts.

[***] => Add the following line to your theme's functions.php file to support excerpts for WP pages as well:

     add_post_type_support( 'page', 'excerpt' );

== Installation ==

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
4. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

== TODO ==
1. Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)
2. Decide how and where to utilize the user notification reset

== Known Issues ==

DEBUG
 To enable logging for this plugin, set WP_DEBUG to 'true' in wp-config.php
 A fair bit (understatement) of data which will get dumped into debug/sequence_debug_log-[date].txt
 (located the under the plugin directory).

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues
Or you can email support@eighty20results.zendesk.com

== Changelog ==

== 2.4 ==

* Refactor for two classes & member functions.
* Make settings updates more uniform w/o breaking compatibility with back-end.
* Remove old function/event structure.
* Refactor settings metabox to improve UI experience.
* Add support for sending alerts as digests or as individual messages.
* Updated text
* Transition away from PHP4 constructors

= 2.3 =
* Enh: Adding Wordpress update functionality.
* Fix: Path to plugin debug log

= 2.2.4 =
* Fix: Didn't always send notifications when using date based delays.
* Enh: New notification email template example (VPT Reminder)

= 2.2.3 =
* Fix: Create default user notice settings
* Version number bump

= 2.2.1 =
* Enh: Clears list of notified posts for specific sequence ID (not all notices for the user id)
* Version number bump

= 2.2 =
* Fix: Complete load of select2 from CDN by removing local file(s).
* Set version number and updated Readme files

= 2.1.6 =
* Fix: Sequence would not be updated if user specified a delay value of 0 for a post/page.
* Fix: Paid Memberships Pro phpmailer action would sometimes trigger error for email messages not related to PMPro Sequence
* Fix: Adding new post to new (unsaved) sequence caused silent error on initial "Update Sequence" click.
* Fix: Properly initialize the user notice data structure.
* Fix: Remove all sequences that no longer exist while loading the Drip Sequence Settings metabox.
* Fix: Return error message asking user to report this to the admin (filtered) if a shortcode uses a sequence ID that no longer exists.
* Fix: Remove all sequences that no longer exist while loading the Drip Sequence Settings metabox.
* Fix: Metabox - handle cases where a post/page/CPT doesn't belong to a sequence yet.
* Fix: Variable init for widget instance
* Fix: Load front-side javascript for widget that didn't need it.
* Fix: Make sure we load the front-side javascript only if the sequence_links shortcode is used
* Fix: Load admin scripts when editing PMPro Sequence post types only.
* Fix: hasAccess() would incorrectly deny access in certain scenarios
* Fix: Remove warning message during load of admin scripts due to un-inited variable(s)
* Fix: Typo in variable
* Fix: Didn't always load the user javascript when needed
* Fix: Didn't always load stylesheets in backend
* Fix: Removed local select2.css file
* Fix: Get rid of unneeded whitespace in .css file
* Enh: Add 'pmpro-sequence-not-found-msg' filter for short-code error message.
* Enh: Infrastructure for setting/getting a default slug (future option / settings page

= 2.1.5 =
* Fix: Would sometimes fail to load default settings for new sequences
* Fix: Correctly manage global $post data while processing shortcode
* Fix: Returned incorrect data for empty post lists when calculating the most recent post for the member
* Fix: Typo in return value when finding most recent post for certain members
* Fix: Incorrect handling of post_type variable while saving settings
* Fix: Would let user activate plugin even if Paid Memberships Pro was not present on system
* Fix: noConflict() mode for pmpro-sequences.js
* Fix: Sequence would not be updated if user specified a delay value of 0 for a post/page
* Fix: Paid Memberships Pro phpmailer action would sometimes trigger error for email messages not related to PMPro Sequence
* Nit: Remove commented out code from pmpro-sequences-admin.js
* Nit: Remove inline php for disabled settings

= 2.1.4 =
* Fix: Calculating "most recent post" with only one post defined in the sequence would generate error message.
* Fix: Font size for settings metabox
* Fix: Would not consistently load admin specific JavaScript.
* Fix: Displays membership length in wp-admin.

= 2.1.3 =
* Fix: Renamed all of the widget filters

= 2.1.2 =
* Fix: Empty sequences would not be processed correctly.
* Fix: Error messages would occasionally cause PHP error
* Fix: Typo in filter for post types managed by the PMPro Sequence plugin (pmpro-sequence-managed-post-types)
* Enh: Moved select2() init to .js file
* Enh: Allow complete reset of user notifications.
* Enh: Load select2 functionality from CDN (performance & updatability).

= 2.1.1 =
* Feature: Enable WP_DEBUG to start logging copious amounts of debug info to a dedicated PMPro Sequence debug log (./debug/sequence_debug_log-<date>.txt)
* Feature: Added 'pmpro-sequence-allowed-post-statuses' filter to widget
* Feature: Added public getAllSequences() function (API)
* Fix: Widget would sometimes attempt to list a sequence member that wasn't visible to end users.
* Fix: Infinite loop in certain situations during configuration.
* Fix: URL paths for icons
* Fix: Typo in pmpro-sequence-cpt-labels fitler
* Enh: Add filter for sequence slug (pmpro-sequence-cpt-slug)
* Enh: Add filter for archive (pmpro-sequence-cpt-archive-slug)
* Refactor: import PMPro Series before registering cron hook in plugin activation.
* Removed: Not using PMPRO_SEQUENCE_DEBUG to enable debug logging to separate file.

= 2.1 - DEV =
* Organized source files a bit more
* Feature: UI for Selectable alert listings (in addition to selecting the template for the alert message, the admin will be able to set the alert as "one email per new piece of content" or "list of links to new content".
* Renamed most of the filters (see README.md for details)
* Enh: Debug uses levels DEBUG_SEQ_[CRITICAL|WARNING|INFO]. INFO is default level.
* Fix: Moved a number of formerly public functions to private.
* Fix: Renamed Widget class to seqRecentPostWidget()
* Fix: Renamed userCan() to userCanEdit()
* Fix: Debug causing the cron job to not complete.

= 2.0 =
* Complete refactor of plugin. Moved anything sequence related into the PMProSequence class and cleaned out pmpro-sequence.php file.

* Feature: Sequence metabox on post editor page (allows assignment of sequence & delay within post/page editor). Editor can add the post to any number of sequences they want.
* Feature: Save of post will also add/update sequence settings for the post.
* Feature: Import of PMPro Series posts on plugin activation (use filter 'pmpro_sequence_import_all_series' to enable)
* Feature: Lots of new filters, see readme.md/filters.txt for details.
* Feature: Let admin schedule if and when email alerts are supposed to be processed for a specific sequence.
* Feature: Let admin decide whether to show availability date for upcoming sequence posts as a date or "days since membership started"
* Feature: Timestamp (and allow very rudimentary "caching") for the private $posts variable (array of posts). Hopefully increase performance a little.

* Enh: Email alerts will only get sent if the post delay value is the same as the normalized "days since start of membership level" value for the user_id being processed.
* Enh: isValidDate now supports any valid PHP date format
* Enh: Workaround for cases where Wordpress may inject <br/> tags into metabox forms.
* Enh: Added recursive in_array() function ( in_array_r() )
* Enh: Added recursive in_object() function ( in_object_r() ) to process arrays of stdClass() objects looking for key/values.
* Enh: Simplified pmpro_sequence_hasAccess() function
* Enh: Added CSS for 'most recent post' widget.

* Fix: Cleaned up init of Sequence options, including setting empty variables to default if needed.
* Fix: Would sometimes add a 2nd opt-in check-box during sequence listing
* Fix: Send new content alert for the current & accessible post in the sequence, only - Old behavior would send a number of email alerts per user (one per "not yet alerted on" post)
* Fix: Only let user access posts that match the 'pmpro-sequence-allowed-post-statuses' filter result or have a post status of 'private', 'publish' and 'future'.
* Fix: Ensure current user has permission to edit the sequence. (Future: Specific capability added for users with Sequence edit rights)
* Fix: Would not always respond to click events in settings metabox.
* Fix: Further fixes to (hopefully) support I18N correctly.
* Fix: Always load posts from DB on init()
* Fix: Only reload posts on add/remove
* Fix: Reset notification settings for all users if post is removed
* Fix: Email alerts were occasionally "off by one" - i.e. would get sent out for a post/page that wasn't the "most current" one for the $user_id.
* Fix: Added i18n text domain to Plugin header comment.
* Fix: Plugin would assume the first 24 hour time period after the 'startdate' value in pmpro_memberships_users were day 0 (now considered "day 1").
* Fix: Removed 'pmpro_sequence' prefix from all functions
* Fix: Updated paths to match current plugin directory layout
* Fix: Seems that min-device-pixel-ratio is invalid CSS(?) - removed it
* Fix: Improved timezone support.
* Fix: Email alerts would not always get sent when cron-job fired.
* Fix: Post order sort wasn't correct if sequence was configured to use dates as delay values.
* Fix: Move loading of meta boxes to more appropriate actions - not 'init'
* Fix: Clean up duplication of sequence post->ID's in the post meta tag '_post_sequences'
* Fix: createTimestamp() used UTC

= 1.0 =
* Set version number
* Feature: Widget containing excerpt from most recently available post in a sequence (by user ID)

= .3 =
* Translation for Norwegian and English (US)
* Feature: Trigger sending of email alerts from the admin UI
* Support "preview" functionality for posts in sequence. (Is this really needed..?)

= .2 =
* Added support for templated and configurable new content alerts. Includes scheduling (cron) by sequence
* Reformatted Sequence Settings metabox.
* Added support for pre PHP v5.3 releases. (tentative - not been able to test)
* Optimized settings save functionality (one instance of the save functionality).
* Separated out Javascript & cleaned up AJAX handling for sequence handling, post addition/removal & settings. (@Askelon)
* Started adding support translations (I8N) based on work by Askelon (@Charlie Merland)
* Split admin & user Javascript functionality.
* Added support for all public & searchable CTPs plus Pages & Posts to the list of posts in a sequence (drip)
* Admin may now select to let the delay time (when a post in the sequence will be accessible to the user) as a 'Calendar date' or as 'days since membership started'.
** Only applies when the Delay Type is configured as "Days after sign-up".
* Added support for admin configurable format of !!today!! (date) placeholder in email templates
* Added a message to the front-end sequence page for when there are no released (visible) posts available to the user in that sequence.
* Bugfix: Incorrect save of options when using "Publish" save vs Sequence Settings save.
* More bug fixes and updates.

= .1.2 =
* Bug Fix: Incorrect page ID supplied when filtering sequence member pages

= .1.1 =
* Version bump for fixes added after the initial version (minor typo & namespace bugs)

= .1 =
* Initial version of the Sequence plugin including support Sequence specific display & delay type options.
* Renamed from "Series" to try and avoid namespace collisions and allow people to transition manually to this plugin if desirable.








