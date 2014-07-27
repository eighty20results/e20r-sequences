=== PMPro Sequences ===
Contributors: strangerstudios, eighty20results
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.3 or later.
Tested up to: 3.9.1
Stable tag: .1.2

Create "Sequence" which are groups of posts/pages where content is revealed to members over time. This a clone of the "drip feed content" module for Paid Memberships Pro (pmpro-series).

== Description ==
This plugin currently requires Paid Memberships Pro and is a complete rip-off of the pmpro_series plugin from strangerstudios.

Added a few features that weren't included in pmpro_series, specifically the ability to:

* Configure the sort order for the series/sequence
* Show/hide upcoming series/sequence posts
* Show/hide "You are on day XXX of your membership"
* Configure "Days of delay since start of membership" or specific calendar date as when to make content available

== Installation ==

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
1. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

== TODO ==
1. Fixed(?): Add support for pre v5.3 releases (Currently uses DateTime->diff() - not available on pre 5.3 releases - to manage TZ specifics). This is "bad", I know...
2. Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)
3. Add support for setting a "preview unpublished posts" window (i.e. # of days/weeks in advance to let users see upcoming content)
4. Add support for setting a "remove posts from list after" window (i.e. # of days/weeks after it went public that it gets removed from the list).
    Should we then remove access to the post - for the member - after this windows has expired?

== Known Issues ==

DEBUG is available
   Currently disabled, to enable set PMPRO_SEQUENCE_DEBUG to 'true' in pmpro-sequences.php.
   A fair bit of data will get dumped into ./sequence_debug_log.txt (located in either website root and/or wp-admin/).

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues

== Changelog ==
= .1 =
* Initial version of the Sequence plugin including support Sequence specific display & delay type options.
* Renamed from "Series" to try and avoid namespace collisions and allow people to transition manually to this plugin if desirable.

= .1.1 =
* Version bump to signify fixes added after the initial version (minor typo & namespace bugs)

= .1.2 =
* Bug Fix: Incorrect page ID supplied when filtering sequence member pages

= .2 =
* Added support for templated and configurable new content alerts. Includes scheduling by sequence.
* Reformatted Sequence Settings metabox.
* Added support for pre PHP v5.3 releases. (tentative - not been able to test)
* Bugfix: Incorrect save of options when using "Publish" save vs Sequence Settings save.
* Multiple bug fixes and updates.
* Optimized settings save functionality (one instance of the save functionality).
* Separated out Javascript & cleaned up AJAX handling for sequence handling, post addition/removal & settings. (Askelon)
* Started work to support translations (I8N) based on work by Askelon (Charlie Merland)
* Split admin & user Javascript functionality.