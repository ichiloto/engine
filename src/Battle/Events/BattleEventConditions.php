<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use InvalidArgumentException;

/**
 * The authored conditions of a troop event page. Every given condition must
 * hold for the page to run:
 *
 * - `turn` (1 or more) with optional `turnEvery` (1 or more): the page can
 *   run on turn `turn`, then every `turnEvery` turns after it.
 * - `enemy` (the troop member's index) with `enemyHpPercent` (0..100): that
 *   enemy is still fighting with HP at or below the share of its maximum.
 * - `actor` (an actor ID) with `actorHpPercent` (0..100): that party member
 *   is in the battle with HP at or below the share of its maximum.
 * - `world`: world conditions (switches, variables, story events and the
 *   rest WorldConditionEvaluator reads).
 *
 * @package Ichiloto\Engine\Battle\Events
 */
final readonly class BattleEventConditions
{
  public const array KEYS = ['turn', 'turnEvery', 'enemy', 'enemyHpPercent', 'actor', 'actorHpPercent', 'world'];

  /**
   * @param list<array<string, mixed>> $world The world conditions.
   */
  public function __construct(
    public ?int $turn = null,
    public ?int $turnEvery = null,
    public ?int $enemy = null,
    public ?int $enemyHpPercent = null,
    public ?string $actor = null,
    public ?int $actorHpPercent = null,
    public array $world = [],
  )
  {
  }

  /**
   * Reads and validates authored conditions.
   *
   * @param mixed $data The authored `conditions` entry.
   * @param string $where Where the conditions are, for messages.
   * @param int $memberCount The troop's authored member count.
   * @throws InvalidArgumentException When the conditions are malformed.
   */
  public static function fromArray(mixed $data, string $where, int $memberCount): self
  {
    if (! is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new InvalidArgumentException("{$where} must be a keyed array of conditions.");
    }

    $unknown = array_diff(array_keys($data), self::KEYS);

    if ($unknown !== []) {
      throw new InvalidArgumentException(sprintf('%s has unknown condition "%s"; use %s.',
        $where, strval(reset($unknown)), implode(', ', self::KEYS)));
    }

    $turn = self::readInteger($data, 'turn', $where, 1);
    $turnEvery = self::readInteger($data, 'turnEvery', $where, 1);

    if ($turnEvery !== null && $turn === null) {
      throw new InvalidArgumentException("{$where} gives turnEvery without the turn it counts from.");
    }

    $enemy = self::readInteger($data, 'enemy', $where, 0);
    $enemyHpPercent = self::readInteger($data, 'enemyHpPercent', $where, 0, 100);

    if (($enemy === null) !== ($enemyHpPercent === null)) {
      throw new InvalidArgumentException("{$where} needs both enemy and enemyHpPercent for an enemy HP condition.");
    }

    if ($enemy !== null && $enemy >= $memberCount) {
      throw new InvalidArgumentException(sprintf('%s names troop member %d, but the troop has %d.', $where, $enemy, $memberCount));
    }

    $actor = null;

    if (array_key_exists('actor', $data)) {
      if (! is_string($data['actor']) || trim($data['actor']) === '') {
        throw new InvalidArgumentException("{$where} field \"actor\" must be an actor ID.");
      }

      $actor = trim($data['actor']);
    }

    $actorHpPercent = self::readInteger($data, 'actorHpPercent', $where, 0, 100);

    if (($actor === null) !== ($actorHpPercent === null)) {
      throw new InvalidArgumentException("{$where} needs both actor and actorHpPercent for a party member HP condition.");
    }

    $world = $data['world'] ?? [];

    if (! is_array($world) || ! array_is_list($world)) {
      throw new InvalidArgumentException("{$where} field \"world\" must be a list of world conditions.");
    }

    WorldConditionEvaluator::validateAll($world, "{$where}.world");

    if ($turn === null && $enemy === null && $actor === null && $world === []) {
      throw new InvalidArgumentException("{$where} needs at least one condition, or the page could never run.");
    }

    return new self($turn, $turnEvery, $enemy, $enemyHpPercent, $actor, $actorHpPercent, array_values($world));
  }

  /** Whether every given condition holds at the check. */
  public function holdsAt(BattleEventCheck $check): bool
  {
    if ($this->turn !== null) {
      $since = $check->turn - $this->turn;

      if ($since < 0 || ($this->turnEvery === null ? $since !== 0 : $since % $this->turnEvery !== 0)) {
        return false;
      }
    }

    if ($this->enemy !== null) {
      $enemy = $check->members[$this->enemy] ?? null;

      if ($enemy === null || ! $enemy->isFighting
        || $enemy->stats->currentHp * 100 > $this->enemyHpPercent * $enemy->stats->totalHp) {
        return false;
      }
    }

    if ($this->actor !== null) {
      $member = $check->findPartyBattler($this->actor);

      if ($member === null || $member->stats->currentHp * 100 > $this->actorHpPercent * $member->stats->totalHp) {
        return false;
      }
    }

    return $this->world === [] || WorldConditionEvaluator::allHold($this->world, $check->worldState, $check->party);
  }

  /** @return array<string, mixed> The authored shape. */
  public function toArray(): array
  {
    return array_filter([
      'turn' => $this->turn,
      'turnEvery' => $this->turnEvery,
      'enemy' => $this->enemy,
      'enemyHpPercent' => $this->enemyHpPercent,
      'actor' => $this->actor,
      'actorHpPercent' => $this->actorHpPercent,
      'world' => $this->world === [] ? null : $this->world,
    ], static fn(mixed $value): bool => $value !== null);
  }

  private static function readInteger(array $data, string $key, string $where, int $minimum, ?int $maximum = null): ?int
  {
    if (! array_key_exists($key, $data)) {
      return null;
    }

    $value = $data[$key];

    if (! is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
      throw new InvalidArgumentException(sprintf('%s field "%s" must be a whole number %s.', $where, $key,
        $maximum === null ? "of at least {$minimum}" : "from {$minimum} to {$maximum}"));
    }

    return $value;
  }
}
