# Sequences for Paid Memberships Pro by Eighty/20 Results

Create "Sequence" which are groups of posts/pages where the content is revealed to members over time. This is a replacement for the "drip feed content" module for Paid Memberships Pro (pmpro-series).

## Description

Create a drip feed "Sequence" which are groups of posts/pages/CPTs where the content is revealed to members over time.

This plugin currently requires Paid Memberships Pro and started life as a fork of the PMPro Series plugin by Stranger Studios, LLC. However, I needed a drip-content plugin that supported different delay type options, paginated lists of series posts, a way to let a user see an excerpt of the page/post, support a user defined custom post type, etc, etc, so I wound up with something completely different from PMPro Series. At this point, there's really nothing left of the original in this fork.

## Features

* Configuration UI for the drip feed sequence (meta box)
* The plugin is translatable (I18N support)
* Add supported post types to one or more sequences with one or more delay values from the post edit page
* [sequence_list] shortcode with attributes for paginated sequence list
* [e20r_available_on] shortcode to prevent visibility of content between [e20r_available_on] and [/e20r_available_on] until a specific date, or until a certain number of days after the users membership started.
* [sequence_alert] shortcode to display opt-in form for user to receive new sequence content alert emails.
* Excerpt widget for current users's most recently available post (display post title & excerpt in widget).
* Multiple delay values for the one post ID (i.e. repeating alerts & display in sequence lists)
* Configurable sort order when listing sequence content
* Show or hide future (upcoming) posts in a drip-feed sequence listing
    * ("show" means all post titles for the sequence will be visible to the user with a configurable view of date or day of membership for availability).
* Independent schedules (using WP-Cron) for sending new content alert emails to users.
    * Default global schedule for sending new content alert emails to users.
* Use either absolute dates or "days since membership started" as delay value for post(s) in a sequence.
* Hide "You are on day XXX of your membership" notice when displaying sequence page.
* Show "delay time" as "days since membership started" or "calendar date" to end-user in sequence post lists.
* Admin configurable setting to show "post available on" as a "day of membership" or a date.
* Uses templates for email alerts for new content
* Allows 'preview' of upcoming posts in the sequence (Lets the admin/editor send alerts for "today" while letting the user read/view upcoming content in the sequence - used in coaching programs, for instance).
* Filters to simplify integration with other membership frameworks/content restriction frameworks
* Supports automatic conversion of existing PMPro Series to sequences on init (filter based)
* See filter table for overview of all available filters/hooks

See ./email/README.txt for information on templates for the email alerts.

[*] = Add the following line to the functions.php file for your theme (or child theme) to support excerpts for WP pages as well:

    add_post_type_support( 'page', 'excerpt' );

## Installation

1. Upload the `e20r-sequences` directory to the `/wp-content/plugins/` directory of your site.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the Sequences menu in the WordPress dashboard to create a new sequence.
4. Add posts to sequence using the "Posts in this Sequences" meta box on the sequence editor page, or add it to the actual post/page via the metabox on the top right of that screen.

### Filters & Actions

