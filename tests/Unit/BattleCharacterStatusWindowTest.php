<?php

use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;

it('keeps low non-zero hp and mp bars visibly filled', function () {
  $window = makeBattleCharacterStatusWindow();
  $character = new Character('Kaelion', 0, new Stats(currentHp: 1, totalHp: 100, currentMp: 1, totalMp: 20));

  $line = $window->formatCharacterStats($character);

  expect(substr_count($line, '■'))->toBeGreaterThanOrEqual(2)
    ->and(TerminalText::displayWidth($line))->toBe(31);
});

it('keeps zero hp and mp bars framed at a stable width', function () {
  $window = makeBattleCharacterStatusWindow();
  $character = new Character('Kaelion', 0, new Stats(currentHp: 0, totalHp: 100, currentMp: 0, totalMp: 20));

  $line = $window->formatCharacterStats($character);

  expect(substr_count($line, '['))->toBe(2)
    ->and(substr_count($line, ']'))->toBe(2)
    ->and(substr_count($line, '■'))->toBe(0)
    ->and(TerminalText::displayWidth($line))->toBe(31);
});

/**
 * Creates a lightweight battle status window for unit tests.
 *
 * @return BattleCharacterStatusWindow
 */
function makeBattleCharacterStatusWindow(): BattleCharacterStatusWindow
{
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50);
  $scene->camera = $camera;

  $window = (new ReflectionClass(BattleCharacterStatusWindow::class))->newInstanceWithoutConstructor();
  $cameraProperty = new ReflectionProperty(BattleCharacterStatusWindow::class, 'camera');
  $cameraProperty->setValue($window, $camera);

  return $window;
}
