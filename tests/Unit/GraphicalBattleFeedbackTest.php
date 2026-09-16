<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackPlacement;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackTiming;
use Ichiloto\Engine\Battle\Presentation\BattleHudListSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleFeedback;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleHud;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

// Like the HUD fixture, use synthetic typed skin resources, not unadmitted Art assets.
function graphicalFeedbackSkin(): BattleUiSkin
{
  $textures = $colors = [];
  foreach (['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting'] as $role) {
    $textures[$role] = new CanvasNineSlice('graphical-canvas/synthetic-320x180.png', new SpriteSourceRect(0, 0, 8, 8));
  }
  foreach (['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'] as $index => $role) {
    $colors[$role] = PresentationColor::rgb(20 * $index, 10 * $index, 255 - 20 * $index);
  }
  return new BattleUiSkin($textures, $colors);
}

function graphicalFeedbackArena(?CanvasRectangle $area = null): BattleArenaDefinition
{
  return new BattleArenaDefinition(1350, 720,
    new CanvasImage('arena', 'graphical-canvas/synthetic-320x180.png', new CanvasRectangle(0, 0, 1350, 720)),
    [new BattlerSlot(969, 265, 143, 181)],
    [new BattlerSlot(375, 467, 173, 197), new BattlerSlot(497, 233, 197, 119)],
    skin: graphicalFeedbackSkin(), feedbackArea: $area ?? new CanvasRectangle(0, 80, 1350, 452));
}

function graphicalFeedbackParticipant(int $id, array $lines, array $persistent = [], ?CanvasRectangle $bounds = null): array
{
  return ['id' => $id, 'bounds' => $bounds ?? new CanvasRectangle(600, 250, 120, 160), 'persistent' => $persistent,
    'popups' => $lines === [] ? [] : [['sequence' => 1, 'shownAt' => 10.0, 'durationSeconds' => 2.0, 'lines' => $lines]]];
}

function graphicalFeedbackFixture(string $name = 'Hero'): array
{
  $party = new Party();
  $hero = new Character($name, 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20), actorId: 'hero');
  $party->addMember($hero);
  $enemies = [];
  foreach ([0, 1] as $index) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    foreach (['name' => 'Twin', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20),
      'position' => new Vector2($index * 10 + 1, 1), 'image' => ['test'], 'imagePath' => ''] as $key => $value) {
      new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
    }
    $enemies[] = $enemy;
  }
  $battle = new BattleConfig($party, new Troop('Twins', $enemies));
  $art = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 143, 181, 71.5, 181);
  $presentation = GraphicalBattlePresentation::prepare($battle,
    new BattlePresentationCatalog(['Twins' => graphicalFeedbackArena()], [$hero->actorId => $art], ['Twin' => $art]),
    __DIR__ . '/../Fixtures/Renderer');
  $scene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScene::class, 'config')->setValue($scene, $battle);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
  new ReflectionProperty(AbstractScene::class, 'camera')->setValue($scene, new Camera($scene, 135, 36));
  $scene->ui = new BattleScreen($scene);
  new ReflectionProperty(BattleFieldWindow::class, 'feedbackTiming')->setValue($scene->ui->fieldWindow,
    new BattleFeedbackTiming(static fn(): float => 10.0));
  return [$presentation, $scene->ui->fieldWindow, $hero, $enemies];
}

function graphicalFeedbackOverlap(CanvasRectangle $first, CanvasRectangle $second): float
{
  return max(0, min($first->x + $first->width, $second->x + $second->width) - max($first->x, $second->x))
    * max(0, min($first->y + $first->height, $second->y + $second->height) - max($first->y, $second->y));
}

function assertGraphicalFeedbackInside(CanvasRectangle $bounds, CanvasRectangle $area): void
{
  expect($bounds->x)->toBeGreaterThanOrEqual($area->x)
    ->and($bounds->y)->toBeGreaterThanOrEqual($area->y)
    ->and($bounds->x + $bounds->width)->toBeLessThanOrEqual($area->x + $area->width)
    ->and($bounds->y + $bounds->height)->toBeLessThanOrEqual($area->y + $area->height);
}

