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

/** Integration tests run against Moodle's separate PHPUnit database. */
final class service_test extends \advanced_testcase {
    private \stdClass $instance;
    private function employment_schema(): void {
        global $DB;
        $this->instance->customtext1 = json_encode([['key' => 'employment', 'label' => 'Employment',
            'mode' => 'alternatives', 'required' => true, 'alternatives' => [
                ['key' => 'option1', 'label' => 'Work history', 'maxage' => 15],
                ['key' => 'option2', 'label' => 'DARDE / LABORA', 'maxage' => 0,
                    'consultation' => 'TEST ONLY: authorise the specified LABORA consultation.'],
            ]]]);
        $DB->update_record('enrol', $this->instance);
    }
    private function draft_pdf(): int {
        global $USER;
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/',
            'filename' => 'test.pdf', 'mimetype' => 'application/pdf', 'userid' => $USER->id], "%PDF-1.4\n%%EOF");
        return $draft;
    }
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enrol_plugins_enabled', 'manual,orderedapply');
        $course = $this->getDataGenerator()->create_course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $course->id]);
        $id = enrol_get_plugin('orderedapply')->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED,
            'customint1' => 25, 'customint2' => 1, 'customchar1' => 'https://example.invalid/privacy']);
        $this->instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
    }
    private function apply(array $extra = []): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]);
        $this->setUser($user);
        $id = service::submit($this->instance->id, $extra + ['privacyaccept' => 1]);
        return $DB->get_record('enrol_orderedapply_app', ['id' => $id], '*', MUST_EXIST);
    }
    private function decide(\stdClass $app, string $decision = 'verified'): void {
        global $DB;
        $this->setAdminUser();
        $current = $DB->get_record('enrol_orderedapply_app', ['id' => $app->id], '*', MUST_EXIST);
        service::review($app->id, $decision, $decision === 'verified' ? '' : 'Review decision', (int)$current->revision, true);
    }
    public function test_twenty_sixth_approval_cannot_overfill(): void {
        global $DB;
        $apps = [];
        for ($i = 0; $i < 26; $i++) { $apps[] = $this->apply(); }
        // Reverse review order deliberately; the last request must remain waiting.
        foreach (array_reverse($apps) as $app) { $this->decide($app); }
        $this->assertSame(25, service::occupied($this->instance));
        $this->assertSame('verified', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $apps[25]->id]));
        $this->assertSame(25, $DB->count_records('enrol_orderedapply_app', ['status' => 'enrolled']));
        // Repeated allocation is idempotent.
        service::process($this->instance->id);
        $this->assertSame(25, service::occupied($this->instance));
    }
    public function test_pending_reserves_and_rejection_releases(): void {
        global $DB;
        $this->instance->customint1 = 1;
        $DB->update_record('enrol', $this->instance);
        $first = $this->apply();
        $second = $this->apply();
        $this->decide($second);
        $this->assertSame(0, service::occupied($this->instance));
        $this->decide($first, 'needsinfo');
        $this->assertSame(0, service::occupied($this->instance));
        $this->decide($first, 'rejected');
        $this->assertSame(1, service::occupied($this->instance));
        $this->assertSame('enrolled', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $second->id]));
    }
    public function test_corrections_preserve_turn_and_stale_review_is_rejected(): void {
        global $DB;
        $app = $this->apply();
        $this->decide($app, 'needsinfo');
        $before = $DB->get_record('enrol_orderedapply_app', ['id' => $app->id]);
        $this->setUser($app->userid);
        service::submit($this->instance->id, ['privacyaccept' => 1, 'phone' => '600000000']);
        $after = $DB->get_record('enrol_orderedapply_app', ['id' => $app->id]);
        $this->assertEquals($before->timesubmitted, $after->timesubmitted);
        $this->assertEquals($before->revision + 1, $after->revision);
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('stalereview', 'enrol_orderedapply'));
        service::review($app->id, 'verified', '', (int)$before->revision, true);
    }
    public function test_withdrawal_releases_seat_for_next_validated_person(): void {
        global $DB;
        $this->instance->customint1 = 1;
        $DB->update_record('enrol', $this->instance);
        $first = $this->apply(); $second = $this->apply();
        $this->decide($first); $this->decide($second);
        $this->setUser($first->userid);
        service::withdraw($first->id);
        $this->assertSame(1, service::occupied($this->instance));
        $this->assertSame('withdrawn', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $first->id]));
        $this->assertSame('enrolled', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $second->id]));
    }
    public function test_other_enrolment_route_blocks_allocation(): void {
        global $DB;
        $app = $this->apply();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED,
            ['courseid' => $this->instance->courseid, 'enrol' => 'manual']);
        $this->decide($app);
        $this->assertSame(0, service::occupied($this->instance));
        $this->assertFalse(configuration::ready($this->instance));
    }
    public function test_unprivileged_reviewer_is_rejected(): void {
        $app = $this->apply();
        $this->expectException(\required_capability_exception::class);
        service::review($app->id, 'verified', '', (int)$app->revision, true);
    }
    public function test_another_user_cannot_withdraw_application(): void {
        $app = $this->apply();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        service::withdraw($app->id);
    }
    public function test_missing_authorisation_is_rejected(): void {
        global $DB;
        $this->instance->customtext1 = json_encode([['key' => 'register', 'label' => 'Register', 'mode' => 'choice',
            'consultation' => 'I authorise this specific consultation.']]);
        $DB->update_record('enrol', $this->instance);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('missingconsent', 'enrol_orderedapply', 'Register'));
        $this->apply(['documents' => ['register' => ['method' => 'consult', 'authorised' => false]]]);
    }
    public function test_authorisation_is_saved_with_exact_text(): void {
        global $DB;
        $this->instance->customtext1 = json_encode([['key' => 'register', 'label' => 'Register', 'mode' => 'choice',
            'consultation' => 'Specific authorisation.']]);
        $DB->update_record('enrol', $this->instance);
        $app = $this->apply(['documents' => ['register' => ['method' => 'consult', 'authorised' => true]]]);
        $metadata = json_decode($app->documents, true);
        $this->assertSame('Specific authorisation.', $metadata['register']['authorisationtext']);
        $this->assertGreaterThan(0, $metadata['register']['authorisedat']);
        $this->assertSame('https://example.invalid/privacy', $metadata['_privacy']['url']);
    }
    public function test_uploaded_document_is_private_and_removed_on_erasure(): void {
        global $DB;
        $this->instance->customtext1 = json_encode([['key' => 'proof', 'label' => 'Proof', 'mode' => 'attachment', 'maxage' => 15]]);
        $DB->update_record('enrol', $this->instance);
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]);
        $this->setUser($user);
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($user->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/',
            'filename' => 'proof.pdf', 'mimetype' => 'application/pdf', 'userid' => $user->id], "%PDF-1.4\n%%EOF");
        $id = service::submit($this->instance->id, ['privacyaccept' => 1,
            'documents' => ['proof' => ['draftid' => $draft, 'issued' => time() - DAYSECS]]]);
        $context = \context_course::instance($this->instance->courseid);
        $this->assertNotFalse(get_file_storage()->get_file($context->id, 'enrol_orderedapply', 'documents', $id, '/proof/', 'proof.pdf'));
        $this->setAdminUser();
        privacy\provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('enrol_orderedapply_app'));
        $this->assertSame(0, $DB->count_records('enrol_orderedapply_mail'));
        $this->assertFalse(get_file_storage()->get_file($context->id, 'enrol_orderedapply', 'documents', $id, '/proof/', 'proof.pdf'));
    }
    public function test_notification_delivery_is_not_repeated(): void {
        global $DB;
        $this->apply();
        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $task = new task\maintenance();
        $task->execute(); $task->execute();
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(0, $DB->count_records('enrol_orderedapply_mail', ['timesent' => 0]));
    }
    public function test_application_form_and_settings_render(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $form = new form\application(null, ['instance' => $this->instance]);
        $html = $form->render();
        $this->assertStringContainsString('privacyaccept', $html);
        $m = new \MoodleQuickForm('settings', 'post', '');
        enrol_get_plugin('orderedapply')->edit_instance_form($this->instance, $m, \context_course::instance($this->instance->courseid));
        $this->assertTrue($m->elementExists('customint1'));
    }
    public function test_duplicate_instance_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        enrol_get_plugin('orderedapply')->add_instance(get_course($this->instance->courseid));
    }
    public function test_simultaneous_approvals_respect_the_last_available_seat(): void {
        global $CFG, $DB;
        $this->preventResetByRollback();
        $apps = [];
        for ($i = 0; $i < 26; $i++) { $apps[] = $this->apply(); }
        for ($i = 0; $i < 24; $i++) { $this->decide($apps[$i]); }
        $barrier = $CFG->dataroot . '/orderedapply-barrier-' . bin2hex(random_bytes(8));
        $workers = [];
        try {
            foreach ([24, 25] as $i) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/review_worker.php',
                    (string)$apps[$i]->id, (string)$apps[$i]->revision, $barrier, (string)$i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $end = microtime(true) + 12;
            while (!(is_file($barrier . '.24.ready') && is_file($barrier . '.25.ready')) && microtime(true) < $end) {
                usleep(10000);
            }
            file_put_contents($barrier . '.release', 'go');
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $out . $err);
            }
            $workers = [];
            $this->assertSame(25, service::occupied($this->instance));
            $this->assertSame('enrolled', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $apps[24]->id]));
            $this->assertSame('verified', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $apps[25]->id]));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
            foreach (glob($barrier . '.*') as $file) { unlink($file); }
        }
    }
    public function test_no_automatic_rejection_after_correction_deadline(): void {
        global $DB;
        $app = $this->apply();
        $this->decide($app, 'needsinfo');
        $DB->set_field('enrol_orderedapply_app', 'deadline', time() - DAYSECS, ['id' => $app->id]);
        service::process($this->instance->id);
        $this->assertSame('needsinfo', $DB->get_field('enrol_orderedapply_app', 'status', ['id' => $app->id]));
        $this->assertContains((int)$app->id, service::plan($this->instance)['reserve']);
    }
    public function test_capacity_cannot_be_reduced_below_occupancy(): void {
        $a = $this->apply(); $b = $this->apply();
        $this->decide($a); $this->decide($b);
        $data = clone $this->instance;
        $data->customint1 = 1;
        $this->expectException(\invalid_parameter_exception::class);
        enrol_get_plugin('orderedapply')->update_instance($this->instance, $data);
    }
    public function test_configuration_uses_normal_form_fields(): void {
        $data = configuration::from_form(['documentcount' => 1, 'dockey_0' => 'proof', 'doclabel_0' => 'Proof',
            'docmode_0' => 'choice', 'docrequired_0' => 1, 'docage_0' => 15, 'docconsent_0' => 'I authorise this consultation.']);
        $schema = configuration::documents((object)$data);
        $this->assertSame('proof', $schema[0]['key']);
        $this->assertSame(15, $schema[0]['maxage']);
        $this->assertArrayNotHasKey('doclabel_0', $data);
    }
    public function test_multiple_document_uploads_survive_submission(): void {
        global $DB;
        $schema = [];
        foreach (['proof', 'labour', 'registration'] as $key) {
            $schema[] = ['key' => $key, 'label' => $key, 'mode' => 'attachment'];
        }
        $this->instance->customtext1 = json_encode($schema);
        $DB->update_record('enrol', $this->instance);
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]);
        $this->setUser($user);
        $documents = [];
        foreach ($schema as $doc) {
            $draft = file_get_unused_draft_itemid();
            get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($user->id)->id,
                'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/',
                'filename' => 'document.pdf', 'mimetype' => 'application/pdf', 'userid' => $user->id], "%PDF-1.4\n%%EOF");
            $documents[$doc['key']] = ['draftid' => $draft];
        }
        $id = service::submit($this->instance->id, ['privacyaccept' => 1, 'documents' => $documents]);
        $context = \context_course::instance($this->instance->courseid);
        $files = get_file_storage()->get_area_files($context->id, 'enrol_orderedapply', 'documents', $id, 'id', false);
        $this->assertCount(3, $files);
        foreach ($schema as $doc) {
            $this->assertNotFalse(get_file_storage()->get_file($context->id, 'enrol_orderedapply', 'documents', $id,
                '/' . $doc['key'] . '/', 'document.pdf'));
        }
        service::submit($this->instance->id, ['privacyaccept' => 1, 'documents' => $documents]);
        $this->assertCount(3, get_file_storage()->get_area_files($context->id, 'enrol_orderedapply', 'documents', $id, 'id', false));
    }
    public function test_other_applicant_cannot_download_documents(): void {
        $app = $this->apply();
        $context = \context_course::instance($this->instance->courseid);
        $this->assertTrue(service::can_access_documents($context, $app));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(service::can_access_documents($context, $app));
        $this->setAdminUser();
        $this->assertTrue(service::can_access_documents($context, $app));
    }
    public function test_profile_is_recorded_from_authenticated_account(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1, 'firstname' => 'Ana', 'lastname' => 'Test']);
        $this->setUser($user);
        $id = service::submit($this->instance->id, ['privacyaccept' => 1, 'firstname' => 'Impersonated', 'email' => 'other@example.invalid']);
        $app = $DB->get_record('enrol_orderedapply_app', ['id' => $id]);
        $this->assertSame('Ana', $app->firstname);
        $this->assertSame($user->email, $app->email);
    }
    public function test_application_requires_privacy_acknowledgement(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('noprivacy', 'enrol_orderedapply'));
        $this->apply(['privacyaccept' => 0]);
    }
    public function test_no_course_enrolment_until_validation(): void {
        $app = $this->apply();
        $this->assertFalse(is_enrolled(\context_course::instance($this->instance->courseid), $app->userid, '', true));
        $this->decide($app);
        $this->assertTrue(is_enrolled(\context_course::instance($this->instance->courseid), $app->userid, '', true));
    }
    public function test_review_requires_explicit_document_check(): void {
        $app = $this->apply();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('missingattestation', 'enrol_orderedapply'));
        service::review($app->id, 'verified', '', (int)$app->revision);
    }
    public function test_settings_render_for_a_new_course(): void {
        global $CFG;
        require_once($CFG->dirroot . '/enrol/editinstance_form.php');
        $this->setAdminUser();
        $instance = (object)(enrol_get_plugin('orderedapply')->get_instance_defaults() + ['id' => null,
            'courseid' => $this->instance->courseid]);
        $form = new \enrol_instance_edit_form(null, [$instance, enrol_get_plugin('orderedapply'),
            \context_course::instance($this->instance->courseid), 'orderedapply', new \moodle_url('/enrol/instances.php')]);
        $this->assertStringContainsString('documentcount', $form->render());
    }
    public function test_course_copy_transfers_closed_configuration_without_applications(): void {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $app = $this->apply();
        $this->setAdminUser();
        $target = $this->getDataGenerator()->create_course();
        $backup = new \backup_controller(\backup::TYPE_1COURSE, $this->instance->courseid, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $backup->get_plan()->get_setting('users')->set_value(false);
        $backupid = $backup->get_backupid();
        $backup->execute_plan();
        $results = $backup->get_results();
        $backup->destroy();
        $file = $results['backup_destination'];
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($file, $CFG->tempdir . '/backup/' . $backupid);
        $restore = new \restore_controller($backupid, $target->id, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id, \backup::TARGET_EXISTING_ADDING);
        $restore->get_plan()->get_setting('enrolments')->set_value(\backup::ENROL_ALWAYS);
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();
        $copy = $DB->get_record('enrol', ['enrol' => 'orderedapply', 'courseid' => $target->id], '*', MUST_EXIST);
        $this->assertEquals(25, $copy->customint1);
        $this->assertEquals(ENROL_INSTANCE_DISABLED, $copy->status);
        $this->assertEmpty($copy->customint2);
        $this->assertEmpty($copy->customchar1);
        $this->assertSame(0, $DB->count_records('enrol_orderedapply_app', ['enrolid' => $copy->id]));
        $this->assertTrue($DB->record_exists('enrol_orderedapply_app', ['id' => $app->id]));
    }
    public function test_recent_work_history_is_sufficient_without_darde(): void {
        $this->employment_schema();
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]); $this->setUser($user);
        $metadata = service::validate_documents($this->instance, $user->id, ['employment' => [
            'variant' => 'option1', 'draftid' => $this->draft_pdf(), 'issued' => time() - 14 * DAYSECS]]);
        $this->assertSame('Work history', $metadata['employment']['variantlabel']);
        $this->assertSame('attachment', $metadata['employment']['method']);
    }
    public function test_old_work_history_is_rejected(): void {
        $this->employment_schema();
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]); $this->setUser($user);
        $draft = $this->draft_pdf();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('documentdate', 'enrol_orderedapply', 'Employment'));
        service::validate_documents($this->instance, $user->id, ['employment' => [
            'variant' => 'option1', 'draftid' => $draft, 'issued' => time() - 16 * DAYSECS]]);
    }
    public function test_darde_does_not_inherit_work_history_age_limit(): void {
        $this->employment_schema();
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]); $this->setUser($user);
        $metadata = service::validate_documents($this->instance, $user->id, ['employment' => [
            'variant' => 'option2', 'draftid' => $this->draft_pdf()]]);
        $this->assertSame('DARDE / LABORA', $metadata['employment']['variantlabel']);
        $this->assertSame(0, $metadata['employment']['issued']);
    }
    public function test_labora_authorisation_is_sufficient_without_files(): void {
        $this->employment_schema();
        $app = $this->apply(['documents' => ['employment' => [
            'variant' => 'option2', 'method' => 'consult', 'authorised' => true]]]);
        $metadata = json_decode($app->documents, true);
        $this->assertSame('consult', $metadata['employment']['method']);
        $this->assertSame('option2', $metadata['employment']['variant']);
        $this->assertSame('TEST ONLY: authorise the specified LABORA consultation.', $metadata['employment']['authorisationtext']);
    }
    public function test_labora_consultation_requires_explicit_authorisation(): void {
        $this->employment_schema();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('missingconsent', 'enrol_orderedapply', 'Employment'));
        $this->apply(['documents' => ['employment' => ['variant' => 'option2', 'method' => 'consult']]]);
    }
    public function test_work_history_cannot_use_unauthorised_consultation_route(): void {
        $this->employment_schema();
        $this->expectException(\invalid_parameter_exception::class);
        $this->apply(['documents' => ['employment' => ['variant' => 'option1', 'method' => 'consult', 'authorised' => true]]]);
    }
    public function test_alternative_form_and_conversion_keep_the_selected_route(): void {
        $this->employment_schema();
        $this->setUser($this->getDataGenerator()->create_user());
        $form = new form\application(null, ['instance' => $this->instance]);
        $html = $form->render();
        $this->assertStringContainsString('variant_employment', $html);
        $this->assertStringContainsString('authorised_employment_option2', $html);
        $this->assertStringContainsString('issued_employment_option1', $html);
        $input = form\application::documents(['file_employment' => 123, 'variant_employment' => 'option2',
            'method_employment_option2' => 'consult', 'authorised_employment_option2' => 1]);
        $this->assertSame('consult', $input['employment']['method']);
        $this->assertSame('option2', $input['employment']['variant']);
        $this->assertTrue($input['employment']['authorised']);
    }
    public function test_unenrolled_applicant_page_uses_own_upload_context(): void {
        global $CFG, $PAGE, $DB, $USER, $OUTPUT;
        $user = $this->getDataGenerator()->create_user(['confirmed' => 1]);
        $this->setUser($user);
        $this->employment_schema();
        $this->assertFalse(is_enrolled(\context_course::instance($this->instance->courseid), $user->id, '', true));
        $_GET['id'] = (string)$this->instance->id;
        ob_start();
        try {
            require($CFG->dirroot . '/enrol/orderedapply/apply.php');
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($_GET['id']);
        }
        $this->assertInstanceOf(\context_user::class, $PAGE->context);
        $this->assertEquals($user->id, $PAGE->context->instanceid);
        // Repository AJAX obtains its course from this context and calls require_login($course).
        [, $uploadcourse] = get_context_info_array($PAGE->context->id);
        $this->assertNull($uploadcourse);
        $this->assertStringContainsString('file_employment', $html);
    }
    public function test_consultation_post_without_hidden_filemanager_is_retained(): void {
        $input = form\application::documents(['method_residence' => 'consult', 'authorised_residence' => 1]);
        $this->assertSame('consult', $input['residence']['method']);
        $this->assertTrue($input['residence']['authorised']);
        $this->assertSame(0, $input['residence']['draftid']);
    }
    public function test_alternative_consultation_without_filemanager_validates(): void {
        $this->employment_schema();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $input = form\application::documents(['variant_employment' => 'option2',
            'method_employment_option2' => 'consult', 'authorised_employment_option2' => 1]);
        $metadata = service::validate_documents($this->instance, $user->id, $input);
        $this->assertSame('consult', $metadata['employment']['method']);
        $this->assertSame('option2', $metadata['employment']['variant']);
        $this->assertTrue($metadata['employment']['authorised']);
    }
}
