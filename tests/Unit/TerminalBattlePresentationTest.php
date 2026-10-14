<?php

use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationState;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Battle\Presentation\TerminalBattlePresentation;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\CharacterSprites;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

function terminalPreviewEnemy(array $sprite, Vector2 $position): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Identical name', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'image' => $sprite, 'position' => $position] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  return $enemy;
}

function terminalPreviewField(BattleConfig $battle, PartyBattlerPositions $positions, Vector2 $origin,
  int $width = 135, int $height = 30): BattleFieldWindow
{
  // Only application boot is omitted: painting and geometry use the production classes.
  $scene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScene::class, 'config')->setValue($scene, $battle);
  $screen = new ReflectionClass(BattleScreen::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScreen::class, 'battleScene')->setValue($screen, $scene);
  new ReflectionProperty(BattleScreen::class, 'screenDimensions')->setValue($screen, new Rect($origin->x, $origin->y, $width, $height));
  $field = new ReflectionClass(BattleFieldWindow::class)->newInstanceWithoutConstructor();
  foreach (['battleScreen' => $screen, 'position' => $origin, 'width' => $width, 'height' => $height,
    'partyBattlerPositions' => $positions] as $key => $value) {
    new ReflectionProperty(BattleFieldWindow::class, $key)->setValue($field, $value);
  }
  return $field;
}

it('projects live conditions and the bounded defeat through the terminal field consumer', function (bool $reduced) {
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100));
  $hero->addState(new \Ichiloto\Engine\Entities\States\State('synthetic', 'Synthetic state', 'STATE'));
  $party->addMember($hero);
  $enemy = terminalPreviewEnemy(['ENEMY'], new Vector2(5, 5));
  $battle = new BattleConfig($party, new Troop('Synthetic', [$enemy]));
  $presentation = new TerminalBattlePresentation($battle);
  $playback = null;
  $events = [];
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $hero, [$enemy], BattlePoseRole::ATTACK, function () use ($enemy): void { $enemy->stats->currentHp = 0; },
    function ($cue) use (&$playback, $enemy, &$events): void {
      if ($cue['type'] === 'commandResolved') { $playback->beginEnemyDefeat($enemy); }
      if ($cue['type'] === 'enemyDefeated') { $events[] = $enemy; }
    });
  $playback->update($playback->plan->phases['return']['start'] / 120 + .01);
  $frame = $presentation->getFrame($playback, $reduced, .1);
  $plain = TerminalText::stripAnsi(implode("\n", $frame['lines']));
  expect($plain)->toContain('STATE', 'ENEMY')->not->toContain('KO')->and($events)->toBeEmpty();
  expect($presentation->getFrame($playback, $reduced, .1))->toBe($frame)->and($events)->toBeEmpty();
  $playback->update(10);
  $hero->removeState('synthetic');
  $plain = TerminalText::stripAnsi(implode("\n", $presentation->getFrame($playback, $reduced, 100)['lines']));
  expect($plain)->not->toContain('STATE', 'ENEMY', 'KO')->and($events)->toBe([$enemy]);
})->with([false, true]);

function terminalPreviewEffect(array $commands, string $layer = 'glyph', int $length = 2): CompiledEffectTimeline
{
  return new CompiledEffectTimeline('synthetic-terminal', '', fps: 10, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => $length - 1, 'layer' => $layer, 'drawCommands' => $commands,
  ]], defaults: ['lengthFrames' => $length, 'restFrame' => $length - 1]);
}

beforeEach(function () {
  $this->terminalStates = [];
  foreach ([Console::class, ConfigStore::class] as $class) { $this->terminalStates[$class] = new ReflectionClass($class)->getStaticProperties(); }
  Console::setTerminalOutputEnabled(false);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'overlays' => [], 'output' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::setLayerTracking(false);
  Console::syncDimensions(135, 30);
  ConfigStore::put(ProjectConfig::class, new PlaySettings([]));
  $this->positions = new PartyBattlerPositions();
  $this->origin = new Vector2();
  $this->actor = new Character('Identical name', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 20),
    images: new CharacterSprites(battle: ['A/\\', '|A|', 'AAA']));
  $this->targets = [terminalPreviewEnemy(['1111', ' 11 ', '1111'], new Vector2(10, 5)),
    terminalPreviewEnemy(['2222', ' 22 ', '2222'], new Vector2(24, 10))];
  $party = new Party();
  $party->addMember($this->actor);
  $this->battle = new BattleConfig($party, new Troop('Synthetic', $this->targets));
});

