<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace certificateelement_date;

use advanced_testcase;
use tool_certificate_generator;
use core_text;

/**
 * Unit tests for date element.
 *
 * @package    certificateelement_date
 * @group      tool_certificate
 * @covers     \certificateelement_date\element
 * @copyright  2018 Daniel Neis Araujo <daniel@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class element_test extends advanced_testcase {

    /**
     * Test set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Get certificate generator
     * @return tool_certificate_generator
     */
    protected function get_generator(): tool_certificate_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    /**
     * Test render_html
     */
    public function test_render_html(): void {
        $certificate1 = $this->get_generator()->create_template((object)['name' => 'Certificate 1']);
        $pageid = $this->get_generator()->create_page($certificate1)->get_id();
        $formdata = ['name' => 'Date element', 'dateitem' => \certificateelement_date\element::CUSTOMCERT_DATE_ISSUE,
            'dateformat' => 'strftimedateshort', ];
        $e = $this->get_generator()->create_element($pageid, 'date', $formdata);
        $this->assertNotEmpty($e->render_html());

        $formdata['dateitem'] = \certificateelement_date\element::CUSTOMCERT_DATE_EXPIRY;
        $formdata['dateformat'] = 'strftimedateshort';
        $e = $this->get_generator()->create_element($pageid, 'date', $formdata);
        $this->assertNotEmpty($e->render_html());

        // Generate PDF for preview.
        $filecontents = $this->get_generator()->generate_pdf($certificate1, true);
        $this->assertGreaterThan(30000, core_text::strlen($filecontents, '8bit'));

        // Generate PDF for issue.
        $issue = $this->get_generator()->issue($certificate1, $this->getDataGenerator()->create_user(), time() + YEARSECS);
        $filecontents = $this->get_generator()->generate_pdf($certificate1, false, $issue);
        $this->assertGreaterThan(30000, core_text::strlen($filecontents, '8bit'));
    }

    /**
     * Test save_unique_data
     */
    public function test_save_unique_data(): void {
        global $DB;
        $certificate1 = $this->get_generator()->create_template((object)['name' => 'Certificate 1']);
        $pageid = $this->get_generator()->create_page($certificate1)->get_id();
        $e = $this->get_generator()->new_element($pageid, 'date');
        $newdata = (object)['dateitem' => \certificateelement_date\element::CUSTOMCERT_DATE_ISSUE,
                            'dateformat' => 'strftimedate', ];
        $expected = json_encode($newdata);
        $e->save_form_data($newdata);
        $el = $DB->get_record('tool_certificate_elements', ['id' => $e->get_id()]);
        $this->assertEquals($expected, $el->data);
    }

    /**
     * Test get_date_formats
     */
    public function test_get_date_formats(): void {
        $formats = \certificateelement_date\element::get_date_formats();
        $this->assertFalse(empty($formats));
        $this->assertArrayHasKey(\certificateelement_date\element::CUSTOMCERT_DATE_FORMAT_ORDINAL_DAY, $formats);
        $this->assertArrayHasKey(\certificateelement_date\element::CUSTOMCERT_DATE_FORMAT_DAY_WORDS, $formats);
    }

    /**
     * Tests custom ordinal day-month-year date format.
     */
    public function test_get_date_format_string_for_ordinal_day_month_year(): void {
        $this->setAdminUser();
        set_user_preference('timezone', 'UTC');

        $method = new \ReflectionMethod(\certificateelement_date\element::class, 'get_date_format_string');
        $method->setAccessible(true);

        $cases = [
            ['2024-12-21 12:00:00', '21st Day of December, 2024.'],
            ['2026-01-03 12:00:00', '3rd Day of January, 2026.'],
            ['2026-05-10 12:00:00', '10th Day of May, 2026.'],
            ['2026-08-11 12:00:00', '11th Day of August, 2026.'],
            ['2026-08-12 12:00:00', '12th Day of August, 2026.'],
            ['2026-08-13 12:00:00', '13th Day of August, 2026.'],
            ['2026-08-22 12:00:00', '22nd Day of August, 2026.'],
        ];

        foreach ($cases as [$datestring, $expected]) {
            $timestamp = (new \DateTimeImmutable($datestring, new \DateTimeZone('UTC')))->getTimestamp();
            $actual = $method->invoke(null, $timestamp, \certificateelement_date\element::CUSTOMCERT_DATE_FORMAT_ORDINAL_DAY);
            $this->assertEquals($expected, $actual);
        }
    }

    /**
     * Tests custom written day-month-year date format.
     */
    public function test_get_date_format_string_for_day_month_year_words(): void {
        $this->setAdminUser();
        set_user_preference('timezone', 'UTC');

        $method = new \ReflectionMethod(\certificateelement_date\element::class, 'get_date_format_string');
        $method->setAccessible(true);

        $cases = [
            ['2024-08-21 12:00:00', 'this twenty-first day of August, two thousand and twenty-four.'],
            ['2026-09-02 12:00:00', 'this second day of September, two thousand and twenty-six.'],
            ['2025-12-10 12:00:00', 'this tenth day of December, two thousand and twenty-five.'],
        ];

        foreach ($cases as [$datestring, $expected]) {
            $timestamp = (new \DateTimeImmutable($datestring, new \DateTimeZone('UTC')))->getTimestamp();
            $actual = $method->invoke(null, $timestamp, \certificateelement_date\element::CUSTOMCERT_DATE_FORMAT_DAY_WORDS);
            $this->assertEquals($expected, $actual);
        }
    }

    /**
     * Creates a quiz with a pass mark and returns the quiz record.
     *
     * @param \stdClass $course
     * @param float $gradepass pass mark on the quiz grade item, 0 for none
     * @return \stdClass
     */
    protected function create_quiz(\stdClass $course, float $gradepass): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $this->getDataGenerator()->create_module('quiz',
            ['course' => $course->id, 'grade' => 100, 'sumgrades' => 10]);
        if ($gradepass > 0) {
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id, 'courseid' => $course->id, 'itemnumber' => 0]);
            $item->gradepass = $gradepass;
            $item->update();
        }
        return $quiz;
    }

    /**
     * Inserts a finished quiz attempt row.
     *
     * @param \stdClass $quiz
     * @param int $userid
     * @param int $attempt attempt number
     * @param int $timefinish
     * @param float $sumgrades
     */
    protected function create_finished_attempt(\stdClass $quiz, int $userid, int $attempt, int $timefinish,
            float $sumgrades): void {
        global $DB;
        static $uniqueid = 777000;

        $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $userid,
            'attempt' => $attempt,
            'uniqueid' => ++$uniqueid,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => $timefinish - HOURSECS,
            'timefinish' => $timefinish,
            'timemodified' => $timefinish,
            'timemodifiedoffline' => 0,
            'sumgrades' => $sumgrades,
        ]);
    }

    /**
     * A raw program/degree completion timestamp stored with the issue wins over everything else.
     */
    public function test_get_completion_date_from_issue_data(): void {
        $completed = 1700000000;
        $issue = (object)[
            'userid' => 123,
            'courseid' => null,
            'timecreated' => $completed + (5 * DAYSECS),
            'data' => json_encode(['programtimecompleted' => $completed]),
        ];
        $this->assertEquals($completed, \certificateelement_date\element::get_completion_date($issue));
    }

    /**
     * For a course certificate the earning exam attempt's finish time is used, even when the
     * certificate was issued days later (e.g. after a proctoring risk hold release).
     */
    public function test_get_completion_date_from_exam_attempt(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $exam = $this->create_quiz($course, 70.0);
        $practice = $this->create_quiz($course, 0.0);

        $examday = 1700000000;
        // Failed first try, passing attempt a week later, practice quiz taken afterwards.
        $this->create_finished_attempt($exam, (int)$user->id, 1, $examday - WEEKSECS, 3.0);
        $this->create_finished_attempt($exam, (int)$user->id, 2, $examday, 8.0);
        $this->create_finished_attempt($practice, (int)$user->id, 1, $examday + DAYSECS, 9.0);

        $issue = (object)[
            'userid' => $user->id,
            'courseid' => $course->id,
            'timecreated' => $examday + (4 * DAYSECS),
            'data' => json_encode(['courseid' => $course->id]),
        ];
        $this->assertEquals($examday, \certificateelement_date\element::get_completion_date($issue));
    }

    /**
     * Attempts finished after the certificate was issued are ignored.
     */
    public function test_get_completion_date_ignores_attempts_after_issue(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $exam = $this->create_quiz($course, 70.0);

        $examday = 1700000000;
        $issuetime = $examday + (2 * DAYSECS);
        $this->create_finished_attempt($exam, (int)$user->id, 1, $examday, 8.0);
        $this->create_finished_attempt($exam, (int)$user->id, 2, $issuetime + DAYSECS, 9.5);

        $issue = (object)[
            'userid' => $user->id,
            'courseid' => $course->id,
            'timecreated' => $issuetime,
            'data' => null,
        ];
        $this->assertEquals($examday, \certificateelement_date\element::get_completion_date($issue));
    }

    /**
     * With no exam attempts the course completion date is used, then the issue date.
     */
    public function test_get_completion_date_fallbacks(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $issuetime = 1700500000;

        $issue = (object)[
            'userid' => $user->id,
            'courseid' => $course->id,
            'timecreated' => $issuetime,
            'data' => null,
        ];
        $this->assertEquals($issuetime, \certificateelement_date\element::get_completion_date($issue));

        $completed = $issuetime - (3 * DAYSECS);
        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => $completed - WEEKSECS,
            'timestarted' => $completed - WEEKSECS,
            'timecompleted' => $completed,
            'reaggregate' => 0,
        ]);
        $this->assertEquals($completed, \certificateelement_date\element::get_completion_date($issue));
    }

    /**
     * A certificate with a completion date element renders for preview and for a real issue.
     */
    public function test_render_completion_date(): void {
        $certificate1 = $this->get_generator()->create_template((object)['name' => 'Certificate 1']);
        $pageid = $this->get_generator()->create_page($certificate1)->get_id();
        $formdata = ['name' => 'Date element',
            'dateitem' => \certificateelement_date\element::CUSTOMCERT_DATE_COMPLETION,
            'dateformat' => 'strftimedate', ];
        $e = $this->get_generator()->create_element($pageid, 'date', $formdata);
        $this->assertNotEmpty($e->render_html());

        // Generate PDF for preview.
        $filecontents = $this->get_generator()->generate_pdf($certificate1, true);
        $this->assertGreaterThan(30000, core_text::strlen($filecontents, '8bit'));

        // Generate PDF for an issue in a course with an exam attempt.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $exam = $this->create_quiz($course, 70.0);
        $this->create_finished_attempt($exam, (int)$user->id, 1, time() - (3 * DAYSECS), 9.0);

        $issue = $this->get_generator()->issue($certificate1, $user, null,
            ['courseid' => $course->id], 'mod_coursecertificate', (int)$course->id);
        $filecontents = $this->get_generator()->generate_pdf($certificate1, false, $issue);
        $this->assertGreaterThan(30000, core_text::strlen($filecontents, '8bit'));
    }

    /**
     * Tests that the edit element form can be initiated without any errors
     */
    public function test_edit_element_form(): void {
        $this->setAdminUser();

        preg_match('|^certificateelement_(\w*)\\\\|', get_class($this), $matches);
        $form = $this->get_generator()->create_template_and_edit_element_form($matches[1]);
        $this->assertNotEmpty($form->render());
    }
}
