<?php

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleEffectResources;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Scenes\Battle\BattleScene;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Small synthetic production-path fixture, including a reserve and differently scaled action cells. */
function createEntryResourcePresentation(?\Ichiloto\Engine\Battle\Presentation\BattleContactEffects $contacts = null, int $frontlineSize = 1, bool $bindReserve = true, bool $allowReserveSwap = false): array
{
  $root = \Ichiloto\Engine\Tests\Support\TemporaryFixtures::createDirectory('battle-resource-cells');
  foreach (['arena' => [32, 32], 'standing' => [8, 8], 'enemy' => [8, 8], 'shared' => [16, 8],
    'move' => [24, 8], 'action' => [24, 8], 'reserve-action' => [8, 8], 'ripple-front' => [8, 8],
    'burrow-front' => [8, 8], 'reserve-standing' => [8, 8]] as $asset => [$width, $height]) {
    \Tests\Support\Rendering\writeTestPng($root . '/' . $asset . '.png', $width, $height);
  }
  $party = new \Ichiloto\Engine\Entities\Party();
  $party->setBattleSize($frontlineSize);
  foreach (['Front', 'Reserve'] as $name) { $party->addMember(new \Ichiloto\Engine\Entities\Character($name, 1,
    new \Ichiloto\Engine\Entities\Stats(currentHp: 100, totalHp: 100))); }
  $enemy = new ReflectionClass(\Ichiloto\Engine\Entities\Enemies\Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Foe', 'level' => 1, 'stats' => new \Ichiloto\Engine\Entities\Stats(currentHp: 100, totalHp: 100),
    'position' => new \Ichiloto\Engine\Core\Vector2(1, 1)] as $key => $value) {
    new ReflectionProperty($enemy, $key)->setValue($enemy, $value);
  }
  $battle = new \Ichiloto\Engine\Scenes\Battle\BattleConfig($party,
    new \Ichiloto\Engine\Entities\Troop('Synthetic', [$enemy], graphicalFormation: [getStageSlot(300, 350, 80, 80)]),
    settings: $allowReserveSwap ? ['reservePolicy' => 'replace_after_wipeout'] : []);
  $idle = new BattlerPose('shared.png', columns: 2, frames: [0, 1]);
  $front = new BattlePoseSet(['idle' => $idle, 'move' => new BattlerPose('move.png', columns: 3, frames: [0, 1, 2])],
    actionPoses: [99 => new BattlerPose('action.png', columns: 3, frames: [1], loop: false, impactFrame: 0,
      scaleSpan: .5, entryFrames: [0], exitFrames: [2], guardFrame: 0)]);
  $reserve = new BattlePoseSet(['idle' => $idle], actionPoses: [42 => new BattlerPose('reserve-action.png', loop: false, impactFrame: 0)]);
  $art = new BattlerArtwork('standing.png', 8, 8, 4, 8);
  $background = new CanvasImage('background', 'arena.png', new CanvasRectangle(0, 0, 20, 20),
    sourceRect: new SpriteSourceRect(2, 2, 20, 20), brightness: .8);
  $layout = new \Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout(1350, 720,
    partySlots: [getStageSlot(900, 350, 80, 80), ...($frontlineSize > 1 ? [getStageSlot(1100, 450, 80, 80, 1.25)] : [])], contactEffects: $contacts ?? new \Ichiloto\Engine\Battle\Presentation\BattleContactEffects());
  $catalog = new \Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog(['synthetic' => new BattleArenaDefinition('Synthetic', $background)],
    ['Front' => $art, ...($bindReserve ? ['Reserve' => new BattlerArtwork('reserve-standing.png', 8, 8, 4, 8)] : [])], ['Foe' => new BattlerArtwork('enemy.png', 8, 8, 4, 8)], ui: $layout,
    actorPoses: ['Front' => $front, 'Reserve' => $reserve], defaultArena: 'synthetic',
    scale: new \Ichiloto\Engine\Battle\Presentation\BattleScale('Front', 30,
      ['Front' => new \Ichiloto\Engine\Battle\Presentation\BattlerScale(1, 1),
        'Reserve' => new \Ichiloto\Engine\Battle\Presentation\BattlerScale(.8, 1)],
      ['Foe' => new \Ichiloto\Engine\Battle\Presentation\BattlerScale(1.5, 1)]));
  return [GraphicalBattlePresentation::prepare($battle, $catalog, $root), $party, $enemy, $catalog, $root, $battle];
}

