<?php
/**
 *  Copyright (c) 2014-2019. - Eighty / 20 Results by Wicked Strong Chicks.
 *  ALL RIGHTS RESERVED
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Sequence;

use E20R\Tools\DBG;

class Sequence_Views {

	private static $_this = null;

	function __construct() {

		if ( null !==  self::$_this ) {
			$error_message = sprintf(__("Attempted to load a second instance of a singleton class (%s)", "e20r-sequences"),
				get_class($this)
			);

			error_log($error_message);
			wp_die( $error_message);
		}

		self::$_this = $this;

	}

	public static function get_instance() {

		if (null == self::$_this) {
			self::$_this = new self;
		}

		DBG::log("Loading instance for views class");
		return self::$_this;
	}

	/**
	 * Used to label the post list in the metabox
	 *
	 * @param $post_state -- The current post state (Draft, Scheduled, Under Review, Private, other)
	 *
	 * @return null|string -- Return the correct postfix for the post
	 *
	 * @access private
	 */
	private function set_post_status( $post_state )
	{
		$txt_state = null;

		switch ($post_state)
		{
			case 'draft':
				$txt_state = __('-DRAFT', "e20r-sequences");
				break;

			case 'future':
				$txt_state = __('-SCHED', "e20r-sequences");
				break;

			case 'pending':
				$txt_state = __('-REVIEW', "e20r-sequences");
				break;

			case 'private':
				$txt_state = __('-PRIVT', "e20r-sequences");
				break;

			default:
				$txt_state = '';
		}

		return $txt_state;
	}

	/**
	 * Refreshes the Post list for the sequence
	 *
	 * @access public
	 * TODO: Make this responsive!
	 */
	public function get_post_list_for_metabox( $force = false ) {

		DBG::log("Generating sequence content metabox for back-end");
		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		//show posts
		$posts = $sequence->load_sequence_post( null, null, null, '=', null, $force, 'any' );
		$all_posts = $sequence->get_posts_from_db();

		DBG::log('Displaying the back-end meta box content');

		ob_start();
		if ( empty( $posts ) ) {
            DBG::log('No Posts found?');
            $sequence->set_error_msg( __('No posts/pages found', "e20r-sequences") );
		}
		$has_error = $sequence->get_error_msg();
		?>

		<?php if(!empty( $has_error )) {
		    DBG::log("Found error: " . print_r( $has_error, true ));
		    $sequence->display_error(); ?>
		<?php } ?>
		<table id="e20r_sequencetable" class="e20r_sequence_postscroll wp-list-table widefat">
			<thead>
			<tr>
			<th class="e20r_sequence_orderlabel"><?php // _e('Order', "e20r-sequences" ); ?></label></th>
			<th class="e20r_sequence_titlelabel"><?php _e('Title', "e20r-sequences"); ?></th>
			<th class="e20r_sequence_idlabel"><?php _e('ID', "e20r-sequences"); ?></th>
			<?php if ($options->delayType == 'byDays'): ?>
				<th id="e20r_sequence_delaylabel"><?php _e('Delay', "e20r-sequences"); ?></th>
			<?php elseif ( $options->delayType == 'byDate'): ?>
				<th id="e20r_sequence_delaylabel"><?php _e('Avail. On', "e20r-sequences"); ?></th>
			<?php else: ?>
				<th id="e20r_sequence_delaylabel"><?php _e('Not Defined', "e20r-sequences"); ?></th>
			<?php endif; ?>
			<th class="e20r_edit_label_big"></th>
			<?php if ( false == $options->allowRepeatPosts ) { ?><th class="e20r_edit_label_small"></th><?php } ?>
			<th class="e20r_edit_label_big"></th>
			</tr>
			</thead>
			<tbody>
			<?php
			$count = 1;

			if ( ! empty($posts ) ) {
				foreach( $posts as $post ) {
					?>
					<tr>
						<td class="e20r_sequence_tblOrder"><?php echo $count; ?>.</td>
						<td class="e20r_sequence_tblPostname"><?php echo ( get_post_status( $post->id ) == 'draft' ? sprintf( "<strong>%s</strong>: ", __("DRAFT", "e20r-sequences" ) ) : null ) . get_the_title($post->id); ?></td>
						<td class="e20r_sequence_tblPostId"><?php printf( __("(ID: %d)", "e20r-sequences" ), esc_attr( $post->id )); ?></td>
						<td class="e20r_sequence_tblNumber"><?php esc_attr_e( $post->delay ); ?></td>
						<td class="e20r_edit_label_big"><?php
							if ( true == $options->allowRepeatPosts ) { ?>
								<a href="javascript:e20r_sequence_editPost( <?php printf( "%s, %s", esc_attr( $post->id ), esc_attr( $post->delay ) ); ?> ); void(0); "><?php _e('Edit',"e20r-sequences"); ?></a><?php
							}
							else { ?>
								<a href="javascript:e20r_sequence_editPost( <?php printf( "%s, %s", esc_attr( $post->id ), esc_attr( $post->delay ) ); ?> ); void(0); "><?php _e('Post',"e20r-sequences"); ?></a><?php
							} ?>
						</td>
						<?php
							if ( false == $options->allowRepeatPosts ) { ?>
						<td class="e20r_edit_label_small">
								<a href="javascript:e20r_sequence_editEntry( <?php printf( "%s, %s", esc_attr( $post->id ), esc_attr( $post->delay ) ); ?> ); void(0);"><?php _e('Edit', "e20r-sequences"); ?></a>
						</td><?php
							} ?>
						<td class="e20r_edit_label_big">
							<a href="javascript:e20r_sequence_removeEntry( <?php printf( "%s, %s", esc_attr( $post->id ), esc_attr( $post->delay ) ); ?>); void(0);"><?php _e('Remove', "e20r-sequences"); ?></a>
						</td>
					</tr><?php

					$count++;
				}
			}
			?>
			</tbody>
		</table>

		<div id="postcustomstuff">
			<div class="e20r-sequence-float-left"><strong><?php _e('Add/Edit Posts:', "e20r-sequences"); ?></strong></div>
			<div class="e20r-sequence-float-right"><button class="primary-button button e20r-sequences-clear-cache"><?php _e("Clear cache", "e20r-sequences");?></button></div>
			<div id="newmeta" class="e20r-meta-table">
				<div class="e20r-table-head clear">
					<div class="table_newmeta e20r-sequence-full-row row heading">
						<div class="table_newmeta e20r-meta-table-col-1 cell"><?php _e('Post/Page', "e20r-sequences"); ?></div>
						<?php if ($options->delayType == 'byDays'): ?>
							<div class="table_newmeta e20r-meta-table-col-2 cell" id="e20r_sequence_delayentrylabel"><label for="e20r_sequencedelay"><?php _e('Days to delay', "e20r-sequences"); ?></label></div>
						<?php elseif ( $options->delayType == 'byDate'): ?>
							<div class="table_newmeta e20r-meta-table-col-2 cell"  id="e20r_sequence_delayentrylabel"><label for="e20r_sequencedelay"><?php _e("Release on (YYYY-MM-DD)", "e20r-sequences"); ?></label></div>
						<?php else: ?>
							<div class="table_newmeta e20r-meta-table-col-2 cell" id="e20r_sequence_delayentrylabel"><label for="e20r_sequencedelay"><?php _e('Not Defined', "e20r-sequences"); ?></label></div>
						<?php endif; ?>
						<div class="table_newmeta e20r-meta-table-col-3 cell e20r-empty"></div>
					</div>
				</div>
				<div class="e20r-table-body clear">
					<div class="table_newmeta e20r-sequence-full-row row">
						<div class="table_newmeta e20r-meta-table-col-1 cell">
						<select id="e20r_sequencepost" name="e20r_sequencepost" style="width: 100%;">
							<option value=""></option>
							<?php
							if  ( $all_posts !== false ) {

								foreach( $all_posts as $post ) { ?>
									<option value="<?php echo $post->ID;?>">
									<?php echo esc_textarea($post->post_title);?> (#<?php esc_attr_e( $post->ID );?><?php echo esc_attr_e( $this->set_post_status( $post->post_status ) );?>)
									</option><?php
								}
							}
							else {
								$sequence->set_error_msg( __( 'No posts found in the database!', "e20r-sequences" ) );
								DBG::log('Error during database search for relevant posts');
							}
							?>
						</select>
					</div>
						<div class="table_newmeta e20r-meta-table-col-2 cell">
                            <input id="e20r_sequencedelay" name="e20r_sequencedelay" type="text" value="" size="7" />
                            <input id="e20r_sequence_id" name="e20r_sequence_id" type="hidden" value="<?php esc_attr_e( $sequence->sequence_id ); ?>" size="7" />
                            <?php wp_nonce_field('e20r-sequence-post', 'e20r_sequence_post_nonce'); ?>
					</div>
						<div class="table_newmeta e20r-meta-table-col-3 cell">
						<a class="button" id="e20r_sequencesave" onclick="javascript:e20r_sequence_addEntry(); return false;">
							<?php _e('Update Sequence', "e20r-sequences"); ?>
						</a>
					</div>
					</div>
				</div>
			</div>
		</div>
		<script>
			// e20r_sequence_admin_responsive();
		</script>
		<?php

		$html = ob_get_clean();

		$errors = $sequence->get_error_msg();
		$status = '';

		if ( !empty( $errors ) ) {

			DBG::log( "Errors: " . print_r( $errors , true ));
			foreach( $errors as $e ) {
				$status .= "{$e}<br/>";
			}
		}

		$status = is_array( $errors ) ? $status : $errors;
		$success = empty( $errors ) ? true : false;

		return array(
			'success' => $success,
			'message' => ( !$success ? $status : null ),
			'html' => $html,
		);
	}

	/**
	 * Defines the Admin UI interface for adding posts to the sequence
	 *
	 * @access public
	 * @since 4.3.3
	 */
	public function sequence_list_metabox() {

		DBG::log("Generating settings metabox for back-end");
		global $post;

		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();


		if ( !isset( $this->sequence_id ) /* || ( $this->sequence_id != $post->ID )  */ ) {
			DBG::log("Loading the sequence metabox for {$post->ID} and not {$sequence->sequence_id}");

			$options = $sequence->get_options( $post->ID );

			if ( !isset( $options->lengthVisisble ) ) {
				echo $sequence->get_error_msg();
			}
		}

		DBG::log('Load the post list meta box');
        $has_error = $sequence->get_error_msg();
        DBG::log("Has error? {$has_error}");
        
		// Instantiate the settings & grab any existing settings if they exist.
		?>
		<div id="e20r-seq-error"><?php echo ( !empty($has_error) ? $has_error : null ); ?></div>
		<div id="e20r_sequence_posts">
			<?php
			$box = $this->get_post_list_for_metabox();
			echo $box['html'];
			?>
		</div>
		<?php
	}

	public function view_sequence_error( $code ) {
	    
	    $errormsg = null;
	    
	    switch( $code ) {
	        case 'ERRNOSEQUENCEID':
	            $errormsg = __("No sequence to opt in for", "e20r-sequences");
	            break;
	    }
	    
	    ob_start();
	    ?>
	    <div class="notice-warning">
	        <?php printf( __("Please notify the webmaster (include message): %s", "e20r-sequences" ), $errormsg ); ?>
        </div>
	    <?php
	    return ob_get_clean();
	}
	/**
	 * Defines the metabox for the Sequence Settings (per sequence page/list) on the Admin page
	 *
	 * @param $object -- The class object (sequence class)
	 * @param $box -- The metabox object
	 *
	 * @access public
	 *
	 */
	// Old name: settings_meta_box
	public function settings( $object, $box ) {

		global $post;
		global $current_screen;

		$sequence = apply_filters('get_sequence_class_instance', null);

		$new_post = false;

		DBG::log("Post ID: {$post->ID} and Sequence ID: {$sequence->sequence_id}");

		if ( ( !isset( $sequence->sequence_id )  ) || ( $sequence->sequence_id != $post->ID ) ) {

			DBG::log("Using the post ID as the sequence ID {$post->ID} vs {$sequence->sequence_id}");
			$options = $sequence->get_options( $post->ID );

			if ( !isset( $options->lengthVisible ) ) {
				DBG::log("Unable to load options/settings for {$post->ID}");
				return;
			}
		}
		else {
			DBG::log('Not a valid Sequence ID, cannot load options');
			$sequence->set_error_msg( __('Invalid drip-feed sequence specified', "e20r-sequences") );
			return;
		}

		if( ( 'pmpro_sequence' == $current_screen->post_type ) && ( $current_screen->action == 'add' )) {
			DBG::log("Adding a new post so hiding the 'Send' for notification alerts");
			$new_post = true;
		}

		$def_email = apply_filters( 'e20r-sequence-get-membership-setting', $sequence->get_membership_setting("from_email"), "from_email" );
		$def_name = apply_filters( 'e20r-sequence-get-membership-setting', $sequence->get_membership_setting('from_name'), "from_name");
		// Buffer the HTML so we can pick it up in a variable.
		ob_start();

		?>
		<div class="submitbox" id="e20r_sequence_meta">
			<div id="minor-publishing">
				<input type="hidden" name="e20r_sequence_settings_noncename" id="e20r_sequence_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
				<input type="hidden" name="e20r-sequence_delayType" id="e20r-sequence_delayType" value="<?php esc_attr_e($options->delayType); ?>"/>
				<input type="hidden" name="hidden-e20r-sequence_wipesequence" id="hidden-e20r-sequence_wipesequence" value="0"/>
				<div id="e20r-sequences-settings-metabox" class="e20r-sequences-settings-table">
					<!-- Checkbox rows: Hide, preview & membership length -->
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" id="e20r-sequence_hideFuture" name="e20r-sequence_hideFuture" title="<?php _e('Hide unpublished / future posts for this sequence', "e20r-sequences"); ?>" <?php checked( $options->hideFuture, 1); ?> />
								<input type="hidden" name="hidden-e20r-sequence_hideFuture" id="hidden-e20r-sequence_hideFuture" value="<?php esc_attr_e($options->hideFuture); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit e20r-sequence-setting-col-2"><?php _e('Hide all future posts', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" id="e20r-sequence_showAdmin" name="e20r-sequence_showAdmin" title="<?php _e('Show all posts for this sequence to admin users', "e20r-sequences"); ?>" <?php checked( $options->showAdmin, true); ?> />
								<input type="hidden" name="hidden-e20r-sequence_showAdmin" id="hidden-e20r-sequence_showAdmin" value="<?php esc_attr_e($options->showAdmin); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit e20r-sequence-setting-col-2"><?php _e('Display all posts to Admin', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" id="e20r-sequence_includeFeatured" name="e20r-sequence_includeFeatured" title="<?php _e('Include featured image as thumbnail in sequence listing', "e20r-sequences"); ?>" <?php checked( $options->includeFeatured, true); ?> />
								<input type="hidden" name="hidden-e20r-sequence_includeFeatured" id="hidden-e20r-sequence_includeFeatured" value="<?php esc_attr_e($options->includeFeatured); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit e20r-sequence-setting-col-2"><?php _e('Show Featured Images for Posts', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" id="e20r-sequence_allowRepeatPosts" name="e20r-sequence_allowRepeatPosts" title="<?php _e('Allow the admin to repeat the same post/page with different delay values', "e20r-sequences"); ?>" <?php checked( $options->allowRepeatPosts, 1); ?> />
								<input type="hidden" name="hidden-e20r-sequence_allowRepeatPosts" id="hidden-e20r-sequence_allowRepeatPosts" value="<?php esc_attr_e($options->allowRepeatPosts); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit"><?php _e('Allow repeat posts/pages', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" id="e20r-sequence-checkbox_previewOffset" name="e20r-sequence-checkbox_previewOffset" title="<?php _e('Let the user see a number of days worth of technically unavailable posts as a form of &quot;sneak-preview&quot;', "e20r-sequences"); ?>" <?php echo ( $options->previewOffset != 0 ? ' checked="checked"' : '' ); ?> />
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit"><?php _e('Allow "preview" of sequence', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					<div class="e20r-sequence-offset e20r-sequence-hidden e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-offset">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-offset"><?php _e('Days of preview:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-offset-status" class="e20r-sequence-status"><?php echo ( $options->previewOffset == 0 ? 'None' : esc_attr( $options->previewOffset ) ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-offset" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Change the number of days to preview', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-offset e20r-sequence-settings-input e20r-sequence-hidden clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-offset e20r-sequence-full-row">
							<div id="e20r-seq-offset-select">
								<input type="hidden" name="hidden-e20r-sequence_previewOffset" id="hidden-e20r-sequence_previewOffset" value="<?php esc_attr_e($options->previewOffset); ?>" >
								<label for="e20r-sequence_previewOffset"></label>
								<select name="e20r-sequence_previewOffset" id="e20r-sequence_previewOffset">
									<option value="0"><?php _e("None", "e20rtracker");?></option>
									<?php foreach (range(1, 5) as $preview_offset) { ?>
										<option value="<?php esc_attr_e($preview_offset); ?>" <?php selected( intval($options->previewOffset), $preview_offset); ?> ><?php esc_attr_e( $preview_offset ); ?></option>
									<?php } ?>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-offset e20r-sequence-full-row">
							<p class="e20r-seq-offset">
								<a href="#" id="ok-e20r-seq-offset" class="save-e20rseq-offset button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-offset" class="cancel-e20rseq-offset button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div>
					</div>
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox"  value="1" id="e20r-sequence_lengthVisible" name="e20r-sequence_lengthVisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text', "e20r-sequences"); ?>" <?php checked( $options->lengthVisible, 1); ?> />
								<input type="hidden" name="hidden-e20r-sequence_lengthVisible" id="hidden-e20r-sequence_lengthVisible" value="<?php esc_attr_e($options->lengthVisible); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit"><?php _e("Show user membership length", "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3"></div>
						</div>
					</div>
					<div class="e20r-sequences-settings-row e20r-sequence-full-row">
						<hr style="width: 100%;"/>
					</div>
					<!-- Sort order, Delay type & Availability -->
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-sortorder e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-sequence_sortOrder"><?php _e('Sort order:', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-sort-status" class="e20r-sequence-status"><?php echo ( $options->sortOrder == SORT_ASC ? __('Ascending', "e20r-sequences") : __('Descending', "e20r-sequences") ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-sort" class="e20r-seq-edit e20r-sequence-setting-col-3">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Edit the list sort order', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-sortorder e20r-sequence-full-row">
							<div id="e20r-seq-sort-select">
								<input type="hidden" name="hidden-e20r-sequence_sortOrder" id="hidden-e20r-sequence_sortOrder" value="<?php echo ($options->sortOrder == SORT_ASC ? esc_attr( SORT_ASC ) : esc_attr( SORT_DESC ) ); ?>" >
								<label for="e20r-sequence_sortOrder"></label>
								<select name="e20r-sequence_sortOrder" id="e20r-sequence_sortOrder">
									<option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($options->sortOrder), SORT_ASC); ?> > <?php _e('Ascending', "e20r-sequences"); ?></option>
									<option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($options->sortOrder), SORT_DESC); ?> ><?php _e('Descending', "e20r-sequences"); ?></option>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-sortorder e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-sort" class="save-e20rseq-sortorder button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-sort" class="cancel-e20rseq-sortorder button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div><!-- end of row -->
					</div>
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-delaytype e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-sequence-delay"><?php _e('Delay type:', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-delay-status" class="e20r-sequence-status"><?php echo ($options->delayType == 'byDate' ? __('A date', "e20r-sequences") : __('Days after sign-up', "e20r-sequences") ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-delay" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Edit the delay type for this sequence', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence_delayType e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-delay-select">
								<input type="hidden" name="hidden-e20r-sequence_delayType" id="hidden-e20r-sequence_delayType" value="<?php echo ($options->delayType != '' ? esc_attr($options->delayType): 'byDays'); ?>" >
								<label for="e20r-sequence_delayType"></label>
								<!-- onchange="e20r-sequence_delayTypeChange(<?php echo esc_attr( $sequence->sequence_id ); ?>); return false;" -->
								<select name="e20r-sequence_delayType" id="e20r-sequence_delayType">
									<option value="byDays" <?php selected( $options->delayType, 'byDays'); ?> ><?php _e('Days after sign-up', "e20r-sequences"); ?></option>
									<option value="byDate" <?php selected( $options->delayType, 'byDate'); ?> ><?php _e('A date', "e20r-sequences"); ?></option>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence_delayType e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-delay-btns">
								<p class="e20r-seq-btns">
									<a href="#" id="ok-e20r-seq-delay" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
									<a href="#" id="cancel-e20r-seq-delay" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
								</p>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-seq-showdelayas e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-showdelayas"><?php _e("Show availability as:", "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-showdelayas-status" class="e20r-sequence-status"><?php echo ($options->showDelayAs == E20R_SEQ_AS_DATE ? __('Calendar date', "e20r-sequences") : __('Day of membership', "e20r-sequences") ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-showdelayas" class="e20r-seq-edit e20r-sequence-setting-col-3">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('How to indicate when the post will be available to the user. Select either "Calendar date" or "day of membership")', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-seq-showdelayas e20r-sequence-settings e20r-sequence-full-row">
							<!-- Only show this if 'hidden_e20r_seq_delaytype' == 'byDays' -->
							<input type="hidden" name="hidden_e20r_seq_showdelayas" id="hidden_e20r_seq_showdelayas" value="<?php echo ($options->showDelayAs == E20R_SEQ_AS_DATE ? esc_attr( E20R_SEQ_AS_DATE ) : esc_attr( E20R_SEQ_AS_DAYNO ) ); ?>" >
							<label for="e20r-sequence_showdelayas"></label>
							<select name="e20r-sequence_showdelayas" id="e20r-sequence_showdelayas">
								<option value="<?php echo E20R_SEQ_AS_DAYNO; ?>" <?php selected( $options->showDelayAs, E20R_SEQ_AS_DAYNO); ?> ><?php _e('Day of membership', "e20r-sequences"); ?></option>
								<option value="<?php echo E20R_SEQ_AS_DATE; ?>" <?php selected( $options->showDelayAs, E20R_SEQ_AS_DATE); ?> ><?php _e('Calendar date', "e20r-sequences"); ?></option>
							</select>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-seq-showdelayas e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-delay-btns">
								<p class="e20r-seq-btns">
									<a href="#" id="ok-e20r-seq-delay" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
									<a href="#" id="cancel-e20r-seq-delay" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
								</p>
							</div>
						</div>
					</div>
					<div class="e20r-sequences-settings-row clear-after e20r-sequence-full-row">
						<div class="e20r-seq-alert-hl"><?php _e('New content alerts', "e20r-sequences"); ?></div>
						<hr style="width: 100%;" />
					</div><!-- end of row -->
					<!--Email alerts -->
					<div class="e20r-sequence-settings-display clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-alerts e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<input type="checkbox" value="1" title="<?php _e('Whether to send an alert/notice to members when new content for this sequence is available to them', "e20r-sequences"); ?>" id="e20r-sequence_sendNotice" name="e20r-sequence_sendNotice" <?php checked($options->sendNotice, 1); ?> />
								<input type="hidden" name="hidden-e20r-sequence_sendNotice" id="hidden-e20r-sequence_sendNotice" value="<?php esc_attr_e($options->sendNotice); ?>" >
							</div>
							<div class="e20r-sequence-setting-col-2">
								<label class="selectit" for="e20r-sequence_sendNotice"><?php _e('Send email alerts', "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-3">&nbsp;</div>
						</div>
					</div> <!-- end of row -->
					<!-- Send now -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after <?php echo ( $new_post ? 'e20r-sequence-hidden' : null ); ?>">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-sendnowbtn e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1"><label for="e20r_seq_send"><?php _e('Send alerts now', "e20r-sequences"); ?></label></div>
							<div class="e20r-sequence-setting-col-2">
								<?php wp_nonce_field('e20r-sequence-sendalert', 'e20r_sequence_sendalert_nonce'); ?>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" class="e20r-seq-settings-send e20r-seq-edit" id="e20r_seq_send">
									<span aria-hidden="true"><?php _e('Send', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php echo sprintf( __( 'Manually trigger sending of alert notices for the %s sequence', "e20r-sequences"), get_the_title( $sequence->sequence_id) ); ?></span>
								</a>
							</div>
						</div><!-- end of row -->
					</div>
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-full-row">
							<p class="e20r-seq-email-hl"><?php _e("Alert settings:", "e20r-sequences"); ?></p>
						</div>
					</div>
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-replyto e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-replyto"><?php _e('Email:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-replyto-status" class="e20r-sequence-status"><?php echo ( !empty( $options->replyto ) ? esc_attr($options->replyto) : esc_attr( $def_email ) ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-replyto" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Enter the email address to use as the sender of the alert', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div><!-- end of row -->
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-email e20r-sequence-replyto e20r-sequence-full-row">
							<div id="e20r-seq-email-input">
								<input type="hidden" name="hidden_e20r_seq_replyto" id="hidden_e20r_seq_replyto" value="<?php echo ( !empty($options->replyto ) ? esc_attr($options->replyto) : esc_attr( $def_email ) ); ?>" />
								<label for="e20r-sequence_replyto"></label>
								<input type="text" name="e20r-sequence_replyto" id="e20r-sequence_replyto" value="<?php echo ( !empty( $options->replyto ) ? esc_attr($options->replyto) : esc_attr( $def_email ) ); ?>"/>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-email e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-email" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-email" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div><!-- end of row -->
					</div>
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-fromname e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-fromname"><?php _e('Name:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-fromname-status" class="e20r-sequence-status"><?php echo ( !empty( $options->fromname ) ? esc_attr($options->fromname) : esc_attr( $def_name ) ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-fromname" class="e20r-seq-edit e20r-sequence-setting-col-3">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div><!-- end of row -->
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-replyto e20r-sequence-full-row">
							<div id="e20r-seq-email-input">
								<label for="e20r-sequence_fromname"></label>
								<input type="text" name="e20r-sequence_fromname" id="e20r-sequence_fromname" value="<?php echo (!empty( $options->fromname ) ? esc_attr($options->fromname) : esc_attr( $def_name ) ); ?>"/>
								<input type="hidden" name="hidden_e20r_seq_fromname" id="hidden_e20r_seq_fromname" value="<?php echo ( !empty( $options->fromname ) ? esc_attr($options->fromname) : esc_attr( $def_name ) ); ?>" />
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-email" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-email" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div><!-- end of row -->
					</div>
					<div class="e20r-sequences-settings-row clear-after e20r-sequence-full-row e20r-sequence-email clear-after">
						<hr width="80%"/>
					</div><!-- end of row -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-sendas e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-sendas"><?php _e('Transmit:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
                                <span id="e20r-seq-sendas-status" class="e20r-sequence-status e20r-sequence-setting-col-2"><?php

	                                switch($options->noticeSendAs) {
		                                case E20R_SEQ_SEND_AS_SINGLE:
			                                _e('One alert per post', "e20r-sequences");
			                                break;

		                                case E20R_SEQ_SEND_AS_LIST:
			                                _e('Digest of posts', "e20r-sequences");
			                                break;
	                                } ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-sendas" class="e20r-seq-edit e20r-sequence-setting-col-3">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Select the format of the alert notice when posting new content for this sequence', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-noticeSendAs e20r-sequence-full-row">
							<div id="e20r-seq-sendas-select">
								<input type="hidden" name="hidden-e20r-sequence_noticeSendAs" id="hidden-e20r-sequence_noticeSendAs" value="<?php echo esc_attr($options->noticeSendAs); ?>" >
								<label for="e20r-sequence_noticeSendAs"></label>
								<select name="e20r-sequence_noticeSendAs" id="e20r-sequence_noticeSendAs">
									<option value="<?php echo E20R_SEQ_SEND_AS_SINGLE; ?>" <?php selected( $options->noticeSendAs, E20R_SEQ_SEND_AS_SINGLE ); ?> ><?php _e('One alert per post', "e20r-sequences"); ?></option>
									<option value="<?php echo E20R_SEQ_SEND_AS_LIST; ?>" <?php selected( $options->noticeSendAs, E20R_SEQ_SEND_AS_LIST ); ?> ><?php _e('Digest of post links', "e20r-sequences"); ?></option>
								</select>
								<p class="e20r-seq-btns">
									<a href="#" id="ok-e20r-seq-sendas" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
									<a href="#" id="cancel-e20r-seq-sendas" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
								</p>
							</div>
						</div>
					</div><!-- end of row -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-template e20r-sequence-settings">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-template"><?php _e('Template:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-template-status" class="e20r-sequence-status"><?php esc_attr_e( $options->noticeTemplate ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-template" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence_fromName e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-template-select">
								<input type="hidden" name="hidden-e20r-sequence_noticeTemplate" id="hidden-e20r-sequence_noticeTemplate" value="<?php esc_attr_e($options->noticeTemplate); ?>" >
								<label for="e20r-sequence_template"></label>
								<select name="e20r-sequence_noticeTemplate" id="e20r-sequence_noticeTemplate">
									<?php echo $this->get_email_templates(); ?>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence_fromname e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-template" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-template" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div> <!-- end of row -->
					</div>
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after e20r-sequence-noticeTime e20r-sequence-email">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-noticeTime"><?php _e('When:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-noticetime-status" class="e20r-sequence-status"><?php esc_attr_e($options->noticeTime); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-noticetime" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-noticeTime e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-noticetime-select">
								<input type="hidden" name="hidden-e20r-sequence_noticeTime" id="hidden-e20r-sequence_noticeTime" value="<?php esc_attr_e($options->noticeTime); ?>" >
								<label for="e20r-sequence_noticeTime"></label>
								<select name="e20r-sequence_noticeTime" id="e20r-sequence_noticeTime">
									<?php echo $this->load_time_options(); ?>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-noticeTime e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-noticetime" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-noticetime" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div>
					</div> <!-- end of setting -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after e20r-sequence-timezone-setting">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-noticeTZ"><?php _e('Timezone:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span class="e20r-sequence-status" id="e20r-sequence-noticeTZ-status"><?php echo get_option('timezone_string'); ?></span>
							</div>
						</div>
					</div><!-- end of setting -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings clear-after e20r-sequence-subject">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-seq-subject"><?php _e("Subject", "e20r-sequences"); ?></label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-subject-status" class="e20r-sequence-status"><?php echo ( ! empty( $options->subject ) ? esc_attr($options->subject) : __('New Content', "e20r-sequences") ); ?></span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-subject" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e("Edit", "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e("Update/Edit the Prefix for the subject of the new content alert", "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-subject e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-subject-input">
								<input type="hidden" name="hidden-e20r-sequence-subject" id="hidden-e20r-sequence_subject" value="<?php echo ( ! empty( $options->subject ) ? esc_attr($options->subject) : __('New Content', "e20r-sequences") ); ?>" />
								<label for="e20r-sequence_subject"></label>
								<input type="text" name="e20r-sequence_subject" id="e20r-sequence_subject" value="<?php echo ( ! empty( $options->subject )  ? esc_attr($options->subject) : __('New Content', "e20r-sequences") ); ?>"/>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-subject e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-subject" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-subject" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div>
					</div><!-- end of setting -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings e20r-sequence-excerptIntro">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-sequence-excerptIntro"><?php _e('Intro:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-excerpt-status" class="e20r-sequence-status">"<?php echo ( !empty( $options->excerptIntro ) ? esc_attr($options->excerptIntro) : __('A summary for the new content follows:', "e20r-sequences") ); ?>"</span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-excerpt" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Update/Edit the introductory paragraph for the new content excerpt', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-excerptIntro e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-excerpt-input">
								<input type="hidden" name="hidden-e20r-sequence_excerptIntro" id="hidden-e20r-sequence_excerptIntro" value="<?php echo ( !empty($options->excerptIntro ) ? esc_attr($options->excerptIntro) : __('A summary for the new content follows:', "e20r-sequences") ); ?>" />
								<label for="e20r-sequence_excerpt"></label>
								<input type="text" name="e20r-sequence_excerptIntro" id="e20r-sequence_excerptIntro" value="<?php echo ( !empty($options->excerptIntro ) ? esc_attr($options->excerptIntro) : __('A summary for the new content follows:', "e20r-sequences") ); ?>"/>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-excerptIntro e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-excerpt" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-excerpt" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div>
					</div> <!-- end of setting -->
					<div class="e20r-sequence-settings-display e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row e20r-sequence-settings e20r-sequence-dateformat">
							<div class="e20r-sequence-setting-col-1">
								<label class="e20r-sequence-label" for="e20r-sequence_dateformat"><?php _e('Date type:', "e20r-sequences"); ?> </label>
							</div>
							<div class="e20r-sequence-setting-col-2">
								<span id="e20r-seq-dateformat-status" class="e20r-sequence-status">"<?php echo ( empty( $options->dateformat ) ? __('m-d-Y', "e20r-sequences") : esc_attr($options->dateformat) ); ?>"</span>
							</div>
							<div class="e20r-sequence-setting-col-3">
								<a href="#" id="e20r-seq-edit-dateformat" class="e20r-seq-edit">
									<span aria-hidden="true"><?php _e('Edit', "e20r-sequences"); ?></span>
									<span class="screen-reader-text"><?php _e('Update/Edit the format of the !!today!! placeholder (a valid PHP date() format)', "e20r-sequences"); ?></span>
								</a>
							</div>
						</div>
					</div>
					<div class="e20r-sequence-settings-input e20r-sequence-hidden e20r-sequence-email clear-after">
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-dateformat e20r-sequence-settings e20r-sequence-full-row">
							<div id="e20r-seq-dateformat-select">
								<input type="hidden" name="hidden-e20r-sequence_dateFormat" id="hidden-e20r-sequence_dateFormat" value="<?php echo ( empty( $options->dateformat) == false ? __('m-d-Y', "e20r-sequences") : esc_attr($options->dateformat) ); ?>" />
								<label for="e20r-sequence_dateformat"></label>
								<select name="e20r-sequence_dateformat" id="e20r-sequence_dateformat">
									<?php echo $this->list_date_formats(); ?>
								</select>
							</div>
						</div>
						<div class="e20r-sequences-settings-row clear-after e20r-sequence-dateformat e20r-sequence-settings e20r-sequence-full-row">
							<p class="e20r-seq-btns">
								<a href="#" id="ok-e20r-seq-dateormat" class="save-e20rseq button"><?php _e('OK', "e20r-sequences"); ?></a>
								<a href="#" id="cancel-e20r-seq-dateformat" class="cancel-e20rseq button-cancel"><?php _e('Cancel', "e20r-sequences"); ?></a>
							</p>
						</div>
					</div> <!-- end of setting -->
					<!--                        <div class="e20r-sequences-settings-row clear-after e20r-sequence-full-row">
											<hr style="width: 100%;" />
										</div> --><!-- end of row -->
					<!--                         <div class="e20r-sequences-settings-row clear-after e20r-sequence-full-row">
                        <a class="button button-primary button-large" class="e20r-seq-settings-save" id="e20r_settings_save" onclick="e20r-sequence_saveSettings(<?php esc_attr_e( $sequence->sequence_id ); ?>) ; return false;"><?php _e('Update Settings', "e20r-sequences"); ?></a>
                        <?php wp_nonce_field('e20r-sequence-save-settings', 'e20r_sequence_settings_nonce'); ?>
                        <div class="seq_spinner"></div>
                    </div>--><!-- end of row -->

				</div><!-- End of sequences settings table -->
				<!-- TODO: Enable and implement
                <tr id="e20r-sequenceseq_start_0" style="display: none;">
                    <td>
                        <input id='e20r-sequence_enablestartwhen' type="checkbox" value="1" title="<?php _e('Configure start parameters for sequence drip. The default is to start day 1 exactly 24 hours after membership started, using the servers timezone and recorded timestamp for the membership check-out.', "e20r-sequences"); ?>" name="e20r-sequence_enablestartwhen" <?php echo ( !empty( $options->startWhen ) ? 'checked="checked"' : '' ); ?> />
                    </td>
                    <td><label class="selectit"><?php _e('Sequence starts', "e20r-sequences"); ?></label></td>
                </tr>
                <tr id="e20r-sequence_seq_start_1" style="display: none; height: 1px;">
                    <td colspan="2">
                        <label class="screen-reader-text" for="e20r-sequence_startwhen">Day 1 Starts</label>
                    </td>
                </tr>
                <tr id="e20r-sequence_seq_start_2" style="display: none;" id="e20r-sequence_selectWhen">
                    <td colspan="2">
                        <select name="e20r-sequence_startwhen" id="e20r-sequence_startwhen">
                            <option value="0" <?php selected( intval($options->startWhen), '0'); ?> >Immediately</option>
                            <option value="1" <?php selected( intval($options->startWhen), '1'); ?> >24 hours after membership started</option>
                            <option value="2" <?php selected( intval($options->startWhen), '2'); ?> >At midnight, immediately after membership started</option>
                            <option value="3" <?php selected( intval($options->startWhen), '3'); ?> >At midnight, 24+ hours after membership started</option>
                        </select>
                    </td>
                </tr>

            </table> -->
			</div> <!-- end of minor-publishing div -->
		</div> <!-- end of e20r_sequence_meta -->
		<?php
		$metabox = ob_get_clean();

		DBG::log('Display the settings meta.');
		// Display the metabox (print it)
		echo $metabox;
	}

	/**
	 * Initial load of the metabox for the editor sidebar
	 */
	public function render_post_edit_metabox() {

		DBG::log( "Metabox for editor" );
		$metabox = '';

		global $post;

		$seq = apply_filters('get_sequence_class_instance', null);

		DBG::log("Page Metabox being loaded");

		ob_start();
		?>
		<div class="submitbox" id="e20r-seq-postmeta">
			<div id="minor-publishing">
				<div id="e20r_seq-configure-sequence">
					<?php echo $this->load_sequence_list_meta( $post->ID ) ?>
				</div>
			</div>
		</div>
		<?php

		$metabox = ob_get_clean();

		echo $metabox;
	}

	/**
	 * Loads metabox content for the post/page/CPT editor metabox (sidebar)
	 *
	 * @param int|null $post_id -- ID of Post being edited
	 * @param int $seq_id -- ID of the sequence being added/edited.
	 *
	 * @return string - HTML of metabox content
	 */
	public function load_sequence_list_meta( $post_id = null, $seq_id = 0) {

		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		DBG::log("Generating sequence metabox for post editor page");
		DBG::log("Parameters for load_sequence_list_meta() {$post_id} and {$seq_id}.");
		$belongs_to = array();
		$processed_ids = array();

		/* Fetch all Sequence posts */
		$sequence_list = $sequence->get_all_sequences( array( 'publish', 'pending', 'draft', 'private', 'future' ) );

		DBG::log("Loading Sequences (count: " . count($sequence_list) . ")");

		// Post ID specified so we need to look for any sequence related metadata for this post

		if ( empty( $post_id ) ) {

			global $post;
			$post_id = $post->ID;
		}

		DBG::log("Loading sequence ID(s) from DB");

		$belongs_to = $sequence->get_sequences_for_post( $post_id );

		// Check that all of the sequences listed for the post actually exist.
		// If not, clean up the $belongs_to array.
		if ( !empty( $belongs_to ) ) {

			DBG::log("Belongs to " . count($belongs_to) . " sequence(s)");

			foreach ( $belongs_to as $cId ) {

				if ( ! $sequence->sequence_exists( $cId ) ) {

					DBG::log( "Sequence {$cId} does not exist. Remove it (post id: {$post_id})." );

					if ( ( $key = array_search( $cId, $belongs_to ) ) !== false ) {

						DBG::log( "Sequence ID {$cId} being removed", E20R_DEBUG_SEQ_INFO );
						unset( $belongs_to[ $key ] );
					}
				}
			}
		}

		if ( !empty( $belongs_to ) ) { // get_post_meta( $post_id, "_post_sequences", true ) ) {

			if ( is_array( $belongs_to ) && ( $seq_id != 0 ) &&
				( ( ( false == $options->allowRepeatPosts ) && !in_array( $seq_id, $belongs_to ) ) ||
					( true == $options->allowRepeatPosts ) && ( in_array( $seq_id, $belongs_to ) ) ) ) {

				DBG::log("Adding the new sequence ID to the existing array of sequences");
				// array_push( $belongs_to, $seq_id );
				$belongs_to[] = $seq_id;
			}
		}
		elseif ( empty( $belongs_to ) && ( $seq_id != 0 ) ) {

			DBG::log("This post has never belonged to a sequence. Adding it to one now");
			$belongs_to = array( $seq_id );
		}
		else {
			// Empty array
			$belongs_to = array();
		}

		// Make sure there's at least one row in the Metabox.

		// array_push( $belongs_to, 0 );
		if ( empty( $belongs_to ) ) {

			DBG::log("Ensure there's at least one entry in the table. Sequence ID: {$seq_id}");
			$belongs_to[] = 0;
		}


		DBG::log("Post belongs to # of sequence(s): " . count( $belongs_to ) . ", content: " . print_r( $belongs_to, true ) );
		ob_start();
		?>
		<?php wp_nonce_field('e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce');?>
		<div class="seq_spinner vt-alignright"></div>
		<table style="width: 100%;" id="e20r-seq-metatable">
			<tbody><?php

			$sequence_value_matrix = array_count_values( $belongs_to );

			DBG::log("The matrix of sequence values: ");
			DBG::log( $sequence_value_matrix);

			foreach( $belongs_to as $active_id ) {

				if ( in_array( $active_id, $processed_ids ) ) {
					DBG::log("Skipping {$active_id} since it's already added to the metabox");
					continue;
				}

				// Figure out the correct delay type and load the value for this post if it exists.
				if ( $active_id != 0 ) {

					DBG::log("Loading options and posts for {$active_id}");
					$sequence->get_options( $active_id );
					// $sequence->load_sequence_post( null, null, null, '=', null, true );
				}
				else {

					$sequence->sequence_id = 0;
					$options = $sequence->default_options();
				}

				DBG::log("Loading all delay values for for {$post_id}");
				$d_posts = $sequence->get_delay_for_post( $post_id, false );

				if ( $sequence->sequence_id != 0 ) {

					foreach( $d_posts as $delay ) {

						if ( isset( $delay->delay ) && (!is_null( $delay->delay ) && is_numeric($delay->delay)) ) {

							DBG::log( "Delay Value: {$delay->delay}" );
							$delay_value = " value=\"{$delay->delay}\" ";

							list( $label, $input_html ) = $this->set_delay_input( $delay_value, $active_id );
							echo $this->print_sequence_header( $active_id );
							echo $this->print_sequence_entry( $sequence_list, $active_id, $input_html, $label );

						}
					}

					// $delays = array();
				}

				if ( empty( $d_posts ) ) {

					$delay_value = "value=''";
					list( $label, $input_html ) = $this->set_delay_input( $delay_value, $active_id );
					echo $this->print_sequence_header( $active_id );
					echo $this->print_sequence_entry( $sequence_list, $active_id, $input_html, $label );
				}

				$processed_ids[] = $active_id;

				// DBG::log(" Label: " . print_r( $label, true ) );
			} // Foreach ?>
			</tbody>
		</table>
		<div id="e20r-seq-new">
			<hr class="e20r-seq-hr" />
			<a href="#" id="e20r-seq-new-meta" class="button-primary"><?php _e( "New Sequence", "e20r-sequences" ); ?></a>
			<a href="#" id="e20r-seq-new-meta-reset" class="button"><?php _e( "Reset", "e20r-sequences" ); ?></a>
		</div>
		<?php

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * List all template files in email directory for this plugin.
	 *
	 * @param $settings (stdClass) - The settings for the sequence.
	 *
	 * @return bool| mixed - HTML containing the Option list
	 *
	 * @access private
	 */
	private function get_email_templates()
	{
		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		ob_start();

		$template_path = array();

		if ( file_exists( get_stylesheet_directory() . "/sequence-email-alerts/")) {

			$template_path[] = get_stylesheet_directory() . "/sequence-email-alerts/";

		}

		if ( file_exists( get_template_directory() . "/sequence-email-alerts/" ) ) {

			$template_path[] = get_template_directory() . "/sequence-email-alerts/";
		} ?>

		<!-- Default template (blank) -->
		<option value=""></option>
		<?php

		DBG::log("Found " . count($template_path) . " user specific custom templates:");
		DBG::log($template_path);

		$f_dirs = apply_filters( 'e20r-sequence-email-alert-template-path', array( E20R_SEQUENCE_PLUGIN_DIR . "/email/" ) );

		if (!is_array($f_dirs)) {

			$f_dirs = array($f_dirs);
		}

		$template_path = array_merge($template_path, $f_dirs);

		DBG::log("Total number of template paths: " . count($template_path));

		foreach ( $template_path as $dir ) {

			chdir($dir);

			DBG::log("Processing directory: {$dir}");

			foreach ( glob('*.html') as $file) {

				DBG::log("File: {$file}");
				echo('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $options->noticeTemplate), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
			}

			/**
			  * @since 4.5 - Adding future support for a sequence email template editor (add-on)
              */
			$use_editor = apply_filters( 'e20r-sequence-template-editor-loaded', false );

			if ( true === $use_editor ) {
				/**
				  * @filter e20r-sequence-template-editor-email-entry  - Load the email entry from the editor add-on as a select option for the drop-down list.
                  *
                  * @param null|string  Option values to include in select.
                  */
				echo apply_filters( 'e20r-sequence-template-editor-email-entry', null );
			}
		}

		$select_list = ob_get_clean();

		return $select_list;
	}

	/**
	 * Create list of options for time.
	 *
	 * @param $settings -- (array) Sequence specific settings
	 *
	 * @return bool| mixed - HTML containing the Option list
	 *
	 * @access private
	 */
	private function load_time_options( )
	{
		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		$prepend    = array('00','01','02','03','04','05','06','07','08','09');
		$hours      = array_merge($prepend,range(10, 23));
		$minutes     = array('00', '30');

		// $prepend_mins    = array('00','30');
		// $minutes    = array_merge($prepend_mins, range(10, 55, 5)); // For debug
		// $selTime = preg_split('/\:/', $settings->noticeTime);

		ob_start();

		foreach ($hours as $hour) {
			foreach ($minutes as $minute) {
				?>
				<option value="<?php printf( '%1$s:%2$s', esc_attr( $hour ), esc_attr( $minute ) ); ?>"<?php selected( $options->noticeTime, "{$hour}:{$minute}" ); ?> ><?php printf( '%1$s:%2$s', esc_attr( $hour ), esc_attr( $minute ) ); ?></option>
				<?php
			}
		}

		$select_list = ob_get_clean();

		return $select_list;
	}

	/**
	 * List the available date formats to select from.
	 *
	 * key = valid dateformat
	 * value = dateformat example.
	 *
	 * @param $settings -- Settings for the sequence
	 *
	 * @return bool| mixed - HTML containing the Option list
	 *
	 * @access private
	 */
	private function list_date_formats() {

		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		ob_start();

		$formats = array(
			"l, F jS, Y" => "Sunday January 25th, 2014",
			"l, F jS," => "Sunday, January 25th,",
			"l \\t\\h\\e jS" => "Sunday the 25th",
			"M. js, " => "Jan. 24th",
			"M. js, Y" => "Jan. 24th, 2014",
			"M. js, 'y" => "Jan. 24th, '14",
			"m-d-Y" => "01-25-2014",
			"m/d/Y" => "01/25/2014",
			"m-d-y" => "01-25-14",
			"m/d/y" => "01/25/14",
			"d-m-Y" => "25-01-2014",
			"d/m/Y" => "25/01/2014",
			"d-m-y" => "25-01-14",
			"d/m/y" => "25/01/14",
		);

		foreach ( $formats as $key => $val)
		{
			printf( '<option value="%1$s" %2%s >%3$s</option>', esc_attr($key), selected( $options->dateformat,  $key ), esc_attr($val) );
		}

		$select_list = ob_get_clean();

		return $select_list;
	}

	private function set_delay_input( $input_value, $active_id ) {
		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		switch ( $options->delayType ) {

			case 'byDate':

				DBG::log("Configured to track delays by Date");
				$delay_format = __( 'Date', "e20r-sequences" );
				$starts = date_i18n( "Y-m-d", current_time('timestamp') );

				if ( empty( $input_value ) ) {
					$input_html = sprintf( '<input class="e20r-seq-delay-info e20r-seq-date" type="date" min="%s" name="e20r_seq-delay[]">', $starts );
				}
				else {
					$input_html = sprintf( '<input class="e20r-seq-delay-info e20r-seq-date" type="date" name="e20r_seq-delay[]" %s>', $input_value );
				}

				break;

			default:

				DBG::log("Configured to track delays by Day count: {$active_id}");
				$delay_format = __('Day count', "e20r-sequences");
				$input_html = sprintf( '<input class="e20r-seq-delay-info e20r-seq-days" type="text" name="e20r_seq-delay[]" %s>', $input_value );

		}

		$label = sprintf( __("Delay (Format: %s)", "e20r-sequences"), $delay_format );

		return array( $label, $input_html );
	}

	private function print_sequence_header( $active_id ) {

		ob_start(); ?>
		<fieldset>
		<tr class="select-row-label sequence-select-label<?php // echo ( $active_id == 0 ? ' new-sequence-select-label' : ' sequence-select-label' ); ?>">
			<td>
				<label for="e20r_seq-memberof-sequences"><?php _e("Managed by (drip content feed)", "e20r-sequences"); ?></label>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();
		return $html;
	}

	private function print_sequence_entry( $sequence_list, $active_id, $input_html, $label ) {
		ob_start(); ?>
		<tr class="select-row-input sequence-select">
			<td class="sequence-list-dropdown">
				<select class="e20r_seq-memberof-sequences" name="e20r_seq-sequences[]">
					<option value="0" <?php echo ( ( empty( $belongs_to ) || $active_id == 0) ? 'selected="selected"' : '' ); ?>><?php _e("Not managed", "e20r-sequences"); ?></option><?php
					// Loop through all of the sequences & create an option list
					foreach ( $sequence_list as $sequence ) {

						printf(
							'<option value="%1$s" %2$s>%3$s</option>',
							esc_attr( $sequence->ID ),
							selected( $sequence->ID, $active_id ),
							esc_attr( $sequence->post_title )
						);
					} ?>
				</select>
			</td>
		</tr>
		<tr class="delay-row-label sequence-delay-label">
			<td>
				<label for="e20r_seq-delay_<?php esc_attr_e( $active_id ); ?>"> <?php esc_attr_e( $label ); ?> </label>
			</td>
		</tr>
		<tr class="delay-row-input sequence-delay">
			<td>
				<?php echo $input_html; ?>
				<label for="remove-sequence_<?php esc_attr_e( $active_id ); ?>" ><?php _e('Remove: ', "e20r-sequences"); ?></label>
				<input type="checkbox" name="remove-sequence" class="e20r_seq-remove-seq" value="<?php esc_attr_e( $active_id ); ?>">
				<button class="button-secondary e20r-sequence-remove-alert"><?php _e("Clear alerts", "e20r-sequences");?></button>
			</td>
		</tr>
		</fieldset>
		<?php
		$html = ob_get_clean();
		return $html;
	}

	/**
	 * Create a list of posts/pages/cpts that are included in the specified sequence (or all sequences, if needed)
	 *
	 * @param bool $highlight -- Whether to highlight the Post that is the closest to the users current membership day
	 * @param int $pagesize -- The size of each page (number of posts per page)
	 * @param bool $button -- Whether to display a "Available Now" button or not.
	 * @param string $title -- The title of the sequence list. Default is the title of the sequence.
	 *
     * @return string -- The HTML we generated.
	 */
	public function create_sequence_list( $highlight = false, $pagesize = 0, $button = false, $title = null, $scrollbox = false ) {

		global $wpdb;
		global $current_user;
		global $id;
		global $post;

		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

		$html = '';

		$save_post = $post;

		// Set a default page size.
		if ($pagesize == 0) {
			$pagesize = 30;
		}

		DBG::log( "Loading posts with pagination enabled. Expecting \\WP_Query result" );
		// $ret_seq = $sequence->load_sequence_post( null, null, null, '=', $pagesize, true );
		$ret_seq = $sequence->load_sequence_post( null, null, null, '=', $pagesize );
  
		// DBG::log("Got: " . print_r( $ret_seq, true ) );
		if ( is_array($ret_seq ) && !empty($ret_seq)) {

			list( $seq_list, $max_num_pages ) = $ret_seq;
		} else {
			$seq_list = null;
			$max_num_pages = 0;
		}

		// $sequence_posts = $this->posts;
		$member_day_count = $sequence->get_membership_days();

		DBG::log( "Sequence {$sequence->sequence_id} has " . count( $seq_list ) . " posts. Current user has been a member for {$member_day_count} days" );

		if ( ! $sequence->has_post_access( $current_user->ID, $post->ID ) ) {
			DBG::log( 'No access to this page/post/sequence ' . $sequence->sequence_id . ' for user ' . $current_user->ID );
			return '';
		}

		/* Get the ID of the post in the sequence who's delay is the closest
         *  to the members 'days since start of membership'
         */
		// $closestPost = apply_filters( 'e20r-sequence-found-closest-post', $sequence->find_closest_post( $current_user->ID ) );

		// Image to bring attention to the closest post item
		$closest_post_img = sprintf( '<img src="%s">', plugins_url( '/../images/most-recent.png', __FILE__ ) );

		$listed_post_cnt   = 0;

		DBG::log( "Loading posts for the sequence_list shortcode...");
		ob_start();
		?>

		<!-- Preface the table of links with the title of the sequence -->
	<div id="e20r_sequence-<?php esc_attr_e( $sequence->sequence_id ); ?>" class="e20r_sequence_list">

		<?php echo apply_filters( 'e20r-sequence-list-title',  $this->set_title_in_shortcode( $title ) ); ?>

		<!-- Add opt-in to the top of the shortcode display. -->
		<?php $this->view_user_notice_opt_in(); ?>

		<!-- List of sequence entries (paginated as needed) -->
		<?php

	if ( count( $seq_list ) == 0 ) {
		// if ( 0 == count( $this->posts ) ) {
		printf( '<span style="text-align: center;">%s</span>', __( "There is <em>no content available</em> for you at this time. Please check back later.", "e20r-sequences" ) );

	} else {
	if ( $scrollbox ) { ?>
		<div id="e20r-seq-post-list">
		<table class="e20r_sequence_postscroll e20r_seq_linklist">
		<?php } else { ?>
		<div>
			<table class="e20r_seq_linklist">
				<?php };

				// Loop through all of the posts in the sequence

				// $posts = $seq_list->get_posts();

				foreach( $seq_list as $s_post ) {

					if ( ( false === $s_post->is_future && ( true === $s_post->list_include )) ) {
					 
						DBG::log("Adding post {$s_post->id} with delay {$s_post->delay}");
						$listed_post_cnt++;

						if ( ( true === $s_post->closest_post ) && ( $highlight ) ) {

							DBG::log( 'The most recently available post for user #' . $current_user->ID . ' is post #' . $s_post->id );

							// Show the highlighted post info
							?>
							<tr id="e20r-seq-selected-post">
								<td class="e20r-seq-post-img"><?php echo apply_filters( 'e20r-sequence-closest-post-indicator-image', $closest_post_img ); ?></td>
								<td class="e20r-seq-post-hl">
									<a href="<?php echo esc_url_raw($s_post->permalink); ?>" title="<?php esc_attr_e($s_post->title); ?>"><strong><?php esc_attr_e($s_post->title); ?></strong>&nbsp;&nbsp;<em>(<?php _e("Current", "e20r-sequences");?>)</em></a>
								</td>
								<td <?php echo( true === $button ? 'class="e20r-seq-availnow-btn"' : '' ); ?>><?php

									if ( true === $button ) {
										?>
									<a class="button primary" href="<?php echo esc_url($s_post->permalink); ?>"> <?php _e( "Available", "e20r-sequences" ); ?></a><?php
									} ?>
								</td>
							</tr> <?php
						} else {
							?>
							<tr id="e20r-seq-post">
								<td class="e20r-seq-post-img">
								<?php if ( $sequence->include_featured_image_for_posts() && has_post_thumbnail( $s_post->id ) ) {
								    echo get_the_post_thumbnail( $s_post->id, 'thumbnail', 'alignleft' );
								} else {
								    ?>&nbsp;<?php } ?>
                                </td>
								<td class="e20r-seq-post-fade">
									<a href="<?php echo esc_url_raw($s_post->permalink); ?>" title="<?php esc_attr_e($s_post->title); ?>"><?php esc_attr_e($s_post->title); ?></a>
								</td>
								<td <?php echo( true === $button ? 'class="e20r-seq-availnow-btn"' : '' ); ?>>
								<?php
								if ( true === $button ) {
									?>
								<a class="button" href="<?php echo esc_url_raw( $s_post->permalink ); ?>"> <?php _e( "Available", "e20r-sequences" ); ?></a><?php
								} ?>
								</td>
							</tr>
							<?php
						}
					} elseif ( ( true == $s_post->is_future && true === $s_post->list_include ) /* && ( false === $this->hide_upcoming_posts() ) */ ) {

						$listed_post_cnt++;

						// Do we need to highlight the (not yet available) post?
						// if ( ( $s_post->ID == $closestPost->id ) && ( $s_post->delay == $closestPost->delay ) && $highlight ) {
						if ( ( true === $s_post->closest_post ) && ( $highlight ) ) {
							?>

							<tr id="e20r-seq-post">
								<td class="e20r-seq-post-img">&nbsp;</td>
								<td colspan="2" id="e20r-seq-post-future-hl">
									<?php DBG::log( "Highlight post #: {$s_post->id} with future availability" ); ?>
									<span class="e20r_sequence_item-title">
                                        <?php esc_attr_e($s_post->title); ?>
                                    </span>
                                    <span class="e20r_sequence_item-unavailable">
                                        <?php printf( __( 'Available: %s', "e20r-sequences" ),
											( $options->delayType == 'byDays' &&
												$options->showDelayAs == E20R_SEQ_AS_DAYNO ) ?
												__( 'day', "e20r-sequences" ) : '' ); ?>
										<?php esc_attr_e( $sequence->display_proper_delay( $s_post->delay )); ?>
                                    </span>
								</td>
							</tr>
							<?php
						} else {
							?>
							<tr id="e20r-seq-post">
								<td class="e20r-seq-post-img">&nbsp;</td>
								<td colspan="2">
                                    <span class="e20r_sequence_item-title"><?php esc_attr_e($s_post->title); ?></span>
                                    <span class="e20r_sequence_item-unavailable">
                                        <?php printf( __( 'Available: %s', "e20r-sequences" ),
                                            ($options->delayType == 'byDays' &&
                                                $options->showDelayAs == E20R_SEQ_AS_DAYNO ) ?
                                                __( 'day', "e20r-sequences" ) : '' ); ?>
                                        <?php esc_attr_e( $sequence->display_proper_delay( $s_post->delay ) ); ?>
                                    </span>
                                </td>
							</tr> <?php
						}
					} elseif ( false !== $s_post->list_include ) {
						if ( ( count( $seq_list ) > 0 ) && ( $listed_post_cnt > 0 ) ) {
							?>
							<tr id="e20r-seq-post">
								<td>
									<span style="text-align: center;"><?php _e("There is <em>no content available</em> for you at this time. Please check back later.", "e20r-sequences"); ?></span>,
								</td>
							</tr><?php
						}
					}
				}

				?></table>
		</div>
		<div class="clear"></div>
		<?php


		echo apply_filters( 'e20r-sequence-list-pagination-code', $sequence->post_paging_nav( ceil( count( $seq_list ) / $pagesize ) ) );
	}
		?>
		</div><?php

		$post = $save_post;

		$html .= ob_get_contents();
		ob_end_clean();

		DBG::log("create_sequence_list() - Returning the - possibly filtered - HTML for the sequence_list shortcode");

		return apply_filters( 'e20r-sequence-list-html', $html );
	}

	/**
	 * Formats the title (unless its empty, then we set it to the post title for the current sequence)
	 *
	 * @param string|null $title -- A string (title) to apply formatting to & return
	 *
	 * @return null|string - The title string
	 */
	private function set_title_in_shortcode( $title = null ) {

		$sequence = apply_filters('get_sequence_class_instance', null);
		// Process the title attribute (default values, can apply filter if needed/wanted)
		if ( ( $title == '' ) && ( $sequence->sequence_id != 0 ) ) {

			$title = sprintf( '<h3>%s</h3>', get_the_title( $sequence->sequence_id ) );
		}
		elseif ( ( $sequence->sequence_id == 0 ) && ( $title == '' ) ) {

			$title = sprintf( '<h3>%s</h3>', __("Available posts", "e20r-sequences") );
		}
		elseif ( $title == '' ) {

			$title = '';
		}
		else {

			$title = "<h3>{$title}</h3>";
		}

		return $title;
	}

	/**
     * Adds notification opt-in to list of posts/pages in sequence.
     *
     * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
     *
     * @access public
     */
    public function view_user_notice_opt_in() {

        $optin_form = '';

        global $current_user;

		$sequence = apply_filters('get_sequence_class_instance', null);
		$options = $sequence->get_options();

        // $meta_key = $wpdb->prefix . "pmpro_sequence_notices";

        DBG::log('User specific opt-in to sequence display for new content notices for user ' . $current_user->ID);

        if ( isset( $options->sendNotice ) && ( $options->sendNotice == 1 ) ) {

            DBG::log("Allow user to opt out of email notices");
            $opt_in = $sequence->load_user_notice_settings( $current_user->ID, $sequence->sequence_id );

            // DBG::log('Fetched Meta: ' . print_r( $opt_in, true));

            $notice_val = isset( $opt_in->send_notices ) && ( $opt_in->send_notices == 1 ) ? $opt_in->send_notices : 0;

            /* Add form information */
            ob_start();
            ?>
            <div class="e20r-seq-centered">
                <div class="e20r_sequence_useroptin">
                    <div class="seq_spinner"></div>
                    <form class="e20r-sequence" action="<?php echo admin_url('admin-ajax.php'); ?>" method="post">
                        <input type="hidden" name="hidden_e20r_seq_useroptin" id="hidden_e20r_seq_useroptin" value="<?php esc_attr_e( $notice_val ); ?>" >
                        <input type="hidden" name="hidden_e20r_seq_id" id="hidden_e20r_seq_id" value="<?php esc_attr_e( $sequence->sequence_id ); ?>" >
                        <input type="hidden" name="hidden_e20r_seq_uid" id="hidden_e20r_seq_uid" value="<?php esc_attr_e( $current_user->ID ); ?>" >
                        <?php wp_nonce_field('e20r-sequence-user-optin', 'e20r_sequence_optin_nonce'); ?>
                        <span>
                            <input type="checkbox" value="1" id="e20r_sequence_useroptin" name="e20r_sequence_useroptin" onclick="javascript:e20r_sequence_optinSelect(); return false;" title="<?php _e('Please email me an alert/reminder when any new content in this sequence becomes available', "e20r-sequences"); ?>" <?php echo ($notice_val == 1 ? ' checked="checked"' : null); ?> " />
                            <label for="e20r-seq-useroptin"><?php _e('Yes, please send me email reminders!', "e20r-sequences"); ?></label>
                        </span>
                    </form>
                </div>
            </div>

            <?php
            $optin_form = ob_get_clean();
        }
        else {
            DBG::log("Not configured to allow sending of notices. {$options->sendNotice}");
        }

        DBG::log("Returning opt-in form HTML (if applicable)");
        return $optin_form;
    }
}