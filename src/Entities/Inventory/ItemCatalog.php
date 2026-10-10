<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use Throwable;

/**
 * The project's inventory definitions, authored one record per file.
 *
 * Items live under `assets/Data/Items`, weapons under `Weapons` and armors
 * and accessories under `Armors`, each file returning
 * `['class' => InventoryItem::class, 'data' => [...]]`, the data in the form
 * {@see ItemRecord} reads. The folders are read in that order and each in
 * file name order, which is the order shops and menus list them in, so
 * record files are numbered (`0001-potion.php`). A file that cannot be read
 * is reported against that file and leaves the others loaded. Every record
 * that reads is returned, a second one claiming an id included: the item
 * store refuses a catalogue in which two definitions answer to one
 * reference, and that refusal stays the store's. `items.php` is the barrel
 * that returns them to the item store.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
final class ItemCatalog
{
  /** The record folders, relative to `assets/Data`, in the order they are listed. */
  public const array DIRECTORIES = ['Items', 'Weapons', 'Armors'];

  /**
   * @param array<string, InventoryItem> $items The definitions, keyed by their record file relative to `assets/Data`, in file order.
   * @param list<string> $problems The authoring problems found while reading the records.
   */
  private function __construct(
    private readonly array $items,
    private readonly array $problems,
  )
  {
  }

  /**
   * Reads a project's inventory records, reporting each problem as a
   * warning, and returns the definitions. This is what a project's
   * `items.php` barrel returns.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return list<InventoryItem> The definitions, in file order.
   */
  public static function loadProjectItems(string $assetRoot): array
  {
    $catalog = self::load($assetRoot);

    foreach ($catalog->getProblems() as $problem) {
      Debug::warn(sprintf('Inventory records: %s', $problem));
    }

    return array_values($catalog->getItems());
  }

  /**
   * Reads a project's inventory records.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @return self The catalogue.
   */
  public static function load(string $assetRoot): self
  {
    $items = [];
    $problems = [];

    foreach (self::DIRECTORIES as $directory) {
      $filenames = glob(Path::join($assetRoot, 'Data', $directory, '*.php')) ?: [];
      sort($filenames, SORT_STRING);

      foreach ($filenames as $filename) {
        $file = $directory . '/' . basename($filename);

        try {
          $items[$file] = self::readRecord($filename);
        } catch (Throwable $exception) {
          $problems[] = sprintf('%s: %s', $file, $exception->getMessage());
        }
      }
    }

    return new self($items, $problems);
  }

  /**
   * Returns every definition, keyed by its record file relative to
   * `assets/Data`, in file order.
   *
   * @return array<string, InventoryItem> The definitions.
   */
  public function getItems(): array
  {
    return $this->items;
  }

  /**
   * Returns the record file a definition is authored in.
   *
   * @param string $id The definition id.
   * @return string|null The file, relative to `assets/Data`.
   */
  public function getSourceFile(string $id): ?string
  {
    return array_find_key($this->items, static fn(InventoryItem $item): bool => $item->id === $id);
  }

  /**
   * Returns the authoring problems found while reading the records.
   *
   * @return list<string> The problems, each naming its file.
   */
  public function getProblems(): array
  {
    return $this->problems;
  }

  /**
   * Reads one record file.
   *
   * @throws InvalidArgumentException When the file is not an inventory record.
   */
  private static function readRecord(string $filename): InventoryItem
  {
    $payload = require $filename;

    if (! is_array($payload) || ($payload['class'] ?? null) !== InventoryItem::class || ! is_array($payload['data'] ?? null)) {
      throw new InvalidArgumentException("an inventory record returns ['class' => InventoryItem::class, 'data' => [...]].");
    }

    return ItemRecord::readItem($payload['data']);
  }
}
