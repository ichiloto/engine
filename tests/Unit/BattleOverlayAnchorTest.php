<?php

use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattleConditionBadgeStyle;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackPlacement;
use Ichiloto\Engine\Battle\Presentation\BattleHeadAttachmentLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Battle\Presentation\BattleTargetCursor;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

beforeEach(function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Drawn bodies are read with optional GD PNG support.'); }
  $this->anchorStatics = [];
  foreach ([Debug::class, ConfigStore::class] as $class) {
    $this->anchorStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->anchorRoot = createTestDirectory('overlay-anchors');
  Debug::configure(['log_directory' => $this->anchorRoot . '/logs']);
  // Canvas padding differs per pose, as it does in production art: the figure is never the whole image.
  writeOverlayAnchorPng($this->anchorRoot . '/idle.png', 100, 100, [30, 20, 69, 94]);
  writeOverlayAnchorPng($this->anchorRoot . '/afflicted.png', 100, 100, [25, 55, 74, 94]);
  writeOverlayAnchorPng($this->anchorRoot . '/knockout.png', 100, 100, [5, 78, 94, 94]);
  writeOverlayAnchorPng($this->anchorRoot . '/attack.png', 100, 100, [10, 35, 89, 94]);
  writeOverlayAnchorPng($this->anchorRoot . '/foe.png', 120, 80, [10, 30, 109, 79]);
  foreach (['arena.png', 'skin.png', 'cursor.png', 'poison.png'] as $asset) {
    writeOverlayAnchorPng($this->anchorRoot . '/' . $asset, 32, 32, [0, 0, 31, 31]);
  }
});

afterEach(function () {
  foreach ($this->anchorStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

/** @param array{int, int, int, int} $opaque Left, top, right and bottom of the figure inside a transparent canvas. */
function writeOverlayAnchorPng(string $path, int $width, int $height, array $opaque): void
{
  $image = imagecreatetruecolor($width, $height);
  imagealphablending($image, false);
  imagesavealpha($image, true);
  imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
  imagefilledrectangle($image, ...[...$opaque, imagecolorallocatealpha($image, 120, 90, 60, 0)]);
  imagepng($image, $path);
}

/** @return array{BattleConfig, BattlePresentationCatalog, list<Character>, list<Enemy>} */
function createOverlayAnchorBattle(string $root, array $size, string $formation): array
{
  [$width, $height, $cellWidth, $cellHeight] = $size;
  $ratio = $width / 1350;
  $slot = static fn(float $x, float $depth, float $w, float $h, float $scale = 1.0): BattleStageSlot =>
    new BattleStageSlot($x * $ratio, $depth * $ratio, $w * $ratio, $h * $ratio, $scale);
  $partySlots = match ($formation) {
    // A front member stands in the column of the one behind it, its head near that one's feet.
    'depth column' => [$slot(969, 190, 143, 181), $slot(1137, 95, 137, 179), $slot(969, 0, 151, 183, 1.05)],
    'line' => [$slot(880, 60, 140, 180), $slot(1040, 60, 140, 180), $slot(1200, 60, 140, 180)],
    'diagonal' => [$slot(940, 200, 140, 180), $slot(1060, 100, 140, 180), $slot(1180, 0, 140, 180)],
  };
  $party = new Party();
  $members = [];
  foreach (['Ash', 'Bryn', 'Cato'] as $name) {
    $members[] = $member = new Character($name, 1, new Stats(currentHp: 100, totalHp: 100));
    $party->addMember($member);
  }
  $enemies = [];
  foreach ([0, 1] as $index) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    foreach (['name' => 'Foe', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
      'position' => new Vector2($index * 10 + 1, 1), 'image' => ['x'], 'imagePath' => '',
      'rewards' => new \Ichiloto\Engine\Battle\BattleRewards(0, 0, [])] as $key => $value) {
      new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
    }
    $enemies[] = $enemy;
  }
  $battle = new BattleConfig($party, new Troop('Foes', $enemies, graphicalFormation:
    [$slot(300, 140, 170, 120), $slot(520, 20, 170, 120)]), entryExecutionId: 'overlay-anchors');
  $nine = new CanvasNineSlice('skin.png', new SpriteSourceRect(0, 0, 32, 32));
  $skin = new BattleUiSkin(array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'], $nine),
    array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'], PresentationColor::rgb(200, 200, 200)),
    new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'], new CanvasNineSlice('cursor.png',
      new SpriteSourceRect(0, 0, 32, 32)))),
    new MenuIconRegistry($root, ['status.state.poison' => 'poison.png']));
  // Feedback may use the whole field between the top banner and the HUD, where every head stands.
  $hudTop = new BattleCanvasLayout($width, $height, $cellWidth, $cellHeight)->getHudTop();
  $layout = new BattleCanvasLayout($width, $height, $cellWidth, $cellHeight, $skin,
    feedbackArea: new CanvasRectangle(0, 4 * $cellHeight, $width, $hudTop - 4 * $cellHeight), partySlots: $partySlots);
  $poses = new BattlePoseSet(['idle' => new BattlerPose('idle.png', pivotY: .95),
    'afflicted' => new BattlerPose('afflicted.png', pivotY: .95), 'knockout' => new BattlerPose('knockout.png', pivotY: .95),
    'attack' => new BattlerPose('attack.png', pivotY: .95)]);
  $art = new BattlerArtwork('idle.png', 100, 100, 50, 95);
  $catalog = new BattlePresentationCatalog(['arena.test' => new BattleArenaDefinition('Test',
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, $width, $height)))],
    array_fill_keys(['Ash', 'Bryn', 'Cato'], $art), ['Foe' => new BattlerArtwork('foe.png', 120, 80, 60, 79)],
    ui: $layout, defaultArena: 'arena.test', actorPoses: array_fill_keys(['Ash', 'Bryn', 'Cato'], $poses));
  return [$battle, $catalog, $members, $enemies];
}

