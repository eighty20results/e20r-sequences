<?php
/*
  License:

	Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)

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

class PMProSeqRecentPost extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'pmpro_sequence__currentpost_widget',
			'Sequence: Currently available Post/Page',
			array(
				'description' =>
					__('Display a summary of the most recently available sequence post (or page) for the currently logged-in user.')
			)
		);
	}

	public function widget( $args, $instance) {

		extract($args);

		$title = apply_filters( 'widget_title', $instance['title'] );
		$seqPrefix = apply_filters( 'pmpro_sequence_widget_prefix', $instance['prefix']) ;
		$sequence_id = apply_filters( 'pmpro_sequence_widget_seqid', $instance['sequence_id'] );
		$defaultTitle = apply_filters('pmpro_sequence_widget_default_post_title', $instance['default_post_title']);

		$wordcount = $instance['wordcount'];
		$show_title = ($instance['show_title'] == 1 ) ? true : false;

		echo $before_widget;

		if ($title)
			echo $before_title . $title . $after_title;

		$this->get_sequencePostData( $sequence_id, $seqPrefix, $wordcount, $show_title, $defaultTitle );

		echo $after_widget;
	}

	public function form( $instance ) {

		// Set up the current (or default) settings
		if ( $instance ) {

			$show_title = ( empty( $instance['show_title'] ) ? 0 : esc_attr( $instance['show_title'] ) );
			$default_title = esc_attr( $instance['default_post_title'] );
			$title = esc_attr( $instance['title'] );
			$sequence_id = esc_attr( $instance['sequence_id'] );
			$excerpt_wordcount = esc_attr( $instance['wordcount'] );
			$seqPrefix = esc_attr( $instance['prefix'] );

		}
		else {
			dbgOut("Widget config: No config found");
			$default_title = __('Your most recently available content', 'pmprosequence');
			$title = null;
			$show_title = 0;
			$sequence_id = 0;
			$seqPrefix = null;
			$excerpt_wordcount = 40;
		}

		?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Widget title', 'pmprosequence'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('title');?>" name="<?php echo $this->get_field_name('title')?>" type="text" value="<?php echo $title; ?>" />
		</p>

		<p>
			<input class="widefat" id="<?php echo $this->get_field_id('show_title');?>" name="<?php echo $this->get_field_name('show_title')?>" type="checkbox" value="1" <?php checked($show_title, 1); ?> />
			<label for="<?php echo $this->get_field_id('show_title'); ?>"><?php _e('Show Post/Page title', 'pmprosequence'); ?></label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('default_post_title'); ?>"><?php _e('Default post/page title (if "hidden")', 'pmprosequence'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('default_post_title');?>" name="<?php echo $this->get_field_name('default_post_title')?>" type="text" value="<?php echo $default_title; ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('prefix'); ?>"><?php _e('Post title prefix', 'pmprosequence'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('prefix');?>" name="<?php echo $this->get_field_name('prefix')?>" type="text" value="<?php echo $seqPrefix; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('wordcount'); ?>"><?php _e('Max size of post/page excerpt (# of words)', 'pmprosequence'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('wordcount');?>" name="<?php echo $this->get_field_name('wordcount')?>" type="text" value="<?php echo $excerpt_wordcount; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('sequence_id'); ?>"><?php _e('Sequence to use', 'pmprosequence'); ?></label>
			<select id="<?php echo $this->get_field_id('sequence_id'); ?>" name="<?php echo $this->get_field_name('sequence_id')?>">
				<?php echo $this->sequenceOptions( $sequence_id ); ?>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance) {
		$instance = $old_instance;

		$instance['show_title'] = strip_tags( $new_instance['show_title']);
		$instance['default_post_title'] = strip_tags( $new_instance['default_post_title']);

		$instance['title'] = strip_tags( $new_instance['title']);
		$instance['sequence_id'] = strip_tags( $new_instance['sequence_id']);
		$instance['wordcount'] = strip_tags( $new_instance['wordcount']);
		$instance['prefix'] = strip_tags( $new_instance['prefix']);

		return $instance;
	}

	private function sequenceOptions( $sequence_id ) {

		global $id;

		$sequences = new WP_Query( array(
			"post_type" => "pmpro_sequence",
		) );

		ob_start();
		if ( $sequences->found_posts == 0 ) {
			?>
			<option value="0" selected="selected"><?php _e('No sequences defined', 'pmprosequence'); ?></option><?php
		}
		else {
			?><option value="0" <?php echo ( $sequence_id != 0 ? '' : 'selected="selected"' ); ?>></option><?php

			while ( $sequences->have_posts() ) : $sequences->the_post();
				dbgOut('widget options: value: ' . $id . ' and title: ' . get_the_title()); ?>
				<option	value="<?php echo $id; ?>" <?php echo selected( $id, $sequence_id ); ?> ><?php echo get_the_title(); ?></option><?php
			endwhile;
		}

		wp_reset_postdata();

		$html = ob_get_clean();

		return $html;
	}

	private function get_sequencePostData( $sequence_id, $seqPrefix = null, $excerpt_length = 0, $show_title = true, $defaultTitle) {

		global $post, $current_user;

		if ($sequence_id != 0) {
			$sequence = new PMProSequence( $sequence_id );
		}
		else {
			?>

			<li class="widget widget-text">
				<h3 id="pmpro-seq-post-notfound">Error</h3>
				<div class="text-widget">
					<?php _e("No sequence specified for this widget!", 'pmprosequence'); ?>
				</div>
			</li>

			<?php

			return false;
		}

		if ( $current_user != 0 ) {

			$seqPostId = $sequence->get_closestPost( $current_user->ID );

			if ( pmpro_sequence_hasAccess( $current_user->ID, $seqPostId, false ) ) {

				add_image_size( 'pmpro_seq_widget_size', 85, 45, false );

				$seq_post = new WP_Query( array(
					'post_type'           => 'any',
					'post_status'         => 'publish',
					'posts_per_page'      => 1,
					'p'                   => $seqPostId,
					'ignore_sticky_posts' => true,
				) );

				if ( $seq_post->found_posts > 0 ) {

					while ( $seq_post->have_posts() ) : $seq_post->the_post();

						$image = ( has_post_thumbnail( $post->ID ) ? get_the_post_thumbnail( $post->ID, 'pmpro_seq_widget_size' ) : '<div class="noThumb"></div>' );
						?>
					<?php if ($show_title) { ?>
						<h3 class="widget-title">
							<span class="widget-inner"><?php echo ( $seqPrefix != '' ? $seqPrefix . ' ' : ' ' ) . get_the_title(); ?></span>
						</h3>
					<?php }	else { ?>
						<h3 class="widget-title"><?php echo $defaultTitle; ?></h3>
					<?php } ?>
						<div id="pmpro-seq-post-body" class="text-widget">
							<p class="pmpro-seq-when">Available on <?php $this->print_available_date($sequence, $seqPostId); ?></p>
							<p id="pmpro-seq-post-body-text"><?php
								echo $image;
								echo $this->limit_excerpt_words( get_the_excerpt(), $excerpt_length ); ?>
							</p>
							<p id="pmpro-seq-post-link">
								<a href="<?php echo get_permalink() ?>" title="<?php the_title(); ?>"><?php _e('Click to access', 'pmprosequence'); ?></a>
							</p>
						</div>
					<?php
					endwhile;?>
				<?php
				} else {
					?>
					<span id="pmpro-seq-post-notfound">
					<h3 class="widget-title">Configuration Error</h3>
					<div id="pmpro-seq-post-body" class="text-widget">
						<?php echo ( $sequence_id != 0 ? get_the_title($sequence_id) . __(': No post(s) found!', 'pmprosequence') : __('No sequence specified', 'pmprosequence') ); ?>
					</div>
				</span>
				<?php
				}

			}
			else {
				?>
				<span id="pmpro-seq-post-notfound">
					<h3 class="widget-title">Membership Level Error</h3>
					<div id="pmpro-seq-post-body" class="text-widget">
						<?php _e( "Sorry, your current membership level does not grant you access to this content.", 'pmprosequence' ); ?>
					</div>
				</span>
			<?php
			}
			wp_reset_postdata();
		}
	}

	private function limit_excerpt_words( $string, $word_limit ) {
		$words = explode( " ", $string, ($word_limit + 1));

		if ( count($words) > $word_limit ) {
			array_pop( $words );
			array_push( $words, '[...]');
		}

		return implode( " ", $words);
	}

	private function print_available_date( PMProSequence $seq, $postId ) {

		$seqPost = $seq->get_postDetails($postId);

		if ( ( $seq->options->delayType == 'byDays' ) && ( $seq->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ) {
			echo 'day ' . $seq->displayDelay($seqPost->delay) . ' of membership';
		}
		else {
			echo $seq->displayDelay($seqPost->delay);
		}
	}
}