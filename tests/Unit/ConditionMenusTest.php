<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;
use Ichiloto\Engine\UI\Presentation\CharacterMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TerminalConditions;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

// Synthetic states, art and themes only; a game's own conditions are authored content.

class ConditionMenusStatusProbe extends StatusViewState
{
  public function conditionsFor(Character $character): array
  {
    $this->character = $character;
    $this->infoPanel = new Window('', '', new Vector2(0, 0), 110, self::INFO_PANEL_HEIGHT, new DefaultBorderPack());

    return $this->buildConditionsContent();
  }
}

function conditionMenusTheme(string $root, array $icons = [], bool $hints = false): MenuPresentationCatalog
{
  return new MenuPresentationCatalog($root, ['schema' => 'ichiloto.menu/1', 'icons' => $icons, 'showInputHints' => $hints]);
}

/** @return array<string, string> Text by layer id. */
function conditionMenusText(PresentationCanvas $canvas): array
{
  $text = [];
  foreach ($canvas->textLayers as $layer) {
    $text[$layer->id] = implode('', array_column($layer->runs, 'text'));
  }
  return $text;
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, ConfigStore::class, Debug::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-condition-menus-' . bin2hex(random_bytes(5));
  mkdir($this->root, 0777, true);
  writeTestPng($this->root . '/venom.png', 31, 47);
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->member = new Character('Walker', 0, new Stats(totalHp: 100, totalMp: 10));
  $this->venom = new State('venom', 'Venom', 'V', 'Saps HP while walking.', label: 'VNM', persistsAfterBattle: true);
  $this->ward = new State('ward', 'Ward', '', 'Turns blows aside.', label: 'WRD', disposition: StateDisposition::BENEFICIAL);
});

afterEach(function () {
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('lists each condition with its art, name and description on the graphical Status screen', function () {
  $this->member->addState($this->venom);
  $this->member->addState($this->ward);
  $canvas = CharacterMenuPresentation::status($this->member, conditionMenusTheme($this->root, ['status.state.venom' => 'venom.png']));
  $text = conditionMenusText($canvas);
  $rows = array_values(array_filter($text, static fn(string $value, string $id): bool =>
    str_starts_with($id, 'status-conditions-condition-') && str_ends_with($id, '-text'), ARRAY_FILTER_USE_BOTH));

  expect($text['status-conditions-title'])->toBe('Conditions')
    ->and($rows)->toBe(['VenomSaps HP while walking.', 'WardTurns blows aside.'])
    ->and(array_filter($canvas->images, static fn($image): bool => $image->asset === 'venom.png'))->toHaveCount(1)
    ->and($text)->toHaveKey('status-stats-grace-text');
  foreach ($canvas->images as $image) { $image->destination->assertWithin($canvas->width, $canvas->height); }
});

it('says a healthy character has no conditions and keeps the stats and equipment', function () {
  $text = conditionMenusText(CharacterMenuPresentation::status($this->member, conditionMenusTheme($this->root)));

  expect($text['status-conditions-none'])->toBe('None')
    ->and($text)->toHaveKeys(['status-stats-grace-text', 'status-slots-slot-0-text']);
});

it('falls back to compact badges when the descriptions cannot fit', function (bool $hints) {
  for ($index = 0; $index < 12; $index++) {
    $this->member->addState(new State('state-' . $index, 'State ' . $index, '',
      str_repeat('A long description that wraps. ', 8), label: 'S' . $index));
  }
  $canvas = CharacterMenuPresentation::status($this->member, conditionMenusTheme($this->root, hints: $hints));
  $badges = array_filter(conditionMenusText($canvas), static fn(string $value, string $id): bool =>
    str_starts_with($id, 'status-conditions-badges-'), ARRAY_FILTER_USE_BOTH);

  expect($badges)->toContain('S0', 'S11')
    ->and(array_filter(array_keys(conditionMenusText($canvas)), static fn(string $id): bool =>
      str_starts_with($id, 'status-conditions-condition-')))->toBe([]);
})->with([false, true]);

it('shows conditions in the Terminal Status screen and compact badges in Terminal summaries', function () {
  $status = new ReflectionClass(ConditionMenusStatusProbe::class)->newInstanceWithoutConstructor();

  expect(trim($status->conditionsFor($this->member)[0]))->toBe('None');

  $this->member->addState($this->venom);
  $this->member->addState($this->ward);
  $lines = array_map(static fn(string $line): string => trim(TerminalText::stripAnsi($line)), $status->conditionsFor($this->member));

  expect($lines[0])->toBe('V Venom: Saps HP while walking.')
    ->and($lines[1])->toBe('WRD Ward: Turns blows aside.')
    ->and(TerminalConditions::composeBadges($this->member))->toBe('V WRD')
    ->and(TerminalConditions::composeLines($this->member, 12))->toBe(['V Venom:', 'Saps HP', 'while', 'walking.', 'WRD Ward:', 'Turns blows', 'aside.']);
});
