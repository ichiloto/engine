<?php

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleConditionEffects;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateInstance;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function writeConditionTimeline(string $root, string $id, array $changes = []): void
{
  $data = array_replace(['fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => 'loop',
    'tracks' => [['id' => 'loop', 'type' => 'glyph', 'anchor' => 'target',
      'keyframes' => [['frame' => 0, 'content' => 'a'], ['frame' => 1, 'content' => 'b']]]]], $changes);
  mkdir($root . '/Animations/' . $id, 0777, true);
  file_put_contents($root . '/Animations/' . $id . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
}

it('projects current state identities and stage roles without copying gameplay state', function () {
  $actor = new Character('Same display name', 1, new Stats(currentHp: 100, totalHp: 100));
  $other = new Character('Same display name', 1, new Stats(currentHp: 100, totalHp: 100));
  $actor->addState(new State('test-state', 'Synthetic affliction', 'S', 'Synthetic description.', durationTurns: 1));
  $actor->setStatStage('speed', -2);
  $effects = new BattleConditionEffects(new EffectTimelineLibrary(createTestDirectory('condition-identity')));
  $entries = BattlerConditions::getEntries($actor);
  expect(array_column($entries, 'key'))->toBe(['state:test-state', 'stat:speed:negative'])
    ->and(BattlerConditions::getInfo($actor))->toContain('Synthetic affliction', 'Synthetic description.', 'Speed -2')
    ->and($effects->createPlayback($other, 100))->toBeNull()
    ->and($effects->createPlayback($actor, 100)->actor)->toBe($actor);
  $actor->tickStates();
  $actor->resetStatStages();
  expect($effects->createPlayback($actor, 100))->toBeNull()->and(BattlerConditions::getInfo($actor))->toBe('');
});

it('loops on the shared clock with a static rest form and bounded huge-time sampling', function (bool $terminal, ?int $durationTurns) {
  $root = createTestDirectory('condition-loop');
  writeConditionTimeline($root, 'synthetic-loop');
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $state = new State('condition', 'Condition', 'C', durationTurns: $durationTurns);
  expect($actor->addState($state))->toBeTrue();
  $stateInstance = $actor->states[0];
  $initialHp = $actor->stats->currentHp;
  expect($stateInstance)->toBeInstanceOf(StateInstance::class)
    ->and($stateInstance->state)->toBe($state)
    ->and($stateInstance->remainingTurns)->toBe($durationTurns);
  $effects = new BattleConditionEffects(new EffectTimelineLibrary($root), ['states' => ['condition' => 'synthetic-loop']]);
  foreach ([0.0 => 'a'] as $seconds => $content) {
    expect($effects->createPlayback($actor, $seconds)->getActiveSegments(false, $terminal)[0]['drawCommands'][0]['content'])->toBe($content);
  }
  foreach ([[.1, 'b'], [.2, 'a'], [.3, 'b']] as [$seconds, $content]) {
    $playback = $effects->createPlayback($actor, $seconds);
    expect($playback->getActiveSegments(false, $terminal)[0]['drawCommands'][0]['content'])->toBe($content)
      ->and($playback->getActiveSegments(true, $terminal)[0]['drawCommands'][0]['content'])->toBe('b');
  }
  expect($effects->createPlayback($actor, 1000000000000.0)->getActiveSegments(false, $terminal)[0]['drawCommands'][0]['content'])
    ->toBeIn(['a', 'b']);
  expect($actor->states)->toBe([$stateInstance])
    ->and($actor->hasState($state->id))->toBeTrue()
    ->and($stateInstance->state)->toBe($state)
    ->and($stateInstance->remainingTurns)->toBe($durationTurns)
    ->and($actor->stats->currentHp)->toBe($initialHp);
})->with([[false, null], [true, null], [false, 3], [true, 3]]);

it('preserves loop introductions and suppresses only authored gaps rather than adding defaults', function () {
  $root = createTestDirectory('condition-intro');
  writeConditionTimeline($root, 'intro-loop', ['lengthFrames' => 4, 'loopFrom' => 2, 'restFrame' => 2,
    'tracks' => [['id' => 'loop', 'type' => 'glyph', 'anchor' => 'target', 'keyframes' => [
      ['frame' => 0, 'content' => 'intro'], ['frame' => 2, 'content' => 'rest'], ['frame' => 3, 'content' => 'loop']]]]]);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('condition', 'Condition', 'C'));
  $effects = new BattleConditionEffects(new EffectTimelineLibrary($root), ['states' => ['condition' => 'intro-loop']]);
  expect($effects->createPlayback($actor, .1)->getActiveSegments())->toBeEmpty()
    ->and($effects->createPlayback($actor, .4)->getActiveSegments()[0]['drawCommands'][0]['content'])->toBe('rest')
    ->and($effects->createPlayback($actor, .5)->getActiveSegments()[0]['drawCommands'][0]['content'])->toBe('loop');
});

