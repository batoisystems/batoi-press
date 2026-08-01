<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use RuntimeException;

final class PublicationState
{
    public const STATUSES = ['draft', 'in_review', 'approved', 'scheduled', 'published', 'archived'];

    public static function normalize(mixed $status): string
    {
        $status = strtolower(trim((string)$status));
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }

    public static function label(string $status): string
    {
        return match (self::normalize($status)) {
            'in_review' => 'In review',
            default => ucfirst(self::normalize($status)),
        };
    }

    public static function normalizeDate(mixed $value, string $label): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException('Enter a valid ' . strtolower($label) . ' date and time.');
        }
        return date(DATE_ATOM, $timestamp);
    }

    public static function isPublic(array $record, ?int $now = null): bool
    {
        $now ??= time();
        $status = self::normalize($record['status'] ?? 'draft');
        if (!in_array($status, ['scheduled', 'published'], true)) {
            return false;
        }

        $publishAt = trim((string)($record['publish_at'] ?? $record['published_at'] ?? ''));
        if ($status === 'scheduled' && $publishAt === '') {
            return false;
        }
        if ($publishAt !== '') {
            $timestamp = strtotime($publishAt);
            if ($timestamp === false || $timestamp > $now) {
                return false;
            }
        }

        $unpublishAt = trim((string)($record['unpublish_at'] ?? ''));
        if ($unpublishAt !== '') {
            $timestamp = strtotime($unpublishAt);
            if ($timestamp !== false && $timestamp <= $now) {
                return false;
            }
        }
        return true;
    }

    public static function history(array $existing, string $status, string $reviewer, string $note, string $actor, string $now): array
    {
        $history = array_values(array_filter((array)($existing['workflow_history'] ?? []), 'is_array'));
        $previousStatus = self::normalize($existing['status'] ?? 'draft');
        $previousReviewer = trim((string)($existing['reviewer'] ?? ''));
        $note = trim($note);
        if ($previousStatus !== $status || $previousReviewer !== $reviewer || $note !== '') {
            $history[] = [
                'at' => $now,
                'actor' => substr(trim($actor), 0, 100),
                'from' => $existing === [] ? '' : $previousStatus,
                'to' => $status,
                'reviewer' => $reviewer,
                'note' => substr($note, 0, 500),
            ];
        }
        return array_slice($history, -100);
    }
}
