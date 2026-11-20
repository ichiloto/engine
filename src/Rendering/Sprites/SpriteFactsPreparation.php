<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Composer\Autoload\ClassLoader;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use RuntimeException;

/** An owned PHP child that warms the existing generated sprite facts store. */
final class SpriteFactsPreparation
{
  public const int MAX_REQUEST_BYTES = 512 * 1024;
  private const int TIMEOUT_NS = 35_000_000_000;

  /**
   * @param resource|null $process
   * @param resource|null $diagnostics
   */
  private function __construct(private $process, private $diagnostics, private readonly int $deadline) {}

  public function __destruct() { $this->cancel(); }

  /** @param list<CanvasImage> $images */
  public static function start(string $assetRoot, array $images): self
  {
    $cache = PngAssetBounds::getFactsCache() ?? throw new RuntimeException('The generated sprite facts store is unavailable.');
    $request = json_encode(['assetRoot' => $assetRoot, 'cacheDirectory' => $cache->directory,
      'images' => array_map(static fn(CanvasImage $image): array => $image->toArray(), $images)], JSON_THROW_ON_ERROR);
    if (strlen($request) > self::MAX_REQUEST_BYTES) {
      throw new RuntimeException('Sprite facts preparation exceeded its request limit.');
    }
    $autoload = self::getComposerAutoload();
    $input = tmpfile();
    $diagnostics = tmpfile();
    try {
      if ($input === false || $diagnostics === false
        || fwrite($input, $request) !== strlen($request) || !rewind($input)) {
        throw new RuntimeException('Could not allocate sprite facts preparation streams.');
      }
      $process = proc_open([PHP_BINARY, '-d', 'memory_limit=384M', '-d', 'display_errors=stderr',
        '-d', 'log_errors=0', '-d', 'max_execution_time=35', dirname(__DIR__, 3) . '/scripts/prepare-sprite-facts.php', $autoload],
        [0 => $input, 1 => $diagnostics, 2 => $diagnostics], $pipes);
      if (!is_resource($process)) { throw new RuntimeException('Could not start sprite facts preparation.'); }
      return new self($process, $diagnostics, hrtime(true) + self::TIMEOUT_NS);
    } catch (\Throwable $failure) {
      if (is_resource($diagnostics)) { fclose($diagnostics); }
      throw $failure;
    } finally {
      if (is_resource($input)) { fclose($input); }
    }
  }

  private static function getComposerAutoload(): string
  {
    foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
      $source = $loader->findFile(PngAssetBounds::class);
      if (is_string($source) && realpath($source) === realpath(__DIR__ . '/PngAssetBounds.php')) {
        return $directory . '/autoload.php';
      }
    }
    throw new RuntimeException('Could not locate the Engine Composer loader for sprite facts preparation.');
  }

  /** Return promptly while the child runs. A completed child is reaped before its result is reported. */
  public function poll(): bool
  {
    $status = proc_get_status($this->process);
    if ($status['running']) {
      if (hrtime(true) < $this->deadline) { return false; }
      $this->cancel();
      throw new RuntimeException('Sprite facts preparation timed out.');
    }
    proc_close($this->process);
    $this->process = null;
    rewind($this->diagnostics);
    $message = trim((string) fread($this->diagnostics, 4096));
    fclose($this->diagnostics);
    $this->diagnostics = null;
    if ($status['exitcode'] !== 0) {
      throw new RuntimeException('Sprite facts preparation failed' . ($message === '' ? '.' : ': ' . $message));
    }
    return true;
  }

  public function cancel(): void
  {
    if (!is_resource($this->process)) { return; }
    $status = proc_get_status($this->process);
    if ($status['running']) { proc_terminate($this->process, 9); }
    proc_close($this->process);
    fclose($this->diagnostics);
    $this->process = $this->diagnostics = null;
  }
}
