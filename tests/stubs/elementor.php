<?php
// Minimal declarations of the Elementor classes the widgets use, for static analysis only.
// phpcs:ignoreFile

namespace Elementor;

abstract class Widget_Base
{
    /** @param array<string, mixed> $args */
    public function start_controls_section(string $id, array $args = []): void {}
    public function end_controls_section(): void {}
    /** @param array<string, mixed> $args */
    public function add_control(string $id, array $args = []): bool { return true; }
    /** @return array<string, mixed> */
    public function get_settings_for_display(?string $key = null): array { return []; }
}

class Controls_Manager
{
    public const TEXT      = 'text';
    public const NUMBER    = 'number';
    public const SELECT    = 'select';
    public const SELECT2   = 'select2';
    public const COLOR     = 'color';
    public const RAW_HTML  = 'raw_html';
    public const TAB_STYLE = 'style';
}

class Elements_Manager
{
    /** @param array<string, mixed> $args */
    public function add_category(string $name, array $args): void {}
}

class Widgets_Manager
{
    public function register(Widget_Base $widget): bool { return true; }
}

class Plugin
{
    public static ?Plugin $instance = null;
    public ?object $files_manager = null;
}
