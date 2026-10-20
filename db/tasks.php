<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$tasks = [[
    'classname' => '\enrol_orderedapply\task\maintenance',
    'blocking' => 0, 'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
]];
