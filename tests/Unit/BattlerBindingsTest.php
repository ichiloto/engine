<?php

use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Battle art bound as data. Synthetic identities and images only. */

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-bindings-' . bin2hex(random_bytes(6));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  writeTestPng($this->root . '/Graphics/Hero/Idle.png', 50, 100);
  writeTestPng($this->root . '/Graphics/Wisp/Idle.png', 40, 80);
  writeTestPng($this->root . '/Graphics/Wisp/Attack.png', 160, 80);
  // Code registers the hero and the scale reference, as a project's battle.php does.
  file_put_contents($this->root . '/' . BattlePresentationCatalog::FILE, <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\{BattlePresentationCatalog, BattlerArtwork, BattleScale, BattlerScale};
return new BattlePresentationCatalog([], ['Hero' => BattlerArtwork::getFromPng(dirname(__DIR__, 2), 'Graphics/Hero/Idle.png', .5, .9)], [],
  scale: new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, .75)]));
PHP);
  $this->bind = function (array $data): void {
    file_put_contents($this->root . '/' . BattlerBindings::FILE, '<?php return ' . var_export($data, true) . ';');
  };
  $this->wisp = [
    'artwork' => ['image' => 'Graphics/Wisp/Idle.png', 'pivot' => ['x' => .25, 'y' => 1]],
    'poses' => ['attack' => ['image' => 'Graphics/Wisp/Attack.png', 'columns' => 4, 'frames' => [0, 1, 2, 3], 'fps' => 12, 'loop' => false,
      'pivot' => ['x' => .5, 'y' => .9], 'scaleSpan' => .7]],
    'scale' => ['relativeSize' => .5, 'sourceSpan' => .8, 'horizontal' => true],
  ];
});

afterEach(function () {
  exec('rm -rf ' . escapeshellarg($this->root));
});

it('builds artwork, animated poses and body profiles from data, sized by the images as they are now', function () {
  ($this->bind)(['enemies' => ['Wisp' => $this->wisp]]);
  $bindings = BattlerBindings::load($this->root);
  $attack = $bindings->enemyPoses['Wisp']->getPose(BattlePoseRole::ATTACK);

  expect($bindings->enemies['Wisp'])->toEqual(new BattlerArtwork('Graphics/Wisp/Idle.png', 40, 80, 10, 80))
    ->and($attack->getArtwork($this->root, 0))->toMatchObject(['width' => 40, 'height' => 80, 'pivotX' => 20.0, 'pivotY' => 72.0])
    ->and([$attack->fps, $attack->loop, $attack->scaleSpan])->toBe([12, false, .7])
    ->and($bindings->enemyProfiles['Wisp'])->toEqual(new BattlerScale(.5, .8, true))
    ->and($bindings->getIdentities(false))->toBe(['Wisp'])
    ->and($bindings->getIdentities(true))->toBe([]);

  // Replacing the image needs no edit to the bindings.
  writeTestPng($this->root . '/Graphics/Wisp/Idle.png', 80, 160);
  expect(BattlerBindings::load($this->root)->enemies['Wisp'])->toEqual(new BattlerArtwork('Graphics/Wisp/Idle.png', 80, 160, 20, 160));
});

it('joins the battlers code registers, under the one scale reference', function () {
  ($this->bind)(['enemies' => ['Wisp' => $this->wisp]]);
  $catalog = BattlePresentationCatalog::load($this->root);

  expect(array_keys($catalog->actors))->toBe(['Hero'])
    ->and(array_keys($catalog->enemies))->toBe(['Wisp'])
    ->and(array_keys($catalog->enemyPoses))->toBe(['Wisp'])
    ->and($catalog->scale->referenceActorId)->toBe('Hero')
    ->and($catalog->scale->getProfile('Wisp', false))->toEqual(new BattlerScale(.5, .8, true))
    ->and($catalog->scale->getProfile('Hero', true))->toEqual(new BattlerScale(1, .75));
});

