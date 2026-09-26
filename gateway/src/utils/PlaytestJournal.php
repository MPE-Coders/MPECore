<?php
declare(strict_types=1);
namespace mpe\utils;
/** Metadata-only diagnostics. Callers must never supply login packets, JWTs or keys. */
final class PlaytestJournal {
    private string $run;
    public function __construct(private string $path, private int $limit = 8388608) {$this->run=bin2hex(random_bytes(8));}
    public function append(int $sid, int $protocol, string $phase, string $event, string $detail): void {
        if (!preg_match('/^[a-z_]{1,40}$/', $event)) { throw new \InvalidArgumentException('Invalid journal event'); }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return; }
        clearstatcache(true, $this->path);
        if (is_file($this->path) && filesize($this->path) >= $this->limit) {
            @unlink($this->path . '.1'); @rename($this->path, $this->path . '.1');
        }
        $line = json_encode(['at' => gmdate('c'), 'run' => $this->run, 'sid' => $sid, 'protocol' => $protocol,
            'phase' => $phase, 'event' => $event, 'detail' => Binary::clean($detail, 512)],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($this->path, 0600);
    }
}
