<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Closure;
use Ichiloto\Engine\Core\Menu\QuantitySelector;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;

/**
 * Setting a battle test party's items, apart from drawing and input
 * devices, in the Shop's stages: the rows are the items the party holds
 * (the default stock until one is changed), then Add an item and, once the
 * items are set, Use the default stock. Confirm on an item chooses how many
 * the party holds, where none removes it; Add lists the items the party
 * does not hold yet and then asks how many. A quantity moves by one up and
 * down and by ten left and right ({@see QuantitySelector}); confirm keeps
 * it and cancel steps back without changing anything. Cancel on the rows
 * leaves the items.
 */
final class BattleTestInventoryEditor
{
  public const string ENTRIES = 'entries';
  public const string CATALOGUE = 'catalogue';
  public const string QUANTITY = 'quantity';
  /** What cancel on the rows asks of the battle test. */
  public const string LEAVE = 'leave';

  public private(set) string $focus = self::ENTRIES;
  public private(set) int $rowIndex = 0;
  public private(set) int $catalogueIndex = 0;
  /** @var list<BattleTestItem>|null The items set; null while the party holds the default stock. */
  public private(set) ?array $items = null;
  /** The quantity being chosen, while one is. */
  public private(set) ?QuantitySelector $quantity = null;
  /** The item whose quantity is being chosen, by id, while one is. */
  public private(set) ?string $itemId = null;
  /** Whether the quantity being chosen is for an item being added. */
  public private(set) bool $adding = false;

  /**
   * @param Closure(): list<array{id: string, name: string, maxQuantity: int}> $catalogue Every item the party may carry.
   * @param Closure(): list<BattleTestItem> $defaultStock The items the party holds when none are set.
   */
  public function __construct(private readonly Closure $catalogue, private readonly Closure $defaultStock) {}

  /**
   * Starts from a setup's items, on the first row.
   *
   * @param list<BattleTestItem>|null $items Null for the default stock.
   */
  public function load(?array $items): void
  {
    $this->items = $items === null ? null : BattleTestItem::requireUnique($items);
    $this->focus = self::ENTRIES;
    $this->rowIndex = 0;
    $this->quantity = null;
    $this->itemId = null;
    $this->adding = false;
  }

  /** @var list<BattleTestItem> The items the party holds: those set, or the default stock. */
  public array $entries {
    get => $this->items ?? ($this->defaultStock)();
  }

  /** @var list<array{id: string, name: string, maxQuantity: int}> The items the party may carry and does not hold yet. */
  public array $addable {
    get {
      $held = array_map(static fn(BattleTestItem $item): string => $item->itemId, $this->entries);
      return array_values(array_filter(($this->catalogue)(), static fn(array $choice): bool => !in_array($choice['id'], $held, true)));
    }
  }

  /** @var list<string> The rows: `item:<n>` for each item held, then `add` while more can be held, and `default` once the items are set. */
  public array $rows {
    get => [...array_map(static fn(int $index): string => "item:{$index}", array_keys($this->entries)),
      ...($this->addable === [] || count($this->entries) >= BattleTestSetup::MAX_ITEMS ? [] : ['add']),
      ...($this->items === null ? [] : ['default'])];
  }

  /** The item held on the row the cursor is on, or null on another row. */
  public ?int $entryIndex {
    get {
      $row = $this->rows[$this->rowIndex] ?? '';
      return str_starts_with($row, 'item:') ? (int) substr($row, 5) : null;
    }
  }

  /** An item's name, by id, as the catalogue names it; its id when the project no longer has it. */
  public function getItemName(string $itemId): string
  {
    return array_find(($this->catalogue)(), static fn(array $choice): bool => $choice['id'] === $itemId)['name'] ?? $itemId;
  }

  public function moveVertical(int $step): void
  {
    match ($this->focus) {
      self::ENTRIES => $this->rowIndex = max(0, min(count($this->rows) - 1, $this->rowIndex + $step)),
      self::CATALOGUE => $this->catalogueIndex = max(0, min(count($this->addable) - 1, $this->catalogueIndex + $step)),
      // Down is less, as in the Shop.
      self::QUANTITY => $this->quantity?->adjust($this->quantity->axisAdjustment($step, 0)),
    };
  }

  /** Changes a quantity being chosen by ten; false where left and right mean nothing here. */
  public function moveHorizontal(int $step): bool
  {
    if ($this->focus !== self::QUANTITY || $this->quantity === null) { return false; }
    $this->quantity->adjust($this->quantity->axisAdjustment(0, $step));
    return true;
  }

  public function confirm(): void
  {
    switch ($this->focus) {
      case self::ENTRIES:
        $row = $this->rows[$this->rowIndex] ?? '';
        $entry = $this->entryIndex === null ? null : $this->entries[$this->entryIndex] ?? null;
        if ($entry !== null) {
          $this->chooseQuantity($entry->itemId, $entry->quantity, 0, false);
        } elseif ($row === 'add') {
          $this->catalogueIndex = 0;
          $this->focus = self::CATALOGUE;
        } elseif ($row === 'default') {
          $this->items = null;
          $this->rowIndex = 0;
        }
        return;
      case self::CATALOGUE:
        $choice = $this->addable[$this->catalogueIndex] ?? null;
        if ($choice !== null) {
          $this->chooseQuantity($choice['id'], min(BattleTestSetup::ITEM_QUANTITY, $choice['maxQuantity']), 1, true);
        }
        return;
      case self::QUANTITY:
        $this->keepQuantity();
        return;
    }
  }

  /** @return self::LEAVE|null Leaving the items, when cancel on the rows asks for it. */
  public function cancel(): ?string
  {
    switch ($this->focus) {
      case self::ENTRIES:
        return self::LEAVE;
      case self::CATALOGUE:
        $this->focus = self::ENTRIES;
        return null;
      case self::QUANTITY:
        $this->focus = $this->adding ? self::CATALOGUE : self::ENTRIES;
        $this->quantity = null;
        $this->itemId = null;
        $this->adding = false;
        return null;
    }
    return null;
  }

  private function chooseQuantity(string $itemId, int $initial, int $minimum, bool $adding): void
  {
    $maximum = max($minimum, array_find(($this->catalogue)(), static fn(array $choice): bool => $choice['id'] === $itemId)['maxQuantity']
      ?? InventoryItem::MAX_QUANTITY);
    $this->quantity = new QuantitySelector($maximum, $minimum, $initial);
    $this->itemId = $itemId;
    $this->adding = $adding;
    $this->focus = self::QUANTITY;
  }

  /** Keeps the quantity chosen: an added item joins the end, an item at none leaves, any other takes its new quantity. */
  private function keepQuantity(): void
  {
    $itemId = $this->itemId;
    $quantity = $this->quantity?->quantity ?? 0;
    $entries = $this->entries;
    if ($itemId !== null && $this->adding) {
      $entries[] = new BattleTestItem($itemId, max(1, $quantity));
      $this->rowIndex = count($entries) - 1;
    } elseif ($itemId !== null) {
      $index = array_find_key($entries, static fn(BattleTestItem $item): bool => $item->itemId === $itemId);
      if ($index !== null && $quantity < 1) {
        array_splice($entries, $index, 1);
      } elseif ($index !== null) {
        $entries[$index] = $entries[$index]->withQuantity($quantity);
      }
    }
    $this->items = array_values($entries);
    $this->rowIndex = max(0, min(count($this->rows) - 1, $this->rowIndex));
    $this->focus = self::ENTRIES;
    $this->quantity = null;
    $this->itemId = null;
    $this->adding = false;
  }
}
