<?php

use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateDisposition;

it('selects command poses from the action kind rather than its display name or wrapper', function () {
  expect(BattlePoseRole::getForAction(new AttackAction('Renamed strike')))->toBe(BattlePoseRole::ATTACK)
    ->and(BattlePoseRole::getForAction(new SkillBattleAction(new BasicSkill('Renamed strike', '', '', 0, 0))))
    ->toBe(BattlePoseRole::ATTACK)
    ->and(BattlePoseRole::getForAction(new SkillBattleAction(new SpecialSkill('Attack', '', '', 0, 0))))
    ->toBe(BattlePoseRole::SKILL)
    ->and(BattlePoseRole::getForAction(new SkillBattleAction(new MagicSkill('Attack', '', '', 0, 0))))
    ->toBe(BattlePoseRole::MAGIC);
});

it('selects persistent poses from live status meaning rather than status names or action blocking alone', function () {
  $battler = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $benefit = new State('blessing', 'Poison', disposition: StateDisposition::BENEFICIAL);
  $harm = new State('venom', 'Blessing');
  $neutral = new State('marker', 'Sleep', disposition: StateDisposition::NEUTRAL);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::IDLE);
  $battler->addState($neutral);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::IDLE);
  $battler->addState($benefit);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::ENHANCED);
  $battler->addState($harm);
  expect($battler->getActionBlockingState())->toBeNull()
    ->and(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::AFFLICTED);
  $battler->beginGuarding();
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::GUARD);
  $battler->stats->currentHp = 0;
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::KNOCKOUT);
  $battler->stats->currentHp = 50;
  $battler->stopGuarding();
  $battler->removeState('venom');
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::ENHANCED);
  $battler->clearBattleStates();
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::IDLE);
});

it('selects enhanced and afflicted poses from actual stat stages and clears them when the stages reset', function () {
  $battler = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $battler->addStatStage('attack', 1);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::ENHANCED);
  $battler->addStatStage('speed', -1);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::AFFLICTED);
  $battler->setStatStage('speed', 0);
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::ENHANCED);
  $battler->resetStatStages();
  expect(BattlePoseRole::getRestingRole($battler))->toBe(BattlePoseRole::IDLE);
});

it('selects animated battle pose cells using elapsed playback time and an explicit rest frame', function () {
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 2, frames: [0, 1], fps: 5, restFrame: 1);
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $first = $pose->getArtwork($root, 0);
  $second = $pose->getArtwork($root, .2);
  expect([$first->width, $first->height, $first->pivotX, $first->pivotY])->toBe([160, 180, 80.0, 180.0])
    ->and($first->sourceRect->x)->toBe(0)->and($second->sourceRect->x)->toBe(160)
    ->and($pose->getArtwork($root, .4)->sourceRect->x)->toBe(0)
    ->and($pose->getArtwork($root, 0, true)->sourceRect->x)->toBe(160);
});

it('holds a non-looping pose final cell and permits a still image in the same role contract', function () {
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 2, frames: [0, 1], loop: false);
  expect($pose->getArtwork($root, 100)->sourceRect->x)->toBe(160);
  $still = new BattlerPose('graphical-canvas/synthetic-143x181.png');
  expect($still->getArtwork($root, 100)->sourceRect)->toBeNull();
});

it('reads the current pose asset dimensions rather than freezing the previous PNG size', function () {
  $root = sys_get_temp_dir() . '/ichiloto-pose-' . bin2hex(random_bytes(6));
  mkdir($root);
  try {
    $pose = new BattlerPose('pose.png', columns: 2, frames: [0, 1]);
    foreach ([[20, 30], [40, 60]] as [$width, $height]) {
      $png = imagecreatetruecolor($width, $height);
      imagepng($png, $root . '/pose.png');
      clearstatcache(true, $root . '/pose.png');
      $art = $pose->getArtwork($root, .2);
      expect([$art->width, $art->height])->toBe([intdiv($width, 2), $height]);
      unset($png);
    }
  } finally {
    unlink($root . '/pose.png'); rmdir($root);
  }
});

it('returns no optional pose for unavailable artwork and rejects malformed role or grid definitions', function () {
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  expect((new BattlerPose('missing.png'))->getArtwork($root, 0))->toBeNull();
  expect(fn() => new BattlePoseSet(['made-up' => new BattlerPose('pose.png')]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new BattlerPose('pose.png', columns: 2, frames: [2]))->toThrow(InvalidArgumentException::class);
  expect(fn() => (new BattlerPose('graphical-canvas/synthetic-143x181.png', columns: 2))->getArtwork($root, 0))
    ->toThrow(InvalidArgumentException::class);
  $set = new BattlePoseSet(['attack' => new BattlerPose('attack.png')]);
  expect($set->getPose(BattlePoseRole::ATTACK)->asset)->toBe('attack.png')
    ->and($set->getPose(BattlePoseRole::DAMAGE))->toBeNull();
});

it('stores a distinct petition gesture without aliasing it to summon or granting an action', function () {
  $set = new BattlePoseSet(['petition' => new BattlerPose('petition.png')]);
  expect($set->getPose(BattlePoseRole::PETITION)->asset)->toBe('petition.png')
    ->and($set->getPose(BattlePoseRole::SUMMON))->toBeNull()
    ->and(BattlePoseRole::getForAction(new SkillBattleAction(new SpecialSkill('Petition', '', '', 0, 0))))
    ->toBe(BattlePoseRole::SKILL);
});

it('keeps registered pose scale and ground pivots independent of the idle contain box', function () {
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $set = new BattlePoseSet([
    'idle' => new BattlerPose('graphical-canvas/synthetic-320x180.png', pivotY: .9),
    'attack' => new BattlerPose('graphical-canvas/synthetic-320x180.png', pivotY: .9),
  ], displayWidth: 240);
  $slot = new BattlerSlot(500, 400, 100, 100);
  foreach ($set->roles as $pose) {
    $art = $pose->getArtwork($root, 0);
    $bounds = $slot->place($art, $set->displayWidth);
    expect($bounds->toArray())->toBe(['x' => 380.0, 'y' => 278.5, 'width' => 240.0, 'height' => 135.0])
      ->and($bounds->x + $art->pivotX * ($bounds->width / $art->width))->toBe($slot->x)
      ->and($bounds->y + $art->pivotY * ($bounds->height / $art->height))->toBe($slot->y);
  }
  expect(fn() => new BattlePoseSet([], displayWidth: INF))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattlePoseSet([], displayWidth: 0))->toThrow(InvalidArgumentException::class);
});
