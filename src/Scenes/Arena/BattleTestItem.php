<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use InvalidArgumentException;

/**
 * One entry of a battle test party's items: an item, by definition id, and
 * how many the party holds. Project data writes it as
 * `['item' => 'item.potion', 'quantity' => 5]`.
 */
final readonly class BattleTestItem
{
  public function __construct(public string $itemId, public int $quantity)
  {
    if (trim($itemId) === '') {
      throw new InvalidArgumentException('A battle test item names an item.');
    }
    if ($quantity < 1) {
      throw new InvalidArgumentException('A battle test party holds at least 1 of each item it lists.');
    }
  }

  /**
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not an entry.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['item', 'quantity']);
    if ($unknown !== []) {
      throw new InvalidArgumentException('A battle test item has no ' . implode(', ', $unknown) . '.');
    }
    if (!is_string($data['item'] ?? null) || !is_int($data['quantity'] ?? null)) {
      throw new InvalidArgumentException('A battle test item needs an item id and a whole-number quantity.');
    }

    return new self($data['item'], $data['quantity']);
  }

  /** @return array{item: string, quantity: int} */
  public function toArray(): array
  {
    return ['item' => $this->itemId, 'quantity' => $this->quantity];
  }

  public function withQuantity(int $quantity): self
  {
    return new self($this->itemId, $quantity);
  }

  /**
   * A list of entries as project data writes it, each item once.
   *
   * @param mixed $data
   * @return list<self>
   * @throws InvalidArgumentException When the data is not a list of entries, or lists an item twice.
   */
  public static function listFromArray(mixed $data): array
  {
    if (!is_array($data) || !array_is_list($data) || array_any($data, static fn(mixed $entry): bool => !is_array($entry))) {
      throw new InvalidArgumentException('A battle test party\'s inventory is a list of items and quantities.');
    }

    return self::requireUnique(array_map(self::fromArray(...), $data));
  }

  /**
   * @param list<mixed> $items
   * @return list<self>
   * @throws InvalidArgumentException When an entry is not an item entry, or an item is listed twice.
   */
  public static function requireUnique(array $items): array
  {
    if (!array_is_list($items) || array_any($items, static fn(mixed $item): bool => !$item instanceof self)) {
      throw new InvalidArgumentException('A battle test party\'s inventory is a list of items and quantities.');
    }
    $ids = array_map(static fn(self $item): string => $item->itemId, $items);
    $repeated = array_keys(array_filter(array_count_values($ids), static fn(int $count): bool => $count > 1));
    if ($repeated !== []) {
      throw new InvalidArgumentException('A battle test party lists ' . implode(', ', $repeated) . ' more than once; give one quantity.');
    }

    return $items;
  }
}