afterEach(function () {
  foreach ($this->terminalStates as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('renders fixed arena rows through the same production sprite and effect layout', function (bool $reduced, bool $enemyActs, bool $translated) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  $origin = $translated ? new Vector2(7, 4) : $this->origin;
  Console::syncDimensions(135 + intval($origin->x), 30 + intval($origin->y));
  $actor = $enemyActs ? $this->targets[0] : $this->actor;
  $targets = $enemyActs ? [$this->actor] : [...$this->targets, $this->targets[0]];
  $effect = terminalPreviewEffect([['content' => '>/', 'color' => 'cyan', 'position' => ['x' => 1, 'y' => 0],
    'payload' => ['facing' => 'east', 'legacyPosition' => 'feet']]]);
  $hits = $cues = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $actor, $targets, BattlePoseRole::MAGIC, function () use (&$hits) { $hits++; }, function () use (&$cues) { $cues++; });
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $field = terminalPreviewField($this->battle, $this->positions, $origin);
  $field->setCommandPlayback($playback);
  expect($field)->toBeInstanceOf(BattlePresentationState::class);
  $presentation = new TerminalBattlePresentation($this->battle, $this->positions, $origin);
  $before = [$playback->session->currentFrame, $hits, $cues, $actor->stats->currentHp, $actor->stats->currentMp];
  $frame = $presentation->getFrame($playback, $reduced);
  expect($presentation->getFrame($playback, $reduced))->toBe($frame)
    ->and([$playback->session->currentFrame, $hits, $cues, $actor->stats->currentHp, $actor->stats->currentMp])->toBe($before)
    ->and($frame['lines'])->toHaveCount(30);
  $field->renderParty($this->battle->party);
  $field->renderTroop($this->battle->troop);
  $field->renderMagicCastEffects();
  $buffer = Console::getBuffer();
  foreach ($frame['lines'] as $row => $line) {
    expect(TerminalText::displayWidth($line))->toBe(135);
    $live = NormalizedRow::fromText($buffer[$row + intval($origin->y)], Console::getWidth())->selectColumns(intval($origin->x), 135)->cells;
    expect(NormalizedRow::fromText($line)->cells)->toBe($live);
  }
  $liveDraws = new ReflectionProperty(BattleFieldWindow::class, 'magicCastEffects')->getValue($field);
  expect($liveDraws)->toBe(array_slice($frame['draws'], -count($liveDraws)));
})->with([false, true])->with([false, true])->with([false, true]);

it('shares live terminal condition draws with the preview using the field clock', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  $root = createTestDirectory('live-terminal-condition');
  mkdir($root . '/Animations/terminal-loop', 0777, true);
  file_put_contents($root . '/Animations/terminal-loop/terminal-loop.timeline.php', '<?php return ' . var_export([
    'fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => 'loop', 'tracks' => [[
      'id' => 'symbol', 'type' => 'glyph', 'anchor' => 'target', 'keyframes' => [
        ['frame' => 0, 'content' => 'A'], ['frame' => 1, 'content' => 'B']]]]], true) . ';');
  $this->actor->addState(new \Ichiloto\Engine\Entities\States\State('synthetic', 'State', 'S'));
  $bindings = new \Ichiloto\Engine\Battle\Presentation\BattleConditionEffects(
    new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($root), ['states' => ['synthetic' => 'terminal-loop']]);
  $origin = new Vector2();
  $field = terminalPreviewField($this->battle, $this->positions, $origin);
  $field->setConditionEffects($bindings);
  $presentation = new TerminalBattlePresentation($this->battle, $this->positions, $origin, conditionEffects: $bindings);
  foreach ([[.1, 'B'], [.1, $reduced ? 'B' : 'A']] as [$advance, $expected]) {
    $field->advancePoseTime($advance);
    Console::clear();
    $field->renderParty($this->battle->party);
    $field->renderTroop($this->battle->troop);
    $field->renderMagicCastEffects();
    $frame = $presentation->getFrame(null, $reduced, $field->getPoseElapsedSeconds());
    $buffer = Console::getBuffer();
    foreach ($frame['lines'] as $row => $line) {
      expect(NormalizedRow::fromText($line)->cells)->toBe(
        NormalizedRow::fromText($buffer[$row], Console::getWidth())->selectColumns(0, 135)->cells);
    }
    expect($frame['draws'][array_key_last($frame['draws'])]['text'])->toBe($expected);
  }
})->with([false, true]);

