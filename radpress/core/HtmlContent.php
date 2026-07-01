<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class HtmlContent
{
    private const ALLOWED_TAGS = '<p><div><span><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><b><em><i><del><mark><small><sub><sup><abbr><a><blockquote><code><pre><img><figure><figcaption><hr><table><thead><tbody><tr><th><td><form><label><input><button><select><option><textarea>';

    public function sanitize(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src|action|formaction)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '', $html) ?? '';

        return trim($html);
    }
}
