<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleScale;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->statics = [];
  foreach ([Debug::class, ConfigStore::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-formation-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root . '/logs']);
  foreach (['arena' => [240, 120], 'hero' => [100, 200], 'idle' => [200, 200], 'enemy' => [160, 100]] as $id => $size) {
    \Tests\Support\Rendering\writeTestPng($this->root . '/' . $id . '.png', ...$size);
  }
  $this->slot = new BattlerSlot(300, 350, 200, 230);
  $this->layout = new BattleCanvasLayout(1350, 720,
    partySlots: [new BattlerSlot(1000, 300, 200, 230), new BattlerSlot(1150, 500, 200, 230)]);
  $this->arena = new BattleArenaDefinition('A synthetic scene',
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)));
  $this->art = new BattlerArtwork('hero.png', 100, 200, 50, 190);
  $this->enemyArt = new BattlerArtwork('enemy.png', 160, 100, 80, 90);
  $this->poses = new BattlePoseSet(['idle' => new BattlerPose('idle.png', columns: 2,
    frames: [1, 0], restFrame: 0, pivotX: .25, pivotY: .9)]);
  $this->scale = new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, .75)],
    ['Creature' => new BattlerScale(.25, .5, horizontal: true)]);
  $this->catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art],
    ['Creature' => $this->enemyArt], ui: $this->layout, actorPoses: ['Hero' => $this->poses],
    defaultArena: 'scene', scale: $this->scale);
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('shares idle placement with battles without constructing a battle for the preview', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  $preview = BattleFormationLayout::compose($this->catalog, 'scene',
    [['enemyId' => 'Creature', 'slot' => $this->slot]], ['Hero'], $this->root, reducedMotion: $reduced);
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100));
  $party->addMember($hero);
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Creature', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new Vector2(1, 1)] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $battle = new BattleConfig($party, new Troop('Synthetic', [$enemy], graphicalFormation: [$this->slot]));
  $frame = GraphicalBattlePresentation::prepare($battle, $this->catalog, $this->root)->frame();
  foreach ([[$preview->party[0], $hero], [$preview->enemies[0], $enemy]] as [$placed, $battler]) {
    $image = array_find($frame->images, static fn($image) => $image->id === 'combatant-' . spl_object_id($battler));
    expect($image->asset)->toBe($placed->image->asset)
      ->and($image->destination)->toEqual($placed->image->destination)
      ->and($image->sourceRect)->toEqual($placed->image->sourceRect)
      ->and($image->flipX)->toBe($placed->image->flipX)
      ->and($placed->ground)->toEqual(new Vector2($placed->slot->x, $placed->slot->y));
  }
  expect($preview->layout)->toBe($this->layout)->and($preview->backgrounds)->toBe([$this->arena->background])
    ->and($preview->arenaChoices)->toBe(['scene' => 'A synthetic scene']);
})->with([false, true]);

it('keeps repeated enemy identities distinct and exposes calibrated body axes independently of slot limits', function () {
  $second = new BattlerSlot(500, 450, 50, 60);
  $preview = BattleFormationLayout::compose($this->catalog, null,
    [['enemyId' => 'Creature', 'slot' => $this->slot], ['enemyId' => 'Creature', 'slot' => $second]], ['Hero'], $this->root);
  expect($preview->enemies)->toHaveCount(2)
    ->and($preview->enemies[0]->image->id)->not->toBe($preview->enemies[1]->image->id)
    ->and($preview->enemies[0]->bounds->width)->toBe($preview->enemies[1]->bounds->width)
    ->and($preview->enemies[0]->bodySpan)->toBe(37.5)->and($preview->enemies[0]->horizontal)->toBeTrue()
    ->and($preview->party[0]->bodySpan)->toBe(150.0)->and($preview->party[0]->horizontal)->toBeFalse();
});

it('names overlapping preview members without renumbering slots whose optional artwork is missing', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional visible-pixel diagnostics require GD.'); }
  $preview = BattleFormationLayout::compose($this->catalog, null, [
    ['enemyId' => 'Missing', 'slot' => new BattlerSlot(100, 100, 200, 230)],
    ['enemyId' => 'Creature', 'slot' => $this->slot],
    ['enemyId' => 'Creature', 'slot' => $this->slot],
  ], ['Hero'], $this->root);
  $before = serialize($preview);
  $issues = $preview->getClearanceDiagnostics($this->root);
  expect($issues['enemies'][1])->toBe(['Visible artwork overlaps battler Creature (enemy member 3).'])
    ->and($issues['enemies'][2])->toBe(['Visible artwork overlaps battler Creature (enemy member 2).'])
    ->and($issues['enemies'][0])->not->toBeEmpty()->and(serialize($preview))->toBe($before);
});

