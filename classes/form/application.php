<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply\form;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/** The user account exists before this form; it grants no course enrolment. */
class application extends \moodleform {
    public function definition() {
        $m = $this->_form;
        $instance = $this->_customdata['instance'];
        $m->addElement('hidden', 'id', $instance->id);
        $m->setType('id', PARAM_INT);
        $m->addElement('static', 'personalhelp', '', get_string('personalhelp', 'enrol_orderedapply'));
        $m->addElement('text', 'identifier', get_string('identifier', 'enrol_orderedapply'), ['maxlength' => 100]);
        $m->setType('identifier', PARAM_TEXT);
        if ($instance->customint5) { $m->addRule('identifier', null, 'required', null, 'client'); }
        $m->addElement('text', 'phone', get_string('phone', 'enrol_orderedapply'), ['maxlength' => 50]);
        $m->setType('phone', PARAM_TEXT);
        foreach (\enrol_orderedapply\configuration::documents($instance) as $doc) {
            $key = $doc['key'];
            $m->addElement('header', 'header_' . $key, $doc['label']);
            if ($doc['mode'] === 'alternatives') {
                $choices = ['' => get_string('choose')];
                foreach ($doc['alternatives'] as $option) { $choices[$option['key']] = $option['label']; }
                $m->addElement('select', 'variant_' . $key, get_string('documenttype', 'enrol_orderedapply'), $choices);
                foreach ($doc['alternatives'] as $option) {
                    $suffix = $key . '_' . $option['key'];
                    if ($option['consultation'] !== '') {
                        $m->addElement('select', 'method_' . $suffix, get_string('method', 'enrol_orderedapply'),
                            ['attachment' => get_string('attachment', 'enrol_orderedapply'), 'consult' => get_string('consult', 'enrol_orderedapply')]);
                        $m->addElement('static', 'text_' . $suffix, '', s($option['consultation']));
                        $m->addElement('advcheckbox', 'authorised_' . $suffix, get_string('authorised', 'enrol_orderedapply'));
                        foreach (['method_', 'text_', 'authorised_'] as $prefix) {
                            $m->hideIf($prefix . $suffix, 'variant_' . $key, 'neq', $option['key']);
                        }
                    }
                    if ($option['maxage']) {
                        $m->addElement('date_selector', 'issued_' . $suffix, get_string('issued', 'enrol_orderedapply'), ['optional' => true]);
                        $m->hideIf('issued_' . $suffix, 'variant_' . $key, 'neq', $option['key']);
                    }
                }
                $m->addElement('static', 'alternativehelp_' . $key, '', get_string('alternativehelp', 'enrol_orderedapply'));
            }
            if ($doc['mode'] === 'choice') {
                $m->addElement('select', 'method_' . $key, get_string('method', 'enrol_orderedapply'),
                    ['attachment' => get_string('attachment', 'enrol_orderedapply'), 'consult' => get_string('consult', 'enrol_orderedapply')]);
                $m->addElement('static', 'text_' . $key, '', s($doc['consultation']));
                $m->addElement('advcheckbox', 'authorised_' . $key, get_string('authorised', 'enrol_orderedapply'));
                $m->hideIf('authorised_' . $key, 'method_' . $key, 'neq', 'consult');
            }
            $m->addElement('filemanager', 'file_' . $key, get_string('attachment', 'enrol_orderedapply'), null,
                ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => min($instance->customint4, get_max_upload_file_size()),
                    'accepted_types' => ['.pdf', '.png', '.jpg', '.jpeg']]);
            if ($doc['mode'] === 'choice') { $m->hideIf('file_' . $key, 'method_' . $key, 'eq', 'consult'); }
            if ($doc['maxage']) {
                $m->addElement('date_selector', 'issued_' . $key, get_string('issued', 'enrol_orderedapply'), ['optional' => true]);
                if ($doc['mode'] === 'choice') { $m->hideIf('issued_' . $key, 'method_' . $key, 'eq', 'consult'); }
            }
        }
        $m->addElement('header', 'privacy', get_string('privacyinfo', 'enrol_orderedapply'));
        $m->addElement('static', 'privacylink', '', \html_writer::link(new \moodle_url($instance->customchar1),
            get_string('privacyinfo', 'enrol_orderedapply'), ['target' => '_blank', 'rel' => 'noopener']));
        $m->addElement('advcheckbox', 'privacyaccept', get_string('privacyaccept', 'enrol_orderedapply'));
        $this->add_action_buttons(true, get_string('submitapplication', 'enrol_orderedapply'));
    }

    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        if (empty($data['privacyaccept'])) { $errors['privacyaccept'] = get_string('noprivacy', 'enrol_orderedapply'); }
        try {
            \enrol_orderedapply\service::validate_documents($this->_customdata['instance'], $USER->id, self::documents($data));
        } catch (\moodle_exception $e) {
            // Keep all draft files and display the server validation error on the form.
            $errors['identifier'] = $e->getMessage();
        }
        return $errors;
    }

    public static function documents(array $data): array {
        $result = [];
        foreach ($data as $field => $value) {
            if (str_starts_with($field, 'file_')) {
                $key = substr($field, 5);
                $variant = $data['variant_' . $key] ?? '';
                $suffix = $variant !== '' ? $key . '_' . $variant : $key;
                $result[$key] = ['draftid' => (int)$value, 'variant' => $variant,
                    'method' => $data['method_' . $suffix] ?? 'attachment',
                    'authorised' => !empty($data['authorised_' . $suffix]), 'issued' => $data['issued_' . $suffix] ?? 0];
            }
        }
        return $result;
    }
}
