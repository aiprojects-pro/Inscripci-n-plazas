<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply\task;
defined('MOODLE_INTERNAL') || die();

/** Outbox messages are committed with decisions and delivered separately by Moodle cron. */
class maintenance extends \core\task\scheduled_task {
    public function get_name() { return get_string('maintenance', 'enrol_orderedapply'); }
    public function execute() {
        global $DB;
        if (!enrol_is_enabled('orderedapply')) { return; }
        $lock = \core\lock\lock_config::get_lock_factory('enrol_orderedapply')->get_lock('maintenance', 0);
        if (!$lock) { return; }
        try {
            $instances = $DB->get_records('enrol', ['enrol' => 'orderedapply', 'status' => ENROL_INSTANCE_ENABLED]);
            foreach ($instances as $instance) {
                try { \enrol_orderedapply\service::process($instance->id); }
                catch (\moodle_exception $e) { mtrace('Ordered applications: allocation failed for instance ' . $instance->id); }
            }
            foreach ($DB->get_records('enrol_orderedapply_mail', ['timesent' => 0], 'id', '*', 0, 100) as $mail) {
                $user = $DB->get_record('user', ['id' => $mail->userid, 'deleted' => 0, 'suspended' => 0]);
                if (!$user) {
                    $DB->set_field('enrol_orderedapply_mail', 'timesent', time(), ['id' => $mail->id]);
                    continue;
                }
                $message = new \core\message\message();
                $message->component = 'enrol_orderedapply';
                $message->name = 'application';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = get_string('notification', 'enrol_orderedapply');
                $message->fullmessage = $mail->body;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = '';
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                if (message_send($message)) {
                    $DB->set_field('enrol_orderedapply_mail', 'timesent', time(), ['id' => $mail->id]);
                }
            }
        } finally { $lock->release(); }
    }
}
