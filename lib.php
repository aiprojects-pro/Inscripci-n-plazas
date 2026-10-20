<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/** Controlled applications, review and ordered enrolment. */
class enrol_orderedapply_plugin extends enrol_plugin {
    public function roles_protected() { return true; }
    public function allow_enrol(stdClass $instance) { return false; }
    public function allow_manage(stdClass $instance) { return false; }
    public function allow_unenrol(stdClass $instance) { return false; }
    public function use_standard_editing_ui() { return true; }
    public function show_enrolme_link(stdClass $instance) { return true; }
    public function get_instance_defaults() {
        return ['status' => ENROL_INSTANCE_DISABLED, 'customint1' => 25, 'customint2' => 0, 'customint3' => 0,
            'customint4' => 10485760, 'customint5' => 0, 'customtext1' => '[]', 'customtext2' => '', 'customchar1' => ''];
    }
    public function can_add_instance($courseid) {
        global $DB;
        return has_capability('enrol/orderedapply:config', context_course::instance($courseid)) &&
            !$DB->record_exists('enrol', ['courseid' => $courseid, 'enrol' => 'orderedapply']);
    }
    public function add_instance($course, ?array $fields = null) {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('enrol_orderedapply')->get_lock('course:' . $course->id, 10);
        if (!$lock) { throw new moodle_exception('busy', 'enrol_orderedapply'); }
        try {
            if ($DB->record_exists('enrol', ['courseid' => $course->id, 'enrol' => 'orderedapply'])) {
                throw new moodle_exception('oneinstance', 'enrol_orderedapply');
            }
            $defaults = ['status' => ENROL_INSTANCE_DISABLED, 'customint1' => 25, 'customint2' => 0,
                'customint3' => 0, 'customint4' => 10485760, 'customint5' => 0, 'customtext1' => '[]',
                'roleid' => $DB->get_field('role', 'id', ['archetype' => 'student'], IGNORE_MULTIPLE)];
            return parent::add_instance($course, array_merge($defaults, \enrol_orderedapply\configuration::from_form($fields ?? [])));
        } finally { $lock->release(); }
    }
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addElement('select', 'status', get_string('enabled', 'enrol_orderedapply'), [ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no')]);
        $mform->setDefault('status', ENROL_INSTANCE_DISABLED);
        foreach (['customint1' => ['capacity', 25], 'customint3' => ['correctiondays', 0]] as $field => [$label, $default]) {
            $mform->addElement('text', $field, get_string($label, 'enrol_orderedapply'));
            $mform->setType($field, PARAM_INT);
            $mform->setDefault($field, $default);
        }
        $mform->addElement('selectyesno', 'customint2', get_string('applicationsopen', 'enrol_orderedapply'));
        $mform->setDefault('customint2', 0);
        $mform->addElement('selectyesno', 'customint5', get_string('requireidentifier', 'enrol_orderedapply'));
        $mform->setDefault('customint5', 0);
        $mform->addElement('select', 'customint4', get_string('maxbytes', 'enrol_orderedapply'),
            [1048576 => '1 MB', 5242880 => '5 MB', 10485760 => '10 MB', 20971520 => '20 MB']);
        $mform->setDefault('customint4', 10485760);
        $mform->addElement('textarea', 'customtext2', get_string('instructions', 'enrol_orderedapply'), ['rows' => 5]);
        $mform->setType('customtext2', PARAM_TEXT);
        $mform->addElement('text', 'customchar1', get_string('privacyurl', 'enrol_orderedapply'), ['size' => 70]);
        $mform->setType('customchar1', PARAM_URL);
        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('applicationstart', 'enrol_orderedapply'), ['optional' => true]);
        $mform->addElement('date_time_selector', 'enrolenddate', get_string('applicationend', 'enrol_orderedapply'), ['optional' => true]);
        $mform->addElement('static', 'notice', '', get_string('exclusivehelp', 'enrol_orderedapply'));
        $docs = \enrol_orderedapply\configuration::documents($instance);
        $mform->addElement('header', 'documentsheader', get_string('documents', 'enrol_orderedapply'));
        $mform->addElement('select', 'documentcount', get_string('documentcount', 'enrol_orderedapply'), array_combine(range(0, 20), range(0, 20)));
        $mform->setDefault('documentcount', count($docs));
        $mform->addElement('static', 'documenthelp', '', get_string('documenthelp', 'enrol_orderedapply'));
        for ($i = 0; $i < 20; $i++) {
            $doc = $docs[$i] ?? ['key' => 'doc' . ($i + 1), 'label' => '', 'mode' => 'attachment', 'required' => true, 'maxage' => 0, 'consultation' => ''];
            $mform->addElement('hidden', 'dockey_' . $i, $doc['key']);
            $mform->setType('dockey_' . $i, PARAM_ALPHANUMEXT);
            $mform->addElement('text', 'doclabel_' . $i, get_string('documentnumber', 'enrol_orderedapply', $i + 1));
            $mform->setType('doclabel_' . $i, PARAM_TEXT);
            $mform->setDefault('doclabel_' . $i, $doc['label']);
            $mform->addElement('select', 'docmode_' . $i, get_string('method', 'enrol_orderedapply'),
                ['attachment' => get_string('attachment', 'enrol_orderedapply'), 'choice' => get_string('uploadorconsult', 'enrol_orderedapply'),
                    'alternatives' => get_string('alternatives', 'enrol_orderedapply')]);
            $mform->setDefault('docmode_' . $i, $doc['mode']);
            $mform->addElement('selectyesno', 'docrequired_' . $i, get_string('required'));
            $mform->setDefault('docrequired_' . $i, (int)$doc['required']);
            $mform->addElement('text', 'docage_' . $i, get_string('maxage', 'enrol_orderedapply'));
            $mform->setType('docage_' . $i, PARAM_INT);
            $mform->setDefault('docage_' . $i, $doc['maxage']);
            $mform->addElement('textarea', 'docconsent_' . $i, get_string('consultationtext', 'enrol_orderedapply'), ['rows' => 3]);
            $mform->setType('docconsent_' . $i, PARAM_TEXT);
            $mform->setDefault('docconsent_' . $i, $doc['consultation']);
            foreach (['doclabel_', 'docmode_', 'docrequired_', 'docage_', 'docconsent_'] as $prefix) {
                $mform->hideIf($prefix . $i, 'documentcount', 'lt', $i + 1);
            }
            $mform->hideIf('docconsent_' . $i, 'docmode_' . $i, 'neq', 'choice');
            $mform->hideIf('docage_' . $i, 'docmode_' . $i, 'eq', 'alternatives');
            for ($n = 1; $n <= 2; $n++) {
                $option = $doc['alternatives'][$n - 1] ?? ['label' => '', 'maxage' => 0, 'consultation' => ''];
                $prefix = 'docoption' . $n;
                $mform->addElement('text', $prefix . 'label_' . $i, get_string('alternativename', 'enrol_orderedapply', $n));
                $mform->setType($prefix . 'label_' . $i, PARAM_TEXT);
                $mform->setDefault($prefix . 'label_' . $i, $option['label']);
                $mform->addElement('text', $prefix . 'age_' . $i, get_string('alternativeage', 'enrol_orderedapply', $n));
                $mform->setType($prefix . 'age_' . $i, PARAM_INT);
                $mform->setDefault($prefix . 'age_' . $i, $option['maxage']);
                $mform->addElement('textarea', $prefix . 'consent_' . $i, get_string('alternativeconsent', 'enrol_orderedapply', $n), ['rows' => 3]);
                $mform->setType($prefix . 'consent_' . $i, PARAM_TEXT);
                $mform->setDefault($prefix . 'consent_' . $i, $option['consultation']);
                foreach (['label_', 'age_', 'consent_'] as $field) {
                    $mform->hideIf($prefix . $field . $i, 'documentcount', 'lt', $i + 1);
                    $mform->hideIf($prefix . $field . $i, 'docmode_' . $i, 'neq', 'alternatives');
                }
            }
        }
        if ($instance && !empty($instance->id)) {
            $mform->addElement('static', 'reviewlink', '', html_writer::link(
                new moodle_url('/enrol/orderedapply/manage.php', ['id' => $instance->id]),
                get_string('reviewapplications', 'enrol_orderedapply')));
        }
    }
    public function edit_instance_validation($data, $files, $instance, $context) {
        global $DB;
        $errors = [];
        try { $data = \enrol_orderedapply\configuration::from_form($data); }
        catch (Throwable $e) { return ['documentcount' => get_string('invalidschema', 'enrol_orderedapply')]; }
        if ((int)$data['customint1'] < 1 || (int)$data['customint1'] > 100000) { $errors['customint1'] = get_string('invalidcapacity', 'enrol_orderedapply'); }
        if ((int)$data['customint3'] < 0 || (int)$data['customint3'] > 365) { $errors['customint3'] = get_string('invaliddays', 'enrol_orderedapply'); }
        if (!in_array((int)$data['customint4'], [1048576, 5242880, 10485760, 20971520], true)) {
            $errors['customint4'] = get_string('invaliduploadlimit', 'enrol_orderedapply');
        }
        if (!empty($data['enrolstartdate']) && !empty($data['enrolenddate']) && $data['enrolenddate'] <= $data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('invaliddates', 'enrol_orderedapply');
        }
        $schema = null;
        try { $schema = \enrol_orderedapply\configuration::documents((object)$data); }
        catch (Throwable $e) { $errors['documentcount'] = get_string('invalidschema', 'enrol_orderedapply'); }
        if (!empty($instance->id)) {
            if ((int)$data['customint1'] < \enrol_orderedapply\service::occupied($instance)) {
                $errors['customint1'] = get_string('capacitybelowoccupied', 'enrol_orderedapply');
            }
            if ($schema !== null && $DB->record_exists('enrol_orderedapply_app', ['enrolid' => $instance->id]) &&
                    $schema !== \enrol_orderedapply\configuration::documents($instance)) {
                $errors['documentcount'] = get_string('schemafrozen', 'enrol_orderedapply');
            }
        }
        if (!empty($data['customint2']) && empty($data['customchar1'])) {
            $errors['customchar1'] = get_string('privacyrequired', 'enrol_orderedapply');
        }
        if (!empty($data['customchar1']) && (core_text::strlen($data['customchar1']) > 255 ||
            !preg_match('~^https?://~i', $data['customchar1']) || !filter_var($data['customchar1'], FILTER_VALIDATE_URL))) {
            $errors['customchar1'] = get_string('invalidurl', 'error');
        }
        return $errors;
    }
    public function get_action_icons($instance) {
        global $OUTPUT;
        $icons = [];
        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/orderedapply:config', $context)) {
            $icons[] = $OUTPUT->action_icon(new moodle_url('/enrol/editinstance.php',
                ['id' => $instance->id, 'courseid' => $instance->courseid, 'type' => 'orderedapply']), new pix_icon('i/edit', get_string('edit')));
        }
        if (has_capability('enrol/orderedapply:review', $context)) {
            $icons[] = $OUTPUT->action_icon(new moodle_url('/enrol/orderedapply/manage.php', ['id' => $instance->id]),
                new pix_icon('i/users', get_string('reviewapplications', 'enrol_orderedapply')));
        }
        return $icons;
    }
    public function update_instance($instance, $data) {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('enrol_orderedapply')->get_lock('course:' . $instance->courseid, 10);
        if (!$lock) { throw new moodle_exception('busy', 'enrol_orderedapply'); }
        try {
            $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
            $data = (object)array_merge((array)$instance, \enrol_orderedapply\configuration::from_form((array)$data));
            $errors = $this->edit_instance_validation((array)$data, [], $instance, context_course::instance($instance->courseid));
            if ($errors) { throw new invalid_parameter_exception(implode(' ', $errors)); }
            $result = parent::update_instance($instance, $data);
        } finally { $lock->release(); }
        \enrol_orderedapply\service::process($instance->id);
        return $result;
    }
    public function delete_instance($instance) {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('enrol_orderedapply')->get_lock('course:' . $instance->courseid, 10);
        if (!$lock) { throw new moodle_exception('busy', 'enrol_orderedapply'); }
        try {
            foreach ($DB->get_records('enrol_orderedapply_app', ['enrolid' => $instance->id]) as $app) {
                \enrol_orderedapply\privacy\provider::erase_application($app);
            }
            parent::delete_instance($instance);
        } finally { $lock->release(); }
    }
    /** Copy configuration only. A restored course is closed until explicitly checked and reopened. */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        // Never merge queues or overwrite an existing intake during a course restore.
        if ($DB->record_exists('enrol', ['courseid' => $course->id, 'enrol' => 'orderedapply'])) {
            $step->set_mapping('enrol', $oldid, 0);
            return;
        }
        $fields = [];
        foreach (['name', 'customint1', 'customint3', 'customint4', 'customint5', 'customtext1', 'customtext2'] as $field) {
            if (isset($data->$field)) { $fields[$field] = $data->$field; }
        }
        // Privacy URLs and dates may belong to another organisation; require explicit configuration here.
        $fields['status'] = ENROL_INSTANCE_DISABLED;
        $fields['customint2'] = 0;
        $fields['customchar1'] = '';
        $step->set_mapping('enrol', $oldid, $this->add_instance($course, $fields));
    }
    public function enrol_page_hook(stdClass $instance) {
        global $OUTPUT;
        if (!\enrol_orderedapply\configuration::ready($instance)) { return ''; }
        return $OUTPUT->single_button(new moodle_url('/enrol/orderedapply/apply.php', ['id' => $instance->id]),
            get_string('apply', 'enrol_orderedapply'), 'get');
    }
    public function enrol_user(stdClass $instance, $userid, $roleid = null, $timestart = 0, $timeend = 0, $status = null, $recovergrades = null) {
        throw new coding_exception('Enrol through the ordered application service.');
    }
    /** Internal entry point: caller must hold the course lock, and have selected this verified application. */
    public function enrol_validated_user(stdClass $instance, int $userid): void {
        global $DB;
        if (!$DB->record_exists('enrol_orderedapply_app', ['enrolid' => $instance->id, 'userid' => $userid, 'status' => 'verified'])) {
            throw new coding_exception('An approved application is required.');
        }
        parent::enrol_user($instance, $userid, $instance->roleid, 0, 0, ENROL_USER_ACTIVE);
    }
}

function enrol_orderedapply_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    require_login();
    if ($context->contextlevel !== CONTEXT_COURSE || $filearea !== 'documents' || count($args) < 3) { send_file_not_found(); }
    $appid = (int)array_shift($args);
    $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid]);
    if (!$app || !\enrol_orderedapply\service::can_access_documents($context, $app)) { send_file_not_found(); }
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';
    $file = get_file_storage()->get_file($context->id, 'enrol_orderedapply', 'documents', $appid, $filepath, $filename);
    if (!$file || $file->is_directory()) { send_file_not_found(); }
    send_stored_file($file, 0, 0, true, $options);
}
