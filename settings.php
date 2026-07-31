<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aiquizremedial', get_string('pluginname', 'local_aiquizremedial'));

    $settings->add(new admin_setting_heading(
        'local_aiquizremedial/generalheading',
        get_string('general_heading', 'local_aiquizremedial'),
        get_string('general_heading_desc', 'local_aiquizremedial')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiquizremedial/enabled',
        get_string('enabled', 'local_aiquizremedial'),
        get_string('enabled_desc', 'local_aiquizremedial'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiquizremedial/showonquizreview',
        get_string('showonquizreview', 'local_aiquizremedial'),
        get_string('showonquizreview_desc', 'local_aiquizremedial'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_aiquizremedial/connectionheading',
        get_string('connection_heading', 'local_aiquizremedial'),
        get_string('connection_heading_desc', 'local_aiquizremedial')
    ));

    $centralconfigstatus = '';
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
        $centralsiteid = function_exists('local_aiconfig_get_siteid') ? local_aiconfig_get_siteid() : '';
        $centralapikey = function_exists('local_aiconfig_get_apikey') ? local_aiconfig_get_apikey() : '';
        if (!empty($centralsiteid) && !empty($centralapikey)) {
            $centralconfigstatus = '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:10px 14px;border-radius:4px;margin-bottom:12px;">'
                . '<strong>&#10004; Central Config active</strong> &mdash; Site ID and API Key are being provided by AI Grader Central Config (<code>local_aiconfig</code>). '
                . 'The fields below are optional overrides.</div>';
        } else {
            $centralconfigstatus = '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:10px 14px;border-radius:4px;margin-bottom:12px;">'
                . '<strong>&#9888; Central Config installed but incomplete</strong> &mdash; Please configure Site ID and API Key in '
                . '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_aiconfig">AI Grader Central Config</a>, '
                . 'or enter them below as local overrides.</div>';
        }
    } else {
        $centralconfigstatus = '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:10px 14px;border-radius:4px;margin-bottom:12px;">'
            . '<strong>Central Config not installed</strong> &mdash; Install <a href="' . $CFG->wwwroot . '/admin/search.php#local_aiconfig">AI Grader Central Config</a> '
            . 'to share Site ID and API Key across all AI Grader plugins, or enter them below.</div>';
    }

    $settings->add(new admin_setting_heading(
        'local_aiquizremedial/centralconfigstatus',
        '',
        $centralconfigstatus
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiquizremedial/siteid',
        get_string('siteid', 'local_aiquizremedial'),
        get_string('siteid_desc', 'local_aiquizremedial'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aiquizremedial/apikey',
        get_string('apikey', 'local_aiquizremedial'),
        get_string('apikey_desc', 'local_aiquizremedial'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_aiquizremedial/featuresheading',
        get_string('features_heading', 'local_aiquizremedial'),
        get_string('features_heading_desc', 'local_aiquizremedial')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiquizremedial/enablevoiceover',
        get_string('enablevoiceover', 'local_aiquizremedial'),
        get_string('enablevoiceover_desc', 'local_aiquizremedial'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'local_aiquizremedial/voiceoverplayback',
        get_string('voiceoverplayback', 'local_aiquizremedial'),
        get_string('voiceoverplayback_desc', 'local_aiquizremedial'),
        'manual',
        [
            'manual' => get_string('voiceoverplayback_manual', 'local_aiquizremedial'),
            'auto'   => get_string('voiceoverplayback_auto',   'local_aiquizremedial'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiquizremedial/enableimages',
        get_string('enableimages', 'local_aiquizremedial'),
        get_string('enableimages_desc', 'local_aiquizremedial'),
        0
    ));

    $settings->add(new admin_setting_configmulticheckbox(
        'local_aiquizremedial/extra_languages',
        get_string('extra_languages', 'local_aiquizremedial'),
        get_string('extra_languages_desc', 'local_aiquizremedial'),
        [],
        [
            'fr' => get_string('lang_fr', 'local_aiquizremedial'),
            'es' => get_string('lang_es', 'local_aiquizremedial'),
            'zh' => get_string('lang_zh', 'local_aiquizremedial'),
            'ar' => get_string('lang_ar', 'local_aiquizremedial'),
            'pt' => get_string('lang_pt', 'local_aiquizremedial'),
            'de' => get_string('lang_de', 'local_aiquizremedial'),
            'ja' => get_string('lang_ja', 'local_aiquizremedial'),
            'ko' => get_string('lang_ko', 'local_aiquizremedial'),
            'vi' => get_string('lang_vi', 'local_aiquizremedial'),
            'hi' => get_string('lang_hi', 'local_aiquizremedial'),
            'id' => get_string('lang_id', 'local_aiquizremedial'),
            'it' => get_string('lang_it', 'local_aiquizremedial'),
        ]
    ));

    $ADMIN->add('localplugins', $settings);
}
