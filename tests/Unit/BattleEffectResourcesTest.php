<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectImageSource;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleEffectResources;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('declares future source cells once without playing or retaining hidden and terminal pictures', function () {
  $root = createTestDirectory('effect-declaration');
  \Tests\Support\Rendering\writeTestPng($root . '/cells.png', 24, 16);
  $command = static fn(int $cell): array => ['assetId' => 'cells.png',
    'payload' => ['columns' => 3, 'rows' => 2, 'sourceFrame' => $cell, 'frameWidth' => 999, 'frameHeight' => 999]];
  $timeline = new CompiledEffectTimeline('synthetic', 'unrelated', playbackSegments: [
    ['layer' => 'image', 'drawCommands' => [$command(0), $command(5), $command(0), [...$command(3), 'visible' => false]]],
    ['layer' => 'image', 'presentation' => 'terminal', 'drawCommands' => [['assetId' => 'unavailable.png']]],
    ['layer' => 'glyph', 'drawCommands' => [['content' => '*']]],
  ], cueSchedule: [['type' => 'applyEffect', 'frame' => 10]]);
  $resources = array_values(BattleEffectResources::collectTimelineResources($timeline, $root));
  expect($resources)->toHaveCount(2)
    ->and($resources[0]->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 8, 'height' => 8])
    ->and($resources[1]->sourceRect->toArray())->toBe(['x' => 16, 'y' => 8, 'width' => 8, 'height' => 8])
    ->and($timeline->getImageSources(EffectPresentation::TERMINAL))->toBeEmpty()
    ->and($timeline->cueSchedule)->toBe([['type' => 'applyEffect', 'frame' => 10]]);
  foreach ($resources as $resource) { expect($resource->drawSize)->toBeNull(); }
  // Current file dimensions own pixel facts, even if old compiled dimensions remain present.
  \Tests\Support\Rendering\writeTestPng($root . '/cells.png', 48, 32);
  $source = $timeline->getImageSources()[1];
  expect($source->getSourceRect($root)->toArray())->toBe(['x' => 32, 'y' => 16, 'width' => 16, 'height' => 16]);
  \Tests\Support\Rendering\writeTestPng($root . '/cells.png', 47, 32);
  expect(fn() => $source->getSourceRect($root))->toThrow(InvalidArgumentException::class, 'no longer matches');
});

it('rejects invalid authored image source grids and unsafe paths', function () {
  foreach ([[0, 1, 0], [1, 0, 0], [2, 2, 4], [2, 2, -1]] as [$columns, $rows, $frame]) {
    expect(fn() => new EffectImageSource('replaceable.png', $columns, $rows, $frame))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new EffectImageSource('../outside.png'))->toThrow(InvalidArgumentException::class);
});

it('keeps scene attacks and prepares reserve spells and items only for a concrete selected action', function () {
  $root = createTestDirectory('effect-owned-cohort');
  $party = new Party();
  $party->setBattleSize(1);
  $front = new Character('Front', 1, new Stats(currentHp: 100, totalHp: 100));
  $reserve = new Character('Reserve', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 0));
  $spell = new MagicSkill('Not enough MP yet', '', '', 10, 0, animationId: 2);
  $reserve->learnSkill($spell);
  $party->addMember($front);
  $party->addMember($reserve);
  $item = new \Ichiloto\Engine\Entities\Inventory\Items\Item('Potion', '', '', 1, animationId: 3);
  $party->addItems($item);
  $animations = AnimationLibrary::createFromAnimations([
    new Animation(1, 'Arbitrary default', targetEffect: 'attack', roles: ['attack-unarmed']),
    new Animation(2, 'Arbitrary spell', sourceEffect: 'casting', targetEffect: 'impact'),
    new Animation(3, 'Arbitrary item', targetEffect: 'item'),
    new Animation(4, 'Unowned skill', targetEffect: 'not-in-this-battle'),
  ]);
  foreach (['attack', 'casting', 'impact', 'item'] as $id) {
    \Tests\Support\Rendering\writeTestPng($root . '/' . $id . '.png', 16, 8);
    $directory = $root . '/Animations/' . $id;
    mkdir($directory, 0700, true);
    $data = ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [[
      'id' => 'image', 'type' => 'image', 'asset' => $id . '.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
    ]]];
    file_put_contents($directory . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
  }
  $effects = new BattleEffectResources($animations, new EffectTimelineLibrary($root));
  $battle = new BattleConfig($party, new Troop('Synthetic', []));
  $before = [$front->stats->currentHp, $reserve->stats->currentMp, $party->inventory->items->count()];
  $scene = $effects->collectBattleResources($battle);
  expect(array_values($scene))->toHaveCount(1)
    ->and(array_values(array_unique(array_map(static fn($r) => $r->asset, $scene))))->toBe(['attack.png']);
  expect(array_values($scene)[0]->sourceRect->x)->toBe(0); // Later attack cels are command-owned too.
  $selectedSpell = $effects->collectActionResources($reserve, new \Ichiloto\Engine\Battle\Actions\SkillBattleAction($spell));
  $selectedItem = $effects->collectActionResources($front, new \Ichiloto\Engine\Battle\Actions\ItemBattleAction($item, $party->inventory));
  expect($effects->collectActionPrefetchResources($reserve, new \Ichiloto\Engine\Battle\Actions\SkillBattleAction($spell), false))->toHaveCount(2)
    ->and($effects->collectActionPrefetchResources($front, new \Ichiloto\Engine\Battle\Actions\ItemBattleAction($item, $party->inventory), false))->toHaveCount(1);
  expect(array_values($selectedSpell))->toHaveCount(4)->and(array_values($selectedItem))->toHaveCount(2)
    ->and(array_values(array_unique(array_map(static fn($r) => $r->asset, array_replace($scene, $selectedSpell, $selectedItem)))))
    ->toEqualCanonicalizing(['attack.png', 'casting.png', 'impact.png', 'item.png'])
    ->and([$front->stats->currentHp, $reserve->stats->currentMp, $party->inventory->items->count()])->toBe($before);
  // A future item cannot block scene entry; its selected preparation still refuses a missing source.
  unlink($root . '/item.png');
  expect($effects->collectBattleResources($battle))->toEqual($scene);
  expect(fn() => $effects->collectActionResources($front,
    new \Ichiloto\Engine\Battle\Actions\ItemBattleAction($item, $party->inventory)))
    ->toThrow(InvalidArgumentException::class, 'item.png');
});

