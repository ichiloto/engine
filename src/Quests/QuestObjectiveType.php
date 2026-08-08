<?php

namespace Ichiloto\Engine\Quests;

/**
 * The kinds of moments a quest objective tracks.
 *
 * @package Ichiloto\Engine\Quests
 */
enum QuestObjectiveType: string
{
  /**
   * Talk to a named NPC (matched against the dialogue trigger's `npcId`,
   * falling back to the first speaker's name).
   */
  case TALK_TO = 'talk_to';
  /**
   * Hold a quantity of a named item. Progress tracks the party's inventory,
   * so using or selling the item lowers it again.
   */
  case COLLECT = 'collect';
  /**
   * Defeat a number of a named enemy in battle.
   */
  case DEFEAT = 'defeat';
  /**
   * Enter a named map (the map's asset path, e.g. `happyville/town-center`).
   */
  case REACH_MAP = 'reach_map';
  /**
   * Have a named switch or story event set.
   */
  case FLAG = 'flag';

  /**
   * Describes the objective in journal-friendly language.
   *
   * @param string $target The objective target.
   * @param int $quantity The required quantity.
   * @return string The description.
   */
  public function describe(string $target, int $quantity): string
  {
    $counted = $quantity > 1 ? sprintf(' x%d', $quantity) : '';

    return match ($this) {
      self::TALK_TO => sprintf('Talk to %s', $target),
      self::COLLECT => sprintf('Collect %s%s', $target, $counted),
      self::DEFEAT => sprintf('Defeat %s%s', $target, $counted),
      self::REACH_MAP => sprintf('Reach %s', $target),
      self::FLAG => sprintf('Trigger %s', $target),
    };
  }
}
