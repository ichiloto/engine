<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use InvalidArgumentException;

/** Automatic PHP Game composition policy, never a constraint on generic sprite DTOs. */
final class PresentationLayerPolicy
{
  public const WORLD = 0;
  public const UI = 1000;
  public const NOTIFICATIONS = 2000;
  public const TRANSITION = 3000;

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
