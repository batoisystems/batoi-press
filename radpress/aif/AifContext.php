<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

final class AifContext
{
    private const LIMITS = [
        'content_type' => 20,
        'title' => 200,
        'body' => 50000,
        'seo_title' => 200,
        'seo_description' => 500,
        'subtitle' => 300,
        'category' => 120,
        'tags' => 1000,
        'featured_image' => 2048,
        'featured_image_alt' => 300,
    ];

    public static function prepare(array $context): array
    {
        $prepared = [];
        foreach (self::LIMITS as $field => $limit) {
            $value = $context[$field] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', array_slice($value, 0, 30)));
            }
            $value = trim((string)$value);
            if ($field === 'body') {
                $value = preg_replace('#<(script|style|template|iframe)[^>]*>.*?</\1>#is', ' ', $value) ?? $value;
                $prepared['body_html'] = substr($value, 0, $limit);
                $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
                $prepared['body_text'] = trim(substr($value, 0, $limit));
                continue;
            }
            $prepared[$field] = substr($value, 0, $limit);
        }
        return $prepared;
    }

    public static function safeMetadata(array $context): array
    {
        $metadata = ['fields' => [], 'bytes' => 0];
        foreach ($context as $field => $value) {
            if (!is_scalar($value) || (string)$value === '') {
                continue;
            }
            $metadata['fields'][] = (string)$field;
            $metadata['bytes'] += strlen((string)$value);
        }
        sort($metadata['fields'], SORT_STRING);
        return $metadata;
    }
}
