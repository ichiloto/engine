<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleProgression;
use Ichiloto\Engine\Battle\Presentation\BattleResultsContent;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleHudListSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleHud;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleResults;
use Ichiloto\Engine\Progression\ProgressionSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

function resultsSkinFixture(array $portraits = [], array $icons = []): BattleResultsSkin
{
  $textures = [];
  foreach (['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider', 'button'] as $role) {
    $textures[$role] = new CanvasNineSlice($role . '.png', new SpriteSourceRect(0, 0, 8, 8));
  }
  $colors = [];
  foreach (['text', 'muted', 'accent', 'positive', 'negative', 'ink'] as $role) {
    $colors[$role] = PresentationColor::rgb(230, 230, 230);
  }
  return new BattleResultsSkin($textures, $colors, $portraits, $icons);
}

function resultsGraphicalFacts(int $members = 4, int $drops = 0, bool $long = false): BattleRewards
{
  $progression = $items = [];
  foreach (range(1, $members) as $index) {
    $thresholds = [1 => 0, 2 => 100, 3 => 300];
    $name = $long ? str_repeat('Character Name ', 12) . $index : 'Hero ' . $index;
    $before = new ProgressionSnapshot('actor-' . $index, $name, 1, 0, 3, $thresholds, ['maxHp' => 100]);
    $after = new ProgressionSnapshot('actor-' . $index, $name, 2, 150, 3, $thresholds, ['maxHp' => 110]);
    $progression[] = new BattleProgression(150, $before, $after,
      [['name' => 'Cure', 'kind' => 'Magic', 'cost' => 7, 'description' => str_repeat('Healing light. ', $long ? 100 : 1)]]);
  }
  for ($i = 0; $i < $drops; $i++) {
    $items[] = ['id' => 'item-' . $i, 'name' => ($long ? str_repeat('Long item ', 20) : 'Item ') . $i,
      'description' => '', 'quantity' => $i + 1, 'received' => $i];
  }
  return new BattleRewards(150, 123, $progression, $items,
    ['Battle time' => '00:43', 'Enemies defeated' => '2'], [['title' => 'Actual seal', 'description' => 'Actual supplied reward']]);
}

function resultsBattlefield(): PresentationCanvas
{
  return new PresentationCanvas(1350, 720, [new CanvasImage('real-final-frame', 'arena.png',
    new CanvasRectangle(0, 0, 1350, 720))]);
}

function resultsFrameText(PresentationCanvas $frame): string
{
  return implode("\n", array_merge(...array_map(fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers)));
}

it('keeps results portraits on the actor id while displaying a renamed actor', function () {
  $data = (require dirname(__DIR__) . '/Fixtures/Actors/FoundationHero.php')['data'];
  $original = \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray($data)->createCharacter();
  $data['name'] = 'Hero Renamed';
  $renamed = \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray($data)->createCharacter($original->toArray());
  $snapshot = ProgressionSnapshot::capture($renamed);
  $portrait = new CanvasNineSlice('hero-portrait.png', new SpriteSourceRect(0, 0, 96, 96));
  $skin = resultsSkinFixture(['actor.hero' => ['menu' => $portrait, 'bust' => $portrait]]);
  $playback = new BattleResultsPlayback(new BattleRewards(0, 0, [new BattleProgression(0, $snapshot, $snapshot)]), true);
  $frame = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $playback);
  expect($snapshot->actorId)->toBe('actor.hero')->and(resultsFrameText($frame))->toContain('Hero Renamed')
    ->and(array_column($frame->images, 'asset'))->toContain('hero-portrait.png');
});

it('centers each confirmation label on its button without a list cursor', function () {
  $p = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  $p->update(0.5);
  foreach (['Complete', 'Continue'] as $label) {
    $p->update(0.33);
    $frame = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
    $text = array_column($frame->textLayers, null, 'id')['results-confirm'];
    expect($text->runs[0]->text)->toBe($label)
      ->and($text->bounds->x + $text->bounds->width / 2)->toBe(675.0)
      ->and($text->bounds->y + $text->bounds->height / 2)->toBe(685.0)
      ->and(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'results-selector-')))->toBe([]);
    $p->confirm();
  }
});

