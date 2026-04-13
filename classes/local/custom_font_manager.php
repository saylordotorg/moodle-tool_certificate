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
 * Handles custom font uploads for the certificate tool.
 *
 * @package    tool_certificate
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_certificate\local;

defined('MOODLE_INTERNAL') || die();

use core\notification;
use stored_file;

/**
 * Class responsible for processing custom font files uploaded in settings.
 */
class custom_font_manager {
    /** @var string filearea for custom fonts uploaded in plugin settings. */
    public const FILEAREA = 'customfonts';

    /** @var array minimum font definition files needed by Moodle TCPDF config checks. */
    private const REQUIRED_FONT_DEFINITIONS = [
        'courier.php',
        'helvetica.php',
        'times.php',
        'symbol.php',
        'zapfdingbats.php',
        'freeserif.php',
        'freesans.php',
    ];

    /**
     * Return accepted file types.
     *
     * @return array
     */
    public static function get_accepted_file_types(): array {
        return ['.ttf'];
    }

    /**
     * Build @font-face CSS for uploaded custom fonts to be used in browser previews.
     *
     * @return string
     */
    public static function get_preview_font_face_css(): string {
        $fontfiles = self::get_uploaded_font_files();
        if (!$fontfiles) {
            return '';
        }

        $fontnameurls = [];
        foreach ($fontfiles as $file) {
            $fontname = self::derive_tcpdf_font_name($file->get_filename());
            if ($fontname === '') {
                continue;
            }
            $fontnameurls[$fontname] = self::get_stored_file_url($file);
        }
        if (!$fontnameurls) {
            return '';
        }

        $fontstyles = self::resolve_font_styles(array_keys($fontnameurls));
        $css = [];
        foreach ($fontstyles as $fontname => $styleinfo) {
            if (!isset($fontnameurls[$fontname])) {
                continue;
            }
            $family = self::escape_css_value($styleinfo['family']);
            $src = self::escape_css_value($fontnameurls[$fontname]);
            $weight = $styleinfo['weight'];
            $style = $styleinfo['style'];
            $css[] = "@font-face {font-family: '{$family}'; src: url('{$src}') format('truetype'); font-weight: {$weight}; font-style: {$style};}";
        }

        return implode("\n", $css);
    }

    /**
     * Converts uploaded fonts and makes them available to TCPDF.
     *
     * @param bool $shownotifications if true, add UI notifications for conversion/path problems.
     * @return void
     */
    public static function process_uploaded_fonts(bool $shownotifications = true): void {
        global $CFG;

        $files = self::get_uploaded_font_files();
        if (!$files) {
            return;
        }

        $customfontpath = self::get_custom_font_path();
        if (!self::prepare_custom_font_path($customfontpath)) {
            if ($shownotifications) {
                notification::add(
                    get_string('customfontsuploadpathinvalid', 'tool_certificate', $customfontpath),
                    notification::ERROR
                );
            }
            return;
        }

        require_once($CFG->libdir . '/pdflib.php');

        $failedfiles = [];
        foreach ($files as $file) {
            if (!self::is_supported_font_file($file->get_filename()) || !self::register_font_file($file, $customfontpath)) {
                $failedfiles[] = $file->get_filename();
            }
        }

        if ($failedfiles && $shownotifications) {
            $a = (object) [
                'files' => implode(', ', $failedfiles),
                'types' => implode(', ', self::get_accepted_file_types()),
            ];
            notification::add(get_string('customfontsconversionfailed', 'tool_certificate', $a), notification::ERROR);
        }
    }

    /**
     * Ensure the custom font path exists and contains mandatory baseline fonts.
     *
     * @param string $customfontpath
     * @return bool
     */
    private static function prepare_custom_font_path(string $customfontpath): bool {
        global $CFG;

        if (!check_dir_exists($customfontpath, true, true) || !is_writable($customfontpath)) {
            return false;
        }

        if (self::has_required_font_definitions($customfontpath)) {
            return true;
        }

        $defaultfontpath = $CFG->dirroot . '/lib/tcpdf/fonts/';
        if (!is_dir($defaultfontpath)) {
            return false;
        }

        foreach (glob($defaultfontpath . '*') as $sourcepath) {
            if (!is_file($sourcepath)) {
                continue;
            }
            $destinationpath = $customfontpath . basename($sourcepath);
            if (file_exists($destinationpath)) {
                continue;
            }
            if (!copy($sourcepath, $destinationpath)) {
                return false;
            }
        }

        return self::has_required_font_definitions($customfontpath);
    }