it('loads code ownership separately so unsaved binding edits can be validated without disk mutations', function () {
  ($this->bind)(['enemies' => ['Wisp' => $this->wisp]]);
  $before = file_get_contents($this->root . '/' . BattlerBindings::FILE);
  $base = BattlePresentationCatalog::loadCode($this->root);
  $proposed = $this->wisp;
  $proposed['artwork']['pivot'] = ['x' => .5, 'y' => 1];
  $preview = $base->bindBattlers(BattlerBindings::getFromArray(['enemies' => ['Wisp' => $proposed]], $this->root));
  expect($base->enemies)->toBe([])
    ->and($preview->enemies['Wisp']->pivotX)->toBe(20.0)
    ->and(BattlePresentationCatalog::load($this->root)->enemies['Wisp']->pivotX)->toBe(10.0)
    ->and(file_get_contents($this->root . '/' . BattlerBindings::FILE))->toBe($before);
  expect(fn() => $base->bindBattlers(BattlerBindings::getFromArray([
    'actors' => ['Hero' => ['artwork' => ['image' => 'Graphics/Hero/Idle.png']]],
  ], $this->root)))->toThrow(InvalidArgumentException::class, 'bound both')
    ->and(file_get_contents($this->root . '/' . BattlerBindings::FILE))->toBe($before);
});

it('gives an identity or the scale reference one owner, refusing either when code and data both name it', function () {
  ($this->bind)(['actors' => ['Hero' => ['artwork' => ['image' => 'Graphics/Hero/Idle.png']]]]);
  expect(fn() => BattlePresentationCatalog::load($this->root))
    ->toThrow(InvalidArgumentException::class, 'Hero is bound both in Data/Presentation/battle.php and in Data/Presentation/battlers.php');

  ($this->bind)(['reference' => ['actor' => 'Wisp', 'height' => 150], 'enemies' => ['Wisp' => $this->wisp]]);
  expect(fn() => BattlePresentationCatalog::load($this->root))
    ->toThrow(InvalidArgumentException::class, 'The battle scale reference is named both');
});

it('lets data name the scale reference when code has none, and refuses profiles with nothing to measure against', function () {
  file_put_contents($this->root . '/' . BattlePresentationCatalog::FILE,
    '<?php return new Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog([], [], []);');
  $hero = ['artwork' => ['image' => 'Graphics/Hero/Idle.png'], 'scale' => ['relativeSize' => 1, 'sourceSpan' => .75]];

  ($this->bind)(['reference' => ['actor' => 'Hero', 'height' => 150], 'actors' => ['Hero' => $hero], 'enemies' => ['Wisp' => $this->wisp]]);
  expect(BattlePresentationCatalog::load($this->root)->scale)->toMatchObject(['referenceActorId' => 'Hero', 'referenceHeight' => 150.0]);

  ($this->bind)(['actors' => ['Hero' => $hero]]);
  expect(fn() => BattlePresentationCatalog::load($this->root))->toThrow(InvalidArgumentException::class, 'no scale reference');
});

it('refuses what it cannot read, naming where in the file', function (array $data, string $message) {
  expect(fn() => BattlerBindings::getFromArray($data, $this->root))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'an unknown key' => [['enemies' => ['Wisp' => ['artwork' => ['image' => 'Graphics/Wisp/Idle.png', 'size' => 2]]]], 'enemies.Wisp.artwork: unknown key size'],
  'nothing to draw' => [['enemies' => ['Wisp' => ['scale' => ['relativeSize' => 1, 'sourceSpan' => 1]]]], 'enemies.Wisp: a battler needs artwork, poses or both.'],
  'an unknown role' => [['actors' => ['Hero' => ['poses' => ['dance' => ['image' => 'Graphics/Hero/Idle.png']]]]], 'actors.Hero.poses.dance: a pose role is one of idle'],
  'a pivot off the image' => [['enemies' => ['Wisp' => ['artwork' => ['image' => 'Graphics/Wisp/Idle.png', 'pivot' => ['x' => 1.5, 'y' => 1]]]]], 'enemies.Wisp.artwork.pivot.x: expected a number from 0 to 1'],
  'a path outside the assets' => [['enemies' => ['Wisp' => ['artwork' => ['image' => '../Wisp.png']]]], 'enemies.Wisp: Sprite asset must be a relative'],
  'a frame outside the sheet' => [['enemies' => ['Wisp' => ['poses' => ['idle' => ['image' => 'Graphics/Wisp/Attack.png', 'columns' => 2, 'frames' => [0, 5]]]]]], 'enemies.Wisp: Battle poses require'],
  'a typed count' => [['enemies' => ['Wisp' => ['poses' => ['idle' => ['image' => 'Graphics/Wisp/Attack.png', 'columns' => '4']]]]], 'enemies.Wisp.poses.idle.columns: expected a whole number.'],
  'a reference without a height' => [['reference' => ['actor' => 'Hero']], 'reference.height: expected a finite number.'],
]);

it('is absent until a project binds battlers as data', function () {
  expect(BattlerBindings::load($this->root))->toBeNull()
    ->and(array_keys(BattlePresentationCatalog::load($this->root)->actors))->toBe(['Hero']);
});
