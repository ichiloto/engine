<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\{MapLayer, MapLayerSet};
use Ichiloto\Engine\IO\Console\{ConsolePresentationChanges, ConsolePresentationSnapshot};
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasOverlayProviderInterface, CanvasProviderInterface,
  CanvasRectangle, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{FramePresentationSourceInterface, FrameViewportProviderInterface,
  PresentationSprite, PresentationSpriteMotion, PresentationSpritePivot, PresentationTextLayer, PresentationTextRun,
  PresentationViewport, PresentationViewportFollow, PresentationWorld, PresentationWorldCanvas,
  RetainedWorldProviderInterface, SceneFrameComposer, SpriteSourceRect, WorldCanvasProviderInterface};
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\{RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

final class FramePresentationSourceProbe implements FramePresentationSourceInterface, RetainedWorldProviderInterface,
  FrameViewportProviderInterface, WorldCanvasProviderInterface, CanvasProviderInterface, CanvasOverlayProviderInterface
{
  public array $sprites = [];
  public array $groups = [];
  public ?PresentationWorld $world = null;
  public ?PresentationViewport $viewport = null;
  public ?PresentationCanvas $canvas = null;
  public ?PresentationCanvas $overlay = null;
  public int $refreshes = 0;
  public int $spriteReads = 0;
  public int $worldReads = 0;
  public int $groupReads = 0;
  public array $viewportInputs = [];

  public function getPresentationSprites(): array { $this->spriteReads++; return $this->sprites; }
  public function getPresentationWorld(): ?PresentationWorld { $this->worldReads++; return $this->world; }
  public function getPresentationWorldCanvases(): array { $this->groupReads++; return $this->groups; }
  public function getPresentationCanvas(): ?PresentationCanvas { return $this->canvas; }
  public function renderPresentationOverlay(): void { $this->refreshes++; }
  public function getPresentationOverlay(int $width, int $height): ?PresentationCanvas { return $this->overlay; }
  public function getExcludedOverlayLayers(): array { return ['authoring-overlay']; }
  public function getPresentationViewport(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites, array $tiles = []): ?PresentationViewport
  {
    $this->viewportInputs[] = [$snapshot, $sprites, $tiles];
    return $this->viewport;
  }
}

final class FrameSourceNotificationProbe extends NotificationManager
{
  public function __construct(public PresentationCanvas $notice) {}
  public function __destruct() {}
  public function getExcludedPresentationLayers(): array { return ['notice']; }
  public function composePresentation(?PresentationCanvas $base, int $width, int $height, array $protected = []): ?PresentationCanvas
  {
    return $base === null ? $this->notice : PresentationCanvas::composeOverlay($base, $this->notice);
  }
}

function createFrameSourceCanvas(string $id, int $width = 384, int $height = 192): PresentationCanvas
{
  return new PresentationCanvas($width, $height, textLayers: [new CanvasTextLayer($id, 1, 0, 0,
    new RendererGridConfig(1, 1, 16, 24), [new PresentationTextRun(0, 0, '*')])]);
}

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-frame-source-');
  $this->grid = new RendererGridConfig(8, 4, 48, 48);
  $this->composer = new SceneFrameComposer($this->root);
  $this->source = new FramePresentationSourceProbe();
  $rows = array_fill(0, 6, str_repeat('.', 12));
  $rows[4] = '.......X....';
  $this->layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'unsaved-authoring', implode("\n", $rows))]);
  $this->source->world = PresentationWorld::getFromLayers($this->layers, 'authoring-map');
  $this->source->sprites = [new PresentationSprite('object', 'Replaceable/Object.png', 4, 2, 48, 96, layer: 10,
    sourceRect: new SpriteSourceRect(0, 0, 48, 96), motion: new PresentationSpriteMotion(.2), lift: 6,
    quarterTurns: 1, pivot: new PresentationSpritePivot(.5, 1)),
    new PresentationSprite('edge', 'Replaceable/Edge.png', -1, 1, 48, 48, layer: 10)];
  $this->source->groups = [new PresentationWorldCanvas('sign', 4, 2, new PresentationSpritePivot(.5, 1),
    new PresentationCanvas(48, 24), 10),
    new PresentationWorldCanvas('ground-effect', 1, 1, new PresentationSpritePivot(.5, 1), new PresentationCanvas(48, 24))];
  $this->source->viewport = new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 384, 192),
    textLayerIds: ['terminal-only'], spriteIds: ['object', 'edge'], worldId: $this->source->world->id,
    worldOriginX: 3, worldOriginY: 2, tileFrame: 7, follow: new PresentationViewportFollow('object'));
  $this->text = array_map(static fn($id) => new PresentationTextLayer($id, 1, [new PresentationTextRun(0, 0, '.')]),
    ['map:terrain', 'object', 'edge', 'sign', 'terminal-only', 'authoring-overlay', 'notice']);
  $this->captures = [];
  $this->capture = function (array $excluded, array $worldLayers): ConsolePresentationSnapshot {
    $this->captures[] = [$excluded, $worldLayers];
    return new ConsolePresentationSnapshot(8, 4, array_values(array_filter($this->text,
      static fn($layer) => !in_array($layer->id, [...$excluded, ...$worldLayers], true))));
  };
});

