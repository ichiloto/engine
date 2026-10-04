<?php

namespace Ichiloto\Engine\Entities\Enemies;

use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\DropItem;
use Ichiloto\Engine\Core\Range;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\StatKey;
use InvalidArgumentException;

/**
 * The data form of an enemy, as one record file authors it.
 *
 * A record holds plain values only. Shared pieces are references into their
 * own databases: an action pattern names its skill in the project's skill
 * catalogue, and a drop names its item in the item store. Stats are keyed by
 * the shared stat vocabulary ({@see StatKey}), every one of them required, so
 * a record never depends on a hidden default.
 *
 * Writing a record leaves out values equal to what reading would assume
 * (an "always" condition, an empty drop list, the origin position), so a
 * record states only what its author chose.
 *
 * @package Ichiloto\Engine\Entities\Enemies
 */
final class EnemyRecord
{
  /** The keys a record's data may hold. */
  public const array KEYS = [
    'name', 'level', 'imagePath', 'stats', 'rewards', 'actionPatterns', 'position',
    'battleAnimation', 'stateResistances', 'elementAffinities', 'knowledgeSubjectId',
  ];

  /**
   * Builds an enemy from its record data.
   *
   * @param array<string, mixed> $data The record's data.
   * @param SkillCatalog $skills The catalogue its action patterns name skills in.
   * @return Enemy The enemy.
   * @throws InvalidArgumentException If the data is not a valid enemy record.
   */
  public static function readEnemy(array $data, SkillCatalog $skills): Enemy
  {
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Unknown enemy record key "%s".', $key));
      }
    }

    $name = self::requireString($data, 'name');
    $rewards = self::requireArray($data, 'rewards');

    return new Enemy(
      $name,
      self::requireInt($data, 'level'),
      self::readStats(self::requireArray($data, 'stats')),
      self::requireString($data, 'imagePath'),
      new BattleRewards(
        self::requireInt($rewards, 'experience', 'rewards.'),
        self::requireInt($rewards, 'gold', 'rewards.'),
        self::readDrops($rewards['items'] ?? []),
      ),
      self::readActionPatterns($data['actionPatterns'] ?? [], $skills),
      self::readPosition($data['position'] ?? null),
      self::readBattleAnimation($data['battleAnimation'] ?? null),
      self::readMultipliers($data['stateResistances'] ?? [], 'stateResistances'),
      self::readMultipliers($data['elementAffinities'] ?? [], 'elementAffinities'),
      self::readOptionalString($data['knowledgeSubjectId'] ?? null, 'knowledgeSubjectId'),
    );
  }

  /**
   * Returns an enemy's record data.
   *
   * @param Enemy $enemy The enemy.
   * @return array<string, mixed> The record's data.
   */
  public static function writeEnemy(Enemy $enemy): array
  {
    $data = [
      'name' => $enemy->name,
      'level' => $enemy->level,
      'imagePath' => $enemy->imagePath,
      'stats' => self::writeStats($enemy->stats),
      'rewards' => self::writeRewards($enemy->rewards),
    ];

    if ($enemy->actionPatterns !== []) {
      $data['actionPatterns'] = array_map(self::writeActionPattern(...), $enemy->actionPatterns);
    }

    if ($enemy->position->x !== 0.0 || $enemy->position->y !== 0.0) {
      $data['position'] = [$enemy->position->x, $enemy->position->y];
    }

    if ($enemy->battleAnimation !== null) {
      $data['battleAnimation'] = $enemy->battleAnimation;
    }

    if ($enemy->stateResistances !== []) {
      $data['stateResistances'] = $enemy->stateResistances;
    }

    if ($enemy->elementAffinities !== []) {
      $affinities = [];

      foreach ($enemy->elementAffinities as $element => $multiplier) {
        $affinities[ElementRegistry::canonicalize($element)] = $multiplier;
      }

      $data['elementAffinities'] = $affinities;
    }

    if ($enemy->knowledgeSubjectId !== null) {
      $data['knowledgeSubjectId'] = $enemy->knowledgeSubjectId;
    }

    return $data;
  }

  /**
   * @param array<string, mixed> $stats
   */
  private static function readStats(array $stats): Stats
  {
    $values = [];

    foreach ($stats as $key => $value) {
      $statKey = StatKey::tryFrom(strval($key))
        ?? throw new InvalidArgumentException(sprintf('Unknown stat "%s" in stats.', $key));

      if (! is_int($value)) {
        throw new InvalidArgumentException(sprintf('stats.%s must be a whole number.', $statKey->value));
      }

      $values[$statKey->value] = $value;
    }

    foreach (StatKey::cases() as $statKey) {
      if (! array_key_exists($statKey->value, $values)) {
        throw new InvalidArgumentException(sprintf('stats.%s is required.', $statKey->value));
      }
    }

    return new Stats(
      currentHp: $values[StatKey::MAX_HP->value],
      currentMp: $values[StatKey::MAX_MP->value],
      attack: $values[StatKey::ATTACK->value],
      defence: $values[StatKey::DEFENCE->value],
      magicAttack: $values[StatKey::MAGIC_ATTACK->value],
      magicDefence: $values[StatKey::MAGIC_DEFENCE->value],
      speed: $values[StatKey::SPEED->value],
      grace: $values[StatKey::GRACE->value],
      evasion: $values[StatKey::EVASION->value],
    );
  }

  /**
   * @return array<string, int>
   */
  private static function writeStats(Stats $stats): array
  {
    return [
      StatKey::MAX_HP->value => $stats->totalHp,
      StatKey::MAX_MP->value => $stats->totalMp,
      StatKey::ATTACK->value => $stats->attack,
      StatKey::DEFENCE->value => $stats->defence,
      StatKey::MAGIC_ATTACK->value => $stats->magicAttack,
      StatKey::MAGIC_DEFENCE->value => $stats->magicDefence,
      StatKey::SPEED->value => $stats->speed,
      StatKey::GRACE->value => $stats->grace,
      StatKey::EVASION->value => $stats->evasion,
    ];
  }

  /**
   * @return list<array{item: string, rate: float}>
   */
  private static function readDrops(mixed $items): array
  {
    if (! is_array($items) || ! array_is_list($items)) {
      throw new InvalidArgumentException('rewards.items must be a list.');
    }

    $drops = [];

    foreach ($items as $index => $item) {
      if (! is_array($item) || ! is_string($item['item'] ?? null) || ! is_numeric($item['rate'] ?? null)) {
        throw new InvalidArgumentException(sprintf('rewards.items.%d needs an item name and a numeric rate.', $index));
      }

      $drops[] = ['item' => $item['item'], 'rate' => floatval($item['rate'])];
    }

    return $drops;
  }

  /**
   * @return array<string, mixed>
   */
  private static function writeRewards(BattleRewards $rewards): array
  {
    $data = ['experience' => $rewards->experience, 'gold' => $rewards->gold];
    $items = array_map(
      static fn(DropItem $drop): array => ['item' => $drop->item->id, 'rate' => $drop->dropRate],
      $rewards->items,
    );

    if ($items !== []) {
      $data['items'] = $items;
    }

    return $data;
  }

  /**
   * @return list<ActionPattern>
   */
  private static function readActionPatterns(mixed $patterns, SkillCatalog $skills): array
  {
    if (! is_array($patterns) || ! array_is_list($patterns)) {
      throw new InvalidArgumentException('actionPatterns must be a list.');
    }

    $actionPatterns = [];

    foreach ($patterns as $index => $pattern) {
      $path = sprintf('actionPatterns.%d.', $index);

      if (! is_array($pattern)) {
        throw new InvalidArgumentException(sprintf('%s must be a pattern.', rtrim($path, '.')));
      }

      $skillName = self::requireString($pattern, 'skill', $path);
      $skill = $skills->findSkill($skillName)
        ?? throw new InvalidArgumentException(sprintf('%sskill names "%s", which the skill catalogue does not define.', $path, $skillName));

      $actionPatterns[] = new ActionPattern(
        $skill,
        self::requireInt($pattern, 'rating', $path),
        self::readCondition($pattern['condition'] ?? [], $path . 'condition.'),
      );
    }

    return $actionPatterns;
  }

  /**
   * @return array<string, mixed>
   */
  private static function writeActionPattern(ActionPattern $pattern): array
  {
    $data = ['skill' => $pattern->skill->name, 'rating' => $pattern->rating];
    $condition = self::writeCondition($pattern->condition);

    if ($condition !== []) {
      $data['condition'] = $condition;
    }

    return $data;
  }

  private static function readCondition(mixed $condition, string $path): ActionCondition
  {
    if (! is_array($condition)) {
      throw new InvalidArgumentException(sprintf('%s must be a condition.', rtrim($path, '.')));
    }

    $default = new ActionCondition();
    $type = $default->type;

    if (array_key_exists('type', $condition)) {
      $type = ActionConditionType::tryFrom(strval($condition['type']))
        ?? throw new InvalidArgumentException(sprintf('%stype "%s" is not a condition type.', $path, strval($condition['type'])));
    }

    $range = $default->range;

    if (array_key_exists('range', $condition)) {
      $bounds = $condition['range'];

      if (! is_array($bounds) || ! array_is_list($bounds) || count($bounds) !== 2
        || ! is_numeric($bounds[0]) || ! is_numeric($bounds[1])) {
        throw new InvalidArgumentException(sprintf('%srange must be [minimum, maximum].', $path));
      }

      $range = new Range($bounds[0] + 0, $bounds[1] + 0);
    }

    return new ActionCondition(
      $type,
      $range,
      array_key_exists('a', $condition) ? self::requireInt($condition, 'a', $path) : $default->a,
      array_key_exists('b', $condition) ? self::requireInt($condition, 'b', $path) : $default->b,
      $condition['status'] ?? $default->status,
      array_key_exists('partyLevel', $condition) ? self::requireInt($condition, 'partyLevel', $path) : $default->partyLevel,
    );
  }

  /**
   * @return array<string, mixed> Only the values that differ from an "always" condition.
   */
  private static function writeCondition(ActionCondition $condition): array
  {
    $default = new ActionCondition();
    $data = [];

    if ($condition->type !== $default->type) {
      $data['type'] = $condition->type->value;
    }

    if ($condition->range->min !== $default->range->min || $condition->range->max !== $default->range->max) {
      $data['range'] = [$condition->range->min, $condition->range->max];
    }

    foreach (['a', 'b', 'status', 'partyLevel'] as $key) {
      if ($condition->$key !== $default->$key) {
        $data[$key] = $condition->$key;
      }
    }

    return $data;
  }

  private static function readPosition(mixed $position): Vector2
  {
    if ($position === null) {
      return new Vector2();
    }

    if (! is_array($position) || ! array_is_list($position) || count($position) !== 2
      || ! is_numeric($position[0]) || ! is_numeric($position[1])) {
      throw new InvalidArgumentException('position must be [x, y].');
    }

    return new Vector2(floatval($position[0]), floatval($position[1]));
  }

  private static function readBattleAnimation(mixed $animation): int|string|null
  {
    if ($animation !== null && ! is_int($animation) && ! is_string($animation)) {
      throw new InvalidArgumentException('battleAnimation must be an animation id or name.');
    }

    return $animation;
  }

  /**
   * @return array<string, float>
   */
  private static function readMultipliers(mixed $multipliers, string $key): array
  {
    if (! is_array($multipliers)) {
      throw new InvalidArgumentException(sprintf('%s must map names to multipliers.', $key));
    }

    foreach ($multipliers as $name => $multiplier) {
      if (! is_string($name) || ! is_numeric($multiplier)) {
        throw new InvalidArgumentException(sprintf('%s must map names to multipliers.', $key));
      }
    }

    return $multipliers;
  }

  private static function readOptionalString(mixed $value, string $key): ?string
  {
    if ($value !== null && ! is_string($value)) {
      throw new InvalidArgumentException(sprintf('%s must be text.', $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   */
  private static function requireString(array $data, string $key, string $path = ''): string
  {
    $value = $data[$key] ?? null;

    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException(sprintf('%s%s is required text.', $path, $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   */
  private static function requireInt(array $data, string $key, string $path = ''): int
  {
    $value = $data[$key] ?? null;

    if (! is_int($value)) {
      throw new InvalidArgumentException(sprintf('%s%s must be a whole number.', $path, $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private static function requireArray(array $data, string $key): array
  {
    $value = $data[$key] ?? null;

    if (! is_array($value)) {
      throw new InvalidArgumentException(sprintf('%s is required.', $key));
    }

    return $value;
  }
}
