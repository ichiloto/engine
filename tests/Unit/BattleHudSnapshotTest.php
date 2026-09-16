<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\Presentation\BattleHudListSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandWindow;
use Ichiloto\Engine\Battle\UI\BattleMessageWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowPadding;

trait BattleHudSnapshotRenderSpy
{
  public int $renderCalls = 0;
  public int $eraseCalls = 0;

  public function render(?int $x = null, ?int $y = null): void { $this->renderCalls++; }
  public function erase(?int $x = null, ?int $y = null): void { $this->eraseCalls++; }

  public function recordedState(): array
  {
    return [$this->content, $this->title, $this->help, $this->height, $this->renderCalls, $this->eraseCalls];
  }
}

class BattleHudCommandSpy extends BattleCommandWindow { use BattleHudSnapshotRenderSpy; }
class BattleHudContextSpy extends BattleCommandContextWindow { use BattleHudSnapshotRenderSpy; }
class BattleHudNameSpy extends BattleCharacterNameWindow { use BattleHudSnapshotRenderSpy; }
class BattleHudStatusSpy extends BattleCharacterStatusWindow { use BattleHudSnapshotRenderSpy; }
class BattleHudMessageSpy extends BattleMessageWindow { use BattleHudSnapshotRenderSpy; }

/** Real content/selection methods, with terminal output replaced only at the render boundary. */
function makeHudSnapshotScreen(): BattleScreen
{
  $screen = (new ReflectionClass(BattleScreen::class))->newInstanceWithoutConstructor();
  $scene = (new ReflectionClass(BattleScene::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty($screen, 'battleScene'))->setValue($screen, $scene);
  (new ReflectionProperty($screen, 'selectionColor'))->setValue($screen, Color::LIGHT_BLUE);
  foreach ([
    'commandWindow' => [BattleHudCommandSpy::class, 14, 'Command', 'i:Info'],
    'commandContextWindow' => [BattleHudContextSpy::class, 62, '', ''],
    'characterNameWindow' => [BattleHudNameSpy::class, 24, 'Name', 'c:Cancel'],
    'characterStatusWindow' => [BattleHudStatusSpy::class, 35, '', ''],
    'messageWindow' => [BattleHudMessageSpy::class, 131, 'Info', ''],
  ] as $property => [$class, $width, $title, $help]) {
    $window = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    foreach ([
      'battleScreen' => $screen, 'width' => $width, 'height' => $property === 'messageWindow' ? 3 : 6,
      'title' => $title, 'help' => $help, 'padding' => new WindowPadding(rightPadding: 1, leftPadding: 1),
      'borderPack' => new DefaultBorderPack(),
    ] as $field => $value) {
      (new ReflectionProperty($window, $field))->setValue($window, $value);
    }
    if ($window instanceof BattleCharacterStatusWindow) {
      (new ReflectionProperty($window, 'camera'))->setValue(
        $window, (new ReflectionClass(Camera::class))->newInstanceWithoutConstructor(),
      );
    }
    (new ReflectionProperty($screen, $property))->setValue($screen, $window);
  }
  return $screen;
}

function hudSnapshotOption(string $label, int $mpCost = 0): BattleCommandOption
{
  return new BattleCommandOption($label, "Authored info: {$label}", new AttackAction('Test'), mpCost: $mpCost);
}

it('copies exact command viewport rows and pagination without rendering or changing selection', function () {
  $window = makeHudSnapshotScreen()->commandWindow;
  $labels = ['Attack', 'Skill', 'Magic', 'Invoke / Wielder', 'Item', 'Guard', 'Escape'];
  $window->commands = $labels;
  $window->focus();
  for ($index = 0; $index < 6; $index++) { $window->selectNext(); }
  $window->setSelectionBlink(false);
  $before = $window->recordedState();

  $snapshot = $window->presentationSnapshot();
  expect($window->presentationSnapshot())->toEqual($snapshot)
    ->and($window->recordedState())->toBe($before)
    ->and(array_column($snapshot->rows, 'label'))->toBe(array_slice($labels, 3))
    ->and(array_column($snapshot->rows, 'index'))->toBe([3, 4, 5, 6])
    ->and(array_column($snapshot->rows, 'selected'))->toBe([false, false, false, true])
    ->and($snapshot->activeIndex)->toBe(6)
    ->and($snapshot->scrollOffset)->toBe(3)
    ->and($snapshot->visibleRowCount)->toBe(4)
    ->and($snapshot->totalRows)->toBe(7)
    ->and($snapshot->currentPage)->toBe(2)
    ->and($snapshot->totalPages)->toBe(2)
    ->and($snapshot->title)->toBe('Command 2/2')
    ->and($snapshot->help)->toBe('i:Info');

  $window->selectNext();
  expect($window->presentationSnapshot()->scrollOffset)->toBe(0)
    ->and($window->presentationSnapshot()->activeIndex)->toBe(0)
    ->and($snapshot->activeIndex)->toBe(6);
  $window->blur();
  expect(array_column($window->presentationSnapshot()->rows, 'selected'))->toBe([false, false, false, false]);
  $window->clear();
  expect($window->presentationSnapshot()->rows)->toBe([])
    ->and($window->presentationSnapshot()->activeIndex)->toBe(-1);
});

it('retains authored context costs labels descriptions and current affordability after scrolling', function () {
  $window = makeHudSnapshotScreen()->commandContextWindow;
  $labels = ['Fire (2 MP)', 'Ice (3 MP)', 'Potion x09', 'Call: Ancient Guardian (12 MP)',
    "\u{2694}\u{FE0F} Shadowstep (02 MP)", 'An exceptionally long authored option label that must not be abbreviated (20 MP)'];
  $costs = [2, 3, 0, 12, 2, 20];
  $items = array_map(hudSnapshotOption(...), $labels, $costs);
  $window->setMpBudget(3);
  $window->setItems($items, 'Magic / Skill');
  $window->focus();
  for ($index = 0; $index < 5; $index++) { $window->selectNext(); }
  $window->blur();
  $before = $window->recordedState();
  $activeItem = $window->getActiveItem();

  $snapshot = $window->presentationSnapshot();
  expect($window->presentationSnapshot())->toEqual($snapshot)
    ->and($window->recordedState())->toBe($before)
    ->and($window->getActiveItem())->toBe($activeItem)
    ->and(array_column($snapshot->rows, 'label'))->toBe(array_slice($labels, 2))
    ->and(array_column($snapshot->rows, 'index'))->toBe([2, 3, 4, 5])
    ->and(array_column($snapshot->rows, 'affordable'))->toBe([true, false, true, false])
    ->and(array_column($snapshot->rows, 'selected'))->toBe([false, false, false, true])
    ->and(array_column($snapshot->rows, 'mpCost'))->toBe([0, 12, 2, 20])
    ->and($snapshot->rows[3]->description)->toBe($items[5]->description)
    ->and($snapshot->title)->toBe('Magic / Skill 2/2')
    ->and($snapshot->help)->toBe('enter:Select i:Info c:Back')
    ->and($snapshot->scrollOffset)->toBe(2)
    ->and($snapshot->currentPage)->toBe(2)
    ->and($snapshot->totalPages)->toBe(2)
    ->and($snapshot->emptyMessage)->toBe('');

  $window->setMpBudget(null);
  expect(array_column($window->presentationSnapshot()->rows, 'affordable'))->toBe([true, true, true, true])
    ->and($snapshot->rows[3]->affordable)->toBeFalse()
    ->and($window->recordedState())->toBe($before);
});

it('preserves context empty messages and the distinction between empty and cleared content', function () {
  $window = makeHudSnapshotScreen()->commandContextWindow;
  $window->setItems([], 'Summon', 'No companions are available.');
  $before = $window->recordedState();
  $snapshot = $window->presentationSnapshot();
  expect($snapshot->rows)->toBe([])
    ->and($snapshot->emptyMessage)->toBe('No companions are available.')
    ->and($snapshot->title)->toBe('Summon')
    ->and($snapshot->help)->toBe('i:Info c:Back')
    ->and($snapshot->activeIndex)->toBe(-1)
    ->and($snapshot->scrollOffset)->toBe(0)
    ->and($snapshot->currentPage)->toBe(1)
    ->and($snapshot->totalPages)->toBe(1)
    ->and($window->recordedState())->toBe($before);
  $window->clear();
  $cleared = $window->presentationSnapshot();
  expect([$cleared->title, $cleared->help, $cleared->emptyMessage])->toBe(['', '', '']);
});

it('copies the four roster names without abbreviation or focus inference', function () {
  $window = makeHudSnapshotScreen()->characterNameWindow;
  $names = ['Kaelion', 'Liora of the Northern Mountains', "Drazek \u{00C9}toile", 'Serin'];
  $window->setNames($names);
  $window->setActiveSelection(2, blink: false);
  $before = $window->recordedState();
  $snapshot = $window->presentationSnapshot();
  expect(array_column($snapshot->rows, 'label'))->toBe($names)
    ->and(array_column($snapshot->rows, 'index'))->toBe([0, 1, 2, 3])
    ->and(array_column($snapshot->rows, 'selected'))->toBe([false, false, true, false])
    ->and($snapshot->title)->toBe('Name')
    ->and($snapshot->help)->toBe('c:Cancel')
    ->and($snapshot->visibleRowCount)->toBe(4)
    ->and($window->recordedState())->toBe($before);
});

it('reads live equipment-aware effective vitals without mutating battlers or terminal content', function () {
  $window = makeHudSnapshotScreen()->characterStatusWindow;
  $hero = new Character('Hero', 0, new Stats(currentHp: 100, currentMp: 20));
  $baseHp = $hero->effectiveStats->totalHp;
  $baseMp = $hero->effectiveStats->totalMp;
  $weaponSlot = $hero->equipment[0];
  $weaponSlot->equipment = new Weapon('Vital Blade', 'Test gear.', '!', 100,
    parameterChanges: new ParameterChanges(totalHp: 50, totalMp: 10));
  $characters = [$hero];
  foreach (['Second', 'Third', 'Fourth', 'Reserve'] as $name) {
    $characters[] = new Character($name, 0, new Stats());
  }
  $window->setCharacters($characters);
  $before = $window->recordedState();
  $hero->stats->currentHp = 1;
  $hero->stats->currentMp = 0;
  $beforeStats = $hero->stats->jsonSerialize();
  $snapshot = $window->presentationSnapshot();
  $row = $snapshot->rows[0];

  expect($window->presentationSnapshot())->toEqual($snapshot)
    ->and($window->recordedState())->toBe($before)
    ->and($hero->stats->jsonSerialize())->toBe($beforeStats)
    ->and($snapshot->rows)->toHaveCount(4)
    ->and(array_column($snapshot->rows, 'index'))->toBe([0, 1, 2, 3])
    ->and($row->currentHp)->toBe(1)
    ->and($row->currentMp)->toBe(0)
    ->and($row->totalHp)->toBe($baseHp + 50)
    ->and($row->totalMp)->toBe($baseMp + 10)
    ->and($row->hpRatio)->toBe(1 / ($baseHp + 50))
    ->and($row->mpRatio)->toBe(0.0)
    ->and($row->atbRatio)->toBeNull();
  $hero->stats->currentHp = 0;
  expect($window->presentationSnapshot()->rows[0]->hpRatio)->toBe(0.0)
    ->and($row->currentHp)->toBe(1);
});

it('only supplies ATB when the status window owns an ATB layout and keeps four rows', function () {
  $window = makeHudSnapshotScreen()->characterStatusWindow;
  $window->setCharacters(array_map(fn(string $name) => new Character($name, 0, new Stats()), ['A', 'B', 'C', 'D']));
  expect(array_column($window->presentationSnapshot()->rows, 'atbRatio'))->toBe([null, null, null, null]);
  $window->setAtbPercentages([0.5, 1.5, -0.5]);
  $before = $window->recordedState();
  $snapshot = $window->presentationSnapshot();
  expect(array_column($snapshot->rows, 'atbRatio'))->toBe([0.5, 1.0, 0.0, 0.0])
    ->and($snapshot->title)->toBe($window->getTitle())
    ->and($snapshot->title)->toContain('ATB')
    ->and($window->recordedState())->toBe($before);
  $window->clearAtbPercentages();
  expect(array_column($window->presentationSnapshot()->rows, 'atbRatio'))->toBe([null, null, null, null]);
});

it('keeps zero maxima finite and copied data deeply read only', function () {
  $stats = new BattleHudStatusRow(0, 0, 0, 0, 0);
  expect([$stats->hpRatio, $stats->mpRatio])->toBe([0.0, 0.0]);
  $row = new BattleHudRow(0, 'Authored', true);
  $list = new BattleHudListSnapshot('Command', 'i:Info', [$row], 0, 0, 4, 1, 1, 1);
  expect(fn() => $row->label = 'Changed')->toThrow(Error::class)
    ->and(fn() => $list->rows[] = $row)->toThrow(Error::class)
    ->and(fn() => $stats->currentHp = 7)->toThrow(Error::class);
  expect(fn() => new BattleHudListSnapshot('', '', array_fill(0, 5, $row), 0, 0, 4, 5, 1, 2))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new BattleHudStatusSnapshot('', '', array_fill(0, 5, $stats)))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new BattleHudListSnapshot('', '', [new stdClass()], 0, 0, 4, 1, 1, 1))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new BattleHudStatusSnapshot('', '', [new stdClass()]))
    ->toThrow(InvalidArgumentException::class);
});