function poisonOverlayAnchorBattler(object $battler): void
{
  $battler->addState(new State('poison', 'Poison', label: 'PSN', disposition: StateDisposition::HARMFUL));
}

/** The body as the battler's own image draws it in this frame, read independently of the presenter. */
function getOverlayAnchorBody(PresentationCanvas $frame, object $battler, string $root): CanvasRectangle
{
  $image = array_find($frame->images, static fn($image): bool => $image->id === 'combatant-' . spl_object_id($battler));
  expect($image)->not->toBeNull();
  $body = PngAssetBounds::getVisibleBounds($root, $image)['bounds'];
  expect($body)->not->toBeNull();
  return $body;
}

function getOverlayAnchorGap(CanvasRectangle $one, CanvasRectangle $two): float
{
  return max(0, $one->x - $two->x - $two->width, $two->x - $one->x - $one->width)
    + max(0, $one->y - $two->y - $two->height, $two->y - $one->y - $one->height);
}

/** Which battler's head a point is nearest to. */
function findOverlayAnchorOwner(array $bodies, float $x, float $y): int
{
  $distances = array_map(static fn(CanvasRectangle $body): float =>
    hypot($body->x + $body->width / 2 - $x, $body->y - $y), $bodies);
  return array_search(min($distances), $distances, true);
}

function assertOverlaysOnDrawnBodies(PresentationCanvas $frame, array $battlers, array $poisoned, object $target,
  string $root, BattleCanvasLayout $layout): void
{
  $style = $layout->skin->conditionBadges;
  $cursorStyle = $layout->skin->targetCursor;
  $bodies = [];
  foreach ($battlers as $battler) { $bodies[spl_object_id($battler)] = getOverlayAnchorBody($frame, $battler, $root); }
  // The cursor stands over the target's drawn head, never over its canvas padding.
  $cursors = array_values(array_filter($frame->images, static fn($image): bool => str_starts_with($image->id, 'target-cursor-')));
  expect($cursors)->toHaveCount(1)->and($cursors[0]->id)->toStartWith('target-cursor-' . spl_object_id($target) . '-');
  $body = $bodies[spl_object_id($target)];
  $cursor = $cursors[0]->destination;
  // Selection motion bobs the cursor up within its envelope, never off the head.
  expect($cursor->x + $cursor->width / 2)->toEqualWithDelta($body->x + $body->width / 2, .000001)
    ->and($cursor->y + $cursor->height)->toBeLessThanOrEqual($body->y - $cursorStyle->gap + .000001)
    ->and($cursor->y + $cursor->height)->toBeGreaterThanOrEqual($body->y - $cursorStyle->gap - 4.000001);
  // Each status icon is drawn for its own battler, at that battler's head and nobody else's.
  $badges = array_values(array_filter($frame->images, static fn($image): bool => str_starts_with($image->id, 'status-badge-')));
  expect($badges)->toHaveCount(count($poisoned));
  foreach ($poisoned as $battler) {
    $identity = spl_object_id($battler);
    $badge = array_find($badges, static fn($image): bool => str_starts_with($image->id, 'status-badge-' . $identity . '-'));
    expect($badge)->not->toBeNull();
    $box = $badge->destination;
    $head = $bodies[$identity];
    $middle = $head->x + $head->width / 2;
    expect($box->y + $style->height)->toBeLessThanOrEqual($head->y)
      ->and($box->y + $style->height)->toBeGreaterThanOrEqual($head->y - $style->headGap - 2 * ($style->height + $style->gap))
      ->and(abs($box->x + $box->width / 2 - $middle))
      ->toBeLessThanOrEqual($cursorStyle->size / 2 + $style->headGap + $style->maxColumns * ($style->size + $style->gap))
      ->and(findOverlayAnchorOwner($bodies, $box->x + $box->width / 2, $box->y + $box->height))->toBe($identity);
  }
  // The target's name sits beside its drawn body, nearer its head than any other battler's.
  $label = array_find($frame->textLayers, static fn($layer): bool => str_starts_with($layer->id, 'label-' . spl_object_id($target) . '-'));
  expect($label)->not->toBeNull()->and($label->runs[0]->text)->toBe($target->name);
  $paint = $label->paintBounds;
  expect(getOverlayAnchorGap($paint, $body))->toBeLessThanOrEqual(12.0)
    ->and(findOverlayAnchorOwner($bodies, $paint->x + $paint->width / 2, $paint->y + $paint->height / 2))
    ->toBe(spl_object_id($target));
}

