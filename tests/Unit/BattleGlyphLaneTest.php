<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineValidator, EffectPresentation};
use Ichiloto\Engine\Battle\Presentation\{TerminalBattleLaneSnapshot, TerminalBattleGlyphLane};
use Ichiloto\Engine\IO\Console\{TerminalText, NormalizedRow};
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;

function glyphLaneFixture(): array
{
  return ['fps' => 24, 'lengthFrames' => 8, 'restFrame' => 2, 'targetCaptureFrame' => 1,
    'effectTiming' => ['mode' => 'frame', 'frame' => 2], 'tracks' => [[
      'id' => 'single-lane', 'type' => 'glyph-lane', 'facing' => 'west', 'keyframes' => [[
        'frame' => 2, 'duration' => 3, 'color' => 'cyan', 'payload' => [
          'head' => [' /','<=',' \\'], 'shaft' => ['--','==','--']]],
      ['frame' => 5, 'visible' => false, 'payload' => ['head' => ['<'], 'shaft' => ['-']]],
    ]]]];
}

it('compiles independent Terminal cell art and preserves it through source editing', function () {
  $data = glyphLaneFixture();
  $data['tracks'][0] = SummonCutsceneTrack::fromArray($data['tracks'][0])->toArray();
  $effect = (new EffectTimelineValidator('/does-not-exist'))->compile('single-lane', $data, forBattle: true, presentation: EffectPresentation::TERMINAL);
  expect($effect->hasTerminalContent)->toBeTrue()->and($effect->playbackSegments)->toHaveCount(2)
    ->and($effect->defaults['terminalLaneCaptureFrame'])->toBe(1)
    ->and($effect->playbackSegments[0]['drawCommands'][0]['payload']['head'])->toBe([' /','<=',' \\'])
    ->and($effect->defaults)->not->toHaveKey('targetCaptureFrame');
  expect((new EffectTimelineValidator(''))->compile('graphic', $data, forBattle: true)->playbackSegments)->toBe([])
    ->and(fn() => (new EffectTimelineValidator(''))->compile('ambient', $data, presentation: EffectPresentation::TERMINAL))->toThrow(InvalidArgumentException::class)
    ->and(fn() => (new EffectTimelineValidator(''))->compile('summon', $data, forSummon: true, presentation: EffectPresentation::TERMINAL))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid lane schemas before playback', function (Closure $change) {
  $data = glyphLaneFixture(); $change($data);
  expect(fn() => (new EffectTimelineValidator(''))->compile('bad', $data, forBattle: true, presentation: EffectPresentation::TERMINAL))
    ->toThrow(InvalidArgumentException::class);
})->with([
  [function(array &$d) { unset($d['targetCaptureFrame']); }],
  [fn(array &$d) => $d['targetCaptureFrame'] = '1'],
  [fn(array &$d) => $d['targetCaptureFrame'] = 8],
  [fn(array &$d) => $d['tracks'][0]['facing'] = 'north'],
  [fn(array &$d) => $d['tracks'][0]['presentation'] = 'all'],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['frame'] = 0],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 0],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 10],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][1]['frame'] = 3],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['visible'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['zIndex'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = []],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = ['AB','AB']],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = ['A','AB','A']],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = ["\e[31mX"]],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = ["X\nY"]],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['head'] = [str_repeat('X',33)]],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['shaft'] = ['-']],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['color'] = 'future-neon'],
  [fn(array &$d) => $d['playback'] = 'loop'],
  [function(array &$d) { unset($d['fps']); $d['cadence'] = 'battle-phase'; }],
]);

it('keeps immutable selected identity and rejects malformed Terminal capture data', function () {
  $x = 80; $point = ['x' => &$x, 'y' => 10]; $targetX = 20;
  $snapshot = new TerminalBattleLaneSnapshot($point, [42 => ['x' => &$targetX, 'y' => 6],43 => ['x' => 35,'y' => 14]], [], true);
  $x = 1; $targetX = 100;
  expect($snapshot->caster)->toBe(['x' => 80,'y' => 10])->and(array_keys($snapshot->targets))->toBe([42,43])
    ->and($snapshot->getEndpoint())->toBe(['x' => 20,'y' => 10])->and($snapshot->getDirectionSign())->toBe(-1);
  foreach ([[], [0 => ['x'=>1,'y'=>2]], [42 => ['x'=>1.1,'y'=>2]], [42 => ['x'=>INF,'y'=>2]]] as $targets) {
    expect(fn() => new TerminalBattleLaneSnapshot(['x'=>1,'y'=>1], $targets, [], true))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new TerminalBattleLaneSnapshot(['x'=>1,'y'=>1], [42=>['x'=>2,'y'=>2]], [['x'=>3,'y'=>3,'width'=>0,'height'=>1]], true))->toThrow(InvalidArgumentException::class);
  expect((new TerminalBattleLaneSnapshot(['x'=>20,'y'=>10], [42=>['x'=>20,'y'=>3]], [], true))->getDirectionSign())->toBe(-1);
});

it('draws one oriented head, tiles the shaft and occludes unrelated bodies without moving clipped paint', function (bool $east) {
  $snapshot = new TerminalBattleLaneSnapshot(['x'=>$east?10:80,'y'=>10], [42=>['x'=>$east?65:25,'y'=>6],43=>['x'=>$east?50:40,'y'=>14]],
    [['x'=>45,'y'=>9,'width'=>7,'height'=>3]], !$east);
  $payload = glyphLaneFixture()['tracks'][0]['keyframes'][0]['payload'] + ['facing'=>'west'];
  $draws = TerminalBattleGlyphLane::compose($snapshot, $payload, 'cyan', 1, 1, 98, 18);
  $text = implode('',array_map(static fn(array $d):string=>TerminalText::stripAnsi($d['text']),$draws));
  expect(substr_count($text,$east?'>':'<'))->toBe(1)->and(substr_count($text,$east?'<':'>'))->toBe(0);
  foreach ($draws as $draw) {
    $width = TerminalText::displayWidth($draw['text']);
    expect($snapshot->isOccluded($draw['x'],$draw['y'],$width))->toBeFalse()
      ->and($draw['x'])->toBeGreaterThanOrEqual(1)->and($draw['x']+$width)->toBeLessThanOrEqual(99)
      ->and($draw['y'])->toBeGreaterThanOrEqual(1)->and($draw['y'])->toBeLessThan(19);
  }
  $clipped = TerminalBattleGlyphLane::compose($snapshot,$payload,null,30,9,10,3);
  foreach ($clipped as $draw) { expect($draw['x'])->toBeGreaterThanOrEqual(30)->and($draw['x']+TerminalText::displayWidth($draw['text']))->toBeLessThanOrEqual(40); }
  expect(implode('',array_column($clipped,'text')))->not->toContain('<','>');
})->with([false,true]);

it('clips an entire wide head cell and never leaves a continuation half at the edge', function () {
  $snapshot = new TerminalBattleLaneSnapshot(['x'=>40,'y'=>4],[42=>['x'=>5,'y'=>4]],[],true);
  $payload=['head'=>['界'],'shaft'=>['─'],'facing'=>'west'];
  $full=TerminalBattleGlyphLane::compose($snapshot,$payload,null,1,1,50,8);
  expect(implode('',array_column($full,'text')))->toContain('界');
  $clipped=TerminalBattleGlyphLane::compose($snapshot,$payload,null,1,1,5,8);
  expect(implode('',array_column($clipped,'text')))->not->toContain('界');
});