it('accepts only the narrow sprite source without requiring a scene game UI or other providers', function () {
  $sprite = $this->source->sprites[0];
  $source = new class($sprite) implements FramePresentationSourceInterface {
    public function __construct(private PresentationSprite $sprite) {}
    public function getPresentationSprites(): array { return [$this->sprite]; }
  };
  $frame = $this->composer->composeFrame($source, $this->grid, fn($feature) => true, $this->capture);
  expect($source)->not->toBeInstanceOf(SceneInterface::class)
    ->and(method_exists($source, 'getGame'))->toBeFalse()->and(method_exists($source, 'getUI'))->toBeFalse()
    ->and($frame->sprites)->toBe([$sprite])->and($frame->world)->toBeNull()->and($frame->viewport)->toBeNull()
    ->and($frame->worldCanvases)->toBe([])->and($frame->canvas)->toBeNull()->and($frame->screenOverlay)->toBeNull()
    ->and($this->captures)->toBe([[['object'], []]]);
});

it('composes unsaved world data projected sprites groups and viewport without changing their ownership', function () {
  $sprites = $this->source->sprites;
  $groups = $this->source->groups;
  $viewport = $this->source->viewport;
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  $rows = array_values(array_filter($frame->world->getOperations(false), fn($op) => $op['op'] === 'worldRows'));
  expect($frame->world)->toBe($this->source->world)->and($rows[4]['rows'][0]['cells'][7]['glyph'])->toBe('X')
    ->and($frame->sprites)->toBe([$sprites[1], $sprites[0]])
    ->and([$frame->sprites[1]->x, $frame->sprites[1]->y])->toBe([4, 2])
    ->and($frame->worldCanvases)->toBe([$groups[1], $groups[0]])
    ->and($frame->viewport->worldCanvasIds)->toBe(['ground-effect', 'sign'])
    ->and($frame->viewport->follow)->toBe($viewport->follow)
    ->and([$frame->viewport->worldOriginX, $frame->viewport->worldOriginY])->toBe([3, 2])
    ->and($this->captures)->toBe([[['edge', 'object', 'authoring-overlay'], ['map:terrain']]])
    ->and(array_column($frame->snapshot->textLayers, 'id'))->toBe(['sign', 'terminal-only', 'notice'])
    ->and($this->source->viewportInputs)->toBe([[$frame->snapshot, [$sprites[1], $sprites[0]], []]])
    ->and($this->source->sprites)->toBe($sprites)->and($this->source->groups)->toBe($groups)
    ->and($this->source->viewport)->toBe($viewport)->and($viewport->worldCanvasIds)->toBe([])
    ->and($this->layers->layers[0]->getGlyphAt(7, 4))->toBe('X');
});

it('uses current in-memory edits and removes deleted subjects rather than caching an authored map', function () {
  $before = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  $this->source->world = PresentationWorld::getFromLayers(new MapLayerSet([
    new MapLayer('terrain', 0, false, 'changed-in-memory', 'Y...')]), $before->world->id);
  $this->source->sprites[0] = new PresentationSprite('object', 'Other/Replacement.png', 3, 1, 48, 48, layer: 10);
  $this->source->groups = [];
  $after = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  $object = array_find($after->sprites, fn($sprite) => $sprite->id === 'object');
  expect($after->world)->toBe($this->source->world)->and($after->world)->not->toBe($before->world)
    ->and($after->world->id)->toBe($before->world->id)
    ->and($after->world->getOperations(false)[1]['rows'][0]['cells'][0]['glyph'])->toBe('Y')
    ->and($object->asset)->toBe('Other/Replacement.png')->and([$object->x, $object->y])->toBe([3, 1])
    ->and($after->worldCanvases)->toBe([])->and($after->viewport->worldCanvasIds)->toBe([])
    ->and($before->worldCanvases)->toHaveCount(2);
});

