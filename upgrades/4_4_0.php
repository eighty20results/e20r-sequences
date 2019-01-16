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

use E20R\Tools\DBG;
use E20R\Sequences\Sequence\Sequence_Controller;

function e20r_sequence_upgrade_settings_433() {

    $obj = apply_filters('get_sequence_class_instance', null);

    $sequence_list = Sequence_Controller::all_sequences('all');
    $settings_map = array(
        'hidden' => 'hideFuture', 'lengthVisible' => 'lengthVisible',
        'sortOrder' => 'sortOrder', 'delayType' => 'delayType', 'byDays' => 'byDays',
        'allowRepeatPosts' => 'allowRepeatPosts', 'showDelayAs' => 'showDelayAs',
        'previewOffset' => 'previewOffset', 'startWhen' => 'startWhen', 'sendNotice' => 'sendNotice',
        'noticeTemplate' => 'noticeTemplate', 'noticeSendAs' => 'noticeSendAs', 'noticeTime' => 'noticeTime',
        'noticeTimestamp' => 'noticeTimestamp', 'excerpt_intro' => 'excerptIntro', 'replyto' => 'replyto',
        'fromname' => 'fromname', 'subject' => 'subject', 'dateformat' => 'dateformat',
        'track_google_analytics' => 'trackGoogleAnalytics', 'ga_tid' => 'gaTid'
    );

    foreach( $sequence_list as $s ) {

        DBG::log("Converting settings for: {$s->ID} - {$s->post_title}");

        $old_settings = get_post_meta($s->ID, '_pmpro_sequence_settings', true);

        if ( isset($old_settings->hiddenFuture) ) {
            return;
        }

        $new_settings = new \stdClass();

        foreach( $old_settings as $key => $value ) {

            if ( !isset($old_settings->{$settings_map[$key]}) ) {
                continue;
            }

            $new_settings->{$settings_map[$key]} = $value;
        }

        DBG::log("New settings for: {$s->ID}: " . print_r($new_settings, true));
        // update_post_meta($s->ID, '_pmpro_sequence_settings', $new_settings);

        unset($new_settings);
        unset($old_settings);
    }
}

add_action('e20r_sequence_update_4.3.3', 'e20r_sequence_upgrade_settings_433');