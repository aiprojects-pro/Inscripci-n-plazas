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

/** Pure deterministic allocation: unresolved earlier applications hold their seats. */
final class queue {
    public static function plan(int $capacity, int $occupied, array $applications): array {
        usort($applications, static fn($a, $b) => [$a->timesubmitted, $a->id] <=> [$b->timesubmitted, $b->id]);
        $remaining = max(0, $capacity - $occupied);
        $result = ['enrol' => [], 'reserve' => [], 'waiting' => []];
        foreach ($applications as $application) {
            if (!in_array($application->status, ['pending', 'needsinfo', 'verified'], true)) {
                continue;
            }
            if ($remaining === 0) {
                $result['waiting'][] = (int)$application->id;
                continue;
            }
            $remaining--;
            $result[$application->status === 'verified' ? 'enrol' : 'reserve'][] = (int)$application->id;
        }
        return $result;
    }
}
