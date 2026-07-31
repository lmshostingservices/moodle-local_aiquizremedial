<?php
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
        $raw = get_config('local_aiquizremedial', 'extra_languages');
        if (empty($raw)) {
            return [];
        }
        $arr = @unserialize($raw);
        if (!is_array($arr)) {
            return [];
        }
        $enabled = array_keys(array_filter($arr));
        return array_values(array_intersect($enabled, array_keys(self::SUPPORTED_LANGUAGES)));
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
