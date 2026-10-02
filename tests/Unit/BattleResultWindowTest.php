<?php

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleProgression;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleResultsText;
use Ichiloto\Engine\Battle\UI\BattleResultWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Progression\ProgressionSnapshot;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;

class BattleResultWindowTestProxy extends BattleResultWindow
{
  public int $renderCount = 0;

  public function render(?int $x = null, ?int $y = null): void
  {
    $this->renderCount++;
  }
}

function makeResultWindowProgression(string $name, int $index = 0, int $experience = 50): BattleProgression
{
  $id = 'actor-' . $index;
  $before = new ProgressionSnapshot($id, $name, 1, 0, 99, [1 => 0, 2 => 50], ['maxHp' => 140, 'maxMp' => 10]);
  $after = new ProgressionSnapshot($id, $name, 2, $experience, 99, [1 => 0, 2 => 50], ['maxHp' => 187, 'maxMp' => 19]);
  return new BattleProgression($experience, $before, $after);
}

function makeBattleResultTestScreen(): BattleScreen
{
  $reflection = new ReflectionClass(BattleScreen::class);
  $screen = $reflection->newInstanceWithoutConstructor();

  $borderPack = $reflection->getProperty('borderPack');
  $borderPack->setValue($screen, new DefaultBorderPack());

  $screenDimensions = $reflection->getProperty('screenDimensions');
  $screenDimensions->setValue($screen, new Rect(0, 0, BattleScreen::WIDTH, BattleScreen::HEIGHT));

  return $screen;
}

it('reveals battle rewards sequentially', function () {
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $result = new BattleResult(
    'Victory',
    entries: [
      ['label' => 'Experience gained:', 'value' => '123'],
      ['label' => 'Gold found:', 'value' => '50G'],
      ['label' => 'Item drops:', 'value' => 'Potion'],
    ],
  );

  $window->display($result);

  expect(implode("\n", $window->getContent()))->toContain('Experience gained:')
    ->not->toContain('123')
    ->not->toContain('Gold found:')
    ->and($window->getHelp())->toBe('enter:Fast Forward');

  $window->advance();

  expect(implode("\n", $window->getContent()))->toContain('Experience gained: 123')
    ->toContain('Gold found:')
    ->not->toContain('50G')
    ->and($window->isComplete())->toBeFalse();

  $window->advance();

  expect(implode("\n", $window->getContent()))->toContain('Gold found: 50G')
    ->toContain('Item drops:')
    ->not->toContain('Potion');

  $window->advance();

  expect(implode("\n", $window->getContent()))->toContain('Item drops: Potion')
    ->and($window->isComplete())->toBeTrue()
    ->and($window->getHelp())->toBe('enter:Continue');
});

