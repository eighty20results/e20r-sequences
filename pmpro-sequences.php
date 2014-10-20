<?php
/*
Plugin Name: PMPro Sequence
Plugin URI: http://www.eighty20results.com/pmpro-sequence/
Description: Offer serialized (drip feed) content to your PMPro members. Derived from the PMPro Series plugin by Stranger Studios.
Version: 1.4
Text Domain: pmprosequence
Author: Thomas Sjolshagen
Author Email: thomas@eighty20results.com
Author URI: http://www.eighty20results.com
License:

	Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)
	Copyright 2013 Stranger Studios (jason@strangerstudios.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

/* Version number */
define('PMPRO_SEQUENCE_VERSION', '1.4');

/* Enable / Disable DEBUG logging to separate file */
define('PMPRO_SEQUENCE_DEBUG', true);

/* Set the max number of email alerts to send in one go to one user */
define('PMPRO_SEQUENCE_MAX_EMAILS', 3);

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('PMPRO_SEQ_REQUIRED_PHP_VERSION', '5.2.2');

/* Set the path to the PMPRO Sequence plugin */
define('PMPRO_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));

define('PMPRO_SEQ_AS_DAYNO', 1);
define('PMPRO_SEQ_AS_DATE', 2);


/**
  *	Include the class for PMProSequences
  */
if (! class_exists( 'PMProSequence' )):

    require_once(PMPRO_SEQUENCE_PLUGIN_DIR . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "class.pmprosequence.php");
	require_once(PMPRO_SEQUENCE_PLUGIN_DIR . DIRECTORY_SEPARATOR ."scheduled" .DIRECTORY_SEPARATOR. "crons.php");

endif;

if ( ! class_exists( 'PMProSeqRecentPost' )):
	require_once(PMPRO_SEQUENCE_PLUGIN_DIR . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "class.PMProSeqRecentPost.php");
endif;



add_action( 'add_meta_boxes', array( &$sequence, 'pmpro_sequence_post_metabox_setup') );

add_action( 'admin_notices', array( &$sequence, 'pmpro_seq_display_error' ) );
add_action( 'admin_init', array( &$sequence, 'pmpro_sequence_init_admin') );

if ( ! function_exists( 'pmpro_sequence_post_save' ) ):

    add_action( 'save_post', 'pmpro_sequence_post_save' );

    function pmpro_sequence_post_save( $post_id ) {

        global $current_user;

        dbgOut("Sequence info for post/page save");

        dbgOut("Sequences & Delays have been configured");

        $seq_ids = is_array( $_POST['pmpro_seq-sequences'] ) ? $_POST['pmpro_seq-sequences'] : null;
        $delays = is_array( $_POST['pmpro_seq-delay']) ? $_POST['pmpro_seq-delay'] : null;

        $errMsg = null;

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            dbgOut("Exit during autosave");
            return;
        }

        if ( wp_is_post_revision( $post_id ) !== false ) {
            dbgOut("Not saving revisions ({$post_id}) in sequence");
            return;
        }

        $already_in = get_post_meta( $post_id, "_post_sequences", true );

        dbgOut( "Saved received variable values...");

        foreach ($seq_ids as $key => $id ) {

            dbgOut("Processing for sequence {$id}");

            if ( $id == 0 ) {
                continue;
            }

            $sequence = new PMProSequence( $id );
            $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $sequence->userCan( $current_user->ID ) );

            if (! $user_can ) {

                $sequence->setError( __( 'Incorrect privileges for this operation', 'pmprosequence' ) );
                dbgOut("User lacks privileges to edit");
                return;
            }

            if ( $id == 0 ) {

                dbgOut("No specified sequence or it's set to 'nothing'");

            }
            elseif ( empty( $delays[$key] ) ) {

                dbgOut("Not a valid delay value...: " . $delays[$key]);
                $sequence->setError( sprintf( __( "You must specify a delay value for the '%s' sequence", 'pmprosequence'), get_the_title( $id ) ) );
            }
            else {

                dbgOut( "Processing post {$post_id} for sequence {$id} with delay {$delays[$key]}" );
                $sequence->addPost( $post_id, $delays[ $key ] );
            }
        }
    }

endif;

/**
  *	Load CSS, JS files
  */
if (! function_exists('pmpro_sequence_scripts')):

    // add_action("init", "pmpro_sequence_scripts");
	add_action("wp_enqueue_scripts", "pmpro_sequence_scripts");

	function pmpro_sequence_scripts() {

		wp_register_script('pmpro_sequence_script', plugins_url('/js/pmpro-sequences.js', __FILE__), array('jquery'), null, true);

		wp_localize_script('pmpro_sequence_script', 'pmpro_sequence',
			array(
				'ajaxurl' => admin_url('admin-ajax.php'),
			)
		);

		wp_enqueue_style("pmpro_sequence_css", plugins_url('/css/pmpro_sequences.css', __FILE__ ));
		wp_enqueue_script('pmpro_sequence_script');
	}
endif;

if (! function_exists('pmpro_sequence_admin_scripts')):

	add_action("admin_enqueue_scripts", "pmpro_sequence_admin_scripts");

	/**
	 * Load all JS for Admin page
	 */
	function pmpro_sequence_admin_scripts()
    {

	    wp_register_script('pmpro_sequence_admin_script', plugins_url('/js/pmpro-sequences-admin.js', __FILE__), array('jquery'), null, true);

	    /* Localize ajax script */
	    wp_localize_script('pmpro_sequence_admin_script', 'pmpro_sequence',
		    array(
			    'ajaxurl' => admin_url('admin-ajax.php'),
			    'lang' => array(
				    'save' => __('Update Sequence', 'pmprosequence'),
				    'saving' => __('Saving', 'pmprosequence'),
				    'saveSettings' => __('Update Settings', 'pmprosequence'),
				    'delay_change_confirmation' => __('Changing the delay type will erase all existing posts or pages in the Sequence list. (Cancel if your are unsure)', 'pmprosequence'),
				    'saving_error_1' => __('Error saving sequence post [1]', 'pmprosequence'),
				    'saving_error_2' => __('Error saving sequence post [2]', 'pmprosequence'),
				    'remove_error_1' => __('Error deleting sequence post [1]', 'pmprosequence'),
				    'remove_error_2' => __('Error deleting sequence post [2]', 'pmprosequence'),
				    'undefined' => __('Not Defined', 'pmprosequence'),
				    'unknownerrorrm' => __('Unknown error removing post from sequence', 'pmprosequence'),
				    'unknownerroradd' => __('Unknown error adding post to sequence', 'pmprosequence'),
				    'daysLabel' => __('Delay', 'pmprosequence'),
				    'daysText' => __('Days to delay', 'pmprosequence'),
				    'dateLabel' => __('Avail. on', 'pmprosequence'),
				    'dateText' => __('Release on (YYYY-MM-DD)', 'pmprosequence'),
			    )
		    )
	    );

	    wp_enqueue_style("pmpro_sequence_css", plugins_url('/css/pmpro_sequences.css', __FILE__ ));
	    wp_enqueue_script('pmpro_sequence_admin_script');

    }
endif;

if (! function_exists( 'pmpro_sequence_register_widgets' )):

	add_action( 'widgets_init', 'pmpro_sequence_register_widgets');

	function pmpro_sequence_register_widgets() {

		// Add widget to display a summary for the most recent post/page
		// in the sequence for the logged in user.
		register_widget( 'PMProSeqRecentPost' );

	}

endif;

if (! function_exists('pmpro_sequence_register_shortcodes')):

	add_action("init", "pmpro_sequence_register_shortcodes");

	function pmpro_sequence_register_shortcodes() {

		// Generates paginated list of links to sequence members
		add_shortcode( 'sequence_links', 'pmpro_sequence_links_shortcode');

	}

endif;

/**
 * Load and use L18N based text (if available)
 */
if (! function_exists('pmpro_sequence_load_textdomain')):

	add_action("init", "pmpro_sequence_load_textdomain");

	function pmpro_sequence_load_textdomain() {

        $domain = "pmprosequence";

		$locale = apply_filters( "plugin_locale", get_locale(), $domain );

        $mofile = "{$domain}-{$locale}.mo";

        $mofile_local = dirname( __FILE__ ) . "/languages/" . $mofile;
        $mofile_global = WP_LANG_DIR . "/pmpro_sequence/" . $mofile;

		load_textdomain( $domain, $mofile_global );

		load_plugin_textdomain( $domain, FALSE, plugin_basename(__FILE__) . "/languages/" );
	}

endif;

if (! function_exists('pmpro_sequence_ajaxUnprivError')):
	/**
	 * Functions returns error message. Used by nopriv Ajax traps.
	 */
	function pmpro_sequence_ajaxUnprivError() {

		dbgOut('Unprivileged ajax call attempted');
		wp_send_json_error( array(
			'message' => __('You must be logged in to edit PMPro Sequences', 'pmprosequence')
		));

	}
endif;

/**
 *	PMPro Sequence CPT init
 */
add_action("init", array("PMProSequence", "createCPT"));

/**
 *	Add the PMPro meta box and the meta box to add posts/pages to sequence
 */
add_action("init", array("PMProSequence", "checkForMetaBoxes"), 20);

