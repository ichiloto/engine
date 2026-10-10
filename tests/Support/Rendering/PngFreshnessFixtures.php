<?php

namespace Tests\Support\Rendering;

/** Valid synthetic RGBA PNGs with equal encoded lengths, independent of image dimensions. */
function createPaddedPngBytes(int $width, int $height, int $bytes = 512): string
{
  $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
    . pack('N', crc32($type . $data));
  $png = "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x70\x90\xB0\xFF", $width), $height)));
  $padding = $bytes - strlen($png) - 24 - strlen("padding\0");
  if ($padding < 0) { throw new \RuntimeException('Synthetic PNG needs a larger padding budget.'); }
  return $png . $chunk('tEXt', "padding\0" . str_repeat('x', $padding)) . $chunk('IEND', '');
}

/** Real filesystem collisions, with bounded retries only when a pair crosses a second boundary. */
function replacePngWithCollidingFileFacts(string $path, string $first, string $next, callable $prime): array
{
  if (strlen($first) !== strlen($next)) { throw new \RuntimeException('Collision fixtures must have equal encoded lengths.'); }
  $facts = static function () use ($path): array {
    clearstatcache(true, $path);
    $stat = stat($path);
    return array_intersect_key($stat, array_flip(['ino', 'size', 'mtime', 'ctime', 'mode']));
  };
  for ($attempt = 0; $attempt < 8; $attempt++) {
    file_put_contents($path, $first);
    touch($path, 1700000000);
    $primed = $prime();
    $before = $facts();
    file_put_contents($path, $next);
    touch($path, 1700000000);
    $after = $facts();
    if ($before === $after) { return ['before' => $before, 'after' => $after, 'primed' => $primed]; }
  }
  throw new \RuntimeException('Could not establish a real stat collision in eight bounded attempts.');
}