it('detaches caller references from HUD snapshot row arrays', function (bool $status) {
  $original = $status
    ? new BattleHudStatusRow(0, 50, 100, 10, 20, 0.5)
    : new BattleHudRow(0, 'Fire (02 MP)', true);
  $row = $original;
  $rows = [&$row];
  $snapshot = $status
    ? new BattleHudStatusSnapshot('HP / MP', '', $rows)
    : new BattleHudListSnapshot('Magic', 'i:Info', $rows, 0, 0, 4, 1, 1, 1);

  $row = $status
    ? new BattleHudStatusRow(0, 0, 100, 0, 20)
    : new BattleHudRow(0, 'Replacement', false);
  expect($snapshot->rows)->toBe([$original]);

  $rows[0] = 'No longer a typed row';
  expect($snapshot->rows)->toBe([$original])
    ->and(ReflectionReference::fromArrayElement($snapshot->rows, 0))->toBeNull();
})->with(['command rows' => false, 'status rows' => true]);

it('rejects non-finite ATB ratios', function (float $ratio) {
  expect(fn() => new BattleHudStatusRow(0, 50, 100, 10, 20, $ratio))
    ->toThrow(InvalidArgumentException::class, 'Battle HUD ATB ratios must be finite.');
})->with(['NaN' => NAN, 'positive infinity' => INF, 'negative infinity' => -INF]);

