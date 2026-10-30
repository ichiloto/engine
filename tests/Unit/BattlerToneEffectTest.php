<?php

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineLibrary, EffectPresentation};
use Ichiloto\Engine\Battle\{BattleReactions, CounterAttackRule};
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Presentation\{BattleConditionEffects, BattleEffectTone};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Skills\{BasicSkill, SkillCatalog};
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasRectangle};
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-state-tone-' . bin2hex(random_bytes(6));
  mkdir($this->root . '/Animations/armed', 0777, true);
  $this->data = ['fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => 'loop', 'tracks' => [
    ['id' => 'shade', 'type' => 'tone', 'anchor' => 'target', 'presentation' => 'graphical', 'keyframes' => [
      ['frame' => 0, 'payload' => ['brightness' => .7]], ['frame' => 1, 'payload' => ['brightness' => .15]],
    ]],
    ['id' => 'label', 'type' => 'glyph', 'anchor' => 'target', 'presentation' => 'terminal',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => 'S']]],
  ]];
  file_put_contents($this->root . '/Animations/armed/armed.timeline.php', '<?php return ' . var_export($this->data, true) . ';');
  $this->actor = new Character('Same name', 0, new Stats(currentHp: 100, totalHp: 100));
  $this->state = new State('armed', 'Prepared stance', 'S');
  $this->effects = new BattleConditionEffects(new EffectTimelineLibrary($this->root), ['states' => ['armed' => 'armed']]);
});

afterEach(function () {
  unlink($this->root . '/Animations/armed/armed.timeline.php');
  rmdir($this->root . '/Animations/armed'); rmdir($this->root . '/Animations'); rmdir($this->root);
});

it('applies state tone to current mirrored image values with static rest and no mutation', function (bool $reduced) {
  $this->actor->addState($this->state); $id = spl_object_id($this->actor);
  $before = serialize($this->actor);
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(100, 200, 32, 48), 100,
    new SpriteSourceRect(4, 3, 8, 6), .8, new CanvasRectangle(100, 200, 32, 42), .8, true, true);
  foreach ([0.0, .1, .25] as $seconds) {
    $playback = $this->effects->createPlayback($this->actor, $seconds);
    $factor = BattleEffectTone::getMultipliers($playback, $reduced)[$id];
    $drawn = BattleEffectTone::applyToImage($image, $factor);
    expect($factor)->toBe($reduced || $seconds === .1 ? .15 : .7)
      ->and($drawn->brightness)->toEqualWithDelta(.8 * $factor, 1e-9)
      ->and($drawn->destination)->toBe($image->destination)->and($drawn->sourceRect)->toBe($image->sourceRect)
      ->and($drawn->layer)->toBe($image->layer)->and($drawn->opacity)->toBe(.8)
      ->and($drawn->clipRect)->toBe($image->clipRect)->and($drawn->flipX)->toBeTrue()->and($drawn->flipY)->toBeTrue()
      ->and(array_column($playback->getActiveSegments($reduced, true), 'layer'))->toBe(['glyph']);
  }
  expect(serialize($this->actor))->toBe($before)->and($image->brightness)->toBe(.8);
  $this->actor->removeState('armed');
  expect($this->effects->createPlayback($this->actor, 2))->toBeNull();
})->with([false, true]);

it('keeps eight same-name actors independent without extra source assets or composite surfaces', function () {
  $actors = array_map(fn() => new Character('Same name', 0, new Stats(currentHp: 100)), range(1, 8));
  $ids = [];
  foreach ($actors as $actor) {
    $actor->addState($this->state);
    $tone = BattleEffectTone::getMultipliers($this->effects->createPlayback($actor, 0), true);
    expect($tone)->toBe([spl_object_id($actor) => .15]);
    array_push($ids, ...array_keys($tone));
  }
  expect(array_unique($ids))->toHaveCount(8);
});

it('ends state appearance at the real prepared interception and creates one response', function () {
  $foe = new Character('Foe', 0, new Stats(currentHp: 100));
  $catalog = SkillCatalog::fromSkills(['reply.php' => new BasicSkill('Reply', '', '', 0, 0,
    effects: [new HPDamageSkillEffect('7', variance: 0)])]);
  $reactions = new BattleReactions($catalog);
  $reactions->prepareCounter($this->actor, $foe, new CounterAttackRule('Reply'), $this->state);
  expect($this->effects->createPlayback($this->actor, 5))->not->toBeNull();
  $attack = new AttackAction('Attack'); $attack->executeWithReactions($foe, [$this->actor], $reactions);
  expect($attack->lastResult->hits()[0]->missReason)->toBe('decoy')
    ->and($this->actor->stats->currentHp)->toBe(100)
    ->and($this->effects->createPlayback($this->actor, 6))->toBeNull()
    ->and($reactions->takeResponses($foe))->toHaveCount(1);
  $reactions->clear();
});

it('refuses malformed or field-owned tone tracks and keeps Terminal content independent', function () {
  $library = new EffectTimelineLibrary($this->root);
  foreach ([-1, 2, '1', NAN] as $bad) {
    $data = $this->data; $data['tracks'][0]['keyframes'][0]['payload']['brightness'] = $bad;
    expect(fn() => $library->compile('bad-tone', $data, true))->toThrow(InvalidArgumentException::class);
  }
  $data = $this->data; $data['tracks'][0]['anchor'] = 'screen';
  expect(fn() => $library->compile('screen-tone', $data, true))->toThrow(InvalidArgumentException::class);
  expect(fn() => $library->compile('field-tone', $this->data))->toThrow(InvalidArgumentException::class);
  expect(array_column($library->compile('terminal', $this->data, true, EffectPresentation::TERMINAL)->playbackSegments, 'layer'))->toBe(['glyph']);
});