dataset('overlay anchor canvases', [
  '1350x720' => [[1350, 720, 10, 20]],
  '1080x576' => [[1080, 576, 8, 16]],
  '1920x1080' => [[1920, 1080, 14, 30]],
]);

it('anchors cursor, status icons and the target name to the drawn bodies of party and enemies', function (
  array $size, string $formation, bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $members, $enemies] = createOverlayAnchorBattle($this->anchorRoot, $size, $formation);
  // The front member is poisoned, so it takes its padded afflicted pose, as does the first enemy.
  poisonOverlayAnchorBattler($members[2]);
  poisonOverlayAnchorBattler($enemies[0]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->anchorRoot);
  $battlers = [...$members, ...$enemies];
  foreach ($battlers as $target) {
    $frame = $presentation->frame(new BattlePresentationSnapshot(selected: [$target], focused: [$target]),
      focus: 'target', now: 1, reducedMotion: $reduced);
    assertOverlaysOnDrawnBodies($frame, $battlers, [$members[2], $enemies[0]], $target, $this->anchorRoot, $catalog->ui);
  }
})->with('overlay anchor canvases')->with(['depth column', 'line', 'diagonal'])->with([false, true]);

it('moves status icons with their battler through command steps and pose changes', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $members] = createOverlayAnchorBattle($this->anchorRoot, [1350, 720, 10, 20], 'depth column');
  $actor = $members[2];
  poisonOverlayAnchorBattler($actor);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->anchorRoot);
  $getAttachment = function (PresentationCanvas $frame) use ($actor): array {
    $body = getOverlayAnchorBody($frame, $actor, $this->anchorRoot);
    $badge = array_find($frame->images, static fn($image): bool => str_starts_with($image->id,
      'status-badge-' . spl_object_id($actor) . '-'))->destination;
    return ['body' => $body, 'offset' => [$badge->x - $body->x - $body->width / 2, $badge->y - $body->y]];
  };
  $resting = $getAttachment($presentation->frame(now: 1, reducedMotion: $reduced));
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.2, .3, .3, .3, .3, .3, .3)),
    $actor, [], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $moved = false;
  foreach ([.1, .15, .2, .3, .3] as $delta) {
    $playback->update($delta);
    $step = $getAttachment($presentation->frame(new BattlePresentationSnapshot($playback), now: 1, reducedMotion: $reduced));
    // The attack pose has a different figure in its canvas, and the step carries it forward: the icon goes with it.
    $moved = $moved || $step['body']->toArray() !== $resting['body']->toArray();
    expect($step['offset'][0])->toEqualWithDelta($resting['offset'][0], .000001)
      ->and($step['offset'][1])->toEqualWithDelta($resting['offset'][1], .000001);
  }
  expect($moved)->toBeTrue();
})->with([false, true]);

it('points at a knocked-out ally lying in its knockout pose, not at the empty canvas above it', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $members] = createOverlayAnchorBattle($this->anchorRoot, [1350, 720, 10, 20], 'depth column');
  $members[1]->stats->currentHp = 0;
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->anchorRoot);
  $frame = $presentation->frame(new BattlePresentationSnapshot(selected: [$members[1]], focused: [$members[1]]),
    focus: 'target', now: 1, reducedMotion: $reduced);
  $image = array_find($frame->images, static fn($image): bool => $image->id === 'combatant-' . spl_object_id($members[1]));
  $body = getOverlayAnchorBody($frame, $members[1], $this->anchorRoot);
  $cursor = array_find($frame->images, static fn($image): bool => str_starts_with($image->id, 'target-cursor-'))->destination;
  // The lying figure fills only the bottom of its canvas; the cursor stands just over it.
  expect($image->asset)->toBe('knockout.png')->and($body->y)->toBeGreaterThan($image->destination->y + $image->destination->height / 2)
    ->and($cursor->y + $cursor->height)->toBeLessThanOrEqual($body->y - $catalog->ui->skin->targetCursor->gap + .000001)
    ->and($cursor->y + $cursor->height)->toBeGreaterThanOrEqual($body->y - $catalog->ui->skin->targetCursor->gap - 4.000001);
})->with([false, true]);