beforeEach(function () {
  $this->feedbackStatics = [];
  foreach ([ConfigStore::class, Console::class] as $class) { $this->feedbackStatics[$class] = new ReflectionClass($class)->getStaticProperties(); }
  ConfigStore::put(ProjectConfig::class, new PlaySettings([]));
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
});

afterEach(function () {
  foreach ($this->feedbackStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('styles typed feedback roles without parsing literal labels or terminal colors', function ($role, $colorRole, $pitch, $height) {
  $arena = graphicalFeedbackArena();
  $lines = [['text' => 'localized 48 MP', 'color' => Color::LIGHT_RED, 'role' => $role]];
  $layer = GraphicalBattleFeedback::compose($arena, [graphicalFeedbackParticipant(1, $lines)], [], 10)[0];
  expect($layer->runs[0]->text)->toBe('localized 48 MP')
    ->and($layer->runs[0]->foreground)->toBe($arena->skin->colors[$colorRole])
    ->and($layer->runs[0]->background)->toBeNull()->and($layer->glyphEffects)->not->toBeNull()
    ->and($layer->grid->cellWidth)->toBe($pitch)->and($layer->grid->cellHeight)->toBe($height);
})->with([
  [BattleFeedbackRole::WEAK, 'damage', 11, 22], [BattleFeedbackRole::RESIST, 'mp', 11, 22],
  [BattleFeedbackRole::NULL, 'mp', 11, 22], [BattleFeedbackRole::ABSORB, 'healing', 11, 22],
  [BattleFeedbackRole::CRITICAL, 'focus', 11, 22], [BattleFeedbackRole::DAMAGE, 'damage', 20, 40],
  [BattleFeedbackRole::HEAL, 'healing', 20, 40], [BattleFeedbackRole::MP_LOSS, 'mp', 20, 40],
  [BattleFeedbackRole::MP_GAIN, 'mp', 20, 40], [BattleFeedbackRole::KO, 'focus', 13, 26],
  [BattleFeedbackRole::MISS, 'text', 17, 34], [BattleFeedbackRole::ZERO, 'text', 20, 40],
]);

it('integrates real typed formatter lines and feedback identity without lifetime or gameplay changes', function () {
  [$presentation, $field, $hero, $enemies] = graphicalFeedbackFixture();
  $target = $enemies[0];
  $target->stats->currentHp = 0;
  $target->stats->currentMp = 12;
  $hit = new CombatHitResult('attack', 'attack:1', 'Hero', 'Twin', true, '', 500,
    ResolutionKind::PHYSICAL_DAMAGE, 500, 0, 0, 0.0, true, 1, true, 1.5, false, 'Fire',
    ElementalOutcome::WEAK, 2.0, 30, -500, 30, 0, 470, 0, 1, 100);
  $state = new ReflectionClass(ActionExecutionState::class)->newInstanceWithoutConstructor();
  $lines = new ReflectionMethod(ActionExecutionState::class, 'buildStatChangePopupLines')->invoke(
    $state, $target, 30, 20, new CombatTargetResult('Twin', [$hit]));
  $field->focusTroopBattlers([0]);
  $field->showStatChangePopup($target, $lines, durationSeconds: 2.0);
  $original = $field->getFeedback();
  $id = 'result-' . spl_object_id($target) . '-1-';
  $results = static fn($frame) => array_values(array_filter($frame->textLayers, static fn($layer) => str_starts_with($layer->id, $id)));
  $first = $results($presentation->frame($field, now: 10));
  $middle = $results($presentation->frame($field, now: 11));
  expect(array_map(fn($layer) => $layer->runs[0]->text, $first))->toBe(['WEAK!', 'CRITICAL', '30', '-8 MP', 'KO'])
    ->and($field->getFeedback())->toBe($original)->and($target->stats->currentHp)->toBe(0)
    ->and($target->stats->currentMp)->toBe(12)->and($hero->stats->currentHp)->toBe(100);
  foreach ($first as $index => $layer) {
    expect($middle[$index]->x)->toBe($layer->x)->and($middle[$index]->y - $layer->y)->toBe(-16.0)
      ->and($middle[$index]->opacity)->toBe($middle[0]->opacity)
      ->and($results($presentation->frame($field, now: 12))[$index]->opacity)->toBe(0.0);
  }
  expect($presentation->frame($field, now: 11)->toArray())->toBe($presentation->frame($field, now: 11)->toArray());
  $field->clearStatChangePopups();
  $cleared = $presentation->frame($field, now: 10.1);
  expect($results($cleared))->toBe([])
    ->and(array_map(fn($image) => $image->id, $cleared->images))->not->toContain('combatant-' . spl_object_id($target));
});

it('wraps long authored multiline participant names without dropping text changing source or adding layers', function () {
  $name = str_repeat('Long name ', 60) . "\r\nSecond line\n\nLast line";
  [$presentation, $field, $hero] = graphicalFeedbackFixture($name);
  $field->focusPartyBattler(0);
  $layers = $presentation->frame($field, now: 10)->textLayers;
  expect($layers)->toHaveCount(1)->and($hero->name)->toBe($name)
    ->and(implode('', array_column($layers[0]->runs, 'text')))->toBe(str_replace(["\r", "\n"], '', $name))
    ->and(array_filter(array_column($layers[0]->runs, 'text'), fn($text) => $text === ''))->toHaveCount(1)
    ->and($layers[0]->grid->rows)->toBeGreaterThan(4);
  assertGraphicalFeedbackInside($layers[0]->paintBounds, $presentation->arena->feedbackArea);
});

it('keeps persistent names and KO stationary through transient appearance fade and clear', function () {
  $arena = graphicalFeedbackArena();
  $persistent = [['text' => 'Selected name'], ['text' => 'KO']];
  $participant = graphicalFeedbackParticipant(1, [['text' => '48', 'role' => BattleFeedbackRole::DAMAGE]], $persistent);
  $initial = GraphicalBattleFeedback::compose($arena, [$participant], [], 10);
  $middle = GraphicalBattleFeedback::compose($arena, [$participant], [], 11);
  $participant['popups'] = [];
  $cleared = GraphicalBattleFeedback::compose($arena, [$participant], [], 11);
  expect(array_slice($initial, 0, 2))->toEqual(array_slice($middle, 0, 2))->toEqual($cleared)
    ->and($cleared[0]->opacity)->toBe(1.0)->and($cleared[1]->opacity)->toBe(1.0);
});

it('bounds critical enlargement and falls back before wrapping a value or colliding', function ($area, $value, $occupied, $expectedPitch) {
  $arena = graphicalFeedbackArena($area);
  $participant = graphicalFeedbackParticipant(1, [
    ['text' => 'CRITICAL', 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => $value, 'role' => BattleFeedbackRole::DAMAGE],
  ]);
  $layers = GraphicalBattleFeedback::compose($arena, [$participant], [], 10, $occupied);
  expect($layers)->toHaveCount(2)->and($layers[0]->runs[0]->text)->toBe('CRITICAL')
    ->and($layers[1]->runs[0]->text)->toBe($value)->and($layers[1]->grid->rows)->toBe(1)
    ->and($layers[1]->grid->cellWidth)->toBe($expectedPitch)
    ->and($layers[1]->grid->cellHeight)->toBe($expectedPitch * 2);
  foreach ($layers as $layer) {
    assertGraphicalFeedbackInside($layer->paintBounds, $area);
    foreach ($occupied as $obstacle) { expect(graphicalFeedbackOverlap($layer->paintBounds, $obstacle))->toBe(0.0); }
  }
})->with([
  [new CanvasRectangle(0, 80, 650, 200), '9999', [], 24],
  [new CanvasRectangle(0, 80, 650, 200), str_repeat('9', 30), [], 20],
  [new CanvasRectangle(0, 80, 650, 90), '9999', [], 20],
  [new CanvasRectangle(0, 80, 200, 100), '9999', [new CanvasRectangle(105, 80, 95, 100)], 20],
]);

it('reserves full padded travel envelopes for simultaneous recipient and same-recipient popups around grown HUD panels', function () {
  $arena = graphicalFeedbackArena();
  $participants = [];
  foreach ([1, 2, 3] as $id) {
    $participants[] = graphicalFeedbackParticipant($id, [
      ['text' => 'WEAK!', 'role' => BattleFeedbackRole::WEAK],
      ['text' => 'CRITICAL', 'role' => BattleFeedbackRole::CRITICAL],
      ['text' => '1234', 'role' => BattleFeedbackRole::DAMAGE],
    ], [['text' => 'Twin']], new CanvasRectangle(500 + $id * 20, 250, 100, 120));
  }
  $participants[0]['popups'][] = ['sequence' => 2, 'shownAt' => 10.0, 'durationSeconds' => 2.0,
    'lines' => [['text' => '+8 MP', 'role' => BattleFeedbackRole::MP_GAIN]]];
  $hud = [new CanvasRectangle(0, 0, 1350, 160), new CanvasRectangle(0, 532, 1350, 188)];
  $ui = [new CanvasTextLayer('opaque', 400, 900, 160, new RendererGridConfig(10, 4, 10, 20),
    [new PresentationTextRun(0, 0, str_repeat(' ', 10), background: PresentationColor::rgb(0, 0, 0))])];
  $start = GraphicalBattleFeedback::compose($arena, $participants, $ui, 10, $hud);
  $end = GraphicalBattleFeedback::compose($arena, $participants, $ui, 12, $hud);
  $envelopes = [];
  foreach ($start as $index => $layer) {
    $bounds = $layer->paintBounds;
    $final = $end[$index]->paintBounds;
    $swept = new CanvasRectangle($bounds->x, $final->y, $bounds->width, $bounds->y + $bounds->height - $final->y);
    assertGraphicalFeedbackInside($swept, $arena->feedbackArea);
    foreach ([...$hud, new CanvasRectangle(900, 160, 100, 20)] as $obstacle) {
      expect(graphicalFeedbackOverlap($swept, $obstacle))->toBe(0.0);
    }
    foreach ($envelopes as $otherIndex => $other) {
      if (preg_replace('/-\d+$/', '', $layer->id) !== preg_replace('/-\d+$/', '', $start[$otherIndex]->id)) {
        expect(graphicalFeedbackOverlap($swept, $other))->toBe(0.0);
      }
    }
    $envelopes[] = $swept;
  }
  foreach ([10.3, 11, 11.7] as $now) {
    $layers = GraphicalBattleFeedback::compose($arena, $participants, $ui, $now, $hud);
    new PresentationCanvas(1350, 720, textLayers: $layers);
    foreach ($layers as $index => $layer) {
      assertGraphicalFeedbackInside($layer->paintBounds, $envelopes[$index]);
      foreach (array_slice($layers, 0, $index) as $other) {
        expect(graphicalFeedbackOverlap($layer->paintBounds, $other->paintBounds))->toBe(0.0);
      }
    }
  }
  expect(array_map(fn($layer) => $layer->runs[0]->text, $start))->toBe([
    'Twin', 'Twin', 'Twin', 'WEAK!', 'CRITICAL', '1234', '+8 MP', 'WEAK!', 'CRITICAL', '1234', 'WEAK!', 'CRITICAL', '1234',
  ]);
});

it('suppresses drift under reduced motion without restarting or extending the same fade', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => true]]));
  $arena = graphicalFeedbackArena();
  $participants = [graphicalFeedbackParticipant(1, [['text' => 'MISS', 'role' => BattleFeedbackRole::MISS]])];
  $initial = GraphicalBattleFeedback::compose($arena, $participants, [], 10)[0];
  foreach ([10.2, 10.6, 11, 12, 15] as $now) {
    $layer = GraphicalBattleFeedback::compose($arena, $participants, [], $now)[0];
    expect($layer->x)->toBe($initial->x)->and($layer->y)->toBe($initial->y)
      ->and($layer->grid)->toEqual($initial->grid)
      ->and($layer->opacity)->toBe(BattleFeedbackTiming::motion(10, 2, $now, 32, true)['opacity']);
  }
});

