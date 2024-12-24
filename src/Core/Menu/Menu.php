<?php

namespace Ichiloto\Engine\Core\Menu;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\MenuEventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\MenuEvent;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use InvalidArgumentException;

/**
 * Class Menu. Represents a menu.
 *
 * @package Ichiloto\Engine\Core\Menu
 */
abstract class Menu implements MenuInterface
{
  /**
   * @var Window|null $window The window of the menu.
   */
  protected ?Window $window = null;
  /**
   * @var int $activeIndex The index of the active item.
   */
  protected(set) int $activeIndex = 0;

  /**
   * @var ItemList<ObserverInterface> $observers
   */
  protected ItemList $observers;
  /**
   * @var int $totalItems The total number of items.
   */
  protected int $totalItems = 0;

  /**
   * Menu constructor.
   *
   * @param string $title The title of the menu.
   * @param string $description The description of the menu.
   * @param ItemList $items The items of the menu.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected string $title,
    protected string $description = '',
    protected ItemList $items = new ItemList(MenuItemInterface::class),
    protected string $cursor = '>',
    protected Rect $rect = new Rect(0, 0, DEFAULT_DIALOG_WIDTH, DEFAULT_DIALOG_HEIGHT),
    protected BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->totalItems = $this->items->count();
    $this->cursor = substr($cursor, 0, 1);
    $this->activate();
    $this->updateWindowContent();
  }

  /**
   * Menu destructor.
   */
  public function __destruct()
  {
    $this->deactivate();
  }

  /**
   * @inheritDoc
   */
  public function getScene(): SceneInterface
  {
    return $this->scene;
  }

  /**
   * @inheritDoc
   */
  public function getTitle(): string
  {
    return $this->title;
  }

  /**
   * @inheritDoc
   */
  public function setTitle(string $title): void
  {
    $this->title = $title;
  }

  /**
   * @inheritDoc
   */
  public function getDescription(): string
  {
    return $this->description;
  }

  /**
   * @inheritDoc
   */
  public function setDescription(string $description): void
  {
    $this->description = $description;
  }

  /**
   * @inheritDoc
   */
  public function getItems(): ItemList
  {
    return $this->items;
  }

  /**
   * @inheritDoc
   */
  public function setItems(ItemList $items): self
  {
    $this->items = $items;
    $this->totalItems = $this->items->count();
    $this->updateWindowContent();
    $this->notify($this, new MenuEvent(MenuEventType::ITEMS_SET));
    return $this;
  }

  /**
   * @inheritDoc
   */
  public function addItem(MenuItemInterface $item): self
  {
    $this->items->add($item);
    $this->totalItems = $this->items->count();
    $this->updateWindowContent();
    $this->notify($this, new MenuEvent(MenuEventType::ITEM_ADDED));

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function removeItem(MenuItemInterface $item): self
  {
    $this->items->remove($item);
    $this->totalItems = $this->items->count();
    $this->updateWindowContent();
    $this->notify($this, new MenuEvent(MenuEventType::ITEM_REMOVED));
    return $this;
  }

  /**
   * @inheritDoc
   */
  public function removeItemByIndex(int $index): self
  {
    $itemsAsArray = $this->items->toArray();
    $item = $itemsAsArray[$index] ?? null;

    if ($item) {
      $this->removeItem($item);
    }
    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getItemByIndex(int $index): MenuItemInterface
  {
    $itemsAsArray = $this->items->toArray();

    if (!isset($itemsAsArray[$index])) {
      throw new InvalidArgumentException('Invalid index.');
    }

    return $itemsAsArray[$index];
  }

  /**
   * @inheritDoc
   */
  public function getItemByLabel(string $label): ?MenuItemInterface
  {
    return $this->items->find(fn(MenuItemInterface $item) => $item->getLabel() === $label);
  }

  /**
   * @inheritDoc
   */
  public function getActiveItem(): MenuItemInterface
  {
    $activeIndex = $this->activeIndex > -1 ? $this->activeIndex : 0;
    return $this->getItemByIndex($activeIndex);
  }

  /**
   * @inheritDoc
   */
  public function setActiveItem(MenuItemInterface $item): void
  {
    if (!$this->items->contains($item)) {
      throw new InvalidArgumentException('Item not found in menu.');
    }

    $this->setActiveItemByIndex($this->items->findIndex(fn(MenuItemInterface $menuItem) => $menuItem === $item));
  }

  /**
   * @inheritDoc
   */
  public function setActiveItemByIndex(int $index): void
  {
    if (!isset($this->items->toArray()[$index])) {
      throw new InvalidArgumentException('Invalid index.');
    }

    $this->activeIndex = $index;
    $this->notify($this, new MenuEvent(MenuEventType::ITEM_ACTIVATED));
  }

  /**
   * @inheritDoc
   */
  public function setActiveItemByLabel(string $label): void
  {
    $item = $this->getItemByLabel($label);

    if (is_null($item)) {
      throw new InvalidArgumentException('Item not found in menu.');
    }

    $this->setActiveItem($item);
  }

  /**
   * Returns the menu cursor.
   *
   * @return string The menu cursor.
   */
  public function getCursor(): string
  {
    return $this->cursor;
  }

  /**
   * Sets the menu cursor.
   *
   * @param string $cursor The menu cursor.
   * @return void
   */
  public function setCursor(string $cursor): void
  {
    $this->cursor = substr($cursor, 0, 1);
  }

  /**
   * @inheritDoc
   */
  public function addObserver(ObserverInterface|string $observer): void
  {
    $this->observers->add($observer);
  }

  /**
   * @inheritDoc
   */
  public function removeObserver(ObserverInterface|string $observer): void
  {
    $this->observers->remove($observer);
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    /**
     * @var ObserverInterface $observer
     */
    foreach ($this->observers as $observer) {
      $observer->onNotify($entity, $event);
    }
  }

  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    // Do nothing
    return self::SUCCESS;
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->render();
  }

  /**
   * Updates the menu window content.
   *
   * @return void
   */
  public function updateWindowContent(): void
  {
    $content = [];
    /**
     * @var int $itemIndex
     * @var MenuItemInterface $item
     */
    foreach ($this->items as $itemIndex => $item) {
      $color = $item->isDisabled() ? Color::BLUE->value : '';
      $prefix = '  ';

      if ($itemIndex === $this->activeIndex) {
        $prefix = "$this->cursor ";
      }

      $output = $prefix . $item->getLabel();
      $content[] = $output;
    }

    if ($this->totalItems < $this->rect->getHeight()) {
      $content = array_pad($content, $this->rect->getHeight() - 2, ''); // -2 for the top and bottom borders
    }

    $this->window?->setContent($content);
    $this->render();
  }
}