it('keeps a front battler\'s icon at its own head when the battler behind it stands over that place', function () {
  $style = new BattleConditionBadgeStyle();
  $cursor = new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'],
    new CanvasNineSlice('unused-layout-only.png', new SpriteSourceRect(0, 0, 1, 1))));
  $behind = new Character('Behind', 1, new Stats(currentHp: 100));
  $front = new Character('Front', 1, new Stats(currentHp: 100));
  poisonOverlayAnchorBattler($front);
  // The front battler's head is just below the feet of the one behind, in the same column.
  $bodies = [spl_object_id($behind) => new CanvasRectangle(900, 230, 120, 160),
    spl_object_id($front) => new CanvasRectangle(915, 400, 90, 160)];
  $placed = new BattleHeadAttachmentLayout()->place([spl_object_id($behind) => $behind, spl_object_id($front) => $front],
    $bodies, 1350, 720, $style, $cursor);
  $box = $placed[spl_object_id($front)][0]['bounds'];
  expect($box->x)->toEqual(915 + 45 + $cursor->size / 2 + $style->headGap)
    ->and($box->y)->toEqual(400 - $style->height - $style->headGap);
});

it('returns a displaced icon to its home beside the head once the obstruction leaves', function () {
  $style = new BattleConditionBadgeStyle();
  $cursor = new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'],
    new CanvasNineSlice('unused-layout-only.png', new SpriteSourceRect(0, 0, 1, 1))));
  $owner = new BattleHeadAttachmentLayout();
  $poisoned = new Character('Poisoned', 1, new Stats(currentHp: 100));
  $neighbour = new Character('Neighbour', 1, new Stats(currentHp: 100));
  poisonOverlayAnchorBattler($poisoned);
  $battlers = [spl_object_id($poisoned) => $poisoned, spl_object_id($neighbour) => $neighbour];
  $home = $owner->place($battlers, [spl_object_id($poisoned) => new CanvasRectangle(600, 300, 80, 150),
    spl_object_id($neighbour) => new CanvasRectangle(1000, 300, 80, 150)], 1350, 720, $style, $cursor)[spl_object_id($poisoned)][0]['bounds'];
  // The neighbour steps in so its left cursor slot covers the home cell for a moment.
  $stepped = $owner->place($battlers, [spl_object_id($poisoned) => new CanvasRectangle(600, 300, 80, 150),
    spl_object_id($neighbour) => new CanvasRectangle($home->x + $home->width + $cursor->gap + 2, $home->y - 40, 80, 150)],
    1350, 720, $style, $cursor)[spl_object_id($poisoned)][0]['bounds'];
  $back = $owner->place($battlers, [spl_object_id($poisoned) => new CanvasRectangle(600, 300, 80, 150),
    spl_object_id($neighbour) => new CanvasRectangle(1000, 300, 80, 150)], 1350, 720, $style, $cursor)[spl_object_id($poisoned)][0]['bounds'];
  expect($stepped->toArray())->not->toBe($home->toArray())
    ->and(getOverlayAnchorGap($stepped, new CanvasRectangle(600, 300, 80, 150)))->toBeLessThanOrEqual(2.0 * ($style->height + $style->gap))
    ->and($back->toArray())->toBe($home->toArray());
});

it('keeps a name beside its battler over a neighbour rather than carrying it away', function () {
  $battler = new CanvasRectangle(600, 300, 80, 150);
  $neighbours = [new CanvasRectangle(400, 200, 195, 400), new CanvasRectangle(685, 200, 200, 400)];
  // The cursor covers the head; both sides are another battler's body.
  $cursor = new CanvasRectangle(620, 250, 40, 50);
  $placed = BattleFeedbackPlacement::moving($battler, 60, 24, new CanvasRectangle(0, 80, 1350, 452), [], [$cursor], 0,
    $neighbours);
  $box = $placed['bounds'];
  expect($placed['overlap'])->toBe(0.0)->and(getOverlayAnchorGap($box, $battler))->toBeLessThanOrEqual(4.0)
    ->and($box->x + $box->width <= $cursor->x || $cursor->x + $cursor->width <= $box->x
      || $box->y + $box->height <= $cursor->y || $cursor->y + $cursor->height <= $box->y)->toBeTrue();
});
