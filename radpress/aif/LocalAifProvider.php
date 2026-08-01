<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

final class LocalAifProvider implements AifProvider
{
    public function available(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'batoi-local';
    }

    public function assist(string $task, array $context = []): array
    {
        $title = trim((string)($context['title'] ?? ''));
        $text = trim((string)($context['body_text'] ?? ''));
        $html = (string)($context['body_html'] ?? '');
        if ($title === '' && $text === '') {
            return ['ok' => false, 'task' => $task, 'provider' => $this->name(), 'error' => 'Add a title or body before requesting assistance.'];
        }

        $suggestions = match ($task) {
            'seo_assist' => $this->seo($title, $text),
            'summarize' => ['summary' => $this->excerpt($text, 240), 'subtitle' => $this->excerpt($text, 180)],
            'tags' => ['tags' => $this->tags($title . ' ' . $text)],
            'draft_content' => ['outline' => $this->outline($title, $text)],
            'content_health' => $this->health($title, $text, $html, $context),
            default => [],
        };
        if ($suggestions === []) {
            return ['ok' => false, 'task' => $task, 'provider' => $this->name(), 'error' => 'This local Batoi AIF capability is not available.'];
        }
        return [
            'ok' => true,
            'task' => $task,
            'provider' => $this->name(),
            'suggestions' => $suggestions,
            'review_required' => true,
            'network_used' => false,
            'message' => 'Batoi AIF prepared a local suggestion. Review it before applying or publishing.',
        ];
    }

    private function seo(string $title, string $text): array
    {
        $seoTitle = $this->truncate($title, 60);
        $description = $this->excerpt($text, 155);
        if ($description === '') {
            $description = $this->truncate($title, 155);
        }
        return ['seo_title' => $seoTitle, 'seo_description' => $description];
    }

    private function health(string $title, string $text, string $html, array $context): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $checks = [];
        $checks[] = $this->check('title', strlen($title) >= 10 && strlen($title) <= 70, 'Use a clear title between 10 and 70 characters.');
        $checks[] = $this->check('body_length', count($words) >= 120, 'Add enough substantive body copy for the page purpose.');
        $checks[] = $this->check('headings', preg_match('/<h[2-4]\b/i', $html) === 1, 'Use descriptive section headings to improve scanning.');
        $checks[] = $this->check('seo_description', strlen(trim((string)($context['seo_description'] ?? ''))) >= 70, 'Add a useful search description, usually 70–160 characters.');
        $missingAlt = preg_match('/<img\b(?![^>]*\balt\s*=)[^>]*>/i', $html) === 1;
        $checks[] = $this->check('image_alt', !$missingAlt, 'Add alt attributes to meaningful images.');
        $passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
        return ['score' => (int)round(($passed / count($checks)) * 100), 'word_count' => count($words), 'checks' => $checks];
    }

    private function check(string $id, bool $passed, string $guidance): array
    {
        return ['id' => $id, 'passed' => $passed, 'guidance' => $guidance];
    }

    private function tags(string $text): array
    {
        $stop = array_fill_keys(['about', 'after', 'also', 'and', 'are', 'been', 'being', 'batoi', 'but', 'can', 'for', 'from', 'have', 'into', 'more', 'our', 'that', 'the', 'their', 'this', 'through', 'with', 'your'], true);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $this->lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($words as $word) {
            if (strlen($word) < 4 || isset($stop[$word])) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    private function outline(string $title, string $text): array
    {
        $topic = $title !== '' ? $title : $this->excerpt($text, 80);
        return [
            ['heading' => 'Purpose', 'guidance' => 'Explain what ' . $topic . ' helps the reader accomplish.'],
            ['heading' => 'Key details', 'guidance' => 'Present the essential facts, evidence, or benefits in a scannable order.'],
            ['heading' => 'Next step', 'guidance' => 'Close with a clear action or related resource.'],
        ];
    }

    private function excerpt(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        return $this->truncate((string)($sentences[0] ?? $text), $limit);
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }
        $cut = substr($value, 0, max(1, $limit - 1));
        $space = strrpos($cut, ' ');
        return rtrim($space === false ? $cut : substr($cut, 0, $space), " \t\n\r\0\x0B,;:-") . '…';
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
