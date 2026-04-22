<?php

use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('aligns the hp, mp, and atb headings with the compact battle bars', function () {
  $window = makeBattleCharacterStatusWindow();
  $character = new Character('Kaelion', 0, new Stats(currentHp: 245, totalHp: 999, currentMp: 32, totalMp: 80));

  $header = invokeBattleStatusHeaderFormatter($window, true);
  $line = $window->formatCharacterStats($character, 0.5);
  $plainHeader = TerminalText::stripAnsi($header);
  $plainLine = TerminalText::stripAnsi($line);
  $barStarts = [];
  $offset = 0;

  while (($position = mb_strpos($plainLine, '[', $offset, 'UTF-8')) !== false) {
    $barStarts[] = $position;
    $offset = $position + 1;
  }

  expect(TerminalText::displayWidth($header))->toBe(31)
    ->and($barStarts)->toHaveCount(3)
    ->and($plainHeader)->not->toContain(' ')
    ->and(mb_strpos($plainHeader, 'HP', 0, 'UTF-8'))->toBe($barStarts[0])
    ->and(mb_strpos($plainHeader, 'MP', 0, 'UTF-8'))->toBe($barStarts[1])
    ->and(mb_strpos($plainHeader, 'ATB', 0, 'UTF-8'))->toBe($barStarts[2]);
});

it('can retain four battlers for future four-person battle layouts', function () {
  $window = makeBattleCharacterStatusWindow();
  $characters = [
    new Character('Kaelion', 0, new Stats()),
    new Character('Liora', 0, new Stats()),
    new Character('Drazek', 0, new Stats()),
    new Character('Serin', 0, new Stats()),
  ];

  $window->setCharacters($characters);

  expect(readBattleStatusCharacters($window))->toHaveCount(4);
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
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'camera'))->setValue($window, $camera);
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'title'))->setValue($window, '');
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'help'))->setValue($window, '');
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'position'))->setValue($window, new Vector2());
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'width'))->setValue($window, BattleCharacterStatusWindow::WIDTH);
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'height'))->setValue($window, BattleCharacterStatusWindow::HEIGHT);
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'borderPack'))->setValue($window, new DefaultBorderPack());
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'alignment'))->setValue($window, WindowAlignment::middleLeft());
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'padding'))->setValue($window, new WindowPadding(rightPadding: 1, leftPadding: 1));
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'backgroundColor'))->setValue($window, Color::BLACK);
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'foregroundColor'))->setValue($window, null);
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'content'))->setValue($window, array_fill(0, BattleCharacterStatusWindow::HEIGHT - 2, ''));
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'cursor'))->setValue($window, Console::cursor());
  (new ReflectionProperty(BattleCharacterStatusWindow::class, 'output'))->setValue($window, new BufferedOutput());

  return $window;
}

/**
 * Invokes the protected battle status header formatter.
 *
 * @param BattleCharacterStatusWindow $window The window under test.
 * @param bool $showAtb Whether to format the ATB-aware header.
 * @return string
 */
function invokeBattleStatusHeaderFormatter(BattleCharacterStatusWindow $window, bool $showAtb): string
{
  $method = new ReflectionMethod(BattleCharacterStatusWindow::class, 'formatHeaderLine');

  return $method->invoke($window, $showAtb);
}

/**
 * Reads the protected battler collection tracked by the status window.
 *
 * @param BattleCharacterStatusWindow $window The window under test.
 * @return array<int, Character>
 */
function readBattleStatusCharacters(BattleCharacterStatusWindow $window): array
{
  $property = new ReflectionProperty(BattleCharacterStatusWindow::class, 'characters');

  return $property->getValue($window);
}
