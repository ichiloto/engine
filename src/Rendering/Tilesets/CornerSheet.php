<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/**
 * A corner-edged terrain kind's corner sheet, ready to draw: one image per
 * kind holding its 16 corner tiles ({@see CornerShape::LAYOUT}) in a block of
 * 4 x 4 tiles for each phase, one phase for a still kind, or three side by
 * side for animated water, cycled 0, 1, 2, 1 as A1 water is. Its phases and
 * tile size are read from the current image, never stored beside it.
 */
final readonly class CornerSheet
{
  public const int COLUMNS = 4;
  public const int ROWS = 4;
  /** Still, or A1 water's three phases. */
  public const array PHASES = [1, 3];
  private const string KEY_PREFIX = 'corners:';

  /** The key its pieces name the sheet by ({@see TilePiece::$sheet}). */
  public string $key;

  /**
   * @param string $kind The terrain kind it draws.
   * @param int $tile The flag identity of the kind's autotile, which a tile layer holds where the kind is.
   * @param string $asset The asset-relative PNG.
   * @param int $phases One or three.
   */
  public function __construct(public string $kind, public int $tile, public string $asset, public int $phases)
  {
    if (!in_array($phases, self::PHASES, true)) {
      throw new InvalidArgumentException("Corner sheet {$asset} has {$phases} phases; a corner sheet has one, or three for animated water.");
    }
    $this->key = self::KEY_PREFIX . $kind;
  }

  /**
   * Reads a kind's corner sheet at its tileset's tile size.
   *
   * @throws RuntimeException|InvalidArgumentException When the image is missing, unreadable or not a corner sheet at that size.
   */
  public static function read(TerrainKind $kind, string $assetRoot, int $tileSize): self
  {
    $asset = $kind->art['corners'] ?? throw new InvalidArgumentException("Terrain kind {$kind->id} has no corner sheet.");
    $size = PngAssetPreflight::inspect($assetRoot, $asset);
    $block = self::COLUMNS * $tileSize;
    $phases = intdiv($size['width'], $block);
    if ($size['height'] !== self::ROWS * $tileSize || $size['width'] % $block !== 0 || !in_array($phases, self::PHASES, true)) {
      throw new RuntimeException(sprintf('%s is %dx%d; a corner sheet is %d x %d tiles for each phase, one phase or three side by side, in its tileset\'s %d-pixel tiles: %dx%d still or %dx%d animated.',
        $asset, $size['width'], $size['height'], self::COLUMNS, self::ROWS, $tileSize, $block, self::ROWS * $tileSize,
        3 * $block, self::ROWS * $tileSize));
    }
    return new self($kind->id, $kind->art['tile'], $asset, $phases);
  }
}
