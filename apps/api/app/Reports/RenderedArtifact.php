<?php

namespace App\Reports;

final class RenderedArtifact
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
        public readonly string $extension,
    ) {}

    public static function forFormat(string $bytes, string $format): self
    {
        return match ($format) {
            'pdf' => new self($bytes, 'application/pdf', 'pdf'),
            'csv' => new self($bytes, 'text/csv; charset=UTF-8', 'csv'),
            'xlsx' => new self($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'),
            default => new self($bytes, 'application/octet-stream', 'bin'),
        };
    }
}
