<?php
/**
 * Calendar-day checks for document issue dates.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply;
defined('MOODLE_INTERNAL') || die();

/** Compare the dates shown to the applicant, including the last permitted day. */
final class document_date {
    public static function accepted(int $issued, int $maxage, int $now, \DateTimeZone $timezone): bool {
        if ($issued <= 0 || $maxage < 0) {
            return false;
        }
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0);
        $date = (new \DateTimeImmutable('@' . $issued))->setTimezone($timezone)->setTime(0, 0);
        // Calendar days can have 23 or 25 hours when daylight saving changes.
        $earliest = $today->sub(new \DateInterval('P' . $maxage . 'D'));
        return $date >= $earliest && $date <= $today;
    }
}
