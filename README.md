#PMPro Sequences

Contributors: strangerstudios, eighty20results
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.2 or later.
Tested up to: 4.1
Stable tag: 2.1.2

Create "Sequence" which are groups of posts/pages where the content is revealed to members over time. This an extension of the "drip feed content" module for Paid Memberships Pro (pmpro-series).

##Description

This plugin currently requires Paid Memberships Pro and started life as a complete rip-off of the pmpro_series
 plugin from strangerstudios. I needed a drip-content plugin that supported different delay type options, paginated
 lists of series posts, a way to let a user see an excerpt of

Added a features that weren't included in PMPro Series, specifically the ability to:

* Configure the sort order for the series/sequence
* Show/hide upcoming series/sequence posts
* Show/hide "You are on day XXX of your membership"
* Show "delay time" as "days since membership started" or calendar date to end user.
* Admin defined schedule (using WP-Cron) for new content alert emails to users.
* User opt-in for receiving email alerts
* Templated email alerts for new content
* Pagination of sequence lists in sequence page
* [sequence_list] shortcode for paginated sequence list
* Widget containing summary (excerpt) of most recent post in a sequence [*]
* Allows 'preview' of upcoming posts in the sequence - I.e. logged in user can access it, but won't receive alert until it's officially released.

See ./email/README.txt for information on templates for the email alerts.

[*] = Add the following line to the functions.php file for your theme (or child theme) to support excerpts for WP pages as well:

    add_post_type_support( 'page', 'excerpt' );

##Installation

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
4. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

###Filters & Actions

| Filter | Description | Default value |
|--------------|:------------:|-------------:|
| pmpro_sequence_widget_seqid | Override the widget specified sequence ID (post ID for the Sequence CPT) | $instance['sequence_id'] |
| pmpro-sequence-managed-post-types | The post types the sequence plugin can mange. This is how to add CPTs, for instance | array( "post", "page" ) |
| pmpro-sequence-cpt-labels | Override the Custom Post Type labels | array() of label definitions |
| pmpro-sequence-cpt-slug | Set the Custom Post Type Slug | 'sequence' |
| pmpro-sequence-cpt-archive-slug | Set the archive slug for the Custom Post Type | 'sequence' |
| pmpro-sequence-can-add-post-status | Post statuses (i.e. the status of the post) that can be added to a sequence. It may (still) not display unless the 'pmpro-sequence-allowed-post-statuses' filter also matches | array( 'publish', 'draft', 'future', 'pending', 'private') |
| pmpro-sequence-found-closest-post | The post ID that is the closest to the day of membership for the currently logged in user | Result from PMProSequence::get_closestPost() function |
| pmpro-sequence-list-sequences-wpquery | Query (WP_Query) to fetch list of sequences from the database. | the WP_Query compatible array of query arguments |
| pmpro-sequence-list-title | The HTML formatted title to use when displaying the list of sequences on the front-end | Output from PMProSequence::setShortcodeTitle() - HTML formatted $title |
| pmpro-sequence-closest-post-indicator-image | URL to the image to use to indicate which of the posts in the post list is the most recently available post for the current user | URL to __PLUGIN_DIR__/images/most-recent.png|
| pmpro-sequence-list-pagination-code | The Pagination code for the Sequence List being rendered | Result from PMProSequence::post_paging_nav() function |
| pmpro-sequence-list-html | The HTML (as a table) for a paginated list of posts (PMPro Sequence posts) | $html - the HTML that will render to show the paginated list (self-contained <div> |
| pmpro-sequence-has-edit-privileges | Used to indicate whether the user is permitted to do something - like edit the sequence member list, settings, etc | true/false from PMProSequence::userCanEdit() function |
| pmpro-sequence-allowed-post-statuses | The post has to have one of these statuses in order for the user to be granted access to the post (and it shows up in the post list) | array( 'publish', 'future', 'private' ) |
| pmpro-sequence-check-valid-date | Check whether the supplied string is a valid date. Return true if so. | Return value from PMProSequence::isValidDate( $delay ) |
| pmpro-sequence-import-pmpro-series | Whether to automatically try to import PMPro Series CPT entries to this plugin. Accepts a number of different return values: The string 'all' or boolean true will import all defined series. An array of Post IDs, i.e. array( 2000, 4000 ), will treat the numbers as the post id for the Series. A single number (array or otherwise) will be treated as a Post ID to import.  | __return_false() |
| pmpro-sequence-alert-message-excerpt-intro | Sets the text to use in place of the !!excerpt_intro!! placeholder in the "new content alert" message | PMProSequence->options->excerpt_intro |
| pmpro-sequence-alert-message-title | The in-message post title ( replacing the !!ptitle!! placeholder) for the "new content alert" email message. | post_title for the post id being processed |

##TODO
1. Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)
2. Link has_access() to WP role() and membership plugin.
3. Define own startdate value rather than rely on PMPro.

##Known Issues

###DEBUG
 Linked to WP_DEBUG setting in wp-config.php.
 A fair bit (understatement) of data which will get dumped into debug/sequence_debug_log-[date].txt
 (located the under the plugin directory).

##Frequently Asked Questions
TBD

###I found a bug in the plugin.

Please report it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues

##Changelog

###2.1 - Dev
* Organized source files a bit more
* Feature: UI for Selectable alert listings (in addition to selecting the template for the alert message, the admin will be able to set the alert as "one email per new piece of content" or "list of links to new content".
* Renamed most of the filters (see README.md for details)
* Fix: Moved a number of formerly public functions to private.
* Fix: Renamed Widget class to seqRecentPostWidget()
* Fix: Renamed userCan() to userCanEdit()
* Enh: Linked dbgOut() to WP_DEBUG variable.

###2.0
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

##Old releases
###.1
* Initial version of the Sequence plugin including support Sequence specific display & delay type options.
* Renamed from "Series" to try and avoid namespace collisions and allow people to transition manually to this plugin if desirable.

###.1.1
* Version bump for fixes added after the initial version (minor typo & namespace bugs)

###.1.2 
* Fix: Incorrect page ID supplied when filtering sequence member pages

###.2 
* Added support for templates and configurable new content alerts. Includes scheduling (cron) by sequence.
* Reformat of the Sequence Settings meta box.
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
* Fix: Incorrect save of options when using "Publish" save vs Sequence Settings save.
* More bug fixes and updates.

###.3 
* Translation for Norwegian and English (US)
* Feature: Trigger sending of email alerts from the admin UI
* Support "preview" functionality for posts in sequence. (Is this really needed..?)

###1.0
* Set version number
* Feature: Widget containing excerpt from most recently available post in a sequence (by user ID)
