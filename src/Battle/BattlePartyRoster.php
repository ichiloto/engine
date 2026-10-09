<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;

/** One battle's participants; reading or drawing them never changes membership. */
final class BattlePartyRoster
{
  /** @var list<CharacterInterface> */
  private array $activeMembers;

  /** @var list<CharacterInterface> */
  public array $battlers { get => $this->activeMembers; }

  public bool $isDefeated {
    get => $this->activeMembers !== []
      && array_all($this->activeMembers, static fn(CharacterInterface $member): bool => $member->isKnockedOut);
  }

  public function __construct(
    public readonly Party $party,
    public readonly ReservePolicy $policy = ReservePolicy::NONE,
  ) {
    $this->activeMembers = $party->battlers->toArray();
  }

  /** Called at a resolution boundary, after the outgoing participants' KO presentation. */
  public function promoteReservesAfterWipeout(): bool
  {
    if ($this->policy !== ReservePolicy::REPLACE_AFTER_WIPEOUT || !$this->isDefeated) { return false; }
    $reserves = array_slice(array_values(array_filter($this->party->members->toArray(),
      static fn(CharacterInterface $member): bool => !$member->isKnockedOut)), 0, Party::BATTLE_SIZE);
    if ($reserves === []) { return false; }
    $this->activeMembers = $reserves;
    return true;
  }
}
