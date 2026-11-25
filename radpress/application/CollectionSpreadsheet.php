<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\{CollectionRepository, CollectionSchema};
use Batoi\Press\Core\RuntimeCapabilities;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\{ErrorCell, StringCell};
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\{Options, Reader};
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

final class CollectionSpreadsheet
{
    public const MAX_UPLOAD = 2000000;
    private const MAX_COLUMNS = 64;
    private const MAX_WORKBOOK_BYTES = 12000000;

    public static function sheets(string $bytes): array
    {
        return self::workbook($bytes, static function (Reader $reader): array {
            $names = [];
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                if ($sheet->isVisible()) $names[$index] = CollectionSchema::text($sheet->getName(), 120);
            }
            if ($names === []) throw new InvalidArgumentException('The workbook has no visible worksheets.');
            return $names;
        });
    }

    public static function read(string $bytes, string $format, int $sheet = 1): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD) throw new InvalidArgumentException('Provide a file or pasted text up to 2 MB.');
        if ($format === 'xlsx') {
            return self::workbook($bytes, static function (Reader $reader) use ($sheet): array {
                foreach ($reader->getSheetIterator() as $index => $candidate) {
                    if ($index !== $sheet || !$candidate->isVisible()) continue;
                    return self::table((static function () use ($candidate): iterable {
                        foreach ($candidate->getRowIterator() as $line => $row) {
                            yield $line => array_map(static function ($cell): mixed {
                                if ($cell instanceof ErrorCell || $cell->getValue() instanceof \DateInterval) return false;
                                // Formula cells expose the expression as literal text. Never use their computed values.
                                $value = $cell->getValue();
                                if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
                                return $value === true ? 'TRUE' : ($value === false ? 'FALSE' : $value);
                            }, $row->getCells());
                        }
                    })());
                }
                throw new InvalidArgumentException('Select a visible worksheet.');
            });
        }
        if (str_contains($bytes, "\0") || preg_match('//u', $bytes) !== 1) throw new InvalidArgumentException('Save the text as UTF-8 before importing.');
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to prepare CSV import.');
        try {
            if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
            fwrite($stream, $bytes);
            rewind($stream);
            return self::table((static function () use ($stream, $format): iterable {
                $delimiter = match ($format) { 'tsv' => "\t", 'semicolon' => ';', default => ',' };
                $index = 0;
                while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) yield ++$index => $row;
            })());
        } finally {
            fclose($stream);
        }
    }

    public static function export(array $collection, string $format): string
    {
        $headers = ['_record_id', ...array_keys($collection['fields'])];
        $rows = (static function () use ($headers, $collection): iterable {
            yield $headers;
            foreach ($collection['records'] as $id => $record) {
                if (!$record['archived']) yield [$id, ...array_values($record['values'])];
            }
        })();
        if ($format === 'xlsx') {
            self::requireXlsx();
            return self::temporary(static function (string $path) use ($rows): string {
                $writer = new Writer();
                $writer->openToFile($path);
                try {
                    foreach ($rows as $row) $writer->addRow(new Row(array_map(static fn ($value): StringCell => new StringCell((string)$value, null), $row)));
                } finally {
                    $writer->close();
                }
                $bytes = file_get_contents($path);
                if ($bytes === false) throw new RuntimeException('Unable to read Excel export.');
                return $bytes;
            });
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to prepare CSV export.');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $types = ['text', ...array_column($collection['fields'], 'type')];
            foreach ($rows as $row) {
                $safe = array_map(static function ($value, int $index) use ($types): string {
                    $text = (string)$value;
                    if ($types[$index] === 'number') return $text;
                    return preg_match('/^[\s\x00-\x20]*[=+@＝＋＠－-]/u', $text) ? "'" . $text : $text;
                }, $row, array_keys($row));
                fputcsv($stream, $safe, ',', '"', '');
            }
            rewind($stream);
            $bytes = stream_get_contents($stream);
            if ($bytes === false) throw new RuntimeException('Unable to read CSV export.');
            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private static function table(iterable $source): array
    {
        $headers = null;
        $rows = [];
        $bytes = 0;
        foreach ($source as $line => $values) {
            if ($line > CollectionRepository::MAX_RECORDS + 1) throw new InvalidArgumentException('The spreadsheet exceeds the 2,000-row import limit. Remove empty trailing rows or split the file.');
            if (count($values) > self::MAX_COLUMNS) throw new InvalidArgumentException('The spreadsheet exceeds the 64-column import limit.');
            if (count(array_filter($values, static fn ($value): bool => $value !== null && (!is_string($value) || trim($value) !== ''))) === 0) continue;
            foreach ($values as $value) $bytes += strlen((string)$value) + 8;
            if ($bytes > self::MAX_WORKBOOK_BYTES / 3) throw new InvalidArgumentException('The sheet contains too much text for one import.');
            if ($headers === null) {
                $headers = [];
                foreach ($values as $index => $value) {
                    if ($value === false) throw new InvalidArgumentException('Header cells must contain plain values.');
                    $headers[$index] = CollectionSchema::text((string)$value, 200) ?: 'Column ' . ($index + 1);
                }
                continue;
            }
            if (array_filter(array_slice($values, count($headers)), static fn ($value): bool => $value !== null && $value !== '') !== []) {
                throw new InvalidArgumentException('Row ' . $line . ' has data beyond the header columns. Check your header row or delimiter.');
            }
            $rows[$line] = array_slice($values, 0, count($headers));
        }
        if ($headers === null || $rows === []) throw new InvalidArgumentException('The sheet needs a header row and at least one data row.');
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function workbook(string $bytes, callable $read): array
    {
        self::requireXlsx();
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD) throw new InvalidArgumentException('Provide an XLSX file up to 2 MB.');
        return self::temporary(static function (string $path) use ($bytes, $read): array {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('Unable to prepare Excel import.');
            self::validateArchive($path);
            $options = new Options();
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new Reader($options);
            try {
                $reader->open($path);
                try { return $read($reader); }
                finally { $reader->close(); }
            } catch (InvalidArgumentException $error) {
                throw $error;
            } catch (\Throwable) {
                throw new InvalidArgumentException('The workbook could not be read. Save a fresh .xlsx copy and try again.');
            }
        });
    }

    private static function validateArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('Upload a valid .xlsx workbook.');
        try {
            if ($zip->numFiles > 256 || $zip->locateName('xl/workbook.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                throw new InvalidArgumentException('The file is malformed or has too many workbook parts.');
            }
            $total = 0;
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $part = $zip->statIndex($index);
                if ($part === false) throw new InvalidArgumentException('The workbook contains an unreadable part.');
                $name = $part['name'];
                $total += $part['size'];
                if ($total > self::MAX_WORKBOOK_BYTES || isset($seen[$name]) || $part['encryption_method'] !== ZipArchive::EM_NONE
                    || preg_match('#(^/|\\\\|(?:^|/)\.\.?(/|$)|^xl/externalLinks/|vbaProject\.bin$)#i', $name)) {
                    throw new InvalidArgumentException('The workbook exceeds its expanded size limit or contains unsupported parts.');
                }
                $seen[$name] = true;
                if (!preg_match('/\.(xml|rels)$/iD', $name)) continue;
                $xml = $zip->getFromIndex($index, $part['size'] + 1);
                if ($xml === false || strlen($xml) !== $part['size'] || str_contains($xml, "\0") || preg_match('/<!DOCTYPE/i', $xml)) {
                    throw new InvalidArgumentException('The workbook contains unsupported XML.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function temporary(callable $use): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'bp-collection-');
        if ($path === false) throw new RuntimeException('Unable to allocate temporary spreadsheet storage.');
        try { return $use($path); }
        finally { @unlink($path); }
    }

    private static function requireXlsx(): void
    {
        if (!RuntimeCapabilities::available('collection_xlsx') || !class_exists(Reader::class)) {
            throw new InvalidArgumentException('Excel is unavailable on this server. Use CSV or enable XLSX support in Hosting Health.');
        }
    }
}