it('fades the complete button assembly with an empty beat and a centered incoming label', function () {
  $p = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  $p->update(0.5);
  $p->confirm();
  foreach ([[0.06, 'Complete', 0.5], [0.09, null, 0.0], [0.10, 'Continue', 0.5], [0.08, 'Continue', 1.0]] as [$delta, $label, $alpha]) {
    $p->update($delta);
    $frame = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
    $images = array_filter($frame->images, fn($image) => str_starts_with($image->id, 'results-confirm-'));
    expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'results-selector-')))->toBe([]);
    $text = array_column($frame->textLayers, null, 'id')['results-confirm'] ?? null;
    if ($label === null) {
      expect($images)->toBe([])->and($text)->toBeNull();
    } else {
      expect($images)->not->toBeEmpty();
      foreach ($images as $image) { expect($image->opacity)->toEqualWithDelta($alpha, 0.000001); }
      expect($text->runs[0]->text)->toBe($label)
        ->and($text->opacity)->toEqualWithDelta($alpha, 0.000001)
        ->and($text->bounds->x + $text->bounds->width / 2)->toBe(675.0);
    }
    expect(resultsFrameText($frame))->toContain('VICTORY', 'REWARDS');
  }
});

it('keeps completed confirmation artwork steady without a cursor in either motion mode', function (bool $reducedMotion) {
  $p = new BattleResultsPlayback(new BattleRewards(0, 0, []), $reducedMotion);
  $p->update(10);
  $field = resultsBattlefield();
  $skin = resultsSkinFixture();
  $first = GraphicalBattleResults::frame($field, $skin, $p);
  $p->update(0.75);
  $next = GraphicalBattleResults::frame($field, $skin, $p);
  $selector = fn($frame) => array_values(array_filter($frame->images,
    fn($image) => str_starts_with($image->id, 'results-selector-')));
  $button = fn($frame) => array_values(array_filter($frame->images,
    fn($image) => str_starts_with($image->id, 'results-confirm-')));
  expect($selector($first))->toBe([])->and($selector($next))->toBe([])
    ->and($button($first))->not->toBeEmpty()->toEqual($button($next))
    ->and($first->toArray())->toBe($next->toArray())
    ->and($p->confirmation())->toBe(['label' => 'Continue', 'opacity' => 1.0, 'enabled' => true]);
})->with([false, true]);

it('keeps the completed action label unchanged throughout the locked exit fade', function (string $kind) {
  $award = resultsGraphicalFacts(1)->progression[0];
  $facts = match ($kind) {
    'primary' => new BattleRewards(0, 0, []),
    'level' => new BattleRewards(150, 0, [new BattleProgression(150, $award->before, $award->after)]),
    'ability' => new BattleRewards(150, 0, [$award]),
    'special' => new BattleRewards(0, 0, [], specialRewards: [['title' => 'Seal', 'description' => 'A keepsake.']]),
  };
  $p = new BattleResultsPlayback($facts);
  while ($p->currentStage()['kind'] !== $kind) {
    $p->update(10);
    $p->confirm();
  }
  $p->update(10);
  $field = resultsBattlefield();
  $skin = resultsSkinFixture();
  $before = array_column(GraphicalBattleResults::frame($field, $skin, $p)->textLayers, null, 'id')['results-confirm'];
  expect($p->confirm())->toBeFalse()->and($p->isExiting())->toBeTrue();
  foreach ([0, 0.14, 0.14, 0.13] as $delta) {
    $p->update($delta);
    $frame = GraphicalBattleResults::frame($field, $skin, $p);
    $label = array_column($frame->textLayers, null, 'id')['results-confirm'];
    expect($label->runs[0]->text)->toBe('Continue')
      ->and($label->bounds)->toEqual($before->bounds)
      ->and($label->opacity)->toBe($p->opacity())
      ->and($p->confirm())->toBeFalse()
      ->and($p->currentStage()['kind'])->toBe($kind)
      ->and($p->rewards)->toBe($facts)
      ->and(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'results-selector-')))->toBe([]);
  }
  $p->update(0.02);
  expect($p->isFinished())->toBeTrue()
    ->and(GraphicalBattleResults::frame($field, $skin, $p))->toBe($field);
})->with(['primary', 'level', 'ability', 'special']);

