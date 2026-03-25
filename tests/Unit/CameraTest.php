<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;

it('centers maps that are smaller than the viewport', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('.', 20)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(30.0)
    ->and($screenPosition->y)->toBe(20.0);
});

it('keeps large maps anchored to the viewport while scrolling', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(10, 5), null, array_fill(0, 60, str_repeat('.', 120)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(25, 15));

  expect($screenPosition->x)->toBe(15.0)
    ->and($screenPosition->y)->toBe(10.0);
});

it('uses the upper middle cell as the focus point on even viewports', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50);
  $scene->camera = $camera;

  expect($camera->getHorizontalFocusPosition())->toBe(39)
    ->and($camera->getVerticalFocusPosition())->toBe(24);
});

it('re-centers smaller maps after the viewport is resized', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('.', 20)));
  $scene->camera = $camera;

  $camera->resizeViewport(100, 60);
  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(40.0)
    ->and($screenPosition->y)->toBe(25.0);
});
