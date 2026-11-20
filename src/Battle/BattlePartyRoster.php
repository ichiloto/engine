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

  /** All members this encounter can put on the field, including its eligible reserve pool.
   * @return list<CharacterInterface>
   */
  public function getPotentialBattlers(): array
  {
    return match ($this->policy) {
      ReservePolicy::NONE => $this->activeMembers,
      ReservePolicy::REPLACE_AFTER_WIPEOUT => $this->party->participants->toArray(),
    };
  }

  /** Called at a resolution boundary, after the outgoing participants' KO presentation. */
  public function promoteReservesAfterWipeout(): bool
  {
    if ($this->policy !== ReservePolicy::REPLACE_AFTER_WIPEOUT || !$this->isDefeated) { return false; }
    $reserves = array_slice(array_values(array_filter($this->getPotentialBattlers(),
      static fn(CharacterInterface $member): bool => !$member->isKnockedOut)), 0, $this->party->battleSize);
    if ($reserves === []) { return false; }
    $this->activeMembers = $reserves;
    return true;
  }
}
