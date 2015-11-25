=== PMPro Sequences ===
Contributors: strangerstudios, eighty20results
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.2 or later.
Tested up to: 4.3.1
Stable tag: 3.0.4

Create a drip feed "Sequence" which are groups of posts/pages/CPTs where the content is revealed to members over time. This is an extension of the "drip feed content" module for Paid Memberships Pro (pmpro-series).

== Description ==
This plugin currently requires Paid Memberships Pro and started life as a complete rip-off of the pmpro_series
 plugin from strangerstudios. I needed a drip-content plugin that supported different delay type options, paginated
 lists of series posts, a way to let a user see an excerpt of the page/post, support a user defined custom post type,
 etc.

Added a features that weren't included in pmpro_series, specifically the ability to:

* Multiple delay values for the same post ID (repeating alerts & posts/pages)
* [sequence_list] shortcode for paginated sequence list
* Widget containing summary (excerpt) of most recent post in a sequence [***] for the logged in user.
* Configure the sort order for the sequence
* Show or hide upcoming posts in a ssequence from the end-user ("show" means all post titles for the sequence will be listed for the user with date/day of availability).
* Show or hide "You are on day XXX of your membership" notice on sequence page.
* Show "delay time" as "days since membership started" or "calendar date" to end-user.
* Let admin decide whether to show "post available on" as a "day of membership" or date (relative to users membership).
* Admin defined schedule (using WP-Cron) for new content alert emails to users.
* User opt-in for receiving email alerts (User can disable/re-enable as desired).
* Templated email alerts for new content
* Pagination of sequence lists in sequence page
* Allows 'preview' of upcoming posts in the sequence (Lets the admin/editor send alerts for "today" while letting the user read ahead if so desired - used in coaching programs, for instance).
* A settings metabox to simplify configuration (rather than only use filters)
* Filters to let the admin specify the types of posts/pages to include in a sequence, etc.
* Convert and existing PMPro Series to a sequence (using filter)

See ./email/README.txt for information on templates for the email alerts.

[***] => Add the following line to your theme's functions.php file to support excerpts for WP pages as well:

     add_post_type_support( 'page', 'excerpt' );

== Installation ==

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
4. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

== TODO ==
* Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)
* Decide how and where to utilize the user notification reset

== Known Issues ==
* If you started with this plugin on one of the V2.x versions, you *must* deactivate and then activate this plugin to convert your sequences to the new metadata formats. (Won't fix)
* The conversion to the V3 metadata format disables the 'Send alerts' setting, so remember to re-enable it after you've re-enabled the plugin. (Won't fix)
* Format for "Posts in sequence" metabox doesn't handle responsive screens well - Fix Pending

== DEBUG ==

 To enable logging for this plugin, set WP_DEBUG to 'true' in wp-config.php
 A LOT of data which will get dumped into debug/sequence_debug_log.txt
 (located the under the plugin directory).

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues
Or you can email support@eighty20results.zendesk.com

== Changelog ==

== 3.0.4 ==

* Conditional return triggered fatal error in certain situations
* Use absolute URL for fontawesome
* Respect theme settings for fonts/text in widgets
* Respect theme settings for fonts/text in other text
* Fix error if is_managed() is called while PHP is outputting data
* Stop forcing access check for posts that aren't managed by any sequences.
* Fix formatting problem
* Initial update of version number to 3.0.4
* Update template file for vpt_reminder.html

== 3.0.3 ==

* On the edit.php page, add a 'Clear alerts' button for a specific post/sequence/delay combination
* Allow admin to clear notification flags for a specific post/delay/sequence id from the posts edit page
* Make language tag consistent

== 3.0.2 ==

* Would sometimes trigger warning message while searching for a specific post ID
* Only grant blanket access to post in sequence if admin is logged in on dashboard and we're not in an ajax operation
* Comment out incomplete Google Analytics tracking support
* Add debug output for send_notice() to help troubleshoot.
* Make opt-in form full-width

== 3.0.1 ==

* Would sometimes issue warning in find_by_id()
* Updated to direct user to dashboard
* v3.0.1

== 3.0-beta-13 ==

* Would sometimes return all posts in the sequence while  deleting one post.
* Fix undefined variable warning in load_sequence_post()
* Didn't include sequence members (posts) in DRAFT state when displaying list of sequences in metabox(es)
* Can specify post_status values to include in load_sequence_post() (array() or string)
* Prefix any post in draft status with 'DRAFT' (or translated equivalent) in metabox list of posts for sequence
* Run wp_reset_query() before returning all sequences in get_all_sequences().

== 3.0-beta-12 ==

* Load Font Awesome fonts as part of script/style load.
* Update path to Font Awesome fonts (CDN)

== 3.0-beta-11 ==

* Update version number and change log
* find_by_id() would sometimes load unneeded posts (and not honor cache)
* When loading a specific post_id for the sequence, don't ignore drafts (May cause duplication in DB)
* Fix typo in debug output for remove_post()
* Log the specific post being removed from the post list in remove_post()
* Only overwrite belongs_to array if the array is empty first
* Include sequence ID when loading the sequence specific meta data for $post_id in rm_sequence_from_post() callback function
* Update translations
* Update text in email opt-in checkbox

