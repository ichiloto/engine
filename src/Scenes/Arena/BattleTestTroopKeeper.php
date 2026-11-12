<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use RuntimeException;

/**
 * Who keeps a troop composed in the battle test as one of the game's own
 * troops, when the player asks to: the Editor, which writes it into
 * Data/troops.php without disturbing the file's other source, or an editor
 * that launched the test, which adds it to the troops it has open. The
 * battle test itself never writes project data; without a keeper, a
 * composed troop is for testing only.
 */
interface BattleTestTroopKeeper
{
  /**
   * Keeps the members as a new troop, named after them.
   *
   * @param list<array<string, mixed>> $members As Data/troops.php holds a troop's `enemies`.
   * @return string What the player is told, such as the name it was kept under.
   * @throws RuntimeException With the reason when it cannot be kept.
   */
  public function keepTroop(array $members): string;
}
