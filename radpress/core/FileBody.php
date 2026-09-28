<?php
declare(strict_types=1);
namespace Batoi\Press\Core;

/** Owns the opened representation across response middleware clones. */
final class FileBody
{
    public function __construct(private $stream, private readonly int $offset, private readonly int $length) {}

    public function send(): void
    {
        if (fseek($this->stream, $this->offset) !== 0) return;
        $remaining = $this->length;
        while ($remaining > 0 && !feof($this->stream)) {
            $chunk = fread($this->stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }

    public function __destruct() { if (is_resource($this->stream)) fclose($this->stream); }
}