it('logs formation conflicts with live display names and distinct member ordinals rather than image ids', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional visible-pixel diagnostics require GD.'); }
  $layout = new BattleCanvasLayout(1350, 720, partySlots: $this->layout->partySlots,
    battlerArea: new CanvasRectangle(0, 0, 1350, 720));
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art],
    ['Creature' => $this->enemyArt], ui: $layout, actorPoses: ['Hero' => $this->poses],
    defaultArena: 'scene', scale: $this->scale);
  $party = new Party();
  $party->addMember(new Character('Display hero', 1, new Stats(currentHp: 100, totalHp: 100), actorId: 'Hero'));
  $enemies = [];
  for ($index = 0; $index < 2; $index++) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    foreach (['name' => 'Creature', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
      'position' => new Vector2(1, 1), 'imagePath' => 'synthetic.txt',
      'rewards' => new \Ichiloto\Engine\Battle\BattleRewards(0, 0, [])] as $key => $value) {
      new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
    }
    $enemies[] = $enemy;
  }
  $overlap = new BattlerSlot(1000, 300, 200, 230);
  $battle = new BattleConfig($party, new Troop('Synthetic', $enemies, graphicalFormation: [$overlap, $overlap]));
  $before = serialize($battle);
  GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $warnings = file_get_contents($this->root . '/logs/warning.log');
  expect($warnings)->toContain('Battle formation Display hero (party slot 1)',
    'Battle formation Creature (enemy member 1)', 'Battle formation Creature (enemy member 2)',
    'overlaps battler Display hero (party slot 1)', 'overlaps battler Creature (enemy member 2)')
    ->not->toContain('combatant-')->and(serialize($battle))->toBe($before);
});

it('shares slot-owned depth across runtime, previews and every role regardless of which actor occupies it',
  function (bool $scaled, bool $poseOnly, bool $reduced) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
    \Tests\Support\Rendering\writeTestPng($this->root . '/wide.png', 300, 200);
    \Tests\Support\Rendering\writeTestPng($this->root . '/lying.png', 220, 80);
    $roles = [];
    foreach (\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::cases() as $role) {
      $roles[$role->value] = match ($role) {
        \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::IDLE => $this->poses->roles['idle'],
        \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::KNOCKOUT => new BattlerPose('lying.png', pivotY: .75, scaleSpan: 1.875),
        default => new BattlerPose('wide.png', pivotX: .6, pivotY: .9),
      };
    }
    $set = new BattlePoseSet($roles);
    $ids = ['Hero', 'Second', 'Third'];
    $sets = array_fill_keys($ids, $set);
    $layout = new BattleCanvasLayout(1350, 720, partySlots: [
      new BattlerSlot(900, 300, 200, 230), new BattlerSlot(1100, 450, 200, 230),
      new BattlerSlot(900, 550, 200, 230, displayScale: 1.05),
    ]);
    $scale = $scaled ? new BattleScale('Hero', 150, array_fill_keys($ids, new BattlerScale(1, .75))) : null;
    if ($poseOnly) { unlink($this->root . '/hero.png'); }
    $catalog = new BattlePresentationCatalog(['scene' => $this->arena], $poseOnly ? ['Hero' => $this->art] : array_fill_keys($ids, $this->art), [],
      ui: $layout, defaultArena: 'scene', actorPoses: $sets, scale: $scale);
    foreach ([$ids, ['Third', 'Hero', 'Second']] as $order) {
      $party = new Party();
      foreach ($order as $id) { $party->addMember(new Character($id, 1, new Stats(currentHp: 100, totalHp: 100))); }
      $battle = new BattleConfig($party, new Troop('Synthetic', []));
      $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
      $preview = BattleFormationLayout::compose($catalog, null, [], $order, $this->root, reducedMotion: $reduced);
      $before = serialize($battle);
      foreach ($party->members as $index => $actor) {
        $slot = $layout->partySlots[$index];
        expect($preview->party[$index]->ground)->toEqual(new Vector2($slot->x, $slot->y))
          ->and($preview->party[$index]->bodySpan)->toBe($scaled ? 150 * $slot->displayScale : null);
        $idleImage = array_find($presentation->frame()->images,
          static fn($image) => $image->id === 'combatant-' . spl_object_id($actor));
        expect($idleImage->destination)->toEqual($preview->party[$index]->image->destination)
          ->and($idleImage->sourceRect)->toEqual($preview->party[$index]->image->sourceRect);
        foreach (\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::cases() as $role) {
          $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
            new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
            $actor, [], $role, static fn() => throw new RuntimeException('Render inspection must not execute combat.'), static fn() => null);
          new ReflectionProperty($playback, 'phase')->setValue($playback, 'source');
          $field = new class extends \Ichiloto\Engine\Battle\UI\BattleFieldWindow {
            public function __construct() {}
            public function getSelectedBattlers(): array { return []; }
            public function getFocusedBattlers(): array { return []; }
            public function getQueuedBattlers(): array { return []; }
          };
          $field->setCommandPlayback($playback);
          $image = array_find($presentation->frame($field)->images,
            static fn($image) => $image->id === 'combatant-' . spl_object_id($actor));
          $art = $roles[$role->value]->getArtwork($this->root, 0, $reduced);
          $density = ($scaled ? 1.0 : 1.15) * $slot->displayScale;
          expect($image->destination->width / $art->width)->toEqualWithDelta($density, .000001)
            ->and($image->destination->height / $art->height)->toEqualWithDelta($density, .000001)
            ->and($image->destination->x + $art->pivotX * $density)->toEqualWithDelta($slot->x, .000001)
            ->and($image->destination->y + $art->pivotY * $density)->toEqualWithDelta($slot->y, .000001)
            ->and($image->asset)->toBe($art->asset);
        }
      }
      expect(serialize($battle))->toBe($before);
    }
  })->with([[true, false], [true, true], [false, false]])->with([false, true]);