/** A debug function */
if ( ! function_exists('dbgOut') ):
	/**
	 * Debug function (if executes if DEBUG is defined)
	 *
	 * @param $msg -- Debug message to print to debug log.
	 */
	function dbgOut($msg)
	{
		$dbgPath = plugin_dir_path( __FILE__ ) . DIRECTORY_SEPARATOR . 'debug';

		if (PMPRO_SEQUENCE_DEBUG)
		{

			if (!  file_exists( $dbgPath )) {
				// Create the debug logging directory
				mkdir( $dbgPath, 0750 );

				if (! is_writable( $dbgPath )) {
					error_log('PMPro Sequence: Debug log directory is not writable. exiting.');
					return;
				}
			}

			$dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'sequence_debug_log-' . date('Y-m-d') . '.txt';

			if ( ($fh = fopen($dbgFile, 'a')) !== false ) {

				// Format the debug log message
				$dbgMsg = '(' . date('d-m-y H:i:s') . ') -- '. $msg;

				// Write it to the debug log file
				fwrite( $fh, $dbgMsg . "\r\n" );
				fclose( $fh );
			}
			else
				error_log('PMPro Sequence: Unable to open debug log');
		}
	}

endif;
/*
	Detect AJAX calls
*/

/*
if ( ! function_exists( 'pmpro_add_post_from_post_meta_callback' ) ):

    add_action("wp_ajax_pmpro_seq_post_meta_add", "pmpro_add_post_from_post_meta_callback");
    add_action('wp_ajax_nopriv_ppmpro_seq_post_meta_add', 'pmpro_sequence_ajaxUnprivError');

    function pmpro_add_post_from_post_meta_callback() {

        check_ajax_referer( 'pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce' );

        dbgOut( "Saving Sequence information for post " );

        $sequence_id = isset( $_POST['pmpro_sequence_id'] ) && '' != $_POST['pmpro_sequence_id'] ? intval( $_POST['pmpro_sequence_id'] ) : null;
        $seq_post_id = isset( $_POST['pmpro_sequencepost'] ) && '' != $_POST['pmpro_sequencepost'] ? intval( $_REQUEST['pmpro_sequencepost'] ) : null;
        $delayVal    = isset( $_POST['pmpro_sequencedelay'] ) ? $_POST['pmpro_sequencedelay'] : null;

        if ( $sequence_id != 0 ) {

            // Initiate & configure the Sequence class
            $sequence = new PMProSequence( $sequence_id );

            dbgOut( 'pmpro_add_post_from_post_meta_callback() - Checking whether delay value is correct' );
            $delay = $sequence->validatePOSTDelay( $delayVal );

            // Get the Delay to use for the post (depends on type of delay configured)
            if ( $delay !== false ) {

                if ( current_user_can( 'edit_posts' ) && ! is_null( $seq_post_id ) ) {

                    dbgOut( '"pmpro_add_post_from_post_meta_callback"() - Adding post ' . $seq_post_id . ' to sequence ' . $sequence->sequence_id );
                    $sequence->addPost( $seq_post_id, $delay );
                    $success = true;
                    $sequence->setError( null );
                } else {
                    $success = false;
                    $sequence->setError( __( 'Not permitted to modify the sequence', 'pmprosequence' ) );
                }
            }
            else {

                if ( empty( $seq_post_id ) && ( $sequence->getError() == null ) ) {

                    $sequence->setError( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );

                }
                elseif ( empty( $delay ) ) {

                    $sequence->setError( __( 'No delay has been specified', 'pmprosequence' ) );
                }

                $delay       = null;
                $seq_post_id = null;

                $success = false;
            }
        }

        $result = $sequence->load_sequence_meta( $seq_post_id, null );

        if ( $result && $success ) {
            dbgOut( 'pmpro_sequence_add_post_callback() - Returning success to javascript frontend' );

            wp_send_json_success( $result['html'] );
        } else {
            dbgOut( 'pmpro_sequence_add_post_callback() - Returning error to javascript frontend' );
            wp_send_json_error( $sequence->getError() );
        }

    } // End of function
endif;
*/

