<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply\privacy;
defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\{contextlist, approved_contextlist, userlist, approved_userlist, writer};

/** Export/deletion cover applicants, reviewers, private files and the notification outbox. */
class provider implements \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider, \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        $tables = [
            'enrol_orderedapply_app' => ['enrolid', 'userid', 'status', 'firstname', 'lastname', 'email', 'identifier', 'phone',
                'documents', 'note', 'timecreated', 'timesubmitted', 'timemodified', 'deadline', 'revision'],
            'enrol_orderedapply_log' => ['appid', 'actorid', 'action', 'fromstatus', 'tostatus', 'note', 'timecreated'],
            'enrol_orderedapply_mail' => ['appid', 'userid', 'body', 'timecreated', 'timesent'],
        ];
        foreach ($tables as $table => $fields) {
            $collection->add_database_table($table, array_fill_keys($fields, 'privacy:field'), 'privacy:metadata');
        }
        $collection->add_subsystem_link('core_files', [], 'privacy:files');
        $collection->add_subsystem_link('core_message', [], 'privacy:messages');
        return $collection;
    }
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql('SELECT DISTINCT c.id FROM {context} c
            JOIN {enrol} e ON e.courseid = c.instanceid AND c.contextlevel = :level
            JOIN {enrol_orderedapply_app} a ON a.enrolid = e.id
            LEFT JOIN {enrol_orderedapply_log} l ON l.appid = a.id
            WHERE a.userid = :owner OR l.actorid = :actor', ['level' => CONTEXT_COURSE, 'owner' => $userid, 'actor' => $userid]);
        return $list;
    }
    public static function get_users_in_context(userlist $list) {
        $context = $list->get_context();
        if (!$context instanceof \context_course) { return; }
        $list->add_from_sql('userid', 'SELECT a.userid FROM {enrol_orderedapply_app} a
            JOIN {enrol} e ON e.id = a.enrolid WHERE e.courseid = :course', ['course' => $context->instanceid]);
        $list->add_from_sql('actorid', 'SELECT l.actorid FROM {enrol_orderedapply_log} l
            JOIN {enrol_orderedapply_app} a ON a.id = l.appid JOIN {enrol} e ON e.id = a.enrolid
            WHERE e.courseid = :course AND l.actorid > 0', ['course' => $context->instanceid]);
    }
    public static function export_user_data(approved_contextlist $list) {
        global $DB;
        $userid = $list->get_user()->id;
        foreach ($list->get_contexts() as $context) {
            if (!$context instanceof \context_course) { continue; }
            foreach (self::applications($context) as $app) {
                $path = [get_string('pluginname', 'enrol_orderedapply'), (string)$app->id];
                if ((int)$app->userid === (int)$userid) {
                    $data = clone $app;
                    $data->history = array_values($DB->get_records('enrol_orderedapply_log', ['appid' => $app->id]));
                    $data->notifications = array_values($DB->get_records('enrol_orderedapply_mail', ['appid' => $app->id]));
                    writer::with_context($context)->export_data($path, $data);
                    writer::with_context($context)->export_area_files($path, 'enrol_orderedapply', 'documents', $app->id);
                } else {
                    $logs = $DB->get_records('enrol_orderedapply_log', ['appid' => $app->id, 'actorid' => $userid]);
                    if ($logs) { writer::with_context($context)->export_data($path, (object)['reviews' => array_values($logs)]); }
                }
            }
        }
    }
    private static function applications(\context_course $context): array {
        global $DB;
        return $DB->get_records_sql('SELECT a.* FROM {enrol_orderedapply_app} a JOIN {enrol} e ON e.id = a.enrolid
            WHERE e.courseid = :course', ['course' => $context->instanceid]);
    }
    /** Internal cleanup used by both privacy erasure and instance deletion; caller holds course lock. */
    public static function erase_application(\stdClass $app): void {
        global $DB;
        $courseid = $DB->get_field('enrol', 'courseid', ['id' => $app->enrolid]);
        if ($courseid) {
            get_file_storage()->delete_area_files(\context_course::instance($courseid)->id, 'enrol_orderedapply', 'documents', $app->id);
        }
        $DB->delete_records('enrol_orderedapply_mail', ['appid' => $app->id]);
        $DB->delete_records('enrol_orderedapply_log', ['appid' => $app->id]);
        $DB->delete_records('enrol_orderedapply_app', ['id' => $app->id]);
    }
    private static function erase(\context $context, ?array $userids): void {
        global $DB;
        if (!$context instanceof \context_course) { return; }
        $lock = \core\lock\lock_config::get_lock_factory('enrol_orderedapply')->get_lock('course:' . $context->instanceid, 10);
        if (!$lock) { throw new \moodle_exception('busy', 'enrol_orderedapply'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            foreach (self::applications($context) as $app) {
                if ($userids === null || in_array((int)$app->userid, $userids, true)) {
                    self::erase_application($app);
                } else {
                    foreach ($userids as $userid) {
                        $DB->set_field('enrol_orderedapply_log', 'actorid', 0, ['appid' => $app->id, 'actorid' => $userid]);
                    }
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }
    public static function delete_data_for_all_users_in_context(\context $context) { self::erase($context, null); }
    public static function delete_data_for_user(approved_contextlist $list) {
        foreach ($list->get_contexts() as $context) { self::erase($context, [(int)$list->get_user()->id]); }
    }
    public static function delete_data_for_users(approved_userlist $list) {
        self::erase($list->get_context(), array_map('intval', $list->get_userids()));
    }
}
