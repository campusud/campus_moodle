<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy class for requesting user data.
 *
 * @package    core_comment
 * @copyright  2018 Adrian Greeve <adrian@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_comment\privacy;

defined('MOODLE_INTERNAL') || die();

use \core_privacy\local\metadata\collection;
use \core_privacy\local\request\transform;

/**
 * Privacy class for requesting user data.
 *
 * @package    core_comment
 * @copyright  2018 Adrian Greeve <adrian@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\subsystem\plugin_provider {

    /**
     * Returns meta data about this system.
     *
     * @param   collection     $collection The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection) : collection {
        $collection->add_database_table('comments', [
                'content' => 'privacy:metadata:comment:content',
                'timecreated' => 'privacy:metadata:comment:timecreated',
                'userid' => 'privacy:metadata:comment:userid',
            ], 'privacy:metadata:comment');

        return $collection;
    }

    /**
     * Writes user data to the writer for the user to download.
     *
     * @param  array  $context Contexts to run through and return data.
     * @param  string $component The component that is calling this function
     * @param  string $commentarea The comment area related to the component
     * @param  int    $itemid An identifier for a group of comments
     * @param  array  $subcontext The sub-context in which to export this data
     * @param  bool   $onlyforthisuser  Only return the comments this user made.
     */
    public static function export_comments($context, $component, $commentarea, $itemid, $subcontext, $onlyforthisuser = true) {

        $data = new \stdClass;
        $data->context   = $context;
        $data->area      = $commentarea;
        $data->itemid    = $itemid;
        $data->component = $component;

        $commentobject = new \comment($data);
        $commentobject->set_view_permission(true);
        $comments = $commentobject->get_comments(0);
        $subcontext[] = get_string('commentsubcontext', 'core_comment');

        $comments = array_filter($comments, function($comment) use ($onlyforthisuser) {
            global $USER;

            return (!$onlyforthisuser || $comment->userid == $USER->id);
        });

        $comments = array_map(function($comment) {
            return (object) [
                'content' => $comment->content,
                'time' => transform::datetime($comment->timecreated),
                'userid' => transform::user($comment->userid),
            ];
        }, $comments);

        if (!empty($comments)) {
            \core_privacy\local\request\writer::with_context($context)
                ->export_data($subcontext, (object) [
                    'comments' => $comments,
                ]);
        }
    }

    /**
     * Deletes all comments for a specified context.
     *
     * @param  \context $context Details about which context to delete comments for.
     */
    public static function delete_comments_for_all_users_in_context(\context $context) {
        global $DB;
        $DB->delete_records('comments', ['contextid' => $context->id]);
    }

    /**
     * Deletes all records for a user from a list of approved contexts.
     *
     * @param  \core_privacy\local\request\approved_contextlist $contextlist Contains the user ID and a list of contexts to be
     * deleted from.
     */
    public static function delete_comments_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $contextids = implode(',', $contextlist->get_contextids());
        $params = ['userid' => $userid];
        list($insql, $inparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params += $inparams;

        $select = "userid = :userid and contextid $insql";
        $DB->delete_records_select('comments', $select, $params);
    }
}
