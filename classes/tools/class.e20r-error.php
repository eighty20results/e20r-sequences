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

namespace E20R\Sequences\Tools;

class E20R_Error extends \WP_Error
{
    private $history = array();
    private static $_this = null;

    public function __construct()
    {
        if (null !== self::$_this ) {
            wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences'), get_class($this)));
        }

        self::$_this = $this;
    }

    private function configure() {

        return array(
            'setting' => null,
            'code' => null,
            'message' => null,
            'type' => null,
        );
    }

    public static function get_instance()
    {

        if ( self::$_this == null ) {
            self::$_this = new self;
        }

        return self::$_this;
    }

    public function set_error( $message = null, $type = 'error', $code = null, $setting = 'e20r_seq_errors' ) {

        $new = $this->configure();

        $new['setting'] = $setting;
        $new['code'] = $code;
        $new['type'] = $type;
        $new['message'] = $message;

        $this->history[] = $new;

        return true;
    }

    public function get_error( $type = null, $limit = null ) {

        $count = 0;
        if ( is_null( $type )) {

            return $this->history;
        }

        $result = array();

        if ( !is_null($limit)) {

            $count = 0;
        }
        foreach( $this->history as $e ) {

            if ( $type == $e['type'] ) {

                if ( !is_null($limit) && ( $count > $limit )) {
                    // quit since we're above the limit.
                    break;
                }

                $result[] = $e;

                if (!is_null($limit)) {
                    $count++;
                }
            }
        }

        return $result;
    }
}