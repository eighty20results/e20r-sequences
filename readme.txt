=== PMPro Sequences ===
Contributors: strangerstudios
Tags: sequence, drip feed, serial, delayed, limited, memberships
Requires at least: 3.4
Requires PHP 5.3 or later.
Tested up to: 3.9.1
Stable tag: .1

Create "Series" which are groups of posts/pages where content is revealed to members over time. This is the "drip feed content" module for Paid Memberships Pro.

== Description ==
This plugin currently requires Paid Memberships Pro. 

== Installation ==

1. Upload the `pmpro-sequences` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
1. Add posts to sequence using the "Posts in this Sequences" meta box under the post content.

== TODO ==
1. Add support for PHP 5.2 (Using DateTime->diff() - not available on pre 5.3 releases - to manage TZ specifics). This is "bad", I know...
2. Consider adding support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/eighty20results/pmpro-sequence/issues

== Changelog ==
= .1 =
* Initial version of the Sequence plugin including support Sequence specific display & delay type options.
* Renamed from "Series" to try and avoid namespace collisions and allow people to transition manually to this plugin if desirable.

