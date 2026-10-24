<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use InvalidArgumentException;

/**
 * Automatic PHP Game composition policy, never a constraint on generic sprite DTOs.
 *
 * The bands, lowest first:
 *  - the field world, below FIELD_GUIDANCE: map layers below characters
 *    (TERRAIN + order), ambient ground effects, characters and world objects
 *    (from WORLD), foreground effects, and map tiles drawn above characters
 *    (MAP_ABOVE + order). Every map band is bounded by MapLayer::MAX_ORDER,
 *    so no authored map, however many layers it has, reaches guidance;
 *  - field guidance (FIELD_GUIDANCE ..< UI): the action prompt, event and NPC
 *    cues and quest waymarks with their edge markers. It belongs to the field
 *    and moves with it, and draws over all of the field world;
 *  - UI, cinematics, transitions and notifications.
 */
final class PresentationLayerPolicy
{
  public const TERRAIN = -100;
  public const WORLD = 0;
  /** Ambient ground light is above map layers and below all character layers. */
  public const FIELD_EFFECT_BEHIND = -1;
  /** Foreground effects stay below authored above-character tiles (MAP_ABOVE..). */
  public const FIELD_EFFECT_FRONT = 899;
  /** Map tiles drawn above characters, MAP_ABOVE + their layer order. */
  public const MAP_ABOVE = 900;
  /**
   * Field guidance: plates and anything an effect draws behind its marks.
   * Every field world layer stays below it, whatever the map's tiles.
   */
  public const FIELD_GUIDANCE = 1000;
  /** Field guidance marks and edge pointers, over their plates. */
  public const FIELD_GUIDANCE_FRONT = 1001;
  public const FIELD_PROMPT_ID = 'field-prompt';
  public const UI = 1100;
  public const CINEMATIC = 2000;
  public const NOTIFICATIONS = 4000;
  public const TRANSITION = 3000;


  public static function getMapLayerId(MapLayer $layer): string
  {
    return 'map:' . $layer->name;
  }

  public static function getMapLayerOrder(MapLayer $layer): int
  {
    return self::getTileLayerOrder($layer->order, false);
  }

  /**
   * The layer a map band of this order draws at: below characters from
   * TERRAIN, or above them from MAP_ABOVE. Authored orders span 0..99, so
   * every map band stays inside the field world.
   */
  public static function getTileLayerOrder(int $order, bool $above): int
  {
    if ($order < MapLayer::MIN_ORDER || $order > MapLayer::MAX_ORDER) {
      throw new InvalidArgumentException('A map layer order is two digits, 0..99.');
    }
    return ($above ? self::MAP_ABOVE : self::TERRAIN) + $order;
  }

  /** Whether a field layer belongs to the field world, below guidance and UI. */
  public static function isFieldWorldLayer(int $layer): bool
  {
    return $layer >= self::FIELD_EFFECT_BEHIND && $layer < self::FIELD_GUIDANCE;
  }


  public static function ui(object $element, callable $draw): void
  {
    $priority = $element instanceof LayeredPresentationInterface ? $element->getPresentationPriority()->value : 0;
    Console::withLayer('ui:' . spl_object_id($element), $draw, self::UI + $priority);
  }

  public static function fieldPrompt(callable $draw): void
  {
    Console::withLayer(self::FIELD_PROMPT_ID, $draw, self::UI + PresentationPriority::FIELD_HUD->value);
  }

  /** @param list<PresentationSprite> $sprites */
  public static function assertWorldSprites(array $sprites): void
  {
    foreach ($sprites as $sprite) {
      if ($sprite->layer < self::FIELD_EFFECT_BEHIND || $sprite->layer >= self::UI) {
        throw new InvalidArgumentException('Automatic Game field sprites require layers -1..'
          . (self::UI - 1) . ': the field world below ' . self::FIELD_GUIDANCE . ', then guidance; UI layers are reserved.');
      }
    }
  }
}
