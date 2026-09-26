<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;

/** Runs a Console drawing check against a clean, silent buffer and restores Console afterwards. */
function withSilentCameraConsole(int $columns, int $rows, Closure $check): void
{
  $state = new ReflectionClass(Console::class)->getStaticProperties();
  try {
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $name => $value) {
      new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    Console::setTerminalOutputEnabled(false);
    Console::setLayerTracking(false);
    Console::syncDimensions($columns, $rows);
    Console::clear();
    $check();
  } finally {
    foreach ($state as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
  }
}

it('centers maps that are smaller than the viewport', function () {
  $scene = makeCameraTestScene();
  // Eighty console columns show forty cells; the twenty-cell map is centred with ten cells either side.
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('..', 20)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(10.0)
    ->and($screenPosition->y)->toBe(20.0)
    ->and($camera->getConsolePosition(new Vector2(0, 0)))->toEqual(new Vector2(20, 20));
});

it('keeps large maps anchored to the viewport while scrolling', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(10, 5), null, array_fill(0, 60, str_repeat('..', 120)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(25, 15));

  expect($screenPosition->x)->toBe(15.0)
    ->and($screenPosition->y)->toBe(10.0)
    ->and($camera->getConsolePosition(new Vector2(25, 15)))->toEqual(new Vector2(30, 10));
});

it('uses the upper middle cell as the focus point on even viewports', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50);
  $scene->camera = $camera;

  expect($camera->screen->getWidth())->toBe(40)
    ->and($camera->getHorizontalFocusPosition())->toBe(19)
    ->and($camera->getVerticalFocusPosition())->toBe(24);
});

it('sizes the field in whole cells while drawing into every console column', function (int $columns, int $cells) {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, $columns, 10);
  expect($camera->screen->getWidth())->toBe($cells)
    ->and($camera->getConsoleColumns())->toBe($columns);
  $camera->resizeViewport(7, 10);
  expect($camera->screen->getWidth())->toBe(7)->and($camera->getConsoleColumns())->toBe(14);
  $camera->resizeToConsole($columns, 12);
  expect($camera->screen->getWidth())->toBe($cells)
    ->and($camera->getConsoleColumns())->toBe($columns)
    ->and($camera->screen->getHeight())->toBe(12);
})->with([[80, 40], [117, 58], [1, 1]]);

it('re-centers smaller maps after the viewport is resized', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('..', 20)));
  $scene->camera = $camera;

  $camera->resizeViewport(50, 60);
  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(15.0)
    ->and($screenPosition->y)->toBe(25.0)
    ->and($camera->getConsolePosition(new Vector2(0, 0))->x)->toBe(30.0);
});

it('detaches, clamps, focuses, restores and reattaches without duplicating camera math', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 20, 10, new Vector2(3, 4), null, array_fill(0, 40, str_repeat('..', 80)));
  $scene->camera = $camera;
  $initial = $camera->captureState();

  $camera->detach();
  $camera->moveTo(500, -20);
  expect($camera->followsPlayer)->toBeFalse()
    ->and([$camera->position->x, $camera->position->y])->toBe([70.0, 0.0]);

  $camera->focusOn(new Vector2(40, 20));
  expect([$camera->position->x, $camera->position->y])->toBe([36.0, 16.0]);

  $camera->restorePrevious();
  expect($camera->followsPlayer)->toBeTrue()
    ->and([$camera->position->x, $camera->position->y])->toBe([
      $initial->position->x,
      $initial->position->y,
    ]);
});

it('writes every map cell as two console columns and centres the map in whole cells', function () {
  withSilentCameraConsole(12, 3, function (): void {
    // Three cells in a six-cell camera leave one whole blank cell on the left and two on the right.
    $camera = new Camera(makeCameraTestScene(), 12, 3, worldSpace: [['##', '界', "\e[31ma\e[0m"]]);
    $camera->renderMap();
    // A wide glyph's second column reads as a space in a snapshot.
    expect($camera->getConsolePosition(new Vector2(0, 0)))->toEqual(new Vector2(2, 1))
      ->and(Console::snapshot()->rows[1])->toBe('  ##界 a     ')
      // A one-column cell is padded to its second column.
      ->and(Console::charAt(6, 1))->toBe('a')->and(Console::charAt(7, 1))->toBe(' ');
  });
});

it('draws actors at the console column of their cell and restores both columns of a cell', function () {
  withSilentCameraConsole(8, 2, function (): void {
    $camera = new Camera(makeCameraTestScene(), 8, 2,
      worldSpace: [MapCell::parseRow('abcdefgh'), MapCell::parseRow('ijklmnop')]);
    $camera->renderMap();
    $camera->renderOnScreen(['@@'], new Vector2(2, 1));
    expect(Console::snapshot()->rows[1])->toBe('ijkl@@op');
    $camera->renderBackgroundTile(2, 1);
    expect(Console::snapshot()->rows[1])->toBe('ijklmnop');
  });
});
