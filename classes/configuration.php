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

/** Validated, portable per-course document schema. */
final class configuration {
    /** Convert the administrator's ordinary form fields to the portable stored schema. */
    public static function from_form(array $fields): array {
        if (!array_key_exists('documentcount', $fields)) { return $fields; }
        $count = (int)$fields['documentcount'];
        if ($count < 0 || $count > 20) { throw new \invalid_parameter_exception('Invalid document count.'); }
        $schema = [];
        for ($i = 0; $i < $count; $i++) {
            $schema[] = ['key' => $fields['dockey_' . $i] ?? 'doc' . ($i + 1),
                'label' => trim($fields['doclabel_' . $i] ?? ''), 'mode' => $fields['docmode_' . $i] ?? 'attachment',
                'required' => !empty($fields['docrequired_' . $i]), 'maxage' => (int)($fields['docage_' . $i] ?? 0),
                'consultation' => trim($fields['docconsent_' . $i] ?? '')];
            if ($schema[$i]['mode'] === 'alternatives') {
                $schema[$i]['maxage'] = 0;
                $schema[$i]['alternatives'] = [];
                for ($n = 1; $n <= 2; $n++) {
                    $schema[$i]['alternatives'][] = ['key' => 'option' . $n,
                        'label' => trim($fields['docoption' . $n . 'label_' . $i] ?? ''),
                        'maxage' => (int)($fields['docoption' . $n . 'age_' . $i] ?? 0),
                        'consultation' => trim($fields['docoption' . $n . 'consent_' . $i] ?? '')];
                }
            }
        }
        $fields['customtext1'] = json_encode($schema, JSON_THROW_ON_ERROR);
        foreach (array_keys($fields) as $key) {
            if ($key === 'documentcount' || preg_match('/^doc(key|label|mode|required|age|consent|option[12](label|age|consent))_\d+$/D', $key)) { unset($fields[$key]); }
        }
        return $fields;
    }
    public static function documents(\stdClass $instance): array {
        $schema = json_decode(($instance->customtext1 ?? '') ?: '[]', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($schema) || !array_is_list($schema) || count($schema) > 20) {
            throw new \invalid_parameter_exception('Document schema must be a list of at most 20 documents.');
        }
        $keys = [];
        foreach ($schema as &$doc) {
            if (!is_array($doc) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $doc['key'] ?? '') ||
                    isset($keys[$doc['key']]) || empty(trim($doc['label'] ?? '')) ||
                    !in_array($doc['mode'] ?? '', ['attachment', 'choice', 'alternatives'], true)) {
                throw new \invalid_parameter_exception('Invalid or duplicate document key, label or mode.');
            }
            $keys[$doc['key']] = true;
            $doc['required'] = (bool)($doc['required'] ?? true);
            $doc['maxage'] = (int)($doc['maxage'] ?? 0);
            if ($doc['maxage'] < 0 || $doc['maxage'] > 3650) {
                throw new \invalid_parameter_exception('Document age must be between 0 and 3650 days.');
            }
            $doc['consultation'] = trim($doc['consultation'] ?? '');
            if ($doc['mode'] === 'choice' && $doc['consultation'] === '') {
                throw new \invalid_parameter_exception('A consultation authorisation text is required.');
            }
            if ($doc['mode'] === 'alternatives') {
                $options = $doc['alternatives'] ?? [];
                if (!is_array($options) || !array_is_list($options) || count($options) !== 2) {
                    throw new \invalid_parameter_exception('Provide two document alternatives.');
                }
                $seen = [];
                foreach ($options as &$option) {
                    if (!is_array($option) || !in_array($option['key'] ?? '', ['option1', 'option2'], true) ||
                        isset($seen[$option['key']]) || empty(trim($option['label'] ?? ''))) {
                        throw new \invalid_parameter_exception('Invalid document alternative.');
                    }
                    $seen[$option['key']] = true;
                    $option['maxage'] = (int)($option['maxage'] ?? 0);
                    $option['consultation'] = trim($option['consultation'] ?? '');
                    if ($option['maxage'] < 0 || $option['maxage'] > 3650) {
                        throw new \invalid_parameter_exception('Invalid age for document alternative.');
                    }
                }
                unset($option);
                $doc['alternatives'] = $options;
                $doc['maxage'] = 0;
            }
        }
        return $schema;
    }

    /** Other enrolment routes must be disabled before opening this controlled intake. */
    public static function conflicts(\stdClass $instance): bool {
        global $DB;
        return $DB->record_exists_select('enrol',
            'courseid = :course AND id <> :id AND status = :enabled',
            ['course' => $instance->courseid, 'id' => $instance->id, 'enabled' => ENROL_INSTANCE_ENABLED]);
    }

    public static function ready(\stdClass $instance): bool {
        return enrol_is_enabled('orderedapply') && (int)$instance->status === ENROL_INSTANCE_ENABLED &&
            (int)$instance->customint1 > 0 && !self::conflicts($instance);
    }
}