it('keeps outside-panel hints readable and fits all nine level-up stats on one ordinary page', function () {
  $stats = array_fill_keys(['maxHp', 'maxMp', 'attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'], 5);
  $before = new ProgressionSnapshot('hero', 'Hero', 1, 0, 3, [1 => 0, 2 => 100, 3 => 300], $stats);
  $after = new ProgressionSnapshot('hero', 'Hero', 2, 150, 3, [1 => 0, 2 => 100, 3 => 300], $stats);
  $playback = new BattleResultsPlayback(new BattleRewards(150, 0, [new BattleProgression(150, $before, $after)]), true);
  $playback->confirm();
  $skin = resultsSkinFixture();
  $frame = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $playback);
  expect($playback->pageCount())->toBe(1)->and(resultsFrameText($frame))->toContain('Evasion', "1  \u{2192}  2", "5 \u{2192} 5")
    ->not->toContain('->');
  $counter = array_column($frame->textLayers, null, 'id')['results-event-counter'];
  expect($counter->runs[0]->background)->toBe($skin->colors['ink']);

  $overflow = new BattleResultsPlayback(resultsGraphicalFacts(drops: 12), true);
  $frame = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $overflow);
  $pages = array_column($frame->textLayers, null, 'id')['results-pages'];
  expect($pages->runs[0]->background)->toBe($skin->colors['ink']);
});

it('preserves the real battlefield and approved primary geometry with independent values and neutral portraits', function () {
  $field = resultsBattlefield();
  $p = new BattleResultsPlayback(resultsGraphicalFacts(drops: 2), true);
  $frame = GraphicalBattleResults::frame($field, resultsSkinFixture(), $p);
  $images = array_column($frame->images, null, 'id');
  expect($frame->images[0])->toBe($field->images[0])
    ->and($images['results-party-1-1']->destination)->toEqual(new CanvasRectangle(52, 146, 754, 502))
    ->and($images['results-rewards-1-1']->destination)->toEqual(new CanvasRectangle(828, 146, 470, 324))
    ->and($images['results-summary-1-1']->destination)->toEqual(new CanvasRectangle(828, 484, 470, 164))
    ->and(resultsFrameText($frame))->toContain('EXP / MEMBER', '150', '123', 'H1', 'Retained: 0 of 1')
    ->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  foreach (['experience', 'gold'] as $key) {
    $value = array_column($frame->textLayers, null, 'id')['results-' . $key . '-value'];
    expect($value->runs[0]->column + mb_strlen($value->runs[0]->text))->toBe($value->grid->columns);
  }
});

it('omits zero EXP fills then clips full-sized strips without compressing their source art', function () {
  $p = new BattleResultsPlayback(resultsGraphicalFacts(1));
  $p->update(0.8);
  $zero = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
  expect(array_filter($zero->images, fn($image) => str_contains($image->id, '-fill')))->toBe([]);
  $p->update(2);
  $full = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
  $fill = array_values(array_filter($full->images, fn($image) => str_contains($image->id, '-fill')))[0];
  expect($fill->destination->width)->toBe(578.0)->and($fill->clipRect->width)->toBe(144.5)
    ->and($fill->sourceRect)->toEqual(new SpriteSourceRect(0, 0, 8, 8));
});

it('pages reserves, long names, item overflow and all event details within canvas limits', function () {
  $facts = resultsGraphicalFacts(8, 7, true);
  $p = new BattleResultsPlayback($facts, true);
  $all = '';
  foreach (range(0, BattleResultsContent::pageCount($p) - 1) as $_) {
    $frame = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
    $all .= resultsFrameText($frame);
    expect(count($frame->textLayers))->toBeLessThanOrEqual(64);
    foreach ($frame->textLayers as $layer) {
      $layer->paintBounds->assertWithin(1350, 720);
      foreach ($layer->runs as $run) { $run->assertFits($layer->grid->columns, $layer->grid->rows); }
    }
    $p->navigate(1);
  }
  expect($all)->toContain('NAME CONTINUED', 'Retained: 6 of 7')->and($p->rewards)->toBe($facts);
  $p->confirm();
  $level = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
  expect(resultsFrameText($level))->toContain('LEVEL UP', '110');
  $p->update(0.1);
  $p->confirm();
  $descriptions = '';
  foreach (range(0, BattleResultsContent::pageCount($p) - 1) as $_) {
    $descriptions .= resultsFrameText(GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p));
    $p->navigate(1);
  }
  expect($descriptions)->toContain('Cost: 7 MP')->and(substr_count($descriptions, 'Healing light.'))->toBeGreaterThanOrEqual(100);
});

