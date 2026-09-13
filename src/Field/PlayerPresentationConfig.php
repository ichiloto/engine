<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use InvalidArgumentException;

/** Current project art, never persistent gameplay/save data. */
final readonly class PlayerPresentationConfig
{
  public function __construct(
    public PlayerSpriteSet $terminal,
    public ?DirectionalGraphicalSpriteSet $graphical = null,
  ) {}

  public static function load(): self
  {
    $data = asset('Data/Entities/player.php', true);
    return self::fromArray(is_array($data) ? $data : []);
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    $graphical = null;
    if (array_key_exists('sprites2d', $data)) {
      if (!is_array($data['sprites2d'])) {
        throw new InvalidArgumentException('Player sprites2d must be a complete directional definition array.');
      }
      $graphical = DirectionalGraphicalSpriteSet::fromArray($data['sprites2d']);
    }
    return new self(PlayerSpriteSet::fromArray($data), $graphical);
  }
}
