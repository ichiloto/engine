<?php

use Ichiloto\Engine\Animations\Field\FieldCueStyle;
use Ichiloto\Engine\Animations\Field\FieldEdgeSprite;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldScreenSprite;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\Waymarks\{FieldWaymark, FieldWaymarkAnchor, FieldWaymarkRole};
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** A synthetic project: a looping one-image cue effect, replaceable pointer art and an optional plate. */
function writeCueStyleProject(string $root, array $style = [], array $pointer = [], ?string $plate = null): void
{
  writeTestPng($root . '/Graphics/mark.png', 16, 2);
  writeTestPng($root . '/Graphics/pointer.png', 3, 3);
  writeTestPng($root . '/Graphics/pointer-diagonal.png', 3, 3);
  writeTestPng($root . '/Graphics/plate.png', 4, 4);
  @mkdir($root . '/Animations/mark', 0777, true);
  file_put_contents($root . '/Animations/mark/mark.timeline.php', '<?php return ' . var_export(['fps' => 5, 'lengthFrames' => 2,
    'playback' => 'loop', 'restFrame' => 0, 'tracks' => [['id' => 'mark', 'type' => 'image', 'asset' => 'Graphics/mark.png',
      'sheet' => ['columns' => 8, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1], 'depth' => 'front',
      'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]]]]], true) . ';');
  @mkdir($root . '/Data/Presentation', 0777, true);
  $turns = ['east' => 0, 'south' => 1, 'west' => 2, 'north' => 3, 'northeast' => 0, 'southeast' => 1, 'southwest' => 2, 'northwest' => 3];
  $pointer = $pointer ?: array_map(null, array_combine(array_keys($turns), array_map(static fn(string $direction, int $turn): array => [
    'asset' => str_contains(substr($direction, 1), 'east') || str_contains(substr($direction, 1), 'west')
      ? 'Graphics/pointer-diagonal.png' : 'Graphics/pointer.png', 'quarterTurns' => $turn], array_keys($turns), $turns)));
  file_put_contents($root . '/Data/Presentation/field.php', '<?php return ' . var_export([
    'cues' => ['bright-yellow' => ['effect' => 'mark', 'pointer' => $pointer, ...($plate === null ? [] : ['plate' => $plate])]],
    'cueStyle' => $style], true) . ';');
}

function showWaymarkAt(FieldEffectManager $manager, Vector2 $cell): void
{
  $manager->showWaymarks([new FieldWaymark('w', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor($cell), 'Go')]);
}

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-cue-style-');
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  $this->view = new FieldViewport(new RendererGridConfig(135, 36, 10, 20));
});

afterEach(function () {
  foreach ($this->debug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('reads the cue style with Engine defaults and refuses values the presentation cannot honour', function () {
  $style = FieldCueStyle::fromArray(['bob' => ['height' => 3, 'seconds' => 1.5],
    'pointerSpacing' => ['x' => 14, 'y' => 26], 'terminal' => ['background' => null, 'bold' => false]]);

  expect($style->bobHeight)->toBe(3)->and($style->bobSeconds)->toBe(1.5)
    ->and($style->pointerSpacingX)->toBe(14)->and($style->pointerSpacingY)->toBe(26)->and($style->styleGlyph('!', 'bright-yellow'))->toBe('<fg=bright-yellow>!</>')
    ->and(FieldCueStyle::fromArray(null)->styleGlyph('!', 'bright-yellow'))->toBe('<fg=bright-yellow;bg=black;options=bold>!</>');
  foreach ([['bob' => ['height' => 13]], ['bob' => ['seconds' => 0.1]], ['pointerSpacing' => ['y' => 97]], ['terminal' => ['background' => 'plaid']],
    ['outline' => 2], ['plate' => 'Graphics/plate.png']] as $invalid) {
    expect(fn() => FieldCueStyle::fromArray($invalid))->toThrow(InvalidArgumentException::class);
  }
});

it('reads each cue colour\'s own plate and refuses one outside the assets', function () {
  writeCueStyleProject($this->root, plate: 'Graphics/plate.png');
  $catalog = FieldPresentationCatalog::load($this->root);

  expect($catalog->cues['bright-yellow']['plate'])->toBe('Graphics/plate.png')
    ->and(fn() => FieldPresentationCatalog::fromArray(['cues' => ['bright-yellow' => ['effect' => 'mark', 'plate' => '../outside.png']]],
      $this->root))->toThrow(InvalidArgumentException::class);
});

it('refuses cue art that bakes the mark into rotating edge images', function () {
  expect(fn() => FieldPresentationCatalog::fromArray(['cues' => ['bright-yellow' => ['effect' => 'mark',
    'edges' => ['east' => ['asset' => 'Graphics/pointer.png']]]]], $this->root))
    ->toThrow(InvalidArgumentException::class, 'Cue edges are now an upright marker and a turning pointer: replace edges with pointer images.');
});

it('bobs gently and holds still under reduced motion or without a bob', function () {
  $style = new FieldCueStyle(bobHeight: 4, bobSeconds: 1.0);
  $lifts = array_map(static fn(float $t): int => $style->getBobLift($t, false), [0.0, 0.25, 0.5, 0.75, 1.0]);

  expect($lifts)->toBe([0, 2, 4, 2, 0])->and($style->getBobLift(0.5, true))->toBe(0)
    ->and((new FieldCueStyle())->getBobLift(0.5, false))->toBe(0);
});

it('keeps an edge marker upright and turns only its pointer, outward, in all eight directions', function (int $x, int $y,
  string $direction) {
  writeCueStyleProject($this->root, ['pointerSpacing' => ['x' => 24, 'y' => 30]], plate: 'Graphics/plate.png');
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', [], null, []);
  showWaymarkAt($manager, new Vector2($x, $y));
  $sprites = $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], false);
  $pointer = array_values(array_filter($sprites, static fn($sprite) => $sprite instanceof FieldEdgeSprite))[0];
  $marks = array_values(array_filter($sprites, static fn($sprite) => $sprite instanceof FieldScreenSprite && !$sprite instanceof FieldEdgeSprite));
  $plate = array_values(array_filter($marks, static fn($sprite) => str_ends_with($sprite->getGraphicalSpriteId(), ':edge-plate')))[0];
  $mark = array_values(array_filter($marks, static fn($sprite) => str_contains($sprite->getGraphicalSpriteId(), ':edge-mark')))[0];
  $catalog = FieldPresentationCatalog::load($this->root);
  $centre = static function ($sprite) {
    $definition = $sprite->getGraphicalSpriteDefinition();
    $position = $sprite->getGraphicalSpriteWorldPosition();
    return [($position->x + 0.5) * 10 + (0.5 - ($definition->pivot?->x ?? 0.5)) * $definition->width,
      ($position->y + 1) * 20 - $definition->lift - $definition->height / 2];
  };
  [$px, $py] = $centre($pointer);
  [$mx, $my] = $centre($mark);
  $angle = array_search($direction, FieldPresentationCatalog::DIRECTIONS, true) * M_PI / 4;

  expect($pointer->getGraphicalSpriteDefinition()->quarterTurns)
    ->toBe($catalog->cues['bright-yellow']['pointer'][$direction]['quarterTurns'])
    ->and($mark->getGraphicalSpriteDefinition()->quarterTurns)->toBe(0)
    ->and($plate->getGraphicalSpriteDefinition()->quarterTurns)->toBe(0)
    ->and($plate->getGraphicalSpriteDefinition()->layer)->toBe(PresentationLayerPolicy::FIELD_GUIDANCE)
    ->and($mark->getGraphicalSpriteDefinition()->layer)->toBe(PresentationLayerPolicy::FIELD_GUIDANCE_FRONT)
    ->and($plate->getGraphicalSpriteWorldPosition())->toEqual($mark->getGraphicalSpriteWorldPosition())
    ->and(($px - $mx) * cos($angle) + ($py - $my) * sin($angle))->toBeGreaterThan(10.0)
    ->and($pointer->getGraphicalSpriteWorldPosition()->x)->toBeGreaterThanOrEqual(0)->toBeLessThan(135)
    ->and($mark->getGraphicalSpriteWorldPosition()->y)->toBeGreaterThanOrEqual(0)->toBeLessThan(36);
})->with([[100, 7, 'east'], [100, 100, 'southeast'], [13, 100, 'south'], [-100, 100, 'southwest'],
  [-100, 7, 'west'], [-100, -100, 'northwest'], [13, -100, 'north'], [100, -100, 'northeast']]);

it('puts every event cue on its plate and bobs it, holding still under reduced motion', function (bool $reduced) {
  writeCueStyleProject($this->root, ['bob' => ['height' => 4, 'seconds' => 1.0]], plate: 'Graphics/plate.png');
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $cue = new ScriptEventTrigger(new Rect(3, 2, 1, 1), ['mode' => 'action', 'reusable' => false], mapId: 'map', marker: 'E',
    cue: ['symbol' => '!', 'color' => 'bright-yellow']);
  $manager->installMap('map', [], null, [$cue]);
  $manager->update(0.5, $reduced, []);
  $sprites = $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], $reduced);
  $plate = array_values(array_filter($sprites, static fn($sprite) => str_ends_with($sprite->getGraphicalSpriteId(), ':plate')));
  $marks = array_values(array_filter($sprites, static fn($sprite) => !str_ends_with($sprite->getGraphicalSpriteId(), ':plate')));

  expect($plate)->toHaveCount(1)->and($marks)->not->toBe([])
    ->and($plate[0]->getGraphicalSpriteDefinition()->lift)->toBe($reduced ? 0 : 4)
    ->and($marks[0]->getGraphicalSpriteDefinition()->lift)->toBe($reduced ? 0 : 4)
    ->and($plate[0]->getGraphicalSpriteWorldPosition())->toEqual(new Vector2(3, 2));
})->with(['moving' => [false], 'reduced motion' => [true]]);

it('drops the plate and bob without new art or data changes beyond the style', function () {
  writeCueStyleProject($this->root);
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', [], null, []);
  showWaymarkAt($manager, new Vector2(3, 2));
  $manager->update(0.5, false, []);
  $sprites = $manager->getSprites([], $this->view, ['x' => 0, 'y' => 0], false);

  expect(array_filter($sprites, static fn($sprite) => str_contains($sprite->getGraphicalSpriteId(), 'plate')))->toBe([])
    ->and($sprites[0]->getGraphicalSpriteDefinition()->lift)->toBe(0)
    ->and($manager->canPresentWaymarks())->toBeTrue();
});

/* NPC cues share the event cue contract */

function makeCuedNpc(?array $cue, Vector2 $position = new Vector2(4, 3)): Ichiloto\Engine\Field\Npc
{
  $npc = new Ichiloto\Engine\Field\Npc('Halen', '@', $position, id: 'halen',
    pages: Ichiloto\Engine\Field\NpcPages::fromEntry(['id' => 'halen', 'cue' => $cue]),
    graphicalSpriteId: 'npc:map:test:id:halen');
  $npc->bind('test', new Ichiloto\Engine\Core\GameState());
  return $npc;
}

it('reads an NPC cue with the event cue validator, refusing what events refuse', function () {
  expect(makeCuedNpc(['symbol' => '!', 'color' => 'bright-yellow'])->cue?->symbol)->toBe('!')
    ->and(makeCuedNpc(null)->cue)->toBeNull()
    ->and(fn() => makeCuedNpc(['symbol' => '!!', 'color' => 'bright-yellow']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => makeCuedNpc(['symbol' => '!', 'color' => 'plaid']))->toThrow(InvalidArgumentException::class);
});

it('gates an NPC waymark by its presence and its own cue', function () {
  $read = Ichiloto\Engine\Field\MapSubjectGates::readMap(['npcs' => [[
    'id' => 'halen', 'name' => 'Halen', 'x' => 1, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'arrived']],
    'cue' => ['symbol' => '!', 'color' => 'bright-yellow', 'conditions' => [['type' => 'event', 'name' => 'rescued', 'negate' => true]]],
  ]]]);
  $gates = new Ichiloto\Engine\Field\MapSubjectGates([], ['garden' => $read['npcs']]);
  $state = new Ichiloto\Engine\Core\GameState();

  expect($gates->allowsNpc('garden', 'halen', $state))->toBeFalse();
  $state->recordStoryEvent('arrived');
  expect($gates->allowsNpc('garden', 'halen', $state))->toBeTrue();
  $state->recordStoryEvent('rescued');
  expect($gates->allowsNpc('garden', 'halen', $state))->toBeFalse();
});

it('draws an NPC cue above the NPC on its plate, follows it, and stands in for its waymark', function () {
  writeCueStyleProject($this->root, plate: 'Graphics/plate.png');
  $manager = new FieldEffectManager($this->root);
  $manager->setCapabilities(true, true);
  $manager->installMap('map', [], null, []);
  $npc = makeCuedNpc(['symbol' => '!', 'color' => 'bright-yellow']);
  $manager->showNpcCues([$npc]);
  $manager->showWaymarks([new FieldWaymark('w', FieldWaymarkRole::TARGET,
    new FieldWaymarkAnchor(new Vector2(4, 3), $npc->getGraphicalSpriteId()), 'Find Halen')]);
  $sprites = $manager->getSprites([$npc], $this->view, ['x' => 0, 'y' => 0], false);
  $ids = array_map(static fn($sprite) => $sprite->getGraphicalSpriteId(), $sprites);

  expect($manager->canPresentNpcCue($npc))->toBeTrue()
    ->and(array_filter($ids, static fn(string $id) => str_contains($id, 'waymark')))->toBe([])
    ->and(array_map(static fn($sprite) => $sprite->getGraphicalSpriteWorldPosition(), $sprites))
    ->each->toEqual(new Vector2(4, 2))
    ->and(array_filter($ids, static fn(string $id) => str_ends_with($id, ':plate')))->toHaveCount(1);

  $manager->showNpcCues([]);
  expect($manager->canPresentNpcCue($npc))->toBeFalse()
    ->and(array_filter(array_map(static fn($sprite) => $sprite->getGraphicalSpriteId(),
      $manager->getSprites([$npc], $this->view, ['x' => 0, 'y' => 0], false)), static fn(string $id) => str_contains($id, 'waymark')))
    ->not->toBe([]);
});

it('lets an NPC showing its own cue stand in for its Terminal waymark', function () {
  $waymark = new FieldWaymark('w', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor(new Vector2(4, 3), 'npc:halen'), 'Find Halen');
  $place = static fn(array $cued) => Ichiloto\Engine\Field\Waymarks\FieldWaymarkTerminalPresenter::place([$waymark],
    new Ichiloto\Engine\Animations\Field\FieldWaymarkStyle(), static fn(Vector2 $cell): Vector2 => $cell, 40, 20, null, $cued);

  expect($place([Ichiloto\Engine\Field\Waymarks\FieldWaymarkTerminalPresenter::OBJECT_KEY . 'npc:halen' => true]))->toBe([])
    ->and($place([]))->toHaveCount(1);
});