it('copies current message text exactly including newlines and authored whitespace', function () {
  $window = makeHudSnapshotScreen()->messageWindow;
  $text = "  Fire (02 MP)\nSecond line\n";
  $window->setText($text);
  $before = $window->recordedState();
  expect($window->presentationSnapshot())->toBe($text)
    ->and($window->recordedState())->toBe($before);
  $window->setText('Replacement');
  expect($window->presentationSnapshot())->toBe('Replacement');
});

it('aggregates only owned visible panels without rendering or changing visibility', function () {
  $screen = makeHudSnapshotScreen();
  expect(BattleHudSnapshot::fromScreen($screen))->toEqual(new BattleHudSnapshot());
  $screen->commandWindow->commands = ['Attack'];
  $screen->showControls();
  $screen->showMessage('Choose an action.');
  $visible = $screen->presentationWindows();
  $before = array_map(fn($window) => $window->recordedState(), $visible);
  $snapshot = BattleHudSnapshot::fromScreen($screen);
  expect($snapshot->commands)->toEqual($screen->commandWindow->presentationSnapshot())
    ->and($snapshot->context)->toEqual($screen->commandContextWindow->presentationSnapshot())
    ->and($snapshot->names)->toEqual($screen->characterNameWindow->presentationSnapshot())
    ->and($snapshot->status)->toEqual($screen->characterStatusWindow->presentationSnapshot())
    ->and($snapshot->message)->toBe('Choose an action.')
    ->and($screen->presentationWindows())->toBe($visible)
    ->and(array_map(fn($window) => $window->recordedState(), $visible))->toBe($before);
  $screen->hideControls();
  expect(BattleHudSnapshot::fromScreen($screen))->toEqual(new BattleHudSnapshot(message: 'Choose an action.'));
  (new ReflectionProperty($screen, 'isMessageVisible'))->setValue($screen, false);
  expect(BattleHudSnapshot::fromScreen($screen))->toEqual(new BattleHudSnapshot());
});

