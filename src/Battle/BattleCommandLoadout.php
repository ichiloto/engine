<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use InvalidArgumentException;

/** A runtime-only command selection and explicit summon access, never save data. */
final readonly class BattleCommandLoadout
{
  /**
   * @param list<BattleCommandType>|null $commands Null retains the actor's normal menu.
   * @param list<string> $availableSummons Explicit sandbox grants bypass story availability only.
   * @param list<string> $additionalSkills Explicit grants, retained when describing a test setup.
   */
  public function __construct(public ?array $commands = null, public array $availableSummons = [], public array $additionalSkills = [])
  {
    if ($commands !== null && ($commands === [] || !array_is_list($commands))) {
      throw new InvalidArgumentException('A battle command selection must be a nonempty list.');
    }
    $seen = [];
    foreach ($commands ?? [] as $command) {
      if (!$command instanceof BattleCommandType || isset($seen[$command->value])) {
        throw new InvalidArgumentException('Battle commands must be unique BattleCommandType values.');
      }
      $seen[$command->value] = true;
    }
  }

  public function allowsCommand(?BattleCommandType $command): bool
  {
    return $this->commands === null || in_array($command, $this->commands, true);
  }

  public function grantsSummonAvailability(string $summonId): bool
  {
    return in_array($summonId, $this->availableSummons, true);
  }
}
