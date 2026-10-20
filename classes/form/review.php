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

class review extends \moodleform {
    public function definition() {
        $m = $this->_form;
        foreach (['id', 'appid', 'revision'] as $field) {
            $m->addElement('hidden', $field, $this->_customdata[$field]);
            $m->setType($field, PARAM_INT);
        }
        $choices = [];
        foreach (['verified', 'needsinfo', 'rejected'] as $choice) { $choices[$choice] = get_string($choice, 'enrol_orderedapply'); }
        $m->addElement('select', 'decision', get_string('decision', 'enrol_orderedapply'), $choices);
        $m->addElement('textarea', 'note', get_string('note', 'enrol_orderedapply'), ['rows' => 4]);
        $m->setType('note', PARAM_TEXT);
        $m->addElement('advcheckbox', 'attested', get_string('attestation', 'enrol_orderedapply'));
        $this->add_action_buttons(true, get_string('savechanges'));
    }
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['decision'] !== 'verified' && trim($data['note']) === '') {
            $errors['note'] = get_string('reasonrequired', 'enrol_orderedapply');
        }
        if ($data['decision'] === 'verified' && empty($data['attested'])) {
            $errors['attested'] = get_string('missingattestation', 'enrol_orderedapply');
        }
        return $errors;
    }
}
