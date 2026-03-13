<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;

it('measures colored world rows by visible symbols', function () {
  $scene = makeCameraTestScene();
  $row = TerminalText::visibleSymbols(
    '<info>o</info>' .
    '<fg=blue>.</>' .
    '<error>x</error>'
  );
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, [$row]);
  $scene->camera = $camera;

  expect($camera->worldSpaceWidth)->toBe(3)
    ->and($camera->worldSpaceHeight)->toBe(1);
});
