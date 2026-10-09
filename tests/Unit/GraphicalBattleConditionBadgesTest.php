<?php

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleConditionBadgeStyle;
use Ichiloto\Engine\Battle\Presentation\BattleConditionEffects;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleConditions;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->badgeStatics = [];
  foreach ([Debug::class, ConfigStore::class] as $class) {
    $this->badgeStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->badgeRoot = createTestDirectory('condition-badges');
  Debug::configure(['log_directory' => $this->badgeRoot . '/logs']);
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
});

afterEach(function () {
  foreach ($this->badgeStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

function createConditionBadgeSkin(string $root, array $icons = [], ?BattleConditionBadgeStyle $style = null,
  bool $monochrome = true): BattleUiSkin
{
  $colors = array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'],
    PresentationColor::rgb(190, 190, 190));
  $colors['ink'] = PresentationColor::rgb(10, 20, 30);
  if (!$monochrome) {
    $colors['ink'] = PresentationColor::rgb(10, 20, 30);
    $colors['healing'] = PresentationColor::rgb(20, 220, 110);
    $colors['damage'] = PresentationColor::rgb(240, 50, 70);
  }
  return new BattleUiSkin(array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'],
    new CanvasNineSlice('skin.png', new SpriteSourceRect(0, 0, 1, 1))), $colors,
    icons: new MenuIconRegistry($root, $icons), conditionBadges: $style ?? new BattleConditionBadgeStyle());
}

function composeConditionBadgeFixture(Character $battler, string $root, ?BattleUiSkin $skin = null): \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas
{
  return GraphicalBattleConditions::compose(new BattleCanvasLayout(1350, 720, skin: $skin,
    feedbackArea: $skin === null ? null : new CanvasRectangle(0, 80, 1350, 452)),
    [spl_object_id($battler) => $battler], [spl_object_id($battler) => new CanvasRectangle(600, 280, 100, 160)], $root);
}

it('projects all seven stat variants with stable semantic keys and live magnitudes', function (string $stat, int $stage) {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->setStatStage($stat, $stage);
  $entry = BattlerConditions::getEntries($actor)[0];
  expect($entry['iconKey'])->toBe('status.stat.' . $stat . '.' . ($stage > 0 ? 'positive' : 'negative'))
    ->and($entry['symbol'])->toBe('stat:' . $stat)->and($entry['magnitude'])->toBe(abs($stage))
    ->and($entry['name'])->toContain(($stage > 0 ? '+' : '') . $stage);
  $frame = composeConditionBadgeFixture($actor, $this->badgeRoot);
  $labels = array_filter($frame->textLayers, static fn($layer) => str_ends_with($layer->id, '-label'));
  expect($frame->images)->toBeEmpty()->and($frame->getOverlayProtection())->toHaveCount(1)
    ->and(array_values($labels)[0]->runs[0]->text)->toContain(($stage > 0 ? '+' : '') . $stage);
})->with(Character::buffableStats())->with([-4, -1, 1, 4]);

it('draws distinct stat pictograms and color-independent up and down geometry', function () {
  $patterns = [];
  foreach (Character::buffableStats() as $stat) {
    $actor = new Character('Actor', 1, new Stats(currentHp: 100));
    foreach ([1, -1] as $stage) {
      $actor->setStatStage($stat, $stage);
      $frame = composeConditionBadgeFixture($actor, $this->badgeRoot, createConditionBadgeSkin($this->badgeRoot));
      $pixels = array_filter($frame->textLayers, static fn($layer) => str_starts_with($layer->id, 'status-badges-')
        || str_starts_with($layer->id, 'status-markers-'));
      $patterns[$stat][$stage] = array_map(static fn($layer) => array_map(
        static fn($run) => [$run->row, $run->column, strlen($run->text)], $layer->runs), array_values($pixels));
    }
    expect($patterns[$stat][1])->not->toBe($patterns[$stat][-1]);
  }
  expect(array_unique(array_map(static fn($pair) => serialize($pair[1]), $patterns)))->toHaveCount(7);
});

it('uses poison and stun identity rather than mutable display names or icons', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('poison', 'Renamed one', 'same'));
  $actor->addState(new State('stun', 'Renamed two', 'same'));
  $entries = BattlerConditions::getEntries($actor);
  expect(array_column($entries, 'iconKey'))->toBe(['status.state.poison', 'status.state.stun'])
    ->and(array_column($entries, 'symbol'))->toBe(['state:poison', 'state:stun'])
    ->and(array_column($entries, 'label'))->toBe(['PSN', 'STN']);
  writeTestPng($this->badgeRoot . '/poison.png', 32, 32);
  writeTestPng($this->badgeRoot . '/stun.png', 32, 32);
  $skin = createConditionBadgeSkin($this->badgeRoot,
    ['status.state.poison' => 'poison.png', 'status.state.stun' => 'stun.png']);
  $first = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin)->toArray();
  $actor->removeState('poison');
  $remaining = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin)->toArray();
  $firstStun = array_find($first['images'], static fn($image) => $image['asset'] === 'stun.png');
  expect($firstStun)->not->toBeNull()
    ->and(array_column($remaining['images'], 'id'))->toBe([$firstStun['id']]);
});

