<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleFormationClearance;
use Ichiloto\Engine\Battle\Presentation\BattleTargetCursor;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional visible-pixel diagnostics require GD.'); }
  $this->root = sys_get_temp_dir() . '/ichiloto-clearance-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  \Tests\Support\Rendering\writeTestPng($this->root . '/image.png', 10, 10);
  $textures = [];
  foreach (['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'] as $role) {
    $textures[$role] = new CanvasNineSlice('image.png', new SpriteSourceRect(0, 0, 10, 10));
  }
  $colors = [];
  foreach (['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'] as $role) {
    $colors[$role] = PresentationColor::rgb(200, 200, 200);
  }
  $cursor = new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'], $textures['target']));
  $this->skin = new BattleUiSkin($textures, $colors, targetCursor: $cursor);
  $this->layout = new BattleCanvasLayout(1350, 720, skin: $this->skin,
    feedbackArea: new CanvasRectangle(0, 40, 1350, 600),
    battlerArea: new CanvasRectangle(20, 80, 1310, 420), enemyArea: new CanvasRectangle(20, 80, 580, 420),
    partyArea: new CanvasRectangle(700, 80, 600, 420));
});

afterEach(function () {
  if (!isset($this->root)) { return; }
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

it('diagnoses field clearance, side clearance and both overlapping members without changing placement', function () {
  $party = new CanvasImage('party', 'image.png', new CanvasRectangle(900, 100, 100, 100));
  $enemy = new CanvasImage('enemy', 'image.png', new CanvasRectangle(550, 60, 100, 100));
  $other = new CanvasImage('other', 'image.png', new CanvasRectangle(590, 100, 100, 100));
  $before = serialize([$party, $enemy, $other, $this->layout]);
  $issues = BattleFormationClearance::inspect($this->layout, [$party], [$enemy, $other], $this->root);
  expect($issues['party'])->toBeEmpty()->and(implode(' ', $issues['enemy']))->toContain('battler area', 'enemy area', 'overlaps battler other')
    ->and(implode(' ', $issues['other']))->toContain('enemy area', 'overlaps battler enemy')
    ->and(serialize([$party, $enemy, $other, $this->layout]))->toBe($before);
});

it('does not infer an enemy half or reuse a feedback area as a battler restriction', function () {
  $layout = new BattleCanvasLayout(1350, 720, feedbackArea: new CanvasRectangle(0, 0, 10, 10));
  $enemy = new CanvasImage('enemy', 'image.png', new CanvasRectangle(900, 100, 100, 100));
  expect(BattleFormationClearance::inspect($layout, [], [$enemy], $this->root)['enemy'])->toBeEmpty();
});

it('keeps diagnostic keys opaque while naming both overlapping members with their supplied labels', function () {
  $images = [new CanvasImage('opaque-a', 'image.png', new CanvasRectangle(200, 100, 100, 100)),
    new CanvasImage('opaque-b', 'image.png', new CanvasRectangle(220, 100, 100, 100))];
  $labels = ['opaque-a' => BattleFormationClearance::getBattlerLabel('Same creature', false, 0),
    'opaque-b' => BattleFormationClearance::getBattlerLabel('Same creature', false, 1)];
  $before = serialize($images);
  $issues = BattleFormationClearance::inspect(new BattleCanvasLayout(1350, 720), [], $images, $this->root, $labels);
  expect($issues)->toBe([
    'opaque-a' => ['Visible artwork overlaps battler Same creature (enemy member 2).'],
    'opaque-b' => ['Visible artwork overlaps battler Same creature (enemy member 1).'],
  ])->and(serialize($images))->toBe($before)
    ->and(BattleFormationClearance::getBattlerLabel('Hero', true, 2))->toBe('Hero (party slot 3)');
});

it('checks either authored party side rather than assuming it is the right half', function (bool $left) {
  $area = new CanvasRectangle($left ? 100 : 900, 80, 250, 420);
  $layout = new BattleCanvasLayout(1350, 720, partyArea: $area);
  $inside = new CanvasImage('inside', 'image.png', new CanvasRectangle($area->x + 50, 100, 100, 100));
  $outside = new CanvasImage('outside', 'image.png', new CanvasRectangle($left ? 900 : 100, 100, 100, 100));
  $before = serialize([$inside, $outside, $layout]);
  $issues = BattleFormationClearance::inspect($layout, [$inside, $outside], [], $this->root);
  expect($issues['inside'])->toBeEmpty()->and(implode(' ', $issues['outside']))->toContain('authored party area')
    ->and(serialize([$inside, $outside, $layout]))->toBe($before);
})->with([false, true]);

it('does not infer a party restriction from the enemy area', function () {
  $layout = new BattleCanvasLayout(1350, 720, enemyArea: new CanvasRectangle(0, 80, 600, 420));
  $party = new CanvasImage('party', 'image.png', new CanvasRectangle(100, 100, 100, 100));
  expect(BattleFormationClearance::inspect($layout, [$party], [], $this->root)['party'])->toBeEmpty();
});

it('requires party cursors to clear both authored side and field regions', function () {
  $layout = new BattleCanvasLayout(1350, 720, skin: $this->skin,
    feedbackArea: $this->layout->feedbackArea, battlerArea: $this->layout->battlerArea,
    partyArea: new CanvasRectangle(900, 200, 100, 100));
  $party = new CanvasImage('party', 'image.png', new CanvasRectangle(900, 200, 100, 100));
  expect(implode(' ', BattleFormationClearance::inspect($layout, [$party], [], $this->root)['party']))
    ->toContain('No target cursor placement');
});

it('refuses side regions outside the canvas before composition', function () {
  expect(fn() => new BattleCanvasLayout(1350, 720, partyArea: new CanvasRectangle(1340, 80, 100, 100)))
    ->toThrow(InvalidArgumentException::class);
});

it('checks cursor clearance against the explicit field and other visible members', function () {
  $enemy = new CanvasImage('enemy', 'image.png', new CanvasRectangle(20, 80, 580, 420));
  $issues = BattleFormationClearance::inspect($this->layout, [], [$enemy], $this->root);
  expect(implode(' ', $issues['enemy']))->toContain('No target cursor placement');
});

it('accepts edge contact and retains regions when an arena replaces the skin', function () {
  $one = new CanvasImage('one', 'image.png', new CanvasRectangle(100, 150, 100, 100));
  $two = new CanvasImage('two', 'image.png', new CanvasRectangle(200, 150, 100, 100));
  expect(BattleFormationClearance::inspect($this->layout, [], [$one, $two], $this->root))->toBe(['one' => [], 'two' => []]);
  $arena = new BattleArenaDefinition('Alternate', new CanvasImage('arena', 'image.png', new CanvasRectangle(0, 0, 1350, 720)),
    skin: clone $this->skin);
  $layout = $this->layout->getForArena($arena);
  expect($layout->battlerArea)->toBe($this->layout->battlerArea)->and($layout->enemyArea)->toBe($this->layout->enemyArea)
    ->and($layout->partyArea)->toBe($this->layout->partyArea);
});