it('shares calibrated pose-only artwork with runtime when the optional base registration is absent', function (bool $party) {
  $profile = new BattlerScale(.5, .75);
  $scale = new BattleScale('Hero', 150,
    ['Hero' => new BattlerScale(1, .75), ...($party ? ['Other' => $profile] : [])],
    ['Creature' => new BattlerScale(.25, .5, horizontal: true), ...($party ? [] : ['Other' => $profile])]);
  $poses = ['Other' => $this->poses];
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art],
    ['Creature' => $this->enemyArt], ui: $this->layout, defaultArena: 'scene', scale: $scale,
    actorPoses: $party ? $poses : [], enemyPoses: $party ? [] : $poses);
  $hero = new Character($party ? 'Other' : 'Hero', 1, new Stats(currentHp: 100, totalHp: 100));
  $members = new Party();
  $members->addMember($hero);
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => $party ? 'Creature' : 'Other', 'level' => 1,
    'stats' => new Stats(currentHp: 100, totalHp: 100), 'position' => new Vector2(1, 1)] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $battle = new BattleConfig($members, new Troop('Synthetic', [$enemy], graphicalFormation: [$this->slot]));
  $preview = BattleFormationLayout::compose($catalog, null,
    [['enemyId' => $enemy->name, 'slot' => $this->slot]], [$hero->actorId], $this->root);
  $placed = $party ? $preview->party[0] : $preview->enemies[0];
  $battler = $party ? $hero : $enemy;
  $canvas = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  $image = array_find($canvas->images, static fn($image) => $image->id === 'combatant-' . spl_object_id($battler));
  expect($image->asset)->toBe('idle.png')->and($placed->image->asset)->toBe($image->asset)
    ->and($image->destination)->toEqual($placed->image->destination)
    ->and($image->sourceRect)->toEqual($placed->image->sourceRect)
    ->and($image->destination->height * $profile->sourceSpan)->toEqualWithDelta(75, .000001)
    ->and($placed->bodySpan)->toBe(75.0)->and($battler->stats->currentHp)->toBe(100);
})->with([false, true]);

it('reads replacement PNG dimensions while preserving the authored ground anchor and body unit', function () {
  $before = BattleFormationLayout::compose($this->catalog, null, [], ['Hero'], $this->root)->party[0];
  \Tests\Support\Rendering\writeTestPng($this->root . '/idle.png', 400, 400);
  $after = BattleFormationLayout::compose($this->catalog, null, [], ['Hero'], $this->root)->party[0];
  expect($after->image->sourceRect->width)->toBe(200)->and($after->image->sourceRect->height)->toBe(400)
    ->and($after->bounds)->toEqual($before->bounds)->and($after->ground)->toEqual($before->ground)
    ->and($after->bodySpan)->toBe($before->bodySpan);
});