it('preserves head center feet and screen geometry without graphical motion', function (bool $reduced) {
  $effects = new TerminalBattleEffects($this->positions, new Vector2(5, 3));
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $this->actor, $this->targets, BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $party = $this->battle->partyRoster->battlers;
  $origins = [];
  foreach (AnimationTargetPosition::cases() as $position) {
    $origins[$position->value] = $effects->getAnimationOrigin($this->actor, $position, $party, $this->targets, $playback, $reduced);
  }
  $x = ($reduced ? 109 : 103) + 5 + 1;
  expect($origins['head'])->toBe(['x' => $x, 'y' => 8])
    ->and($origins['center'])->toBe(['x' => $x, 'y' => 9])
    ->and($origins['feet'])->toBe(['x' => $x, 'y' => 10])
    ->and($origins['screen'])->toBe(['x' => 72, 'y' => 18]);
  $playback->update($playback->plan->phases['return']['start'] / BattleCommandTimeline::FPS);
  expect($effects->getPartyPresentedPosition(0, $this->actor, $playback, $reduced))->toEqual($this->positions->idlePositions[0]);
  $playback->cancel();
  expect($effects->getPartyPresentedPosition(0, $this->actor, $playback, false))->toEqual($this->positions->idlePositions[0]);
  $unknown = new Character('Identical name', 1, new Stats(currentHp: 100));
  expect($effects->getAnimationOrigin($unknown, AnimationTargetPosition::CENTER, $party, $this->targets))->toBeNull();
})->with([false, true]);

it('composes screen effects once and legacy screen effects with the existing two cell inset', function (string $anchor, string $position, int $x, int $y) {
  $effect = terminalPreviewEffect([['content' => "  SCREEN\n    ART", 'position' => ['x' => 3, 'y' => 2],
    'payload' => ['anchor' => $anchor, 'legacyPosition' => $position]]]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $effects = new TerminalBattleEffects();
  $frame = $effects->compose($playback, fn($target, $position) => $effects->getAnimationOrigin($target, $position,
    $this->battle->partyRoster->battlers, $this->targets, $playback));
  expect($frame['draws'])->toBe([['text' => '  SCREEN', 'x' => $x, 'y' => $y], ['text' => '    ART', 'x' => $x, 'y' => $y + 1]]);
})->with([['screen', 'center', 70, 17], ['target', 'screen', 70, 17], ['legacy-screen', 'center', 5, 4]]);

it('shares subject flashes with runtime and suppresses full screen flashes and shake', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  $effect = terminalPreviewEffect([['payload' => ['anchor' => 'target', 'color' => 'yellow']],
    ['payload' => ['anchor' => 'screen', 'color' => 'red']]], 'flash');
  $effect->playbackSegments[] = ['startFrame' => 0, 'endFrame' => 1, 'layer' => 'shake',
    'drawCommands' => [['payload' => ['anchor' => 'screen', 'amplitude' => 8]]]];
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $frame = new TerminalBattlePresentation($this->battle)->getFrame($playback, $reduced);
  expect($frame['flashes'])->toHaveCount($reduced ? 0 : 2);
  $field = terminalPreviewField($this->battle, $this->positions, $this->origin);
  $field->setCommandPlayback($playback);
  $field->renderParty($this->battle->party);
  $field->renderTroop($this->battle->troop);
  $field->renderMagicCastEffects();
  foreach ($frame['lines'] as $row => $line) { expect(NormalizedRow::fromText($line)->cells)->toBe(NormalizedRow::fromText(Console::getBuffer()[$row])->cells); }
  expect(serialize($frame['lines']))->not->toContain(Color::RED->value);
})->with([false, true]);