it('removes dormant crisis and enemy-pattern effects from entry and prepares their concrete selection', function () {
  $project = createTestDirectory('effect-crisis-pattern');
  $root = $project . '/assets';
  mkdir($root, 0700);
  $variant = new \Ichiloto\Engine\Entities\Skills\BasicSkill('Dormant', '', '', 0, 0, animationId: 2);
  writeSkillRecords($project, $variant);
  $crisis = \Ichiloto\Engine\Battle\CrisisAbility::fromArray(['name' => 'Synthetic', 'hpBelowPercent' => 20,
    'variants' => [['skill' => 'Dormant', 'conditions' => []]]]);
  $actor = new Character('Healthy', 1, new Stats(currentHp: 100, totalHp: 100), crisisAbility: $crisis);
  $party = new Party(); $party->addMember($actor);
  $enemy = new ReflectionClass(\Ichiloto\Engine\Entities\Enemies\Enemy::class)->newInstanceWithoutConstructor();
  $pattern = new \Ichiloto\Engine\Entities\Enemies\ActionPattern(
    new MagicSkill('Conditional enemy spell', '', '', 10, 0, animationId: 3), 1,
    new \Ichiloto\Engine\Entities\Enemies\ActionCondition());
  foreach (['name' => 'Foe', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new \Ichiloto\Engine\Core\Vector2(1, 1), 'actionPatterns' => [$pattern]] as $key => $value) {
    new ReflectionProperty($enemy, $key)->setValue($enemy, $value);
  }
  foreach (['crisis', 'pattern'] as $id) {
    \Tests\Support\Rendering\writeTestPng($root . '/' . $id . '.png', 8, 8);
    mkdir($root . '/Animations/' . $id, 0700, true);
    $data = ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [['id' => 'picture', 'type' => 'image', 'asset' => $id . '.png',
      'keyframes' => [['frame' => 0]]]]];
    file_put_contents($root . '/Animations/' . $id . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
  }
  $effects = new BattleEffectResources(AnimationLibrary::createFromAnimations([
    new Animation(1, 'Empty default', roles: ['attack', 'attack-unarmed']),
    new Animation(2, 'Dormant choice', targetEffect: 'crisis'),
    new Animation(3, 'Enemy choice', targetEffect: 'pattern'),
  ]), new EffectTimelineLibrary($root));
  $resources = $effects->collectBattleResources(new BattleConfig($party, new Troop('Synthetic', [$enemy])));
  expect($resources)->toBe([]);
  $selectedCrisis = $effects->collectActionResources($actor, new \Ichiloto\Engine\Battle\Actions\SkillBattleAction($variant));
  $selectedPattern = $effects->collectActionResources($enemy, new \Ichiloto\Engine\Battle\Actions\SkillBattleAction($pattern->skill));
  expect(array_map(static fn($r) => $r->asset, array_values(array_replace($selectedCrisis, $selectedPattern))))
    ->toBe(['crisis.png', 'pattern.png'])
    ->and($effects->collectBattleResources(new BattleConfig($party, new Troop('Synthetic', [$enemy]))))->toBe([])
    ->and($crisis->isActive($actor))->toBeFalse()->and($actor->stats->currentHp)->toBe(100);
});

