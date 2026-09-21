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

namespace local_aiquizremedial\insights;

use html_writer;

/**
 * Self-contained SVG/HTML charts for the Insights page (v1.5.0).
 *
 * No chart library: every chart is plain SVG or HTML styled by styles.css (.aiqr-ins tokens).
 * Rules followed throughout: one axis, thin marks with rounded data ends, a recessive grid,
 * text in ink colours (never the series colour), a hover/focus tooltip on every mark
 * (data-aiqr-tip) and a "View as table" alternative for every chart.
 *
 * @package    local_aiquizremedial
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class charts {
    /** Severity icons (inline SVG paths, 16px box). */
    const ICONS = [
        'critical' => '<path d="M5.2 1.5h5.6l3.7 3.7v5.6l-3.7 3.7H5.2l-3.7-3.7V5.2z" fill="currentColor"/>' .
            '<path d="M8 4.5v4.3M8 10.9v.1" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>',
        'high' => '<path d="M8 1.6l6.9 12.2H1.1z" fill="currentColor"/>' .
            '<path d="M8 6v3.6M8 11.6v.1" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/>',
        'medium' => '<rect x="3" y="3" width="10" height="10" rx="1.5" transform="rotate(45 8 8)" fill="currentColor"/>',
        'low' => '<circle cx="8" cy="8" r="5.5" fill="currentColor"/>',
        'info' => '<circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.6"/>' .
            '<path d="M8 7.2v4M8 4.8v.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
    ];

    /**
     * Tooltip attributes for a mark or cell (hover and keyboard focus).
     *
     * @param string $text plain text
     * @param bool $focusable
     * @return array
     */
    public static function tip(string $text, bool $focusable = true): array {
        $a = ['data-aiqr-tip' => $text, 'aria-label' => $text];
        if ($focusable) {
            $a['tabindex'] = '0';
        }
        return $a;
    }

    /**
     * Attribute string for inline SVG.
     *
     * @param array $attrs
     * @return string
     */
    protected static function attrs(array $attrs): string {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                continue;
            }
            $out .= ' ' . $k . '="' . s((string) $v) . '"';
        }
        return $out;
    }

    /**
     * Round for SVG output.
     *
     * @param float $v
     * @return string
     */
    protected static function n(float $v): string {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /**
     * Percent text.
     *
     * @param float|null $v 0..1
     * @return string
     */
    public static function pct(?float $v): string {
        return $v === null ? '–' : round($v * 100) . '%';
    }

    /**
     * Severity badge: icon + word + colour.
     *
     * @param string $severity
     * @return string
     */
    public static function severity(string $severity): string {
        $icon = '<svg class="aiqr-sev-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">' .
            (self::ICONS[$severity] ?? self::ICONS['info']) . '</svg>';
        return html_writer::span($icon . html_writer::span(get_string('sev_' . $severity, 'local_aiquizremedial'),
            'aiqr-sev-label'), 'aiqr-sev aiqr-sev-' . $severity);
    }

    /**
     * Chart card with title, optional subtitle, body and "View as table".
     *
     * @param string $title
     * @param string $body
     * @param string $subtitle
     * @param string $table HTML table (the accessible alternative)
     * @param string $class
     * @param string $help tooltip for the (i) next to the title
     * @return string
     */
    public static function card(string $title, string $body, string $subtitle = '', string $table = '', string $class = '',
            string $help = ''): string {
        $head = html_writer::tag('h3', s($title) . ($help !== '' ? ' ' . html_writer::span('i', 'aiqr-help',
            self::tip($help) + ['role' => 'note']) : ''), ['class' => 'aiqr-card-title']);
        if ($subtitle !== '') {
            $head .= html_writer::div(s($subtitle), 'aiqr-card-sub');
        }
        $html = html_writer::div($head, 'aiqr-card-head') . html_writer::div($body, 'aiqr-card-body');
        if ($table !== '') {
            $html .= html_writer::tag('details', html_writer::tag('summary', get_string('viewastable', 'local_aiquizremedial')) .
                html_writer::div($table, 'aiqr-tableview-inner'), ['class' => 'aiqr-tableview']);
        }
        return html_writer::tag('section', $html, ['class' => trim('aiqr-card ' . $class)]);
    }

    /**
     * Plain accessible table.
     *
     * @param array $head
     * @param array $rows arrays of cell HTML (already escaped)
     * @return string
     */
    public static function table(array $head, array $rows): string {
        $h = '';
        foreach ($head as $c) {
            $h .= html_writer::tag('th', $c, ['scope' => 'col']);
        }
        $b = '';
        foreach ($rows as $r) {
            $cells = '';
            foreach ($r as $c) {
                $cells .= html_writer::tag('td', $c);
            }
            $b .= html_writer::tag('tr', $cells);
        }
        return html_writer::tag('table', html_writer::tag('thead', html_writer::tag('tr', $h)) . html_writer::tag('tbody', $b),
            ['class' => 'aiqr-datatable']);
    }

    /**
     * Headline number tile.
     *
     * @param string $label
     * @param string $value
     * @param float|null $delta change vs previous period (same unit as value; points for %)
     * @param string $deltatext e.g. "6 pts vs previous 90 days"
     * @param bool $goodup is an increase good?
     * @param string $sub small caption (usually n)
     * @param string $help tooltip text
     * @param string $extra extra HTML under the value
     * @return string
     */
    public static function kpi(string $label, string $value, ?float $delta, string $deltatext, bool $goodup, string $sub,
            string $help = '', string $extra = ''): string {
        $html = html_writer::div(s($label) . ($help !== '' ? ' ' . html_writer::span('i', 'aiqr-help', self::tip($help)
            + ['role' => 'note']) : ''), 'aiqr-kpi-label');
        $html .= html_writer::div(s($value), 'aiqr-kpi-value');
        if ($delta !== null) {
            if (abs($delta) < 0.5) {
                $cls = 'flat';
                $arrow = '→';
            } else {
                $good = ($delta > 0) === $goodup;
                $cls = $good ? 'good' : 'bad';
                $arrow = $delta > 0 ? '↑' : '↓';
            }
            $html .= html_writer::div(html_writer::span($arrow, 'aiqr-delta-arrow', ['aria-hidden' => 'true']) . ' ' .
                s($deltatext), 'aiqr-delta aiqr-delta-' . $cls);
        } else if ($deltatext !== '') {
            $html .= html_writer::div(s($deltatext), 'aiqr-delta aiqr-delta-flat');
        }
        $html .= $extra;
        if ($sub !== '') {
            $html .= html_writer::div(s($sub), 'aiqr-kpi-sub');
        }
        return html_writer::div($html, 'aiqr-kpi');
    }

    /**
     * Inline % bar (0..100) with the value as text beside it.
     *
     * @param float|null $v 0..1
     * @param bool $low low sample (muted)
     * @param string $tip
     * @return string
     */
    public static function pctbar(?float $v, bool $low = false, string $tip = ''): string {
        $w = $v === null ? 0 : max(2, round($v * 100));
        $bar = html_writer::span(html_writer::span('', 'aiqr-pbar-fill', ['style' => 'width:' . $w . '%']),
            'aiqr-pbar' . ($low ? ' aiqr-pbar-low' : ''));
        return html_writer::span($bar . html_writer::span(self::pct($v), 'aiqr-pbar-val'), 'aiqr-pbar-wrap',
            $tip !== '' ? self::tip($tip) : []);
    }

    /**
     * 12-week sparkline of % correct.
     *
     * @param array $values floats 0..1 or null (gap)
     * @param string $label accessible description
     * @return string
     */
    public static function sparkline(array $values, string $label): string {
        $w = 96;
        $h = 26;
        $count = count($values);
        if (!$count || !array_filter($values, function ($v) {
            return $v !== null;
        })) {
            return html_writer::span('–', 'aiqr-muted');
        }
        $step = $count > 1 ? ($w - 6) / ($count - 1) : 0;
        $paths = [];
        $cur = '';
        $last = null;
        foreach ($values as $i => $v) {
            if ($v === null) {
                if ($cur !== '') {
                    $paths[] = $cur;
                }
                $cur = '';
                continue;
            }
            $x = 3 + $i * $step;
            $y = 3 + (1 - (float) $v) * ($h - 6);
            $cur .= ($cur === '' ? 'M' : 'L') . self::n($x) . ' ' . self::n($y);
            $last = [$x, $y];
        }
        if ($cur !== '') {
            $paths[] = $cur;
        }
        $svg = '<svg class="aiqr-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '"' .
            self::attrs(['role' => 'img', 'aria-label' => $label]) . '>';
        $svg .= '<line class="aiqr-spark-mid" x1="3" x2="' . ($w - 3) . '" y1="' . ($h / 2) . '" y2="' . ($h / 2) . '"/>';
        foreach ($paths as $d) {
            if (strpos($d, 'L') === false) {
                // A single week between gaps: show it as a small dot.
                [$px, $py] = explode(' ', substr($d, 1));
                $svg .= '<circle class="aiqr-spark-pt" cx="' . $px . '" cy="' . $py . '" r="1.6"/>';
            } else {
                $svg .= '<path class="aiqr-spark-line" d="' . $d . '"/>';
            }
        }
        if ($last) {
            $svg .= '<circle class="aiqr-spark-dot" cx="' . self::n($last[0]) . '" cy="' . self::n($last[1]) . '" r="2.6"/>';
        }
        return $svg . '</svg>';
    }

    /**
     * Weekly line chart of % correct with optional edit markers.
     *
     * @param array $points ['start' => ts, 'n' => int, 'value' => float|null]
     * @param array $markers ['time' => ts, 'label' => string]
     * @param string $label accessible description
     * @param int $w drawing width (wider for full-width cards, so text keeps its size)
     * @return string
     */
    public static function line(array $points, array $markers, string $label, int $w = 560): string {
        $h = 230;
        $l = 44;
        $r = 16;
        $t = 16;
        $b = 30;
        $pw = $w - $l - $r;
        $ph = $h - $t - $b;
        $count = count($points);
        if (!$count) {
            return '';
        }
        $x = function (int $i) use ($l, $pw, $count) {
            return $l + ($count > 1 ? $i * $pw / ($count - 1) : $pw / 2);
        };
        $y = function (float $v) use ($t, $ph) {
            return $t + (1 - $v) * $ph;
        };
        $svg = '<svg class="aiqr-chart" viewBox="0 0 ' . $w . ' ' . $h . '"' .
            self::attrs(['role' => 'img', 'aria-label' => $label]) . '>';
        // Grid + y labels.
        foreach ([0, 0.25, 0.5, 0.75, 1] as $g) {
            $gy = self::n($y($g));
            $svg .= '<line class="aiqr-grid' . ($g == 0 ? ' aiqr-baseline' : '') . '" x1="' . $l . '" x2="' . ($w - $r) .
                '" y1="' . $gy . '" y2="' . $gy . '"/>';
            $svg .= '<text class="aiqr-axis" x="' . ($l - 8) . '" y="' . self::n($y($g) + 4) . '" text-anchor="end">' .
                round($g * 100) . '%</text>';
        }
        // X labels: first, middle, last.
        $fmt = get_string('strftimedateshort', 'langconfig');
        foreach (array_unique([0, (int) floor(($count - 1) / 2), $count - 1]) as $i) {
            $anchor = $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle');
            $svg .= '<text class="aiqr-axis" x="' . self::n($x($i)) . '" y="' . ($h - 8) . '" text-anchor="' . $anchor . '">' .
                s(userdate($points[$i]['start'], $fmt)) . '</text>';
        }
        // Edit markers with "after" shading from the latest edit.
        $first = $points[0]['start'];
        $span = max(1, $points[$count - 1]['start'] + WEEKSECS - $first);
        $mx = function (int $ts) use ($l, $pw, $first, $span, $count, $x) {
            $i = ($ts - $first) / WEEKSECS;
            return $count > 1 ? $l + max(0, min($count - 1, $i)) * $pw / ($count - 1) : $x(0);
        };
        $markers = array_values(array_filter($markers, function ($m) use ($first, $span) {
            return $m['time'] >= $first && $m['time'] <= $first + $span;
        }));
        if ($markers) {
            $lastm = end($markers);
            $sx = $mx((int) $lastm['time']);
            $svg .= '<rect class="aiqr-after" x="' . self::n($sx) . '" y="' . $t . '" width="' . self::n($w - $r - $sx) .
                '" height="' . $ph . '"/>';
        }
        foreach ($markers as $m) {
            $mxv = self::n($mx((int) $m['time']));
            $svg .= '<g class="aiqr-marker"' . self::attrs(self::tip($m['label'])) . '>' .
                '<line x1="' . $mxv . '" x2="' . $mxv . '" y1="' . $t . '" y2="' . ($t + $ph) . '"/>' .
                '<rect x="' . self::n((float) $mxv - 9) . '" y="' . ($t - 2) . '" width="18" height="14" rx="4"/>' .
                '<text x="' . $mxv . '" y="' . ($t + 9) . '" text-anchor="middle">' . s($m['short'] ?? '✎') . '</text></g>';
        }
        // Line (gaps where a week has too few answers).
        $d = '';
        $prevok = false;
        foreach ($points as $i => $p) {
            if ($p['value'] === null || $p['n'] < 2) {
                $prevok = false;
                continue;
            }
            $d .= ($prevok ? 'L' : 'M') . self::n($x($i)) . ' ' . self::n($y((float) $p['value']));
            $prevok = true;
        }
        if ($d !== '') {
            $svg .= '<path class="aiqr-line" d="' . $d . '"/>';
        }
        // Points (with tooltip) — 8px markers, 2px surface ring.
        foreach ($points as $i => $p) {
            if ($p['value'] === null || $p['n'] < 2) {
                continue;
            }
            $tip = get_string('tip_week', 'local_aiquizremedial', (object) [
                'week' => userdate($p['start'], $fmt), 'pct' => self::pct((float) $p['value']), 'n' => $p['n']]);
            $svg .= '<g class="aiqr-pt"' . self::attrs(self::tip($tip)) . '>' .
                '<circle class="aiqr-hit" cx="' . self::n($x($i)) . '" cy="' . self::n($y((float) $p['value'])) . '" r="12"/>' .
                '<circle class="aiqr-dot" cx="' . self::n($x($i)) . '" cy="' . self::n($y((float) $p['value'])) . '" r="4"/></g>';
        }
        return $svg . '</svg>';
    }

    /**
     * Blue sequential step for a heatmap cell (darker = fewer correct).
     *
     * @param float $v % correct 0..1
     * @return int step 1..7
     */
    public static function heatstep(float $v): int {
        return (int) max(1, min(7, 1 + floor((1 - $v) * 7)));
    }

    /**
     * Questions × groups heatmap (HTML grid, so it wraps and scrolls on small screens).
     *
     * @param array $rows ['label', 'sub', 'url', 'cells' => [colid => ['n', 'value']]]
     * @param array $cols colid => name
     * @param int $mincell
     * @return string
     */
    public static function heatmap(array $rows, array $cols, int $mincell): string {
        $head = html_writer::tag('th', get_string('col_question', 'local_aiquizremedial'), ['scope' => 'col',
            'class' => 'aiqr-hm-rowhead']);
        foreach ($cols as $name) {
            $head .= html_writer::tag('th', html_writer::span(s($name), 'aiqr-hm-colname'), ['scope' => 'col']);
        }
        $body = '';
        foreach ($rows as $r) {
            $cells = html_writer::tag('th', html_writer::link($r['url'], s($r['label']), ['class' => 'aiqr-hm-q']) .
                html_writer::div(s($r['sub']), 'aiqr-hm-sub'), ['scope' => 'row']);
            foreach ($cols as $cid => $name) {
                $c = $r['cells'][$cid] ?? null;
                if (!$c || $c['n'] < $mincell || $c['value'] === null) {
                    $tip = get_string('tip_cell_small', 'local_aiquizremedial', (object) ['group' => $name, 'min' => $mincell]);
                    $cells .= html_writer::tag('td', html_writer::span('', 'aiqr-hm-cell aiqr-hm-small', self::tip($tip)));
                    continue;
                }
                $step = self::heatstep((float) $c['value']);
                $tip = get_string('tip_cell', 'local_aiquizremedial', (object) ['group' => $name, 'q' => $r['label'],
                    'pct' => self::pct((float) $c['value']), 'n' => $c['n']]);
                $cells .= html_writer::tag('td', html_writer::span(self::pct((float) $c['value']),
                    'aiqr-hm-cell aiqr-hm-' . $step, self::tip($tip)));
            }
            $body .= html_writer::tag('tr', $cells);
        }
        $legend = html_writer::div(
            html_writer::span(get_string('legend_morecorrect', 'local_aiquizremedial'), 'aiqr-legend-end') .
            html_writer::span(implode('', array_map(function ($i) {
                return html_writer::span('', 'aiqr-hm-' . $i);
            }, range(1, 7))), 'aiqr-hm-ramp', ['aria-hidden' => 'true']) .
            html_writer::span(get_string('legend_fewercorrect', 'local_aiquizremedial'), 'aiqr-legend-end') .
            html_writer::span(html_writer::span('', 'aiqr-hm-small aiqr-hm-swatch') . ' ' .
                get_string('legend_small', 'local_aiquizremedial', $mincell), 'aiqr-legend-small'),
            'aiqr-hm-legend');
        return html_writer::div(html_writer::tag('table', html_writer::tag('thead', html_writer::tag('tr', $head)) .
            html_writer::tag('tbody', $body), ['class' => 'aiqr-heatmap table-reboot']), 'aiqr-hm-scroll') . $legend;
    }

    /**
     * Difficulty vs discrimination scatter with four plain-English zones.
     *
     * @param array $points ['x' => 0..1 % correct, 'y' => discrimination -1..1, 'n', 'label', 'url', 'low' => bool]
     * @return string
     */
    public static function scatter(array $points): string {
        $w = 560;
        $h = 300;
        $l = 50;
        $r = 16;
        $t = 14;
        $b = 38;
        $pw = $w - $l - $r;
        $ph = $h - $t - $b;
        $ymin = -0.3;
        $ymax = 0.8;
        $x = function (float $v) use ($l, $pw) {
            return $l + max(0, min(1, $v)) * $pw;
        };
        $y = function (float $v) use ($t, $ph, $ymin, $ymax) {
            $v = max($ymin, min($ymax, $v));
            return $t + ($ymax - $v) / ($ymax - $ymin) * $ph;
        };
        $svg = '<svg class="aiqr-chart" viewBox="0 0 ' . $w . ' ' . $h . '"' .
            self::attrs(['role' => 'img', 'aria-label' => get_string('scatter_title', 'local_aiquizremedial')]) . '>';
        // Zones.
        $svg .= '<rect class="aiqr-zone-broken" x="' . $l . '" y="' . self::n($y(0)) . '" width="' . $pw . '" height="' .
            self::n($y($ymin) - $y(0)) . '"/>';
        $svg .= '<rect class="aiqr-zone-easy" x="' . self::n($x(0.9)) . '" y="' . $t . '" width="' . self::n($x(1) - $x(0.9)) .
            '" height="' . self::n($y(0) - $t) . '"/>';
        $svg .= '<rect class="aiqr-zone-hard" x="' . $l . '" y="' . $t . '" width="' . self::n($x(0.3) - $l) .
            '" height="' . self::n($y(0) - $t) . '"/>';
        // Grid.
        foreach ([-0.2, 0, 0.2, 0.4, 0.6, 0.8] as $g) {
            $gy = self::n($y($g));
            $svg .= '<line class="aiqr-grid' . ($g == 0 ? ' aiqr-baseline' : '') . '" x1="' . $l . '" x2="' . ($w - $r) .
                '" y1="' . $gy . '" y2="' . $gy . '"/>';
            $svg .= '<text class="aiqr-axis" x="' . ($l - 8) . '" y="' . self::n($y($g) + 4) . '" text-anchor="end">' .
                round($g * 100) . '</text>';
        }
        foreach ([0, 0.25, 0.5, 0.75, 1] as $g) {
            $svg .= '<text class="aiqr-axis" x="' . self::n($x($g)) . '" y="' . ($t + $ph + 16) .
                '" text-anchor="middle">' . round($g * 100) . '%</text>';
        }
        $svg .= '<line class="aiqr-guide" x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . self::n($y(0.2)) . '" y2="' .
            self::n($y(0.2)) . '"/>';
        $svg .= '<text class="aiqr-axis-title" x="' . ($l + $pw / 2) . '" y="' . ($h - 4) . '" text-anchor="middle">' .
            s(get_string('scatter_x', 'local_aiquizremedial')) . '</text>';
        $svg .= '<text class="aiqr-axis-title" transform="translate(12 ' . ($t + $ph / 2) . ') rotate(-90)" text-anchor="middle">' .
            s(get_string('scatter_y', 'local_aiquizremedial')) . '</text>';
        // Zone labels (muted ink).
        $svg .= '<text class="aiqr-zone-label" x="' . self::n($x(1) - 6) . '" y="' . self::n($y($ymin) - 8) .
            '" text-anchor="end">' . s(get_string('zone_broken', 'local_aiquizremedial')) . '</text>';
        $svg .= '<text class="aiqr-zone-label" x="' . self::n($x(1) - 6) . '" y="' . ($t + 14) . '" text-anchor="end">' .
            s(get_string('zone_easy', 'local_aiquizremedial')) . '</text>';
        $svg .= '<text class="aiqr-zone-label" x="' . ($l + 8) . '" y="' . ($t + 14) . '">' .
            s(get_string('zone_hard', 'local_aiquizremedial')) . '</text>';
        $svg .= '<text class="aiqr-zone-label" x="' . self::n($x(0.6)) . '" y="' . ($t + 14) . '" text-anchor="middle">' .
            s(get_string('zone_healthy', 'local_aiquizremedial')) . '</text>';
        // Points: 2px surface ring, low-sample hollow.
        foreach ($points as $p) {
            $cx = self::n($x((float) $p['x']));
            $cy = self::n($y((float) $p['y']));
            $svg .= '<a href="' . s($p['url']) . '" class="aiqr-sc-pt' . (!empty($p['low']) ? ' aiqr-sc-low' : '') . '"' .
                self::attrs(self::tip($p['tip'], false)) . '>' .
                '<circle class="aiqr-hit" cx="' . $cx . '" cy="' . $cy . '" r="11"/>' .
                '<circle class="aiqr-dot" cx="' . $cx . '" cy="' . $cy . '" r="5"/></a>';
        }
        return $svg . '</svg>';
    }

    /**
     * Revision funnel (ordinal blue steps, one bar per stage).
     *
     * @param array $steps ['label', 'n', 'of' => int base for %, 'note' => string]
     * @return string
     */
    public static function funnel(array $steps): string {
        $base = max(1, (int) ($steps[0]['n'] ?? 0));
        $html = '';
        foreach ($steps as $i => $s) {
            $of = max(1, (int) ($s['of'] ?? $base));
            $pct = (int) $s['n'] / $of;
            $width = $i === 0 ? 100 : max(1, round((int) $s['n'] / $base * 100, 1));
            $tip = get_string('tip_funnel', 'local_aiquizremedial', (object) ['label' => $s['label'], 'n' => (int) $s['n'],
                'of' => $of, 'pct' => self::pct($pct)]);
            $html .= html_writer::div(
                html_writer::div(s($s['label']) . (!empty($s['note']) ? html_writer::span(s($s['note']), 'aiqr-fn-note') : ''),
                    'aiqr-fn-label') .
                html_writer::div(html_writer::span('', 'aiqr-fn-bar aiqr-fn-' . min(5, $i + 1), ['style' => 'width:' .
                    $width . '%']), 'aiqr-fn-track') .
                html_writer::div(html_writer::tag('strong', (int) $s['n']) . ($i ? ' ' . html_writer::span(self::pct($pct),
                    'aiqr-muted') : ''), 'aiqr-fn-val'),
                'aiqr-fn-row', self::tip($tip));
        }
        return html_writer::div($html, 'aiqr-funnel');
    }

    /**
     * Dot plot with 95% interval whiskers (group comparison).
     *
     * @param array $rows ['name', 'value', 'lo', 'hi', 'learners', 'ref' => bool]
     * @return string
     */
    public static function dotplot(array $rows): string {
        $w = 560;
        $rowh = 34;
        $l = 150;
        $r = 56;
        $t = 8;
        $h = $t + count($rows) * $rowh + 24;
        $pw = $w - $l - $r;
        $x = function (float $v) use ($l, $pw) {
            return $l + max(0, min(1, $v)) * $pw;
        };
        $svg = '<svg class="aiqr-chart" viewBox="0 0 ' . $w . ' ' . $h . '"' .
            self::attrs(['role' => 'img', 'aria-label' => get_string('groups_title', 'local_aiquizremedial')]) . '>';
        foreach ([0, 0.25, 0.5, 0.75, 1] as $g) {
            $gx = self::n($x($g));
            $svg .= '<line class="aiqr-grid" x1="' . $gx . '" x2="' . $gx . '" y1="' . $t . '" y2="' . ($h - 22) . '"/>';
            $svg .= '<text class="aiqr-axis" x="' . $gx . '" y="' . ($h - 6) . '" text-anchor="middle">' . round($g * 100) .
                '%</text>';
        }
        $ref = null;
        foreach ($rows as $row) {
            if (!empty($row['ref'])) {
                $ref = $row['value'];
            }
        }
        if ($ref !== null) {
            $rx = self::n($x((float) $ref));
            $svg .= '<line class="aiqr-refline" x1="' . $rx . '" x2="' . $rx . '" y1="' . $t . '" y2="' . ($h - 22) . '"/>';
        }
        foreach (array_values($rows) as $i => $row) {
            $cy = $t + $i * $rowh + $rowh / 2;
            $tip = get_string('tip_group', 'local_aiquizremedial', (object) ['name' => $row['name'],
                'pct' => self::pct((float) $row['value']), 'lo' => self::pct((float) $row['lo']),
                'hi' => self::pct((float) $row['hi']), 'n' => $row['learners']]);
            $svg .= '<text class="aiqr-rowlabel' . (!empty($row['ref']) ? ' aiqr-rowlabel-ref' : '') . '" x="' . ($l - 12) .
                '" y="' . self::n($cy + 4) . '" text-anchor="end">' . s(shorten_text($row['name'], 22)) . '</text>';
            $svg .= '<g class="aiqr-dp' . (!empty($row['ref']) ? ' aiqr-dp-ref' : '') . '"' . self::attrs(self::tip($tip)) . '>' .
                '<rect class="aiqr-hitrect" x="' . $l . '" y="' . self::n($cy - $rowh / 2) . '" width="' . $pw .
                '" height="' . $rowh . '"/>' .
                '<line class="aiqr-whisker" x1="' . self::n($x((float) $row['lo'])) . '" x2="' . self::n($x((float) $row['hi'])) .
                '" y1="' . self::n($cy) . '" y2="' . self::n($cy) . '"/>' .
                '<circle class="aiqr-dot" cx="' . self::n($x((float) $row['value'])) . '" cy="' . self::n($cy) . '" r="5"/></g>';
            $svg .= '<text class="aiqr-rowvalue" x="' . ($w - $r + 10) . '" y="' . self::n($cy + 4) . '">' .
                self::pct((float) $row['value']) . '</text>';
        }
        return $svg . '</svg>';
    }

    /**
     * Answer breakdown: one bar per option, key marked; stronger/weaker thirds as text.
     *
     * @param array $options answerkey => object(label, iscorrect, n, rate, ratetop, ratebottom)
     * @param bool $thirds show stronger/weaker third columns
     * @param int $n answers on this version
     * @param int $blank left blank
     * @return string
     */
    public static function answers(array $options, bool $thirds, int $n, int $blank): string {
        $max = 0.0;
        foreach ($options as $o) {
            $max = max($max, (float) $o->rate);
        }
        $topwrong = null;
        foreach ($options as $k => $o) {
            if (!(int) $o->iscorrect && ($topwrong === null || $o->n > $options[$topwrong]->n)) {
                $topwrong = $k;
            }
        }
        $html = '';
        if ($thirds) {
            $html .= html_writer::div(
                html_writer::div('', 'aiqr-ans-label') . html_writer::div('', 'aiqr-ans-track') .
                html_writer::div(get_string('col_all', 'local_aiquizremedial'), 'aiqr-ans-val aiqr-ans-colhead') .
                html_writer::div(get_string('col_stronger', 'local_aiquizremedial'), 'aiqr-ans-third aiqr-ans-colhead',
                    self::tip(get_string('help_thirds', 'local_aiquizremedial'))) .
                html_writer::div(get_string('col_weaker', 'local_aiquizremedial'), 'aiqr-ans-third aiqr-ans-colhead'),
                'aiqr-ans-row aiqr-ans-head');
        }
        foreach ($options as $k => $o) {
            $key = (int) $o->iscorrect;
            $cls = $key ? 'aiqr-ans-key' : ($k === $topwrong && $o->n > 0 ? 'aiqr-ans-top' : 'aiqr-ans-other');
            $label = ($key ? html_writer::span('✓ ' . get_string('correctanswer', 'local_aiquizremedial'), 'aiqr-keytag') . ' '
                : ($k === $topwrong && $o->n > 0 ? html_writer::span(get_string('topwrong', 'local_aiquizremedial'),
                'aiqr-toptag') . ' ' : '')) . s($o->label);
            $tip = get_string('tip_option', 'local_aiquizremedial', (object) ['label' => $o->label, 'n' => $o->n,
                'pct' => self::pct((float) $o->rate), 'total' => $n]);
            $width = $max > 0 ? max(0.5, (float) $o->rate / $max * 100) : 0;
            $row = html_writer::div($label, 'aiqr-ans-label') .
                html_writer::div(html_writer::span('', 'aiqr-ans-bar ' . $cls, ['style' => 'width:' . round($width, 1) . '%']),
                    'aiqr-ans-track') .
                html_writer::div(html_writer::tag('strong', self::pct((float) $o->rate)) . ' ' .
                    html_writer::span('(' . $o->n . ')', 'aiqr-muted'), 'aiqr-ans-val');
            if ($thirds) {
                $row .= html_writer::div($o->ratetop === null ? '–' : self::pct((float) $o->ratetop), 'aiqr-ans-third') .
                    html_writer::div($o->ratebottom === null ? '–' : self::pct((float) $o->ratebottom), 'aiqr-ans-third');
            }
            $html .= html_writer::div($row, 'aiqr-ans-row', self::tip($tip));
        }
        if ($blank > 0) {
            $html .= html_writer::div(
                html_writer::div(html_writer::tag('em', get_string('leftblank', 'local_aiquizremedial')), 'aiqr-ans-label') .
                html_writer::div(html_writer::span('', 'aiqr-ans-bar aiqr-ans-blank', ['style' => 'width:' .
                    round($max > 0 ? min(100, $blank / max(1, $n) / $max * 100) : 0, 1) . '%']), 'aiqr-ans-track') .
                html_writer::div(html_writer::tag('strong', self::pct($blank / max(1, $n))) . ' ' .
                    html_writer::span('(' . $blank . ')', 'aiqr-muted'), 'aiqr-ans-val') .
                ($thirds ? html_writer::div('', 'aiqr-ans-third') . html_writer::div('', 'aiqr-ans-third') : ''),
                'aiqr-ans-row');
        }
        return html_writer::div($html, 'aiqr-answers' . ($thirds ? ' aiqr-answers-thirds' : ''));
    }

    /**
     * Tooltip script: one floating tooltip for every [data-aiqr-tip] element (hover and focus).
     *
     * @return string JavaScript
     */
    public static function tooltip_js(): string {
        return <<<'JS'
(function () {
    var root = document.querySelector('.aiqr-ins');
    if (!root || root.dataset.tipready) {
        return;
    }
    root.dataset.tipready = '1';
    var tip = document.createElement('div');
    tip.className = 'aiqr-tooltip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.appendChild(tip);
    var show = function (el) {
        tip.textContent = el.getAttribute('data-aiqr-tip');
        tip.hidden = false;
        var r = el.getBoundingClientRect();
        var tw = tip.offsetWidth;
        var th = tip.offsetHeight;
        var left = Math.max(8, Math.min(window.innerWidth - tw - 8, r.left + r.width / 2 - tw / 2));
        var top = r.top - th - 10;
        if (top < 8) {
            top = r.bottom + 10;
        }
        tip.style.left = (left + window.scrollX) + 'px';
        tip.style.top = (top + window.scrollY) + 'px';
    };
    var hide = function () {
        tip.hidden = true;
    };
    var find = function (e) {
        return e.target && e.target.closest ? e.target.closest('[data-aiqr-tip]') : null;
    };
    root.addEventListener('mouseover', function (e) {
        var el = find(e);
        if (el) {
            show(el);
        }
    });
    root.addEventListener('mouseout', function (e) {
        if (find(e)) {
            hide();
        }
    });
    root.addEventListener('focusin', function (e) {
        var el = find(e);
        if (el) {
            show(el);
        }
    });
    root.addEventListener('focusout', hide);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            hide();
        }
    });
    window.addEventListener('scroll', hide, {passive: true});
})();
JS;
    }
}
