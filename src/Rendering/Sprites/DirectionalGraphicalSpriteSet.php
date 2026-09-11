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
}
