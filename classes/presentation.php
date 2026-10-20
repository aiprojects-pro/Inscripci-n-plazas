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

/** Shared escaped display of an application, accessible only after page-level ownership checks. */
final class presentation {
    public static function documents(\stdClass $instance, \stdClass $app): string {
        $context = \context_course::instance($instance->courseid);
        $metadata = json_decode($app->documents, true) ?? [];
        $output = '';
        foreach (configuration::documents($instance) as $doc) {
            $entry = $metadata[$doc['key']] ?? [];
            $body = '';
            if (($entry['method'] ?? '') === 'consult') {
                $body = s($entry['authorisationtext'] ?? '') . '<br>' . userdate($entry['authorisedat']);
            } else {
                $files = get_file_storage()->get_area_files($context->id, 'enrol_orderedapply', 'documents',
                    $app->id, 'id', false);
                foreach ($files as $file) {
                    if ($file->get_filepath() !== '/' . $doc['key'] . '/') { continue; }
                    $url = \moodle_url::make_pluginfile_url($context->id, 'enrol_orderedapply', 'documents',
                        $app->id, $file->get_filepath(), $file->get_filename(), true);
                    $body .= \html_writer::div(\html_writer::link($url, s($file->get_filename())));
                }
                if (!empty($entry['issued'])) { $body .= s(get_string('issued', 'enrol_orderedapply')) . ': ' . userdate($entry['issued']); }
            }
            if (!empty($entry['variantlabel'])) { $body = s($entry['variantlabel']) . '<br>' . $body; }
            $output .= \html_writer::tag('dt', s($doc['label'])) . \html_writer::tag('dd', $body ?: '—');
        }
        return \html_writer::tag('dl', $output);
    }
}