it('diagnoses unavailable optional idle art and retains the same base placement as runtime', function (bool $missing) {
  if ($missing) { unlink($this->root . '/idle.png'); }
  else { \Tests\Support\Rendering\writeTestPng($this->root . '/idle.png', 201, 200); }
  $member = BattleFormationLayout::compose($this->catalog, null, [], ['Hero'], $this->root)->party[0];
  expect($member->image->asset)->toBe('hero.png')->and($member->diagnostics)->not->toBeEmpty()
    ->and($member->bounds)->toEqual($this->layout->partySlots[0]->placeAtScale($this->art, 1));
})->with([false, true]);

it('keeps missing-art identities and ground/body metadata without generating substitute artwork', function () {
  unlink($this->root . '/hero.png');
  unlink($this->root . '/idle.png');
  $preview = BattleFormationLayout::compose($this->catalog, null,
    [['enemyId' => 'Unregistered', 'slot' => $this->slot]], ['Hero'], $this->root);
  expect($preview->party[0]->image)->toBeNull()->and($preview->party[0]->bodySpan)->toBe(150.0)
    ->and($preview->enemies[0]->id)->toBe('Unregistered')->and($preview->enemies[0]->image)->toBeNull()
    ->and($preview->enemies[0]->ground)->toEqual(new Vector2(300, 350))
    ->and($preview->enemies[0]->bodySpan)->toBeNull()->and($preview->enemies[0]->diagnostics)->not->toBeEmpty();
});

it('never calibrates absent pixels against a body profile or legacy display width', function (bool $registered, bool $scaled) {
  unlink($this->root . '/enemy.png');
  $slot = new BattlerSlot(30, 40, 100, 60);
  $scale = $scaled ? new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, .75)],
    ['Creature' => new BattlerScale(.6, .01, horizontal: true)]) : null;
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art],
    $registered ? ['Creature' => $this->enemyArt] : [], ui: $this->layout,
    enemyPoses: ['Creature' => new BattlePoseSet([], $scaled ? null : 2000)], defaultArena: 'scene', scale: $scale);
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Creature', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new Vector2(1, 1)] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $battle = new BattleConfig(new Party(), new Troop('Synthetic', [$enemy], graphicalFormation: [$slot]));
  $preview = BattleFormationLayout::compose($catalog, null,
    [['enemyId' => 'Creature', 'slot' => $slot]], [], $this->root)->enemies[0];
  $canvas = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  expect($preview->bounds)->toEqual($slot->placeAtScale(BattleFormationLayout::getUnavailableArtwork(), 1))
    ->and($preview->image)->toBeNull()->and($preview->ground)->toEqual(new Vector2(30, 40))
    ->and($preview->bodySpan)->toBe($scaled ? 90.0 : null)
    ->and(array_filter($canvas->images, static fn($image) => str_starts_with($image->id, 'combatant-')))->toBeEmpty()
    ->and(serialize($canvas->textLayers))->toContain('Creature')->and($enemy->stats->currentHp)->toBe(100);
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('fits uncalibrated pose-only pixels rather than borrowing the name fallback scale', function (?float $width) {
  $poses = new BattlePoseSet($this->poses->roles, $width);
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], [], [], ui: $this->layout,
    actorPoses: ['Hero' => $poses], defaultArena: 'scene');
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100));
  $party->addMember($hero);
  $battle = new BattleConfig($party, new Troop('Synthetic', []));
  $preview = BattleFormationLayout::compose($catalog, null, [], ['Hero'], $this->root)->party[0];
  $canvas = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  $image = array_find($canvas->images, static fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  $idle = $poses->getPose(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::IDLE)->getArtwork($this->root, 0, false);
  expect($preview->bounds)->toEqual($this->layout->partySlots[0]->place($idle, $width))
    ->and($image->destination)->toEqual($preview->bounds)->and($image->asset)->toBe('idle.png');
})->with([null, 80.0]);

it('keeps absent-art names usable at canvas edges without changing the authored anchor', function (int $x, int $y) {
  $slot = new BattlerSlot($x, $y, 100, 60);
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art], [],
    ui: $this->layout, defaultArena: 'scene', scale: $this->scale);
  $preview = BattleFormationLayout::compose($catalog, null,
    [['enemyId' => 'Creature', 'slot' => $slot]], [], $this->root)->enemies[0];
  $preview->bounds->assertWithin($this->layout->width, $this->layout->height);
  expect($preview->bounds->width)->toBe(1.0)->and($preview->bounds->height)->toBe(1.0)
    ->and($preview->ground)->toEqual(new Vector2($x, $y))->and($preview->image)->toBeNull();
})->with([[0, 0], [1350, 0], [0, 720], [1350, 720]]);