it('preserves reduced-motion choices and degrades unsupported motion before the viewport provider reads sprites', function (bool $reduced) {
  if ($reduced) {
    $this->source->sprites[0] = $this->source->sprites[0]->withoutMotion();
    $this->source->viewport = $this->source->viewport->withoutFollow();
  }
  $normal = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  $degraded = $this->composer->composeFrame($this->source, $this->grid,
    fn($feature) => $feature !== RendererSessionConfig::FIELD_MOTION, $this->capture);
  expect(array_column($degraded->sprites, 'motion'))->toBe([null, null])
    ->and($degraded->viewport->follow)->toBeNull()->and($degraded->viewport->tileFrame)->toBe(7)
    ->and($degraded->viewport->worldCanvasIds)->toBe($normal->viewport->worldCanvasIds)
    ->and($degraded->sprites[1]->toArray(true, true, true))->toBe(array_diff_key(
      $normal->sprites[1]->toArray(true, true, true), ['motion' => true]))
    ->and($this->source->viewportInputs[1][1])->toBe($degraded->sprites)
    ->and($this->source->sprites[0]->motion)->toBe($reduced ? null : $normal->sprites[1]->motion);
})->with([false, true]);

it('keeps source overlays optional and notifications above them with the same Terminal exclusions', function (bool $overlay) {
  $this->source->overlay = createFrameSourceCanvas('cover');
  $notices = new FrameSourceNotificationProbe(createFrameSourceCanvas('notice'));
  $frame = $this->composer->composeFrame($this->source, $this->grid,
    fn($feature) => $overlay || $feature !== RendererSessionConfig::CANVAS_OVERLAY, $this->capture, $notices);
  expect($this->source->refreshes)->toBe($overlay ? 1 : 0)
    ->and($this->captures[0][0])->toBe($overlay ? ['edge', 'object', 'authoring-overlay', 'notice'] : ['edge', 'object', 'notice'])
    ->and(array_column($frame->screenOverlay->textLayers, 'id'))->toBe($overlay ? ['cover', 'notice'] : ['notice']);
  if ($overlay) {
    expect($frame->screenOverlay->textLayers[1]->layer)->toBeGreaterThan($frame->screenOverlay->textLayers[0]->layer);
  }
})->with([true, false]);

it('applies explicit graphical layer suppression even when the provider returns no overlay canvas', function () {
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  expect($this->captures[0][0])->toContain('authoring-overlay')
    ->and(array_column($frame->snapshot->textLayers, 'id'))->not->toContain('authoring-overlay')
    ->and($frame->screenOverlay)->toBeNull();
});

it('respects caller-owned overlay refresh while still composing the supplied overlay', function () {
  $this->source->overlay = createFrameSourceCanvas('cover');
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture, refreshOverlay: false);
  expect($this->source->refreshes)->toBe(0)->and($frame->screenOverlay)->toBe($this->source->overlay)
    ->and($this->captures[0][0])->toContain('authoring-overlay');
});

it('replaces field inputs with a source canvas without reading field providers or collecting Terminal text', function () {
  $this->source->canvas = createFrameSourceCanvas('replacement');
  $this->source->overlay = createFrameSourceCanvas('cover');
  $notices = new FrameSourceNotificationProbe(createFrameSourceCanvas('notice'));
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true,
    fn() => throw new LogicException('Replacement canvases must not capture field text.'), $notices);
  expect($frame->canvas)->toBe($this->source->canvas)->and($frame->snapshot)->toBeNull()
    ->and($frame->world)->toBeNull()->and($frame->viewport)->toBeNull()->and($frame->sprites)->toBe([])
    ->and($frame->worldCanvases)->toBe([])
    ->and([$this->source->spriteReads, $this->source->worldReads, $this->source->groupReads])->toBe([0, 0, 0])
    ->and($this->source->viewportInputs)->toBe([])
    ->and(array_column($frame->screenOverlay->textLayers, 'id'))->toBe(['cover', 'notice'])
    ->and($frame->screenOverlay->textLayers[1]->layer)->toBeGreaterThan($frame->screenOverlay->textLayers[0]->layer);
});

it('passes detached retained text changes to the existing viewport contract without consuming a cursor', function () {
  $changes = new ConsolePresentationChanges(8, 4, false);
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true,
    function (array $excluded, array $worldLayers) use ($changes) {
      expect($excluded)->toBe(['edge', 'object', 'authoring-overlay'])->and($worldLayers)->toBe(['map:terrain']);
      return $changes;
    });
  expect($frame->snapshot)->toBe($changes)->and($this->source->viewportInputs[0][0])->toBe($changes);
});

