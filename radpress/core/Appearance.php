<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Validated default-theme tokens shared by settings and public rendering. */
final class Appearance
{
    public const LABELS = [
        'body_bg' => 'Body background', 'header_bg' => 'Header background', 'footer_bg' => 'Footer background',
        'link_hover' => 'Link hover', 'header_text' => 'Header text', 'body_text' => 'Body text',
        'primary_button' => 'Primary button', 'secondary_button' => 'Secondary button',
        'primary_hover' => 'Primary button hover', 'secondary_hover' => 'Secondary button hover',
    ];

    public static function palette(array $site, string $mode): array
    {
        $dark = $mode === 'dark';
        $defaults = ['body_bg'=>$dark?'#111827':'#FFFFFF','header_bg'=>$dark?'#111827':'#FFFFFF','footer_bg'=>$dark?'#1F2937':'#F3F6F8','link_hover'=>$dark?'#7DD3FC':'#07497C','header_text'=>$dark?'#F9FAFB':'#111827','body_text'=>$dark?'#E5E7EB':'#374151','primary_button'=>'#0E68B0','secondary_button'=>$dark?'#374151':'#F3F6F8','primary_hover'=>'#07497C','secondary_hover'=>$dark?'#4B5563':'#E5E7EB'];
        if (preg_match('/^#[0-9a-f]{6}$/iD', (string)($site['brand_primary_color'] ?? ''))) $defaults['primary_button'] = strtoupper((string)$site['brand_primary_color']);
        foreach ($defaults as $key => $fallback) {
            $value = (string)($site['palette_' . $mode][$key] ?? '');
            if (preg_match('/^#[0-9a-f]{6}$/iD', $value)) $defaults[$key] = strtoupper($value);
        }
        return $defaults;
    }

    public static function css(array $site): string
    {
        $rules = [];
        foreach (['light', 'dark'] as $mode) {
            $tokens = '';
            foreach (self::palette($site, $mode) as $key => $value) $tokens .= '--bp-' . str_replace('_', '-', $key) . ':' . $value . ';';
            $rules[$mode] = $tokens;
        }
        return ':root{' . $rules['light'] . '}[data-bp-color-mode="dark"]{' . $rules['dark'] . '}@media(prefers-color-scheme:dark){[data-bp-color-mode="system"]{' . $rules['dark'] . '}}';
    }

    public static function footerLinks(string $source): array
    {
        $links = [];
        foreach (array_slice(explode("\n", $source), 0, 12) as $line) {
            [$label, $url, $icon] = array_pad(array_map('trim', explode('|', $line, 3)), 3, '');
            $local = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\');
            $https = filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://');
            if ($label !== '' && ($local || $https) && !preg_match('/[\x00-\x20\x7f]/', $url)) $links[] = ['label'=>$label, 'url'=>$url, 'icon'=>$icon];
        }
        return $links;
    }
}
