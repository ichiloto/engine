<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Battle\BattleCommandLoadout;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Skills\Skill;

/** Shared resource selection, validation and grants for the battle sandbox. */
final class BattleTestLoadoutCatalog
{
  /** @var array<string, SummonCutsceneDefinition> */
  private array $summons = [];

  public function __construct(private readonly SkillCatalog $skills, SummonCutsceneLibrary $summons)
  {
    foreach ($summons->load() as $definition) {
      $this->summons[$definition->id] = $definition;
    }
  }

  public static function getProjectCatalog(): self
  {
    return new self(SkillCatalog::getProjectCatalog(), new SummonCutsceneLibrary());
  }

  /** @return list<array{id: string, name: string}> */
  public function getSkillChoices(bool $magic): array
  {
    $choices = [];
    foreach ($this->skills->getSkills() as $skill) {
      if ($this->isBattleUsable($skill) && ($magic ? $skill instanceof MagicSkill : $skill instanceof SpecialSkill)
        && array_find($this->summons, static fn($definition): bool => $definition->linkedActionId === $skill->name) === null) {
        $choices[] = ['id' => $skill->name, 'name' => $skill->name];
      }
    }
    return $choices;
  }

  /** @return list<array{id: string, name: string}> */
  public function getSummonChoices(Character $character): array
  {
    $choices = [];
    foreach ($this->summons as $id => $definition) {
      if ($this->getSummonProblem($id, $character) === null) {
        $choices[] = ['id' => $id, 'name' => $definition->name];
      }
    }
    return $choices;
  }

  /** @return list<string> */
  public function getProblems(BattleTestMember $member, Character $character): array
  {
    $problems = [];
    foreach ($member->skills as $name) {
      $skill = $this->skills->findSkill($name);
      if ($skill === null) {
        $problems[] = "the project has no skill {$name}.";
      } elseif (!$skill instanceof MagicSkill && !$skill instanceof SpecialSkill) {
        $problems[] = "{$name} is not a learnable ability or spell.";
      } elseif (!$this->isBattleUsable($skill)) {
        $problems[] = "{$name} cannot be used in battle.";
      } elseif (array_find($this->summons, static fn($definition): bool => $definition->linkedActionId === $name) !== null) {
        $problems[] = "{$name} is a summon action; select its summon instead.";
      }
    }
    foreach ($member->summons as $id) {
      $problem = $this->getSummonProblem($id, $character);
      if ($problem !== null) { $problems[] = $problem; }
    }
    return $problems;
  }

  /**
   * @param list<BattleTestMember> $members
   * @param array<string, Character> $characters
   * @return list<string>
   */
  public function getTenancyProblems(array $members, array $characters): array
  {
    $holders = [];
    foreach ($members as $index => $member) {
      foreach (array_unique([...($characters[$member->actorId]->summons ?? []), ...$member->summons]) as $id) {
        $holders[$id][] = $index + 1;
      }
    }
    $problems = [];
    foreach ($holders as $id => $places) {
      if (count($places) > 1 && ($this->summons[$id]->wielders?->isExclusive() ?? false)) {
        $problems[] = sprintf('Exclusive summon %s is assigned to members %s; choose one holder.', $id, implode(', ', $places));
      }
    }
    return $problems;
  }

  public function applyToCharacter(BattleTestMember $member, Character $character): void
  {
    foreach ($member->skills as $name) {
      $skill = $this->skills->findSkill($name);
      if ($skill !== null) { $character->learnSkill(clone $skill); }
    }
    foreach ($member->summons as $id) { $character->assignSummon($id); }
    if ($member->commands !== null || $member->summons !== [] || $member->skills !== []) {
      $character->battleCommandLoadout = new BattleCommandLoadout($member->commands, $member->summons, $member->skills);
    }
  }

  private function getSummonProblem(string $id, Character $character): ?string
  {
    $definition = $this->summons[$id] ?? null;
    if ($definition === null) { return "the project has no summon {$id}."; }
    $skill = $definition->linkedActionId === null ? null : $this->skills->findSkill($definition->linkedActionId);
    return match (true) {
      $skill === null => "summon {$id} has no valid linked action.",
      count(array_filter($this->summons, static fn($other): bool => $other->linkedActionId === $definition->linkedActionId)) > 1
        => "summon {$id} shares its linked action with another summon; the action must be unambiguous.",
      !$this->isBattleUsable($skill) => "summon {$id}'s linked action cannot be used in battle.",
      $definition->wielders !== null && (!$definition->wielders->isValid() || !$definition->wielders->allowsCharacter($character))
        => "this actor is not eligible to hold summon {$id}.",
      default => null,
    };
  }

  private function isBattleUsable(Skill $skill): bool
  {
    return in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true);
  }
}
