<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use InvalidArgumentException;

/** Automatic PHP Game composition policy, never a constraint on generic sprite DTOs. */
final class PresentationLayerPolicy
{
  public const TERRAIN = -100;
  public const TERRAIN_ID = 'terrain';
  public const WORLD = 0;
  public const UI = 1000;
  public const NOTIFICATIONS = 2000;
  public const TRANSITION = 3000;

  public static function terrain(callable $draw): void
  {
    Console::withLayer(self::TERRAIN_ID, $draw, self::WORLD, replaceUnderlying: true);
  }

  public static function getMapLayerId(MapLayer $layer): string
  {
    return 'map:' . $layer->name;
  }

  public static function getMapLayerOrder(MapLayer $layer): int
  {
    // Authored two-digit prefixes span 0..99, keeping every map layer below WORLD.
    return self::TERRAIN + $layer->order;
  }

  public static function drawMapLayer(MapLayer $layer, callable $draw): void
  {
    Console::withLayer(self::getMapLayerId($layer), $draw, self::getMapLayerOrder($layer), replaceUnderlying: true);
  }

  public static function ui(object $element, callable $draw): void
  {
    $priority = $element instanceof LayeredPresentationInterface ? $element->getPresentationPriority()->value : 0;
    Console::withLayer('ui:' . spl_object_id($element), $draw, self::UI + $priority);
  }

  public static function fieldPrompt(callable $draw): void
  {
    Console::withLayer('field-prompt', $draw, self::UI + PresentationPriority::FIELD_HUD->value);
  }

  /** @param list<PresentationSprite> $sprites */
  public static function assertWorldSprites(array $sprites): void
  {
    foreach ($sprites as $sprite) {
      if ($sprite->layer < self::WORLD || $sprite->layer >= self::UI) {
        throw new InvalidArgumentException('Automatic Game world sprites require layers 0..999; UI layers are reserved.');
      }
    }
  }
}
