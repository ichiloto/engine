<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Actors;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/** Project item references granted as equipped gear only when an actor is first created. */
final readonly class InitialEquipment
{
  /** @param array<string, string> $items Semantic slot => project item reference. */
  private function __construct(public array $items, private string $source) {}

  public static function fromArray(mixed $items, string $source): self
  {
    if (!is_array($items)) { throw new InvalidArgumentException("{$source} initialEquipment must be a slot-to-item map."); }
    foreach ($items as $slot => $reference) {
      if (!is_string($slot) || EquipmentSlotType::tryFrom($slot) === null || !is_string($reference) || trim($reference) === '') {
        throw new InvalidArgumentException("{$source} initialEquipment requires semantic equipment slots and nonempty item references.");
      }
    }
    return new self($items, $source);
  }

  public function equipCharacter(Character $character): void
  {
    if ($this->items === []) { return; }
    $store = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;
    if (!$store instanceof ItemStore) { throw new InvalidArgumentException("{$this->source} initialEquipment requires the project item catalogue."); }
    foreach ($this->items as $slotId => $reference) {
      $item = $store->instantiate($reference, context: "equipping {$this->source} initial {$slotId}")[0];
      $slots = array_values(array_filter($character->equipment,
        static fn(EquipmentSlot $slot): bool => $slot->semanticSlot->value === $slotId));
      if (!$item instanceof Equipment || $item->semanticSlot->value !== $slotId || count($slots) !== 1
        || !$character->assignEquipment($slots[0]->name, $item)) {
        throw new InvalidArgumentException("{$this->source} initialEquipment {$slotId} cannot equip '{$reference}'.");
      }
    }
  }
}
