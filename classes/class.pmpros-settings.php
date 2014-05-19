<?php
/**
    Plugin Name: PMPro Sequence
    Plugin URI: http://www.eighty20results.com/pmpro-sequence/
    Description: Offer serialized (drip feed) content to your PMPro members.
    Version: .1.0
    Author: Eighty / 20 Results <thomas@eighty20results.com>
    Author URI: http://www.eighty20results.com
 */

require_once dirname( __FILE__ ) . '/class.settings-api.php';

if ( !class_exists( 'PMPros_Settings' ) ):
class PMPros_Settings {

    private $settings_api;
    private $sequence = array();
    private $options;

    /**
     * Class constructor (creates the
     */
    public function __construct( $postID = null )
    {
       $this->settings_api = new WeDevs_Settings_API;

       add_action( 'admin_init', array( $this, 'admin_init' ) );
       add_action( 'admin_menu', array( $this, 'admin_menu' ) );
    }

    /**
     * Init for PMPro Sequence Settings (Settings Page)
     */
    public function admin_init()
    {
        $this->settings_api->set_sections( $this->get_settings_sections() );
        $this->settings_api->set_fields( $this->get_settings_fields() );

        $this->settings_api->admin_init();

    }

    public function page_init()
    {
        $this->settings_api->set_fields( $this->get_settings_fields() );

        $this->settings_api->admin_init();
    }
    /**
     * Add the settings entry for the PMPro Sequence to the default "Settings" admin menu
     * Only visible to users with 'manage_options' privileges
     */
    public function admin_menu()
    {
        add_options_page( 'PMPro Sequences', 'PMPro Sequences', 'manage_options', 'pmpro_sequence', array( $this, 'plugin_page' ));
    }


    /**
     * Load metadata related to the sequence (used to populate Settings tabs)
     */
    public function load_sequence_data()
    {
        $args = array(
            'post_type' => 'pmpro_sequence',
            'orderby' => 'title',
            'posts_per_page' => -1,
            'caller_get_posts' => 1
        );

        $tmpPosts = null;
        $tmpPosts = get_posts($args);

        foreach ( $tmpPosts as $post) : setup_postdata($post);

            $this->sequence[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->post_name
            );

        endforeach;

        // Create dummy entry for when there are no Sequences defined
        if ( empty($tmpPosts[0]) )
            $this->sequence[] = array(
                'id' => 'default',
                'title' => 'empty',
                'slug' => 'undefined'
            );

        wp_reset_postdata();

    }

    /**
     * Debug function - Only if PMPROS_SEQUENCE_DEBUG is true / defined
     *
     * @param $msg -- Debug message to print to debug log.
     */
    public function dbgOut($msg)
    {
        if (PMPROS_SEQUENCE_DEBUG)
        {
            $tmpFile = './sequence_log.txt';
            $fh = fopen($tmpFile, 'a');
            fwrite($fh, $msg . "\r\n");
            fclose($fh);
        }
    }


    /**
     * @param int|null $sequence_id - The ID used to load the sequence options from the database
     */
    public function load_settings( $sequence_id = null)
    {

        if ( is_null($sequence_id) )
        {
            $this->dbgOut('No sequence specified. Using default options');
            $this->options = $this->defaultOptions();
            return $this->options;
        }

        $args = array(
            'post_type' => 'pmpro_sequence',
            'orderby' => 'title',
            'posts_per_page' => 1, // Only need the one post
            'caller_get_posts' => 1
        );

        $post = null;
        $post = get_single_post($sequence_id);

        setup_postdata($post);
        if ( intval($sequence_id) == intval($post->ID) )
        {
            $this->options = get_option( 'pmpro-sequence-' . intval($sequence_id) );
            $this->dbgOut('Retrieved options for sequence #' . $sequence_id);
        }
        else
        {
            $this->dbgOut('Post ' . $post->ID .' not in sequence for ' . $sequence_id);
        }

        wp_reset_postdata();

        return $$this->options;
    }
    /**
     *
     * Create sections for the PMPro Sequence settings page
     *
     * @return array -- Array of sections (tabs) for the settings page
     */
    public function get_settings_sections()
    {
        /* If the list is empty, grab the list of pmpro_sequence CPT entries */
        if (empty($this->sequence[0]))
            $this->load_sequence_data();

        $sections = array();

        // Append a new section for each of the defined & published sequence
        foreach ($this->sequence as $post)
        {
            $sections[] = array(
                'id' => 'pmpro-sequence-' . $post['id'], // Series slug
                'title' => __( 'Settings ( ' . $post['title'] . ' )', 'pmpro_sequence' ) // Series Title
            );
        }

        return $sections;
    }

    /**
     *
     * Define settings fields for each of the sections on the settings page
     *
     * @return array -- Array of fields for each of the defined sections / sequence
     */
    public function get_settings_fields()
    {
        if (empty($this->sequence[0]))
            $this->load_sequence_data();

        $settings_fields = array();

        foreach ($this->sequence as $post)
        {
            $settings_fields[ 'pmpro-sequence-' . $post['id']] = array(
                    array(
                        'name' => 'hidden',
                        'label' => __( 'Hide', 'pmpro_sequence' ),
                        'desc' => __( 'Hide future posts', 'pmpro_sequence' ),
                        'type' => 'checkbox',
                    ),
                    array(
                        'name' => 'dayCount',
                        'label' => __( 'Show Day Count', 'pmpro_sequence' ),
                        'desc' => __( 'Show the "You are on day NNN of your membership" text', 'pmpro_sequence' ),
                        'type' => 'checkbox',
                    ),
                    array(
                        'name' => 'sortOrder',
                        'label' => __( 'Sort Order', 'pmpro_sequence' ),
                        'desc' => __( 'The sort order for this sequence', 'pmpro_sequence' ),
                        'type' => 'select',
                        'options' => array(
                            SORT_ASC => 'Ascending',
                            SORT_DESC => 'Descending',
                        )
                    ),
                    array(
                        'name' => 'delayType',
                        'label' => __( 'Delay Type', 'pmpro_sequence' ),
                        'desc' => __( 'The type of value used to specify the delay', 'pmpro_sequence' ),
                        'type' => 'select',
                        'options' => array(
                            'byDays' => '# of Days',
                            'byDate' => 'Date (YYYY-MM-DD)'
                        ),
                    ),/* TODO: Uncomment to support custom rewrite rules for the sequence URL
                    array(
                        'name' => 'sequence-prefix',
                        'label' => __('Series Prefix', 'pmpro_sequence'),
                        'desc' => __('The prefix to use for this sequence (i.e. "/prefix/sequence_name)"', 'pmpro_sequence'),
                        'type' => 'text',
                    ) */
                );
        }

        // echo '<div>' . var_dump($settings_fields) . '</div>';

        return $settings_fields;
    }

    /**
     * Create the page for the PMPro Sequence settings
     */
    public function plugin_page()
    {
        echo '<div class="wrap">';
        $this->settings_api->show_navigation();
        $this->settings_api->show_forms();

        echo '</div>';
    }

    /**
     * Creates the page list & titles
     *
     * @return array -- Array of option pages (i.e. data in the tabs for the settings/options)
     */
    public function get_pages()
    {
        $pages = get_pages();
        $pages_options = array();

        if ( $pages )
        {
            foreach ( $pages as $page )
            {
                $pages_options[$page->ID] = $page->$post_title;
            }
        }

        return $pages_options;
    }
}
endif;
