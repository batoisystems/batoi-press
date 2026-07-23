<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class HtmlContent
{
    private const ALLOWED_TAGS = '<main><header><footer><nav><section><article><aside><p><div><span><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><b><em><i><del><mark><small><sub><sup><abbr><a><blockquote><code><pre><img><iframe><figure><figcaption><hr><table><thead><tbody><tfoot><tr><th><td><form><fieldset><legend><label><input><button><select><option><textarea>';
    private const TEXT_EDIT_BLOCKED_TAGS = [
        'button',
        'code',
        'fieldset',
        'form',
        'iframe',
        'input',
        'math',
        'option',
        'pre',
        'script',
        'select',
        'style',
        'svg',
        'table',
        'tbody',
        'td',
        'textarea',
        'tfoot',
        'th',
        'thead',
        'tr',
    ];
    private const VOID_TAGS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    public function sanitize(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace_callback('/\s+style\s*=\s*([\'"])(.*?)\1/is', static function (array $match): string {
            $css = preg_replace('/(?:expression\s*\(|javascript\s*:|@import|behavior\s*:|-moz-binding\s*:)/i', '', $match[2]) ?? '';
            return $css === '' ? '' : ' style="' . htmlspecialchars($css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }, $html) ?? '';
        $html = preg_replace('/\s+(href|src|action|formaction)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '', $html) ?? '';
        $html = preg_replace_callback('#<iframe\b([^>]*)>#i', static function (array $match): string {
            if (preg_match('/\bsrc\s*=\s*([\'"])(https?:\/\/[^\'"]+)\1/i', $match[1], $src) !== 1) {
                return '';
            }
            return '<iframe src="' . htmlspecialchars($src[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin">';
        }, $html) ?? '';

        return trim($html);
    }

    public function hasComplexStructure(string $html): bool
    {
        return preg_match('/<(?:main|header|footer|nav|section|article|aside|div|figure|table|form|iframe)\b/i', $html) === 1;
    }

    /**
     * Return editable visible-text segments without exposing layout markup,
     * tables, forms, code samples, or embedded content to the visual editor.
     *
     * @return array<int, array{id: string, tag: string, value: string}>
     */
    public function editableTextSegments(string $html): array
    {
        $segments = [];
        $stack = [];
        $index = 0;

        foreach ($this->tokens($html) as $token) {
            if ($token['type'] === 'tag') {
                $this->updateTagStack($stack, $token['value']);
                continue;
            }
            if (!$this->isEditableText($token['value'], $stack)) {
                continue;
            }

            [, $content] = $this->textParts($token['value']);
            $segments[] = [
                'id' => (string)$index,
                'tag' => (string)(end($stack) ?: 'text'),
                'value' => html_entity_decode($content, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            ];
            $index++;
        }

        return $segments;
    }

    /**
     * Replace only the editable text tokens and preserve every original tag,
     * attribute, table, form, code sample, and whitespace boundary byte-for-byte.
     *
     * @param array<string|int, mixed> $replacements
     */
    public function replaceEditableText(string $html, array $replacements): string
    {
        $tokens = $this->tokens($html);
        $stack = [];
        $index = 0;

        foreach ($tokens as &$token) {
            if ($token['type'] === 'tag') {
                $this->updateTagStack($stack, $token['value']);
                continue;
            }
            if (!$this->isEditableText($token['value'], $stack)) {
                continue;
            }

            [$leading, $content, $trailing] = $this->textParts($token['value']);
            $key = (string)$index;
            if (array_key_exists($key, $replacements) || array_key_exists($index, $replacements)) {
                $replacement = (string)($replacements[$key] ?? $replacements[$index]);
                $current = html_entity_decode($content, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                if ($replacement !== $current) {
                    $token['value'] = $leading
                        . htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
                        . $trailing;
                }
            }
            $index++;
        }
        unset($token);

        return implode('', array_column($tokens, 'value'));
    }

    /**
     * @return array<int, array{type: 'tag'|'text', value: string}>
     */
    private function tokens(string $html): array
    {
        $tokens = [];
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $next = strpos($html, '<', $offset);
                $next = $next === false ? $length : $next;
                $tokens[] = ['type' => 'text', 'value' => substr($html, $offset, $next - $offset)];
                $offset = $next;
                continue;
            }

            $quote = null;
            $end = $offset + 1;
            while ($end < $length) {
                $character = $html[$end];
                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '>') {
                    $end++;
                    break;
                }
                $end++;
            }
            if ($end > $length || $html[$end - 1] !== '>') {
                $tokens[] = ['type' => 'text', 'value' => substr($html, $offset)];
                break;
            }

            $tokens[] = ['type' => 'tag', 'value' => substr($html, $offset, $end - $offset)];
            $offset = $end;
        }

        return $tokens;
    }

    /**
     * @param array<int, string> $stack
     */
    private function updateTagStack(array &$stack, string $tag): void
    {
        if (str_starts_with($tag, '<!--') || str_starts_with($tag, '<!') || str_starts_with($tag, '<?')) {
            return;
        }
        if (preg_match('/^<\s*\/\s*([a-z0-9:-]+)/i', $tag, $closing) === 1) {
            $name = strtolower($closing[1]);
            for ($index = count($stack) - 1; $index >= 0; $index--) {
                if ($stack[$index] === $name) {
                    $stack = array_slice($stack, 0, $index);
                    break;
                }
            }
            return;
        }
        if (preg_match('/^<\s*([a-z0-9:-]+)/i', $tag, $opening) !== 1) {
            return;
        }

        $name = strtolower($opening[1]);
        if (str_ends_with(rtrim($tag), '/>') || in_array($name, self::VOID_TAGS, true)) {
            return;
        }
        $stack[] = $name;
    }

    /**
     * @param array<int, string> $stack
     */
    private function isEditableText(string $text, array $stack): bool
    {
        if (array_intersect($stack, self::TEXT_EDIT_BLOCKED_TAGS) !== []) {
            return false;
        }
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        return preg_match('/[^\s\x{00a0}]/u', $decoded) === 1;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function textParts(string $text): array
    {
        if (preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $parts) !== 1) {
            return ['', $text, ''];
        }

        return [$parts[1], $parts[2], $parts[3]];
    }
}