it('retains state disposition and useful authored terminal text without naming or formula inference', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  foreach ([StateDisposition::BENEFICIAL, StateDisposition::HARMFUL, StateDisposition::NEUTRAL] as $index => $disposition) {
    $actor->addState(new State('state-' . $index, 'Identical name', "\033[31mS\033[0m", disposition: $disposition));
  }
  expect(array_column(BattlerConditions::getEntries($actor), 'polarity'))->toBe(['positive', 'negative', 'neutral']);
  $effects = new BattleConditionEffects(new EffectTimelineLibrary($this->badgeRoot));
  $playback = $effects->createPlayback($actor, 100);
  expect($playback->getActiveSegments())->toBeEmpty()
    ->and($playback->getActiveSegments(terminal: true)[0]['drawCommands'][0]['content'])->toBe("\033[31mS\033[0m");
});

it('keeps state and stat symbols separate and makes authored badge names safe single-line text', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('attack', "\033[31m\n\tSharp\033[0m"));
  $actor->setStatStage('attack', 1);
  $entries = BattlerConditions::getEntries($actor);
  expect(array_column($entries, 'symbol'))->toBe(['state:attack', 'stat:attack'])
    ->and($entries[0]['label'])->toBe('SHA');
  $frame = composeConditionBadgeFixture($actor, $this->badgeRoot);
  $labels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers));
  expect($labels)->toContain('SHA', '+1');
});

it('contains independently bound replaceable artwork while keeping direction and stage visible', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->setStatStage('attack', 2);
  $actor->setStatStage('defence', -3);
  $skin = createConditionBadgeSkin($this->badgeRoot, ['status.stat.attack.positive' => 'up.png',
    'status.stat.defence.negative' => 'down.png', 'unknown' => 'unrelated.png']);
  writeTestPng($this->badgeRoot . '/unrelated.png', 4, 4);
  foreach ([[12, 4], [4, 16], [24, 24]] as [$width, $height]) {
    writeTestPng($this->badgeRoot . '/replacement.png', $width, $height);
    rename($this->badgeRoot . '/replacement.png', $this->badgeRoot . '/up.png');
    writeTestPng($this->badgeRoot . '/down.png', 8, 8);
    $frame = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin);
    expect(array_column($frame->images, 'asset'))->toBe(['up.png', 'down.png'])
      ->and($frame->images[0]->destination->width / $frame->images[0]->destination->height)
      ->toEqualWithDelta($width / $height, .00001);
    $labels = array_values(array_filter($frame->textLayers, static fn($layer) => str_ends_with($layer->id, '-label')));
    expect(array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $labels)))->toBe(['+2', '-3']);
    expect($frame->images[0]->clipRect->width)->toBe(32.0)
      ->and($frame->images[0]->clipRect->height)->toBe(32.0);
    foreach ($frame->images as $image) {
      $footer = $labels[0];
      expect($footer->y)->toBeGreaterThanOrEqual($image->destination->y + $image->destination->height);
    }
    expect(array_filter($frame->textLayers, static fn($layer) => str_starts_with($layer->id, 'status-badges-')
      || str_starts_with($layer->id, 'status-markers-')))->toBeEmpty();
  }
  unlink($this->badgeRoot . '/up.png');
  expect(array_column(composeConditionBadgeFixture($actor, $this->badgeRoot, $skin)->images, 'asset'))->toBe(['down.png']);
  file_put_contents($this->badgeRoot . '/down.png', 'not a png');
  $frame = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin);
  expect($frame->images)->toBeEmpty()->and($frame->getOverlayProtection())->toHaveCount(2)
    ->and(file_get_contents($this->badgeRoot . '/logs/warning.log'))->toContain('up.png', 'down.png');
});

