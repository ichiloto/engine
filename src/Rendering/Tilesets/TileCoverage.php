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
  private string $assetRoot;
  /** @var array<string, array{width: int, height: int, mask: string}> By sheet key ({@see TilePiece::$sheet}). */
  private array $sheets = [];
  /** @var array<string, string> */
  private array $sheetDiagnostics = [];
  /** @var array<string, list<string>> One byte (0 or 1) per pixel, in row-major order, by tile or corner share. */
  private array $frames = [];
  /** @var array<int, CornerSheet>|null The usable corner sheets by flag identity, read on first use. */
  private ?array $cornerSheets = null;

  public function __construct(private readonly Tileset $tileset, string $assetRoot)
  {
    $this->assetRoot = $assetRoot;
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
      $layout = TilesetSheet::from($name);
      $this->readSheet($name, $asset, $this->tileSize * $layout->getColumns(), $this->tileSize * $layout->getRows());
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
   * @param list<int> $stack The cell's tiles, a corner-edged kind's own tile among them drawing nothing by itself.
   * @param list<array{int, int}> $corners The corner-edged kinds' shares of the cell, as [flag identity, packed cases]
   *   ({@see CornerShape::resolveLayer()}).
   * @return array{tileSize: ?int, uncoveredPixels: ?int, diagnostic: ?string}
   */
  public function findUncovered(array $stack, array $corners = [], int $x = 0, int $y = 0): array
  {
    if (!array_is_list($stack)) {
      throw new InvalidArgumentException('Tile coverage stack must be a list of tile identities.');
    }
    foreach ($stack as $tileId) {
      if (!is_int($tileId) || !TileId::isValid($tileId)) {
        throw new InvalidArgumentException('Tile coverage stack must contain valid integer tile identities.');
      }
    }
    foreach ($corners as $share) {
      if (!is_array($share) || !array_is_list($share) || count($share) !== 2 || !is_int($share[0]) || !is_int($share[1])) {
        throw new InvalidArgumentException('Tile coverage corner shares must be [flag identity, packed cases] pairs.');
      }
    }
    if ($this->diagnostic !== null) {
      return ['tileSize' => $this->tileSize, 'uncoveredPixels' => null, 'diagnostic' => $this->diagnostic];
    }
    $cornerTiles = $this->tileset->getCornerTiles();
    $results = [];
    foreach ($stack as $tileId) {
      $results[] = CornerShape::isCornerTile($tileId, $cornerTiles) ? ['frames' => [str_repeat("\0", $this->tileSize ** 2)], 'diagnostic' => null]
        : $this->readMasks($tileId);
    }
    foreach ($corners as [$tile, $cases]) {
      $results[] = $this->readCornerMasks($tile, $cases, $x, $y);
    }
    $covered = str_repeat("\0", $this->tileSize * $this->tileSize);
    foreach ($results as $result) {
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
    if ($tileId === TileId::EMPTY) {
      return ['frames' => [str_repeat("\0", $this->tileSize * $this->tileSize)], 'diagnostic' => null];
    }
    return $this->readComposedMasks((string)$tileId, "tile {$tileId}",
      fn(): array => TileComposer::compose($tileId, $this->tileSize, $this->tileset->isTable($tileId)));
  }

  /** @return array{frames: ?list<string>, diagnostic: ?string} */
  private function readCornerMasks(int $tile, int $cases, int $x, int $y): array
  {
    if (!in_array($tile, $this->tileset->getCornerTiles(), true)) {
      throw new InvalidArgumentException("Tile {$tile} is not a corner-edged kind's autotile for pixel inspection.");
    }
    $this->cornerSheets ??= $this->tileset->getUsableCornerSheets($this->assetRoot, $this->tileSize, $this->tileset->getCornerTiles());
    $sheet = $this->cornerSheets[$tile] ?? null;
    if ($sheet === null) {
      return ['frames' => null, 'diagnostic' => "Tile pixel inspection unavailable: tileset {$this->tileset->id} has no usable corner sheet for tile {$tile}."];
    }
    if (!isset($this->sheets[$sheet->key]) && !isset($this->sheetDiagnostics[$sheet->key])) {
      $this->readSheet($sheet->key, $sheet->asset, $this->tileSize * CornerSheet::COLUMNS * $sheet->phases,
        $this->tileSize * CornerSheet::ROWS * $sheet->variants);
    }
    $variants = $sheet->getCellVariants($x, $y);
    return $this->readComposedMasks("corners:{$tile}:{$cases}:" . implode(',', $variants), "corner tile {$tile}",
      fn(): array => TileComposer::composeCorners($sheet, $cases, $this->tileSize, $variants));
  }

  /**
   * @param callable(): list<list<TilePiece>> $compose
   * @return array{frames: ?list<string>, diagnostic: ?string}
   */
  private function readComposedMasks(string $key, string $name, callable $compose): array
  {
    if (isset($this->frames[$key])) { return ['frames' => $this->frames[$key], 'diagnostic' => null]; }
    $frames = [];
    foreach ($compose() as $pieces) {
      $mask = str_repeat("\0", $this->tileSize * $this->tileSize);
      foreach ($pieces as $piece) {
        $sheet = $this->sheets[$piece->sheet] ?? null;
        if ($sheet === null) {
          return ['frames' => null, 'diagnostic' => $this->sheetDiagnostics[$piece->sheet]
            ?? "Tile pixel inspection unavailable: tileset {$this->tileset->id} has no usable sheet {$piece->sheet} for {$name}."];
        }
        if ($piece->x + $piece->width > $sheet['width'] || $piece->y + $piece->height > $sheet['height']) {
          return ['frames' => null, 'diagnostic' => "Tile pixel inspection source exceeds sheet {$piece->sheet} for {$name}."];
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
    if (count($this->frames) >= self::MAX_CACHE_ENTRIES) { unset($this->frames[array_key_first($this->frames)]); }
    $this->frames[$key] = $frames;
    return ['frames' => $frames, 'diagnostic' => null];
  }

  /** Decodes one sheet's visibility, or records why it cannot be read. */
  private function readSheet(string $key, string $asset, int $width, int $height): void
  {
    try {
      $size = PngAssetPreflight::inspect($this->assetRoot, $asset);
      $decoded = @imagecreatefrompng($this->assetRoot . DIRECTORY_SEPARATOR . $asset);
      if ($decoded === false) {
        throw new RuntimeException("Tile pixel inspection could not decode sheet {$key}: {$asset}.");
      }
      try {
        if (imagesx($decoded) !== $size['width'] || imagesy($decoded) !== $size['height']
          || $size['width'] !== $width || $size['height'] !== $height) {
          throw new RuntimeException("Tile pixel inspection found changed dimensions for sheet {$key}: {$asset}.");
        }
        $this->sheets[$key] = ['width' => $size['width'], 'height' => $size['height'], 'mask' => self::readSheetMask($decoded)];
      } finally {
        // Retain packed visibility, not every decoded RGBA sheet at once.
        unset($decoded);
      }
    } catch (RuntimeException $error) {
      $this->sheetDiagnostics[$key] = $error->getMessage();
    }
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