it('declares configured condition and interception images before their states are active', function () {
  $root = createTestDirectory('effect-condition-declarations');
  \Tests\Support\Rendering\writeTestPng($root . '/condition.png', 8, 8);
  foreach (['loop' => 'loop', 'contact' => 'once'] as $id => $playback) {
    mkdir($root . '/Animations/' . $id, 0700, true);
    $data = ['fps' => 12, 'lengthFrames' => 1, 'playback' => $playback, 'tracks' => [['id' => 'picture',
      'type' => 'image', 'asset' => 'condition.png', 'anchor' => 'target', 'keyframes' => [['frame' => 0]]]]];
    file_put_contents($root . '/Animations/' . $id . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
  }
  $effects = new \Ichiloto\Engine\Battle\Presentation\BattleConditionEffects(new EffectTimelineLibrary($root),
    ['states' => ['future-state' => 'loop'], 'interceptions' => ['future-block' => 'contact']]);
  expect(array_map(static fn($t) => $t->sourceId, $effects->getDeclaredTimelines()))->toBe(['loop', 'contact']);
  foreach ($effects->getDeclaredTimelines() as $timeline) {
    expect(array_values(BattleEffectResources::collectTimelineResources($timeline, $root))[0]->asset)->toBe('condition.png');
  }
});


it('prefetches a selected linked summon from its owning asset root without loading unrelated summons or spending MP', function () {
  $root = createTestDirectory('selected-linked-summon');
  $directory = $root . '/Cutscenes/Summons/selected'; mkdir($directory, 0700, true);
  \Tests\Support\Rendering\writeTestPng($root . '/selected.png', 16, 8);
  file_put_contents($directory . '/selected.data.php', "<?php return ['id' => 'selected', 'name' => 'Selected', 'linkedActionId' => 'Call'];");
  $data = ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [['id' => 'picture', 'type' => 'image', 'asset' => 'selected.png',
    'sheet' => ['columns' => 2, 'rows' => 1], 'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]]]]];
  file_put_contents($directory . '/selected.timeline.php', '<?php return ' . var_export($data, true) . ';');
  // Valid unselected authoring with an unavailable image must not become a required preparation.
  $other = $root . '/Cutscenes/Summons/unselected'; mkdir($other, 0700, true);
  file_put_contents($other . '/unselected.data.php', "<?php return ['id' => 'unselected', 'name' => 'Other', 'linkedActionId' => 'Other call'];");
  $data['tracks'][0]['asset'] = 'unavailable.png';
  file_put_contents($other . '/unselected.timeline.php', '<?php return ' . var_export($data, true) . ';');
  $actor = new Character('Caller', 1, new Stats(currentHp: 100, currentMp: 10, totalMp: 10));
  $library = new \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary($root . '/Cutscenes/Summons', cacheForBattle: true, assetRoot: $root);
  $effects = new BattleEffectResources(AnimationLibrary::createFromAnimations([]), new EffectTimelineLibrary($root), $library);
  $action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Call', '', '', 2, 0));
  $resources = array_values($effects->collectActionResources($actor, $action));
  expect(array_column($resources, 'asset'))->toBe(['selected.png', 'selected.png'])
    ->and(array_map(static fn($r) => $r->sourceRect->x, $resources))->toBe([0, 8])
    ->and($actor->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(10);
});


it('collects only the sampled command picture while preserving authored clocks, reduced motion and cue ownership', function () {
  $root = createTestDirectory('command-image-cohort');
  \Tests\Support\Rendering\writeTestPng($root . '/film.png', 32, 8);
  $timeline = new CompiledEffectTimeline('film', '', fps: 12,
    playbackSegments: array_map(static fn(int $frame): array => [
      'startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
      'drawCommands' => [['trackId' => 'paint', 'assetId' => 'film.png', 'position' => ['x' => 0, 'y' => 0],
        'payload' => ['columns' => 4, 'rows' => 1, 'sourceFrame' => $frame]]]], range(0, 3)),
    cueSchedule: [['type' => 'applyEffect', 'frame' => 2]], defaults: ['lengthFrames' => 4, 'restFrame' => 2]);
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $calls = 0;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $timeline),
    $actor, [], \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK,
    function () use (&$calls) { $calls++; }, function () use (&$calls) { $calls++; });
  $before = [$playback->session->currentFrame, $playback->phase, $playback->session->getCuesAt()];
  foreach (range(0, 3) as $frame) {
    $at = $playback->plan->getCommandFrameForAuthoredFrame('target', $frame);
    $normal = array_values(BattleEffectResources::collectCommandFrameResources($playback, $root, $at, false));
    $reduced = array_values(BattleEffectResources::collectCommandFrameResources($playback, $root, $at, true));
    expect($normal)->toHaveCount(1)->and($normal[0]->sourceRect->x)->toBe(8 * $frame)
      ->and($reduced)->toHaveCount(1)->and($reduced[0]->sourceRect->x)->toBe(16);
  }
  expect([$playback->session->currentFrame, $playback->phase, $playback->session->getCuesAt()])->toBe($before)
    ->and($calls)->toBe(0)->and($actor->stats->currentHp)->toBe(100);
});
