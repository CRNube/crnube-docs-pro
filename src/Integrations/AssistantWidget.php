<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Integrations;

use CRNube\Docs\Integrations\Elementor\DocumentsWidget;
use CRNube\Docs\Integrations\Elementor\Integration;
use CRNube\DocsPro\Assistant\Widget;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * "Document assistant" widget: the same as [crnube_docs_assistant].
 */
final class AssistantWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'crnube-docs-assistant';
    }

    public function get_title(): string
    {
        return __('Document assistant', 'crnube-docs-pro');
    }

    public function get_icon(): string
    {
        return 'eicon-commenting-o';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return [Integration::CATEGORY];
    }

    /** @return list<string> */
    public function get_keywords(): array
    {
        return ['asistente', 'chat', 'ia', 'ai', 'preguntas', 'documentos'];
    }

    /** @return list<string> */
    public function get_style_depends(): array
    {
        return ['crnd-frontend', Widget::HANDLE];
    }

    /** @return list<string> */
    public function get_script_depends(): array
    {
        return [Widget::HANDLE];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('content', ['label' => __('Assistant', 'crnube-docs-pro')]);
        $this->add_control('category', [
            'label'       => __('Answer only from these categories', 'crnube-docs-pro'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'options'     => DocumentsWidget::categoryOptions(),
            'label_block' => true,
            'description' => __('Empty: every public document.', 'crnube-docs-pro'),
        ]);
        $this->add_control('title', [
            'label' => __('Title', 'crnube-docs-pro'),
            'type'  => Controls_Manager::TEXT,
        ]);
        $this->add_control('hint', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('Provider, spending caps, welcome text and suggested questions are set in Documents › Assistant. If the assistant is off, the widget shows nothing to visitors.', 'crnube-docs-pro'),
            'content_classes' => 'elementor-descriptor',
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $atts     = ['title' => (string) ($settings['title'] ?? '')];
        $ids      = array_filter(array_map('absint', (array) ($settings['category'] ?? [])));
        if ($ids !== []) {
            $atts['category'] = implode(',', $ids);
        }
        echo (new Widget())->render($atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the assistant template
    }
}