it('composes explicit special rewards without inventing rarity and clears overlay on finished exit', function () {
  $p = new BattleResultsPlayback(new BattleRewards(0, 0, [], specialRewards: [
    ['title' => 'Bronze seal', 'description' => 'A keepsake.'],
  ]), true);
  $p->confirm();
  $field = resultsBattlefield();
  $frame = GraphicalBattleResults::frame($field, resultsSkinFixture(), $p);
  expect(resultsFrameText($frame))->toContain('SPECIAL REWARD', 'Bronze seal', 'A keepsake.')
    ->not->toContain('RARE', 'LEGENDARY');
  $p->update(0.1);
  $p->confirm();
  expect(GraphicalBattleResults::frame($field, resultsSkinFixture(), $p))->toBe($field);
});

it('contains separate menu and bust artwork without enlarging a menu portrait into an event bust', function () {
  $menu = new CanvasNineSlice('menu.png', new SpriteSourceRect(0, 0, 100, 100));
  $skin = resultsSkinFixture(['actor-1' => ['menu' => $menu]]);
  $p = new BattleResultsPlayback(resultsGraphicalFacts(1), true);
  $primary = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $p);
  expect(array_filter($primary->images, fn($image) => $image->asset === 'menu.png'))->toHaveCount(1);
  $p->confirm();
  $event = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $p);
  expect(array_filter($event->images, fn($image) => $image->asset === 'menu.png'))->toBe([])
    ->and(resultsFrameText($event))->toContain('H1');
});

it('validates asset presence, source bounds, unique decoded costs and one-density roles', function () {
  $root = sys_get_temp_dir() . '/ichiloto-results-test-' . bin2hex(random_bytes(4));
  mkdir($root);
  $skin = resultsSkinFixture();
  try {
    foreach ($skin->textures as $texture) {
      file_put_contents($root . '/' . $texture->asset, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 8, 8));
    }
    $costs = GraphicalBattleResults::preflight($skin, $root);
    expect($costs)->toHaveCount(8)->and(array_sum($costs))->toBe(8 * 8 * 4 * 8);
    foreach ($skin->textures as $texture) {
      file_put_contents($root . '/' . $texture->asset, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 4096, 4096));
      touch($root . '/' . $texture->asset, time() + 1);
    }
    expect(fn() => GraphicalBattleResults::preflight($skin, $root))->toThrow(RuntimeException::class, '64 MiB');
    foreach ($skin->textures as $texture) {
      file_put_contents($root . '/' . $texture->asset, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 8, 8));
      touch($root . '/' . $texture->asset, time() + 2);
    }
    $portrait = new CanvasNineSlice('panel.png', new SpriteSourceRect(1, 0, 7, 8));
    $prepared = GraphicalBattleResults::prepare(resultsSkinFixture(['actor-1' => ['menu' => $portrait]]), $root);
    expect($prepared->portraits['actor-1']['menu']->source->toArray())
      ->toBe(['x' => 0, 'y' => 0, 'width' => 8, 'height' => 8]);
    unlink($root . '/exp.png');
    expect(GraphicalBattleResults::preflight($skin, $root))->not->toHaveKey('exp.png')->toHaveCount(7);
    $textures = $skin->textures;
    $textures['exp'] = new CanvasNineSlice('exp.png', new SpriteSourceRect(0, 0, 8, 8), density: 2);
    expect(fn() => new BattleResultsSkin($textures, $skin->colors))->toThrow(InvalidArgumentException::class);
  } finally {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
  }
});

it('contains current portrait and icon images after same-path replacements without changing rewards', function () {
  $root = sys_get_temp_dir() . '/ichiloto-results-replacement-' . bin2hex(random_bytes(4));
  mkdir($root);
  $legacy = new CanvasNineSlice('actor.png', new SpriteSourceRect(0, 0, 100, 120));
  $skin = resultsSkinFixture(['actor-1' => ['menu' => $legacy, 'bust' => $legacy]], ['inventory' => $legacy]);
  $facts = resultsGraphicalFacts(1);
  $before = serialize($facts);
  try {
    foreach ($skin->textures as $texture) {
      file_put_contents($root . '/' . $texture->asset, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 8, 8));
    }
    foreach ([[40, 80], [200, 60], [100, 120]] as $revision => [$width, $height]) {
      file_put_contents($root . '/actor.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', $width, $height));
      touch($root . '/actor.png', time() + $revision);
      $prepared = GraphicalBattleResults::prepare($skin, $root);
      expect($prepared->icons['inventory']->source->toArray())
        ->toBe(['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height]);
      $playback = new BattleResultsPlayback($facts, true);
      foreach (['menu', 'bust'] as $family) {
        $frame = GraphicalBattleResults::frame(new PresentationCanvas(1350, 720), $prepared, $playback);
        CanvasImagePreflight::inspect($frame->images, $root);
        $image = array_values(array_filter($frame->images, fn($image) => $image->asset === 'actor.png'))[0];
        expect($image->sourceRect)->toEqual($prepared->portraits['actor-1'][$family]->source)
          ->and($image->destination->width / $image->destination->height)->toEqualWithDelta($width / $height, 0.000001);
        $playback->confirm();
      }
      expect(serialize($facts))->toBe($before)->and($skin->portraits['actor-1']['menu'])->toBe($legacy);
    }
  } finally {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
  }
});

