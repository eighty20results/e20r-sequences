<?php
/**
 * Created by PhpStorm.
 * User: sjolshag
 * Date: 12/13/15
 * Time: 1:26 PM
 */

namespace E20R\Sequences\Shortcodes;
use E20R\Tools as E20RTools;

class Available_On
{

    private static $_this;

    /**
     * availableOn_shortcode constructor.
     *
     * @since v4.0.1
     */
    public function __construct()
    {
        if (isset(self::$_this)) {
            wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences'), get_class($this)));
        }

        self::$_this = $this;

        add_filter('get_available_class_instance', 'E20R\Sequences\Shortcodes\Available_On::get_instance' );
        add_shortcode('e20r_available_on', array($this, 'load_shortcode'));
    }

    /**
     *
     * Process the e20r_available_on shortcode
     *
     * @param null $attr - Attributes included in shortcode
     * @param null $content -- The content between [e20r_available_on][/e20r_available_on]
     * @return mixed|string - $content or a message about unavailability (default is null)
     *
     * @since v4.0.1
     */
    public function load_shortcode($attr = null, $content = null)
    {

        /**
         * Valid shortcode arguments:
         *  'type' => 'days' | 'date'
         *  'delay' => number of days | date a valid format (per strtotime).
         */

        global $current_user;
        $delay = 0;

        $sequence_obj = apply_filters('get_sequence_class_instance', null);
        E20RTools\DBG::log("Processing attributes.");

        $when = 'today';

        $attributes = shortcode_atts(array(
            'when' => 'today',
        ), $attr);

        /*
        if (!in_array($attributes['type'], array('days', 'date'))) {
            E20RTools\DBG::log("User didn't specify the correct type attribute in the shortcode definition. Used: {$attributes['type']}");
            wp_die( sprintf(__('%s is not a valid type attribute for the e20r_available_on shortcode', 'e20r-sequences'), $type));
        }
        */
        E20RTools\DBG::log("When attribute is specified: {$attributes['when']}");

        if ( !is_numeric( $attributes['when'] ) && (false === strtotime( $attributes['when'] )) ) {

            E20RTools\DBG::log("User didn't specify a recognizable format for the 'when' attribute");
            wp_die( sprintf(__('%s is not a recognizable format for the when attribute in the e20r_available_on shortcode', 'e20r-sequences'), $attributes['when']));
        }


        // Converts to "days since startdate" for the current user, if provided a date
        // Otherwise assuming that the number is the number of days requested.
        $delay = $sequence_obj->convert_date_to_days($attributes['when']);
        $days_since_start = $sequence_obj->get_membership_days($current_user->ID);

        if ($delay <= $days_since_start) {

            E20RTools\DBG::log("We need to display the content for the shortcode.");
            return do_shortcode($content);
        }

        E20RTools\DBG::log("We can't display the content within the shortcode block: {$delay} vs {$days_since_start}");
        return apply_filters('e20r-sequence-shortcode-text-not-available', null);
    }

    /**
     * Returning the instance (used by the 'get_available_class_instance' hook)
     *
     * @return Available_On
     *
     * * @since v4.0.1
     */
    public static function get_instance()
    {
    	if ( is_null( self::$_this ) ) {
    		self::$_this = new self;
	    }
	    
        return self::$_this;
    }
}