<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\SummonsMenuState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\UI\Presentation\SummonsMenuView;
use Ichiloto\Engine\UI\Presentation\SummonEquipmentSlot;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Tests\Support\Input\FakeInputSource;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class SummonMenuRoutingScene extends GameScene
{
  public ?\Ichiloto\Engine\Scenes\Game\States\GameSceneState $requestedState = null;
  public function __construct() {}
  public function setState(\Ichiloto\Engine\Scenes\Game\States\GameSceneState $state): void { $this->requestedState = $state; }
}

beforeEach(function () {
  $this->summonsStatics = [];
  foreach ([Console::class, ConfigStore::class, ActionHints::class, InputManager::class] as $class) {
    $this->summonsStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 40);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 40]));
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::C]]]);
  $this->summonsRoot = createTestDirectory('summons-menu');
  foreach (['panel.png', 'quiet.png', 'portrait.png'] as $name) { writeTestPng($this->summonsRoot . '/' . $name, 32, 32); }
});

afterEach(function () {
  foreach ($this->summonsStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function createSummonsMenuProbe(array $definitions, bool $characterContext = false): SummonsMenuState
{
  $actor = new Character('Actor', 1, new Stats(currentHp: 100), actorId: 'actor.synthetic');
  $party = new Party(); $party->addMember($actor);
  $scene = new SummonMenuRoutingScene();
  new ReflectionProperty(GameScene::class, 'party')->setValue($scene, $party);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
  $state = new SummonsMenuState(new SceneStateContext($scene));
  $state->character = $characterContext ? $actor : null;
  new ReflectionProperty($state, 'view')->setValue($state, $characterContext ? SummonsMenuView::CHARACTER : SummonsMenuView::SUMMONS);
  new ReflectionProperty(SummonsMenuState::class, 'availableSummons')->setValue($state, $definitions);
  new ReflectionMethod($state, 'initializeUI')->invoke($state);
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  return $state;
}

function summonMenuKey(SummonsMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

it('removes the character header from summon browsing and keeps separate info in replaceable theme frames', function (bool $alternative) {
  $data = ['schema' => 'ichiloto.menu/1', 'portraits' => ['actor.synthetic' => 'portrait.png'],
    'frames' => ['panel' => ['asset' => 'panel.png', 'cuts' => [4, 4, 4, 4]],
      'quiet' => ['asset' => 'quiet.png', 'cuts' => [4, 4, 4, 4]]]];
  if ($alternative) {
    $data['metrics'] = ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88];
    $data['colors'] = ['text' => [43, 30, 10], 'background' => [237, 230, 210], 'panel' => [245, 235, 225]];
  }
  $theme = new MenuPresentationCatalog($this->summonsRoot, $data);
  $definition = new SummonCutsceneDefinition('synthetic', 'Spirit', 'Useful description', 'Spirit move', new SummonWielderPolicy());
  $state = createSummonsMenuProbe([$definition]);
  $before = serialize($state->party);
  foreach ([SummonsMenuView::ACTIONS, SummonsMenuView::HOLDERS, SummonsMenuView::INFO, SummonsMenuView::SUMMONS] as $view) {
    new ReflectionProperty($state, 'view')->setValue($state, $view);
    new ReflectionProperty($state, 'selectedSummonId')->setValue($state, $definition->id);
    new ReflectionMethod($state, 'refreshUI')->invoke($state);
    $frame = new ReflectionMethod($state, 'composeMenuCanvas')->invoke($state, $theme, 0.0);
    expect($frame)->not->toBeNull()->and(array_column($frame->images, 'asset'))->toContain('panel.png', 'quiet.png')->not->toContain('portrait.png');
    $texts = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers)));
    expect($texts)->toContain('Spirit')->not->toContain('arrow keys');
    if ($view === SummonsMenuView::HOLDERS) { expect($texts)->toContain('Actor'); }
    else { expect($texts)->not->toContain('Actor'); }
    expect(serialize($state->party))->toBe($before);
  }
  $member = $state->party->leader;
  expect($state->party->assignSummon($definition, $member))->toBeTrue();
  expect($state->getPresentationContent()->rows[0]->values[1]->text)->toBe($member->name);
  $state->party->unassignSummon($definition->id, $member);
  expect($state->getPresentationContent()->rows[0]->values[1]->text)->toBe('');
})->with([false, true]);