it('clips ANSI wide troop art before the party zone and never paints half a glyph', function () {
  $sprite = [Color::GREEN->value . str_repeat("\u{754c}", 100) . Color::RESET->value];
  $enemy = terminalPreviewEnemy($sprite, new Vector2(500, 5));
  $battle = new BattleConfig($this->battle->party, new Troop('Synthetic', [$enemy]));
  $effects = new TerminalBattleEffects();
  $idle = $effects->getTroopIdlePosition($enemy);
  $active = $effects->getTroopActivePosition($enemy);
  expect($idle)->toEqual(new Vector2(2, 5))->and($active)->toEqual(new Vector2(5, 5));
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $enemy, [$this->actor], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $frame = new TerminalBattlePresentation($battle)->getFrame($playback);
  $field = terminalPreviewField($battle, $this->positions, $this->origin);
  $field->setCommandPlayback($playback);
  $field->renderParty($battle->party);
  $field->renderTroop($battle->troop);
  expect(NormalizedRow::fromText($frame['lines'][4])->cells)->toBe(NormalizedRow::fromText(Console::getBuffer()[4])->cells)
    ->and(TerminalText::displayWidth($frame['lines'][4]))->toBe(135)
    ->and(TerminalText::stripAnsi($frame['lines'][4]))->toContain('A/\\');
  $regions = $effects->getFlashRegions([['target' => $enemy, 'screen' => false, 'color' => Color::WHITE]],
    fn($target) => $effects->getBattlerAnchor($target, [$this->actor], [$enemy], $playback),
    fn($target) => $effects->getTroopVisibleSpriteWidth($target, $active));
  expect($regions[0]['right'])->toBeLessThanOrEqual($effects->getPartyZoneLeft() - 3);
});

it('does not promote reserves or change the console when inspecting a defeated party', function () {
  for ($i = 0; $i < 3; $i++) {
    $this->battle->party->addMember(new Character('Member ' . $i, 1, new Stats(currentHp: 100),
      images: new CharacterSprites(battle: ['RESERVE'])));
  }
  $battle = new BattleConfig($this->battle->party, $this->battle->troop, settings: ['reservePolicy' => 'replace_after_wipeout']);
  $roster = $battle->partyRoster->battlers;
  foreach ($roster as $member) { $member->stats->currentHp = 0; }
  $before = new ReflectionClass(Console::class)->getStaticProperties();
  $frame = new TerminalBattlePresentation($battle)->getFrame();
  expect($battle->partyRoster->battlers)->toBe($roster)
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($before)
    ->and(serialize($frame['lines']))->not->toContain('RESERVE');
});

it('uses the explicit reduced motion input rather than global accessibility settings', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => true]]));
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $this->actor, $this->targets, BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $presentation = new TerminalBattlePresentation($this->battle);
  $normal = $presentation->getFrame($playback, false);
  $reduced = $presentation->getFrame($playback, true);
  expect($normal['draws'][0]['x'])->toBe(103.0)->and($reduced['draws'][0]['x'])->toBe(109.0);
});

it('rejects invalid dimensions before producing a partial frame', function () {
  expect(fn() => new TerminalBattlePresentation($this->battle, width: 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new TerminalBattlePresentation($this->battle, height: -1))->toThrow(InvalidArgumentException::class);
});

it('holds terminal rest art without moving the command playhead or dispatching its cues', function () {
  $effect = new CompiledEffectTimeline('rest-art', '', fps: 10, playbackSegments: [
    ['startFrame' => 0, 'endFrame' => 0, 'layer' => 'glyph', 'drawCommands' => [['content' => 'MOVING']]],
    ['startFrame' => 1, 'endFrame' => 1, 'layer' => 'glyph', 'drawCommands' => [['content' => 'REST']]],
    ['startFrame' => 0, 'endFrame' => 1, 'layer' => 'image', 'clearBeforeDraw' => true,
      'drawCommands' => [['assetId' => 'must-not-load.png', 'zIndex' => 100, 'payload' => ['anchor' => 'target']]]],
  ], defaults: ['lengthFrames' => 2, 'restFrame' => 1]);
  $hits = $cues = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, $this->targets, BattlePoseRole::MAGIC, function () use (&$hits) { $hits++; }, function () use (&$cues) { $cues++; });
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $before = [$playback->session->currentFrame, $hits, $cues];
  $presentation = new TerminalBattlePresentation($this->battle);
  expect(serialize($presentation->getFrame($playback, false)['lines']))->toContain('MOVING')->not->toContain('must-not-load')
    ->and(serialize($presentation->getFrame($playback, true)['lines']))->toContain('REST')->not->toContain('MOVING')
    ->and([$playback->session->currentFrame, $hits, $cues])->toBe($before);
});

