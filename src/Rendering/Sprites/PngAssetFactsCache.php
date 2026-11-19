<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Closure;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\LocalDataFiles;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Disposable exact pixel facts. Authored identities, pivots and physical occupancy never enter this cache. */
final class PngAssetFactsCache
{
  private const int SCHEMA = 1;
  private const int MAX_RECORD_BYTES = 128 * 1024;
  private const int MAX_ENTRIES = 2048;
  private const int MAX_BYTES = 64 * 1024 * 1024;
  // Bound temporary growth during a batch to at most 16 records (2 MiB).
  private const int WRITES_PER_TRIM = 16;
  private ?int $batchWrites = null;
  /** @var array<string, true> */
  private array $reported = [];

  public function __construct(public readonly string $directory)
  {
    $drive = strlen($directory) >= 3 && preg_match('/^[A-Za-z]$/D', $directory[0]) === 1
      && $directory[1] === ':' && ($directory[2] === '/' || $directory[2] === chr(92));
    if ($directory === '' || str_contains($directory, "\0") || trim($directory, '/\\') === ''
      || (!str_starts_with($directory, '/') && !(PHP_OS_FAMILY === 'Windows' && $drive))) {
      throw new InvalidArgumentException('Generated asset facts require a dedicated absolute cache directory.');
    }
  }

  /** A host may supply its own directory; defaults never write assets, player settings or saves. */
  public static function createForUser(): self
  {
    $home = getenv('HOME');
    $base = match (PHP_OS_FAMILY) {
      'Windows' => getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '/Ichiloto/Cache' : null,
      'Darwin' => $home ? $home . '/Library/Caches/Ichiloto' : null,
      default => ($base = self::getAbsoluteXdgDirectory() ?? ($home ? $home . '/.cache' : null)) === null
        ? null : $base . '/ichiloto',
    };
    if ($base === null) { throw new RuntimeException('The host must provide a generated asset facts cache directory.'); }
    return new self($base . '/asset-facts/v1');
  }

  private static function getAbsoluteXdgDirectory(): ?string
  {
    $directory = getenv('XDG_CACHE_HOME');
    return is_string($directory) && str_starts_with($directory, '/') ? $directory : null;
  }

  /**
   * Amortize directory maintenance over consecutive crop writes. The outermost
   * batch trims remaining writes on exit, even if the caller throws.
   *
   * @template T
   * @param Closure(): T $work
   * @return T
   */
  public function runBatch(Closure $work): mixed
  {
    if ($this->batchWrites !== null) { return $work(); }

    $this->batchWrites = 0;
    try {
      return $work();
    } finally {
      try {
        if ($this->batchWrites > 0) { $this->trimOwnedRecords(); }
      } catch (RuntimeException $failure) {
        $this->report('batch-maintenance', $failure);
      } finally {
        $this->batchWrites = null;
      }
    }
  }

