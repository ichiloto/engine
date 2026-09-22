<?php

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\UI\BattleResultWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;

class BattleResultWindowTestProxy extends BattleResultWindow
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during unit tests.
  }
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