it('keeps missing-art targets selectable at canvas edges without attaching a cursor to absent pixels', function (int $x, int $y) {
  $textures = [];
  foreach (['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'] as $role) {
    $textures[$role] = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice('enemy.png',
      new \Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect(0, 0, 10, 10));
  }
  $colors = [];
  foreach (['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'] as $role) {
    $colors[$role] = \Ichiloto\Engine\Rendering\Presentation\PresentationColor::rgb(200, 200, 200);
  }
  $cursor = new \Ichiloto\Engine\Battle\Presentation\BattleTargetCursor(
    array_fill_keys(['above', 'left', 'right'], $textures['target']));
  $skin = new \Ichiloto\Engine\Battle\Presentation\BattleUiSkin($textures, $colors, $cursor);
  $layout = new BattleCanvasLayout(1350, 720, skin: $skin, feedbackArea: new CanvasRectangle(0, 80, 1350, 452));
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art], [],
    ui: $layout, defaultArena: 'scene', scale: $this->scale);
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Creature', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new Vector2(1, 1)] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $field = new class($enemy) extends \Ichiloto\Engine\Battle\UI\BattleFieldWindow {
    public function __construct(private Enemy $target) {}
    public function getSelectedBattlers(): array { return [$this->target]; }
    public function getFocusedBattlers(): array { return [$this->target]; }
    public function getQueuedBattlers(): array { return [$this->target]; }
  };
  $battle = new BattleConfig(new Party(), new Troop('Synthetic', [$enemy],
    graphicalFormation: [new BattlerSlot($x, $y, 100, 60)]));
  $canvas = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame($field, focus: 'target');
  expect(serialize($canvas->textLayers))->toContain('Creature', 'Queued')
    ->and(array_filter($canvas->images, static fn($image) => str_starts_with($image->id, 'target-cursor-')))->toBeEmpty()
    ->and($enemy->stats->currentHp)->toBe(100);
})->with([[0, 0], [1350, 0], [0, 720], [1350, 720]]);

it('can lose and regain registered pixels during a battle without freezing their availability', function () {
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art], [],
    ui: $this->layout, defaultArena: 'scene', scale: $this->scale);
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100));
  $party->addMember($hero);
  $presentation = GraphicalBattlePresentation::prepare(new BattleConfig($party, new Troop('Synthetic', [])),
    $catalog, $this->root);
  $getImage = static fn($canvas) => array_find($canvas->images,
    static fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  $before = $getImage($presentation->frame());
  unlink($this->root . '/hero.png');
  expect($getImage($presentation->frame()))->toBeNull();
  \Tests\Support\Rendering\writeTestPng($this->root . '/hero.png', 100, 200);
  expect($getImage($presentation->frame()))->toEqual($before)->and($hero->stats->currentHp)->toBe(100);
});

it('uses existing contain fit when a legacy registered width cannot fit the canvas', function () {
  $catalog = new BattlePresentationCatalog(['scene' => $this->arena], ['Hero' => $this->art], [],
    ui: $this->layout, actorPoses: ['Hero' => new BattlePoseSet([], 2000)], defaultArena: 'scene');
  $member = BattleFormationLayout::compose($catalog, null, [], ['Hero'], $this->root)->party[0];
  expect($member->bounds)->toEqual($this->layout->partySlots[0]->place($this->art))
    ->and($member->diagnostics)->not->toBeEmpty()->and($member->bodySpan)->toBeNull();
});

it('rejects unknown arenas, invalid formation entries and insufficient party slots', function () {
  expect(fn() => BattleFormationLayout::compose($this->catalog, 'missing', [], [], $this->root))
    ->toThrow(RuntimeException::class)
    ->and(fn() => BattleFormationLayout::compose($this->catalog, null, [], ['Hero', 'Hero', 'Hero'], $this->root))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => BattleFormationLayout::compose($this->catalog, null, [['enemyId' => 'Creature', 'slot' => []]], [], $this->root))
    ->toThrow(InvalidArgumentException::class);
});

it('can preview a plain canvas without choosing or writing an arena into a troop', function () {
  $catalog = new BattlePresentationCatalog([], ['Hero' => $this->art], [], ui: $this->layout);
  $before = array_map('file_get_contents', glob($this->root . '/*.png'));
  $preview = BattleFormationLayout::compose($catalog, null, [], ['Hero'], $this->root);
  expect($preview->arena)->toBeNull()->and($preview->backgrounds)->toBe([])
    ->and($preview->arenaChoices)->toBe([])->and($preview->party[0]->image)->not->toBeNull()
    ->and(array_map('file_get_contents', glob($this->root . '/*.png')))->toBe($before);
});
