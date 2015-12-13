<?php
/**
 * Created by PhpStorm.
 * User: sjolshag
 * Date: 12/13/15
 * Time: 1:26 PM
 */

namespace E20R\Sequences\Shortcodes;

class availableOn_shortcode
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
            wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20rsequence'), get_class($this)));
        }

        self::$_this = $this;

        add_filter('get_available_class_instance', [$this, 'get_instance']);
        add_shortcode('e20r_available_on', array($this, 'load_shortcode'));
    }

    /**
     *
     * Process the e20r_available_on shortcode
     *
     * @param null $attr - Attributes included in shortcode
     * @param null $content -- The content between [e20r_available_on][/e20r_available_on]
     * @return mixed|string|void - $content or a message about unavailability (default is null)
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

        $type = 'days';
        $delay = 0;

        global $current_user;

        $sequence_obj = apply_filters('get_sequence_class_instance', null);

        $attributes = shortcode_atts(array(
            'type' => 'days',
            'delay' => 0,
        ), $attr);

        if (!in_array($attributes['type'], array('days', 'date'))) {
            wp_die(__('%s is not a valid type attribute for the e20r_available_on shortcode', 'e20rsequence'), $type);
        }

        if (('date' == $attributes['type']) && (!$sequence_obj->is_valid_date($attributes['delay']))) {
            wp_die(__('%s is not a valid date format for the delay attribute in the e20r_available_on shortcode', 'e20rsequence'), $attributes['delay']);
        }

        // Converts to "days since startdate" for the current user, if provided a date
        // Otherwise assuming that the number is the number of days requested.
        $delay = $sequence_obj->convert_date_to_days($attributes['delay']);
        $days_since_start = $sequence_obj->get_membership_days($current_user->ID);

        if ($delay <= $days_since_start) {

            return do_shortcode($content);
        }

        return apply_filters('e20r-sequence-shortcode-text-unavailable', null);
    }

    /**
     * Returning the instance (used by the 'get_available_class_instance' hook)
     *
     * @return availableOn_shortcode
     *
     * * @since v4.0.1
     */
    public function get_instance()
    {
        return self::$_this;
    }
}