it('keeps healing beside its recipient rather than below near another party member', function ($reducedMotion) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
  $kaelion = new CanvasRectangle(913, 94, 113, 181);
  $liora = new CanvasRectangle(1083, 213, 109, 179);
  $drazek = new CanvasRectangle(910, 327, 121, 183);
  $participants = [
    graphicalFeedbackParticipant(1, [['text' => '+42', 'role' => BattleFeedbackRole::HEAL]], [['text' => 'Kaelion']], $kaelion),
    graphicalFeedbackParticipant(2, [], [], $liora),
    graphicalFeedbackParticipant(3, [], [], $drazek),
  ];
  foreach ([10, 10.6, 11, 11.9] as $now) {
    $layers = GraphicalBattleFeedback::compose(graphicalFeedbackArena(), $participants, [], $now);
    $heal = array_values(array_filter($layers, fn($layer) => str_starts_with($layer->id, 'result-1-')))[0];
    expect($heal->runs[0]->text)->toBe('+42')
      ->and($heal->paintBounds->x + $heal->paintBounds->width)->toBe($kaelion->x - 4)
      ->and($heal->paintBounds->y + $heal->paintBounds->height)->toBeLessThan($liora->y);
    foreach ([$liora, $drazek] as $neighbor) {
      expect(graphicalFeedbackOverlap($heal->paintBounds, $neighbor))->toBe(0.0);
    }
  }
})->with([false, true]);

