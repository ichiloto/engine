<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** Current project art, never persistent gameplay/save data. */
final readonly class PlayerPresentationConfig
{
  public function __construct(
    public PlayerSpriteSet $terminal,
    public ?CharacterSheet $graphical = null,
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
      // Optional art never stops the game: a malformed sheet keeps the terminal sprite.
      try {
        if (!is_array($data['sprites2d'])) {
          throw new InvalidArgumentException('Player sprites2d must be a character sheet definition array.');
        }
        $graphical = CharacterSheet::fromArray($data['sprites2d']);
      } catch (InvalidArgumentException $error) {
        Debug::warn('Player sprites2d is invalid; keeping the terminal sprite: ' . $error->getMessage());
      }
    }
    return new self(PlayerSpriteSet::fromArray($data), $graphical);
  }
}