it('exposes complete long contracts through the shared scroll viewport and semantic controls', function () {
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return new ControlHint('controller', $action, 'Pad'); }
  });
  $lore = implode("\n", array_map(static fn($i) => 'Contract line ' . $i, range(1, 100)));
  $state = createSummonsMenuProbe([new SummonCutsceneDefinition('long', 'Long contract', lore: $lore)]);
  new ReflectionProperty($state, 'view')->setValue($state, SummonsMenuView::INFO);
  new ReflectionProperty($state, 'selectedSummonId')->setValue($state, 'long');
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  $theme = new MenuPresentationCatalog($this->summonsRoot, ['schema' => 'ichiloto.menu/1', 'showInputHints' => true]);
  $frame = new ReflectionMethod($state, 'composeMenuCanvas')->invoke($state, $theme, 0.0);
  $texts = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers)));
  expect($texts)->toContain('Pad', 'Scroll down')->not->toContain('enter:', 'tab:', 'c:');
  $viewport = new ReflectionProperty($state, 'detailViewport')->getValue($state);
  $page = new ReflectionProperty($state, 'lastDetailPage')->getValue($state);
  expect($page->total)->toBeGreaterThan($page->rows)->and($page->source)->toContain('Contract line 100');
  for ($i = 0; $i < $page->total; $i++) { $viewport->scroll(1, $page); $page = $viewport->page($page->columns, $page->rows); }
  expect(implode("\n", $page->lines))->toContain('Contract line 100');
  new ReflectionMethod($state, 'composeMenuCanvas')->invoke($state, $theme, 0.0);
  $help = new ReflectionMethod($state, 'getActionHints')->invoke($state);
  expect(array_column($help, 'action'))->toContain('cancel', 'up', 'down')->not->toContain('confirm', 'character_next');
});


it('removes eligibility rosters and only offers assignable summons in the Equipment character context', function () {
  $allowed = new SummonCutsceneDefinition('allowed', 'Allowed spirit',
    wielders: new SummonWielderPolicy(SummonWielderPolicy::MODE_CHARACTERS,
      characters: ['actor.synthetic', 'future.identity'], tenancy: SummonWielderPolicy::TENANCY_EXCLUSIVE));
  $future = new SummonCutsceneDefinition('future', 'Future-only spirit',
    wielders: new SummonWielderPolicy(SummonWielderPolicy::MODE_CHARACTERS, characters: ['future.identity']));
  $state = createSummonsMenuProbe([$allowed, $future], true);
  $before = serialize([$allowed->wielders, $future->wielders]);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Allowed spirit']);
  $detail = new ReflectionMethod($state, 'getDetailText')->invoke($state);
  expect($detail)->not->toContain('future.identity', 'Wielders', 'One bearer', 'Actor')
    ->and($state->getPresentationContent()->status)->toBeNull();
  $later = new Character('Later hero', 1, new Stats(currentHp: 100), actorId: 'future.identity');
  $state->party->addMember($later);
  $state->character = $later;
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Allowed spirit', 'Future-only spirit']);
  $state->party->assignSummon($allowed, $later);
  $first = $state->party->members->toArray()[0];
  $state->character = $first;
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  expect($state->getPresentationContent()->rows)->toBe([]);
  $state->party->unassignSummon($allowed->id, $later);
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Allowed spirit']);
  expect(serialize([$allowed->wielders, $future->wielders]))->toBe($before);
});

it('offers separate Info and Assign actions and never assigns while reading or confirming Info', function () {
  $definition = new SummonCutsceneDefinition('contract', 'Contract', wielders: new SummonWielderPolicy());
  $state = createSummonsMenuProbe([$definition]);
  $before = serialize($state->party);
  expect($state->getPresentationContent()->character)->toBeNull();
  summonMenuKey($state, KeyCode::ENTER);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Info', 'Assign']);
  summonMenuKey($state, KeyCode::ENTER);
  expect($state->getPresentationContent()->detailsOpen)->toBeTrue();
  summonMenuKey($state, KeyCode::ENTER);
  expect(serialize($state->party))->toBe($before);
  summonMenuKey($state, KeyCode::C);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Info', 'Assign']);
});