    /**
     * Check mandatory font definition files.
     *
     * @param string $fontpath
     * @return bool
     */
    private static function has_required_font_definitions(string $fontpath): bool {
        foreach (self::REQUIRED_FONT_DEFINITIONS as $fontdefinition) {
            if (!file_exists($fontpath . $fontdefinition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Converts one uploaded stored file into TCPDF font files.
     *
     * @param stored_file $file
     * @param string $fontpath
     * @return bool
     */
    private static function register_font_file(stored_file $file, string $fontpath): bool {
        $tempdir = make_temp_directory('tool_certificate/fonts');
        if (!$tempdir) {
            return false;
        }

        $filename = clean_param($file->get_filename(), PARAM_FILE);
        if ($filename === '') {
            return false;
        }

        $tempfilepath = $tempdir . '/' . $file->get_contenthash() . '_' . $filename;
        if (!$file->copy_content_to($tempfilepath)) {
            return false;
        }

        $tcpdffontname = \TCPDF_FONTS::addTTFfont($tempfilepath, 'TrueTypeUnicode', '', 32, $fontpath);
        @unlink($tempfilepath);

        return $tcpdffontname !== false;
    }

    /**
     * Get uploaded font files from settings filearea.
     *
     * @return stored_file[]
     */
    private static function get_uploaded_font_files(): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'tool_certificate',
            self::FILEAREA,
            0,
            'filename',
            false
        );

        $fontfiles = [];
        foreach ($files as $file) {
            if (self::is_supported_font_file($file->get_filename())) {
                $fontfiles[] = $file;
            }
        }
        return $fontfiles;
    }

    /**
     * Derive the TCPDF font name from uploaded file name.
     *
     * Uses the same normalisation rules as TCPDF_FONTS::addTTFfont().
     *
     * @param string $filename
     * @return string
     */
    private static function derive_tcpdf_font_name(string $filename): string {
        $fontpathparts = pathinfo($filename);
        $fontname = isset($fontpathparts['filename']) ? $fontpathparts['filename'] : '';
        $fontname = strtolower($fontname);
        $fontname = preg_replace('/[^a-z0-9_]/', '', $fontname);
        $fontname = str_replace(['bold', 'oblique', 'italic', 'regular'], ['b', 'i', 'i', ''], $fontname);
        if ($fontname === '') {
            $fontname = 'tcpdffont';
        }
        return $fontname;
    }

    /**
     * Resolve family/weight/style based on TCPDF font naming rules.
     *
     * @param string[] $fontnames
     * @return array
     */
    private static function resolve_font_styles(array $fontnames): array {
        $fontnames = array_values(array_unique($fontnames));
        $fontset = array_fill_keys($fontnames, true);
        $styles = [];

        foreach ($fontnames as $fontname) {
            if (substr($fontname, -2) === 'bi') {
                $family = substr($fontname, 0, -2);
                if (isset($fontset[$family])) {
                    $styles[$fontname] = ['family' => $family, 'weight' => 'bold', 'style' => 'italic'];
                    continue;
                }
            }
            if (substr($fontname, -1) === 'i') {
                $family = substr($fontname, 0, -1);
                if (isset($fontset[$family])) {
                    $styles[$fontname] = ['family' => $family, 'weight' => 'normal', 'style' => 'italic'];
                    continue;
                }
            }
            if (substr($fontname, -1) === 'b') {
                $family = substr($fontname, 0, -1);
                if (isset($fontset[$family])) {
                    $styles[$fontname] = ['family' => $family, 'weight' => 'bold', 'style' => 'normal'];
                    continue;
                }
            }
            $styles[$fontname] = ['family' => $fontname, 'weight' => 'normal', 'style' => 'normal'];
        }

        return $styles;
    }

    /**
     * Build pluginfile URL for an uploaded font file.
     *
     * @param stored_file $file
     * @return string
     */
    private static function get_stored_file_url(stored_file $file): string {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * Escape value for safe single-quoted CSS usage.
     *
     * @param string $value
     * @return string
     */
    private static function escape_css_value(string $value): string {
        return str_replace(['\\', '\''], ['\\\\', '\\\''], $value);
    }

    /**
     * Check whether file extension is supported.
     *
     * @param string $filename
     * @return bool
     */
    private static function is_supported_font_file(string $filename): bool {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($extension === '') {
            return false;
        }

        return in_array('.' . strtolower($extension), self::get_accepted_file_types(), true);
    }

    /**
     * Return custom font folder path.
     *
     * @return string
     */
    private static function get_custom_font_path(): string {
        global $CFG;

        $fontpath = defined('PDF_CUSTOM_FONT_PATH') ? PDF_CUSTOM_FONT_PATH : $CFG->dataroot . '/fonts/';
        return rtrim($fontpath, '/\\') . '/';
    }
}
