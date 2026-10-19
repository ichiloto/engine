<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use GdImage;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/** Read-only optional author inspection. Create a new snapshot for each validation pass. */
final class TileCoverage
{
  private const int MAX_CACHE_ENTRIES = 512;
  private ?int $tileSize = null;
  private ?string $diagnostic = null;
  /** @var array<string, array{width: int, height: int, mask: string}> */
  private array $sheets = [];
  /** @var array<string, string> */
  private array $sheetDiagnostics = [];
  /** @var array<int, list<string>> One byte (0 or 1) per pixel, in row-major order. */
  private array $frames = [];

  public function __construct(private readonly Tileset $tileset, string $assetRoot)
  {
    if (!function_exists('imagecreatefrompng')) {
      $this->diagnostic = 'Tile pixel inspection unavailable: optional GD PNG support is not installed.';
      return;
    }
    $usable = $tileset->getUsableSheets($assetRoot);
    if ($usable === null) {
      $this->diagnostic = "Tile pixel inspection unavailable: tileset {$tileset->id} has no usable sheets.";
      return;
    }
    $this->tileSize = $usable['tileSize'];
    foreach ($usable['sheets'] as $name => $asset) {
      try {
        $size = PngAssetPreflight::inspect($assetRoot, $asset);
        $decoded = @imagecreatefrompng($assetRoot . DIRECTORY_SEPARATOR . $asset);
        if ($decoded === false) {
          throw new RuntimeException("Tile pixel inspection could not decode sheet {$name}: {$asset}.");
        }
        try {
          $layout = TilesetSheet::from($name);
          if (imagesx($decoded) !== $size['width'] || imagesy($decoded) !== $size['height']
            || $size['width'] !== $this->tileSize * $layout->getColumns()
            || $size['height'] !== $this->tileSize * $layout->getRows()) {
            throw new RuntimeException("Tile pixel inspection found changed dimensions for sheet {$name}: {$asset}.");
          }
          $this->sheets[$name] = ['width' => $size['width'], 'height' => $size['height'],
            'mask' => self::readSheetMask($decoded)];
        } finally {
          // Retain packed visibility, not every decoded RGBA sheet at once.
          unset($decoded);
        }
      } catch (RuntimeException $error) {
        $this->sheetDiagnostics[$name] = $error->getMessage();
      }
    }
  }

  /** @return array{tileSize: ?int, frames: ?list<list<bool>>, diagnostic: ?string} */
  public function readFrames(int $tileId): array
  {
    $result = $this->readMasks($tileId);
    return ['tileSize' => $this->tileSize,
      'frames' => $result['frames'] === null ? null : array_map(
        static fn(string $mask): array => array_map(static fn(string $pixel): bool => $pixel === "\1", str_split($mask)),
        $result['frames']),
      'diagnostic' => $result['diagnostic']];
  }

  /**
   * Counts distinct pixels exposed in any independent combination of animation frames.
   * This is not the maximum number exposed simultaneously in one combination.
   *
   * @param list<int> $stack
   * @return array{tileSize: ?int, uncoveredPixels: ?int, diagnostic: ?string}
   */
  public function findUncovered(array $stack): array
  {
    if (!array_is_list($stack)) {
      throw new InvalidArgumentException('Tile coverage stack must be a list of tile identities.');
    }
    foreach ($stack as $tileId) {
      if (!is_int($tileId) || !TileId::isValid($tileId)) {
        throw new InvalidArgumentException('Tile coverage stack must contain valid integer tile identities.');
      }
    }
    if ($this->diagnostic !== null) {
      return ['tileSize' => $this->tileSize, 'uncoveredPixels' => null, 'diagnostic' => $this->diagnostic];
    }
    $covered = str_repeat("\0", $this->tileSize * $this->tileSize);
    foreach ($stack as $tileId) {
      $result = $this->readMasks($tileId);
      if ($result['frames'] === null) {
        return ['tileSize' => $this->tileSize, 'uncoveredPixels' => null, 'diagnostic' => $result['diagnostic']];
      }
      // A pixel is safe in every combination iff at least one layer covers it in every frame.
      $always = str_repeat("\1", strlen($covered));
      foreach ($result['frames'] as $mask) { $always &= $mask; }
      $covered |= $always;
    }
    return ['tileSize' => $this->tileSize, 'uncoveredPixels' => substr_count($covered, "\0"), 'diagnostic' => null];
  }

  /** @return array{frames: ?list<string>, diagnostic: ?string} */
  private function readMasks(int $tileId): array
  {
    if (!TileId::isValid($tileId)) {
      throw new InvalidArgumentException("Tile {$tileId} is not a valid tile identity for pixel inspection.");
    }
    if ($this->diagnostic !== null) { return ['frames' => null, 'diagnostic' => $this->diagnostic]; }
    if (isset($this->frames[$tileId])) { return ['frames' => $this->frames[$tileId], 'diagnostic' => null]; }
    $frames = [];
    if ($tileId === TileId::EMPTY) {
      $frames[] = str_repeat("\0", $this->tileSize * $this->tileSize);
    } else {
      foreach (TileComposer::compose($tileId, $this->tileSize, $this->tileset->isTable($tileId)) as $pieces) {
        $mask = str_repeat("\0", $this->tileSize * $this->tileSize);
        foreach ($pieces as $piece) {
          $name = $piece->sheet->value;
          $sheet = $this->sheets[$name] ?? null;
          if ($sheet === null) {
            return ['frames' => null, 'diagnostic' => $this->sheetDiagnostics[$name]
              ?? "Tile pixel inspection unavailable: tileset {$this->tileset->id} has no usable sheet {$name} for tile {$tileId}."];
          }
          if ($piece->x + $piece->width > $sheet['width'] || $piece->y + $piece->height > $sheet['height']) {
            return ['frames' => null, 'diagnostic' => "Tile pixel inspection source exceeds sheet {$name} for tile {$tileId}."];
          }
          for ($y = 0; $y < $piece->height; $y++) {
            $source = ($piece->y + $y) * $sheet['width'] + $piece->x;
            $destination = ($piece->top + $y) * $this->tileSize + $piece->left;
            $row = substr($mask, $destination, $piece->width) | substr($sheet['mask'], $source, $piece->width);
            $mask = substr_replace($mask, $row, $destination, $piece->width);
          }
        }
        $frames[] = $mask;
      }
    }
    if (count($this->frames) >= self::MAX_CACHE_ENTRIES) { unset($this->frames[array_key_first($this->frames)]); }
    $this->frames[$tileId] = $frames;
    return ['frames' => $frames, 'diagnostic' => null];
  }

  private static function readSheetMask(GdImage $image): string
  {
    $width = imagesx($image);
    $height = imagesy($image);
    $mask = str_repeat("\0", $width * $height);
    $trueColor = imageistruecolor($image);
    $paletteAlpha = [];
    for ($y = 0; $y < $height; $y++) {
      for ($x = 0; $x < $width; $x++) {
        $color = imagecolorat($image, $x, $y);
        if ($color === false) { throw new RuntimeException('Tile pixel inspection could not read a sheet pixel.'); }
        $alpha = $trueColor ? (($color >> 24) & 127)
          : ($paletteAlpha[$color] ??= imagecolorsforindex($image, $color)['alpha']);
        if ($alpha < 127) { $mask[$y * $width + $x] = "\1"; }
      }
    }
    return $mask;
  }
}
