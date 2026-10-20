<?php
/**
 * Ordered course applications.
 *
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// PHPUnit-only child process: it never uses the site's normal database prefix or data directory.
if (PHP_SAPI !== 'cli' || count($argv) !== 5) { exit(1); }
$public = dirname(__DIR__, 4);
require_once(dirname($public) . '/vendor/autoload.php');
define('PHPUNIT_UTIL', true);
require_once($public . '/lib/phpunit/bootstrap.php');
if ($CFG->prefix !== $CFG->phpunit_prefix || $CFG->dataroot !== $CFG->phpunit_dataroot ||
    !is_file($CFG->dataroot . '/phpunittestdir.txt')) { exit(2); }
\core\session\manager::set_user(get_admin());
$barrier = $argv[3];
file_put_contents($barrier . '.' . $argv[4] . '.ready', 'ready');
$end = microtime(true) + 15;
while (!is_file($barrier . '.release')) {
    if (microtime(true) >= $end) { exit(3); }
    usleep(10000);
}
try {
    \enrol_orderedapply\service::review((int)$argv[1], 'verified', '', (int)$argv[2], true);
    echo 'approved';
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(4);
}
