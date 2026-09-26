<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/**
 * A field character drawn from an RPG Maker character sheet.
 *
 * Each character is 3 walking frames by 4 direction rows (down, left, right,
 * up). A standard sheet holds 4 x 2 characters, selected by index; a sheet
 * whose file name begins with `$` holds one. Frame size comes from the image,
 * and a character always occupies exactly one field cell.
 */
final readonly class CharacterSheet
{
  public const int FRAMES_PER_DIRECTION = 3;
  public const int DIRECTION_ROWS = 4;
  public const int SHEET_CHARACTER_COLUMNS = 4;
  public const int SHEET_CHARACTER_ROWS = 2;
  public const string SINGLE_CHARACTER_PREFIX = '$';
  public const int DEFAULT_LAYER = 0;

  public bool $isSingleCharacter;

  public function __construct(
    public string $asset,
    public int $index = 0,
    public int $layer = self::DEFAULT_LAYER,
  )
  {
    SpriteValidation::validateDefinition($asset, FieldViewport::CELL_SIZE, FieldViewport::CELL_SIZE, $layer);
    $this->isSingleCharacter = str_starts_with(basename($asset), self::SINGLE_CHARACTER_PREFIX);
    $characters = $this->isSingleCharacter ? 1 : self::SHEET_CHARACTER_COLUMNS * self::SHEET_CHARACTER_ROWS;
    if ($index < 0 || $index >= $characters) {
      throw new InvalidArgumentException($this->isSingleCharacter
        ? "Character sheet '$asset' holds one character; its index must be 0."
        : "Character sheet '$asset' holds $characters characters; its index must be 0 to " . ($characters - 1) . '.');
    }
  }

  /** @param array<string, mixed> $data `sheet`, with optional `index` and `layer`. */
  public static function fromArray(array $data): self
  {
    if (array_diff_key($data, array_flip(['sheet', 'index', 'layer'])) !== []) {
      throw new InvalidArgumentException('A character sheet accepts only sheet, index and layer. Size, anchor and frames come from the sheet.');
    }
    if (!is_string($data['sheet'] ?? null) || !is_int($data['index'] ?? 0) || !is_int($data['layer'] ?? self::DEFAULT_LAYER)) {
      throw new InvalidArgumentException('A character sheet requires a string sheet and integer index and layer.');
    }
    return new self($data['sheet'], $data['index'] ?? 0, $data['layer'] ?? self::DEFAULT_LAYER);
  }

  /** @return array{width: int, height: int} One frame's size, derived from the sheet image. */
  public function getFrameSize(int $imageWidth, int $imageHeight): array
  {
    $columns = self::FRAMES_PER_DIRECTION * ($this->isSingleCharacter ? 1 : self::SHEET_CHARACTER_COLUMNS);
    $rows = self::DIRECTION_ROWS * ($this->isSingleCharacter ? 1 : self::SHEET_CHARACTER_ROWS);
    if ($imageWidth < $columns || $imageHeight < $rows || $imageWidth % $columns !== 0 || $imageHeight % $rows !== 0) {
      throw new InvalidArgumentException(sprintf(
        "Character sheet '%s' is %d x %d pixels; an RPG Maker %s sheet divides into %d x %d equal frames.",
        $this->asset, $imageWidth, $imageHeight, $this->isSingleCharacter ? 'single-character' : 'character',
        $columns, $rows,
      ));
    }
    return ['width' => intdiv($imageWidth, $columns), 'height' => intdiv($imageHeight, $rows)];
  }

  /**
   * The one-cell definition for a heading and walking pattern (0 to 2; 1 is
   * the standing frame).
   *
   * @param array{width: int, height: int} $frameSize
   */
  public function getFrame(MovementHeading $heading, int $pattern, array $frameSize): GraphicalSpriteDefinition
  {
    if ($pattern < 0 || $pattern >= self::FRAMES_PER_DIRECTION) {
      throw new InvalidArgumentException('A walking pattern selects one of the three frames of a direction.');
    }
    $block = $this->isSingleCharacter ? 0 : $this->index;
    $column = ($block % self::SHEET_CHARACTER_COLUMNS) * self::FRAMES_PER_DIRECTION + $pattern;
    $row = intdiv($block, self::SHEET_CHARACTER_COLUMNS) * self::DIRECTION_ROWS + self::getDirectionRow($heading);
    return new GraphicalSpriteDefinition($this->asset, FieldViewport::CELL_SIZE, FieldViewport::CELL_SIZE,
      PresentationSpriteAnchor::BOTTOM_CENTER, $this->layer, new SpriteSourceRect(
        $column * $frameSize['width'], $row * $frameSize['height'], $frameSize['width'], $frameSize['height'],
      ));
  }

  /** RPG Maker's direction rows: down, left, right, up. Facing nowhere shows down. */
  public static function getDirectionRow(MovementHeading $heading): int
  {
    return match ($heading) {
      MovementHeading::SOUTH, MovementHeading::NONE => 0,
      MovementHeading::WEST => 1,
      MovementHeading::EAST => 2,
      MovementHeading::NORTH => 3,
    };
  }
}
