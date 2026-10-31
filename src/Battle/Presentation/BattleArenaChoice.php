<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/**
 * The arena a battle is drawn on once its encounter terrain is known
 * ({@see BattlePresentationCatalog::resolveArenaVariant()}).
 */
final readonly class BattleArenaChoice
{
  /**
   * @param string $key The arena drawn, by catalog key.
   * @param string|null $terrain The encounter terrain the battle started on, or null for none.
   * @param bool $isMissingVariant Whether the named arena has no variant for that terrain, so its own picture is drawn.
   */
  public function __construct(public string $key, public ?string $terrain, public bool $isMissingVariant)
  {
  }
}