it('keeps every structured loot row reachable instead of truncating the result', function () {
  $items = [];
  for ($i = 1; $i <= 23; $i++) {
    $items[] = ['id' => 'loot-' . $i, 'name' => 'Unique Reward ' . $i, 'description' => '', 'quantity' => $i];
  }
  $playback = new BattleResultsPlayback(new BattleRewards(0, 0, [], $items), reducedMotion: true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  $all = implode("\n", $window->getContent());
  for ($i = 1; $i < $playback->pageCount(); $i++) {
    $playback->navigate(1);
    $window->displayPlayback($playback);
    $all .= "\n" . implode("\n", $window->getContent());
  }
  foreach ($items as $item) { expect($all)->toContain($item['name'] . ' x' . $item['quantity']); }
  expect($playback->isFinished())->toBeFalse();
  $playback->navigate(1);
  expect($playback->scrollOffset)->toBe($playback->pageCount() - 1);
  $playback->navigate(-1);
  expect($playback->scrollOffset)->toBe($playback->pageCount() - 2);
});

it('shows retained quantities rather than claiming capped inventory received every drop', function () {
  $playback = new BattleResultsPlayback(new BattleRewards(0, 2, [], [
    ['id' => 'potion', 'name' => 'Potion', 'description' => '', 'quantity' => 4, 'received' => 1],
  ]), reducedMotion: true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  expect(implode("\n", $window->getContent()))->toContain('Potion x4 (retained 1)');
});

it('hides the terminal action hint while its replacement is input locked', function () {
  $playback = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  expect($window->getHelp())->toBe('enter:Complete');
  $playback->confirm();
  $window->displayPlayback($playback);
  expect($window->getHelp())->toBe('');
  $playback->update(0.33);
  $window->displayPlayback($playback);
  expect($window->getHelp())->toBe('enter:Continue');
});

it('left aligns party names and right aligns progression across the complete content width', function () {
  $names = ['Kaelion', 'Liora', 'Drazek', 'Seraphis'];
  $awards = array_map(makeResultWindowProgression(...), $names, array_keys($names));
  $playback = new BattleResultsPlayback(new BattleRewards(50, 200, $awards), reducedMotion: true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  $value = 'Lv 1 -> 2  +50 EXP';
  expect($window->getContent()[0])->toBe('EXP per member: 50   Gold: 200 G')
    ->and($window->getContent()[1])->toBe('Party Progress')
    ->and($window->getHelp())->toBe('enter:Continue up/down:Page 1/2');
  foreach ($names as $index => $name) {
    $label = $name . ':';
    expect($window->getContent()[$index + 2])->toBe($label . str_repeat(' ',
      $window->getContentWidth() - strlen($label . $value)) . $value);
  }
  $window->displayPlayback($playback);
  expect($window->renderCount)->toBe(1);
});

it('aligns level values to the same right edge without changing the graphical text grid', function () {
  $playback = new BattleResultsPlayback(new BattleRewards(50, 200, [makeResultWindowProgression('Kaelion')]), true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  $playback->confirm();
  $window->displayPlayback($playback);
  expect($window->getTitle())->toBe('Victory - Level');
  foreach ([2 => ['HP', "140 \u{2192} 187 (+47)"], 3 => ['MP', "10 \u{2192} 19 (+9)"]] as $index => [$label, $value]) {
    expect($window->getContent()[$index])->toBe($label . str_repeat(' ',
      $window->getContentWidth() - TerminalText::displayWidth($label . $value)) . $value);
  }
  foreach (\Ichiloto\Engine\Battle\Presentation\BattleResultsContent::getEventLines($playback) as $line) {
    expect(mb_strlen($line['text']))->toBeLessThanOrEqual(50);
  }
});

it('measures wide and combining character names in terminal cells and removes control styling', function () {
  $names = ["\e[31m\u{754C}\u{754C}\e[0m", "Jose\u{0301}"];
  $awards = array_map(makeResultWindowProgression(...), $names, array_keys($names));
  $playback = new BattleResultsPlayback(new BattleRewards(50, 200, $awards), true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  foreach ($names as $index => $name) {
    $line = $window->getContent()[$index + 2];
    expect($line)->toStartWith(TerminalText::stripAnsi($name) . ':')->toEndWith('Lv 1 -> 2  +50 EXP')
      ->not->toContain("\e")->and(TerminalText::displayWidth($line))->toBe($window->getContentWidth());
  }
});

it('preserves oversized identities and values on fixed pages when the party grows', function () {
  $awards = [];
  foreach (range(1, 19) as $index) { $awards[] = makeResultWindowProgression('Party member ' . $index, $index, $index); }
  $name = str_repeat('UnbrokenIdentity', 12);
  $awards[] = makeResultWindowProgression($name, 20, PHP_INT_MAX);
  $playback = new BattleResultsPlayback(new BattleRewards(50, 200, $awards,
    [['id' => 'potion', 'name' => 'Potion', 'description' => '', 'quantity' => 4, 'received' => 1]],
    ['Actual summary' => 'Still retained']), true);
  $window = new BattleResultWindowTestProxy(makeBattleResultTestScreen());
  $window->displayPlayback($playback);
  $bounds = $window->getBounds();
  $content = [];
  for ($page = 0; $page < $playback->pageCount(); $page++) {
    $window->displayPlayback($playback);
    expect($window->getBounds())->toEqual($bounds)->and($window->getContent())->toHaveCount(6)
      ->and($window->getHelp())->toBe(sprintf('enter:Continue up/down:Page %d/%d', $page + 1, $playback->pageCount()));
    foreach ($window->getContent() as $line) {
      expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($window->getContentWidth());
    }
    array_push($content, ...$window->getContent());
    $playback->navigate(1);
  }
  $text = implode("\n", $content);
  foreach (range(1, 19) as $index) {
    $line = array_values(array_filter($content, static fn(string $line): bool => str_starts_with($line, 'Party member ' . $index . ':')))[0];
    expect($line)->toEndWith('Lv 1 -> 2  +' . $index . ' EXP');
  }
  expect(preg_replace('/\s+/u', '', $text))->toContain($name . ':')
    ->and($text)->toContain('+' . PHP_INT_MAX . ' EXP', 'Potion x4 (retained 1)', 'Actual summary: Still retained')
    ->and($playback->isFinished())->toBeFalse();
  $playback->navigate(-1);
  $window->displayPlayback($playback);
  expect($window->getBounds())->toEqual($bounds)->and($playback->scrollOffset)->toBe($playback->pageCount() - 2);
});

it('wraps each complete fact without truncation at narrower content widths', function (int $width) {
  $award = makeResultWindowProgression(str_repeat('Identity', 20), experience: PHP_INT_MAX);
  $playback = new BattleResultsPlayback(new BattleRewards(PHP_INT_MAX, PHP_INT_MAX, [$award]), true);
  $lines = BattleResultsText::getLines($playback, $width);
  foreach ($lines as $line) { expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($width); }
  $text = preg_replace('/\s+/u', '', implode('', $lines));
  expect($text)->toContain(str_repeat('Identity', 20) . ':', 'Lv1->2+' . PHP_INT_MAX . 'EXP',
    'EXPpermember:' . PHP_INT_MAX, 'Gold:' . PHP_INT_MAX . 'G');
})->with([16, 40, 60]);

it('renders aligned party progress inside intact fixed borders', function () {
  $state = new ReflectionClass(Console::class)->getStaticProperties();
  try {
    Console::setTerminalOutputEnabled(false);
    new ReflectionProperty(Console::class, 'overlays')->setValue(null, []);
    Console::syncDimensions(BattleScreen::WIDTH, BattleScreen::HEIGHT);
    $screen = makeBattleResultTestScreen();
    $window = new BattleResultWindow($screen);
    $playback = new BattleResultsPlayback(new BattleRewards(50, 200, array_map(makeResultWindowProgression(...),
      ['Kaelion', 'Liora', 'Drazek', 'Seraphis'], range(0, 3))), true);
    $window->displayPlayback($playback);
    $bounds = $window->getBounds();
    $rows = array_slice(Console::snapshot()->rows, $bounds->getTop(), $bounds->getHeight());
    foreach (range(2, 5) as $index) {
      $content = $window->getContent()[$index];
      $row = TerminalText::sliceSymbols($rows[$index + 1], $bounds->getLeft(), $bounds->getWidth());
      expect($row)->toBe($screen->borderPack->getVerticalBorder() . ' ' . $content . ' ' . $screen->borderPack->getVerticalBorder())
        ->and(TerminalText::displayWidth($row))->toBe($bounds->getWidth());
    }
  } finally {
    foreach ($state as $key => $value) { new ReflectionProperty(Console::class, $key)->setValue(null, $value); }
  }
});
