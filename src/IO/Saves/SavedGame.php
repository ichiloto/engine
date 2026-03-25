<?php

namespace Ichiloto\Engine\IO\Saves;

use Ichiloto\Engine\Scenes\Game\GameConfig;

/**
 * Represents a save-file payload after it has been decoded.
 *
 * @package Ichiloto\Engine\IO\Saves
 */
readonly class SavedGame
{
  /**
   * @param SaveSlot $slot The decoded save-slot summary.
   * @param GameConfig $config The saved game configuration.
   */
  public function __construct(
    public SaveSlot $slot,
    public GameConfig $config,
  )
  {
  }
}
