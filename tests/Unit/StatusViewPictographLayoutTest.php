<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;

class PictographStatusViewProxy extends StatusViewState
{
  public function renderCharacter(Character $character, int $left = 60, int $top = 2): void
  {
    $this->character = $character;
    $this->leftMargin = $left;
    $this->topMargin = $top;
    $this->initializeUI();
  }
}

it('keeps legacy plain equipment pictographs inside the status composition', function () {
  $character = new Character('Seraphis', 0, new Stats());
  $weaponSlot = $character->equipment[0];
  $weaponSlot->equipment = new Weapon(
    'Wooden Sword',
    'A training weapon.',
    '🗡',
    100,
  );

  Console::syncDimensions(230, 39);
  $view = (new ReflectionClass(PictographStatusViewProxy::class))->newInstanceWithoutConstructor();

  ob_start();
  $view->renderCharacter($character);
  ob_end_clean();

  $buffer = Console::getBuffer();

  // The status view emits its equipment row through the same canonical
  // console boundary used by shops, battle menus, and equipment screens.
  // A legacy one-code-point sword is made explicitly two cells there, so
  // its row cannot wrap and corrupt the Info panel below it.
  expect($buffer[12])->toContain('🗡️ Wooden Sword')
    ->and(TerminalText::displayWidth($buffer[12]))->toBe(230)
    ->and(TerminalText::stripAnsi($buffer[36]))->toContain('╚')
    ->and(TerminalText::stripAnsi($buffer[36]))->toContain('╝')
    ->and(TerminalText::displayWidth($buffer[36]))->toBe(230);
});
