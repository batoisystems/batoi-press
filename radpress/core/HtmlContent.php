<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class HtmlContent
{
    private const ALLOWED_TAGS = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><b><em><i><a><blockquote><code><pre><img><figure><figcaption><hr><table><thead><tbody><tr><th><td>';

    public function sanitize(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '', $html) ?? '';

        return trim($html);
    }
}