it('keeps discovered contracts visible and assigns atomically among eligible current members without future-character spoilers', function () {
  $definition = new SummonCutsceneDefinition('exclusive', 'Exclusive', wielders: new SummonWielderPolicy(
    SummonWielderPolicy::MODE_CHARACTERS, characters: ['actor.synthetic', 'actor.second', 'future.identity'],
    tenancy: SummonWielderPolicy::TENANCY_EXCLUSIVE));
  $state = createSummonsMenuProbe([$definition]);
  $other = new Character('Second member', 1, new Stats(currentHp: 10), actorId: 'actor.second');
  $ineligible = new Character('Ineligible member', 1, new Stats(currentHp: 10), actorId: 'actor.other');
  $state->party->addMember($other);
  $state->party->addMember($ineligible);
  $first = $state->party->leader;
  $state->party->assignSummon($definition, $first);
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  summonMenuKey($state, KeyCode::ENTER);
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  summonMenuKey($state, KeyCode::ENTER);
  $content = $state->getPresentationContent();
  expect(array_column($content->rows, 'label'))->toBe([$first->name, $other->name])
    ->and(serialize($content))->not->toContain('future.identity', 'Ineligible member');
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  summonMenuKey($state, KeyCode::ENTER);
  expect($first->hasSummon($definition->id))->toBeFalse()->and($other->hasSummon($definition->id))->toBeTrue();
  summonMenuKey($state, KeyCode::ENTER);
  expect($other->hasSummon($definition->id))->toBeFalse();
});

it('supports several Equipment assignments while keeping physical equipment and inventory unchanged', function () {
  $definitions = array_map(static fn($id) => new SummonCutsceneDefinition($id, ucfirst($id), wielders: new SummonWielderPolicy()), ['one', 'two']);
  $state = createSummonsMenuProbe($definitions, true);
  $member = $state->character;
  $before = serialize([$member->equipment, $state->party->inventory]);
  summonMenuKey($state, KeyCode::ENTER);
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  summonMenuKey($state, KeyCode::ENTER);
  $slot = SummonEquipmentSlot::createForCharacter($state->party, $member, $state->getGameScene()->gameState, $definitions);
  expect($slot->getAssignedNames())->toBe(['One', 'Two'])
    ->and($slot->getRow(true, true)->values[0]->text)->toBe('One, Two')
    ->and(serialize([$member->equipment, $state->party->inventory]))->toBe($before);
  summonMenuKey($state, KeyCode::ENTER);
  expect($member->hasSummon('one'))->toBeTrue()->and($member->hasSummon('two'))->toBeFalse();
});

it('hides the Equipment summon slot when current contracts cannot be held by that character', function () {
  $definition = new SummonCutsceneDefinition('restricted', 'Restricted', wielders: new SummonWielderPolicy(
    SummonWielderPolicy::MODE_CHARACTERS, characters: ['future.identity']));
  $state = createSummonsMenuProbe([$definition]);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Restricted'])
    ->and(SummonEquipmentSlot::createForCharacter($state->party, $state->party->leader,
      $state->getGameScene()->gameState, [$definition]))->toBeNull();
  summonMenuKey($state, KeyCode::ENTER);
  expect(array_column($state->getPresentationContent()->rows, 'label'))->toBe(['Info']);
});

it('routes the main Summons command directly to the summon library and clears a previous character context', function () {
  $state = createSummonsMenuProbe([]);
  $scene = $state->getGameScene();
  new ReflectionProperty(GameScene::class, 'summonsMenuState')->setValue($scene, $state);
  $state->character = $scene->party->leader;
  $main = new \Ichiloto\Engine\Scenes\Game\States\MainMenuState(new SceneStateContext($scene));
  $menu = new \Ichiloto\Engine\Core\Menu\MainMenu\MainMenu($scene, '');
  $command = new \Ichiloto\Engine\Core\Menu\Commands\OpenSummonsMenuCommand($menu);
  $context = new \Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext(['state' => $main],
    new \Symfony\Component\Console\Output\BufferedOutput(), $menu, $scene);
  expect($command->execute($context))->toBe(0)
    ->and($scene->requestedState)->toBe($state)
    ->and($state->character)->toBeNull();
});