| Filter | Description | Default value |
|--------------|:------------:|-------------:|
| e20r-sequence-managed-post-types | The post types the sequence plugin can mange. This is how to add CPTs, for instance | array( "post", "page" ) |
| e20r-sequence-allowed-post-statuses | The post has to have one of these statuses in order for the user to be granted access to the post (and it shows up in the post list) | array( 'publish', 'future', 'private' ) |
| e20r-sequence-found-closest-post | The post ID that is the closest to the day of membership for the currently logged in user | Result from E20R\Sequences\Sequence::get_closestPost() function |
| e20r-sequence-list-title | The HTML formatted title to use when displaying the list of sequences on the front-end | Output from E20R\Sequences\Sequence::setShortcodeTitle() - HTML formatted $title |
| e20r-sequence-closest-post-indicator-image | URL to the image to use to indicate which of the posts in the post list is the most recently available post for the current user | URL to __PLUGIN_DIR__/images/most-recent.png|
| e20r-sequence-list-pagination-code | The Pagination code for the Sequence List being rendered | Result from E20R\Sequences\Sequence::post_paging_nav() function |
| e20r-sequence-list-html | The HTML (as a table) for a paginated list of posts (E20R Sequence posts) | $html - the HTML that will render to show the paginated list (self-contained <div> |
| e20r-sequence-email-alert-template-path | Array of paths to the email alert template(s) | $path = E20R_SEQUENCE_PLUGIN_DIR . "/email/" - the file system path to the templates | 
| e20r-sequence-has-access-filter | A plug-in specific version of the pmpro_has_membership_access_filter filter | $hasAccess (bool), (WP_Post) $post, (WP_User) $user, (array) $levels |
| e20r-sequence-add-startdate-offset | Offset the apparent startdate for a user when calculating access rights for a specific sequence. | (int) $sequence_id |
| e20r-sequence-check-valid-date | Check whether the supplied string is a valid date. Return true if so. | Return value from E20R\Sequences\Sequence::isValidDate( $delay ) |
| e20r-sequence-can-add-post-status | Post statuses (i.e. the status of the post) that can be added to a sequence. It may (still) not display unless the 'e20r-sequence-allowed-post-statuses' filter also matches | array( 'publish', 'future', 'pending', 'private') |
| e20r-sequence-has-edit-privileges | Used to indicate whether the user is permitted to do something - like edit the sequence member list, settings, etc | true/false from E20R\Sequences\Sequence::userCanEdit() function |
| e20r-sequence-alert-message-excerpt-intro | Sets the text to use in place of the !!excerpt_intro!! placeholder in the "new content alert" message | PMProSequence->options->excerpt_intro |
| e20r-sequence-alert-message-title | The in-message post title ( replacing the !!ptitle!! placeholder) for the "new content alert" email message. | post_title for the post id being processed |
| e20r-sequence-cpt-labels | Override the Custom Post Type labels | array() of label definitions |
| e20r-sequence-cpt-slug | Set the Custom Post Type Slug | 'sequence' |
| e20r-sequence-cpt-archive-slug | Set the archive slug for the Custom Post Type | 'sequence' |
| e20r-sequence-not-found-msg | HTML error message for when a sequence isn't available/found | <p class="error" style="text-align: center;">The specified E20R Sequence was not found. <br/>Please report this error to the webmaster.</p> |
| e20r-seq-recentpost-widget-nopostfound | Set ID for the h3 element if a post isn't found | e20r-seq-widget-recentpost-nopostfound-title | 
| e20r-seq-widget-recentpost-nopostfound-body | Set the class for the error message if no post is found | empty |
| e20r-seq-recent-post-widget-title-id | Set the element ID for the widget title | e20r-seq-widget-recentpost-title |
| e20r-seq-widget-postlink-class | Set a class for the link to the post in the widget | empty |
| widget_title | Set the title for the Widget | $instance['title'] |
| e20r-sequence-widget-prefix | Set prefix for the widget | $instance['prefix'] |
| e20r-sequence-widget-default-post-title | Set the default title for the member post | $instance['default_post_title'] |
| e20r-sequence-widget-before-widget-title | Insert text before the widget title | $instance['before_title'] |
| e20r-sequence-widget-after-widget-title | Insert text after the widget title | $instance['after_title' |
| e20r-sequence-widget-seqid | Override the widget specified sequence ID (post ID for the Sequence CPT) | $instance['sequence_id'] |
| e20r-sequence-before-widget | Insert stuff before the widget gets rendered | $instance['before_widget'] |
| e20r-sequence-after-widget | Insert stuff after the widget gets rendered | $instance['after_widget'] |
| e20r-sequence-import-pmpro-series | Whether to automatically try to import PMPro Series CPT entries to this plugin. Accepts a number of different return values: The string 'all' or boolean true will import all defined series. An array of Post IDs, i.e. array( 2000, 4000 ), will treat the numbers as the post id for the Series. A single number (array or otherwise) will be treated as a Post ID to import.  | __return_false() |
| e20r-sequence-shortcode-text-unavailable| The text to display if the current user should not be permitted to see the content protected by the e20r_available_on shortcode | null |
| e20r-sequence-user-startdate | Returns the startdate (as seconds in UNIX epoch) for the specified user ID| strtotime(today) - midnight today |
| e20r-sequence-days-as-member | Returns the number of days the user Id has been a member of the site | 0 |
| e20r-sequence-membership-level-for-user | Returns the membership level the user ID has been assigned | false or an integer value representing a level id|
| e20r-sequence-has-membership-level | Decide whether or not the user is assigned the specified membership level(s) | false |
| e20r-sequence-membership-access | Whether the user ID has been granted access to the post ID specified | 3 element array: 0 => boolean for access, 1 => Numeric array of level Ids w/access, 2 => string array of level descr |
| e20r-sequence-default-sender-email | The email address to use as the default sequence notification sender | email address for admin user |
| e20r-sequence-default-sender-name | The name to use as the default sequence notification sender | Name of the admin user (display name) |
| e20r-sequence-site-name | A text/string containing the name you wish to use as the blog name for this site | get_option('blogname') |
| e20r-sequences-userstyle-url | The URL to a user defined .css file containing styles (will load after the default Sequences styles) | null |

## Roadmap (possible features)
1. Add support for admin selected definition of when "Day 1" of content drip starts (i.e. "Immediately", "at midnight the date following the membership start", etc)
2. Link has_access() to WP role() and membership plugin.
3. Define own startdate value rather than rely on PMPro.
4. Must rename plug-in to conform with Wordpress.org naming requirements - Done

## Known Issues
* If you started with this plugin on one of the V2.x versions, you *must* deactivate, and then activate this plugin to convert your sequences to the new metadata formats. (Won't fix)
* The conversion to the V3 metadata format disables the 'Send alerts' setting, so remember to re-enable it after you've re-enabled the plugin. (Won't fix)
* Format for "Posts in sequence" metabox only partially handles responsive screens well - Fix underway
* Limited library of translations available: English (US) and Norwegian. Contributions would be most welcome!

For more, see the [Issues section](https://github.com/eighty20results/e20r-sequences/issues) for the plugin on Github.com.

### DEBUG
 To enable logging for this plugin, define WP_DEBUG as 'true' in wp-config.php (`define('WP_DEBUG', true);`)
 A LOT of data/log info will be dumped into wp-content/uploads/e20r-sequences/debug_log.txt.

## About the email alert templates
 These templates are standard HTML files and need to have .html as their extension. By default, we have included two template files, one template is for the scenario where you want to send one alert per new post available that day. The other is a digest approach where we include a link of posts that were made available for the sequence and user that day.
  
  The alert templates support a few variables that it can substitute:
  
  `!!name!!` - The First Name for the user as defined in their profile
  `!!sitename!!` - A filtered variable containing the name of the site (default: is the option 'blogname' as defined in the Site settings).
  `!!post_link!!` - Either an <a href> tag (if configured to send one message per new post) or an unordered list of <a href> entries for each of the posts made available that day (depending on settings).
  `!!post_url!!` - The URL to the available post (only available when configured to send one alert per newly available post/page for the user).
  `!!today!!` - The date for when the user is supposed to get access to the specified post/page in the sequence (i.e. membership startdate + delay value)
  `!!excerpt!!` - The excerpt from the post/page containing the content we're sending a reminder about.
  `!!ptitle!!` - The title of the post/page we're sending the alert about.
  
### Adding new email alert templates
  The plugin will search the directory of the currently active theme for the `sequence-email-alerts` directory. If found, it will load any .html files and add them to the Sequence Settings under the "Template" settings drop-down (at the top of the list) for all new and defined sequences.
  
  These template files support all standard HTML elements.
  
  A template file _must_ end with the .html extension or it will not be located by the settings metabox.
  
  Whether or not the template file contains any of the replaceable variables is entirely optional.
  
  _Note_: As of v4.3, the admin can define any substitutions they would like in the template, and apply the 'e20r-sequence-email-substitution-fields' filter to do the "dirty work". The substitutions will be executed before the email gets sent to the user. Also new in 4.3 is the fact that all of the above listed substitution vairables have filters (See the sources for information on the filters).
  
  All field names need to be wrapped in dual "bang" characters ('!'). However, when specifying the substitution variable it's done without any '!!' characters. I.e. '!!post_url!! becomes `'post_url' => "http://example.com/my-post-name"` in the substitution array.  
  
## Shortcode attributes

### [sequence_links]

This shortcode can be placed on any page or post. It will load a paginated list of links to the available posts or 
pages that are managed by the specified sequence. This list will respect the "what-to-show" settings in the back-end 
definiton for the sequence.

The following attributes may be used, unless they have the "Required" keyword next to it. Then you _have_ to set it.

* id - The ID (page ID or Post ID) of the sequence to list links for. (Required)
* pagesize - The number of links to list per page in the paginated list. Default: 30
* title = Override the title for the list of links (i.e. what you've named the sequence in the back-end). Default: N/A
* button = Whether to display a button for the user to click in order to access the page/post. Default: 'false'                   
* highlight = Whether to highlight the most recent/current post or page in the list of links. Default: 'false'                  
* scrollbox = Whether to wrap the list in a scrollable <div> box. Default: 'false'                  

Example 1:
`[sequence_links id="4" pagesize="20" title="My Sequence Links" button="true" highlight="true" scrollbox="true"]`

### [sequence_alert]

This shortcode can be placed on any page or post and will load a checkbox allowing the logged-in user to opt in, or 
out of receiving email alerts about new content. 

The following attribute is required:

* sequence_id - The ID of the sequence (post ID) to associate this opt-in with.

Example 1:

`[sequence_alert sequence_id="4"]`


### [e20r_available_on]

This shortcode is designed to prevent visibility of the content between [e20r_available_on] and [/e20r_available_on] 
until the specified "when" attribute value has been exceeded by the currently logged in user. If the "when" value is 
specified as a number of days (i.e. purely numeric value like: 1, 100, 10, etc), users who are not logged in to your 
site will not see the content between the shortcode blocks at all. If the "when" value is specified in a valid date 
format, as defined by the PHP strtotime() function, any viewer of your site will be given access to the content between 
the shortcode blocks unless you're using some other means to prevent access.

NOTE: This shortcode does *NOT* require a sequence to exist in order to function.

The following attribute can be used with the shortcode:
* when - A valid date format, or the number of days since the start date for membership level of the currently logged in user

Example 1:

`[e20r_available_on when="01-01-2016"]
This content will be visible on January 1st, 2016. It does not matter whether the viewer is a member or not. They will see
this content on/after January 1st, 2016.
[/e20r_available_on]`

Example 2:

`[e20r_available_on when="10"]
This content will be visible 10 or more days after the start date of the current membership level for the logged in user.
If they are not members of your site, they will *not* see this content
[/e20r_available_on]`

## Frequently Asked Questions
TBD

### I found a bug in your plugin.

Please report it in the [issues section](https://github.com/eighty20results/e20r-sequences/issues) of GitHub and we'll fix it as soon as we can. Thanks for helping!
You can also email you support question(s) to support@eighty20result.zendesk.com

## Changelog

### 4.7

* ENHANCEMENT: Use Gutenberg editor for the Sequence Custom Post Type (when editing a sequence)
* BUG FIX: Didn't always load the associated files for the Select2 library version we're using
* BUG FIX: Update Select2 library to v4.0.5
* BUG FIX: Added 'Post/Page' to message returned when removing a post/page from the sequence
* BUG FIX: Add/Edit Posts metabox didn't size the post/page list correctly
* BUG FIX: JS error when updating/removing posts/pages from sequence in Sequence CPT editor
* BUG FIX: Accessibility update (color contrast) for Posts in Sequence metabox


### 4.6.14

* BUG FIX: Revert Gutenberg support in sequences (broken)
* BUG FIX: Updated Copyright notices

### 4.6.13

* BUG FIX: Adding basic support for Sequences in Gutenberg
* BUG FIX: Not escaping Subject properly
* BUG FIX: create_function() is deprecated. Using anonymous function instead (for now)

### 4.6.12

* BUG FIX: PHP Notice when post ID isn't found
* BUG FIX: Ensure we're not actively looking for post ID 0 (we know there's no post, so nothing to find)
* BUG FIX: PHP Warning in Sequence_Updates class

### 4.6.11

* BUG FIX: Didn't show Drip Feed Settings metabox for all post types it was configured for
* BUG FIX: PHP Notice when post ID isn't found
* ENHANCEMENT: Add support for using custom subject and links in notices
* ENHANCEMENT: Didn't apply the e20r-sequence-alert-message-post-title filter to the Email Subject

### 4.6.8

* BUG FIX: Logic for testing metadata version was invalid
* BUG FIX: Didn't save the Preview offset value correctly
* BUG FIX: Element ID collision for offset value vs checkbox for Preview Offset setting

### 4.6.7

* BUG FIX: Incorrectly assumed a new sequence had to be converted to v3 in v3+.
* BUG FIX: Would incorrectly request reactivation for metadata conversion
* BUG FIX: Didn't display the sequence preview offset value setting when configured
* ENHANCEMENT/FIX: Warning message for empty sequences on initial load


### 4.6.6

* BUG FIX: Formatting for no-access messages


### 4.6.5

* ENHANCEMENT/FIX: Only show info about when post can be accessed in certain situations

### 4.6.4

* BUG FIX: Cache timeout fixes
* BUG FIX: find_by_id() method didn't always return the correct post(s)
* ENHANCEMENT/FIX: Didn't use cached data when loading the page view
* ENHANCEMENT: Simplified Cache handling
* ENHANCEMENT: Reduced timeout for cache
* ENHANCEMENT: Use Cache class to manage sequence post/page cache
* ENHANCEMENT: WP Style updates/formatting of Sequence_Controller class
* ENHANCEMENT: Simplified & improved post cache (adding Cache / Cache_Object class)


### 4.6.3

* BUG FIX: PHP Warning in Post_Widget class
* BUG FIX: Restore access control filter
* BUG FIX: Restore access control filter for unprotected individual posts/pages that are in a sequence
* BUG FIX: Didn't provide link to checkout page for sequence protected posts in the 'required membership' section
* BUG FIX: Properly handle multiple sequences for a post/page in denied access text
* BUG FIX: PHP warning in membership access filter handler
* ENHANCEMENT: Can replace the 'members'/'membership(s)' text in Restricted content warning for Sequences with gettext filter magic

### 4.6.2

* BUG FIX: Too many columns in current post listing table for sequence

### 4.6.1

* BUG FIX: PHP Warnings from Sequence_Links class/shortcode

### 4.6.0
* BUG FIX: Showing HTML for header in sequence listing
* BUG FIX: Didn't consistently adhere to the shortcode attributes for the Sequence Links
* BUG FIX: Start date calculation failed for some users
* BUG FIX: Access check for posts would sometimes yield unexpected result
* ENHANCEMENT/FIX: Entry row formatting for post in sequence on front-end
* ENHANCEMENT: Add support for using featured image of post/page as the thumbnail in the sequence specific post listing(s)
* ENHANCEMENT: Add 'show featured image in post list' setting for sequence
* ENHANCEMENT: More clearly highlighted 'closest post' in sequence post listing if it's a future post
* ENHANCEMENT: Update class name & loading methods for Available_On class
* ENHANCEMENT: Update Class name for E20R_Mail class
* ENHANCEMENT: Renamed Cron class file to class.cron.php
* ENHANCEMENT: Updated license text
* ENHANCEMENT: Clean up unused namespace definitions in Sequence_Alert class
* ENHANCEMENT: Refactor Sequence_Alert class for simplified class loader
* ENHANCEMENT: Support simplified auto_loader function (rename class file for Sequence_Updates)
* ENHANCEMENT: Simplified namespaces for Sequence_Updates class
* ENHANCEMENT: Move PMPro Series import to own class
* ENHANCEMENT: Simplified namespaces for 4.4.0 upgrade function
* ENHANCEMENT: Simplified namespaces for 4.4.11 upgrade function
* ENHANCEMENT: Support simplified auto_loader function (rename class file for Sequence_Views)
* ENHANCEMENT: Simplified namespaces for Sequence_Views
* ENHANCEMENT: WordPress code style improvements in Sequence_Views
* ENHANCEMENT: Renamed class from Views to Sequence_Views
* ENHANCEMENT: Add show/hide future posts for Administrators setting
* ENHANCEMENT: Clean up namespaces for Controller/Sequence_Controller class
* ENHANCEMENT: Relocate license text & update year in Controller/Sequence_Controller class
* ENHANCEMENT: Rename Controller class to Sequence_Controller
* ENHANCEMENT: Rename class-controller.php to class.sequence-controller.php
* ENHANCEMENT: WordPress style update
* ENHANCEMENT: Simplify namespace for E20R_Mail class
* ENHANCEMENT: Update code to better align w/WordPress style requirements in E20R_Mail class
* ENHANCEMENT: Remove unused loader file in build script
* ENHANCEMENT: Consolidate plugin load functionality
* ENHANCEMENT: Clean up namespace & constants
* ENHANCEMENT/FIX: Make all text translatable for PostWidget
* ENHANCEMENT: Rename class file for new auto-loader method for PostWidget class
* ENHANCEMENT: Make PostWidget class more WordPress code style compliant
* ENHANCEMENT: Add license info in Available_On class
* ENHANCEMENT: Simplify namespace for Available_On class/shortcode
* ENHANCEMENT: Update code to better align w/WordPress style requirements in Available_On class
* ENHANCEMENT: Add warning banner syles for front-end
* ENHANCEMENT: Add ability to display warning banner on front-end
* ENHANCEMENT: Rename view_sequence_id_required() to view_sequence_error() in Sequence_Views class
* ENHANCEMENT: Add support for warning banner if sequence ID isn't specified in shortcode in Sequence_Views
* ENHANCEMENT: WordPress Code Style enhancements in Upcoming_Content class
* ENHANCEMENT: Better translation support in Upcoming_Content class
* ENHANCEMENT: Better singleton handling in Upcoming_Content class
* ENHANCEMENT: Simplify namespace for DBG class
* ENHANCEMENT: Simplify namespace for E20R_Error class
* ENHANCEMENT: Update code to better align w/WordPress style requirements
* ENHANCEMENT: Add license to class.e20r-error.php file
* ENHANCEMENT: Renamed E20RError to E20R_Error for new auto loader
* ENHANCEMENT: Simplify namespace for PostWidget class
* ENHANCEMENT: Update code to better align w/WordPress style requirements in PostWidget class
* ENHANCEMENT: Renamed PostWidget class to Post_Widget in support of new auto loader
* ENHANCEMENT: Renamed class.postwidget.php file to class.post-widget.php in support of new auto loader
* ENHANCEMENT: Clean up and include (temporarily) License handling
* ENHANCEMENT: Simplify namespace for Membership_Module class
* ENHANCEMENT: Rename membership_module class to Membership_Module
* ENHANCEMENT: Move E20R_Async_Request to E20R\Sequences\Async_Notices namespace
* ENHANCEMENT: Rename file in support of new autoloader
* ENHANCEMENT: Move E20R_Background_Process to E20R\Sequences\Async_Notices namespace
* ENHANCEMENT: Rename to class.e20r-background-process.php in support of new autoloader
* ENHANCEMENT: Simplify namespace for Send_Sequence_Notices class
* ENHANCEMENT: Updated PostWidget widget_registration to match new name (Post_Widget)
* REFACTOR: Various file clean-up
* REFACTOR: Using simple DBG::log() (namespace simplification for Cron class)
* REFACTOR: Renamed License handler class
* REFACTOR: Renamed file for Send_Sequence_Notices class
* REFACTOR: Rename file for and E20R_Utils class

