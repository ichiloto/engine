<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
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

it('removes equipped item icon overrides from status while retaining type icons and pictograph widths in names', function () {
  $character = new Character('Seraphis', 0, new Stats());
  $weaponSlot = $character->equipment[0];
  $weaponSlot->equipment = new Weapon(
    'Training 🗡',
    'A training weapon.',
    '💎',
    100,
  );

  Console::syncDimensions(230, 39);
  $view = (new ReflectionClass(PictographStatusViewProxy::class))->newInstanceWithoutConstructor();

  ob_start();
  $view->renderCharacter($character);
  ob_end_clean();

  $buffer = Console::getBuffer();

  // Item-specific icons no longer identify a slot. Authored item names still pass
  // through the canonical width boundary without corrupting the panel below.
  expect($buffer[12])->toContain('Training 🗡️')
    ->and($buffer[12])->toContain(EquipmentIcon::getTerminalGlyph(EquipmentSlotType::WEAPON))
    ->and($buffer[12])->not->toContain('💎')
    ->and(TerminalText::displayWidth($buffer[12]))->toBe(230)
    ->and(TerminalText::stripAnsi($buffer[36]))->toContain('╚')
    ->and(TerminalText::stripAnsi($buffer[36]))->toContain('╝')
    ->and(TerminalText::displayWidth($buffer[36]))->toBe(230);
});
