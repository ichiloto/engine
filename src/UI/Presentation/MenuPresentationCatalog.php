<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;
use RuntimeException;

/** Optional project data. Engine owns composition; projects supply no drawing callbacks. */
final readonly class MenuPresentationCatalog
{
  public const string FILE = 'Data/Presentation/menus.php';
  public const array CAPABILITIES = [RendererSessionConfig::GRAPHICAL_CANVAS,
    RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::CANVAS_CLIP_OPACITY];
  private const array DEFAULT_COLORS = [
    'text' => [232, 235, 239], 'selected' => [46, 59, 72], 'accent' => [153, 193, 213],
    'focus' => [220, 232, 240], 'disabled' => [126, 137, 147], 'edge' => [162, 172, 182],
    'background' => [13, 18, 24], 'panel' => [24, 31, 40],
    'increase' => [118, 213, 159], 'decrease' => [237, 142, 142],
  ];

  public MenuRowSkin $rows;
  public MenuThemeMetrics $metrics;
  public ?MenuIconRegistry $icons;
  /** @var array<string, PresentationColor> */
  public array $colors;
  /** @var array<string, MenuRowArtwork> */
  public array $frames;
  /** @var array<string, string> Stable actor IDs to relative PNG paths. */
  public array $portraits;
  public bool $showInputHints;

  public function __construct(public string $assetRoot, array $data)
  {
    self::keys($data, ['schema', 'colors', 'metrics', 'rowMetrics', 'rowArtwork', 'frames', 'icons', 'cursor', 'portraits', 'showInputHints']);
    if (($data['schema'] ?? null) !== 'ichiloto.menu/1') {
      throw new InvalidArgumentException('Menu catalog requires schema ichiloto.menu/1.');
    }
    $showInputHints = $data['showInputHints'] ?? true;
    if (!is_bool($showInputHints)) { throw new InvalidArgumentException('Menu showInputHints must be a boolean.'); }
    $this->showInputHints = $showInputHints;
    $colors = self::map($data, 'colors');
    self::keys($colors, array_keys(self::DEFAULT_COLORS));
    $palette = [];
    foreach (array_replace(self::DEFAULT_COLORS, $colors) as $role => $rgb) {
      if (!is_array($rgb) || !array_is_list($rgb) || count($rgb) !== 3 || !array_all($rgb, static fn($value) => is_int($value))) {
        throw new InvalidArgumentException('Menu colors must be RGB integer triples.');
      }
      $palette[$role] = PresentationColor::rgb(...$rgb);
    }
    $this->colors = $palette;
    $this->metrics = new MenuThemeMetrics(...self::map($data, 'metrics'));
    $art = self::artwork(self::map($data, 'rowArtwork'), MenuRowSkin::ARTWORK);
    $this->rows = new MenuRowSkin(array_intersect_key($palette, array_flip(MenuRowSkin::COLORS)),
      new MenuRowMetrics(...self::map($data, 'rowMetrics')), $art, $assetRoot);
    $this->frames = self::artwork(self::map($data, 'frames'), ['panel', 'quiet', 'portrait',
      'slider.track', 'slider.thumb', 'scroll.track', 'scroll.thumb']);
    $bindings = self::map($data, 'icons');
    $cursor = $data['cursor'] ?? null;
    if ($cursor !== null && !is_string($cursor)) { throw new InvalidArgumentException('Menu cursor must be a PNG path.'); }
    $this->icons = $bindings !== [] || $cursor !== null ? new MenuIconRegistry($assetRoot, $bindings, $cursor) : null;
    $portraits = self::map($data, 'portraits');
    foreach ($portraits as $actorId => $asset) {
      if (!is_string($actorId) || $actorId === '' || !is_string($asset)) {
        throw new InvalidArgumentException('Menu portraits require stable actor IDs and PNG paths.');
      }
      SpriteValidation::validateAssetPath($asset);
    }
    $this->portraits = $portraits;
    // Diagnose every configured asset, including artwork for an actor not currently on screen.
    $assets = [...array_values($bindings), ...array_values($portraits)];
    if ($cursor !== null) { $assets[] = $cursor; }
    foreach ([...array_values($art), ...array_values($this->frames)] as $image) { $assets[] = $image->asset; }
    foreach (array_unique($assets) as $asset) { PngAssetPreflight::inspect($assetRoot, $asset); }
  }

  public static function exists(string $assetRoot): bool
  {
    return file_exists($assetRoot . '/' . self::FILE) || is_link($assetRoot . '/' . self::FILE);
  }

  /** Discover existing capabilities without executing or validating optional artwork at renderer startup. */
  public static function requestedCapabilities(string $assetRoot): array
  {
    return self::exists($assetRoot) ? self::CAPABILITIES : [];
  }

  public static function load(string $assetRoot): ?self
  {
    if (!self::exists($assetRoot)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($assetRoot . '/' . self::FILE);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Menu presentation catalog must be a readable file inside assets.');
    }
    $data = (static fn(string $file): mixed => require $file)($path);
    if (!is_array($data)) { throw new RuntimeException(self::FILE . ' must return a plain theme array.'); }
    return new self($root, $data);
  }

  private static function map(array $data, string $key): array
  {
    $value = $data[$key] ?? [];
    if (!is_array($value)) { throw new InvalidArgumentException("Menu {$key} must be an array."); }
    return $value;
  }

  private static function keys(array $data, array $allowed): void
  {
    if (array_diff(array_keys($data), $allowed) !== []) { throw new InvalidArgumentException('Unsupported menu theme field.'); }
  }

  /** @return array<string, MenuRowArtwork> */
  private static function artwork(array $data, array $roles): array
  {
    self::keys($data, $roles);
    $result = [];
    foreach ($data as $role => $definition) {
      if (!is_array($definition)) { throw new InvalidArgumentException('Menu artwork must be a plain descriptor.'); }
      self::keys($definition, ['asset', 'cuts', 'density', 'borderWidths']);
      $cuts = $definition['cuts'] ?? [0, 0, 0, 0];
      if (!is_array($cuts) || !array_is_list($cuts) || count($cuts) !== 4 || !array_all($cuts, static fn($value) => is_int($value))) {
        throw new InvalidArgumentException('Menu artwork cuts must be four integers: left, top, right, bottom.');
      }
      $result[$role] = new MenuRowArtwork($definition['asset'] ?? '',
        ...[...$cuts, $definition['density'] ?? 1, $definition['borderWidths'] ?? null]);
    }
    return $result;
  }
}
