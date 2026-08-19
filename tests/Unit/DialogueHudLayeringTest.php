<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredUIElementInterface;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\UIManager;

final class LayeredHudProbe implements LayeredUIElementInterface
{
  public bool $isActive = true;
  public bool $eligible = true;
  public int $renderCount = 0;
  public int $eraseCount = 0;
  /** @var array<string, mixed> */
  public array $state = ['quest' => 3, 'dialogue' => 'unchanged'];

  public function __construct(public Rect $bounds)
  {
  }

  public function activate(): void
  {
    $this->isActive = true;
  }

  public function deactivate(): void
  {
    $this->isActive = false;
  }

  public function isPresentationVisible(): bool
  {
    return $this->isActive && $this->eligible;
  }

  public function getPresentationBounds(): Rect
  {
    return $this->bounds;
  }

  public function getPresentationPriority(): PresentationPriority
  {
    return PresentationPriority::FIELD_HUD;
  }

  public function render(): void
  {
    $this->renderCount++;
  }

  public function erase(): void
  {
    $this->eraseCount++;
  }
}

final class PlainUiElementProbe implements UIElementInterface
{
  public bool $isActive = true;
  public int $renderCount = 0;
  public int $eraseCount = 0;

  public function activate(): void
  {
    $this->isActive = true;
  }

  public function deactivate(): void
  {
    $this->isActive = false;
  }

  public function render(): void
  {
    $this->renderCount++;
  }

  public function erase(): void
  {
    $this->eraseCount++;
  }
}

final readonly class HigherPresentationProbe implements LayeredPresentationInterface
{
  public function __construct(public Rect $bounds)
  {
  }

  public function getPresentationBounds(): Rect
  {
    return $this->bounds;
  }

  public function getPresentationPriority(): PresentationPriority
  {
    return PresentationPriority::MODAL;
  }
}

/** @param UIElementInterface ...$elements */
function makeLayeredUiManager(UIElementInterface ...$elements): UIManager
{
  $manager = (new ReflectionClass(UIManager::class))->newInstanceWithoutConstructor();
  $list = new ItemList(UIElementInterface::class);

  foreach ($elements as $element) {
    $list->add($element);
  }

  $property = (new ReflectionClass(UIManager::class))->getProperty('uiElements');
  $property->setValue($manager, $list);

  return $manager;
}

it('suppresses a lower HUD where an 80x24 dialogue footprint intersects it', function () {
  $hud = new LayeredHudProbe(new Rect(1, 20, 25, 4));
  $dialogue = new HigherPresentationProbe(new Rect(0, 19, 80, 5));
  $manager = makeLayeredUiManager($hud);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();

  expect($hud->renderCount)->toBe(1)
    ->and($hud->eraseCount)->toBe(1)
    ->and($manager->isSuppressed($hud))->toBeTrue();
});

it('keeps a non-intersecting HUD visible on a wider layout', function () {
  $hud = new LayeredHudProbe(new Rect(1, 35, 25, 4));
  $dialogue = new HigherPresentationProbe(new Rect(75, 34, 80, 5));
  $manager = makeLayeredUiManager($hud);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();

  expect($hud->renderCount)->toBe(2)
    ->and($hud->eraseCount)->toBe(0)
    ->and($manager->isSuppressed($hud))->toBeFalse();
});

it('does not flash the HUD across dialogue pages or a dialogue to choice transition', function () {
  $hud = new LayeredHudProbe(new Rect(1, 20, 25, 4));
  $dialogue = new HigherPresentationProbe(new Rect(0, 19, 80, 5));
  $choice = new HigherPresentationProbe(new Rect(10, 15, 50, 9));
  $manager = makeLayeredUiManager($hud);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();

  // Advancing a page retains the same presentation reservation.
  $manager->present($dialogue);
  $manager->render();

  // Closing dialogue and opening a choice happen in one update. The old
  // dismissal is committed only after the choice has claimed precedence.
  $manager->dismiss($dialogue);
  $manager->present($choice);
  $manager->commitPresentationChanges();
  $manager->render();

  expect($hud->renderCount)->toBe(1)
    ->and($hud->eraseCount)->toBe(1)
    ->and($manager->isSuppressed($hud))->toBeTrue();

  $manager->dismiss($choice);
  $manager->commitPresentationChanges();
  $manager->render();

  expect($hud->renderCount)->toBe(2)
    ->and($manager->isSuppressed($hud))->toBeFalse();
});

