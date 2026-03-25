<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Windows\CommandPanel;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Ichiloto\Engine\Core\Menu\ShopMenu\Windows\ShopMainPanel;

class ArrayConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $segments = explode('.', $path);
    $value = $this->values;

    foreach ($segments as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $segments = explode('.', $path);
    $target = &$this->values;

    foreach ($segments as $segment) {
      if (! isset($target[$segment]) || ! is_array($target[$segment])) {
        $target[$segment] = [];
      }

      $target = &$target[$segment];
    }

    $target = $value;
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
    // Test stub; persistence is not needed.
  }
}

class MenuHighlightWindowProxy extends Window
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during menu highlighting tests.
  }
}

class MenuHighlightProxy extends Menu
{
  public function activate(): void
  {
    // Not used in these isolated tests.
  }

  public function deactivate(): void
  {
    // Not used in these isolated tests.
  }

  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during menu highlighting tests.
  }

  public function erase(?int $x = null, ?int $y = null): void
  {
    // Not used in these isolated tests.
  }

  public function update(): void
  {
    // Not used in these isolated tests.
  }

  public function getWindowContentForTest(): array
  {
    return $this->window?->getContent() ?? [];
  }
}

class CommandPanelHighlightProxy extends CommandPanel
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during menu highlighting tests.
  }
}

class ShopMainPanelHighlightProxy extends ShopMainPanel
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during menu highlighting tests.
  }
}

class CharacterPanelHighlightProxy extends CharacterPanel
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during menu highlighting tests.
  }
}

function setMenuHighlightProperty(object $object, string $property, mixed $value): void
{
  $reflection = new ReflectionObject($object);

  while (! $reflection->hasProperty($property)) {
    $reflection = $reflection->getParentClass();

    if (! $reflection) {
      throw new RuntimeException("Property {$property} not found.");
    }
  }

  $reflectionProperty = $reflection->getProperty($property);
  $reflectionProperty->setAccessible(true);
  $reflectionProperty->setValue($object, $value);
}

function makeTestMenuItem(string $label): MenuItemInterface
{
  return new class($label) implements MenuItemInterface {
    public function __construct(
      private string $label,
      private string $description = '',
      private string $icon = '',
      private bool $disabled = false,
    )
    {
    }

    public function execute(?ExecutionContextInterface $context = null): int
    {
      return MenuInterface::SUCCESS;
    }

    public function getLabel(): string
    {
      return $this->label;
    }

    public function setLabel(string $label): void
    {
      $this->label = $label;
    }

    public function getIcon(): string
    {
      return $this->icon;
    }

    public function setIcon(string $icon): void
    {
      $this->icon = $icon;
    }

    public function getDescription(): string
    {
      return $this->description;
    }

    public function setDescription(string $description): void
    {
      $this->description = $description;
    }

    public function isDisabled(): bool
    {
      return $this->disabled;
    }

    public function enable(): void
    {
      $this->disabled = false;
    }

    public function disable(): void
    {
      $this->disabled = true;
    }

    public function __toString(): string
    {
      return $this->label;
    }
  };
}

it('highlights the active main-menu entry', function () {
  $items = new ItemList(MenuItemInterface::class);
  $items->add(makeTestMenuItem('Items'));
  $items->add(makeTestMenuItem('Status'));

  $menu = (new ReflectionClass(MenuHighlightProxy::class))->newInstanceWithoutConstructor();
  setMenuHighlightProperty($menu, 'rect', new Rect(0, 0, 30, 8));
  setMenuHighlightProperty($menu, 'window', new MenuHighlightWindowProxy());
  setMenuHighlightProperty($menu, 'items', $items);
  setMenuHighlightProperty($menu, 'totalItems', $items->count());
  setMenuHighlightProperty($menu, 'activeIndex', 1);
  setMenuHighlightProperty($menu, 'cursor', '>');

  $menu->updateWindowContent();
  $content = $menu->getWindowContentForTest();

  expect($content[0])->not->toContain(Color::LIGHT_BLUE->value)
    ->and($content[1])->toContain(Color::LIGHT_BLUE->value)
    ->and($content[1])->toContain('> Status');
});

it('highlights the active shop command entry', function () {
  $items = new ItemList(MenuItemInterface::class);
  $items->add(makeTestMenuItem('Buy'));
  $items->add(makeTestMenuItem('Sell'));
  $items->add(makeTestMenuItem('Cancel'));

  $menu = (new ReflectionClass(MenuHighlightProxy::class))->newInstanceWithoutConstructor();
  setMenuHighlightProperty($menu, 'items', $items);
  setMenuHighlightProperty($menu, 'totalItems', $items->count());
  setMenuHighlightProperty($menu, 'activeIndex', 1);

  $panel = (new ReflectionClass(CommandPanelHighlightProxy::class))->newInstanceWithoutConstructor();
  setMenuHighlightProperty($panel, 'menu', $menu);

  $panel->updateContent();
  $content = $panel->getContent();

  expect($content[0])->toContain(Color::LIGHT_BLUE->value)
    ->and($content[0])->toContain('Sell');
});

it('highlights the active shop item entry', function () {
  ConfigStore::put(ProjectConfig::class, new ArrayConfigStub([
    'vocab' => [
      'currency' => [
        'symbol' => 'G',
      ],
    ],
    'ui' => [
      'menu' => [
        'selection_color' => Color::LIGHT_BLUE,
      ],
    ],
  ]));

  $panel = (new ReflectionClass(ShopMainPanelHighlightProxy::class))->newInstanceWithoutConstructor();
  setMenuHighlightProperty($panel, 'width', 55);
  setMenuHighlightProperty($panel, 'height', 10);

  $panel->setItems([
    new Item('Potion', 'Restores HP.', '!', 50),
    new Item('Phoenix Down', 'Revives an ally.', '*', 300),
  ]);
  $panel->activeItemIndex = 1;

  $content = $panel->getContent();

  expect($content[0])->not->toContain(Color::LIGHT_BLUE->value)
    ->and($content[1])->toContain(Color::LIGHT_BLUE->value)
    ->and($content[1])->toContain('Phoenix Down');
});

it('uses the shared menu selection color for focused character panels', function () {
  ConfigStore::put(ProjectConfig::class, new ArrayConfigStub([
    'ui' => [
      'menu' => [
        'selection_color' => Color::YELLOW,
      ],
    ],
  ]));

  $panel = new CharacterPanelHighlightProxy(new Rect(0, 0, 40, 7));

  $panel->focus();

  expect($panel->getForegroundColor())->toBe(Color::YELLOW);
});