it('mirrors directional glyphs and offsets toward distinct targets rather than display names', function () {
  $effect = terminalPreviewEffect([['content' => '>/', 'position' => ['x' => 1, 'y' => 0], 'payload' => ['facing' => 'east']]]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $effects = new TerminalBattleEffects();
  $frame = $effects->compose($playback, fn($battler, $position) => $effects->getAnimationOrigin($battler, $position,
    [$this->actor], $this->targets, $playback));
  expect($frame['draws'])->toBe([['text' => '\\<', 'x' => 11, 'y' => 6], ['text' => '\\<', 'x' => 25, 'y' => 11]]);
});

it('erases an entire wide glyph when a terminal effect lands on its continuation cell', function () {
  $enemy = terminalPreviewEnemy(["\u{754c}E"], new Vector2(10, 5));
  $battle = new BattleConfig($this->battle->party, new Troop('Synthetic', [$enemy]));
  $effect = terminalPreviewEffect([['content' => 'X']]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [$enemy], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  $frame = new TerminalBattlePresentation($battle)->getFrame($playback);
  $field = terminalPreviewField($battle, $this->positions, $this->origin);
  $field->setCommandPlayback($playback);
  $field->renderParty($battle->party);
  $field->renderTroop($battle->troop);
  $field->renderMagicCastEffects();
  expect(NormalizedRow::fromText($frame['lines'][4])->cells)->toBe(NormalizedRow::fromText(Console::getBuffer()[4])->cells)
    ->and(substr(TerminalText::stripAnsi($frame['lines'][4]), 9, 3))->toBe(' XE')
    ->and(TerminalText::displayWidth($frame['lines'][4]))->toBe(135);
});

it('uses supplied geometry and origin for a smaller fixed arena', function () {
  $positions = new PartyBattlerPositions([new Vector2(45, 3)], [new Vector2(40, 3)]);
  $origin = new Vector2(6, 2);
  $enemy = terminalPreviewEnemy(['ENEMY'], new Vector2(500, 4));
  $battle = new BattleConfig($this->battle->party, new Troop('Synthetic', [$enemy]));
  $effect = terminalPreviewEffect([['content' => 'CENTER', 'payload' => ['anchor' => 'screen']]]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $enemy, [$this->actor], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / BattleCommandTimeline::FPS);
  Console::syncDimensions(66, 14);
  $field = terminalPreviewField($battle, $positions, $origin, 60, 12);
  $field->setCommandPlayback($playback);
  $field->renderParty($battle->party);
  $field->renderTroop($battle->troop);
  $field->renderMagicCastEffects();
  $frame = new TerminalBattlePresentation($battle, $positions, $origin, 60, 12)->getFrame($playback);
  expect($frame['lines'])->toHaveCount(12);
  foreach ($frame['lines'] as $row => $line) {
    expect(TerminalText::displayWidth($line))->toBe(60)
      ->and(NormalizedRow::fromText($line)->cells)->toBe(NormalizedRow::fromText(Console::getBuffer()[$row + 2])->selectColumns(6, 60)->cells);
  }
  expect($frame['draws'][0]['x'])->toBe(51.0)
    ->and($frame['draws'][3]['x'])->toBe(38.0)
    ->and($frame['draws'][4])->toBe(['text' => 'CENTER', 'x' => 36, 'y' => 8]);
});

it('inspects results only commands at rest without executing them', function () {
  $hits = $cues = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), resultsOnly: true),
    $this->actor, $this->targets, BattlePoseRole::MAGIC, function () use (&$hits) { $hits++; }, function () use (&$cues) { $cues++; });
  $presentation = new TerminalBattlePresentation($this->battle);
  expect($presentation->getFrame($playback))->toBe($presentation->getFrame())
    ->and($playback->session->currentFrame)->toBe(0)->and($hits)->toBe(0)->and($cues)->toBe(0);
});