it('projects the same conditional multi-value row to Equipment Terminal and graphical consumers and routes its selection', function () {
  $definition = new SummonCutsceneDefinition('spirit', 'Spirit', wielders: new SummonWielderPolicy());
  $state = createSummonsMenuProbe([$definition]);
  $scene = $state->getGameScene();
  new ReflectionProperty(GameScene::class, 'summonsMenuState')->setValue($scene, $state);
  $equipment = new \Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState(new SceneStateContext($scene));
  $equipment->character = $scene->party->leader;
  new ReflectionProperty($equipment, 'summonDefinitions')->setValue($equipment, [$definition]);
  $scene->party->assignSummon($definition, $equipment->character);
  $menu = new \Ichiloto\Engine\Core\Menu\EquipmentMenu\EquipmentMenu($scene, '');
  $panel = new \Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\EquipmentAssignmentPanel($menu,
    new \Ichiloto\Engine\Core\Rect(0, 0, 70, 28), new \Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack());
  $panel->setSlots($equipment->character->equipment, $equipment->character, $equipment->getSummonSlot());
  $index = count($equipment->character->equipment);
  $panel->setActiveSlotByIndex($index);
  expect($panel->isSummonSlotSelected())->toBeTrue()->and($panel->activeSlot)->toBeNull();
  $rows = $equipment->getEquipmentSlotRows($index, true);
  expect($rows[$index]->id)->toBe('summon-slot')->and($rows[$index]->values[0]->text)->toBe('Spirit')
    ->and($rows[$index]->focused)->toBeTrue()
    ->and(implode("\n", $panel->getContent()))->toContain('Summons:', 'Spirit');
  new ReflectionProperty($equipment, 'equipmentMenu')->setValue($equipment, $menu);
  new ReflectionProperty($equipment, 'equipmentAssignmentPanel')->setValue($equipment, $panel);
  $theme = new MenuPresentationCatalog($this->summonsRoot, ['schema' => 'ichiloto.menu/1',
    'portraits' => ['actor.synthetic' => 'portrait.png']]);
  $frame = \Ichiloto\Engine\UI\Presentation\CharacterMenuPresentation::equipment($equipment,
    new \Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSlotSelectionMode($equipment), $theme);
  $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers)));
  expect($text)->toContain('Summons', 'Spirit');
  $equipment->openSummonSlot();
  expect($scene->requestedState)->toBe($state)->and($state->character)->toBe($equipment->character);
});

it('retains all menu-selected assignments through the existing character save representation', function () {
  $definitions = array_map(static fn($id) => new SummonCutsceneDefinition($id, ucfirst($id), wielders: new SummonWielderPolicy()), ['one', 'two']);
  $state = createSummonsMenuProbe($definitions, true);
  summonMenuKey($state, KeyCode::ENTER);
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  summonMenuKey($state, KeyCode::ENTER);
  $saved = $state->character->__serialize();
  $restored = new ReflectionClass(Character::class)->newInstanceWithoutConstructor();
  $restored->__unserialize($saved);
  expect($restored->summons)->toBe(['one', 'two']);
});

it('refreshes a reordered party before accepting confirmation against an obsolete holder row', function () {
  $definition = new SummonCutsceneDefinition('spirit', 'Spirit', wielders: new SummonWielderPolicy());
  $state = createSummonsMenuProbe([$definition]);
  $other = new Character('Other member', 1, new Stats(currentHp: 10), actorId: 'actor.other');
  $first = $state->party->leader;
  $state->party->addMember($other);
  summonMenuKey($state, KeyCode::ENTER);
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  summonMenuKey($state, KeyCode::ENTER);
  new ReflectionProperty($state, 'activeIndex')->setValue($state, 1);
  $state->party->swapMembers(0, 1);
  summonMenuKey($state, KeyCode::ENTER);
  expect($first->hasSummon('spirit'))->toBeFalse()->and($other->hasSummon('spirit'))->toBeFalse()
    ->and(array_column($state->getPresentationContent()->rows, 'label'))->toBe([$other->name, $first->name]);
});
