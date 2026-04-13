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
     * Tests that the edit element form can be initiated without any errors
     */
    public function test_edit_element_form(): void {
        $this->setAdminUser();

        preg_match('|^certificateelement_(\w*)\\\\|', get_class($this), $matches);
        $form = $this->get_generator()->create_template_and_edit_element_form($matches[1]);
        $this->assertNotEmpty($form->render());
    }
}