it('uses independently compiled terminal art and currently replaceable graphical sheets', function (bool $reduced) {
  $root = createTestDirectory('condition-art');
  writeTestPng($root . '/replaceable.png', 8, 4);
  $graphical = ['fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => 'loop', 'tracks' => [[
    'id' => 'aura', 'type' => 'image', 'asset' => 'replaceable.png', 'anchor' => 'target',
    'attachment' => 'head', 'sheet' => ['columns' => 2, 'rows' => 1],
    'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
  ]]];
  $terminal = ['fps' => 10, 'lengthFrames' => 2, 'restFrame' => 0, 'playback' => 'loop', 'tracks' => [[
    'id' => 'symbol', 'type' => 'glyph', 'anchor' => 'target', 'keyframes' => [
      ['frame' => 0, 'content' => 'T'], ['frame' => 1, 'content' => 't']]]]];
  mkdir($root . '/Animations/aura', 0777, true);
  file_put_contents($root . '/Animations/aura/aura.timeline.php', '<?php return ' . var_export(
    ['presentations' => ['terminal' => $terminal, 'graphical' => $graphical]], true) . ';');
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('condition', 'Condition', 'C'));
  $effects = new BattleConditionEffects(new EffectTimelineLibrary($root), ['states' => ['condition' => 'aura']]);
  $bounds = [spl_object_id($actor) => new CanvasRectangle(100, 100, 40, 80)];
  foreach ([[8, 4], [16, 6], [12, 8]] as [$width, $height]) {
    writeTestPng($root . '/replacement.png', $width, $height);
    rename($root . '/replacement.png', $root . '/replaceable.png');
    $playback = $effects->createPlayback($actor, .1);
    $frame = GraphicalBattleEffects::compose($playback, new BattleCanvasLayout(1440, 840), $bounds, $root, $reduced);
    expect($frame->images)->toHaveCount(1)->and($frame->images[0]->sourceRect->width)->toBe(intdiv($width, 2))
      ->and($frame->images[0]->sourceRect->height)->toBe($height)
      ->and($frame->images[0]->sourceRect->x)->toBe(intdiv($width, 2));
  }
  unlink($root . '/replaceable.png');
  $playback = $effects->createPlayback($actor, .1);
  $draws = new TerminalBattleEffects()->compose($playback, static fn() => ['x' => 5, 'y' => 5], $reduced)['draws'];
  expect($draws[0]['text'])->toBe($reduced ? 'T' : 't')->and($playback->hasPresentationFailure)->toBeFalse();
  $frame = GraphicalBattleEffects::compose($effects->createPlayback($actor, .1), new BattleCanvasLayout(1440, 840), $bounds, $root, $reduced);
  expect($frame->images)->toBeEmpty()->and($frame->textLayers)->toBeEmpty();
})->with([false, true]);

it('keeps terminal formatting independent from the graphical fallback', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $glyph = "\033[31mS\033[0m";
  $actor->addState(new State('condition', 'Condition', $glyph));
  $effects = new BattleConditionEffects(new EffectTimelineLibrary(createTestDirectory('condition-sgr')));
  $playback = $effects->createPlayback($actor, 0);
  expect($playback->getActiveSegments(false, true)[0]['drawCommands'][0]['content'])->toBe($glyph)
    ->and($playback->getActiveSegments(false, false))->toBeEmpty();
});

it('refuses invalid condition references at the shared binding boundary', function (array $bindings) {
  expect(fn() => new BattleConditionEffects(new EffectTimelineLibrary(''), $bindings))->toThrow(InvalidArgumentException::class);
})->with([
  [['unknown' => []]], [['states' => 'bad']], [['states' => ['' => 'fx']]],
  [['states' => ['state' => '../fx']]], [['states' => ['state' => []]]],
  [['statStages' => ['hp' => ['positive' => 'fx']]]], [['statStages' => ['speed' => ['sideways' => 'fx']]]],
]);

it('diagnoses unsafe persistent timelines without dispatching their cues or changing state', function (array $changes) {
  $root = createTestDirectory('condition-unsafe');
  writeConditionTimeline($root, 'unsafe-loop', $changes);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('condition', 'Condition', 'C'));
  $effects = new BattleConditionEffects(new EffectTimelineLibrary($root), ['states' => ['condition' => 'unsafe-loop']]);
  $playback = $effects->createPlayback($actor, 100);
  expect($playback->getActiveSegments())->toBeEmpty()
    ->and($playback->getActiveSegments(terminal: true)[0]['drawCommands'][0]['content'])->toBe('C')
    ->and($actor->stats->currentHp)->toBe(100);
})->with([
  [['playback' => 'once']],
  [['cues' => [['id' => 'sound', 'frame' => 0, 'type' => 'playSound', 'payload' => ['sound' => 'bad']]]]],
  [['cues' => [['id' => 'impact', 'frame' => 0, 'type' => 'applyEffect']]]],
  [['tracks' => [['id' => 'screen', 'type' => 'glyph', 'anchor' => 'screen', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => 'bad']]]]]],
  [['restFrame' => 1, 'tracks' => [['id' => 'gap', 'type' => 'glyph', 'anchor' => 'target', 'keyframes' => [['frame' => 0, 'content' => 'bad']]]]]],
]);

it('freezes ambient time on pause and releases its binding on discard without curing gameplay states', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('condition', 'Condition', 'C'));
  $field = new ReflectionClass(BattleFieldWindow::class)->newInstanceWithoutConstructor();
  $effects = new BattleConditionEffects(new EffectTimelineLibrary(createTestDirectory('condition-pause')));
  $field->setConditionEffects($effects);
  $field->advancePoseTime(.1);
  $field->pauseTiming();
  $field->advancePoseTime(100);
  expect($field->getPoseElapsedSeconds())->toBe(.1);
  $field->resumeTiming();
  $field->advancePoseTime(.2);
  expect($field->getPoseElapsedSeconds())->toEqualWithDelta(.3, .000001);
  $field->resumeTiming(true);
  expect(new ReflectionProperty(BattleFieldWindow::class, 'conditionEffects')->getValue($field))->toBeNull()
    ->and($actor->hasState('condition'))->toBeTrue();
});