== 3.0-beta-10 ==

* Add all_sequences() static function
* Add post_details() static function
* Update change log & version numbers
* Allow calling PMProSequence::sequences_for_post() to return array of sequence IDs for the post_id specified
* Add static function to fetch all sequence IDs that a post_id is associated with

== 3.0-beta-9 ==

* Didn't always display the delay input box in the post editor metabox.
* Make opt-in checkbox responsive
* Update change log & version numbers

== 3.0-beta-8 ==

* Didn't always set the optin_at timestamp correctly in the default user alert settings
* Removed redundant option management
* Don't show a checkmark if the user has opted-out of receiving alert notices/emails
* Fixed typo in send_notices variable for the opt-in callback
* Prevent user from being overwhelmed by old alert messages when opting back in
* Didn't always handle situations where the alert notice timestamp for a user was reset
* Use post->id and the normalized delay value for the notified flag in user alert settings
* Allowed saving of user alert settings when there was no sequence ID specified
* Force load (refresh cache) of sequence members on start of convert_alert_setting()
* Fix fix_user_alert_settings() so it correctly identified valid & invalid alert notices.
* Refactor load of metadata version matrix
* Add check of validity of post cache
* Use cached data to locate specific post ID in find_by_id (faster loop rather than DB lookup)
* Add debug for load_sequence_post() variables
* Refactor load_sequence_post()
* Improve search for delay values (correctly identify date or numeric format)
* Load options rather than posts & options in load_sequence_meta()
* Refactor get_sequences_for_post()
* Remove impossible option load in sort_posts_by_delay()
* Refactor function order in class
* Use normalized delay values in convert_alert_setting()
* Add function to fix user alert settings
* Add function ro remove old user alert settings
* Fix is_after_opt_in() to support new user alert setting format

== 3.0-beta-7 ==

* Wouldn't always honor the refresh value when loading the sequence
* Refactor conversion for user's new-post notice settings
* Clean up erroneous notification settings for user
* Didn't save the 'Allow email notification' setting

== 3.0-beta-6 ==

* Primarily convert to V3 as part of plugin activation or if the user attempts to load the sequence.
* Would sometimes get into a load/convert loop Flag conversion attempt as 'forced' if no posts are found with V3 format and the sequence is NOT previously converted.
* Add padding to opt-in checkbox
* Could loop indefinitely during conversion of user opt-in settings for certain users.
* Would sometimes hide the opt-in check-box
* Don't print settings to debug log.
* New and empty sequences would incorrectly be flagged as needing conversion.
* Updated translations (Norwegian & English/US)

== 3.0-beta-5 ==

* Fix error handling in add post to sequence operation
* Add class function to configure & time out error message in /wp-admin/
* Fix $_POST variables in add_entry()
* Clean up error & success functions for jQuery.ajax() call in add_entry()
* Handle error messages returned from back-end
* Correct warning for (future) Google Analytics ID variable in configuration
* Rename (make consistent) filter for the type of posts that can be managed by this plugin
* is_present() will loop through the $this->posts array looking for the post_id & delay value specified. Returns false if not found & the array key value if found.
* Ensure the error <div> is present in the sequence metabox
* Remove 'draft' as a valide default post status to list.
* Clean up variable names in $_POST for the add operation
* Return error message/warning to back-end if post_id/delay combination is present in system.
* Refactor add_post_callback()

== 3.0-beta-4 ==

* Update the TODO section in README.txt
* Clean up TODO items class.PMProSequence.php
* Would loop indefinitely if there were no sequence posts and the sequence was attempted viewed from the front-end.
* Allow users with admin privileges to have access to any sequence & posts.
* Didn't always save the opt-in settings for the user(s).
* Typo in opt-in setting.
* Force user id when looking for closest post.
* Didn't always allow administrators to see posts in sequence
* Correctly sanitize date values as delays
* Re-enable the wipe functionality when changing the type of sequence from day # based to date based (or vice versa)

== 3.0-beta-3 ==

* Renamed 'Add' button to 'New Sequence'
* Would sometimes add an extra sequence/delay input field when the 'Add' button was clicked in edit.php

== 3.0-beta-2 ==

* Track conversion to v3 metadata based on sequence ID in options table
* If option value isn't configured, double-check that the V3 metadata isn't there in is_converted()
* Reduce the number of error messages on back-end
* Wouldn't always reload posts when changing sequence ID to manage
* Load delay type for each sequence to front-end (JavaScript)
* Add test for old-style metadata use if a sequence is found to be empty.
* Convert if needed. Warn admin/editor/user if a sequence hasn't been converted yet.
* Didn't always save the sequence ID when a post was added to a new sequence in edit.php
* Removed redundant saving of sequence ID for a post_id during add_post()
* Load all instances of a sequence/delay combination in edit.php
* Refactor edit.php metabox rendering
* Avoid warning message when no posts are found in get_delay_for_post()
* Remove unused code paths
* Simplify addition of new sequence/delay values to a post in edit.php
* No longer calling back-end when adding new sequence ID to post/page in edit.php
* Simplify removal of sequence/delay pair from a post in edit.php
* Support removing one of multiple sequence entries from a post/page in edit.php

