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
 * up). A standard sheet holds 4 x 2 characters, selected by index. As in RPG
 * Maker, the file name's leading run of `$` and `!` marks describe the sheet:
 * `$` holds one character and `!` holds an object. Frame size comes from the
 * image, and a character always occupies exactly one field cell.
 *
 * Artists draw an RPG Maker character's feet on its frame's bottom edge, and
 * RPG Maker draws the frame {@see LIFT} pixels above its tile (Sprite_Character
 * shiftY), so the character stands within its tile. An object, such as a door
 * or chest, sits on its tile unlifted. The lift is a presentation of the
 * character only: its cell, collision and draw order stay those of the field.
 */
final readonly class CharacterSheet
{
  public const int FRAMES_PER_DIRECTION = 3;
  public const int DIRECTION_ROWS = 4;
  public const int SHEET_CHARACTER_COLUMNS = 4;
  public const int SHEET_CHARACTER_ROWS = 2;
  public const string SINGLE_CHARACTER_PREFIX = '$';
  public const string OBJECT_PREFIX = '!';
  public const int DEFAULT_LAYER = 0;
  /** RPG Maker MZ's character shiftY, in the sprite's field pixels (one field cell is FieldViewport::TILE_SIZE). */
  public const int LIFT = 6;

  public bool $isSingleCharacter;
  /** Whether the sheet is an RPG Maker object (`!`), drawn on its tile without the character lift. */
  public bool $isObject;
  /** Field pixels every frame of this sheet is drawn above its cell: {@see LIFT}, or 0 for an object. */
  public int $lift;

  public function __construct(
    public string $asset,
    public int $index = 0,
    public int $layer = self::DEFAULT_LAYER,
  )
  {
    SpriteValidation::validateDefinition($asset, FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE, $layer);
    // RPG Maker reads the marks from the name's leading run of `$` and `!`, in either order.
    $marks = preg_match('/^[$!]+/', basename($asset), $match) === 1 ? $match[0] : '';
    $this->isSingleCharacter = str_contains($marks, self::SINGLE_CHARACTER_PREFIX);
    $this->isObject = str_contains($marks, self::OBJECT_PREFIX);
    $this->lift = $this->isObject ? 0 : self::LIFT;
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
   * the standing frame), lifted as this sheet is ({@see $lift}).
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
    return new GraphicalSpriteDefinition($this->asset, FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE,
      PresentationSpriteAnchor::BOTTOM_CENTER, $this->layer, new SpriteSourceRect(
        $column * $frameSize['width'], $row * $frameSize['height'], $frameSize['width'], $frameSize['height'],
      ), $this->lift);
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
