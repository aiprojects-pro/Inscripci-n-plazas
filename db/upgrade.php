<?php
/**
 * Upgrade without altering existing applications or document requirements.
 * @package enrol_orderedapply
 * @copyright 2026 Authors of enrol_orderedapply
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
function xmldb_enrol_orderedapply_upgrade($oldversion) {
    if ($oldversion < 2026102001) {
        // Alternative document requirements use the existing schema and metadata JSON fields.
        upgrade_plugin_savepoint(true, 2026102001, 'enrol', 'orderedapply');
    }
    return true;
}
