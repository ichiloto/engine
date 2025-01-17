<?php

namespace Ichiloto\Engine\Entities;

/**
 * Represents the character sprites. This is a collection of character sprites for different contexts.
 *
 * @package Ichiloto\Engine\Entities
 */
class CharacterSprites
{
  /**
   * The constructor.
   *
   * @param string[] $dialog The sprite array for dialog.
   * @param string[] $field The sprite array for the field.
   * @param string[] $battle The sprite array for battle.
   */
  public function __construct(
    public array $dialog = [],
    public array $field = [],
    public array $battle = [],
  )
  {
  }

  /**
   * Creates a new character sprite list from an array.
   *
   * @param array{dialog: string[]|null, field: string[]|null, battle: string[]|null} $data The data to create the character sprite array from.
   * @return static The character sprites.
   */
  public static function fromArray(array $data): static
  {
    return new static(
      $data['dialog'] ?? [],
      $data['field'] ?? [],
      $data['battle'] ?? [
        "    O    ",
        "   /|\   ",
        "  / | \  ",
        "   / \   ",
        "  /   \  ",
      ]
    );
  }
}