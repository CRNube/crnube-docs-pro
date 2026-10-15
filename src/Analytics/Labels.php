<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Analytics;

/**
 * Human names for the values stored in the events table (used in the CSV export; the
 * reports screen has the same names in JavaScript).
 */
final class Labels
{
    public static function event(string $event): string
    {
        return $event === 'view' ? __('Viewed', 'crnube-docs-pro') : __('Downloaded', 'crnube-docs-pro');
    }

    public static function get(string $dimension, string $value): string
    {
        if ($value === '') {
            return __('Unknown', 'crnube-docs-pro');
        }
        if ($dimension === 'country') {
            // Country names in the site language when PHP has intl; the code otherwise.
            if (class_exists(\Locale::class)) {
                $name = \Locale::getDisplayRegion('-' . $value, get_locale());
                return $name !== '' && $name !== $value ? $name : $value;
            }
            return $value;
        }

        $names = [
            'device'  => [
                'desktop' => __('Computer', 'crnube-docs-pro'),
                'mobile'  => __('Phone', 'crnube-docs-pro'),
                'tablet'  => __('Tablet', 'crnube-docs-pro'),
            ],
            'browser' => [
                'chrome'  => 'Chrome',
                'safari'  => 'Safari',
                'edge'    => 'Edge',
                'firefox' => 'Firefox',
                'opera'   => 'Opera',
                'samsung' => 'Samsung Internet',
                'other'   => __('Other', 'crnube-docs-pro'),
            ],
            'os'      => [
                'windows'  => 'Windows',
                'macos'    => 'macOS',
                'ios'      => 'iOS',
                'android'  => 'Android',
                'linux'    => 'Linux',
                'chromeos' => 'ChromeOS',
                'other'    => __('Other', 'crnube-docs-pro'),
            ],
        ];
        return $names[$dimension][$value] ?? $value;
    }
}