  /** @return array{version: string, pixels: ?array, edge: array<int, int>}|null */
  public function find(string $path, string $revision, ?SpriteSourceRect $crop, int $width, int $height): ?array
  {
    $key = $this->getKey($path, $crop);
    $file = $this->directory . '/' . $key . '.json';
    try {
      if (!LocalDataFiles::runFileOperation(static fn(): bool => is_file($file))) { return null; }
      $this->validateFilePath($file);
      $bytes = LocalDataFiles::runFileOperation(static fn() => filesize($file));
      if ($bytes === false || $bytes > self::MAX_RECORD_BYTES) { throw new RuntimeException('Generated facts record exceeds its byte bound.'); }
      $raw = LocalDataFiles::runFileOperation(static fn() => file_get_contents($file, false, null, 0, self::MAX_RECORD_BYTES + 1));
      if (!is_string($raw) || strlen($raw) > self::MAX_RECORD_BYTES) { throw new RuntimeException('Generated facts record cannot be read within its bound.'); }
      $record = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
      if (!is_array($record) || ($record['schema'] ?? null) !== self::SCHEMA || ($record['key'] ?? null) !== $key
        || ($record['revision'] ?? null) !== $revision || ($record['size'] ?? null) !== [$width, $height]) { return null; }
      $pixels = $record['pixels'] ?? null;
      $edge = $record['edge'] ?? null;
      if (!array_key_exists('pixels', $record) || !is_array($edge) || !array_is_list($edge)
        || count($edge) > $width || !$this->isValidBounds($pixels, $width, $height)) {
        throw new RuntimeException('Generated facts record has invalid bounds or lower-edge data.');
      }
      $columns = [];
      $previous = -1;
      foreach ($edge as $pair) {
        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_int($pair[1])
          || $pixels === null || $pair[0] <= $previous || $pair[0] < $pixels[0] || $pair[0] >= $pixels[0] + $pixels[2]
          || $pair[1] < $pixels[1] || $pair[1] >= $pixels[1] + $pixels[3]) {
          throw new RuntimeException('Generated facts record has invalid lower-edge coordinates.');
        }
        $columns[$pair[0]] = $pair[1]; $previous = $pair[0];
      }
      return ['version' => $revision, 'pixels' => $pixels, 'edge' => $columns];
    } catch (RuntimeException|JsonException $failure) {
      $this->report($key . ':' . $revision, $failure);
      return null;
    }
  }

  /** @param array{version: string, pixels: ?array, edge: array<int, int>} $facts */
  public function store(string $path, string $revision, ?SpriteSourceRect $crop, int $width, int $height, array $facts): void
  {
    $key = $this->getKey($path, $crop);
    $file = $this->directory . '/' . $key . '.json';
    try {
      $this->validateDirectoryPath();
      $pairs = [];
      foreach ($facts['edge'] as $column => $row) { $pairs[] = [$column, $row]; }
      $raw = json_encode(['schema' => self::SCHEMA, 'key' => $key, 'revision' => $revision,
        'size' => [$width, $height], 'pixels' => $facts['pixels'], 'edge' => $pairs], JSON_THROW_ON_ERROR);
      if (strlen($raw) > self::MAX_RECORD_BYTES) { throw new RuntimeException('Generated facts exceed the cache record byte bound.'); }
      LocalDataFiles::ensureDirectoryExists($this->directory);
      $this->validateFilePath($file);
      LocalDataFiles::writeAtomically($file, $raw);
      if ($this->batchWrites === null) {
        $this->trimOwnedRecords();
      } elseif (++$this->batchWrites >= self::WRITES_PER_TRIM) {
        $this->trimOwnedRecords();
        $this->batchWrites = 0;
      }
    } catch (RuntimeException|JsonException $failure) { $this->report($key . ':' . $revision, $failure); }
  }

  private function getKey(string $path, ?SpriteSourceRect $crop): string
  {
    return hash('sha256', self::SCHEMA . "\0" . $path . "\0"
      . ($crop === null ? 'whole' : implode(':', [$crop->x, $crop->y, $crop->width, $crop->height])));
  }

  private function isValidBounds(mixed $pixels, int $width, int $height): bool
  {
    return $pixels === null || (is_array($pixels) && array_is_list($pixels) && count($pixels) === 4
      && array_all($pixels, static fn(mixed $pixel): bool => is_int($pixel)) && $pixels[0] >= 0 && $pixels[1] >= 0 && $pixels[2] > 0 && $pixels[3] > 0
      && $pixels[0] + $pixels[2] <= $width && $pixels[1] + $pixels[3] <= $height);
  }

  /** Refuse symlinked cache descendants rather than redirecting generated writes. */
  private function validateDirectoryPath(): void
  {
    $path = rtrim($this->directory, '/\\');
    while ($path !== '' && dirname($path) !== $path) {
      if (is_link($path)) { throw new RuntimeException('Generated facts cache cannot follow a symlink.'); }
      $path = dirname($path);
    }
  }

  private function validateFilePath(string $file): void
  {
    $this->validateDirectoryPath();
    if (is_link($file)) { throw new RuntimeException('Generated facts record cannot follow a symlink.'); }
  }

  /** Only this schema's own regular records are disposable; no unrelated files or directories are removed. */
  private function trimOwnedRecords(): void
  {
    $files = []; $bytes = 0;
    foreach (new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS) as $file) {
      if ($file->isLink() || !$file->isFile() || !preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename())) { continue; }
      $files[] = ['path' => $file->getPathname(), 'bytes' => $file->getSize(), 'time' => $file->getMTime()];
      $bytes += $file->getSize();
    }
    usort($files, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
    $remaining = count($files);
    foreach ($files as $file) {
      if ($remaining <= self::MAX_ENTRIES && $bytes <= self::MAX_BYTES) { break; }
      if (LocalDataFiles::runFileOperation(static fn(): bool => unlink($file['path']))) { $remaining--; $bytes -= $file['bytes']; }
    }
  }

  private function report(string $key, RuntimeException|JsonException $failure): void
  {
    if (isset($this->reported[$key])) { return; }
    if (count($this->reported) >= self::MAX_ENTRIES) { array_shift($this->reported); }
    $this->reported[$key] = true;
    Debug::warn('Generated PNG facts cache unavailable; using current exact pixel facts: ' . $failure->getMessage());
  }
}
