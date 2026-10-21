<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * Moves a character along their Mastery Map: the one place where a node's
 * state is worked out, EXP goes into the node being studied, nodes are
 * learned and their skills join the character's ability book or spellbook.
 * Every menu, reward and save path uses it.
 *
 * The character studies the node the player chose, or else the first open
 * node in the default order. EXP earned while no node is open is banked and
 * goes into the next node that opens. A node costing nothing is learned as
 * soon as it opens.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryProgression
{
  public function __construct(private MasteryMap $map, private SkillCatalog $skills)
  {
  }

  /** The progression for a character's map in the running project, or null when they have none. */
  public static function forCharacter(Character $character): ?self
  {
    $map = MasteryCatalog::getProjectCatalog()->findMap($character->actorId);

    return $map === null ? null : new self($map, SkillCatalog::getProjectCatalog());
  }

  /** @return list<MasteryNodeStatus> Every node's state, in default study order. */
  public function describe(Character $character): array
  {
    $target = $this->findTarget($character);

    return array_values(array_map(fn(MasteryNode $node): MasteryNodeStatus => $this->getStatus($character, $node, $target), $this->map->nodes));
  }

  /**
   * Makes an open node the one the character studies; its progress is kept, as is every other node's.
   *
   * @throws InvalidArgumentException When the node is unknown, learned or still locked.
   */
  public function choose(Character $character, string $nodeId): void
  {
    $node = $this->map->findNode($nodeId) ?? throw new InvalidArgumentException(sprintf('%s has no mastery node %s.', $character->name, $nodeId));
    if ($character->mastery->hasLearned($node->id)) {
      throw new InvalidArgumentException(sprintf('%s already knows %s.', $character->name, $node->skill));
    }
    if (! $this->isOpen($character, $node)) {
      throw new InvalidArgumentException(sprintf('%s cannot study %s yet.', $character->name, $node->skill));
    }
    $character->mastery->studying = $node->id;
    // EXP banked while nothing was open goes into what is now studied.
    $this->advance($character, 0);
  }

  /**
   * Puts EXP into the studied node, learning every node it completes and
   * moving on to the next; what is left when no node is open is banked.
   * Skills the character already knows count their nodes as learned, and
   * free nodes are learned as they open, so this also brings an old save
   * up to its map.
   */
  public function advance(Character $character, int $experience): MasteryAdvance
  {
    $mastery = $character->mastery;
    $learned = [];
    foreach ($this->map->nodes as $node) {
      if (! $mastery->hasLearned($node->id) && $this->knows($character, $node->skill)) {
        $mastery->markLearned($node->id);
      }
    }
    $pool = $mastery->banked + max(0, $experience);
    $mastery->banked = 0;

    while (true) {
      foreach ($this->map->nodes as $node) {
        if ($node->cost === 0 && ! $mastery->hasLearned($node->id) && $this->isOpen($character, $node)
          && ($skill = $this->learn($character, $node)) !== null) {
          $learned[] = $skill;
          continue 2;
        }
      }
      $target = $this->findTarget($character);
      if ($target === null) {
        $mastery->banked = $pool;
        break;
      }
      $need = $target->cost - $mastery->getProgress($target->id);
      if ($pool < $need) {
        $mastery->progress[$target->id] = $mastery->getProgress($target->id) + $pool;
        break;
      }
      $pool -= $need;
      $skill = $this->learn($character, $target);
      if ($skill === null) {
        $mastery->banked = $pool;
        break;
      }
      $learned[] = $skill;
    }

    return new MasteryAdvance($learned, $this->findTarget($character)?->id);
  }

  private function getStatus(Character $character, MasteryNode $node, ?MasteryNode $target): MasteryNodeStatus
  {
    $progress = $character->mastery->getProgress($node->id);
    if ($character->mastery->hasLearned($node->id)) {
      return new MasteryNodeStatus($node, MasteryNodeState::LEARNED, $node->cost);
    }
    $missing = array_values(array_filter($node->requires, static fn(string $required): bool => ! $character->mastery->hasLearned($required)));
    if ($character->level < $node->opensAtLevel || $missing !== []) {
      return new MasteryNodeStatus($node, MasteryNodeState::LOCKED, $progress,
        $character->level < $node->opensAtLevel ? $node->opensAtLevel : null, $missing);
    }

    return new MasteryNodeStatus($node, $target === $node ? MasteryNodeState::STUDYING : MasteryNodeState::OPEN, $progress);
  }

  /** The node EXP goes into: the one chosen while it stays open, else the first open node in default order. */
  private function findTarget(Character $character): ?MasteryNode
  {
    $chosen = $character->mastery->studying === null ? null : $this->map->findNode($character->mastery->studying);
    if ($chosen !== null && ! $character->mastery->hasLearned($chosen->id) && $this->isOpen($character, $chosen)) {
      return $chosen;
    }

    return array_find($this->map->nodes, fn(MasteryNode $node): bool =>
      ! $character->mastery->hasLearned($node->id) && $this->isOpen($character, $node) && $this->skills->findSkill($node->skill) !== null);
  }

  private function isOpen(Character $character, MasteryNode $node): bool
  {
    return $character->level >= $node->opensAtLevel
      && array_all($node->requires, static fn(string $required): bool => $character->mastery->hasLearned($required));
  }

  private function learn(Character $character, MasteryNode $node): ?Skill
  {
    $skill = $this->skills->findSkill($node->skill);
    if ($skill === null) {
      Debug::warn(sprintf('%s\'s mastery node %s teaches unknown skill %s.', $character->name, $node->id, $node->skill));

      return null;
    }
    $character->learnSkill($skill);
    $character->mastery->markLearned($node->id);

    return $skill;
  }

  private function knows(Character $character, string $skill): bool
  {
    foreach ([...$character->abilityBook->getLearnedAbilities(), ...$character->spellbook->getLearnedSpells()] as $known) {
      if ($known->name === $skill) {
        return true;
      }
    }

    return false;
  }
}
