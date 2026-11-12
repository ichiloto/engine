<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use InvalidArgumentException;

/**
 * The HP or MP a battle test member starts a fight with: a percentage of
 * its maximum or exact points. Project data writes it as `['percent' => 9]`
 * or `['points' => 29]`; typed, as on the command line and in the arena's
 * choices, it is `9%` or `29`.
 */
final class BattleTestVital
{
  public function __construct(public readonly int $amount, public readonly BattleTestVitalUnit $unit = BattleTestVitalUnit::PERCENT)
  {
    if ($amount < 0 || ($unit === BattleTestVitalUnit::PERCENT && $amount > 100)) {
      throw new InvalidArgumentException('A battle test member starts with 0 to 100 percent, or 0 or more points.');
    }
  }

  /** Whether this is the whole maximum, which a member starts with when nothing is set. */
  public bool $isFull {
    get => $this->unit === BattleTestVitalUnit::PERCENT && $this->amount === 100;
  }

  /**
   * As project data writes it: `['percent' => int]` or `['points' => int]`.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a starting amount.
   */
  public static function fromArray(array $data): self
  {
    $unit = count($data) === 1 ? BattleTestVitalUnit::tryFrom(strval(array_key_first($data))) : null;
    $amount = $unit === null ? null : $data[$unit->value];
    if ($unit === null || !is_int($amount)) {
      throw new InvalidArgumentException('A starting HP or MP is written as percent or points, a whole number.');
    }

    return new self($amount, $unit);
  }

  /** @return array<string, int> */
  public function toArray(): array
  {
    return [$this->unit->value => $this->amount];
  }

  /**
   * As typed: `9%` for a percentage, `29` for points.
   *
   * @throws InvalidArgumentException When the text is neither.
   */
  public static function parse(string $text): self
  {
    if (preg_match('/\A\s*([0-9]+)\s*(%?)\s*\z/', $text, $match) !== 1) {
      throw new InvalidArgumentException(sprintf('"%s" is not a starting amount; write a percentage such as 9%% or points such as 29.', $text));
    }

    return new self((int) $match[1], $match[2] === '%' ? BattleTestVitalUnit::PERCENT : BattleTestVitalUnit::POINTS);
  }

  /** The amount as typed: `9%` or `29`. */
  public function format(): string
  {
    return $this->amount . ($this->unit === BattleTestVitalUnit::PERCENT ? '%' : '');
  }

  /**
   * The points this amount comes to for a maximum. A percentage rounds down
   * and leaves at least 1 point unless it is 0; points are as given.
   */
  public function resolvePoints(int $maximum): int
  {
    if ($this->unit === BattleTestVitalUnit::POINTS) {
      return $this->amount;
    }

    return $this->amount === 0 || $maximum <= 0 ? 0 : max(1, intdiv($maximum * $this->amount, 100));
  }

  /** The same amount, counted in the other unit for a maximum: whole percent rounds down. */
  public function convert(int $maximum): self
  {
    return $this->unit === BattleTestVitalUnit::PERCENT
      ? new self($this->resolvePoints($maximum), BattleTestVitalUnit::POINTS)
      : new self($maximum <= 0 ? 0 : min(100, intdiv(min($this->amount, $maximum) * 100, $maximum)));
  }

  /** Another amount in the same unit, kept within 0 and the unit's limit (100 percent, or the maximum's points). */
  public function withAmount(int $amount, int $maximum): self
  {
    $limit = $this->unit === BattleTestVitalUnit::PERCENT ? 100 : max(0, $maximum);

    return new self(max(0, min($limit, $amount)), $this->unit);
  }

  /** The whole maximum, as a percentage. */
  public static function createFull(): self
  {
    return new self(100);
  }
}
