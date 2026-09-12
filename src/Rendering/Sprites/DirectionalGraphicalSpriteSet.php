<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use InvalidArgumentException;

/** An optional capability, but a complete immutable set once configured. */
final readonly class DirectionalGraphicalSpriteSet
{
  public function __construct(
    public GraphicalSpriteDefinition $north,
    public GraphicalSpriteDefinition $east,
    public GraphicalSpriteDefinition $south,
    public GraphicalSpriteDefinition $west,
  )
  {
  }

  public function getForHeading(MovementHeading $heading): GraphicalSpriteDefinition
  {
    return match ($heading) {
      MovementHeading::NORTH => $this->north,
      MovementHeading::EAST => $this->east,
      MovementHeading::SOUTH, MovementHeading::NONE => $this->south,
      MovementHeading::WEST => $this->west,
    };
  }

  /** @param array<string, mixed> $data Four cardinal definitions; only anchor and layer may be omitted. */
  public static function fromArray(array $data): self
  {
    if (($data['mode'] ?? null) === 'sheet') {
      return self::fromSheetArray($data);
    }
    $directions = ['north', 'east', 'south', 'west'];
    if (array_diff_key($data, array_flip($directions)) !== []) {
      throw new InvalidArgumentException('Graphical sprite set only accepts north, east, south and west.');
    }

    $definitions = [];
    foreach ($directions as $direction) {
      $entry = $data[$direction] ?? null;
      if (!is_array($entry)) {
        throw new InvalidArgumentException("Graphical sprite direction '$direction' must contain a definition array.");
      }
      $entry += ['anchor' => PresentationSpriteAnchor::BOTTOM_CENTER->value, 'layer' => 0];
      if (array_diff_key($entry, array_flip(['asset', 'width', 'height', 'anchor', 'layer'])) !== []
        || !is_string($entry['asset'] ?? null)
        || !is_int($entry['width'] ?? null) || !is_int($entry['height'] ?? null)
        || !is_string($entry['anchor']) || !is_int($entry['layer'])) {
        throw new InvalidArgumentException("Graphical sprite direction '$direction' requires a string asset/anchor and integer width/height/layer, with no unknown fields.");
      }
      $anchor = PresentationSpriteAnchor::tryFrom($entry['anchor']);
      if ($anchor === null) {
        throw new InvalidArgumentException("Graphical sprite direction '$direction' has an unsupported anchor.");
      }
      try {
        $definitions[$direction] = new GraphicalSpriteDefinition(
          $entry['asset'], $entry['width'], $entry['height'], $anchor, $entry['layer'],
        );
      } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException("Graphical sprite direction '$direction': " . $exception->getMessage(), 0, $exception);
      }
    }

    return new self($definitions['north'], $definitions['east'], $definitions['south'], $definitions['west']);
  }

  /** @param array<string, mixed> $data */
  private static function fromSheetArray(array $data): self
  {
    $data += ['anchor' => 'bottom_center', 'layer' => 0, 'idleFrame' => 0,
      'frameDurationMs' => 80, 'stepDurationMs' => 160];
    $integerKeys = ['frameWidth', 'frameHeight', 'width', 'height', 'layer', 'idleFrame', 'frameDurationMs', 'stepDurationMs'];
    if (array_diff_key($data, array_flip([...$integerKeys, 'mode', 'anchor', 'directions'])) !== []) {
      throw new InvalidArgumentException('Sprite sheet configuration contains unknown fields.');
    }
    foreach ($integerKeys as $key) {
      if (!is_int($data[$key] ?? null)) {
        throw new InvalidArgumentException("Sprite sheet '$key' must be an integer.");
      }
    }
    $anchor = is_string($data['anchor']) ? PresentationSpriteAnchor::tryFrom($data['anchor']) : null;
    $directions = ['north', 'east', 'south', 'west'];
    if ($anchor === null || !is_array($data['directions'] ?? null)
      || array_diff_key($data['directions'], array_flip($directions)) !== []) {
      throw new InvalidArgumentException('Sprite sheets require a supported anchor and four cardinal directions.');
    }
    $definitions = [];
    foreach ($directions as $direction) {
      $entry = $data['directions'][$direction] ?? null;
      if (!is_array($entry) || array_diff_key($entry, array_flip(['asset', 'columns', 'rows', 'frames'])) !== []
        || !is_string($entry['asset'] ?? null) || !is_int($entry['columns'] ?? null)
        || !is_int($entry['rows'] ?? null) || !is_int($entry['frames'] ?? null)) {
        throw new InvalidArgumentException("Sprite sheet direction '$direction' requires an asset and integer columns, rows and frames.");
      }
      $sheet = new SpriteSheet($data['frameWidth'], $data['frameHeight'], $entry['columns'], $entry['rows'],
        $entry['frames'], $data['frameDurationMs'], $data['stepDurationMs'], $data['idleFrame']);
      $definitions[$direction] = new GraphicalSpriteDefinition($entry['asset'], $data['width'], $data['height'],
        $anchor, $data['layer'], $sheet->sourceRect($sheet->idleFrame), $sheet);
    }
    return new self($definitions['north'], $definitions['east'], $definitions['south'], $definitions['west']);
  }
}
