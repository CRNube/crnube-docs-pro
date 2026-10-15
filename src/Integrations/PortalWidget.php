<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Integrations;

use CRNube\Docs\Integrations\Elementor\DocumentsWidget;
use CRNube\Docs\Integrations\Elementor\Integration;
use CRNube\DocsPro\Pro;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * "Members' portal" widget: the same as [crnube_docs_portal].
 */
final class PortalWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'crnube-docs-portal';
    }

    public function get_title(): string
    {
        return __('Members\' portal', 'crnube-docs-pro');
    }

    public function get_icon(): string
    {
        return 'eicon-lock-user';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return [Integration::CATEGORY];
    }

    /** @return list<string> */
    public function get_keywords(): array
    {
        return ['portal', 'privado', 'login', 'miembros', 'junta', 'delegados'];
    }

    /** @return list<string> */
    public function get_style_depends(): array
    {
        return ['crnd-frontend'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('content', ['label' => __('Portal', 'crnube-docs-pro')]);
        $this->add_control('category', [
            'label'       => __('Categories', 'crnube-docs-pro'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'options'     => DocumentsWidget::categoryOptions(),
            'label_block' => true,
            'description' => __('Empty: every restricted document the member may open.', 'crnube-docs-pro'),
        ]);
        $this->add_control('layout', [
            'label'   => __('Layout', 'crnube-docs-pro'),
            'type'    => Controls_Manager::SELECT,
            'default' => '',
            'options' => [
                ''         => __('As in the settings', 'crnube-docs-pro'),
                'tree'     => __('Tree', 'crnube-docs-pro'),
                'sections' => __('Sections', 'crnube-docs-pro'),
                'grid'     => __('Grid', 'crnube-docs-pro'),
                'list'     => __('List', 'crnube-docs-pro'),
            ],
        ]);
        $this->add_control('hint', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('Visitors see the sign-in with an emailed code; members see their documents. Groups are managed in Documents › Access.', 'crnube-docs-pro'),
            'content_classes' => 'elementor-descriptor',
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $atts     = [];
        $ids      = array_filter(array_map('absint', (array) ($settings['category'] ?? [])));
        if ($ids !== []) {
            $atts['category'] = implode(',', $ids);
        }
        if (!empty($settings['layout'])) {
            $atts['layout'] = (string) $settings['layout'];
        }
        echo Pro::instance()->portal->render($atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the portal template
    }
}
