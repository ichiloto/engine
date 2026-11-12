<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Battle\Events\BattleEventControlInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * What a registered command handler may use while its script runs.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final readonly class ScriptCommandContext
{
  /**
   * @param GameScene $scene The scene running the script: party, player, world state and scene states.
   * @param string|null $scriptId The script's stable identity, when it has one.
   * @param array<string, scalar|null> $origin Where the script was authored: map, trigger, NPC.
   * @param BattleEventControlInterface|null $battle The battle a battle event page runs in; null in field scripts.
   */
  public function __construct(
    public GameScene $scene,
    public ?string $scriptId = null,
    public array $origin = [],
    public ?BattleEventControlInterface $battle = null,
  )
  {
  }
}
