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
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->summonsStatics = [];
  foreach ([Console::class, ConfigStore::class, ActionHints::class] as $class) {
    $this->summonsStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 40);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 40]));
  $this->summonsRoot = createTestDirectory('summons-menu');
  foreach (['panel.png', 'quiet.png', 'portrait.png'] as $name) { writeTestPng($this->summonsRoot . '/' . $name, 32, 32); }
});

afterEach(function () {
  foreach ($this->summonsStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function createSummonsMenuProbe(array $definitions): SummonsMenuState
{
  $actor = new Character('Actor', 1, new Stats(currentHp: 100), actorId: 'actor.synthetic');
  $party = new Party(); $party->addMember($actor);
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(GameScene::class, 'party')->setValue($scene, $party);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
  $state = new SummonsMenuState(new SceneStateContext($scene));
  $state->character = $actor;
  new ReflectionProperty(SummonsMenuState::class, 'availableSummons')->setValue($state, $definitions);
  new ReflectionMethod($state, 'initializeUI')->invoke($state);
  new ReflectionMethod($state, 'refreshUI')->invoke($state);
  return $state;
}

it('uses replaceable shared theme frames for list details and assignment status', function (bool $alternative) {
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
  foreach ([false, true] as $details) {
    new ReflectionProperty($state, 'viewingDetail')->setValue($state, $details);
    new ReflectionMethod($state, 'refreshUI')->invoke($state);
    $frame = new ReflectionMethod($state, 'composeMenuCanvas')->invoke($state, $theme, 0.0);
    expect($frame)->not->toBeNull()->and(array_column($frame->images, 'asset'))->toContain('panel.png', 'quiet.png', 'portrait.png');
    $texts = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers)));
    expect($texts)->toContain('Summons', 'Actor', 'Spirit')->not->toContain('arrow keys');
    expect(serialize($state->party))->toBe($before);
  }
  expect($state->party->assignSummon($definition, $state->character))->toBeTrue();
  expect($state->getPresentationContent()->rows[0]->values[1]->text)->toBe('Assigned');
  $state->party->unassignSummon($definition->id, $state->character);
  expect($state->getPresentationContent()->rows[0]->values[1]->text)->toBe('');
})->with([false, true]);

it('exposes complete long contracts through the shared scroll viewport and semantic controls', function () {
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return new ControlHint('controller', $action, 'Pad'); }
  });
  $lore = implode("\n", array_map(static fn($i) => 'Contract line ' . $i, range(1, 100)));
  $state = createSummonsMenuProbe([new SummonCutsceneDefinition('long', 'Long contract', lore: $lore)]);
  new ReflectionProperty($state, 'viewingDetail')->setValue($state, true);
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
  expect(array_column($help, 'action'))->toContain('cancel', 'up', 'down', 'character_next');
});


it('removes eligibility rosters and only offers options assignable to the current party member', function () {
  $allowed = new SummonCutsceneDefinition('allowed', 'Allowed spirit',
    wielders: new SummonWielderPolicy(SummonWielderPolicy::MODE_CHARACTERS,
      characters: ['actor.synthetic', 'future.identity'], tenancy: SummonWielderPolicy::TENANCY_EXCLUSIVE));
  $future = new SummonCutsceneDefinition('future', 'Future-only spirit',
    wielders: new SummonWielderPolicy(SummonWielderPolicy::MODE_CHARACTERS, characters: ['future.identity']));
  $state = createSummonsMenuProbe([$allowed, $future]);
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
