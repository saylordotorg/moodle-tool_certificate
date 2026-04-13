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

/**
 * This file contains the certificate element date's core interaction API.
 *
 * @package    certificateelement_date
 * @copyright  2013 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace certificateelement_date;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/grade/constants.php');

/**
 * The certificate element date's core interaction API.
 *
 * @package    certificateelement_date
 * @copyright  2013 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends \tool_certificate\element {

    /**
     * @var int Show creation date
     */
    const CUSTOMCERT_DATE_ISSUE = -1;

    /**
     * @var int Show expiry date.
     */
    const CUSTOMCERT_DATE_EXPIRY = -2;

    /**
     * @var string Show day with ordinal suffix and full month, e.g. "21st Day of December, 2024."
     */
    const CUSTOMCERT_DATE_FORMAT_ORDINAL_DAY = 'ordinaldayofmonthyear';

    /**
     * @var string Show date in words, e.g. "this twenty-first day of August, two thousand and twenty-four."
     */
    const CUSTOMCERT_DATE_FORMAT_DAY_WORDS = 'dayinwordsmonthyear';

    /**
     * This function renders the form elements when adding a certificate element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function render_form_elements($mform) {

        // Get the possible date options.
        $dateoptions = [];
        $dateoptions[self::CUSTOMCERT_DATE_ISSUE] = get_string('issueddate', 'certificateelement_date');
        $dateoptions[self::CUSTOMCERT_DATE_EXPIRY] = get_string('expirydate', 'certificateelement_date');

        $mform->addElement('select', 'dateitem', get_string('dateitem', 'certificateelement_date'), $dateoptions);
        $mform->addHelpButton('dateitem', 'dateitem', 'certificateelement_date');

        $mform->addElement('select', 'dateformat', get_string('dateformat', 'certificateelement_date'), self::get_date_formats());
        $mform->addHelpButton('dateformat', 'dateformat', 'certificateelement_date');

        parent::render_form_elements($mform);
    }

    /**
     * Handles saving the form elements created by this element.
     * Can be overridden if more functionality is needed.
     *
     * @param \stdClass $data the form data or partial data to be updated (i.e. name, posx, etc.)
     */
    public function save_form_data(\stdClass $data) {
        $data->data = json_encode(['dateitem' => $data->dateitem, 'dateformat' => $data->dateformat]);
        parent::save_form_data($data);
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param \pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     * @param \stdClass $user the user we are rendering this for
     * @param \stdClass $issue the issue we are rendering
     */
    public function render($pdf, $preview, $user, $issue) {
        // Decode the information stored in the database.
        $dateinfo = @json_decode($this->get_data(), true) + ['dateitem' => '', 'dateformat' => ''];

        // If we are previewing this certificate then just show a demonstration date.
        if ($preview) {
            $date = time();
        } else if ($dateinfo['dateitem'] == self::CUSTOMCERT_DATE_EXPIRY) {
            $date = $issue->expires;
        } else {
            $date = $issue->timecreated;
        }

        // Ensure that a date has been set.
        if (!empty($date)) {
            \tool_certificate\element_helper::render_content($pdf, $this,
                $this->get_date_format_string($date, $dateinfo['dateformat']));
        }
    }

    /**
     * Render the element in html.
     *
     * This function is used to render the element when we are using the
     * drag and drop interface to position it.
     *
     * @return string the html
     */
    public function render_html() {
        // Decode the information stored in the database.
        $dateinfo = @json_decode($this->get_data(), true) + ['dateformat' => ''];
        return \tool_certificate\element_helper::render_html_content($this,
            $this->get_date_format_string(time(), $dateinfo['dateformat']));
    }

    /**
     * Prepare data to pass to moodleform::set_data()
     *
     * @return \stdClass|array
     */
    public function prepare_data_for_form() {
        $record = parent::prepare_data_for_form();
        if (!empty($this->get_data())) {
            $dateinfo = json_decode($this->get_data());
            $record->dateitem = $dateinfo->dateitem;
            $record->dateformat = $dateinfo->dateformat;
        }
        return $record;
    }

    /**
     * Helper function to return all the date formats.
     *
     * @return array the list of date formats
     */
    public static function get_date_formats() {
        // Hard-code date so users can see the difference between short dates with and without the leading zero.
        // Eg. 06/07/18 vs 6/07/18.
        $date = 1530849658;

        $dateformats = [];

        $strdateformats = [
            'strftimedate',
            'strftimedatefullshort',
            'strftimedatefullshortwleadingzero',
            'strftimedateshort',
            'strftimedaydate',
            'strftimedayshort',
            'strftimemonthyear',
            self::CUSTOMCERT_DATE_FORMAT_ORDINAL_DAY,
            self::CUSTOMCERT_DATE_FORMAT_DAY_WORDS,
        ];

        foreach ($strdateformats as $strdateformat) {
            $dateformats[$strdateformat] = self::get_date_format_string($date, $strdateformat);
        }

        return $dateformats;
    }

    /**
     * Returns the date in a readable format.
     *
     * @param int $date
     * @param string $dateformat
     * @return string
     */
    protected static function get_date_format_string($date, $dateformat) {
        if ($dateformat == self::CUSTOMCERT_DATE_FORMAT_ORDINAL_DAY) {
            $certificatedate = self::get_ordinal_day_month_year_string($date);
        } else if ($dateformat == self::CUSTOMCERT_DATE_FORMAT_DAY_WORDS) {
            $certificatedate = self::get_day_month_year_words_string($date);
        } else if ($dateformat == 'strftimedatefullshortwleadingzero') {
            $certificatedate = userdate($date, get_string('strftimedatefullshort', 'langconfig'), 99, false);
        } else if (get_string_manager()->string_exists($dateformat, 'langconfig')) {
            $certificatedate = userdate($date, get_string($dateformat, 'langconfig'));
        } else {
            $certificatedate = userdate($date, get_string('strftimedate', 'langconfig'));
        }
        return $certificatedate;
    }

    /**
     * Returns a date in "21st Day of December, 2024." format.
     *
     * @param int $date
     * @return string
     */
    protected static function get_ordinal_day_month_year_string(int $date): string {
        $dateparts = usergetdate($date);
        $day = (int)$dateparts['mday'];
        $year = (int)$dateparts['year'];
        $month = userdate($date, '%B');
        $a = (object)[
            'day' => $day,
            'suffix' => self::get_english_ordinal_suffix($day),
            'month' => $month,
            'year' => $year,
        ];
        return get_string('dateformatordinaldayofmonthyear', 'certificateelement_date', $a);
    }

    /**
     * Returns ordinal suffix for an integer day value.
     *
     * @param int $day
     * @return string
     */
    protected static function get_english_ordinal_suffix(int $day): string {
        $mod100 = $day % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return 'th';
        }

        $mod10 = $day % 10;
        if ($mod10 == 1) {
            return 'st';
        } else if ($mod10 == 2) {
            return 'nd';
        } else if ($mod10 == 3) {
            return 'rd';
        }
        return 'th';
    }

    /**
     * Returns a date in "this twenty-first day of August, two thousand and twenty-four." format.
     *
     * @param int $date
     * @return string
     */
    protected static function get_day_month_year_words_string(int $date): string {
        $dateparts = usergetdate($date);
        $day = (int)$dateparts['mday'];
        $year = (int)$dateparts['year'];
        $month = userdate($date, '%B');
        $a = (object)[
            'dayword' => self::get_english_ordinal_day_word($day),
            'month' => $month,
            'yearword' => self::get_english_number_words($year),
        ];
        return get_string('dateformatdayinwordsmonthyear', 'certificateelement_date', $a);
    }

    /**
     * Returns the written ordinal form for days 1 to 31.
     *
     * @param int $day
     * @return string
     */
    protected static function get_english_ordinal_day_word(int $day): string {
        $ordinaldaywords = [
            1 => 'first',
            2 => 'second',
            3 => 'third',
            4 => 'fourth',
            5 => 'fifth',
            6 => 'sixth',
            7 => 'seventh',
            8 => 'eighth',
            9 => 'ninth',
            10 => 'tenth',
            11 => 'eleventh',
            12 => 'twelfth',
            13 => 'thirteenth',
            14 => 'fourteenth',
            15 => 'fifteenth',
            16 => 'sixteenth',
            17 => 'seventeenth',
            18 => 'eighteenth',
            19 => 'nineteenth',
            20 => 'twentieth',
            21 => 'twenty-first',
            22 => 'twenty-second',
            23 => 'twenty-third',
            24 => 'twenty-fourth',
            25 => 'twenty-fifth',
            26 => 'twenty-sixth',
            27 => 'twenty-seventh',
            28 => 'twenty-eighth',
            29 => 'twenty-ninth',
            30 => 'thirtieth',
            31 => 'thirty-first',
        ];
        return $ordinaldaywords[$day] ?? (string)$day;
    }

    /**
     * Converts a positive integer to English words.
     *
     * @param int $number
     * @return string
     */
    protected static function get_english_number_words(int $number): string {
        $units = [
            0 => 'zero',
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
            11 => 'eleven',
            12 => 'twelve',
            13 => 'thirteen',
            14 => 'fourteen',
            15 => 'fifteen',
            16 => 'sixteen',
            17 => 'seventeen',
            18 => 'eighteen',
            19 => 'nineteen',
        ];

        $tens = [
            20 => 'twenty',
            30 => 'thirty',
            40 => 'forty',
            50 => 'fifty',
            60 => 'sixty',
            70 => 'seventy',
            80 => 'eighty',
            90 => 'ninety',
        ];

        if ($number < 0) {
            return 'minus ' . self::get_english_number_words(abs($number));
        }
        if ($number < 20) {
            return $units[$number];
        }
        if ($number < 100) {
            $baseten = intdiv($number, 10) * 10;
            $remainder = $number % 10;
            return $tens[$baseten] . ($remainder ? '-' . $units[$remainder] : '');
        }
        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $remainder = $number % 100;
            $text = $units[$hundreds] . ' hundred';
            if ($remainder) {
                $text .= ' and ' . self::get_english_number_words($remainder);
            }
            return $text;
        }
        if ($number < 1000000) {
            $thousands = intdiv($number, 1000);
            $remainder = $number % 1000;
            $text = self::get_english_number_words($thousands) . ' thousand';
            if ($remainder) {
                $text .= $remainder < 100 ? ' and ' : ' ';
                $text .= self::get_english_number_words($remainder);
            }
            return $text;
        }

        $millions = intdiv($number, 1000000);
        $remainder = $number % 1000000;
        $text = self::get_english_number_words($millions) . ' million';
        if ($remainder) {
            $text .= $remainder < 100 ? ' and ' : ' ';
            $text .= self::get_english_number_words($remainder);
        }
        return $text;
    }
}