if ( !function_exists( 'pmpro_sequence_add_post_callback')):

    // add_action("init", "pmpro_sequence_ajax");
	add_action("wp_ajax_pmpro_sequence_add_post", "pmpro_sequence_add_post_callback");
	add_action('wp_ajax_nopriv_pmpro_sequence_add_post', 'pmpro_sequence_ajaxUnprivError');

    /**
     * Process AJAX based additions to the sequence list
     *
     * Returns 'error' message (or nothing, if success) to calling JavaScript function
     */
    function pmpro_sequence_add_post_callback()
    {
	    check_ajax_referer('pmpro-sequence-add-post', 'pmpro_sequence_addpost_nonce');

        global $current_user;

	    // Fetch the ID of the sequence to add the post to
        $sequence_id = isset( $_POST['pmpro_sequence_id'] ) && '' != $_POST['pmpro_sequence_id'] ? intval($_POST['pmpro_sequence_id']) : null;
        $seq_post_id = isset( $_POST['pmpro_sequencepost'] ) && '' != $_POST['pmpro_sequencepost'] ? intval( $_REQUEST['pmpro_sequencepost'] ) : null;
        $delayVal = isset( $_POST['pmpro_sequencedelay'] ) ? $_POST['pmpro_sequencedelay'] : null ;

        if ( $sequence_id != 0 ) {

            // Initiate & configure the Sequence class
            $sequence = new PMProSequence( $sequence_id );

            dbgOut( 'add_post_callback() - Checking whether delay value is correct' );
            $delay = $sequence->validatePOSTDelay( $delayVal );

            // Get the Delay to use for the post (depends on type of delay configured)
            if ( $delay !== false ) {

                $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $sequence->userCan( $current_user->ID ) );

                if ( $user_can && ! is_null( $seq_post_id ) ) {

                    dbgOut( 'pmpro_sequence_add_post_callback() - Adding post ' . $seq_post_id . ' to sequence ' . $sequence->sequence_id );
                    $sequence->addPost( $seq_post_id, $delay );
                    $success = true;
                    $sequence->setError( null );

                } else {
                    $success = false;
                    $sequence->setError( __( 'Not permitted to modify the sequence', 'pmprosequence' ) );
                }

            } else {

                dbgOut( 'pmpro_sequence_add_post_callback(): Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['pmpro_sequencedelay'] ) );

                if ( empty( $seq_post_id ) && ( $sequence->getError() == null ) ) {
                    $sequence->setError( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
                } elseif ( empty( $delay ) ) {
                    $sequence->setError( __( 'No delay has been specified', 'pmprosequence' ) );
                }

                $delay       = null;
                $seq_post_id = null;

                $success = false;

            }

            if ( empty( $seq_post_id ) && ( $sequence->getError() == null ) ) {

                $success = false;
                $sequence->setError( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
            }
            elseif ( empty( $sequence_id ) && ( $sequence->getError() == null ) ) {

                $success = false;
                $sequence->setError( sprintf( __( 'This sequence was not found on the server!', 'pmprosequence' ) ) );
            }

            $result = $sequence->getPostListForMetaBox();

            // dbgOut("pmpro_sequence_add_post_callback() - Data added to sequence. Returning status to calling JS script: " . print_r($result, true));

            if ( $result['success'] && $success ) {
                dbgOut( 'pmpro_sequence_add_post_callback() - Returning success to javascript frontend' );

                wp_send_json_success( $result['html'] );
            } else {
                dbgOut( 'pmpro_sequence_add_post_callback() - Returning error to javascript frontend' );
                wp_send_json_error( $sequence->getError() );
            }
        }
        else {
            dbgOut( "Sequence ID was 0. That's a 'blank' sequence" );
            wp_send_json_error( 'No sequence specified on save.' );
        }
    }
endif;

if ( ! function_exists( 'update_delay_post_meta_callback' ) ):

    // add_action("init", "pmpro_sequence_ajax");
    add_action('wp_ajax_pmpro_sequence_update_post_meta', 'update_delay_post_meta_callback');
    add_action('wp_ajax_nopriv_pmpro_sequence_update_post_meta', 'pmpro_sequence_ajaxUnprivError');

    function update_delay_post_meta_callback() {

        dbgOut("Update the delay input for the post/page meta");

        check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

        dbgOut("Nonce Passed for postmeta AJAX call");

        $seq_id = isset( $_POST['pmpro_sequence_id'] ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
        $post_id = isset( $_POST['pmpro_sequence_post_id']) ? intval( $_POST['pmpro_sequence_post_id'] ) : null;

        dbgOut("Sequence: {$seq_id}, Post: {$post_id}" );

//         if ( $seq_id != 0 ) {

            $seq = new PMProSequence( $seq_id );

            $html = $seq->load_sequence_meta( $post_id, $seq_id );

            wp_send_json_success( $html );
//        }
//        else {
//            wp_send_json_error( 'Error loading sequence data.' );
//        }
    }

endif;

/**
 * Removes the sequence from managing this $post_id.
 * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
 */
if ( ! function_exists( 'pmpro_rm_sequence_from_post_callback' ) ):

    add_action('wp_ajax_pmpro_rm_sequence_from_post', 'pmpro_rm_sequence_from_post_callback' );
    add_action('wp_ajax_nopriv_pmpro_rm_sequence_from_post', 'pmpro_sequence_ajaxUnprivError');

    function pmpro_rm_sequence_from_post_callback() {

        $success = false;

        dbgOut("In pmpro_rm_sequence_from_post()");
        check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

        dbgOut("NONCE is OK for pmpro_sequence_rm");

        $sequence_id = ( isset( $_POST['pmpro_sequence_id'] ) && ( intval( $_POST['pmpro_sequence_id'] ) != 0 ) ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
        $post_id = isset( $_POST['pmpro_seq_post_id'] ) ? intval( $_POST['pmpro_seq_post_id'] ) : null;

        $sequence = new PMProSequence( $sequence_id );
        $sequence->setError( null ); // Clear any pending error messages (don't care at this point).

        // Remove the post (if the user is allowed to)
        if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {

            dbgOut("Removing post # {$post_id} from sequence {$sequence_id}");
            $sequence->removePost( $post_id, true );
            //$result = __('The post has been removed', 'pmprosequence');
            $success = true;
        } else {

            $success = false;
            $sequence->setError( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence' ) );
        }

        $result = $sequence->load_sequence_meta( $post_id );

        if ( ! empty( $result ) && is_null( $sequence->getError() ) && ( $success ) ) {

            dbgOut( 'Returning success to caller' );
            wp_send_json_success( $result );
        } else {

            wp_send_json_error( ( ! is_null( $sequence->getError() ) ? $sequence->getError() : 'Error clearing the sequence from this post' ) );
        }
    }
endif;

/**
 * Used by the Sequence CPT edit page to remove a post from the sequence being processed
 */
if ( !function_exists( 'pmpro_sequence_rm_post_callback')):

	// add_action("init", "pmpro_sequence_ajax");
	add_action("wp_ajax_pmpro_sequence_rm_post", "pmpro_sequence_rm_post_callback");
	add_action('wp_ajax_nopriv_pmpro_sequence_rm_post', 'pmpro_sequence_ajaxUnprivError');

	/**
	 * Process AJAX based removals of posts from the sequence list
	 *
	 * Returns 'error' message (or nothing, if success) to calling JavaScript function
	 */
	function pmpro_sequence_rm_post_callback() {

		check_ajax_referer('pmpro-sequence-rm-post', 'pmpro_sequence_rmpost_nonce');

		$result = '';
		$success = false;

		$sequence_id = ( isset( $_POST['pmpro_sequence_id']) && '' != $_POST['pmpro_sequence_id'] ? intval($_POST['pmpro_sequence_id']) : null );
		$seq_post_id = ( isset( $_POST['pmpro_seq_post']) && '' != $_POST['pmpro_seq_post'] ? intval($_POST['pmpro_seq_post']) : null );

		$sequence = new PMProSequence( $sequence_id );

		// Remove the post (if the user is allowed to)
		if ( current_user_can( 'edit_posts' ) && ! is_null($seq_post_id) ) {

			$sequence->removePost($seq_post_id);
			//$result = __('The post has been removed', 'pmprosequence');
			$success = true;

		}
		else {

			$success = false;
			$sequence->setError( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence'));
		}

		// Return the content for the new listbox (sans the deleted item)
		$result = $sequence->getPostListForMetaBox();

		if ( is_null( $result['message'] ) && is_null( $sequence->getError() ) && ($success)) {
			dbgOut('Returning success to calling javascript');
			wp_send_json_success( $result['html'] );
		}
		else
			wp_send_json_error( ( ! is_null( $sequence->getError() ) ? $sequence->getError() : $result['message']) );

	}

endif;

if ( ! function_exists( 'pmpro_sequence_clear_callback')):

    add_action('wp_ajax_pmpro_sequence_clear', 'pmpro_sequence_clear_callback');
	add_action('wp_ajax_nopriv_pmpro_sequence_clear', 'pmpro_sequence_ajaxUnprivError');

    /**
     * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members)
     */
    function pmpro_sequence_clear_callback()
    {
	    // Validate that the ajax referrer is secure
	    check_ajax_referer('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce');

	    $sequence = new PMProSequence();
	    $result = '';

	    // Clear the sequence metadata if the sequence type (by date or by day count) changed.
        if (isset($_POST['pmpro_sequence_clear']))
        {
            if (isset($_POST['pmpro_sequence_id']))
            {
                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $sequence = new PMProSequence($sequence_id);

	            dbgOut('Deleting all entries in sequence # ' .$sequence_id);

	            if (! delete_post_meta($sequence_id, '_sequence_posts'))
	            {
		            dbgOut('Unable to delete the posts in sequence # ' . $sequence_id);
		            $sequence->setError( __('Could not delete posts from this sequence', 'pmprosequence'));
		            $success = false;

	            }
	            else {
		            $result = $sequence->getPostListForMetaBox();
	            }

            }
            else
            {
                $sequence->setError( __('Unable to identify the Sequence', 'pmprosequence'));
	            $success = false;
            }
        }
        else {
	        $sequence->setError( __('Unknown request', 'pmprosequence'));
	        $success = false;
        }

	    // Return the status to the calling web page
	    if ( $result['success'] )
	        wp_send_json_success( $result['html']  );
	    else
		    wp_send_json_error( $sequence->getError() );

    }

endif;

if (! function_exists('pmpro_sequence_sendalert_callback')):

	add_action('wp_ajax_pmpro_send_notices', 'pmpro_sequence_sendalert_callback');
	add_action('wp_ajax_nopriv_pmpro_send_notices', 'pmpro_sequence_ajaxUnprivError');

	function pmpro_sequence_sendalert_callback() {

		dbgOut('sendalert() - Processing the request to send alerts manually');

		check_ajax_referer('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce');

		dbgOut('Nonce is OK');

		if ( isset( $_POST['pmpro_sequence_id'] ) ) {

			$sequence_id = intval($_POST['pmpro_sequence_id']);
			dbgOut('Will send alerts for sequence #' . $sequence_id);
			do_action( 'pmpro_sequence_cron_hook', $sequence_id);
			dbgOut('Completed action for sequence');
		}
	}

endif;

if (! function_exists('pmpro_sequence_optin_callback')):

    add_action('wp_ajax_pmpro_sequence_save_user_optin', 'pmpro_sequence_optin_callback');
    add_action('wp_ajax_nopriv_pmpro_sequence_save_user_optin', 'pmpro_sequence_ajaxUnprivError');

    function pmpro_sequence_optin_callback()
    {
        global $current_user, $wpdb;

	    $result = '';

        try {
	        $seq = new PMProSequence();

	        check_ajax_referer('pmpro-sequence-user-optin', 'pmpro_sequence_optin_nonce');

	        if ( isset($_POST['hidden_pmpro_seq_uid'])) {

		        $user_id = intval($_POST['hidden_pmpro_seq_uid']);
		        dbgOut('Updating user settings for user #: ' . $user_id);
	        }
	        else {
		        dbgOut( 'No user ID specified. Ignoring settings!' );

		        wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
	        }

	        if ( isset($_POST['hidden_pmpro_seq_id'])) {

		        $seqId = intval( $_POST['hidden_pmpro_seq_id']);
	        }
	        else {

		        dbgOut( 'No sequence number specified. Ignoring settings for user' );

		        wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
	        }

	        $seq = new PMProSequence( $seqId );
	        dbgOut('Updating user settings for sequence #: ' . $seq->sequence_id);

	        // Grab the metadata from the database
	        $usrSettings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence_notices', true);

	        if ( empty($usrSettings->sequence) || empty( $usrSettings->sequence[$seqId] ) ) {

		        dbgOut('No user specific settings found in general or for this sequence. Creating defaults');

		        // Create new opt-in settings for this user
		        if ( empty($usrSettings->sequence) )
		            $new = new stdClass();
		        else // Saves existing settings
			        $new = $usrSettings;

		        dbgOut('addUserNoticeOptIn() - Using default setting for user ' . $current_user->ID . ' and sequence ' . $seq->sequence_id);

		        $usrSettings = $new;
	        }

	        $usrSettings->sequence[$seqId]->sendNotice = ( isset( $_POST['hidden_pmpro_seq_useroptin'] ) ?
		        intval($_POST['hidden_pmpro_seq_useroptin']) : $seq->options->sendNotice );

	        // If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
	        // If they opted out, set the opt-in timestamp to -1
	        if ($usrSettings->sequence[$seqId]->sendNotice == 1)
		        // Set the timestamp when the user opted in.
		        $usrSettings->sequence[$seqId]->optinTS = current_time('timestamp', true);
	        else
		        $usrSettings->sequence[$seqId]->optinTS = -1; // Opted out.

	        // Add an empty array to store posts that the user has already been notified about
	        if ( empty( $usrSettings->sequence[$seqId]->notifiedPosts ) )
		        $usrSettings->sequence[$seqId]->notifiedPosts = array();

            /* Save the user options we just defined */
            if ( $user_id == $current_user->ID ) {

	            dbgOut('Opt-In Timestamp is: ' . $usrSettings->sequence[$seqId]->optinTS);
	            // dbgOut('Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r($usrSettings, true));
	            update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence_notices', $usrSettings );
	            $status = true;
	            $seq->setError(null);
            }
            else {

                dbgOut('Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID);
	            $seq->setError( __( 'Unable to save your settings', 'pmprosequence' ) );
	            $status = false;
            }
        }
        catch (Exception $e) {
	        $seq->setError( sprintf( __('Error: %s', 'pmprosequence' ), $e->getMessage() ) );
	        $status = false;
	        dbgOut('optin_save() - Exception error: ' . $e->getMessage());
        }

	    if ($status)
		    wp_send_json_success();
	    else
	        wp_send_json_error( $seq->getError() );

    }
endif;

if (! function_exists( 'pmpro_sequence_settings_callback')):

    add_action('wp_ajax_pmpro_save_settings', 'pmpro_sequence_settings_callback');
	add_action('wp_ajax_nopriv_pmpro_save_settings', 'pmpro_sequence_ajaxUnprivError');

    /**
     * Function to process Sequence Settings AJAX POST call (save operation)
     *
     * Returns 'success' or 'error' message to calling JavaScript function
     */
    function pmpro_sequence_settings_callback()
    {
	    // Validate that the ajax referrer is secure
	    check_ajax_referer('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce');

	    $status = false;
	    $response = '';

	    $sequence = new PMProSequence(); // For error management purposes

	    try {

            if ( isset($_POST['pmpro_sequence_id']) ) {

                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $sequence = new PMProSequence($sequence_id);

	            dbgOut('ajaxSaveSettings() - Saving settings for ' . $sequence_id);

                if ( ($status = pmpro_sequence_settings_save($sequence_id, $sequence)) === true) {

                    if ( isset($_POST['hidden_pmpro_seq_wipesequence'])) {

		                if (intval($_POST['hidden_pmpro_seq_wipesequence']) == 1) {

			                // Wipe the list of posts in the sequence.
			                $sposts = get_post_meta( $sequence_id, '_sequence_posts' );

			                if ( count($sposts) > 0) {

				                if ( ! delete_post_meta( $sequence_id, '_sequence_posts' ) ) {

					                dbgOut( 'ajaxSaveSettings() - Unable to delete the posts in sequence # ' . $sequence_id );
					                $sequence->setError( __('Unable to wipe existing posts', 'pmprosequence') );
					                $status = false;
				                }
				                else
					                $status = true;
			                }

			                dbgOut( 'ajaxSaveSettings() - Deleted all posts in the sequence' );
	                    }
	                }
                }
	            else {
		            $sequence->setError( printf( __('Save status returned was: %s', 'pmprosequence'), $status ) );
	            }

	            $response = $sequence->getPostListForMetaBox();
            }
		    else {
			    $sequence->setError( __( 'No sequence ID found/specified', 'pmprosequence' ) );
			    $status = false;
		    }

        } catch (Exception $e) {

		    $status = false;
		    $sequence->setError( printf( __('(exception) %s', 'pmprosequence'), $e->getMessage()) );
		    dbgOut(print_r($sequence->getError(), true));
        }


	    if ($status)
		    wp_send_json_success( $response['html'] );
	    else
		    wp_send_json_error( $sequence->getError() );

    }


	/**
	 * Save the settings for a sequence ID as post_meta for that Sequence CPT
	 *
	 * @param $sequence_id -- ID of the sequence to save options for
	 * @param $sequenceObj -- stdObject containing configuration settings
	 * @return bool - Returns true if save is successful
	 */

	function pmpro_sequence_settings_save( $sequence_id, PMProSequence $sequenceObj )
	{

		$settings = $sequenceObj->options;
		dbgOut('Saving settings for Sequence w/ID: ' . $sequence_id);

		// Check that the function was called correctly. If not, just return
		if(empty($sequence_id)) {
			dbgOut('pmpro_sequence_settings_save(): No sequence ID supplied...');
			$sequenceObj->setError( __('No sequence provided', 'pmprosequence'));
			return false;
		}

		// Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			$sequenceObj->setError(null);
			return $sequence_id;
		}

		// Verify that we're allowed to update the sequence data
		if ( !current_user_can( 'edit_post', $sequence_id ) ) {
			dbgOut('pmpro_sequence_settings_save(): User is not allowed to edit this post type');
			$sequenceObj->setError( __('User is not allowed to change settings', 'pmprosequence'));
			return false;
		}

		if (!$sequenceObj->options)
			$sequenceObj->options = $sequenceObj->defaultOptions();

		// Checkbox - not included during post/save if unchecked
		if ( isset($_POST['hidden_pmpro_seq_future']) )
		{
			$sequenceObj->options->hidden = intval($_POST['hidden_pmpro_seq_future']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->hidden: ' . $_POST['hidden_pmpro_seq_future'] );
		}
		elseif ( empty($sequenceObj->options->hidden) )
			$sequenceObj->options->hidden = 0;

		// Checkbox - not included during post/save if unchecked
		if (isset($_POST['hidden_pmpro_seq_lengthvisible']) )
		{
			$sequenceObj->options->lengthVisible = intval($_POST['hidden_pmpro_seq_lengthvisible']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->lengthVisible: ' . $_POST['hidden_pmpro_seq_lengthvisible']);
		}
		elseif (empty($sequenceObj->options->lengthVisible)) {
			dbgOut('Setting lengthVisible to default value (checked)');
			$sequenceObj->options->lengthVisible = 1;
		}

		if ( isset($_POST['hidden_pmpro_seq_sortorder']) )
		{
			$sequenceObj->options->sortOrder = intval($_POST['hidden_pmpro_seq_sortorder']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->sortOrder: ' . $_POST['hidden_pmpro_seq_sortorder'] );
		}
		elseif (empty($sequenceObj->options->sortOrder))
			$sequenceObj->options->sortOrder = SORT_ASC;

		if ( isset($_POST['hidden_pmpro_seq_delaytype']) )
		{
			$sequenceObj->options->delayType = esc_attr($_POST['hidden_pmpro_seq_delaytype']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->delayType: ' . esc_attr($_POST['hidden_pmpro_seq_delaytype']) );
		}
		elseif (empty($sequenceObj->options->delayType))
			$sequenceObj->options->delayType = 'byDays';

        // options->showDelayAs
        if ( isset($_POST['hidden_pmpro_seq_showdelayas']) )
        {
            $sequenceObj->options->showDelayAs = esc_attr($_POST['hidden_pmpro_seq_showdelayas']);
            dbgOut('pmpro_sequence_settings_save(): POST value for settings->showDelayAs: ' . esc_attr($_POST['hidden_pmpro_seq_showdelayas']) );
        }
        elseif (empty($sequenceObj->options->showDelayAs))
            $sequenceObj->options->delayType = PMPRO_SEQ_AS_DAYNO;

        if ( isset($_POST['hidden_pmpro_seq_offset']) )
        {
            $sequenceObj->options->previewOffset = esc_attr($_POST['hidden_pmpro_seq_offset']);
            dbgOut('pmpro_sequence_settings_save(): POST value for settings->previewOffset: ' . esc_attr($_POST['hidden_pmpro_seq_offset']) );
        }
        elseif (empty($sequenceObj->options->previewOffset))
            $sequenceObj->options->previewOffset = 0;

        if ( isset($_POST['hidden_pmpro_seq_startwhen']) )
		{
			$sequenceObj->options->startWhen = esc_attr($_POST['hidden_pmpro_seq_startwhen']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->startWhen: ' . esc_attr($_POST['hidden_pmpro_seq_startwhen']) );
		}
		elseif (empty($sequenceObj->options->startWhen))
			$sequenceObj->options->startWhen = 0;

		// Checkbox - not included during post/save if unchecked
		if ( isset($_POST['hidden_pmpro_seq_sendnotice']) )
		{
			$sequenceObj->options->sendNotice = intval($_POST['hidden_pmpro_seq_sendnotice']);

            if ( $sequenceObj->options->sendNotice == 0 ) {

                $sequenceObj->stopSendingNotices();
            }

            dbgOut('pmpro_sequence_settings_save(): POST value for settings->sendNotice: ' . intval($_POST['hidden_pmpro_seq_sendnotice']) );
		}
		elseif (empty($sequenceObj->options->sendNotice)) {
			$sequenceObj->options->sendNotice = 1;
		}

		if ( isset($_POST['hidden_pmpro_seq_noticetemplate']) )
		{
			$sequenceObj->options->noticeTemplate = esc_attr($_POST['hidden_pmpro_seq_noticetemplate']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->noticeTemplate: ' . esc_attr($_POST['hidden_pmpro_seq_noticetemplate']) );
		}
		else
			$sequenceObj->options->noticeTemplate = 'new_content.html';

		if ( isset($_POST['hidden_pmpro_seq_noticetime']) )
		{
			$sequenceObj->options->noticeTime = esc_attr($_POST['hidden_pmpro_seq_noticetime']);
			dbgOut('pmpro_sequence_settings_save() - noticeTime in settings: ' . $sequenceObj->options->noticeTime);

			/* Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone) */
			$sequenceObj->options->noticeTimestamp = $sequenceObj->calculateTimestamp($settings->noticeTime);

			dbgOut('pmpro_sequence_settings_save(): POST value for settings->noticeTime: ' . esc_attr($_POST['hidden_pmpro_seq_noticetime']) );
		}
		else
			$sequenceObj->options->noticeTime = '00:00';

        if ( isset($_POST['hidden_pmpro_seq_excerpt']) )
        {
	        $sequenceObj->options->excerpt_intro = esc_attr($_POST['hidden_pmpro_seq_excerpt']);
            dbgOut('pmpro_sequence_settings_save(): POST value for settings->excerpt_intro: ' . esc_attr($_POST['hidden_pmpro_seq_excerpt']) );
        }
        else
	        $sequenceObj->options->excerpt_intro = 'A summary of the post follows below:';

		if ( isset($_POST['hidden_pmpro_seq_fromname']) )
		{
			$sequenceObj->options->fromname = esc_attr($_POST['hidden_pmpro_seq_fromname']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->fromname: ' . esc_attr($_POST['hidden_pmpro_seq_fromname']) );
		}
		else
			$sequenceObj->options->fromname = pmpro_getOption('from_name');

        if ( isset($_POST['hidden_pmpro_seq_dateformat']) )
        {
            $sequenceObj->options->dateformat = esc_attr($_POST['hidden_pmpro_seq_dateformat']);
            dbgOut('pmpro_sequence_settings_save(): POST value for settings->dateformat: ' . esc_attr($_POST['hidden_pmpro_seq_dateformat']) );
        }
        else
            $sequenceObj->options->dateformat = __('m-d-Y', 'pmprosequence'); // Default is MM-DD-YYYY (if translation supports it)

        if ( isset($_POST['hidden_pmpro_seq_replyto']) )
		{
			$sequenceObj->options->replyto = esc_attr($_POST['hidden_pmpro_seq_replyto']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->replyto: ' . esc_attr($_POST['hidden_pmpro_seq_replyto']) );
		}
		else
			$sequenceObj->options->replyto = pmpro_getOption('from_email');

		if ( isset($_POST['hidden_pmpro_seq_subject']) )
		{
			$sequenceObj->options->subject = esc_attr($_POST['hidden_pmpro_seq_subject']);
			dbgOut('pmpro_sequence_settings_save(): POST value for settings->subject: ' . esc_attr($_POST['hidden_pmpro_seq_subject']) );
		}
		else
			$sequenceObj->options->subject = __('New: ', 'pmprosequence');

		// $sequence->options = $settings;
		if ( $sequenceObj->options->sendNotice == 1 ) {

			dbgOut( 'pmpro_sequence_meta_save(): Updating the cron job for sequence ' . $sequenceObj->sequence_id );

			if (! $sequenceObj->updateNoticeCron() )
				dbgOut('pmpro_sequence_settings_save() - Error configuring cron() system for sequence ' . $sequenceObj->sequence_id);
		}

		// dbgOut('pmpro_sequence_settings_save() - Settings are now: ' . print_r($settings, true));

		// Save settings to WPDB
		return $sequenceObj->save_sequence_meta($sequenceObj->options, $sequence_id);
	}
endif;

if ( ! function_exists( 'pmpro_sequence_content' )):

    add_filter("the_content", "pmpro_sequence_content");

    /**
     * Show list of sequence pages at the bottom of the sequence page
     *
     * @param $content -- The content to process as part of the filter action
     * @return string -- The filtered content
     */
    function pmpro_sequence_content($content)
    {
        global $post;

        if ( ( $post->post_type == "pmpro_sequence" ) && pmpro_has_membership_access() )
        {
            $sequence = new PMProSequence($post->ID);
	        // $sequence->options = $sequence->fetchOptions();

            // If we're supposed to show the "days of membership" information, adjust the text for type of delay.
            if ( intval($sequence->options->lengthVisible) == 1 )
                $content .= sprintf("<p>%s</p>", sprintf( __("You are on day %s of your membership", "pmprosequence"), pmpro_sequence_getMemberDays()) );
/*
	        if ( intval($sequence->options->sendNotice) == 1)
		        $content .= $sequence->pmpro_sequence_addUserNoticeOptIn( $sequence );
*/
            // Add the list of posts in the sequence to the content.
            $content .= $sequence->getPostList();
        }

        return $content;
    }
endif;

if (! function_exists('pmpro_sequence_getMemberDays')):

	function pmpro_sequence_getMemberDays( $user_id = NULL, $level_id = 0 ) {

		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}

		global $pmpro_member_days;

		if ( empty( $pmpro_member_days[$user_id][$level_id] ) ) {

			$startdate = pmpro_getMemberStartdate( $user_id, $level_id );

			//check that there was a startdate at all
			if( empty( $startdate ) ) {

                $pmpro_member_days[$user_id][$level_id] = 0;
            }
			else {

				$now = current_time("timestamp");
				$days = ceil( ($now - $startdate) / ( 60*60*24 ) );

				$pmpro_member_days[$user_id][$level_id] = $days;
			}
		}

		return $pmpro_member_days[$user_id][$level_id];
}

endif;

if ( ! function_exists( 'pmpro_sequence_hasAccess')):

    /**
     * Check the whether the User ID has access to the post ID
     * Make sure people can't view content they don't have access to.
     *
     * @param $user_id (int) -- The users ID to check access for
     * @param $post_id (int) -- The ID of the post we're checking access for
     * @return bool -- true | false -- Indicates user ID's access privileges to the post/sequence
     */
    function pmpro_sequence_hasAccess($user_id, $post_id, $isAlert = false)
    {
        //is this post in a sequence
        $post_sequence = get_post_meta($post_id, "_post_sequences", true);


        if (empty($post_sequence)) {

            return true; //not in a sequence
        }

        // If the post isn't published there's no need to give access to it.
        $status = get_post_status($post_id);
/*
        if ( ( $status != 'publish' ) ||
             ( $status != 'private' ) ) {

            dbgOut("hasAccess() - Post hasn't been published yet!");
            return false;
        }
*/
        // Does the current user have a membership level giving them access to everything?
        $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id);

        if (!empty($all_access_levels) && pmpro_hasMembershipLevel($all_access_levels, $user_id)) {

            dbgOut("hasAccess() - This user has one of the all access membership levels");
            return true; //user has one of the all access levels
        }


        // Iterate through all sequences that the $post_id is included in
        foreach ($post_sequence as $sequence_id) {

	        dbgOut('hasAccess() - Processing for sequence: ' . $sequence_id);

	        $results = pmpro_has_membership_access($sequence_id, $user_id, true); //Using true to return all level IDs that have access to the sequence

	        if ($results[0] === false) { // First item in results array == true if user has access

		        dbgOut( 'hasAccess() - User ' . $user_id . ' does NOT have access to post ' . $post_id . ' in sequence ' . $sequence_id );
		        continue;
            }

	        $sequence = new PMProSequence($sequence_id);

            // Get the preview offset (if it's defined). If not, set it to 0 ( for compatibility )
            if ( empty( $sequence->options->previewOffset ) ) {

                $sequence->options->previewOffset = 0;
                dbgOut('Saving settings due to initial config of previewOffset for post # ' . $post_id);
                $sequence->save_sequence_meta(); // Save the settings (only the first time we check this variable, if it's empty)
            }

            // Check if the post exists in the list of posts for the current sequence & return its details if true
            if ( ( $sp = $sequence->get_postDetails( $post_id ) ) !== null ) {

	            // Verify for all levels given access to this post
	            foreach ( $results[1] as $level_id ) {

		            if ( $sequence->options->delayType == 'byDays' ) {

			            //user has access to this sequence and has been at the level for longer than this post's delay
			            // $durationOfMembership = pmpro_getMemberDays( $user_id, $level_id ); // Buggy (returns decimal number for day count)

			            // Don't add 'preview' value if this is for an alert notice.
			            if (! $isAlert)
			                $durationOfMembership = pmpro_sequence_getMemberDays( $user_id, $level_id ) + $sequence->options->previewOffset;
			            else
				            $durationOfMembership = pmpro_sequence_getMemberDays( $user_id, $level_id );

			            dbgOut( sprintf('hasAccess() - Member %d has been active at level %d for %f days', $user_id, $level_id, $durationOfMembership) );

			            if ( $durationOfMembership >= $sp->delay ) {
				            return true;
			            }

		            } elseif ( $sequence->options->delayType == 'byDate' ) {

			            // Don't add 'preview' value if this is for an alert notice.
			            if (! $isAlert)
			                $previewAdd = ((60*60*24) * $sequence->options->previewOffset);
			            else
				            $previewAdd = 0;

			            $today = date( __( 'Y-m-d', 'pmprosequence' ), (current_time( 'timestamp' ) + $previewAdd) );

			            if ( $today >= $sp->delay ) {
				            return true;
			            }
		            } // EndIf for delayType
	            } // End of foreach -> $level_id
            } // EndIF
        } // End of foreach

        dbgOut("hasAccess() - User does NOT have access to post {$post_id} in sequence {$sequence_id}" );

        // Haven't found anything yet, so must not have access.
        return false;

    } // End of function

endif;

if ( ! function_exists( 'pmpro_sequence_pmpro_has_membership_access_filter' ) ):

add_filter("pmpro_has_membership_access_filter", "pmpro_sequence_has_membership_access_filter", 10, 4);

    /*
        Filter pmpro_has_membership_access based on sequence access.
    */
    /**
     * Filter pmpro_has_membership_access based on sequence access.
     *
     * @param $hasaccess (bool) -- Current access status
     * @param $mypost (int) -- The post we're processing
     * @param $myuser (int) -- The user ID we're testing
     * @param $post_membership_levels -- The membership level(s) we're testing against
     *
     * @return bool -- True if access is granted, false if not
     */
    function pmpro_sequence_has_membership_access_filter($hasaccess, $mypost, $myuser, $post_membership_levels)
    {
        // dbgOut("Running membership_access_filter");

        //If the user doesn't have access already, we won't change that. So only check if they already have access.
        if($hasaccess)
        {

            //okay check if the user has access
            if(pmpro_sequence_hasAccess($myuser->ID, $mypost->ID)) {
                $hasaccess = true;
            }
            else {
                $hasaccess = false;
            }
        }

        return $hasaccess;
}

endif;

if ( ! function_exists( 'pmpro_seuquence_text_filter' )):

    add_filter("pmpro_non_member_text_filter", "pmpro_seuquence_text_filter");
    add_filter("pmpro_not_logged_in_text_filter", "pmpro_seuquence_text_filter");

    /**
     * Filter the message for users without access.
     *
     * @param $text (string) -- The text to filter
     * @return string -- the filtered text
     */
    function pmpro_seuquence_text_filter($text)
    {
        global $current_user, $post;

        dbgOut("Running text_filter");

        if(!empty($current_user) && !empty($post))
        {
            if(!pmpro_sequence_hasAccess($current_user->ID, $post->ID))
            {
	            $post_sequence = get_post_meta($post->ID, "_post_sequences", true);

                //Update text. The user either will have to wait or sign up.
                $insequence = false;

                foreach($post_sequence as $ps)
                {
                    if(pmpro_has_membership_access($ps))
                    {
                        dbgOut("User may have access to: {$ps} ");
                        $insequence = $ps;
	                    $sequence = new PMProSequence($ps);
                        $delay = $sequence->getDelayForPost($post->ID);
	                    break;
                    }
                }

                if($insequence)
                {
                    //user has one of the sequence levels, find out which one and tell him how many days left
	                $text = sprintf("%s<br/>", sprintf( __("This content managed as part of the <a href='%s'>%s</a> sequence", 'pmprosequence'), get_permalink($ps), get_the_title($ps)) );

	                switch ($sequence->options->delayType) {

                        case 'byDays':

                            switch ( $sequence->options->showDelayAs ) {

                                case PMPRO_SEQ_AS_DAYNO:

                                    $text .= sprintf( __( 'You will get access to this content ("%s") on day %s of your membership', 'pmprosequence' ), get_the_title( $post->ID ), $sequence->displayDelay( $delay ) );
                                    break;

                                case PMPRO_SEQ_AS_DATE:

                                    $text .= sprintf( __( 'You will get access to this content ("%s") on %s', 'pmprosequence' ), get_the_title( $post->ID ), $sequence->displayDelay( $delay ) );
                                    break;
                            }

                            break;

		                case 'byDate':
			                $text .= sprintf( __('You will get access to this content ("%s") on %s', 'pmprosequence'), get_the_title($post->ID), $delay );
			                break;
		                default:

	                }

                }
                else
                {
                    // User has to sign up for one of the sequence(s)
                    if(count($post_sequence) == 1)
                    {
	                    $text = sprintf("%s<br/>", sprintf( __("This content is part of the <a href='%s'>%s</a> sequence", 'pmprosequence'), get_permalink($post_sequence[0]), get_the_title($post_sequence[0])) );
                    }
                    else
                    {
                        $text = sprintf("<p>%s</p>", __('This content is part of the following sequences: ', 'pmprosequence'));
                        $seq_links = array();

                        foreach($post_sequence as $sequence_id) {
                            $seq_links[] = "<p><a href='" . get_permalink($sequence_id) . "'>" . get_the_title($sequence_id) . "</a></p>";
                        }

                        $text .= implode( $seq_links);
                    }
                }
            }
        }

        return $text;
    }
endif;

if ( !  function_exists('pmpro_seqeuence_included_cpts')):

	add_filter('pmpro_sequencepost_types', 'pmpro_seqeuence_included_cpts');

	/**
	 * Get a list of Custom Post Types to include in the list of available posts for a sequence (drip)
	 *
	 * @param $defaults -- Default post types to include (regardless)
	 *
	 * @return array -- Array of publicly available post types
	 */
	function pmpro_seqeuence_included_cpts( $defaults ) {

		$cpt_args = array(
			'public'                => true,
			'exclude_from_search'   => false,
			'_builtin'              => false,
		);

		$output = 'names';
		$operator = 'and';

		$post_types = get_post_types($cpt_args, $output, 'and');
		$postTypeList = array();

		foreach ($post_types as $post_type) {
			$postTypeList[] = $post_type;
		}

		return array_merge( $defaults, $postTypeList);
	}
endif;

/**
 * Filter to replace the !!excerpt_intro!! variable content in a "new content alert" message.
 */
if ( ! function_exists('pmpro_sequence_email_body')):

	add_filter("pmpro_after_phpmailer_init", "pmpro_sequence_email_body");

	/**
	 * Changes the content of the following placeholders as described:
	 *
	 *  !!excerpt_intro!! --> The introduction to the excerpt (Configure in "Sequence" editor ("Sequence Settings pane")
	 *  !!lesson_title!! --> The title of the lesson/post we're emailing an alert about.
	 *  !!today!! --> Today's date (in the configured format).
	 *
	 * @param $phpmailer -- PMPro Mail object (contains the Body of the message)
	 */
	function pmpro_sequence_email_body( $phpmailer )
	{
	//	dbgOut('email_body filter() -  Mailer Obj contains: ' . print_r($phpmailer, true));

		$phpmailer->Body = str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body );
		// $phpmailer->Body = str_replace( "!!today!!", date($phpmailer->dateformat, current_time('timestamp')), $phpmailer->Body );
		$phpmailer->Body = str_replace( "!!ptitle!!", $phpmailer->ptitle , $phpmailer->Body );

	}
endif;

if ( ! function_exists( 'pmpro_seq_datediff') ):

	/**
	 *
	 * Calculates the difference between two dates (specified in UTC seconds)
	 *
	 * @param $startdate (timestamp) - timestamp value for start date
	 * @param $enddate (timestamp) - timestamp value for end date
	 * @return int
	 */
	function pmpro_seq_datediff( $startdate, $enddate = null ) {

		// use current day as $enddate if nothing is specified
		if (! $enddate)
			$enddate = current_time('timestamp');

		// Create two DateTime objects
		$dStart = new DateTime( date( 'Y-m-d', $startdate ) );
		$dEnd   = new DateTime( date( 'Y-m-d', $enddate ) );

		if ( version_compare( PHP_VERSION, PMPRO_SEQ_REQUIRED_PHP_VERSION, '>=' ) ) {

			/* Calculate the difference using 5.3 supported logic */
			$dDiff  = $dStart->diff( $dEnd );
			$dDiff->format( '%d' );
			//$dDiff->format('%R%a');

			$days = $dDiff->days;

			// Invert the value
			if ( $dDiff->invert == 1 )
				$days = 0 - $days;
		}
		else {

			// V5.2.x workaround
			$dStartStr = $dStart->format('U');
			$dEndStr = $dEnd->format('U');

			// Difference (in seconds)
			$diff = abs($dStartStr - $dEndStr);

			// Convert to days.
			$days = $diff * 86400;

			// Sign flip if needed.
			if ( gmp_sign($dStartStr - $dEndStr) == -1)
				$days = 0 - $days;
		}

		return $days;
	}
endif;

/**
  *	Couple functions from PMPro in case we don't have them loaded yet.
  */
if( ! function_exists("pmpro_getMemberStartdate") ):

    /**
     *
     * Get the member's start date (either generally speaking or for a specific level)
     *
     * @param $user_id (int) - ID of user who's start date we're finding
     * @param $level_id (int) - ID of the level to find the start date for (optional)
     *
     * @returns mixed - The start date for this user_id at the specific level_id (or in general)
     */
    function pmpro_getMemberStartdate($user_id = NULL, $level_id = 0)
	{		
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}

		global $pmpro_startdates;	//for cache

		if(empty($pmpro_startdates[$user_id][$level_id]))
		{			
			global $wpdb;
			
			if(!empty($level_id))
				$sqlQuery = $wpdb->prepare(
					"
						SELECT UNIX_TIMESTAMP(startdate)
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND membership_id IN ( %d ) AND user_id = %d
						ORDER BY id LIMIT 1
					",
					'active',
					$level_id,
					$user_id
				);
			else
				$sqlQuery = $wpdb->prepare(
					"
						SELECT UNIX_TIMESTAMP(startdate)
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND user_id = %d
						ORDER BY id LIMIT 1
					",
					'active',
					$user_id
				);
				
			$startdate = apply_filters("pmpro_member_startdate", $wpdb->get_var($sqlQuery), $user_id, $level_id);
			
			$pmpro_startdates[$user_id][$level_id] = $startdate;
		}
		
		return $pmpro_startdates[$user_id][$level_id];
	}

    /**
     * Calculate the # of days since the membership level (or membership in general) was started for a specific user_id
     *
     * @param int $user_id -- user_id to calculate # of days since membership start for
     * @param int $level_id -- level_id to calculate the # of days for
     * @return int -- Number of days since user_id started their membership (at this level)
     */
    function pmpro_getMemberDays($user_id = NULL, $level_id = 0)
	{
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		global $pmpro_member_days;

		if(empty($pmpro_member_days[$user_id][$level_id]))
		{
			// Get the timestamp representing the start date for the specific user_id.
			$startdate = pmpro_getMemberStartdate($user_id, $level_id);

			// Check that there is a start date at all
			if(empty($startdate))
				$days = 0;
			else
				$days = pmpro_seq_datediff($startdate, current_time('timestamp'));

			$pmpro_member_days[$user_id][$level_id] = $days;
		}
		
		return $pmpro_member_days[$user_id][$level_id];
	}

endif;

if ( ! function_exists( 'sequence_post_type_icon' )):

    add_action( 'admin_head', 'sequence_post_type_icon' );

    /**
     * Configure & display the icon for the Sequence Post type (in the Dashboard)
     */
    function sequence_post_type_icon() {
        ?>
        <style>
            /* Admin Menu - 16px */
            #menu-posts-pmpro_sequence .wp-menu-image {
                background: url(<?php echo plugins_url('images/icon-sequence16-sprite.png', __FILE__); ?>) no-repeat 6px 6px !important;
            }
            #menu-posts-pmpro_sequence:hover .wp-menu-image, #menu-posts-pmpro_sequence.wp-has-current-submenu .wp-menu-image {
                background-position: 6px -26px !important;
            }
            /* Post Screen - 32px */
            .icon32-posts-pmpro_sequence {
                background: url(<?php echo plugins_url('images/icon-sequence32.png', __FILE__); ?>) no-repeat left top !important;
            }
            @media
            only screen and (-webkit-min-device-pixel-ratio: 1.5),
            only screen and (   min--moz-device-pixel-ratio: 1.5),
            only screen and (     -o-min-device-pixel-ratio: 3/2),
            only screen and (        min-device-pixel-ratio: 1.5),
            only screen and (                min-resolution: 1.5dppx) {

                /* Admin Menu - 16px @2x */
                #menu-posts-pmpro_sequence .wp-menu-image {
                    background-image: url(<?php echo plugins_url('images/icon-sequence16-sprite_2x.png', __FILE__); ?>) !important;
                    -webkit-background-size: 16px 48px;
                    -moz-background-size: 16px 48px;
                    background-size: 16px 48px;
                }
                /* Post Screen - 32px @2x */
                .icon32-posts-pmpro_sequence {
                    background-image:url(<?php echo plugins_url('images/icon-sequence32_2x.png', __FILE__); ?>) !important;
                    -webkit-background-size: 32px 32px;
                    -moz-background-size: 32px 32px;
                    background-size: 32px 32px;
                }
            }
        </style>
    <?php }
endif;

if ( ! function_exists('pmpro_sequence_activation')):

    register_activation_hook( __FILE__, 'pmpro_sequence_activation' );

    /*
        We need to flush rewrite rules on activation/etc for the CPTs.
    */
    function pmpro_sequence_activation()
    {
        PMProSequence::createCPT();
        flush_rewrite_rules();

	    /* Search for existing pmpro_series posts & import */

	    /* Register the default cron job to send out new content alerts */
	    wp_schedule_event(current_time('timestamp'), 'daily', 'pmpro_sequence_cron_hook');
    }

endif;

if ( ! function_exists( 'pmpro_sequence_deactivation' )):

    register_deactivation_hook( __FILE__, 'pmpro_sequence_deactivation' );

    function pmpro_sequence_deactivation()
    {
        global $pmpros_deactivating, $wpdb;
        $pmpros_deactivating = true;
        flush_rewrite_rules();

	    // Easiest is to iterate through all Sequence IDs and set the setting to 'sendNotice == 0'
	    /* Hack: Disable all sequence alerts */
	    $sql = $wpdb->prepare(
		    "
		        SELECT *
		        FROM {$wpdb->posts}
		        WHERE post_type = 'pmpro_sequence'
	    	"
	    );

	    $seqs = $wpdb->get_results($sql);

	    // Iterate through all sequences and disable any cron jobs causing alerts to be sent to users
	    foreach($seqs as $s) {

		    $sequence = new PMProSequence($s->ID);

		    if ($sequence->options->sendNotice == 1) {

			    // Set the alert flag to 'off'
			    $sequence->options->sendNotice = 0;

			    // save meta for the sequence.
			    $sequence->save_sequence_meta();

			    wp_clear_scheduled_hook('pmpro_sequence_cron_hook', array( $s->ID ));
			    dbgOut('Deactivated email alert(s) for sequence ' . $s->ID);
		    }
	    }

	    /* Unregister the default Cron job for new content alert(s) */
        wp_clear_scheduled_hook('pmpro_sequence_cron_hook');
    }
endif;

if ( ! function_exists('pmpro_sequence_links_shortcode')):

    function pmpro_sequence_links_shortcode( $attributes ) {

	    global $current_user;

	    // To avoid errors in development tool
        $highlight = false;
	    $button = false;
	    $scrollbox = false;
	    $pagesize = 10;
        $id = 0;
		$title = null;

        extract( shortcode_atts( array(
            'id' => 0,
	        'pagesize' => 0,
	        'title' => '',
	        'button' => false,
            'highlight' => false,
	        'scrollbox' => false,
        ), $attributes ) );

	    if ($pagesize == 0)
		    $pagesize = 15; // Default

	    if ($id == 0) {
		    global $wp_query;
		    // Try using the current WP post ID
		    if (! empty($wp_query->post->ID))
			    $id = $wp_query->post->ID;
		    else
			    return ''; // No post given so returning no info.
	    }

	    dbgOut("shortcode() - Ready to build link list for sequence with ID of: " . $id);

	    if ( pmpro_sequence_hasAccess( $current_user->ID, $id, false ) )
            return pmpro_sequence_createSequenceList( $id, $highlight, $pagesize, $button, $title, $scrollbox);
	    else {

            return '';
        }
    }
endif;

if ( ! function_exists('pmpro_sequence_member_links_bottom')):

	// add_action('pmpro_member_links_bottom', 'pmpro_sequence_member_links_bottom');

	/**
	 * Add series post links to the account page for the user.
     *
     * @param $seq_id (int) -- ID (page ID) of the sequence to process
	 */

	function pmpro_sequence_member_links_bottom( $seq_id = 0 ) {

		// TODO: Add admin configurable setting to allow/disallow showing of the link data on member page
		dbgOut('Listing Sequence pages as Member Links');
        echo pmpro_sequence_createSequenceList(7888, true, 22, true, null, false);

	}

    /**
     * Create a list of posts/pages/cpts that are included in the specified sequence (or all sequences, if needed)
     *
     * @param int $seq_id -- The ID for the sequence to process (can be empty. If so, process all sequences)
     * @param bool $highlight -- Whether to highlight the Post that is the closest to the users current membership day
     * @param int $pagesize -- The size of each page (number of posts per page)
     * @param bool $button -- Whether to display a "Available Now" button or not.
     * @param string $title -- The title of the sequence list. Default is the title of the sequence.
     * @return string -- The HTML we generated.
     */
	function pmpro_sequence_createSequenceList(
		$seq_id = 0,
		$highlight = false,
		$pagesize = 0,
		$button = false,
		$title = null,
		$scrollbox = false
	) {

		global $wpdb, $current_user, $id;
		$html = '';

		// Set a default page size.
		if ($pagesize == 0)
			$pagesize = 15;

		// Process the title attribute (default values, can apply filter if needed/wanted)
		if ( ( $title == '' ) && ( $seq_id != 0 ))
			$title = '<h3>' . get_the_title($seq_id) . '</h3>';
		elseif (($seq_id == 0) && ($title == ''))
			$title = "<h3>Sequence List</h3>";
		elseif ($title == '')
			$title = '';
		else
			$title = "<h3>{$title}</h3>";

        if ( $seq_id == 0) {
	        dbgOut('No sequence ID provided. Listing all sequences');
	        //get all series
	        $sql = $wpdb->prepare(
		        "
	                SELECT *
	                FROM {$wpdb->posts}
	                WHERE post_type = 'pmpro_sequence'
            	"
	        );
        } else {
	        dbgOut('Loading data for the "' . get_the_title($seq_id) . '" sequence');
            $sql = $wpdb->prepare(
	            "
	                SELECT *
	                FROM {$wpdb->posts}
	                WHERE post_type = 'pmpro_sequence' AND ID = %d
	            ",
                $seq_id
            );
        }

		// dbgOut('SQL to load sequences' . print_r($sql, true));
		$seqs = $wpdb->get_results($sql);

		// Process the list of sequences
		foreach($seqs as $s) {

            $sequence = new PMProSequence( $s->ID );

            $sequence_posts = $sequence->getPosts();
            $memberDayCount = pmpro_sequence_getMemberDays();

            dbgOut( "Number of posts in sequence: " . count( $sequence_posts ) . " number of days as member: " . $memberDayCount );

            if ( ! pmpro_sequence_hasAccess( $current_user->ID, $s->ID ) ) {
                dbgOut( 'No access to sequence ' . $s->ID . ' for user ' . $current_user->ID );
                continue;
            }

            $post_list = array();

            // Generate a list of posts for the sequence (used in WP_Query object)
            foreach ( $sequence_posts as $sequence_post ) {

                if ( pmpro_sequence_HasAccess( $current_user->ID, $sequence_post->id ) ) {
                    $post_list[] = $sequence_post->id;
                }
            }

            /* Get the ID of the post in the sequence who's delay is the closest
             *  to the members 'days since start of membership'
             */
            $closestPostId = apply_filters( 'pmpro_seq_find_closest_post', $sequence->get_closestPost( $current_user->ID ) );

            // Image to bring attention to the closest post item
            $closestPostImg = '<img src="' . plugins_url( '/images/most-recent.png', __FILE__ ) . '" >';

            dbgOut( 'build_linkData() - The most recently available post for user #' . $current_user->ID . ' is post #' . $closestPostId );

            $query_args = array(
                'post_type'           => apply_filters( 'pmpro_sequencepost_types', array( 'post', 'page' ) ),
                // Filter returns an array()
                'post__in'            => $post_list,
                'ignore_sticky_posts' => 1,
                'paged'               => ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1,
                'posts_per_page'      => $pagesize,
                'orderby'             => 'post__in'
            );

            $seqEntries = new WP_Query( apply_filters( 'pmpro_sequence_list_query', $query_args ) );

            $listed_postCnt   = 0;
            $noPostsDisplayed = true;

            ob_start();
            ?>

            <!-- Preface the table of links with the title of the sequence -->
        <div id="pmpro_sequence-<?php echo $sequence->sequence_id; ?>" class="pmpro_sequence_list">

            <?php echo apply_filters( 'pmpro_seq_list_title', $title ); ?>

            <!-- Add opt-in to the top of the shortcode display. -->
            <?php echo $sequence->pmpro_sequence_addUserNoticeOptIn(); ?>

            <!-- List of sequence entries (paginated as needed) -->
            <?php
            if ( empty( $seqEntries ) ) {

                echo "<p>" . __( "There is no content available at this time", "pmprosequence" ) . "</p>";

            } else {
                if ( $scrollbox ) { ?>
                    <div id="pmpro-seq-post-list">
                    <table class="pmpro_sequence_postscroll pmpro_seq_linklist">
          <?php } else { ?>
                    <div>
                    <table class="pmpro_seq_linklist">
          <?php };

            /**
             * Flow of post display:
             *      Iterate through all the posts that belong to the sequence
             *         if isPastDelay() == true then
             *              $noPostsDisplayed = false;
             *              if ( ( $id == $closestPostId ) && ( $highlight ) )
             *                  Show post link with 'Current' value & highlight CSS . ( $button ? 'Available Now' : '');
             *              else
             *                  Show post link as normal . ( $button ? 'Available Now' : '')
             *         elseif (! isPastDelay()) &&  (! hideUpcomingPosts() )
             *              $noPostsDisplayed = false;
             *              if ( ($id == $closestPostId) && ( $highlight ) )
             *                    Show post with dashed underline (in highlight color);
             *              else
             *                  Show post link as normal;
             *         elseif ($noPostsDisplayed && (! isPastDelay()) // Not allowed to show any posts)
             *               Show 'no posts available' message
             *
             *
             */
            // Loop through all of the posts in the sequence
            while ( $seqEntries->have_posts() ) : $seqEntries->the_post();

                // Should the current post be highlighted?
                if ( ( $sequence->isPastDelay( $memberDayCount,
                    $sequence_posts[ $sequence->getPostKey( $id ) ]->delay ) )
                ) {

                    $noPostsDisplayed = false;
                    $listed_postCnt ++;

                    if ( ( $id == $closestPostId ) && ( $highlight ) ) {
                        // Show the highlighted post info
                        ?>
                        <tr id="pmpro-seq-selected-post">
                            <td class="pmpro-seq-post-img"><?php echo apply_filters( 'pmpro_seq_closestpost_img', $closestPostImg ); ?></td>
                            <td class="pmpro-seq-post-hl">
                                <a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>"><strong><?php the_title(); ?></strong>&nbsp;&nbsp;<em>(Current)</em></a>
                            </td>
                            <td <?php echo( $button ? 'class="pmpro-seq-availnow-btn"' : '' ); ?>><?php

                                if ( $button ) {
                                    ?>
                                <a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink(); ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                                } ?>
                            </td>
                        </tr> <?php
                    } else {
                        ?>
                        <tr id="pmpro-seq-post">
                            <td class="pmpro-seq-post-img">&nbsp;</td>
                            <td class="pmpro-seq-post-fade">
                                <a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>"><?php the_title(); ?></a>
                            </td>
                            <td<?php echo( $button ? ' class="pmpro-seq-availnow-btn">' : '>' );
                            if ( $button ) {
                                ?>
                            <a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink(); ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                            } ?>
                            </td>
                        </tr>
                    <?php
                    }
                } elseif ( ( ! $sequence->isPastDelay( $memberDayCount, $sequence_posts[ $sequence->getPostKey( $id ) ]->delay ) ) &&
                           ( ! $sequence->hideUpcomingPosts() )
                ) {

                    $noPostsDisplayed = false;
                    $listed_postCnt ++;

                    // Do we need to highlight the (not yet available) post?
                    if ( ( $id == $closestPostId ) && ( $highlight ) ) {
                        ?>

                        <tr id="pmpro-seq-post">
                            <td class="pmpro-seq-post-img">&nbsp;</td>
                            <td id="pmpro-seq-post-future-hl">
                                <?php dbgOut( "Highlight post #: {$id} with future availability" ); ?>
                                <span class="pmpro_sequence_item-title">
                                            <?php echo get_the_title(); ?>
                                        </span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $sequence->options->delayType == 'byDays' &&
                                                  $sequence->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $sequence->displayDelay( $sequence_posts[ $sequence->getPostKey( $id ) ]->delay ); ?>
                                        </span>
                            </td>
                            <td></td>
                        </tr>
                    <?php
                    } else {
                        ?>
                        <tr id="pmpro-seq-post">
                            <td class="pmpro-seq-post-img">&nbsp;</td>
                            <td>
                                <?php dbgOut( "Show upcoming post #: {$id}" ); ?>
                                <span class="pmpro_sequence_item-title"><?php echo get_the_title(); ?></span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $sequence->options->delayType == 'byDays' &&
                                                  $sequence->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $sequence->displayDelay( $sequence_posts[ $sequence->getPostKey( $id ) ]->delay ); ?>
                                        </span>
                            </td>
                            <td></td>
                        </tr> <?php
                    }
                } else {
                    if ( ( count( $post_list ) > 0 ) && ( $listed_postCnt > 0 ) ) {
                        ?>
                        <tr id="pmpro-seq-post">
                            <td>
                                <span style="text-align: center;">There is <em>no content available</em> for you at this time. Please check back later.</span>
                            </td>
                        </tr><?php
                    }
                }

                // Show as 'pmpro_sequence_item-unavailable' if there's no access to the post (account for preview).
                /*						if (! pmpro_sequence_hasAccess( $current_user->ID, $id, false )) {
                                            // Skip to the next post?
                                            continue;
                                        }
                */
            endwhile;

               ?></table>
            </div>
            <div class="clear"></div>
            <?php
                apply_filters( 'pmpro_seq_paginate_list', pmpro_seq_paging_nav( $seqEntries->max_num_pages ) );
                wp_reset_postdata();

            }
            ?>
            </div><?php

            $html .= ob_get_contents();
            ob_end_clean();

            return apply_filters( 'pmpro_sequence_list_html', $html );
        }
	}
endif;

if (! function_exists( 'pmpro_seq_paging_nav()')):

	function pmpro_seq_paging_nav( $total ) {

		if ($total > 1) {

			if (! $current_page = get_query_var( 'page' ) )
				$current_page = 1;

			$paged = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
			$base = @add_query_arg('page','%#%');
			$format = '?page=%#%';

			$prev_arrow = is_rtl() ? '&rarr;' : '&larr;';
			$next_arrow = is_rtl() ? '&larr;' : '&rarr;';

			?>
			<nav class="navigation paging-navigation" role="navigation">
				<h4 class="screen-reader-text"><?php _e( 'Link Navigation', 'pmprosequence' ); ?></h4>
				<?php echo paginate_links( array(
					'base'          => $base,
					'format'        => $format,
					'total'         => $total,
					'current'       => $paged,
					'mid_size'      => 2,
					'prev_text'     => sprintf( __( '%s Previous', 'pmprosequence'), $prev_arrow),
					'next_text'     => sprintf( __( 'Next %s', 'pmprosequence'), $next_arrow),
					'prev_next'     => true,
					'type'          => 'list',
					'before_page_number' => '<span class="screen-reader-text">' . __('Page', 'pmprosequence') . '</span>',
				)); ?>
			</nav>
		<?php

		}
	}
	endif;

if ( ! function_exists ('pmpro_seq_import_series') ):

    function pmpro_seq_import_series() {


    }
endif;

$sequence = new PMProSequence();