it('holds reserve bodies and shared roles at entry while action-bound poses await selection', function () {
  [$presentation, $party, $enemy] = createEntryResourcePresentation(allowReserveSwap: true);
  $hp = array_map(static fn($b) => $b->stats->currentHp, [...$party->participants->toArray(), $enemy]);
  $resources = $presentation->collectBattleResources();
  $byAsset = [];
  foreach ($resources as $resource) { $byAsset[$resource->asset][] = $resource; }
  expect(array_keys($byAsset))->toBe(['arena.png', 'standing.png', 'shared.png', 'move.png', 'reserve-standing.png', 'enemy.png'])
    ->and($byAsset['arena.png'][0]->sourceRect->toArray())->toBe(['x' => 2, 'y' => 2, 'width' => 20, 'height' => 20])
    ->and($byAsset['arena.png'][0]->base->brightness)->toBe(.8);
  expect(array_map(static fn($r) => $r->sourceRect->x, $byAsset['move.png']))->toBe([0, 8, 16])
    ->and($byAsset['move.png'][0]->drawSize->height)->toBe(30.0)
    ->and($byAsset['reserve-standing.png'][0]->drawSize->height)->toBe(24.0);
  $front = $party->participants->toArray()[0];
  $pose = $presentation->getAnimationPose($front, 99);
  $selected = array_values($presentation->collectActionPoseResources($front, $pose, false));
  expect(array_column($selected, 'asset'))->toBe(['action.png', 'action.png', 'action.png'])
    ->and(array_map(static fn($resource) => $resource->sourceRect->x, $selected))->toEqualCanonicalizing([0, 8, 16])
    ->and($selected[0]->drawSize->height)->toBe(60.0)
    ->and($presentation->collectActionPoseResources($front, $pose, true))->toHaveCount(1);
  foreach ($resources as $resource) {
    expect($resource->canvas->toArray())->toBe(['width' => 1350, 'height' => 720, 'viewportFit' => 'native']);
  }
  expect($presentation->collectBattleResources())->toEqual($resources)
    ->and(array_map(static fn($b) => $b->stats->currentHp, [...$party->participants->toArray(), $enemy]))->toBe($hp);
});

it('collects variable-size contact cells and externally owned UI through their complete descriptor identities', function () {
  [$presentation] = createEntryResourcePresentation(new \Ichiloto\Engine\Battle\Presentation\BattleContactEffects(
    ripple: new \Ichiloto\Engine\Battle\Presentation\BattleSurfaceEffect(
      new BattlerPose('shared.png', columns: 2, frames: [0, 1]), new BattlerPose('ripple-front.png')),
    burrow: new \Ichiloto\Engine\Battle\Presentation\BattleSurfaceEffect(front: new BattlerPose('burrow-front.png'))));
  $resources = $presentation->collectBattleResources([
    new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('shared.png'),
    new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('hud.png'),
    new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('hud.png'),
  ]);
  expect(array_filter($resources, static fn($r) => $r->asset === 'hud.png'))->toHaveCount(1);
  $contact = array_values(array_filter($resources, static fn($r) => $r->asset === 'shared.png' && $r->sourceRect !== null && $r->drawSize === null));
  expect($contact)->toHaveCount(2)
    ->and(array_map(static fn($r) => $r->sourceRect->x, $contact))->toBe([0, 8]);
  foreach (['ripple-front.png', 'burrow-front.png'] as $asset) {
    expect(array_filter($resources, static fn($r) => $r->asset === $asset))->toHaveCount(1);
  }
});