it('renders signed 64-bit totals without truncation or overlap', function () {
  $p = new BattleResultsPlayback(new BattleRewards(PHP_INT_MAX, PHP_INT_MAX, [], [
    ['id' => 'x', 'name' => 'Elixir', 'quantity' => PHP_INT_MAX, 'description' => ''],
  ]), true);
  $frame = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), $p);
  expect(resultsFrameText($frame))->toContain((string)PHP_INT_MAX, 'x' . PHP_INT_MAX);
});

it('validates every portrait but budgets Primary pages and individual busts separately over the battlefield', function () {
  $root = sys_get_temp_dir() . '/ichiloto-results-stages-' . bin2hex(random_bytes(4));
  mkdir($root);
  $png = static function (string $name, int $width, int $height) use ($root): void {
    static $revision = 0;
    file_put_contents($root . '/' . $name, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', $width, $height));
    touch($root . '/' . $name, time() + ++$revision);
  };
  try {
    foreach (resultsSkinFixture()->textures as $texture) { $png($texture->asset, 8, 8); }
    $png('arena.png', 3072, 3072);
    $portraits = [];
    foreach (range(1, 6) as $i) {
      $png('menu-' . $i . '.png', 512, 512);
      $png('bust-' . $i . '.png', 2048, 2048);
      $portraits['actor-' . $i] = [
        'menu' => new CanvasNineSlice('menu-' . $i . '.png', new SpriteSourceRect(0, 0, 512, 512)),
        'bust' => new CanvasNineSlice('bust-' . $i . '.png', new SpriteSourceRect(0, 0, 2048, 2048)),
      ];
    }
    $skin = resultsSkinFixture($portraits);
    $costs = GraphicalBattleResults::preflight($skin, $root, resultsBattlefield()->images);
    expect(array_sum($costs))->toBeGreaterThan(67108864);

    // Unavailable optional portraits do not invalidate healthy catalog entries.
    unlink($root . '/bust-6.png');
    $available = GraphicalBattleResults::preflight($skin, $root, [], ['actor-1']);
    expect($available)->not->toHaveKey('bust-6.png')->toHaveKey('menu-6.png')->toHaveKey('bust-1.png')->toHaveCount(19);
    $png('bust-6.png', 2048, 2048);

    // These actors can share a Primary page when continued names shift the page boundary.
    foreach ([4, 5] as $i) {
      $png('menu-' . $i . '.png', 3072, 3072);
      $portraits['actor-' . $i]['menu'] = new CanvasNineSlice('menu-' . $i . '.png', new SpriteSourceRect(0, 0, 3072, 3072));
    }
    $skin = resultsSkinFixture($portraits);
    expect(fn() => GraphicalBattleResults::preflight($skin, $root))->toThrow(RuntimeException::class, '64 MiB');
    expect(GraphicalBattleResults::preflight($skin, $root, [], ['actor-1']))->toHaveCount(20);
  } finally {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
  }
});

it('keeps multiline party names separate from gains at ordinary and 64-bit widths', function () {
  foreach ([150, PHP_INT_MAX] as $gain) {
    $award = resultsGraphicalFacts(1, long: true)->progression[0];
    $facts = new BattleRewards($gain, 0, [new BattleProgression($gain, $award->before, $award->after)]);
    $frame = GraphicalBattleResults::frame(resultsBattlefield(), resultsSkinFixture(), new BattleResultsPlayback($facts, true));
    $layers = array_column($frame->textLayers, null, 'id');
    $name = $layers['results-party-name-0-1']->bounds;
    $number = $layers['results-party-gain-0']->bounds;
    expect($name->x + $name->width)->toBeLessThanOrEqual($number->x)
      ->and(resultsFrameText($frame))->toContain('+' . $gain);
  }
});

it('uses admitted semantic item and skill icons rather than falling back for known categories', function () {
  $icon = fn(string $name) => new CanvasNineSlice($name . '.png', new SpriteSourceRect(0, 0, 8, 8));
  $skin = resultsSkinFixture(icons: ['item' => $icon('item'), 'skill' => $icon('skill'), 'unknown' => $icon('unknown')]);
  $p = new BattleResultsPlayback(resultsGraphicalFacts(1), true);
  $assets = [];
  while (!$p->isFinished()) {
    $frame = GraphicalBattleResults::frame(resultsBattlefield(), $skin, $p);
    array_push($assets, ...array_column($frame->images, 'asset'));
    $p->update(0.1);
    $p->confirm();
  }
  expect($assets)->toContain('skill.png', 'item.png')->not->toContain('unknown.png');
});

it('keeps healthy Results artwork above fallback panels with complete rewards and controls', function () {
  $root = sys_get_temp_dir() . '/ichiloto-results-fallback-' . bin2hex(random_bytes(4));
  mkdir($root);
  try {
    foreach (['exp.png', 'portrait.png', 'button.png'] as $asset) {
      file_put_contents($root . '/' . $asset, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 8, 8));
    }
    $skin = GraphicalBattleResults::prepare(resultsSkinFixture(), $root);
    $playback = new BattleResultsPlayback(resultsGraphicalFacts(), true);
    $frame = GraphicalBattleResults::frame(new PresentationCanvas(1350, 720), $skin, $playback);
    expect(array_column($frame->images, 'asset'))->toContain('exp.png', 'portrait.png', 'button.png')
      ->and(resultsFrameText($frame))->toContain('VICTORY', 'Hero 1', 'Hero 4', '123', 'Continue');
    $layers = array_column($frame->textLayers, null, 'id');
    $images = array_column($frame->images, null, 'id');
    expect($layers['results-party-fallback']->layer)->toBeLessThan($images['results-party-0-portrait-frame-1-1']->layer)
      ->and($layers['results-party-fallback']->layer)->toBeLessThan($images['results-party-0-fill-1-1']->layer);
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    $frame = GraphicalBattleResults::frame(new PresentationCanvas(1350, 720), $skin, $playback);
    expect($frame->images)->toBe([])->and(resultsFrameText($frame))->toContain('Continue', 'Hero 4');
  } finally {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
  }
});

