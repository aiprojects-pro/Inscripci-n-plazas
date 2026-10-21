<?php
/**
 * Inclusive document age boundaries.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_orderedapply;
defined('MOODLE_INTERNAL') || die();

final class document_date_test extends \basic_testcase {
    public function test_inclusive_calendar_boundary_in_multiple_timezones(): void {
        foreach (['Europe/Madrid', 'UTC', 'Pacific/Auckland', 'America/New_York'] as $name) {
            $tz = new \DateTimeZone($name);
            // Ordinary days, spring clock change and autumn clock change.
            foreach ([['2026-10-21', '2026-10-06', '2026-10-05'],
                    ['2026-04-01', '2026-03-17', '2026-03-16'],
                    ['2026-11-02', '2026-10-18', '2026-10-17']] as [$today, $last, $old]) {
                $timestamp = static fn(string $date): int => (new \DateTimeImmutable($date, $tz))->getTimestamp();
                $now = $timestamp($today . ' 16:00:00');
                $this->assertTrue(document_date::accepted($timestamp($last), 15, $now, $tz), "$name $last");
                $this->assertFalse(document_date::accepted($timestamp($old), 15, $now, $tz), "$name $old");
                $this->assertTrue(document_date::accepted($timestamp($today), 15, $now, $tz));
                $this->assertFalse(document_date::accepted($now + 2 * 86400, 15, $now, $tz));
                $this->assertFalse(document_date::accepted(0, 15, $now, $tz));
            }
        }
    }
}
