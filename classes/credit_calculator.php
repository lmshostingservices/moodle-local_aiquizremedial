<?php
// This file is part of Moodle - http://moodle.org/
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
 * Part of the local_aiquizremedial plugin.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_aiquizremedial;

defined('MOODLE_INTERNAL') || die();

class credit_calculator {
    const COST_TEXT = 2;
    const COST_VOICEOVER = 2;
    const COST_IMAGE = 4;
    const COST_PER_LANGUAGE = 5;
    const BATCH_SIZE = 10;

    const SUPPORTED_LANGUAGES = [
        'fr' => 'Français (French)',
        'es' => 'Español (Spanish)',
        'zh' => '中文 (Mandarin Chinese)',
        'ar' => 'العربية (Arabic)',
        'pt' => 'Português (Portuguese)',
        'de' => 'Deutsch (German)',
        'ja' => '日本語 (Japanese)',
        'ko' => '한국어 (Korean)',
        'vi' => 'Tiếng Việt (Vietnamese)',
        'hi' => 'हिंदी (Hindi)',
        'id' => 'Bahasa Indonesia',
        'it' => 'Italiano (Italian)',
    ];

    public static function get_cost_per_question(): int {
        $total = self::COST_TEXT;

        if (self::is_voiceover_enabled()) {
            $total += self::COST_VOICEOVER;
        }

        if (self::is_images_enabled()) {
            $total += self::COST_IMAGE;
        }

        $extra = self::get_enabled_languages();
        $total += count($extra) * self::COST_PER_LANGUAGE;

        return $total;
    }

    public static function is_voiceover_enabled(): bool {
        return (bool) get_config('local_aiquizremedial', 'enablevoiceover');
    }

    public static function is_images_enabled(): bool {
        return (bool) get_config('local_aiquizremedial', 'enableimages');
    }

    public static function get_batch_size(): int {
        return self::BATCH_SIZE;
    }

    public static function get_enabled_languages(): array {
        // FIX-LANG-PARSE (v1.3.0): the multicheckbox setting is stored comma-separated,
        // not serialized — see helper::parse_languages().
        return helper::parse_languages(
            get_config('local_aiquizremedial', 'extra_languages'),
            array_keys(self::SUPPORTED_LANGUAGES)
        );
    }

    public static function get_breakdown(): array {
        $voiceovercost = self::is_voiceover_enabled() ? self::COST_VOICEOVER : 0;
        $imagecost     = self::is_images_enabled() ? self::COST_IMAGE : 0;
        $langs         = self::get_enabled_languages();
        $langcost      = count($langs) * self::COST_PER_LANGUAGE;

        return [
            'text'              => self::COST_TEXT,
            'voiceover'         => $voiceovercost,
            'image'             => $imagecost,
            'languages'         => $langcost,
            'total'             => self::COST_TEXT + $voiceovercost + $imagecost + $langcost,
            'voiceover_enabled' => self::is_voiceover_enabled(),
            'images_enabled'    => self::is_images_enabled(),
            'extra_languages'   => $langs,
        ];
    }
}
