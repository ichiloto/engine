<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * One node of the project's mastery tree, which every character can reach:
 * what it is (its kind and place in its branch's chain), the skill it
 * teaches by catalogue name, and the terms anyone learns it on unless their
 * own map states others. It owns no copy of the skill.
 *
 * A foundation may teach a skill or nothing yet: what learning it grants is
 * authored, and an empty grant is not replaced by an invented one. A node without a price cannot be learned on the
 * tree's terms; a character whose map prices it can.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryNodeDefinition
{
  /** The keys a node's data may hold. */
  public const array KEYS = ['id', 'kind', 'skill', 'name', 'grade', 'phase', 'extends', 'cost', 'opensAtLevel', 'requires', 'hidden'];

  /**
   * @param string $id The node's identity, stable across renames and moves; saves record it.
   * @param MasteryNodeKind $kind What it is to its branch.
   * @param string $region The region it sits in.
   * @param string|null $branch The branch it sits on, or null on the region's trunk.
   * @param string|null $skill The skill it teaches, by catalogue name.
   * @param string|null $name Its name where the skill does not give one (a foundation).
   * @param int|null $grade Its rung on the branch's ladder of basic applications (1 for I).
   * @param MasteryPhase|null $phase A special grade's phase.
   * @param string|null $extends The node it builds on: a full phase's demi phase.
   * @param int|null $cost Its price in Mastery points (SP), or null when the tree prices it for nobody.
   * @param int|null $opensAtLevel The character level it opens at, or null when no level holds it back.
   * @param list<string> $requires Nodes that must be learned first besides its place in the chain.
   * @param bool $hidden Whether menus keep it a secret ("???") while it is locked.
   */
  public function __construct(
    public readonly string $id,
    public readonly MasteryNodeKind $kind,
    public readonly string $region,
    public readonly ?string $branch = null,
    public readonly ?string $skill = null,
    public readonly ?string $name = null,
    public readonly ?int $grade = null,
    public readonly ?MasteryPhase $phase = null,
    public readonly ?string $extends = null,
    public readonly ?int $cost = null,
    public readonly ?int $opensAtLevel = null,
    public readonly array $requires = [],
    public readonly bool $hidden = false,
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery node needs an id without surrounding spaces.');
    }
    $teaches = $kind !== MasteryNodeKind::FOUNDATION;
    if ($teaches && ($skill === null || trim($skill) === '')) {
      throw new InvalidArgumentException(sprintf('Mastery node %s is a %s and names no skill.', $id, $kind->value));
    }
    if ($skill === null && ($name === null || trim($name) === '')) {
      throw new InvalidArgumentException(sprintf('Mastery node %s teaches no skill, so it needs a name.', $id));
    }
    if ($grade !== null && ($kind !== MasteryNodeKind::APPLICATION || $grade < 1)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s: only an application has a grade, from 1 up.', $id));
    }
    if (($phase !== null) !== ($kind === MasteryNodeKind::SPECIAL)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s: a special grade has a phase (demi or full), and no other node does.', $id));
    }
    if (($phase === MasteryPhase::FULL) !== ($extends !== null)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s: a full phase extends its demi phase, and no other node extends one.', $id));
    }
    if ($branch === null && ($kind !== MasteryNodeKind::APPLICATION || $grade !== null)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s sits on its region\'s trunk, which holds only ungraded applications; place it on a branch.', $id));
    }
    if ($cost !== null && $cost < 0) {
      throw new InvalidArgumentException(sprintf('Mastery node %s cannot cost less than 0 points.', $id));
    }
    if ($opensAtLevel !== null && $opensAtLevel < 1) {
      throw new InvalidArgumentException(sprintf('Mastery node %s must open at level 1 or later.', $id));
    }
  }

  /** The name menus show: its skill's, unless it names itself. */
  public string $label {
    get => $this->name ?? $this->skill ?? $this->id;
  }

  /**
   * @param array<string, mixed> $data
   * @param string $region The region the node sits in.
   * @param string|null $branch The branch it sits on, or null on the region's trunk.
   * @throws InvalidArgumentException When the entry is malformed or holds a key nodes do not have.
   */
  public static function fromArray(array $data, string $region, ?string $branch = null): self
  {
    $id = strval($data['id'] ?? '');
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Mastery node %s has unknown key "%s".', $id, $key));
      }
    }
    foreach (['grade', 'cost', 'opensAtLevel'] as $key) {
      if (array_key_exists($key, $data) && ! is_int($data[$key])) {
        throw new InvalidArgumentException(sprintf('Mastery node %s %s must be a whole number.', $id, $key));
      }
    }
    foreach (['skill', 'name', 'extends'] as $key) {
      if (array_key_exists($key, $data) && ! is_string($data[$key])) {
        throw new InvalidArgumentException(sprintf('Mastery node %s %s must be text.', $id, $key));
      }
    }
    if (array_key_exists('hidden', $data) && ! is_bool($data['hidden'])) {
      throw new InvalidArgumentException(sprintf('Mastery node %s hidden must be true or false.', $id));
    }
    $kind = MasteryNodeKind::tryFrom(strval($data['kind'] ?? '')) ?? throw new InvalidArgumentException(sprintf(
      'Mastery node %s kind must be one of %s.', $id, implode(', ', array_column(MasteryNodeKind::cases(), 'value'))));
    $phase = null;
    if (array_key_exists('phase', $data)) {
      $phase = MasteryPhase::tryFrom(strval($data['phase'])) ?? throw new InvalidArgumentException(sprintf(
        'Mastery node %s phase must be one of %s.', $id, implode(', ', array_column(MasteryPhase::cases(), 'value'))));
    }

    return new self(
      $id,
      $kind,
      $region,
      $branch,
      $data['skill'] ?? null,
      $data['name'] ?? null,
      $data['grade'] ?? null,
      $phase,
      $data['extends'] ?? null,
      $data['cost'] ?? null,
      $data['opensAtLevel'] ?? null,
      self::readNodeIds($data['requires'] ?? [], $id),
      $data['hidden'] ?? false,
    );
  }

  /**
   * @return list<string>
   * @throws InvalidArgumentException When the value is not a list of node ids.
   */
  public static function readNodeIds(mixed $requires, string $id): array
  {
    if (! is_array($requires) || ! array_is_list($requires) || array_filter($requires, static fn(mixed $node): bool => ! is_string($node)) !== []) {
      throw new InvalidArgumentException(sprintf('Mastery node %s requires must list node ids.', $id));
    }

    return array_values($requires);
  }
}
