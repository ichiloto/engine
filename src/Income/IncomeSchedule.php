<?php

namespace Ichiloto\Engine\Income;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use InvalidArgumentException;

/**
 * One project-authored income schedule: what advances it, what one period
 * pays, where the pay goes and while which world conditions it runs.
 *
 * @package Ichiloto\Engine\Income
 */
final readonly class IncomeSchedule
{
  /** Seconds without input after which active play stops counting, when a schedule names none. */
  public const int DEFAULT_IDLE_TIMEOUT = 120;
  /** The authored fields, in the order editors present them. */
  public const array FIELDS = ['id', 'name', 'conditions', 'cadence', 'interval', 'idleTimeout', 'amount', 'rates', 'delivery'];

  /**
   * @param string $id Stable identity; saves and script commands name it.
   * @param string $name What the player sees as the notice title.
   * @param IncomeCadence $cadence What advances the schedule.
   * @param int|null $interval Seconds, steps or won battles per period; null for the event cadence.
   * @param int $idleTimeout Seconds without input before active play stops counting.
   * @param int $amount The pay per period when no rate holds.
   * @param IncomeRate[] $rates The pay table; the first row that holds sets the pay.
   * @param IncomeDelivery $delivery Where completed pay goes.
   * @param array<int, array<string, mixed>> $conditions While these hold the schedule runs.
   */
  public function __construct(
    public string $id,
    public string $name,
    public IncomeCadence $cadence,
    public ?int $interval,
    public int $idleTimeout,
    public int $amount,
    public array $rates,
    public IncomeDelivery $delivery,
    public array $conditions,
  ) {}

  /**
   * Reads and validates one authored schedule.
   *
   * @param mixed $entry The authored record.
   * @param string $where Where it was authored, for error messages.
   * @return self The schedule.
   * @throws InvalidArgumentException When the record is invalid.
   */
  public static function fromArray(mixed $entry, string $where): self
  {
    if (! is_array($entry)) {
      throw new InvalidArgumentException("$where must be an array.");
    }
    $id = $entry['id'] ?? null;
    if (! is_string($id) || trim($id) === '') {
      throw new InvalidArgumentException("$where needs a non-empty id.");
    }
    $where .= ' (' . trim($id) . ')';
    foreach (array_keys($entry) as $key) {
      if (! in_array($key, self::FIELDS, true)) {
        throw new InvalidArgumentException("$where has unknown field '$key'.");
      }
    }
    $name = $entry['name'] ?? null;
    if (! is_string($name) || trim($name) === '') {
      throw new InvalidArgumentException("$where needs a non-empty name.");
    }
    $cadenceValue = $entry['cadence'] ?? null;
    $cadence = is_string($cadenceValue) ? IncomeCadence::tryFrom(trim($cadenceValue)) : null;
    if ($cadence === null) {
      throw new InvalidArgumentException(sprintf(
        '%s cadence must be one of %s.', $where, implode(', ', IncomeCadence::values())));
    }
    $interval = $entry['interval'] ?? null;
    if ($cadence->isCounted()) {
      if (! is_int($interval) || $interval < 1) {
        throw new InvalidArgumentException(sprintf(
          '%s interval must be a positive whole number of %s.', $where, self::describeUnit($cadence)));
      }
    } elseif (array_key_exists('interval', $entry)) {
      throw new InvalidArgumentException("$where interval does not apply to the event cadence; payday pays it.");
    }
    $idleTimeout = $entry['idleTimeout'] ?? self::DEFAULT_IDLE_TIMEOUT;
    if (array_key_exists('idleTimeout', $entry) && $cadence !== IncomeCadence::ACTIVE_TIME) {
      throw new InvalidArgumentException("$where idleTimeout applies only to the active_time cadence.");
    }
    if (! is_int($idleTimeout) || $idleTimeout < 1) {
      throw new InvalidArgumentException("$where idleTimeout must be a positive whole number of seconds.");
    }
    $amount = $entry['amount'] ?? null;
    if (! is_int($amount) || $amount < 0) {
      throw new InvalidArgumentException("$where amount must be a whole number of at least 0.");
    }
    $deliveryValue = $entry['delivery'] ?? IncomeDelivery::DEPOSIT->value;
    $delivery = is_string($deliveryValue) ? IncomeDelivery::tryFrom(trim($deliveryValue)) : null;
    if ($delivery === null) {
      throw new InvalidArgumentException(sprintf(
        '%s delivery must be one of %s.', $where, implode(', ', IncomeDelivery::values())));
    }
    $conditions = $entry['conditions'] ?? [];
    if (! is_array($conditions) || ! array_is_list($conditions)) {
      throw new InvalidArgumentException("$where conditions must be a list.");
    }
    WorldConditionEvaluator::validateAll($conditions, "$where conditions");

    $rates = $entry['rates'] ?? [];
    if (! is_array($rates) || ! array_is_list($rates)) {
      throw new InvalidArgumentException("$where rates must be a list.");
    }
    $readRates = [];
    foreach ($rates as $index => $rate) {
      $rateWhere = "$where rate [$index]";
      if (! is_array($rate)) {
        throw new InvalidArgumentException("$rateWhere must be an array.");
      }
      foreach (array_keys($rate) as $key) {
        if (! in_array($key, ['conditions', 'amount'], true)) {
          throw new InvalidArgumentException("$rateWhere has unknown field '$key'.");
        }
      }
      $rateConditions = $rate['conditions'] ?? null;
      if (! is_array($rateConditions) || ! array_is_list($rateConditions) || $rateConditions === []) {
        throw new InvalidArgumentException("$rateWhere needs a non-empty conditions list; amount is the pay otherwise.");
      }
      WorldConditionEvaluator::validateAll($rateConditions, "$rateWhere conditions");
      $rateAmount = $rate['amount'] ?? null;
      if (! is_int($rateAmount) || $rateAmount < 0) {
        throw new InvalidArgumentException("$rateWhere amount must be a whole number of at least 0.");
      }
      $readRates[] = new IncomeRate($rateConditions, $rateAmount);
    }

    return new self(
      trim($id), trim($name), $cadence, $cadence->isCounted() ? $interval : null,
      $idleTimeout, $amount, $readRates, $delivery, $conditions,
    );
  }

  /** Whether the schedule runs in this world state. */
  public function isActive(GameState $state, ?Party $party): bool
  {
    return WorldConditionEvaluator::allHold($this->conditions, $state, $party);
  }

  /** The pay for one period in this world state: the first rate that holds, else the amount. */
  public function resolvePeriodPay(GameState $state, ?Party $party): int
  {
    foreach ($this->rates as $rate) {
      if (WorldConditionEvaluator::allHold($rate->conditions, $state, $party)) {
        return $rate->amount;
      }
    }
    return $this->amount;
  }

  private static function describeUnit(IncomeCadence $cadence): string
  {
    return match ($cadence) {
      IncomeCadence::ACTIVE_TIME => 'seconds',
      IncomeCadence::STEPS => 'steps',
      IncomeCadence::BATTLES => 'won battles',
      IncomeCadence::EVENT => 'paydays',
    };
  }
}