it('removes cured expired and zero-stage badges immediately and restores live ones after revival', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('poison', 'Poison', durationTurns: 1));
  $actor->addState(new State('stun', 'Stun'));
  $actor->setStatStage('speed', 1);
  $before = serialize($actor);
  expect(composeConditionBadgeFixture($actor, $this->badgeRoot)->getOverlayProtection())->toHaveCount(3)
    ->and(serialize($actor))->toBe($before);
  $actor->tickStates();
  $actor->removeState('stun');
  expect(composeConditionBadgeFixture($actor, $this->badgeRoot)->getOverlayProtection())->toHaveCount(1);
  $actor->stats->currentHp = 0;
  $ko = composeConditionBadgeFixture($actor, $this->badgeRoot);
  expect($ko->textLayers)->toBeEmpty()->and($actor->getStatStage('speed'))->toBe(1);
  $actor->stats->currentHp = 50;
  expect(composeConditionBadgeFixture($actor, $this->badgeRoot)->getOverlayProtection())->toHaveCount(1);
  $actor->setStatStage('speed', 0);
  expect(composeConditionBadgeFixture($actor, $this->badgeRoot)->textLayers)->toBeEmpty();
});

it('places wrapped badges clear of UI battlers and one another for same-name participants', function () {
  $battlers = $bounds = [];
  foreach ([350, 850] as $x) {
    $actor = new Character('Same name', 1, new Stats(currentHp: 100));
    foreach (Character::buffableStats() as $stat) { $actor->setStatStage($stat, 4); }
    $actor->addState(new State('poison', 'Poison'));
    $actor->addState(new State('stun', 'Stun'));
    $battlers[spl_object_id($actor)] = $actor;
    $bounds[spl_object_id($actor)] = new CanvasRectangle($x, 260, 100, 160);
  }
  $ui = new CanvasTextLayer('opaque-ui', 500, 0, 0, new RendererGridConfig(135, 3, 10, 20),
    array_map(static fn($row) => new PresentationTextRun($row, 0, str_repeat(' ', 135),
      background: PresentationColor::rgb(0, 0, 0)), [0, 1, 2]));
  $occupied = new CanvasRectangle(0, 550, 1350, 170);
  $frame = GraphicalBattleConditions::compose(new BattleCanvasLayout(1350, 720), $battlers, $bounds,
    $this->badgeRoot, [$ui], [$occupied]);
  $boxes = $frame->getOverlayProtection();
  expect($boxes)->toHaveCount(18);
  foreach ($boxes as $index => $box) {
    $box->assertWithin($frame->width, $frame->height);
    foreach ([...array_values($bounds), $ui->bounds, $occupied, ...array_slice($boxes, $index + 1)] as $other) {
      expect($box->x + $box->width <= $other->x || $other->x + $other->width <= $box->x
        || $box->y + $box->height <= $other->y || $other->y + $other->height <= $box->y)->toBeTrue();
    }
  }
});

it('batches a dense concurrent roster within the existing canvas layer budget', function () {
  $battlers = $bounds = [];
  foreach (range(0, 7) as $index) {
    $actor = new Character('Same name', 1, new Stats(currentHp: 100));
    foreach (Character::buffableStats() as $stat) { $actor->setStatStage($stat, $index % 2 === 0 ? 4 : -4); }
    $actor->addState(new State('poison', 'Poison'));
    $actor->addState(new State('stun', 'Stun'));
    $battlers[spl_object_id($actor)] = $actor;
    $bounds[spl_object_id($actor)] = new CanvasRectangle(100 + $index % 4 * 300, 230 + intdiv($index, 4) * 300, 80, 120);
  }
  $frame = GraphicalBattleConditions::compose(new BattleCanvasLayout(1350, 840), $battlers, $bounds, $this->badgeRoot);
  expect($frame->getOverlayProtection())->toHaveCount(72)->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  $labels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers));
  expect(count(array_filter($labels, static fn($label) => $label === '+4')))->toBe(28)
    ->and(count(array_filter($labels, static fn($label) => $label === '-4')))->toBe(28);
});

