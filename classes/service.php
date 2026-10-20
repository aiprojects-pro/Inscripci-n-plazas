<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply;
defined('MOODLE_INTERNAL') || die();

/** All state changes and allocations share one course lock and one database transaction. */
final class service {
    public const ACTIVE = ['pending', 'needsinfo', 'verified', 'enrolled'];

    public static function can_access_documents(\context $context, \stdClass $app): bool {
        global $DB, $USER;
        return $context instanceof \context_course && isloggedin() && !isguestuser() &&
            $DB->record_exists('enrol', ['id' => $app->enrolid, 'courseid' => $context->instanceid, 'enrol' => 'orderedapply']) &&
            ((int)$USER->id === (int)$app->userid || has_capability('enrol/orderedapply:review', $context));
    }

    private static function locked(int $enrolid, callable $operation) {
        global $DB;
        $instance = $DB->get_record('enrol', ['id' => $enrolid, 'enrol' => 'orderedapply'], '*', MUST_EXIST);
        $factory = \core\lock\lock_config::get_lock_factory('enrol_orderedapply');
        $lock = $factory->get_lock('course:' . $instance->courseid, 10);
        if (!$lock) {
            throw new \moodle_exception('busy', 'enrol_orderedapply');
        }
        try {
            $instance = $DB->get_record('enrol', ['id' => $enrolid, 'enrol' => 'orderedapply'], '*', MUST_EXIST);
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation($instance);
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function occupied(\stdClass $instance): int {
        // Exclusive intake means every active enrolment consumes a seat, regardless of its role.
        return count_enrolled_users(\context_course::instance($instance->courseid), '', 0, true);
    }

    public static function plan(\stdClass $instance): array {
        global $DB;
        $apps = $DB->get_records('enrol_orderedapply_app', ['enrolid' => $instance->id], 'timesubmitted ASC, id ASC');
        return queue::plan((int)$instance->customint1, self::occupied($instance), array_values($apps));
    }

    /** Validate both the metadata and actual draft files; consultation is a recorded authorisation, not a query. */
    public static function validate_documents(\stdClass $instance, int $userid, array $input): array {
        $normalised = [];
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $maxbytes = min((int)$instance->customint4, get_max_upload_file_size());
        foreach (configuration::documents($instance) as $doc) {
            $entry = $input[$doc['key']] ?? [];
            $variant = [];
            if ($doc['mode'] === 'alternatives') {
                $chosen = null;
                foreach ($doc['alternatives'] as $option) {
                    if ($option['key'] === ($entry['variant'] ?? '')) { $chosen = $option; break; }
                }
                if (!$chosen) { throw new \moodle_exception('choosealternative', 'enrol_orderedapply', '', $doc['label']); }
                $variant = ['variant' => $chosen['key'], 'variantlabel' => $chosen['label']];
                $doc['maxage'] = $chosen['maxage'];
                $doc['consultation'] = $chosen['consultation'];
                $doc['mode'] = $chosen['consultation'] !== '' ? 'choice' : 'attachment';
            }
            $method = $entry['method'] ?? 'attachment';
            if ($method === 'consult' && $doc['mode'] === 'choice') {
                if (empty($entry['authorised'])) {
                    throw new \moodle_exception('missingconsent', 'enrol_orderedapply', '', $doc['label']);
                }
                $normalised[$doc['key']] = $variant + ['method' => 'consult', 'authorised' => true,
                    'authorisationtext' => $doc['consultation'], 'authorisedat' => time()];
                continue;
            }
            if ($method !== 'attachment') {
                throw new \invalid_parameter_exception('Invalid document method.');
            }
            $draftid = (int)($entry['draftid'] ?? 0);
            $files = $draftid ? $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false) : [];
            if (count($files) > 1 || ($doc['required'] && !$files)) {
                throw new \moodle_exception('missingdocument', 'enrol_orderedapply', '', $doc['label']);
            }
            foreach ($files as $file) {
                if ($file->get_filesize() > $maxbytes || !in_array(strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)),
                        ['pdf', 'png', 'jpg', 'jpeg'], true) ||
                        !in_array($file->get_mimetype(), ['application/pdf', 'image/png', 'image/jpeg'], true)) {
                    throw new \moodle_exception('badfile', 'enrol_orderedapply');
                }
            }
            $issued = (int)($entry['issued'] ?? 0);
            if ($files && $doc['maxage']) {
                $today = usergetmidnight(time());
                if (!$issued || $issued < $today - $doc['maxage'] * DAYSECS || $issued > time()) {
                    throw new \moodle_exception('documentdate', 'enrol_orderedapply', '', $doc['label']);
                }
            }
            $normalised[$doc['key']] = $variant + ['method' => 'attachment', 'draftid' => $draftid, 'issued' => $issued];
        }
        return $normalised;
    }

    public static function submit(int $enrolid, array $data): int {
        global $DB, $USER;
        require_login();
        if (isguestuser() || empty($USER->confirmed) || !empty($USER->suspended) || !validate_email($USER->email)) {
            throw new \moodle_exception('invaliduser', 'enrol_orderedapply');
        }
        return self::locked($enrolid, static function($instance) use ($data, $USER, $DB) {
            if (!configuration::ready($instance) || empty($instance->customint2)) {
                throw new \moodle_exception('notready', 'enrol_orderedapply');
            }
            if (($instance->enrolstartdate && time() < $instance->enrolstartdate) ||
                    ($instance->enrolenddate && time() > $instance->enrolenddate)) {
                throw new \moodle_exception('closed', 'enrol_orderedapply');
            }
            if (empty($data['privacyaccept']) || empty($instance->customchar1)) {
                throw new \moodle_exception('noprivacy', 'enrol_orderedapply');
            }
            $app = $DB->get_record('enrol_orderedapply_app', ['enrolid' => $instance->id, 'userid' => $USER->id]);
            if ($app && !in_array($app->status, ['pending', 'needsinfo', 'withdrawn'], true)) {
                throw new \moodle_exception('cannotedit', 'enrol_orderedapply');
            }
            if ($app && $app->status === 'needsinfo' && $app->deadline && time() > $app->deadline) {
                throw new \moodle_exception('correctionexpired', 'enrol_orderedapply');
            }
            $documents = self::validate_documents($instance, $USER->id, $data['documents'] ?? []);
            $identifier = trim((string)($data['identifier'] ?? ''));
            $phone = trim((string)($data['phone'] ?? ''));
            if (\core_text::strlen($identifier) > 100 || \core_text::strlen($phone) > 50 ||
                    (!empty($instance->customint5) && $identifier === '')) {
                throw new \moodle_exception('invalidpersonal', 'enrol_orderedapply');
            }
            $oldstatus = $app->status ?? '';
            $newturn = !$app || $oldstatus === 'withdrawn';
            if (!$app) {
                $app = (object)['enrolid' => $instance->id, 'userid' => $USER->id, 'revision' => 0, 'timecreated' => time()];
            }
            $app->status = 'pending';
            $app->firstname = $USER->firstname;
            $app->lastname = $USER->lastname;
            $app->email = $USER->email;
            $app->identifier = $identifier;
            $app->phone = $phone;
            $app->documents = '{}';
            $app->note = '';
            $app->deadline = 0;
            $app->timemodified = time();
            $app->timesubmitted = $newturn ? time() : $app->timesubmitted;
            $app->revision++;
            if (empty($app->id)) {
                $app->id = $DB->insert_record('enrol_orderedapply_app', $app);
            }
            $context = \context_course::instance($instance->courseid);
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'enrol_orderedapply', 'documents', $app->id);
            foreach ($documents as $key => &$document) {
                if ($document['method'] === 'attachment' && $document['draftid']) {
                    foreach ($fs->get_area_files(\context_user::instance($USER->id)->id, 'user', 'draft',
                            $document['draftid'], 'id', false) as $file) {
                        $fs->create_file_from_storedfile(['contextid' => $context->id, 'component' => 'enrol_orderedapply',
                            'filearea' => 'documents', 'itemid' => $app->id, 'filepath' => '/' . $key . '/',
                            'filename' => $file->get_filename(), 'userid' => $USER->id], $file);
                    }
                }
                unset($document['draftid']);
            }
            unset($document);
            $documents['_privacy'] = ['url' => $instance->customchar1, 'acceptedat' => time()];
            $app->documents = json_encode($documents, JSON_THROW_ON_ERROR);
            $DB->update_record('enrol_orderedapply_app', $app);
            self::log($app, 'submit', $oldstatus, 'pending', '');
            self::notify($app, $instance);
            self::allocate($instance);
            return (int)$app->id;
        });
    }

    public static function review(int $appid, string $decision, string $note, int $revision, bool $attested = false): void {
        global $DB;
        $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid], '*', MUST_EXIST);
        self::locked($app->enrolid, static function($instance) use ($appid, $decision, $note, $revision, $attested, $DB) {
            require_capability('enrol/orderedapply:review', \context_course::instance($instance->courseid));
            $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid, 'enrolid' => $instance->id], '*', MUST_EXIST);
            if ($app->revision !== $revision && (int)$app->revision !== $revision) {
                throw new \moodle_exception('stalereview', 'enrol_orderedapply');
            }
            if (!in_array($app->status, ['pending', 'needsinfo', 'verified'], true) ||
                    !in_array($decision, ['verified', 'needsinfo', 'rejected'], true)) {
                throw new \moodle_exception('invalidtransition', 'enrol_orderedapply');
            }
            if ($decision !== 'verified' && trim($note) === '') {
                throw new \moodle_exception('reasonrequired', 'enrol_orderedapply');
            }
            if ($decision === 'verified' && !$attested) {
                throw new \moodle_exception('missingattestation', 'enrol_orderedapply');
            }
            $old = $app->status;
            $app->status = $decision;
            $app->note = trim($note);
            $app->deadline = $decision === 'needsinfo' && $instance->customint3 ? time() + $instance->customint3 * DAYSECS : 0;
            $app->timemodified = time();
            $app->revision++;
            $DB->update_record('enrol_orderedapply_app', $app);
            self::log($app, 'review', $old, $decision, $app->note);
            self::notify($app, $instance);
            self::allocate($instance);
        });
    }

    public static function withdraw(int $appid): void {
        global $DB, $USER;
        $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid], '*', MUST_EXIST);
        self::locked($app->enrolid, static function($instance) use ($appid, $DB, $USER) {
            $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid], '*', MUST_EXIST);
            if ((int)$app->userid !== (int)$USER->id) {
                throw new \required_capability_exception(\context_course::instance($instance->courseid),
                    'enrol/orderedapply:review', 'nopermissions', '');
            }
            if (!in_array($app->status, self::ACTIVE, true)) {
                return;
            }
            $old = $app->status;
            $app->status = 'withdrawn';
            $app->timemodified = time();
            $app->revision++;
            $DB->update_record('enrol_orderedapply_app', $app);
            $plugin = enrol_get_plugin('orderedapply');
            $plugin->unenrol_user($instance, $app->userid);
            self::log($app, 'withdraw', $old, 'withdrawn', '');
            self::notify($app, $instance);
            self::allocate($instance);
        });
    }

    public static function process(int $enrolid): void {
        self::locked($enrolid, static fn($instance) => self::allocate($instance));
    }

    /** Only called inside locked(). Never release or reserve a seat in a separate transaction. */
    private static function allocate(\stdClass $instance): void {
        global $DB;
        if (!configuration::ready($instance)) {
            return;
        }
        $plan = self::plan($instance);
        foreach ($plan['enrol'] as $id) {
            $app = $DB->get_record('enrol_orderedapply_app', ['id' => $id], '*', MUST_EXIST);
            $user = $DB->get_record('user', ['id' => $app->userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1]);
            if (!$user || self::occupied($instance) >= $instance->customint1) {
                continue;
            }
            $plugin = enrol_get_plugin('orderedapply');
            $plugin->enrol_validated_user($instance, $app->userid);
            $app->status = 'enrolled';
            $app->revision++;
            $app->timemodified = time();
            $DB->update_record('enrol_orderedapply_app', $app);
            self::log($app, 'allocate', 'verified', 'enrolled', '');
            self::notify($app, $instance);
        }
    }

    private static function log(\stdClass $app, string $action, string $from, string $to, string $note): void {
        global $DB, $USER;
        $DB->insert_record('enrol_orderedapply_log', (object)['appid' => $app->id, 'actorid' => $USER->id ?? 0,
            'action' => $action, 'fromstatus' => $from, 'tostatus' => $to, 'note' => $note, 'timecreated' => time()]);
    }

    private static function notify(\stdClass $app, \stdClass $instance): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $body = get_string('status_' . $app->status, 'enrol_orderedapply') . "\n" . $course->fullname . "\n" .
            (new \moodle_url('/enrol/orderedapply/apply.php', ['id' => $instance->id]))->out(false);
        // Documents and reviewer notes never leave Moodle in email.
        $DB->insert_record('enrol_orderedapply_mail', (object)['appid' => $app->id, 'userid' => $app->userid,
            'body' => $body, 'timecreated' => time(), 'timesent' => 0]);
    }
}