it('recomputes intersection after a narrow resize and after widening again', function () {
  $hud = new LayeredHudProbe(new Rect(1, 35, 25, 4));
  $dialogue = new HigherPresentationProbe(new Rect(75, 34, 80, 5));
  $manager = makeLayeredUiManager($hud);

  $manager->present($dialogue);
  $manager->render();
  expect($hud->renderCount)->toBe(1);

  // A resize recomposes the canvas, then asks the manager which layers draw.
  $hud->bounds = new Rect(76, 35, 25, 4);
  $manager->render();
  expect($hud->renderCount)->toBe(1)
    ->and($manager->isSuppressed($hud))->toBeTrue();

  $hud->bounds = new Rect(1, 35, 25, 4);
  $manager->render();
  expect($hud->renderCount)->toBe(2)
    ->and($manager->isSuppressed($hud))->toBeFalse();
});

it('suppresses only intersecting lower-precedence HUD elements', function () {
  $leftHud = new LayeredHudProbe(new Rect(1, 20, 25, 4));
  $rightHud = new LayeredHudProbe(new Rect(100, 20, 25, 4));
  $dialogue = new HigherPresentationProbe(new Rect(0, 19, 80, 5));
  $manager = makeLayeredUiManager($leftHud, $rightHud);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();

  expect($leftHud->renderCount)->toBe(1)
    ->and($leftHud->eraseCount)->toBe(1)
    ->and($rightHud->renderCount)->toBe(2)
    ->and($rightHud->eraseCount)->toBe(0);
});

it('changes presentation only and leaves active field and dialogue state untouched', function () {
  $hud = new LayeredHudProbe(new Rect(1, 20, 25, 4));
  $state = $hud->state;
  $dialogue = new HigherPresentationProbe(new Rect(0, 19, 80, 5));
  $manager = makeLayeredUiManager($hud);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();
  $manager->dismiss($dialogue);
  $manager->commitPresentationChanges();
  $manager->render();

  expect($hud->isActive)->toBeTrue()
    ->and($hud->eligible)->toBeTrue()
    ->and($hud->state)->toBe($state);
});

it('keeps ordinary and motion-independent rendering unchanged without a higher layer', function () {
  $hud = new LayeredHudProbe(new Rect(1, 20, 25, 4));
  $manager = makeLayeredUiManager($hud);

  $manager->render();
  $manager->render();
  $manager->render();

  expect($hud->renderCount)->toBe(3)
    ->and($hud->eraseCount)->toBe(0)
    ->and($manager->isSuppressed($hud))->toBeFalse();
});

it('keeps existing non-layered UI elements backward compatible under a modal', function () {
  $element = new PlainUiElementProbe();
  $dialogue = new HigherPresentationProbe(new Rect(0, 19, 80, 5));
  $manager = makeLayeredUiManager($element);

  $manager->render();
  $manager->present($dialogue);
  $manager->render();

  expect($element->renderCount)->toBe(2)
    ->and($element->eraseCount)->toBe(0);
});

it('treats touching edges as non-overlapping terminal footprints', function () {
  expect((new Rect(0, 0, 10, 4))->intersects(new Rect(10, 0, 5, 4)))->toBeFalse()
    ->and((new Rect(0, 0, 10, 4))->intersects(new Rect(9, 3, 5, 4)))->toBeTrue();
});