it('omits nonpositive duration popups immediately without discarding persistent labels', function ($duration) {
  $participant = graphicalFeedbackParticipant(1, [['text' => 'MISS', 'role' => BattleFeedbackRole::MISS]], [['text' => 'Name']]);
  $participant['popups'][0]['durationSeconds'] = $duration;
  $layers = GraphicalBattleFeedback::compose(graphicalFeedbackArena(), [$participant], [], 10);
  expect($layers)->toHaveCount(1)->and($layers[0]->runs[0]->text)->toBe('Name');
})->with([0.0, -1.0]);

it('finds free lateral slots for moving feedback while preserving legacy G1 placement', function () {
  $battler = new CanvasRectangle(125, 40, 50, 20);
  $area = new CanvasRectangle(0, 0, 300, 100);
  $obstacle = new CanvasRectangle(100, 0, 100, 100);
  $moving = BattleFeedbackPlacement::moving($battler, 100, 100, $area, [], [$obstacle], 0);
  expect(graphicalFeedbackOverlap($moving['envelope'], $obstacle))->toBe(0.0)
    ->and($moving['overlap'])->toBe(0.0);
  $ui = [new CanvasTextLayer('opaque', 400, 100, 0, new RendererGridConfig(10, 1, 10, 100),
    [new PresentationTextRun(0, 0, str_repeat(' ', 10), background: PresentationColor::rgb(0, 0, 0))])];
  expect(BattleFeedbackPlacement::place($battler, 100, 100, 300, 100, $ui)->toArray())
    ->toBe(['x' => 21.0, 'y' => 0.0, 'width' => 100.0, 'height' => 100.0]);
});