it('uses theme palette and geometry without changing condition identity', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->setStatStage('attack', 1);
  $entries = BattlerConditions::getEntries($actor);
  $mono = composeConditionBadgeFixture($actor, $this->badgeRoot, createConditionBadgeSkin($this->badgeRoot));
  $color = composeConditionBadgeFixture($actor, $this->badgeRoot,
    createConditionBadgeSkin($this->badgeRoot, style: new BattleConditionBadgeStyle(3, 5, 2), monochrome: false));
  expect($mono->getOverlayProtection()[0]->width)->toBe(32.0)
    ->and($color->getOverlayProtection()[0]->width)->toBe(48.0)
    ->and($mono->textLayers[0]->runs[0]->background)->not->toBe($color->textLayers[0]->runs[0]->background)
    ->and(BattlerConditions::getEntries($actor))->toBe($entries);
});

it('keeps persistent badges through animation gaps failed bindings and reduced motion in the actual battle frame', function (bool $reduced) {
  foreach (['arena.png', 'actor.png'] as $asset) { writeTestPng($this->badgeRoot . '/' . $asset, 16, 16); }
  $party = new Party();
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $reserve = new Character('Reserve', 1, new Stats(currentHp: 100));
  $party->addMember($actor);
  $party->addMember(new Character('Second', 1, new Stats(currentHp: 100)));
  $party->addMember(new Character('Third', 1, new Stats(currentHp: 100)));
  $party->addMember($reserve);
  $actor->addState(new State('poison', 'Poison'));
  $actor->setStatStage('attack', 2);
  $reserve->setStatStage('speed', 3);
  $battle = new BattleConfig($party, new Troop('Empty', []), settings: ['reservePolicy' => 'replace_after_wipeout']);
  $catalog = new BattlePresentationCatalog(['test' => new BattleArenaDefinition('Test',
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)))],
    array_fill_keys(['Actor', 'Second', 'Third', 'Reserve'], new BattlerArtwork('actor.png', 16, 16, 8, 16)), [],
    ui: new BattleCanvasLayout(1350, 720, partySlots: [new BattlerSlot(900, 240, 100, 100),
      new BattlerSlot(1000, 400, 100, 100), new BattlerSlot(1100, 560, 100, 100)]), defaultArena: 'test');
  mkdir($this->badgeRoot . '/Animations/loop', 0777, true);
  file_put_contents($this->badgeRoot . '/Animations/loop/loop.timeline.php', '<?php return ' . var_export([
    'fps' => 10, 'lengthFrames' => 3, 'restFrame' => 0, 'playback' => 'loop', 'tracks' => [[
      'id' => 'gap', 'type' => 'glyph', 'anchor' => 'target',
      'keyframes' => [['frame' => 0, 'content' => 'a'], ['frame' => 2, 'content' => 'b']]]]], true) . ';');
  foreach (['loop', 'missing'] as $effect) {
    ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['ui' => ['battle' => ['conditions' => ['states' => ['poison' => $effect]]]]]));
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->badgeRoot);
    $before = serialize($battle);
    $frame = $presentation->frame(new BattlePresentationSnapshot(poseElapsedSeconds: .1), now: 1, reducedMotion: $reduced,
      imageFlips: false, compositing: false);
    $labels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers));
    expect($labels)->toContain('PSN', '+2')->not->toContain('+3', 'KO')
      ->and(serialize($battle))->toBe($before);
  }
  foreach ($battle->partyRoster->battlers as $outgoing) { $outgoing->stats->currentHp = 0; }
  expect($battle->partyRoster->promoteReservesAfterWipeout())->toBeTrue();
  $afterPromotion = $presentation->frame(now: 1, reducedMotion: $reduced);
  $labels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $afterPromotion->textLayers));
  expect($labels)->toContain('+3')->not->toContain('PSN', '+2', 'KO');
})->with([false, true]);

