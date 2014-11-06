=== PMPro Sequences ===
Contributors: strangerstudios, eighty20results
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.2 or later.
Tested up to: 4.0
Stable tag: 1.3

Create "Sequence" which are groups of posts/pages where the content is revealed to members over time. This an extension of the "drip feed content" module for Paid Memberships Pro (pmpro-series).

== Description ==
This plugin currently requires Paid Memberships Pro and started life as a complete rip-off of the pmpro_series
 plugin from strangerstudios. I needed a drip-content plugin that supported different delay type options, paginated
 lists of series posts, a way to let a user see an excerpt of

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

See ./email/README.txt for information on templates for the email alerts.

[***] => Add the following line to your theme's functions.php file to support excerpts for WP pages as well:

     add_post_type_support( 'page', 'excerpt' );

== Installation ==

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
1. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

== TODO (?) ==
1. Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)

== Done ==
1. Add support for pre v5.3 releases (Currently uses DateTime->diff() - not available on pre 5.3 releases - to manage TZ specifics). This is "bad", I know... (DONE?)
3. Added support for setting a "preview unpublished posts" window (i.e. # of days/weeks in advance to let users see upcoming content) (DONE)

== Known Issues ==

DEBUG
 Currently disabled. To enable set PMPRO_SEQUENCE_DEBUG to 'true' in pmpro-sequences.php.
 A fair bit (understatement) of data which will get dumped into ./sequence_debug_log-[date].txt
 (located the ./debug/ directory).

== Frequently Asked Questions ==
TBD

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues

== Changelog ==
= .1 =
* Initial version of the Sequence plugin including support Sequence specific display & delay type options.
* Renamed from "Series" to try and avoid namespace collisions and allow people to transition manually to this plugin if desirable.

= .1.1 =
* Version bump for fixes added after the initial version (minor typo & namespace bugs)

= .1.2 =
* Bug Fix: Incorrect page ID supplied when filtering sequence member pages

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

= .3 =
* Translation for Norwegian and English (US)
* Feature: Trigger sending of email alerts from the admin UI
* Support "preview" functionality for posts in sequence. (Is this really needed..?)

= 1.0 =
* Set version number
* Feature: Widget containing excerpt from most recently available post in a sequence (by user ID)

= 2.0 =
* Major refactor of plugin. Moved anything sequence specific into the PMProSequence class.
* Feature: