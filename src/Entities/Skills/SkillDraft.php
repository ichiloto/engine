<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\ItemScope;
use InvalidArgumentException;

/**
 * A skill record that is not finished: its identity, kind, reach and every
 * value its author has given, with the values not yet assigned listed in
 * the record's `unassigned` key and left out of it. A value is unassigned
 * while its author has not decided it, or while the effect it needs does
 * not exist yet. The Engine never guesses them: a draft is in the
 * catalogue's identity space,
 * so its name is taken and the mastery tree can place it, but nobody can
 * learn, cast or be given it until its author assigns the values and
 * removes the key.
 *
 * Only these values can await an author: the MP cost (`cost`), when the
 * skill can be used (`occasion`), whom it reaches (`scope`), all of its
 * effects (`effects`), and one effect's amount (`effects.<n>.formula`) or
 * spread (`effects.<n>.variance`). An unassigned spread is never read as the
 * Engine's default spread.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
final readonly class SkillDraft
{
  /** The record key that lists the values awaiting the author. */
  public const string KEY = 'unassigned';

  /** The values that can await an author; `<n>` is an effect's place in the list. */
  public const array UNASSIGNABLE = ['cost', 'occasion', 'scope', 'effects', 'effects.<n>.formula', 'effects.<n>.variance'];

  /** What reading the rest of a draft stands each unassigned value in with, to check the rest; a stand-in is never shown or used. */
  private const array STAND_INS = ['cost' => 0, 'occasion' => 'Never', 'scope' => ['side' => 'User', 'number' => 'One', 'status' => 'Alive'],
    'effects' => [], 'formula' => '0'];

  /**
   * @param string $name The skill's name, which no other skill may take.
   * @param string $kind The record's kind (basic, special or magic).
   * @param list<string> $unassigned The values awaiting the author, as record paths.
   * @param array<string, mixed> $data The record's data as authored.
   * @param Skill $reading The record read with each unassigned value stood in, kept only to read the values the author
   *   has given; never learned, cast or given.
   */
  private function __construct(
    public string $name,
    public string $kind,
    public array $unassigned,
    public array $data,
    private Skill $reading,
  )
  {
  }

  /** Its MP cost as the author gives it, or null while the cost awaits the author. */
  public function getCost(): ?int
  {
    return $this->isAssigned('cost') ? $this->reading->cost : null;
  }

  /** Whom it reaches as the author gives it, or null while that awaits the author. */
  public function getScope(): ?ItemScope
  {
    return $this->isAssigned('scope') ? $this->reading->scope : null;
  }

  /** Its description as the author gives it. */
  public function getDescription(): string
  {
    return $this->reading->description;
  }

  /** Whether the author has given a value (a record path such as `cost`), so menus may show it. */
  public function isAssigned(string $path): bool
  {
    return ! in_array($path, $this->unassigned, true);
  }

  /** Whether a record's data is a draft. */
  public static function isDraft(array $data): bool
  {
    return array_key_exists(self::KEY, $data);
  }

  /**
   * Reads a draft, checking every value its author has given the way a
   * finished record is checked.
   *
   * @param array<string, mixed> $data The record's data, with its `unassigned` list.
   * @throws InvalidArgumentException When the list names a value that cannot await an author, a value it names is
   *   still given, or the rest of the record is not a valid skill record.
   */
  public static function fromArray(array $data): self
  {
    $unassigned = $data[self::KEY] ?? null;
    if (! is_array($unassigned) || $unassigned === [] || ! array_is_list($unassigned)
      || array_filter($unassigned, static fn(mixed $path): bool => ! is_string($path)) !== []) {
      throw new InvalidArgumentException(sprintf('%s must list the values that await the author: %s.', self::KEY, implode(', ', self::UNASSIGNABLE)));
    }
    $complete = $data;
    unset($complete[self::KEY]);
    foreach ($unassigned as $path) {
      $complete = self::standIn($complete, $path);
    }
    // Geometry may already be authored while scope/occasion await the author.
    // These values exist only in the private validation reading, never in draft data.
    $targeting = \Ichiloto\Engine\Battle\Formation\BattleTargeting::fromArray($data['battleTargeting'] ?? null);
    if ($targeting !== null) {
      if (in_array('occasion', $unassigned, true)) {
        $complete['occasion'] = \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN->value;
      }
      if (in_array('scope', $unassigned, true)) {
        $complete['scope']['number'] = in_array($targeting->shape, [\Ichiloto\Engine\Battle\Formation\BattleTargetShape::FRONT_ROW,
          \Ichiloto\Engine\Battle\Formation\BattleTargetShape::BACK_ROW], true) ? 'All' : 'One';
      }
    }
    $reading = SkillRecord::readSkill($complete);

    return new self(strval($data['name']), strval($data['kind']), array_values(array_unique($unassigned)), $data, $reading);
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private static function standIn(array $data, string $path): array
  {
    if (in_array($path, ['cost', 'occasion', 'scope', 'effects'], true)) {
      if (array_key_exists($path, $data)) {
        throw new InvalidArgumentException(sprintf('%s is listed as awaiting the author, so the record must leave it out.', $path));
      }
      $data[$path] = self::STAND_INS[$path];

      return $data;
    }
    if (preg_match('/^effects\.(\d+)\.(formula|variance)$/', $path, $match) === 1) {
      [, $index, $key] = $match;
      $index = intval($index);
      if (! is_array($data['effects'][$index] ?? null)) {
        throw new InvalidArgumentException(sprintf('%s names an effect the record does not have.', $path));
      }
      if (array_key_exists($key, $data['effects'][$index])) {
        throw new InvalidArgumentException(sprintf('%s is listed as awaiting the author, so the effect must leave it out.', $path));
      }
      if ($key === 'formula') {
        $data['effects'][$index]['formula'] = self::STAND_INS['formula'];
      }

      return $data;
    }

    throw new InvalidArgumentException(sprintf('%s cannot await the author; only %s can.', $path, implode(', ', self::UNASSIGNABLE)));
  }
}
