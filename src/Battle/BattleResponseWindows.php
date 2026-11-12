<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\Skill;

/** Tracks the turns owed by the battlers present when a warning was issued. */
final class BattleResponseWindows
{
  /** @var array<int, array{actor: CharacterInterface, skill: Skill, waiting: array<int, array{battler: CharacterInterface, turns: int}>}> */
  private array $windows = [];

  public function isPending(CharacterInterface $actor): bool
  {
    return isset($this->windows[spl_object_id($actor)]);
  }

  /** @param list<CharacterInterface> $respondents */
  public function prepare(CharacterInterface $actor, Skill $skill, array $respondents, int $turns): void
  {
    if ($this->isPending($actor)) { return; }
    $waiting = [];
    foreach ($respondents as $battler) {
      if ($battler->isFighting && $battler !== $actor) {
        $waiting[spl_object_id($battler)] = ['battler' => $battler, 'turns' => $turns];
      }
    }
    if ($waiting !== []) {
      $this->windows[spl_object_id($actor)] = ['actor' => $actor, 'skill' => $skill, 'waiting' => $waiting];
    }
  }

  /** @param list<CharacterInterface> $participants */
  public function completeTurn(CharacterInterface $actor, array $participants): void
  {
    $this->releaseInactive($participants);
    $respondent = spl_object_id($actor);
    foreach ($this->windows as $owner => $window) {
      $entry = $window['waiting'][$respondent] ?? null;
      if ($entry === null) { continue; }
      if ($entry['turns'] <= 1) {
        unset($this->windows[$owner]['waiting'][$respondent]);
      } else {
        --$this->windows[$owner]['waiting'][$respondent]['turns'];
      }
    }
  }

  public function getReadyAction(CharacterInterface $actor, CombatRandomSource $random,
    ?CombatResolver $resolver = null): ?SkillBattleAction
  {
    $window = $this->windows[spl_object_id($actor)] ?? null;
    if ($window === null || $window['waiting'] !== []) { return null; }
    $action = new SkillBattleAction($window['skill'], $resolver, $random);
    return $action->getExecutionRefusal($actor) === null ? $action : null;
  }

  public function consumeExecutedAction(CharacterInterface $actor, SkillBattleAction $action): void
  {
    $key = spl_object_id($actor);
    if ($action->lastResult !== null && ($this->windows[$key]['skill'] ?? null) === $action->skill) {
      unset($this->windows[$key]);
    }
  }

  /** @param list<CharacterInterface>|null $participants */
  public function releaseInactive(?array $participants = null): void
  {
    $isPresent = static fn(CharacterInterface $battler): bool =>
      $battler->isFighting && ($participants === null || in_array($battler, $participants, true));
    foreach ($this->windows as $owner => $window) {
      if (!$isPresent($window['actor'])) {
        unset($this->windows[$owner]);
        continue;
      }
      $this->windows[$owner]['waiting'] = array_filter($window['waiting'],
        static fn(array $entry): bool => $isPresent($entry['battler']));
    }
  }

  public function clear(): void { $this->windows = []; }
}
