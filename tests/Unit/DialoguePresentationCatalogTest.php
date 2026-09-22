<?php

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;

it('initializes an empty dialogue presentation catalogue', function () {
  $catalogue = new DialoguePresentationCatalog();

  expect($catalogue->actors)->toBe([])
    ->and(DialoguePresentationCatalog::FILE)->toBe('Data/Presentation/dialogue.php');
});

it('retains supplied actors without allowing catalogue mutation', function () {
  $actors = ['example-actor' => ['portrait' => 'Graphics/Portraits/Example.png']];
  $catalogue = new DialoguePresentationCatalog($actors);

  expect($catalogue->actors)->toBe($actors)
    ->and(fn() => $catalogue->actors = [])->toThrow(Error::class);
});
