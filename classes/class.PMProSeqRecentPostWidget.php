<?php
/**
 * Created by PhpStorm.
 * User: sjolshag
 * Date: 8/15/14
 * Time: 3:00 PM
 */

class PMProSeqRecentPostWidget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'pmpro_sequence__currentpost_widget',
			'Most Current Post/Page in the Sequence',
			array(
				'description' =>
					__('Display a summary of the most recently available sequence post (or page) for the currently logged-in user.')
			)
		);
	}

	public function widget( $args, $instance) {

		extract($args);

		$title = apply_filters( 'widget_title', $instance['title'] );
		$seqPrefix = apply_fiters( 'pmpro_sequence_widget_prefix', $instance['prefix']) ;
		$sequence_id = apply_filters( 'pmpro_sequence_widget_sequence', $instance['sequence_id'] );

		echo $before_widget;

		if ($title)
			echo $before_title . $title . $after_title;

		$this->get_sequencePostData( $sequence_id, $seqPrefix );
		echo $after_widget;
	}

	public function form( $instance ) {

		// Set up the current (or default) settings
		if ( ! empty( $instance ) ) {

			$title = esc_attr($instance['title']);
			$sequence_id = esc_attr($instance['sequence_id']);
			$excerpt_wordcount = esc_attr( $instance['wordcount'] );
			$seqPrefix = esc_attr( $instance['prefix']);
		}
		else {
			global $wp_query;
			$title = 'Available Post/Page';
			$sequence_id = 0;
			$seqPrefix = 'Lesson:';
			$excerpt_wordcount = 40;
		}

		?>
			<p>
				<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title', 'pmprosequence'); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id('title');?>" name="<?php echo $this->get_field_name('title')?>" type="text" value="<?php echo $title; ?>" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('prefix'); ?>"><?php _e('Prefix', 'pmprosequence'); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id('prefix');?>" name="<?php echo $this->get_field_name('prefix')?>" type="text" value="<?php echo $seqPrefix; ?>" />
			</p>
		<p>
			<label for="<?php echo $this->get_field_id('wordcount'); ?>"><?php _e('Max size of post/page excerpt (# of words)', 'pmprosequence'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('wordcount');?>" name="<?php echo $this->get_field_name('wordcount')?>" type="text" value="<?php echo $excerpt_wordcount; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('sequence_id'); ?>"><?php _e('Sequence', 'pmprosequence'); ?></label>
			<select id="<?php echo $this->get_field_id('sequence_id'); ?>" name="<?php echo $this->get_field_name('sequence_id')?>">
				<?php $this->sequenceOptions($sequence_id); ?>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance) {
		$instance = $old_instance;

		$instance['title'] = strip_tags( $new_instance['title']);
		$instance['sequence_id'] = strip_tags( $new_instance['sequence_id']);
		$instance['wordcount'] = strip_tags( $new_instance['wordcount']);
		$instance['prefix'] = strip_tags( $new_instance['prefix']);

		return $instance;
	}

	private function sequenceOptions( $sequence_id = 0) {

		global $wpdb, $current_user;

		$sequenceInfo = array();

		$sequences = get_posts( array(
			"post_type" => "pmpro_sequence"
		) );

		ob_start();
		?>
		<option value="0" <?php echo ($sequence_id == 0 ? 'selected="selected"' : ''); ?>>None</option><?php

		foreach ( $sequences as $seq ) : setup_postdata( $seq ); ?>
			<option value="<?php get_the_ID(); ?>" <?php selected($sequence_id, get_the_ID(), true); ?>><?php the_title(); ?></option><?php
		endforeach;

		wp_reset_postdata();

		$html = ob_get_clean();

		return $html;
	}

	private function get_sequencePostData( $sequence_id, $seqPrefix = null, $excerpt_length = 20) {

		global $post, $current_user;


		$sequence = new PMProSequence( $sequence_id );

		if ($current_user != 0) {
			$post_id = $sequence->get_closestPost( $current_user->ID );

			if ( pmpro_sequence_hasAccess( $current_user->ID, $post_id, false )) {
				add_image_size( 'pmpro_seq_widget_size', 85, 45, false );

				$seq_post = new WP_Query( array(
					'post_type' => 'any',
					'ID' => $post_id
				));

				if ( $seq_post->found_posts  > 0 ) {
					?>
					<div id=pmpro-seq-postsummary"><?php
						while ( $seq_post->have_posts() ) : $seq_post->the_post();
							$image = ( has_post_thumbnail( $post->ID ) ? get_the_post_thumbnail( $post->ID, 'pmpro_seq_widget_size' ) : '<div class="noThumb"></div>');
							?>
							<div id="pmpro-seq-post-header">
								<h2><?php echo ( $seqPrefix != '' ? $seqPrefix . ' ' : ' ' ) . get_the_title(); ?></h2>
							</div>
							<div id="pmpro-seq-post-body"><?php

								echo $image;
								echo $this->excerpt( $excerpt_length ); ?>

							</div>
						<?php
						endwhile;
					?></div><?php
				}
			}
			else {
				?>
				<div id="pmpro-seq-no-access">
					<?php _e("Sorry, your current membership level does not grant you access to this content.", 'pmprosequence'); ?>
				</div>
				<?php
			}
		}
		else { ?>
			<div id="pmpro-seq-post-notfound">
				No post found
			</div>
		<?php
		}

		wp_reset_postdata();
	}

	/**
	 * Fetches an excerpt & limits it (word count) to the $length number of words.
	 *
	 * MUST be run within the WP loop.
	 *
	 * @param $length -- Length of the excerpt to return
	 *
	 * @return array|mixed|string -- An excerpt conforming to the $length value
	 *
	 * @visibility private
	 */
	private function excerpt( $length ) {

		$excerpt = explode( ' ' , get_the_excerpt(), $length );

		if ( count( $excerpt ) >= $length ) {
			array_pop( $excerpt );
			$excerpt = implode( " ", $excerpt . "...");
		}
		else {
			$excerpt = implode( " ", $excerpt );
		}

		$excerpt = preg_replace( "`\[[^\]]*\`", '', $excerpt);

		return $excerpt;
	}
} 