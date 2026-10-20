<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
$id = required_param('id', PARAM_INT);
$withdraw = optional_param('withdraw', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
require_login();
if (isguestuser()) { throw new moodle_exception('invaliduser', 'enrol_orderedapply'); }
$instance = $DB->get_record('enrol', ['id' => $id, 'enrol' => 'orderedapply'], '*', MUST_EXIST);
$course = get_course($instance->courseid);
$context = context_course::instance($course->id);
if ((!$course->visible || !core_course_category::get($course->category)->is_uservisible()) &&
    !has_capability('moodle/course:viewhiddencourses', $context)) { throw new moodle_exception('notready', 'enrol_orderedapply'); }
$url = new moodle_url('/enrol/orderedapply/apply.php', ['id' => $id]);
// Repository AJAX calls require access to the page context. Applicants are not enrolled yet,
// so draft uploads must use their own user context, while submitted files stay private in the course.
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('apply', 'enrol_orderedapply'));
$PAGE->set_heading(format_string($course->fullname));
$app = $DB->get_record('enrol_orderedapply_app', ['enrolid' => $id, 'userid' => $USER->id]);
if ($withdraw && $app) {
    if ($confirm && data_submitted() && confirm_sesskey()) {
        \enrol_orderedapply\service::withdraw($app->id);
        redirect($url, get_string('withdrawn', 'enrol_orderedapply'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('withdrawconfirm', 'enrol_orderedapply'), new moodle_url($url,
        ['withdraw' => 1, 'confirm' => 1, 'sesskey' => sesskey()]), $url);
    echo $OUTPUT->footer();
    exit;
}
$editable = (!$app || in_array($app->status, ['pending', 'needsinfo', 'withdrawn'], true)) &&
    (!$app || !$app->deadline || time() <= $app->deadline) &&
    \enrol_orderedapply\configuration::ready($instance) && $instance->customint2 &&
    (!$instance->enrolstartdate || time() >= $instance->enrolstartdate) &&
    (!$instance->enrolenddate || time() <= $instance->enrolenddate);
$form = null;
if ($editable) {
    $form = new \enrol_orderedapply\form\application($url, ['instance' => $instance]);
    if ($form->is_cancelled()) { redirect(new moodle_url('/enrol/orderedapply/index.php')); }
    if ($data = $form->get_data()) {
        require_sesskey();
        $data->documents = \enrol_orderedapply\form\application::documents((array)$data);
        \enrol_orderedapply\service::submit($id, (array)$data);
        redirect($url, get_string('saved', 'enrol_orderedapply'));
    }
    $defaults = ['identifier' => $app->identifier ?? '', 'phone' => $app->phone ?? ''];
    $metadata = $app ? (json_decode($app->documents, true) ?? []) : [];
    foreach (\enrol_orderedapply\configuration::documents($instance) as $doc) {
        $key = $doc['key'];
        $draft = file_get_submitted_draft_itemid('file_' . $key);
        if (!$draft) {
            $draft = file_get_unused_draft_itemid();
            if ($app) {
                foreach (get_file_storage()->get_area_files($context->id, 'enrol_orderedapply', 'documents', $app->id, 'id', false) as $file) {
                    if ($file->get_filepath() !== '/' . $key . '/') { continue; }
                    get_file_storage()->create_file_from_storedfile(['contextid' => context_user::instance($USER->id)->id,
                        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/', 'userid' => $USER->id], $file);
                }
            }
        }
        $defaults['file_' . $key] = $draft;
        $variant = $metadata[$key]['variant'] ?? '';
        $defaults['variant_' . $key] = $variant;
        $suffix = $variant !== '' ? $key . '_' . $variant : $key;
        $defaults['method_' . $suffix] = $metadata[$key]['method'] ?? 'attachment';
        $defaults['issued_' . $suffix] = $metadata[$key]['issued'] ?? 0;
        // A consultation authorisation must be given again explicitly after editing.
    }
    $form->set_data($defaults);
}
echo $OUTPUT->header();
echo html_writer::div(s(fullname($USER)) . ' — ' . s($USER->email));
echo html_writer::link(new moodle_url('/user/edit.php'), get_string('profile', 'enrol_orderedapply'));
if ($instance->customtext2) { echo $OUTPUT->box(format_text($instance->customtext2, FORMAT_PLAIN)); }
if ($app) {
    echo $OUTPUT->heading(get_string('status_' . $app->status, 'enrol_orderedapply'), 3);
    echo html_writer::div(s(get_string('submitted', 'enrol_orderedapply')) . ': ' . userdate($app->timesubmitted));
    if ($app->note) { echo $OUTPUT->box(format_text($app->note, FORMAT_PLAIN)); }
    if ($app->deadline) { echo html_writer::div(get_string('deadline', 'enrol_orderedapply') . ': ' . userdate($app->deadline)); }
    if ($app->status === 'enrolled') {
        echo $OUTPUT->single_button(new moodle_url('/course/view.php', ['id' => $course->id]), get_string('courseaccess', 'enrol_orderedapply'), 'get');
    }
    if (!$editable) { echo \enrol_orderedapply\presentation::documents($instance, $app); }
    if (in_array($app->status, \enrol_orderedapply\service::ACTIVE, true)) {
        echo $OUTPUT->single_button(new moodle_url($url, ['withdraw' => 1]), get_string('withdraw', 'enrol_orderedapply'), 'get');
    }
}
if ($form) { $form->display(); }
else if (!$app) { echo $OUTPUT->notification(get_string('closed', 'enrol_orderedapply'), 'info'); }
echo $OUTPUT->footer();
