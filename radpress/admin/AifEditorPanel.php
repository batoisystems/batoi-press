<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\Config;

final class AifEditorPanel
{
    public static function render(Config $config, string $contentType): string
    {
        $status = (new AifManager($config->aif()))->status();
        $ready = ($status['enabled'] ?? false) === true && ($status['available'] ?? false) === true;
        $features = is_array($status['features'] ?? null) ? $status['features'] : [];
        $provider = (string)($status['provider'] ?? 'disabled');
        $endpoint = function_exists('bp_url') ? \bp_url('/admin/aif/assist') : '/admin/aif/assist';
        $html = '<section class="bp-editor-panel bp-aif-editor-panel" data-bp-aif-panel data-endpoint="' . self::e($endpoint) . '" data-content-type="' . self::e($contentType) . '"><header><div><h2>Batoi AIF</h2><p>Prepare suggestions inside the editor; nothing is applied or published automatically.</p></div><span class="bp-status-badge ' . ($ready ? 'is-published' : 'is-draft') . '">' . ($ready ? 'Ready' : 'Off') . '</span></header>';
        if (!$ready) {
            return $html . '<p class="bp-field-help">AIF is installed but disabled. An owner can review its trust boundary and provider status under <a href="/admin/aif">Batoi AIF</a>.</p></section>';
        }

        $labels = [
            'content_health' => 'Check content',
            'seo_assist' => 'Suggest SEO',
            'summarize' => 'Summarize',
            'tags' => 'Suggest tags',
            'draft_content' => 'Build outline',
        ];
        $html .= '<p class="bp-field-help">Provider: <strong>' . self::e($provider) . '</strong>' . (($status['network_access'] ?? false) ? ' · configured network provider' : ' · stays on this server') . '</p><div class="bp-aif-actions">';
        foreach ($labels as $task => $label) {
            if (($features[$task] ?? false) !== true) {
                continue;
            }
            $html .= '<button type="button" class="bp-button bp-button-secondary" data-bp-aif-task="' . self::e($task) . '">' . self::e($label) . '</button>';
        }
        $html .= '</div><div class="bp-aif-result" data-bp-aif-result hidden tabindex="-1" aria-live="polite"></div>';
        return $html . '</section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