it('loads badge geometry and semantic icons through the real project catalogue rather than constructor-only setup', function (int $pixelSize) {
  $path = $this->badgeRoot . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($path), 0777, true);
  $data = '<?php return new \\Ichiloto\\Engine\\Battle\\Presentation\\BattlePresentationCatalog([], [], [], ui: '
    . 'new \\Ichiloto\\Engine\\Battle\\Presentation\\BattleCanvasLayout(1350, 720, skin: '
    . 'new \\Ichiloto\\Engine\\Battle\\Presentation\\BattleUiSkin('
    . 'array_fill_keys(' . var_export(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'], true) . ', '
    . 'new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasNineSlice("skin.png", '
    . 'new \\Ichiloto\\Engine\\Rendering\\Presentation\\SpriteSourceRect(0, 0, 1, 1))), '
    . 'array_fill_keys(' . var_export(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'], true) . ', '
    . '\\Ichiloto\\Engine\\Rendering\\Presentation\\PresentationColor::rgb(210, 210, 210)), '
    . 'icons: new \\Ichiloto\\Engine\\UI\\Presentation\\MenuIconRegistry(' . var_export($this->badgeRoot, true)
    . ', ["status.stat.attack.positive" => "loaded.png"]), '
    . ($pixelSize === 2 ? '' : 'conditionBadges: new \\Ichiloto\\Engine\\Battle\\Presentation\\BattleConditionBadgeStyle(' . $pixelSize . ', 6, 2), ')
    . '), feedbackArea: new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasRectangle(0, 80, 1350, 452)));';
  file_put_contents($path, $data);
  writeTestPng($this->badgeRoot . '/loaded.png', 32, 32);
  $catalog = BattlePresentationCatalog::load($this->badgeRoot);
  expect($catalog->ui->skin->conditionBadges->pixelSize)->toBe($pixelSize)
    ->and($catalog->ui->skin->conditionBadges->size)->toBe(16 * $pixelSize);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->setStatStage('attack', 4);
  $frame = GraphicalBattleConditions::compose($catalog->ui, [spl_object_id($actor) => $actor],
    [spl_object_id($actor) => new CanvasRectangle(500, 200, 75, 150)], $this->badgeRoot);
  expect($frame->getOverlayProtection()[0]->width)->toBe((float)(16 * $pixelSize))
    ->and($frame->images[0]->destination->width)->toBe((float)(16 * $pixelSize))
    ->and($frame->images[0]->destination->height)->toBe((float)(16 * $pixelSize))
    ->and($frame->images[0]->asset)->toBe('loaded.png');
  $label = array_find($frame->textLayers, static fn($layer) => str_ends_with($layer->id, '-label'));
  expect($label->runs[0]->text)->toBe('+4')->and($label->grid->cellWidth)->toBeGreaterThanOrEqual(6)
    ->and($label->grid->cellHeight)->toBeGreaterThanOrEqual(12)->and($label->runs[0]->background)->not->toBeNull();
})->with([2, 3, 4]);

it('leaves complete semantic state artwork free of duplicate frame direction and text', function () {
  writeTestPng($this->badgeRoot . '/state.png', 32, 32);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100));
  $actor->addState(new State('poison', 'Poison'));
  $skin = createConditionBadgeSkin($this->badgeRoot, ['status.state.poison' => 'state.png']);
  $frame = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin);
  expect($frame->images[0]->destination->width)->toBe(32.0)
    ->and($frame->images[0]->destination->height)->toBe(32.0)->and($frame->textLayers)->toBeEmpty();
  unlink($this->badgeRoot . '/state.png');
  $fallback = composeConditionBadgeFixture($actor, $this->badgeRoot, $skin);
  expect($fallback->images)->toBeEmpty()
    ->and(array_find($fallback->textLayers, static fn($layer) => str_ends_with($layer->id, '-label'))->runs[0]->text)->toBe('PSN')
    ->and(file_get_contents($this->badgeRoot . '/logs/warning.log'))->toContain('state.png');
});

it('refuses invalid badge style geometry instead of silently dropping conditions', function (int $pixelSize, int $gap, int $maxColumns) {
  expect(fn() => new BattleConditionBadgeStyle($pixelSize, $gap, $maxColumns))->toThrow(InvalidArgumentException::class);
})->with([[1, 4, 4], [9, 4, 4], [4, -1, 4], [4, 33, 4], [4, 4, 0], [4, 4, 9]]);