it('prepares every party slot depth with the same source-to-logical transform used by drawing', function () {
  [$presentation] = createEntryResourcePresentation(frontlineSize: 2);
  $resources = $presentation->collectBattleResources();
  $move = array_values(array_filter($resources, static fn($r) => $r->asset === 'move.png' && $r->sourceRect->x === 0));
  expect(array_map(static fn($r) => $r->drawSize->height, $move))->toEqualCanonicalizing([30.0, 37.5]);
  $drawn = array_values(array_filter($presentation->frame()->images, static fn($image) => $image->asset === 'shared.png'));
  expect($drawn)->not->toBeEmpty();
  foreach ($drawn as $image) {
    expect(array_any($resources, static fn($r) => $r->asset === $image->asset && $r->sourceRect == $image->sourceRect
      && $r->drawSize?->width === $image->destination->width && $r->drawSize?->height === $image->destination->height))->toBeTrue();
  }
});

it('keeps a missing selected pose diagnosable and prepares reserve actions after an actual swap', function () {
  [$presentation, $party, , , $root, $battle] = createEntryResourcePresentation(bindReserve: false, allowReserveSwap: true);
  [$front, $reserve] = $party->participants->toArray();
  unlink($root . '/action.png');
  $resources = $presentation->collectBattleResources();
  $placeholder = \Ichiloto\Engine\Battle\Presentation\BattleFormationLayout::getUnavailableArtwork()->asset;
  expect(array_column($resources, 'asset'))->not->toContain($placeholder, 'action.png', 'reserve-action.png');
  $selected = array_values($presentation->collectActionPoseResources($front, $presentation->getAnimationPose($front, 99), false));
  expect($selected)->toHaveCount(1)->and($selected[0]->asset)->toBe('action.png')->and($selected[0]->drawSize)->toBeNull();
  $front->stats->currentHp = 0;
  expect($battle->partyRoster->promoteReservesAfterWipeout())->toBeTrue()->and($battle->partyRoster->battlers)->toBe([$reserve]);
  $selected = array_values($presentation->collectActionPoseResources($reserve, $presentation->getAnimationPose($reserve, 42), false));
  expect($selected)->toHaveCount(1)->and($selected[0]->asset)->toBe('reserve-action.png')->and($selected[0]->drawSize)->not->toBeNull();
});

it('declares the owning HUD, Pause, Results and control-family assets without sampling current panels or rewards', function () {
  $art = static fn(string $path): \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice =>
    new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice($path, new SpriteSourceRect(0, 0, 16, 16));
  $color = \Ichiloto\Engine\Rendering\Presentation\PresentationColor::rgb(20, 30, 40);
  $hud = new \Ichiloto\Engine\Battle\Presentation\BattleUiSkin(
    array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'], $art('hud.png')),
    array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'], $color),
    targetCursor: new \Ichiloto\Engine\Battle\Presentation\BattleTargetCursor(
      array_fill_keys(['above', 'left', 'right'], $art('target.png'))),
    icons: new \Ichiloto\Engine\UI\Presentation\MenuIconRegistry('/synthetic/assets', ['slot.head' => 'slot.png'], cursor: 'cursor.png'));
  $pause = new \Ichiloto\Engine\Battle\Presentation\BattlePauseSkin(
    array_fill_keys(\Ichiloto\Engine\Battle\Presentation\BattlePauseSkin::TEXTURES, $art('pause.png')),
    array_fill_keys(\Ichiloto\Engine\Battle\Presentation\BattlePauseSkin::COLORS, $color));
  $results = new \Ichiloto\Engine\Battle\Presentation\BattleResultsSkin(
    [...array_fill_keys(['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider'], $art('results.png')),
      'button' => $art('unused-legacy-button.png')],
    array_fill_keys(['text', 'muted', 'accent', 'positive', 'negative', 'ink'], $color),
    portraits: ['participant' => ['menu' => $art('participant-menu.png'), 'bust' => $art('participant-bust.png')],
      'reserve' => ['menu' => $art('reserve.png'), 'bust' => null],
      'outside-party' => ['menu' => $art('not-in-battle.png'), 'bust' => null]], icons: ['reward' => $art('reward.png')]);
  $controls = new \Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog('/synthetic/assets', [
    'schema' => 'ichiloto.menu/1', 'controls' => [
      'keyboard' => ['keycap' => ['asset' => 'keycap.png'], 'glyphs' => ['ENTER' => 'enter.png']],
      'controller' => ['glyphs' => ['face-south' => 'south.png']],
    ],
  ]);
  expect($hud->getAssetPaths())->toBe(['hud.png', 'target.png', 'slot.png', 'cursor.png'])
    ->and($pause->getAssetPaths())->toBe(['pause.png'])
    ->and($results->getAssetPaths(['participant', 'reserve']))
    ->toBe(['results.png', 'reward.png', 'participant-menu.png', 'participant-bust.png', 'reserve.png'])
    ->and($controls->getControlAssetPaths())->toBe(['keycap.png', 'enter.png', 'south.png']);
});


