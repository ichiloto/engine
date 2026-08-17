<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;

/** A temporary field participant owned by one cinematic. */
final class StagedActor
{
  protected(set) MovementHeading $facing;

  /**
   * @param string[] $sprite
   * @param array<string, string[]> $directionalSprites
   */
  public function __construct(
    public readonly string $id,
    protected(set) array $sprite,
    protected(set) Vector2 $position,
    protected(set) bool $isVisible = true,
    protected(set) bool $hasCollision = false,
    protected(set) array $directionalSprites = [],
    MovementHeading $facing = MovementHeading::SOUTH,
    public readonly ?string $assetReference = null,
  )
  {
    $this->facing = $facing;
    $this->applyFacingSprite();
  }

  public function show(): void
  {
    $this->isVisible = true;
  }

  public function hide(): void
  {
    $this->isVisible = false;
  }

  public function face(Vector2 $direction): void
  {
    $this->facing = match (true) {
      $direction->y < 0 => MovementHeading::NORTH,
      $direction->y > 0 => MovementHeading::SOUTH,
      $direction->x < 0 => MovementHeading::WEST,
      $direction->x > 0 => MovementHeading::EAST,
      default => $this->facing,
    };
    $this->applyFacingSprite();
  }

  protected function applyFacingSprite(): void
  {
    $sprite = $this->directionalSprites[$this->facing->name]
      ?? $this->directionalSprites[strtolower($this->facing->name)]
      ?? null;

    if (is_array($sprite) && $sprite !== []) {
      $this->sprite = array_values(array_map('strval', $sprite));
    }
  }
}
