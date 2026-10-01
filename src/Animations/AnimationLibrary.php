<?php

namespace Ichiloto\Engine\Animations;

use RuntimeException;
use Throwable;
use Ichiloto\Engine\Util\Debug;

/**
 * Loads terminal animations from the project's data assets.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationLibrary
{
  /** @var Animation[]|null */
  private ?array $battleAnimations = null;

  /**
   * @param string $assetPath The asset path relative to assets/.
   */
  public function __construct(
    protected string $assetPath = 'Data/animations.php',
    protected bool $cacheForBattle = false,
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
    if ($this->cacheForBattle && $this->battleAnimations !== null) {
      return $this->battleAnimations;
    }

    try {
      $payload = asset($this->assetPath, true);
    } catch (Throwable $error) {
      Debug::warn(sprintf('Animation library %s could not be loaded: %s', $this->assetPath, $error->getMessage()));
      return $this->cacheForBattle ? ($this->battleAnimations = []) : [];
    }

    if (! is_array($payload)) {
      Debug::warn(sprintf('Animation library %s must return an array.', $this->assetPath));
      return $this->cacheForBattle ? ($this->battleAnimations = []) : [];
    }

    $animations = [];
    foreach ($payload as $index => $source) {
      try {
        if (!is_array($source)) { throw new RuntimeException('Animation entry must be an array.'); }
        $animations[] = Animation::fromArray($source);
      } catch (Throwable $error) {
        Debug::warn(sprintf('Animation entry %s could not be loaded: %s', strval($index), $error->getMessage()));
      }
    }
    return $this->cacheForBattle ? ($this->battleAnimations = $animations) : $animations;
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
