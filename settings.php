<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading('enrol_orderedapply/portal', get_string('pluginname', 'enrol_orderedapply'),
        html_writer::link(new moodle_url('/enrol/orderedapply/index.php'), get_string('portal', 'enrol_orderedapply')) .
        html_writer::div(get_string('exclusivehelp', 'enrol_orderedapply'))));
}