it('measures a future pose before its travel destination exists without placing it at the resting anchor', function () {
  $slot = new \Ichiloto\Engine\Battle\Presentation\BattlerSlot(100, 20, 80, 80);
  $art = new BattlerArtwork('future.png', 8, 8, 4, 8);
  $layout = new \Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout(1350, 720);
  $profile = new \Ichiloto\Engine\Battle\Presentation\BattlerScale(1, 1);
  $scale = new \Ichiloto\Engine\Battle\Presentation\BattleScale('Synthetic', 30, ['Synthetic' => $profile]);
  $size = \Ichiloto\Engine\Battle\Presentation\BattleFormationLayout::getPoseDrawSize($slot, $art, 3.75, $layout,
    $profile, $scale, .5);
  expect($size)->toBe(['width' => 60.0, 'height' => 60.0]);
  // Placement now retains an overhanging pose; future-resource sizing still needs no placement or viewport crop.
  $placed = \Ichiloto\Engine\Battle\Presentation\BattleFormationLayout::getPosePlacement($slot, $art, 3.75,
    $layout, $profile, $scale, .5);
  expect($placed->y)->toBe(-40.0)->and($placed->width)->toBe($size['width'])
    ->and($placed->height)->toBe($size['height'])->and($placed->x + $art->pivotX * 7.5)->toBe($slot->x)
    ->and($placed->y + $art->pivotY * 7.5)->toBe($slot->y);
});

