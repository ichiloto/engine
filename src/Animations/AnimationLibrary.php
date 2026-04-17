<?php

namespace Ichiloto\Engine\Animations;

use RuntimeException;

/**
 * Loads terminal animations from the project's data assets.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationLibrary
{
  /**
   * @param string $assetPath The asset path relative to assets/.
   */
  public function __construct(
    protected string $assetPath = 'Data/animations.php',
  )
  {
  }

  /**
   * Loads all configured animations.
   *
   * @return Animation[]
   */
  public function load(): array
  {
    try {
      $payload = asset($this->assetPath, true);
    } catch (RuntimeException) {
      return [];
    }

    if (! is_array($payload)) {
      return [];
    }

    return array_map(
      static fn(array $animation): Animation => Animation::fromArray($animation),
      array_values(array_filter($payload, 'is_array'))
    );
  }

  /**
   * Finds an animation by numeric id.
   *
   * @param int $id The animation id.
   * @return Animation|null
   */
  public function findById(int $id): ?Animation
  {
    foreach ($this->load() as $animation) {
      if ($animation->id === $id) {
        return $animation;
      }
    }

    return null;
  }

  /**
   * Finds an animation by name.
   *
   * @param string $name The animation name.
   * @return Animation|null
   */
  public function findByName(string $name): ?Animation
  {
    foreach ($this->load() as $animation) {
      if ($animation->name === $name) {
        return $animation;
      }
    }

    return null;
  }
}