it('retains off-grid sprites for clipping and never shows their subjects terminal glyphs in their place', function () {
  $this->source->sprites = [new PresentationSprite('left', 'a.png', -1, 1, 48, 48),
    new PresentationSprite('top', 'a.png', 1, -1, 48, 48), new PresentationSprite('right', 'a.png', 8, 1, 48, 48),
    new PresentationSprite('bottom', 'a.png', 1, 4, 48, 48), new PresentationSprite('corner', 'a.png', 7, 3, 48, 48)];
  $this->source->viewport = null;
  $this->source->groups = [];
  // A two-cell terminal glyph whose subject stands one cell past the left edge leaves its tail at column 0.
  $this->text = [new PresentationTextLayer('left', 1, [new PresentationTextRun(1, 0, ')')]),
    new PresentationTextLayer('terminal-only', 1, [new PresentationTextRun(1, 0, '#')])];
  $frame = $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  expect($frame->sprites)->toHaveCount(5)
    ->and($this->captures[0][0])->toBe(['top', 'left', 'right', 'corner', 'bottom', 'authoring-overlay'])
    ->and(array_column($frame->snapshot->textLayers, 'id'))->toBe(['terminal-only']);
});

it('rejects unsupported world canvas capabilities instead of dropping authored groups', function (string $missing) {
  expect(fn() => $this->composer->composeFrame($this->source, $this->grid,
    fn($feature) => $feature !== $missing, $this->capture))->toThrow(RendererProtocolException::class);
})->with([RendererSessionConfig::WORLD_CANVAS, RendererSessionConfig::FRAME_VIEWPORT]);

it('refuses world canvases without their matching world and viewport', function (string $invalid) {
  if ($invalid === 'world') { $this->source->world = null; }
  elseif ($invalid === 'viewport') { $this->source->viewport = null; }
  else { $this->source->viewport = new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 384, 192), worldId: 'other-map'); }
  expect(fn() => $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture))
    ->toThrow(RendererProtocolException::class);
})->with(['world', 'viewport', 'mismatched-world']);

it('applies the same field layer policy to projected sprites and world canvases', function (string $kind, int $layer) {
  if ($kind === 'sprite') { $this->source->sprites = [new PresentationSprite('invalid', 'a.png', 1, 1, 48, 48, layer: $layer)]; }
  else { $this->source->groups = [new PresentationWorldCanvas('invalid', 1, 1, new PresentationSpritePivot(.5, 1), new PresentationCanvas(48, 24), $layer)]; }
  expect(fn() => $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture))
    ->toThrow(InvalidArgumentException::class);
})->with([['sprite', -2], ['sprite', 1000], ['group', -2], ['group', 1000]]);

it('refuses malformed projected sprite lists before motion degradation', function (string $invalid) {
  $sprite = $this->source->sprites[0];
  $this->source->sprites = match ($invalid) {
    'untyped' => [new stdClass()], 'keyed' => ['object' => $sprite],
    'duplicate' => [$sprite, $sprite], 'unbounded' => array_fill(0, 1025, $sprite),
  };
  expect(fn() => $this->composer->composeFrame($this->source, $this->grid,
    fn($feature) => $feature !== RendererSessionConfig::FIELD_MOTION, $this->capture))->toThrow(InvalidArgumentException::class);
})->with(['untyped', 'keyed', 'duplicate', 'unbounded']);

it('validates viewport membership and unique world canvas identities', function (string $invalid) {
  if ($invalid === 'duplicate-group') {
    $this->source->groups = [$this->source->groups[0], $this->source->groups[0]];
  } else {
    $this->source->viewport = new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 384, 192),
      textLayerIds: $invalid === 'text-member' ? ['missing-text'] : [],
      spriteIds: $invalid === 'sprite-member' ? ['missing-sprite'] : [], worldId: $this->source->world->id);
  }
  expect(fn() => $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture))
    ->toThrow(InvalidArgumentException::class);
})->with(['duplicate-group', 'text-member', 'sprite-member']);

it('does not retain a closed source world sprites viewport or overlay on subsequent empty frames', function () {
  $this->source->overlay = createFrameSourceCanvas('cover');
  $this->composer->composeFrame($this->source, $this->grid, fn($feature) => true, $this->capture);
  $frame = $this->composer->composeFrame(null, $this->grid, fn($feature) => true,
    fn() => new ConsolePresentationSnapshot(8, 4, []));
  expect($frame->world)->toBeNull()->and($frame->sprites)->toBe([])->and($frame->worldCanvases)->toBe([])
    ->and($frame->viewport)->toBeNull()->and($frame->screenOverlay)->toBeNull()->and($frame->canvas)->toBeNull();
});
