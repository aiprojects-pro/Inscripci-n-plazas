<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'enrol/orderedapply:config' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['manager' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW],
    ],
    'enrol/orderedapply:review' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'write', 'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
];
