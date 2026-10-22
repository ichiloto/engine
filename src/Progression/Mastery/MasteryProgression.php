<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Debug;

/**
 * Moves a character along their Mastery Map: the one place where a node's
 * state is worked out, Mastery points (SP) are added and spent, and nodes
 * are learned so their skills join the character's ability book or
 * spellbook. Every menu, reward and save path uses it.
 *
 * Every character can reach every node of the tree. A node nobody can learn
 * yet (its skill still awaits author values, nothing prices it for this
 * character, or it is a foundation granting no skill that opens nothing
 * learnable) is unavailable: it never takes points and is never learned for
 * free. A foundation that grants no skill yet is learned for its place in
 * the chain alone, and counts as learned once the character has learned a
 * node that builds on it, since its branch's ladder starts there.
 *
 * Battles and quests add points to the character's total through
 * ExperienceAwarder. The player
 * spends them on any open node whose price the total covers, and the node
 * is learned at once. A node costing nothing is learned as soon as it opens.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryProgression
{
  public function __construct(private MasteryMap $map, private SkillCatalog $skills)
  {
  }

  /** The progression for a character's map in the running project, or null when the project has no mastery tree. */
  public static function forCharacter(Character $character): ?self
  {
    $map = MasteryCatalog::getProjectCatalog()->findMap($character->actorId);

    return $map === null ? null : new self($map, SkillCatalog::getProjectCatalog());
  }

  /** @return list<MasteryNodeStatus> Every node's state, in the order the map lists them. */
  public function describe(Character $character): array
  {
    return array_values(array_map(fn(MasteryNode $node): MasteryNodeStatus => $this->getStatus($character, $node), $this->map->nodes));
  }

  /**
   * Spends the node's price from the character's points and learns it.
   *
   * @throws MasteryRefusal When the node is unknown, already learned, still locked, or costs more than the character holds.
   */
  public function learn(Character $character, string $nodeId): MasteryAdvance
  {
    $node = $this->map->findNode($nodeId) ?? throw new MasteryRefusal(MasteryRefusalReason::UNKNOWN,
      sprintf('%s has no mastery node %s.', $character->name, $nodeId));
    $status = $this->getStatus($character, $node);
    match (true) {
      $status->state === MasteryNodeState::LEARNED => throw new MasteryRefusal(MasteryRefusalReason::LEARNED,
        sprintf('%s already knows %s.', $character->name, $node->skill)),
      $status->state === MasteryNodeState::UNAVAILABLE => throw new MasteryRefusal(MasteryRefusalReason::UNAVAILABLE,
        sprintf('Nobody can learn %s yet (%s).', $node->name, $status->unavailability?->value)),
      $status->state === MasteryNodeState::LOCKED => throw new MasteryRefusal(MasteryRefusalReason::LOCKED,
        sprintf('%s cannot learn %s yet.', $character->name, $node->name)),
      ! $status->affordable => throw new MasteryRefusal(MasteryRefusalReason::SHORT,
        sprintf('%s holds %d of the %d points %s costs.', $character->name, $character->mastery->points, $node->cost, $node->name)),
      default => null,
    };
    $skill = null;
    if ($node->skill !== null) {
      $skill = $this->skills->findSkill($node->skill) ?? throw new MasteryRefusal(MasteryRefusalReason::UNKNOWN,
        sprintf('%s\'s mastery node %s teaches unknown skill %s.', $character->name, $node->id, $node->skill));
    }
    $character->mastery->points -= (int)$node->cost;
    if ($skill !== null) {
      $character->learnSkill($skill);
    }
    $character->mastery->markLearned($node->id);

    // Learning a node may open free nodes that build on it.
    return new MasteryAdvance([...($skill === null ? [] : [$skill]), ...$this->reconcile($character)->learned]);
  }

  /**
   * Brings the map up to what the character knows and their level: skills
   * the character already knows count their nodes as learned, and free
   * nodes are learned as they open. Safe to repeat; it spends nothing, so
   * it also brings an old save up to its map.
   */
  public function reconcile(Character $character): MasteryAdvance
  {
    $mastery = $character->mastery;
    foreach ($this->map->nodes as $node) {
      if (! $mastery->hasLearned($node->id) && $node->skill !== null && $this->knows($character, $node->skill)) {
        $mastery->markLearned($node->id);
      }
    }
    $learned = [];
    do {
      $opened = $this->markKnownFoundations($character);
      foreach ($this->map->nodes as $node) {
        if ($node->cost === 0 && ! $mastery->hasLearned($node->id) && $this->findUnavailability($node) === null && $this->isOpen($character, $node)) {
          $skill = $node->skill === null ? null : $this->skills->findSkill($node->skill);
          if ($node->skill !== null && $skill === null) {
            Debug::warn(sprintf('%s\'s mastery node %s teaches unknown skill %s.', $character->name, $node->id, $node->skill));
            continue;
          }
          if ($skill !== null) {
            $character->learnSkill($skill);
            $learned[] = $skill;
          }
          $mastery->markLearned($node->id);
          $opened = true;
        }
      }
    } while ($opened);

    return new MasteryAdvance($learned);
  }

  /**
   * Counts as learned each foundation granting no skill that a learned node
   * builds on: no branch's ladder climbs from nothing.
   *
   * @return bool Whether any foundation was newly counted.
   */
  private function markKnownFoundations(Character $character): bool
  {
    $marked = false;
    foreach ($this->map->nodes as $node) {
      $step = $this->map->tree->findChainPredecessor($node->definition);
      if ($step !== null && $step->kind === MasteryNodeKind::FOUNDATION && $step->skill === null
        && $character->mastery->hasLearned($node->id) && ! $character->mastery->hasLearned($step->id)) {
        $character->mastery->markLearned($step->id);
        $marked = true;
      }
    }

    return $marked;
  }

  private function getStatus(Character $character, MasteryNode $node): MasteryNodeStatus
  {
    $affordable = $node->cost !== null && $character->mastery->points >= $node->cost;
    if ($character->mastery->hasLearned($node->id)) {
      return new MasteryNodeStatus($node, MasteryNodeState::LEARNED, $affordable);
    }
    $unavailability = $this->findUnavailability($node);
    if ($unavailability !== null) {
      return new MasteryNodeStatus($node, MasteryNodeState::UNAVAILABLE, false, unavailability: $unavailability);
    }
    $missing = array_values(array_filter($node->requires, static fn(string $required): bool => ! $character->mastery->hasLearned($required)));
    if ($character->level < $node->opensAtLevel || $missing !== []) {
      return new MasteryNodeStatus($node, MasteryNodeState::LOCKED, $affordable,
        $character->level < $node->opensAtLevel ? $node->opensAtLevel : null, $missing);
    }

    return new MasteryNodeStatus($node, MasteryNodeState::OPEN, $affordable);
  }

  /** Why nobody can learn a node yet, or null when it can be learned on its terms. */
  private function findUnavailability(MasteryNode $node): ?MasteryUnavailability
  {
    return match (true) {
      $node->skill !== null && $this->skills->findSkill($node->skill) === null && $this->skills->findDraft($node->skill) !== null
        => MasteryUnavailability::AWAITING_VALUES,
      $node->cost === null => MasteryUnavailability::UNPRICED,
      $node->kind === MasteryNodeKind::FOUNDATION && $node->skill === null && ! $this->opensLearnableNode($node) => MasteryUnavailability::NOTHING_TO_OPEN,
      default => null,
    };
  }

  /** Whether a node that builds on a foundation, on this character's terms, can be learned. */
  private function opensLearnableNode(MasteryNode $foundation): bool
  {
    return array_any($this->map->nodes, fn(MasteryNode $node): bool => $node->kind !== MasteryNodeKind::FOUNDATION
      && in_array($foundation->id, $node->requires, true) && $this->findUnavailability($node) === null);
  }

  private function isOpen(Character $character, MasteryNode $node): bool
  {
    return $character->level >= $node->opensAtLevel
      && array_all($node->requires, static fn(string $required): bool => $character->mastery->hasLearned($required));
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
