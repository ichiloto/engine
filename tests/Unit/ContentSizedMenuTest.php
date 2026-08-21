<?php

use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\GameOver\Menus\GameOverMenu;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowPadding;

class TitleMenuSizingProbe extends TitleMenu
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Keep terminal output out of this layout test.
  }

  public function windowHeight(): int
  {
    return $this->window->getHeight();
  }

  public function contentHeight(): int
  {
    return $this->window->getContentHeight();
  }

  public function content(): array
  {
    return $this->window->getContent();
  }
}

class GameOverMenuSizingProbe extends GameOverMenu
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Keep terminal output out of this layout test.
  }

  public function windowHeight(): int
  {
    return $this->window->getHeight();
  }

  public function contentHeight(): int
  {
    return $this->window->getContentHeight();
  }
}

function makeContentSizedMenuItem(TitleMenu|GameOverMenu $menu, string $label): MenuItem
{
  return new class($menu, $label, '') extends MenuItem {
  };
}

function makeUnstartedTitleScene(): TitleScene
{
  return (new ReflectionClass(TitleScene::class))->newInstanceWithoutConstructor();
}

it('grows every content-sized window when authored rows exceed its capacity', function () {
  $window = new Window(
    position: new Vector2(),
    width: 20,
    height: 3,
    padding: new WindowPadding(1, 1, 1, 1),
  );

  $window->setContent(['one', 'two', 'three', 'four', 'five']);

  expect($window->getHeightPolicy())->toBe(WindowHeightPolicy::GROW_TO_CONTENT)
    ->and($window->getContentHeight())->toBe(5)
    ->and($window->getHeight())->toBe(9);

  $window->setContent(['one']);

  expect($window->getContentHeight())->toBe(5)
    ->and($window->getHeight())->toBe(9);
});

it('lets deliberately paginated windows retain a fixed viewport', function () {
  $window = new Window(
    position: new Vector2(),
    width: 20,
    height: 5,
    padding: new WindowPadding(),
    heightPolicy: WindowHeightPolicy::FIXED,
  );

  $window->setContent(['one', 'two', 'three', 'four', 'five']);

  expect($window->getHeight())->toBe(5)
    ->and($window->getContentHeight())->toBe(3);
});

it('sizes the title menu for every authored command plus padding and borders', function () {
  $menu = new TitleMenuSizingProbe(
    makeUnstartedTitleScene(),
    '',
    '',
    rect: new Rect(0, 0, 16, 3),
  );

  foreach (['New Game', 'Continue', 'Options', 'Credits', 'Quit'] as $label) {
    $menu->addItem(makeContentSizedMenuItem($menu, $label));
  }

  expect($menu->contentHeight())->toBe(5)
    ->and($menu->windowHeight())->toBe(9)
    ->and(implode("\n", $menu->content()))
    ->toContain('New Game')
    ->toContain('Continue')
    ->toContain('Options')
    ->toContain('Credits')
    ->toContain('Quit');
});

it('applies the same content-sized contract to the game-over command menu', function () {
  $menu = new GameOverMenuSizingProbe(
    makeUnstartedTitleScene(),
    '',
    '',
    rect: new Rect(0, 0, 16, 3),
  );

  foreach (['Continue', 'Title', 'Quit'] as $label) {
    $menu->addItem(makeContentSizedMenuItem($menu, $label));
  }

  expect($menu->contentHeight())->toBe(3)
    ->and($menu->windowHeight())->toBe(7);
});
