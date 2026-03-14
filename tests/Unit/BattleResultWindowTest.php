<?php

use Ichiloto\Engine\Battle\BattleResult;
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
  $borderPack->setAccessible(true);
  $borderPack->setValue($screen, new DefaultBorderPack());

  $screenDimensions = $reflection->getProperty('screenDimensions');
  $screenDimensions->setAccessible(true);
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
