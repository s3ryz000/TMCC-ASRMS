<?php

namespace App\Services\Backup;

/** The outcome of one backup run (#62). */
final class BackupResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $label,
        public readonly ?string $file,
        public readonly ?int $bytes,
        public readonly ?array $manifest,
        public readonly float $seconds,
        public readonly string $message,
        public readonly array $promoted = [],
        public readonly array $pruned = [],
    ) {
    }

    public static function success(string $label, string $file, int $bytes, array $manifest, float $seconds, array $promoted, array $pruned): self
    {
        return new self(true, $label, $file, $bytes, $manifest, $seconds, 'ok', $promoted, $pruned);
    }

    public static function failure(string $label, string $reason, float $seconds): self
    {
        return new self(false, $label, null, null, null, $seconds, $reason);
    }

    /** "daily asrms-20261006-1800.zip 1.2 MB in 0.8 s" or "daily: <reason>" */
    public function summary(): string
    {
        if (! $this->ok) {
            return "{$this->label}: {$this->message}";
        }

        $size = $this->bytes >= 1048576 ? round($this->bytes / 1048576, 1) . ' MB' : round($this->bytes / 1024, 1) . ' KB';
        $counts = collect($this->manifest['counts'] ?? [])->map(fn ($n, $t) => "{$t}={$n}")->join(' ');

        return trim(sprintf('%s %s %s in %.1f s %s', $this->label, basename((string) $this->file), $size, $this->seconds, $counts));
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'label' => $this->label,
            'file' => $this->file ? basename($this->file) : null,
            'folder' => $this->file ? basename(dirname($this->file)) : null,
            'bytes' => $this->bytes,
            'seconds' => round($this->seconds, 2),
            'message' => $this->message,
            'counts' => $this->manifest['counts'] ?? null,
        ];
    }
}