it('prepares an actual command image timeline through the screen host without a cinematic stage or native process', function () {
  require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
  [$presentation, $party, , , $root] = createEntryResourcePresentation();
  $transport = new \Tests\Support\Input\FakeRendererTransport();
  $transport->batches[] = [\Ichiloto\Engine\Rendering\Transport\RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["asset_holds"]}')];
  $runtime = new \Ichiloto\Engine\Rendering\Runtime\RendererRuntime(
    new \Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig(
      new \Ichiloto\Engine\Rendering\Transport\RendererProcessConfig(['never-launched']), $root,
      requiredCapabilities: [\Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::ASSET_HOLDS]), $transport);
  $preparation = null; $stream = null;
  try {
    $runtime->start('In-memory image preparation', 135, 36);
    $game = $this->getStubBuilder(\Tests\Support\PassiveGame::class)->disableOriginalConstructor()
      ->onlyMethods(['getRendererRuntime'])->getStub();
    $game->method('getRendererRuntime')->willReturn($runtime);
    $scene = $this->getStubBuilder(\Ichiloto\Engine\Scenes\Battle\BattleScene::class)->disableOriginalConstructor()
      ->onlyMethods(['getGame'])->getStub();
    $scene->method('getGame')->willReturn($game);
    new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
    $screen = new ReflectionClass(\Ichiloto\Engine\Battle\UI\BattleScreen::class)->newInstanceWithoutConstructor();
    new ReflectionProperty($screen, 'battleScene')->setValue($screen, $scene);
    $effect = new \Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline('owned-effect', '', fps: 12,
      playbackSegments: array_map(static fn(int $cell): array => ['layer' => 'image', 'startFrame' => $cell,
        'endFrame' => $cell, 'drawCommands' => [['trackId' => 'picture', 'assetId' => 'shared.png',
          'payload' => ['columns' => 2, 'rows' => 1, 'sourceFrame' => $cell]]]], [0, 1]), defaults: ['lengthFrames' => 2]);
    $plan = new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
    $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback($plan, $party->participants->toArray()[0], [],
      \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
    expect($plan->hasCinematicStages())->toBeFalse();
    $stream = $screen->createCommandResourceStream($playback, 1);
    $preparation = $stream->initialPreparation;
    expect(fn() => $screen->prepareCommandResources($playback, 1, 2))
      ->toThrow(\Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException::class, 'rolling resource stream');
    expect($preparation)->not->toBeNull()->and($preparation->deadline)->toBeNull()
      ->and(array_map(static fn($r) => $r->resource->sourceRect->x, $preparation->requests))->toBe([0]);
  } finally { $stream?->release(); $preparation?->release(); $runtime->shutdown(); }
});


/** Actual scene/screen ownership, with a renderer transport that never launches a process. */
function createActionResourceHost(Closure $buildStub): array
{
  require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
  [$presentation, $party, , , $root, $config] = createEntryResourcePresentation();
  $transport = new \Tests\Support\Input\FakeRendererTransport();
  $transport->batches[] = [\Ichiloto\Engine\Rendering\Transport\RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["asset_holds"]}')];
  $runtime = new \Ichiloto\Engine\Rendering\Runtime\RendererRuntime(
    new \Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig(
      new \Ichiloto\Engine\Rendering\Transport\RendererProcessConfig(['never-launched']), $root,
      requiredCapabilities: [\Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::ASSET_HOLDS]), $transport);
  $runtime->start('Synthetic semantic focus', 135, 36);
  $game = $buildStub(\Tests\Support\PassiveGame::class, ['getRendererRuntime']);
  $game->method('getRendererRuntime')->willReturn($runtime);
  $scene = $buildStub(BattleScene::class, ['getGame']);
  $scene->method('getGame')->willReturn($game);
  $config->bindContent($root);
  new ReflectionProperty(BattleScene::class, 'config')->setValue($scene, $config);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
  $screen = new ReflectionClass(\Ichiloto\Engine\Battle\UI\BattleScreen::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($screen, 'battleScene')->setValue($screen, $scene);
  return [$screen, $scene, $runtime, $party->participants->toArray()[0], $root];
}

/** Load the test animations through the same project binding as battle entry. */
function loadActionResourceAnimations(BattleScene $scene, string $root, array $animations): BattleEffectResources
{
  mkdir($root . '/Data', 0700);
  file_put_contents($root . '/Data/animations.php', '<?php return ' . var_export($animations, true) . ';');
  return $scene->getBattleEffectResources() ?? throw new LogicException('The fixture requires bound battle content.');
}

it('reuses actual focused image preparations for execution while preserving independently owned scene resources', function () {
  [$screen, $scene, $runtime, $actor, $root] = createActionResourceHost(fn(string $class, array $methods) =>
    $this->getStubBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getStub());
  $command = null; $stream = null;
  $scenePreparation = null;
  try {
    $animations = [];
    foreach (['first', 'second'] as $index => $id) {
      $directory = $root . '/Animations/' . $id; mkdir($directory, 0700, true);
      \Tests\Support\Rendering\writeTestPng($root . '/' . $id . '.png', 16, 8);
      $data = ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [['id' => 'image', 'type' => 'image',
        'asset' => $id . '.png', 'sheet' => ['columns' => 2, 'rows' => 1],
        'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]]]]];
      file_put_contents($directory . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
      $animations[] = ['id' => $index + 1, 'name' => $id, 'targetEffect' => $id];
    }
    $effects = loadActionResourceAnimations($scene, $root, $animations);
    $a = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(
      new \Ichiloto\Engine\Entities\Skills\MagicSkill('One', '', '', 0, 0, animationId: 1));
    $b = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(
      new \Ichiloto\Engine\Entities\Skills\MagicSkill('Two', '', '', 0, 0, animationId: 2));
    $scenePreparation = new \Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation($runtime->assetHolds,
      $effects->collectActionResources($actor, $a), 0, null);
    new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleScene::class, 'battleAssetPreparation')->setValue($scene, $scenePreparation);
    $sceneRequests = $scene->getBattleResourceRequests();
    $screen->prefetchActionResources($actor, $a);
    expect($runtime->assetHolds->getDesiredRequests())->toBe($sceneRequests);
    $screen->prefetchActionResources($actor, $b);
    $all = $runtime->assetHolds->getDesiredRequests();
    expect($all)->toHaveCount(3);
    $selected = array_values(array_filter($all, static fn($r) => $r->resource->asset === 'second.png'));
    $screen->prefetchActionResources($actor, $b);
    expect($runtime->assetHolds->getDesiredRequests())->toBe($all); // Same focus keeps its IDs and preparation.
    $runtime->assetHolds->applyFeedback(\Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback::fromArray([
      'holds' => array_map(static fn($r): array => ['id' => $r->id, 'revision' => $r->revision,
        'attempt' => 1, 'state' => 'held', 'regionBytes' => 256, 'baseBytes' => 0], $all),
      'holdCapacity' => createTestAssetHoldCapacity(0, 0)]));
    $timeline = new EffectTimelineLibrary($root)->load('second', forBattle: true);
    $plan = new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $timeline);
    $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback($plan, $actor, [],
      \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
    $stream = $screen->createCommandResourceStream($playback, 1);
    $command = $stream->initialPreparation;
    expect($command->requests)->toBe($selected)
      ->and($command->pollAt(1))->toBe(\Ichiloto\Engine\Rendering\Assets\AssetHoldPreparationState::READY)
      ->and($runtime->assetHolds->getDesiredRequests())->toBe($all);
    $screen->releaseActionPrefetch();
    expect($command->scope->getRequests())->toBe($selected);
    $command->release();
    expect($runtime->assetHolds->getDesiredRequests())->toBe($sceneRequests);
    $screen->prefetchActionResources($actor, $b);
    expect($runtime->assetHolds->getDesiredRequests())->toHaveCount(3);
    $screen->prefetchActionResources(null, null);
    expect($runtime->assetHolds->getDesiredRequests())->toBe($sceneRequests)
      ->and($actor->stats->currentHp)->toBe(100);
  } finally { $screen->releaseActionPrefetch(); $stream?->release(); $command?->release(); $scenePreparation?->release(); $runtime->shutdown(); }
});



it('promotes the focused animation-only pose into command ownership and waits for its pixels', function () {
  [$screen, $scene, $runtime, $actor, $root] = createActionResourceHost(fn(string $class, array $methods) =>
    $this->getStubBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getStub());
  $stream = null;
  try {
    loadActionResourceAnimations($scene, $root, [
      ['id' => 99, 'name' => 'Pose alone'],
      ['id' => 100, 'name' => 'Unbound pose'],
    ]);
    $action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(
      new \Ichiloto\Engine\Entities\Skills\MagicSkill('Pose only', '', '', 0, 0, animationId: 99));
    $other = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(
      new \Ichiloto\Engine\Entities\Skills\MagicSkill('Other action', '', '', 0, 0, animationId: 100));
    $screen->prefetchActionResources($actor, $action);
    expect($runtime->assetHolds->getDesiredRequests())->toHaveCount(3);
    $screen->prefetchActionResources($actor, $other);
    expect($runtime->assetHolds->getDesiredRequests())->toBe([]);
    $screen->prefetchActionResources($actor, $action);
    $focused = $runtime->assetHolds->getDesiredRequests();
    expect(array_map(static fn($r) => $r->resource->asset, $focused))->toBe(['action.png', 'action.png', 'action.png']);

    $pose = $scene->graphicalPresentation->getAnimationPose($actor, 99);
    $plan = new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), actionPoseOverride: $pose);
    $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback($plan, $actor, [],
      \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
    expect($plan->timeline->getImageSources())->toBe([]);
    $stream = $screen->createCommandResourceStream($playback, 1);
    expect($stream)->not->toBeNull();
    expect($stream->initialPreparation->requests)->toBe($focused)
      ->and($stream->initialPreparation->deadline)->toBeNull()
      ->and($stream->prepareInitialAt(1))->toBe(\Ichiloto\Engine\Rendering\Assets\AssetHoldPreparationState::WAITING)
      ->and($stream->approveElapsedSeconds(.5, 1))->toBe(0.0);
    $runtime->assetHolds->applyFeedback(\Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback::fromArray([
      'holds' => array_map(static fn($r): array => ['id' => $r->id, 'revision' => $r->revision,
        'attempt' => 1, 'state' => 'held', 'regionBytes' => 256, 'baseBytes' => 0], $focused),
      'holdCapacity' => createTestAssetHoldCapacity(0, 0)]));
    expect($stream->prepareInitialAt(1))->toBe(\Ichiloto\Engine\Rendering\Assets\AssetHoldPreparationState::READY);
    $screen->releaseActionPrefetch();
    expect($runtime->assetHolds->getDesiredRequests())->toBe($focused);
    $stream->release();
    expect($runtime->assetHolds->getDesiredRequests())->toBe([]);
  } finally {
    $stream?->release();
    $screen->releaseActionPrefetch();
    $runtime->shutdown();
  }
});


it('keeps an action sheet cold through entry and transfers its focused CPU worker to the command', function () {
  if (!function_exists('imagecreatefrompng') || !function_exists('proc_open')) {
    $this->markTestSkipped('The fixture needs PNG decoding and the PHP worker.');
  }
  [$screen, $scene, $runtime, $actor, $root] = createActionResourceHost(fn(string $class, array $methods) =>
    $this->getStubBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getStub());
  $artwork = null;
  try {
    loadActionResourceAnimations($scene, $root, [
      ['id' => 99, 'name' => 'Selected artwork'],
    ]);
    $action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(
      new \Ichiloto\Engine\Entities\Skills\MagicSkill('Selected artwork', '', '', 0, 0, animationId: 99));
    $facts = new \Ichiloto\Engine\Battle\Presentation\BattleArtworkFacts($root);
    $facts->addPose($scene->graphicalPresentation->getAnimationPose($actor, 99));
    expect($facts->hasCurrentFacts())->toBeFalse();

    $screen->prefetchActionResources($actor, $action);
    $focus = new ReflectionProperty($screen, 'focusedArtwork')->getValue($screen);
    $artwork = $screen->takeActionArtworkPreparation($actor, 99);
    expect($artwork)->toBeInstanceOf(\Ichiloto\Engine\Battle\Presentation\BattleArtworkPreparation::class)
      ->and($artwork)->toBe($focus);
    $screen->releaseActionPrefetch();
    $deadline = hrtime(true) + 5_000_000_000;
    while (!($ready = $artwork->poll()) && hrtime(true) < $deadline) { usleep(10_000); }
    expect($ready)->toBeTrue()->and($facts->hasCurrentFacts())->toBeTrue();
  } finally {
    $artwork?->cancel();
    $screen->releaseActionPrefetch();
    $runtime->shutdown();
  }
});

it('holds an already-revealed scene again while a changed resource is being reread', function () {
  [$screen, $scene, $runtime] = createActionResourceHost(fn(string $class, array $methods) =>
    $this->getStubBuilder($class)->disableOriginalConstructor()->onlyMethods($methods)->getStub());
  $prepare = null;
  try {
    $prepare = new \Ichiloto\Engine\Rendering\Assets\AssetHoldPreparation($runtime->assetHolds,
      [new \Ichiloto\Engine\Rendering\Assets\AssetHoldResource('standing.png')],
      \Ichiloto\Engine\Core\Clocks\RuntimeClock::getSeconds(), null);
    new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleScene::class, 'battleAssetPreparation')->setValue($scene, $prepare);
    $r = $prepare->requests[0];
    $reply = static function (string $state, int $attempt) use ($runtime, $r): void {
      $runtime->assetHolds->applyFeedback(\Ichiloto\Engine\Rendering\Assets\AssetHoldFeedback::fromArray([
        'holds' => [['id' => $r->id, 'revision' => $r->revision, 'attempt' => $attempt, 'state' => $state,
          ...($state === 'ended' ? ['reason' => 'changed'] : ['regionBytes' => 256, 'baseBytes' => 0])]],
        'holdCapacity' => createTestAssetHoldCapacity(0, 0)]));
    };
    $reply('held', 1); expect($scene->pollBattleResourcePreparation())->toBeTrue();
    $reply('ended', 1); $reply('pending', 2);
    expect($scene->pollBattleResourcePreparation())->toBeFalse();
    $reply('held', 2); expect($scene->pollBattleResourcePreparation())->toBeTrue();
  } finally { $prepare?->release(); $runtime->shutdown(); }
});
