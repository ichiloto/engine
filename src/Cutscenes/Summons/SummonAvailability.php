<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Util\Debug;

/**
 * Optional world-state gate for an authored summon definition.
 *
 * Definitions that omit this object retain the historical open behavior.
 * Once an availability block is declared, malformed data and unknown
 * condition types fail closed instead of exposing the summon.
 */
final class SummonAvailability
{
  /** @var array<int, array<string, mixed>> */
  protected array $conditions = [];

  /** @var string[] */
  protected array $errors = [];

  /**
   * @param array<int, array<string, mixed>> $conditions
   * @param string[] $errors
   */
  private function __construct(array $conditions = [], array $errors = [])
  {
    $this->conditions = $conditions;
    $this->errors = $errors;
  }

  /**
   * Hydrates a declared availability block without ever falling back open.
   */
  public static function fromAuthored(mixed $data): self
  {
    if (! is_array($data)) {
      return self::invalid('The availability block must be an array.');
    }

    $conditions = $data['conditions'] ?? null;

    if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
      return self::invalid('The availability block must contain a non-empty conditions list.');
    }

    $normalized = [];
    $errors = [];

    foreach ($conditions as $index => $condition) {
      if (! is_array($condition)) {
        $errors[] = sprintf('Availability condition %d must be an array.', $index + 1);
        continue;
      }

      $typeValue = $condition['type'] ?? null;
      $nameValue = $condition['name'] ?? null;

      if (! is_string($typeValue)) {
        $errors[] = sprintf('Availability condition %d has a malformed type.', $index + 1);
        $type = '';
      } else {
        $type = trim($typeValue);
      }

      if (! is_string($nameValue)) {
        $errors[] = sprintf('Availability condition %d has a malformed name.', $index + 1);
        $name = '';
      } else {
        $name = trim($nameValue);
      }

      if (! WorldConditionType::tryFrom($type) instanceof WorldConditionType) {
        $errors[] = sprintf('Availability condition %d uses unknown type "%s".', $index + 1, $type !== '' ? $type : '(empty)');
      }

      if ($name === '') {
        $errors[] = sprintf('Availability condition %d has no name.', $index + 1);
      }

      $errors = [
        ...$errors,
        ...self::validateConditionPayload($condition, $type, $index + 1),
      ];

      $normalized[] = $condition;
    }

    if ($errors !== []) {
      foreach ($errors as $error) {
        Debug::warn($error . ' The summon remains locked.');
      }
    }

    return new self($normalized, $errors);
  }

  /** Returns true only when the block is valid and every condition holds. */
  public function isSatisfied(GameState $gameState, ?Party $party = null): bool
  {
    return $this->isValid()
      && WorldConditionEvaluator::allHold($this->conditions, $gameState, $party);
  }

  public function isValid(): bool
  {
    return $this->errors === [] && $this->conditions !== [];
  }

  /** @return string[] */
  public function getErrors(): array
  {
    return $this->errors;
  }

  /** @return array{conditions: array<int, array<string, mixed>>} */
  public function toArray(): array
  {
    return ['conditions' => $this->conditions];
  }

  private static function invalid(string $error): self
  {
    Debug::warn($error . ' The summon remains locked.');

    return new self([], [$error]);
  }

  /**
   * Validates fields the shared evaluator consumes so malformed values never
   * reach string or integer coercion paths.
   *
   * @param array<string, mixed> $condition
   * @return string[]
   */
  private static function validateConditionPayload(array $condition, string $type, int $position): array
  {
    $errors = [];

    if (array_key_exists('negate', $condition) && ! is_bool($condition['negate'])) {
      $errors[] = sprintf('Availability condition %d has a non-boolean negate flag.', $position);
    }

    switch ($type) {
      case WorldConditionType::SWITCH->value:
        if (array_key_exists('value', $condition) && ! is_bool($condition['value'])) {
          $errors[] = sprintf('Availability condition %d has a non-boolean switch value.', $position);
        }
        break;

      case WorldConditionType::VARIABLE->value:
        $operator = $condition['op'] ?? '==';
        if (! is_string($operator) || ! in_array($operator, ['==', '!=', '>', '>=', '<', '<='], true)) {
          $errors[] = sprintf('Availability condition %d has an invalid variable operator.', $position);
        }
        if (array_key_exists('value', $condition) && ! is_int($condition['value'])
          && ! is_float($condition['value']) && ! is_string($condition['value'])
        ) {
          $errors[] = sprintf('Availability condition %d has a malformed variable value.', $position);
        }
        break;

      case WorldConditionType::ITEM->value:
        if (array_key_exists('quantity', $condition)) {
          $quantity = $condition['quantity'];
          if ((! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity))) || intval($quantity) < 1) {
            $errors[] = sprintf('Availability condition %d has an invalid item quantity.', $position);
          }
        }
        break;

      case WorldConditionType::QUEST->value:
        $status = $condition['status'] ?? 'completed';
        if (! is_string($status) || ! in_array($status, ['active', 'completed'], true)) {
          $errors[] = sprintf('Availability condition %d has an invalid quest status.', $position);
        }
        break;
    }

    return $errors;
  }
}