it('accounts for six full result blocks with four party HUD rows under the shared text layer budget', function () {
  $arena = graphicalFeedbackArena();
  $list = static fn(string $title) => new BattleHudListSnapshot($title, 'Help',
    array_map(static fn(int $index) => new BattleHudRow($index, 'Row ' . $index, $index === 0), range(0, 3)),
    0, 0, 4, 4, 1, 1);
  $hud = GraphicalBattleHud::compose($arena, new BattleHudSnapshot($list('Command'), $list('Context'), $list('Name'),
    new BattleHudStatusSnapshot('', '', array_map(static fn(int $index) => new BattleHudStatusRow($index, 100, 100, 20, 20, 0.5), range(0, 3))),
    message: 'Existing battle message'), null, 10);
  $lines = [
    ['text' => 'ABSORB', 'role' => BattleFeedbackRole::ABSORB],
    ['text' => 'CRITICAL', 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => '30', 'role' => BattleFeedbackRole::DAMAGE],
    ['text' => '+10', 'role' => BattleFeedbackRole::HEAL],
    ['text' => '-8 MP', 'role' => BattleFeedbackRole::MP_LOSS],
    ['text' => 'KO', 'role' => BattleFeedbackRole::KO],
  ];
  $participants = array_map(static fn(int $id) => graphicalFeedbackParticipant($id, $lines, [['text' => 'Name ' . $id]],
    new CanvasRectangle(100 + $id * 160, 300, 100, 120)), range(0, 5));
  $feedback = GraphicalBattleFeedback::compose($arena, $participants, [], 10, array_map(static fn($image) => $image->destination, $hud->images));
  expect($feedback)->toHaveCount(42)->and($hud->textLayers)->toHaveCount(18);
  expect(count($hud->textLayers) + count($feedback))->toBeLessThanOrEqual(64);
  new PresentationCanvas(1350, 720, $hud->images, textLayers: [...$hud->textLayers, ...$feedback]);
});