it('keeps terminal row bytes unchanged across snapshot reads', function () {
  $screen = makeHudSnapshotScreen();
  $screen->commandWindow->commands = ['Attack'];
  $screen->commandWindow->focus();
  $screen->commandContextWindow->setItems([hudSnapshotOption('Fire (02 MP)', 2)], 'Magic');
  $screen->characterNameWindow->setNames(['Hero']);
  $hero = new Character('Hero', 0, new Stats(currentHp: 0, currentMp: 0));
  $screen->characterStatusWindow->setCharacters([$hero]);
  $screen->showControls();
  $windows = $screen->presentationWindows();
  $before = array_map(fn(Window $window) => $window->getContent(), $windows);
  BattleHudSnapshot::fromScreen($screen);
  expect(array_map(fn(Window $window) => $window->getContent(), $windows))->toBe($before)
    ->and($before[0])->toHaveCount(4)
    ->and($before[1])->toHaveCount(4)
    ->and($before[2])->toHaveCount(4)
    ->and($before[3])->toHaveCount(4)
    ->and(TerminalText::stripAnsi($before[0][0]))->toBe('> Attack  ')
    ->and(TerminalText::stripAnsi($before[1][0]))->toBe(str_pad('> Fire (02 MP)', 58))
    ->and($before[2][0])->toBe(str_pad('   Hero', 20))
    ->and($before[3][0])->toBe('   0 [          ]     0 [     ]');
});
