<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/enrol/orderedapply/index.php');
$PAGE->set_title(get_string('portal', 'enrol_orderedapply'));
$PAGE->set_heading(get_string('portal', 'enrol_orderedapply'));
echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('accounthelp', 'enrol_orderedapply'), 'info');
$instances = $DB->get_records('enrol', ['enrol' => 'orderedapply', 'status' => ENROL_INSTANCE_ENABLED], 'sortorder, id');
$shown = 0;
foreach ($instances as $instance) {
    $mine = $DB->get_record('enrol_orderedapply_app', ['enrolid' => $instance->id, 'userid' => $USER->id]);
    if (!$mine && (empty($instance->customint2) || !\enrol_orderedapply\configuration::ready($instance) ||
        ($instance->enrolstartdate && time() < $instance->enrolstartdate) ||
        ($instance->enrolenddate && time() > $instance->enrolenddate))) { continue; }
    $course = get_course($instance->courseid);
    if ((!$course->visible || !core_course_category::get($course->category)->is_uservisible()) &&
        !has_capability('moodle/course:viewhiddencourses', context_course::instance($course->id))) { continue; }
    $content = html_writer::tag('h2', format_string($course->fullname));
    if ($mine) { $content .= html_writer::div(s(get_string('status_' . $mine->status, 'enrol_orderedapply'))); }
    $content .= $OUTPUT->single_button(new moodle_url('/enrol/orderedapply/apply.php', ['id' => $instance->id]),
        get_string('apply', 'enrol_orderedapply'), 'get');
    echo $OUTPUT->box($content, 'generalbox mb-3');
    $shown++;
}
if (!$shown) { echo $OUTPUT->notification(get_string('nocourses', 'enrol_orderedapply'), 'info'); }
echo $OUTPUT->footer();