== 3.0-beta-1 ==

* Check whether V3 postmeta is the current format on admin_init.
* Skip sending notices for sequences that haven't been converted yet.
* Add error message if the meta data for the sequence members isn't in V3 format.
* Ensure all posts in the sequence gets loaded when an admin/editor is processing the sequence CPT.
* Correctly identify sequence members that haven't been converted to V3 format yet.
* Add consistent error check (and error message) for init() of sequences
* Fix issue where type of edit operation in sequence editor window wasn't working.
* Didn't always save the repeat post setting for the sequence
* Add conversion to V3 post meta_data to activate() function
* Didn't always set the checkbox value for the sequence settings
* Differentiate between cron processing and normal (admin) processing
* Didn't always correctly indicate the most current post for the requested user_id
* Simplify cache check for $this->posts content
* Avoid perma-loop while in post loop
* Would sometimes add posts the user_id didn't actually have access to yet
* Removed duplicate processing for delay values.
* Didn't correctly convert user alert (notice) settings to V3 format
* Fixes to help user notice processing handle new v3 formats (and simplify post processing)
* Consistently use post_id + post_delay values for alert keys
* Adds support for multiple delay values for a single post_ID within the same sequence (i.e. repeating posts without duplication of content).
* Add support for 'upcoming' specific post array
* Record metadata as individual post entries (simplifies search/load of sequence info)
* Renamed all active functions to 'WordPress friendly' naming (i.e. lower case and underscores)
* Started work to allow tracking using google analytics. (Possible future feature, ETA is TBD if at all)
* Simplify loading of sequence member post(s).
* Simplified post/sequence member lookup.
* Commented out duplicate or unnecessary code
* Removed some unneeded code.
* Updated path to login page in a template
* Support V3 functions & post management
* Use new user notification settings (per sequence rather than global)
* Use new debug logger function
* Renamed Debug logging function
* Use new user notification settings (per sequence rather than global)
* Support V3 functions & post management
* Would generate undefined variable error in certain situations
* Remove superfluous load of font-awesome css file
* Use FontAwesome for admin page icon(s)
* Fix getPostKey() function to support post_id/delay combinations (for repeating post IDs in sequence)
* Add debug output for get_users_of_sequence() function
* Fix convertNotification() function (works)

== 2.4.15 ==

* Set allowRepeatPosts default to false
* Add $delay to javascript entries for edit/remove post entries in sequence
* Add allowRepeatPosts setting to sequence settings metabox
* Fix typo in allowRepeatPosts setting
* Refactor getting the sequence users for a sequence to its own private function (get_users_of_sequence())
* Add convertNotifications() function to convert post notification settings for all users/sequences to new post_id & delay format
* Use get_users_of_sequence() in removeNotifiedFlagForPost()
* Add $delay value to add/remove callbacks for
* Make PMPro dependency error message translatable
* Make sure settings for hiding any future sequence posts get saved
* Add convertNotifications() to activation hook
* Fix edit/remove/add posts to sequence in back-end scripts
* Add short code - &#91;sequence_opt_in sequence=<sequence_post_id>&#93; - for the Alert/Notification email opt-in (user managed).
* Simplify has_membership_access_filter() (Thanks to Jessica Oros @ PMPro)

== 2.4.14 ==

* Removed CR+LF (\n) from sendEmail()

== 2.4.13 ==

* Added 'pmpro-sequence-add-startdate-offset' filter which will allow the admin to add an offset (pos/neg integer) to the
'current day' calculation. This modifies when the current user apparently started their access to the sequence. The filter
expects a numeric value to be returned.


== 2.4.12 ==

* Update docs for pmpro_has_membership_access_filter()
* Apply new 'pmpro-sequence-has-access-filter' filter to result from $this->hasAccess() in has_membership_access_filter() function
* Increase priority of has_membership_access_filter() function in pmpro_has_membership_access_filter.

== 2.4.10 ==

* Remove redundant footer-like text
* Remove \n for replaceable text

== 2.4.9 ==

* Fix 'Drip Feed Settings' metabox actions/events.
* We should _enable_ not _disable_ a disabled row.

== 2.4.8 ==

* Reload content of sequence list select in post/page metabox

== 2.4.7 ==

* Instantiate $class variable

== 2.4.6 ==

* Instantiate $class variable within bind_controls() function.
* Handle meta control display when adding/editing supported posts/pages.

== 2.4.5 ==

* Fix excerpt, post_link and post title (ptitle) handling for pmproemail class.
* Remove pmpro_after_phpmailer_init filter/handler.

== 2.4.4 ==

* Edit license text (copyright)

== 2.4.3 ==

* Updated translations

== 2.4.2 ==

* Init the update checker on plugin load
* Correct path to update check functionality

== 2.4.1 ==

* Fix settings text for previews
* Adjust new change log creation script for this plugin

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








