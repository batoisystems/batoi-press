<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\Appearance;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\ThemeManager;
use RuntimeException;

/** Public fields only. Raw configuration and secrets never enter a proposal or tool result. */
final class PublicSettingsRepository
{
    public const CORE_FIELDS = ['name', 'tagline', 'locale', 'timezone', 'posts_per_page'];
    public const APPEARANCE_FIELDS = ['appearance_mode', 'show_theme_toggle', 'posts_load_more', 'palette_light', 'palette_dark', 'brand_primary_color', 'brand_accent_color', 'font_family', 'footer_text', 'footer_bottom_text', 'footer_icon_links', 'footer_top_columns', 'footer_bottom_columns'];

    public function __construct(private readonly Paths $paths) {}

    public function load(): array
    {
        $site = (new WebsiteDocumentStore($this->paths))->read('site');
        return ['settings' => $this->project($site), 'revision' => ContentRevision::for($site), 'capabilities' => $this->capabilities($site)];
    }

    public function prepareSave(array $changes, string $expectedRevision): array
    {
        $site = (new WebsiteDocumentStore($this->paths))->read('site');
        if (!hash_equals(ContentRevision::for($site), trim($expectedRevision, '" '))) throw new MenuConflictException('Public settings changed. Read them again before proposing changes.');
        $capabilities = $this->capabilities($site);
        $after = [];
        foreach ($changes as $key => $value) {
            if (!in_array($key, array_merge(self::CORE_FIELDS, self::APPEARANCE_FIELDS), true)) throw new \InvalidArgumentException('Unsupported public settings field.');
            $feature = str_starts_with($key, 'footer_') ? 'footer_layout' : 'appearance_tokens';
            if (in_array($key, self::APPEARANCE_FIELDS, true) && !$capabilities[$feature]) throw new \InvalidArgumentException('The active theme does not declare support for this appearance feature.');
            if (in_array($key, ['palette_light', 'palette_dark'], true)) {
                if (!is_array($value) || array_diff(array_keys($value), array_keys(Appearance::LABELS)) !== []) throw new \InvalidArgumentException('Use supported palette tokens.');
                $palette = Appearance::palette($site, $key === 'palette_dark' ? 'dark' : 'light');
                foreach ($value as $token => $color) $palette[$token] = $this->color($color);
                $after[$key] = $palette;
            } elseif (in_array($key, ['brand_primary_color', 'brand_accent_color'], true)) {
                $after[$key] = $this->color($value);
            } elseif (in_array($key, ['show_theme_toggle', 'posts_load_more'], true)) {
                if (!is_bool($value)) throw new \InvalidArgumentException('Visibility options must be booleans.');
                $after[$key] = $value;
            } elseif (in_array($key, ['posts_per_page', 'footer_top_columns', 'footer_bottom_columns'], true)) {
                if (!is_int($value) || $value < 1 || $value > ($key === 'posts_per_page' ? 48 : 4)) throw new \InvalidArgumentException('Public layout count is outside its supported range.');
                $after[$key] = $value;
            } else {
                $maximum = match ($key) { 'name' => 200, 'tagline', 'footer_text', 'footer_bottom_text' => 500, 'footer_icon_links' => 4000, default => 120 };
                if (!is_string($value) || strlen($value) > $maximum || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new \InvalidArgumentException('Invalid public settings text.');
                $value = trim($value);
                if ($key === 'name' && $value === '') throw new \InvalidArgumentException('The site name cannot be empty.');
                if ($key === 'timezone' && !in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) throw new \InvalidArgumentException('Use a timezone supported by the server.');
                if ($key === 'locale' && !preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/D', $value)) throw new \InvalidArgumentException('Use a supported locale identifier.');
                if ($key === 'appearance_mode' && !in_array($value, ['light', 'dark', 'system'], true)) throw new \InvalidArgumentException('Use light, dark or system appearance.');
                if ($key === 'font_family' && !preg_match('/^[A-Za-z0-9 _-]{1,120}$/D', $value)) throw new \InvalidArgumentException('Use a plain font-family name, not CSS source.');
                if ($key === 'footer_icon_links') $this->validateFooterLinks($value);
                $after[$key] = $value;
            }
        }
        $current = $this->project($site);
        $before = [];
        foreach ($after as $key => $_value) $before[$key] = $current[$key] ?? null;
        return ['before' => $before, 'after' => $after, 'base_revision' => ContentRevision::for($site), 'capabilities' => $capabilities];
    }

    public function save(array $changes, string $expectedRevision, string $operationId): array
    {
        $prepared = $this->prepareSave($changes, $expectedRevision);
        $store = new WebsiteDocumentStore($this->paths);
        $current = $store->read('site');
        return $store->commit('site', array_replace($current, $prepared['after']), $prepared['base_revision'], $operationId);
    }

    private function project(array $site): array
    {
        $public = array_intersect_key($site, array_flip(array_merge(self::CORE_FIELDS, self::APPEARANCE_FIELDS)));
        foreach ($public as $key => $value) if (!in_array($key, ['palette_light', 'palette_dark'], true) && !is_scalar($value)) unset($public[$key]);
        foreach (['light', 'dark'] as $mode) $public['palette_' . $mode] = Appearance::palette($site, $mode);
        return $public;
    }

    private function capabilities(array $site): array
    {
        $manager = new ThemeManager($this->paths, new FileStore());
        $theme = $manager->activeSlug($site);
        $supports = [];
        $manifestValid = false;
        try { $supports = $manager->manifest($theme)['supports']; $manifestValid = true; } catch (RuntimeException $error) {}
        $default = $theme === 'default' && $manifestValid;
        return ['theme' => $theme, 'appearance_tokens' => $default || in_array('appearance_tokens', $supports, true), 'footer_layout' => $default || in_array('footer_layout', $supports, true), 'widgets' => $default || in_array('widgets', $supports, true), 'support_basis' => $default ? 'bundled_theme' : ($manifestValid ? 'theme_manifest_declaration' : 'unavailable'), 'custom_theme_verified' => false, 'executable_code_editing' => false];
    }

    private function color(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/iD', $value)) throw new \InvalidArgumentException('Colors must use six-digit hexadecimal notation.');
        return strtoupper($value);
    }

    private function validateFooterLinks(string $value): void
    {
        if ($value === '') return;
        $lines = explode("\n", $value);
        if (count($lines) > 12 || count(Appearance::footerLinks($value)) !== count($lines)) throw new \InvalidArgumentException('Supply up to 12 labelled internal or HTTPS footer links.');
        foreach (Appearance::footerLinks($value) as $link) {
            $parts = parse_url($link['url']);
            $decoded = rawurldecode($link['url']);
            if ($parts === false || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) throw new \InvalidArgumentException('Footer URLs cannot contain credentials or ambiguous encoded paths.');
        }
    }
}