it('fits all-missing Results textures over a full four-member HUD without dropping either surface', function () {
  $root = sys_get_temp_dir() . '/ichiloto-combined-fallback-' . bin2hex(random_bytes(4));
  mkdir($root);
  try {
    $list = new BattleHudListSnapshot('Title', 'Help', array_map(
      fn($i) => new BattleHudRow($i, 'Member ' . $i, $i === 0), range(0, 3)), 0, 0, 4, 4, 1, 1);
    $hud = new BattleHudSnapshot($list, $list, $list,
      new BattleHudStatusSnapshot('', '', array_map(
        fn($i) => new BattleHudStatusRow($i, 31 + $i * 27, 200, 7 + $i * 3, 30, $i / 3), range(0, 3))),
      message: 'Battle won');
    $textures = [];
    foreach (['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting'] as $role) {
      $textures[$role] = new CanvasNineSlice($role . '.png', new SpriteSourceRect(0, 0, 8, 8));
    }
    $palette = array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'], PresentationColor::rgb(200, 200, 200));
    $arena = new BattleArenaDefinition(1350, 720, new CanvasImage('field', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)),
      [], [], skin: new BattleUiSkin($textures, $palette), feedbackArea: new CanvasRectangle(0, 80, 1350, 452));
    $field = GraphicalBattleHud::compose($arena, $hud, 'submenu', 0, $root);
    $skin = GraphicalBattleResults::prepare(resultsSkinFixture(), $root);
    $frame = GraphicalBattleResults::frame($field, $skin, new BattleResultsPlayback(resultsGraphicalFacts(), true));
    expect($frame->images)->toBe([])->and(resultsFrameText($frame))->toContain('Continue', 'Hero 4', 'Member 0', 'Battle won', '16%', '100%');
  } finally { rmdir($root); }
});
