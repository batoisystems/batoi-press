<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class HtmlContent
{
    private const ALLOWED_TAGS = '<main><header><footer><nav><section><article><aside><p><div><span><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><b><em><i><del><mark><small><sub><sup><abbr><a><blockquote><code><pre><img><iframe><figure><figcaption><hr><table><thead><tbody><tfoot><tr><th><td><form><fieldset><legend><label><input><button><select><option><textarea>';

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
}
