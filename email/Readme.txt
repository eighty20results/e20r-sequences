= Summary for the email notice (alert) templates. =

All adjustable settings are configured in the Sequence Editor (Right column, meta box called "Sequence Settings").

You can also set the template file to use (default is the new_content.html file located in <plugin_home>/email/).

== To add a new notification template ==
The plugin will search the directory of the currently active theme for the `sequence-email-alerts` directory.
If found, it will load any .html files and add them to the Sequence Settings under the "Template" settings
drop-down (at the top of the list) for all new and defined sequences.

These template files support standard HTML elements.

== Mandatory Information ==

The following is always included in the message:

*To Name*: The WPUser->first_name value for the user ID being processed (In User Profile)
*To Email*: The WPuser->user_email value for the user ID being processed. (In User Profile)
*From Email*: The specified email address (settings) or the PMPro Setting (PMPro's default)
*From Name*: The specified name (settings) or the PMPro Setting (PMPro's default)
*Subject*: Subject_Prefix (From "Sequence Settings") and the Post/Page title, concatenated with a ':' separator.
            (default value: "New")

== Placeholders for templates ==

These templates will support using the any placeholders, as long as they use the format "!!<placeholder>!!" and the <placeholder> entry is included in the $data array (see filters to manipulate $data content):

    - !!name!! --> The first name of the user receiving the message
    - !!excerpt!! --> The page excerpt (can be enabled for pages as well. Google is your friend!)
    - !!excerpt_intro!! --> The introduction to the excerpt (Configure in "Sequence" editor ("Sequence Settings pane")
    - !!ptitle!! --> The title of the post we're emailing an alert about.
    - !!today!! --> Today's date (in format '[day], [month][st|rd|th], [year]).
