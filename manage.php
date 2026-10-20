<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
$id = required_param('id', PARAM_INT);
$appid = optional_param('appid', 0, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
require_login();
$instance = $DB->get_record('enrol', ['id' => $id, 'enrol' => 'orderedapply'], '*', MUST_EXIST);
$course = get_course($instance->courseid);
$context = context_course::instance($course->id);
require_capability('enrol/orderedapply:review', $context);
$url = new moodle_url('/enrol/orderedapply/manage.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url($url, ['appid' => $appid, 'page' => $page]));
$PAGE->set_title(get_string('reviewapplications', 'enrol_orderedapply'));
$PAGE->set_heading(format_string($course->fullname));
$form = null;
if ($appid) {
    $app = $DB->get_record('enrol_orderedapply_app', ['id' => $appid, 'enrolid' => $id], '*', MUST_EXIST);
    if (in_array($app->status, ['pending', 'needsinfo', 'verified'], true)) {
        $form = new \enrol_orderedapply\form\review(new moodle_url($url, ['appid' => $appid]),
            ['id' => $id, 'appid' => $appid, 'revision' => $app->revision]);
        if ($form->is_cancelled()) { redirect($url); }
        if ($data = $form->get_data()) {
            require_sesskey();
            \enrol_orderedapply\service::review($appid, $data->decision, $data->note, $data->revision, !empty($data->attested));
            redirect(new moodle_url($url, ['appid' => $appid]), get_string('reviewed', 'enrol_orderedapply'));
        }
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reviewapplications', 'enrol_orderedapply'));
if (!\enrol_orderedapply\configuration::ready($instance)) {
    echo $OUTPUT->notification(get_string('configurationproblem', 'enrol_orderedapply'), 'warning');
}
echo html_writer::div(get_string('capacitysummary', 'enrol_orderedapply', (object)
    ['occupied' => \enrol_orderedapply\service::occupied($instance), 'capacity' => $instance->customint1]));
if ($appid) {
    echo $OUTPUT->heading(s($app->firstname . ' ' . $app->lastname), 3);
    echo html_writer::div(s($app->email) . ' | ' . s($app->identifier) . ' | ' . s($app->phone));
    echo html_writer::div(s(get_string('status_' . $app->status, 'enrol_orderedapply')));
    echo \enrol_orderedapply\presentation::documents($instance, $app);
    if ($form) { $form->display(); }
    echo $OUTPUT->heading(get_string('history', 'enrol_orderedapply'), 3);
    $table = new html_table();
    $table->head = [get_string('date'), get_string('user'), get_string('status'), get_string('note', 'enrol_orderedapply')];
    foreach ($DB->get_records('enrol_orderedapply_log', ['appid' => $appid], 'timecreated, id') as $log) {
        $table->data[] = [userdate($log->timecreated), (int)$log->actorid,
            s(get_string('status_' . $log->tostatus, 'enrol_orderedapply')), s($log->note)];
    }
    echo html_writer::table($table);
    echo html_writer::link($url, get_string('back'));
} else {
    $plan = \enrol_orderedapply\service::plan($instance);
    $reserved = array_fill_keys(array_merge($plan['reserve'], $plan['enrol']), true);
    $table = new html_table();
    $table->head = [get_string('submitted', 'enrol_orderedapply'), get_string('fullname'), get_string('status'),
        get_string('queue', 'enrol_orderedapply'), get_string('deadline', 'enrol_orderedapply'), get_string('review', 'enrol_orderedapply')];
    $apps = $DB->get_records('enrol_orderedapply_app', ['enrolid' => $id], 'timesubmitted, id', '*', $page * 50, 50);
    foreach ($apps as $app) {
        $turn = in_array($app->status, ['pending', 'needsinfo', 'verified'], true) ?
            get_string(isset($reserved[$app->id]) ? 'reserved' : 'waiting', 'enrol_orderedapply') : '—';
        $table->data[] = [userdate($app->timesubmitted), s($app->firstname . ' ' . $app->lastname),
            s(get_string('status_' . $app->status, 'enrol_orderedapply')), s($turn),
            $app->deadline ? userdate($app->deadline) : '—',
            html_writer::link(new moodle_url($url, ['appid' => $app->id]), get_string('review', 'enrol_orderedapply'))];
    }
    echo $apps ? html_writer::table($table) : $OUTPUT->notification(get_string('noapplications', 'enrol_orderedapply'), 'info');
    echo $OUTPUT->paging_bar($DB->count_records('enrol_orderedapply_app', ['enrolid' => $id]), $page, 50, $url);
}
echo $OUTPUT->footer();
