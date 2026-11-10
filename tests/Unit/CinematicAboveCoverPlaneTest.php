<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Field\FieldEffectSprite;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFieldPlane;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Cinematics\FieldAnimationOperation;
use Ichiloto\Engine\Cutscenes\Cinematics\StagedActor;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicAboveCoverPresentation;
use Ichiloto\Engine\Events\Interpreter\Commands\PlayStageCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** A field scene presented by an isolated graphical host, with a 48-pixel grid and no zoom. */
final class AboveCoverScene extends GameScene
{
  public function __construct(string $root, Closure $supports, bool $graphical = true)
  {
    $grid = new RendererGridConfig(28, 15, 48, 48);
    $this->currentMapId = 'synthetic-cover-map';
    $this->gameState = new GameState();
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
    $this->camera = new Camera($this, 28, 15, worldSpace: array_fill(0, 15, str_repeat('.', 28)));
    new ReflectionProperty(GameScene::class, 'presentationContext')->setValue($this,
      new ScenePresentationContext($grid, $supports, graphical: $graphical, assetRoot: $root));
    new ReflectionProperty(GameScene::class, 'fieldViewport')->setValue($this, new FieldViewport($grid));
  }

  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
}

function makeAboveCoverDimSource(): array
{
  return ['fps' => 10, 'lengthFrames' => 10, 'restFrame' => 4, 'tracks' => [],
    'stage' => ['canvas' => ['width' => 100, 'height' => 100], 'background' => null,
      'startFrame' => 0, 'restoreFrame' => 9,
      'camera' => [['id' => 'still', 'frame' => 0, 'focus' => ['x' => 50, 'y' => 50], 'zoom' => 1]],
      'covers' => [
        ['id' => 'begin', 'frame' => 0, 'color' => 'black', 'opacity' => .42, 'easing' => 'hold'],
        ['id' => 'clear', 'frame' => 9, 'color' => 'black', 'opacity' => 0],
      ]]];
}

/** @return list<string> */
function getAboveCoverProviderIds(GameScene $scene): array
{
  return array_map(static fn($provider): string => $provider->getGraphicalSpriteId(),
    iterator_to_array($scene->getGraphicalSpriteProviders(), false));
}

beforeEach(function () {
  $this->previousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => false]]);
  $this->root = createTestDirectory('above-cover');
  mkdir($this->root . '/Animations/dim', 0777, true);
  file_put_contents($this->root . '/Animations/dim/dim.timeline.php', '<?php return ' . var_export(makeAboveCoverDimSource(), true) . ';');
  writeTestPng($this->root . '/flare.png', 48, 48);
  writeTestPng($this->root . '/ring.png', 32, 16);
  $this->capabilities = null;
  $this->makeScene = fn(bool $graphical = true): AboveCoverScene => new AboveCoverScene($this->root,
    fn(string $capability): bool => $this->capabilities === null || in_array($capability, $this->capabilities, true), $graphical);
  $this->ring = new EffectTimelineLibrary($this->root)->compile('ring', ['fps' => 5, 'lengthFrames' => 10, 'tracks' => [[
    'id' => 'light', 'type' => 'image', 'asset' => 'ring.png', 'sheet' => ['columns' => 2, 'rows' => 1],
    'keyframes' => [['frame' => 0, 'sourceFrame' => 1]],
  ]]]);
});

afterEach(function () {
  $this->previousConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->previousConfig);
});

it('composites a staged actor and an effect authored above the cover over the active dim at full strength', function () {
  $scene = ($this->makeScene)();
  $dim = new PlayStageCommand()->execute(new ScriptCommandContext($scene), ['stage' => 'dim', 'seconds' => 2]);
  expect($dim->operation->update(.1))->toBeFalse();
  $scene->cinematicStage->add(['id' => 'flare', 'sprite' => '@', 'x' => 2, 'y' => 3, 'plane' => 'aboveCover',
    'sprites2d' => ['asset' => 'flare.png', 'layer' => 100]]);
  $scene->cinematicStage->add(['id' => 'onlooker', 'sprite' => 'V', 'x' => 5, 'y' => 3,
    'sprites2d' => ['asset' => 'flare.png']]);
  // Each lane holds its operation, as the interpreter does.
  $lanes = [new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(4, 6), plane: CinematicFieldPlane::ABOVE_COVER),
    new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(7, 6))];

  $overlay = $scene->getPresentationOverlay(1344, 720);
  $cover = array_find($overlay->composites, static fn($composite): bool => str_ends_with($composite->id, 'cinematic-stage-cover'));
  $images = array_column(array_map(static fn($image): array => [$image->id, $image], $overlay->images), 1, 0);
  $flare = $images['cinematic-above-cover:staged:flare'];
  $ring = array_values(array_filter($overlay->images,
    static fn($image): bool => str_starts_with($image->id, 'cinematic-above-cover:cinematic-effect:')));

  expect($cover->opacity)->toBe(.42)
    ->and(array_keys($images))->not->toContain('cinematic-above-cover:staged:onlooker')
    ->and($ring)->toHaveCount(1)
    // Over the dim, as the field sprite stands: its pivot on the cell's ground point.
    ->and($flare->layer)->toBeGreaterThan($cover->layer)->and($ring[0]->layer)->toBeGreaterThan($cover->layer)
    ->and($flare->opacity)->toBe(1.0)->and($ring[0]->opacity)->toBe(1.0)
    ->and([$flare->destination->x, $flare->destination->y, $flare->destination->width, $flare->destination->height])
    ->toBe([96.0, 144.0, 48.0, 48.0])
    ->and($ring[0]->sourceRect->x)->toBe(16)
    // The field effect stands above the actor in the field world, and keeps that order over the cover.
    ->and($ring[0]->layer)->toBeGreaterThan($flare->layer);
  $providers = iterator_to_array($scene->getGraphicalSpriteProviders(), false);
  expect(getAboveCoverProviderIds($scene))->toContain('staged:onlooker')->not->toContain('staged:flare')
    ->and(array_values(array_filter($providers, static fn($provider): bool => $provider instanceof FieldEffectSprite)))->toHaveCount(1)
    ->and($scene->getExcludedOverlayLayers())->toContain('staged:flare')->not->toContain('staged:onlooker');
});

it('keeps the default field plane under the dim, unchanged', function () {
  $scene = ($this->makeScene)();
  new PlayStageCommand()->execute(new ScriptCommandContext($scene), ['stage' => 'dim', 'seconds' => 2]);
  $scene->cinematicStage->add(['id' => 'flare', 'sprite' => '@', 'x' => 2, 'y' => 3,
    'sprites2d' => ['asset' => 'flare.png']]);
  new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(4, 6));

  $overlay = $scene->getPresentationOverlay(1344, 720);
  expect($overlay->images)->toBe([])->and($overlay->composites)->toHaveCount(1)
    ->and(getAboveCoverProviderIds($scene))->toContain('staged:flare')
    ->and(array_filter(iterator_to_array($scene->getGraphicalSpriteProviders(), false),
      static fn($provider): bool => $provider instanceof FieldEffectSprite))->toHaveCount(1)
    ->and($scene->getExcludedOverlayLayers())->not->toContain('staged:flare')
    ->and($scene->cinematicStage->all()[0]->plane)->toBe(CinematicFieldPlane::FIELD);
});

it('draws above-cover images in the field when the renderer cannot present the stage plane', function () {
  $this->capabilities = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY,
    RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::SPRITE_SOURCE_RECT];
  $scene = ($this->makeScene)();
  $scene->cinematicStage->add(['id' => 'flare', 'sprite' => '@', 'x' => 2, 'y' => 3, 'plane' => 'aboveCover',
    'sprites2d' => ['asset' => 'flare.png']]);
  new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(4, 6), plane: CinematicFieldPlane::ABOVE_COVER);

  expect($scene->getPresentationOverlay(1344, 720))->toBeNull()
    ->and(getAboveCoverProviderIds($scene))->toContain('staged:flare')
    ->and(array_filter(iterator_to_array($scene->getGraphicalSpriteProviders(), false),
      static fn($provider): bool => $provider instanceof FieldEffectSprite))->toHaveCount(1);
});

it('leaves the Terminal presentation to the shared glyphs on either plane', function () {
  $scene = ($this->makeScene)(graphical: false);
  $scene->cinematicStage->add(['id' => 'flare', 'sprite' => '@', 'x' => 2, 'y' => 3, 'plane' => 'aboveCover',
    'sprites2d' => ['asset' => 'flare.png']]);
  new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(4, 6), plane: CinematicFieldPlane::ABOVE_COVER);

  expect($scene->getPresentationOverlay(1344, 720))->toBeNull()
    ->and($scene->getExcludedOverlayLayers())->toBe([])
    ->and(iterator_to_array($scene->getGraphicalSpriteProviders(), false))->toBe([]);
});

it('places screen-pinned effects on the stage plane in the session text grid', function () {
  $scene = ($this->makeScene)();
  new FieldAnimationOperation($this->ring, $scene->cinematicPresentation, new Vector2(3, 2), screenSpace: true,
    plane: CinematicFieldPlane::ABOVE_COVER);
  $overlay = $scene->getPresentationOverlay(1344, 720);
  $image = $overlay->images[0];
  // A one-cell, 16x16 frame stretched to the 48-pixel cell, bottom-centred on grid cell (3, 2).
  expect([$image->destination->x, $image->destination->y, $image->destination->width, $image->destination->height])
    ->toBe([144.0, 96.0, 48.0, 48.0]);
});

it('refuses an unknown plane and a lifted world object takeover at authoring time', function () {
  CinematicScriptValidator::validateFieldAnimation(['effect' => 'synthetic', 'target' => ['kind' => 'player'],
    'plane' => 'aboveCover'], 'test', 'test');
  CinematicScriptValidator::validateStagedActor(['id' => 'flare', 'subject' => ['kind' => 'player'],
    'plane' => 'aboveCover'], 'test', 'test');
  expect(fn() => CinematicScriptValidator::validateFieldAnimation(['effect' => 'synthetic',
    'target' => ['kind' => 'player'], 'plane' => 'overDim'], 'test', 'test'))
    ->toThrow(InvalidArgumentException::class, '"test/plane": Field animation plane must be one of field, aboveCover.')
    ->and(fn() => CinematicScriptValidator::validateStagedActor(['id' => 'flare', 'sprite' => '@',
      'plane' => true], 'test', 'test'))
    ->toThrow(InvalidArgumentException::class, '"test/plane": Staged actor plane must be one of field, aboveCover.')
    ->and(fn() => CinematicScriptValidator::validateStagedActor(['id' => 'chest',
      'subject' => ['kind' => 'world_object', 'id' => 'chest'], 'plane' => 'aboveCover'], 'test', 'test'))
    ->toThrow(InvalidArgumentException::class, 'A world object takeover draws in the field');
  expect(CinematicCommandSchema::export()['fieldPlane'])->toMatchArray([
    'field' => 'plane', 'consumers' => ['field_animation', 'stage_actor'],
    'options' => ['field', 'aboveCover'], 'default' => 'field',
  ])->and(CinematicCommandSchema::STAGED_ACTOR_FIELDS)->toContain('plane');
});

it('places a lifted image where the field world draws its sprite, at any zoom', function () {
  $scene = ($this->makeScene)();
  $view = new FieldViewport(new RendererGridConfig(28, 15, 48, 48), 2);
  $sprite = new FieldEffectSprite('synthetic', new \Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition(
    'flare.png', 48, 48, lift: 6), new Vector2(5, 4));
  $origin = $scene->camera->getWorldOrigin();
  $world = \Ichiloto\Engine\Animations\Field\FieldSpriteBounds::findBounds($sprite, $view, $origin);
  $surface = \Ichiloto\Engine\Animations\Field\FieldSpriteBounds::findSurfaceBounds($sprite, $scene->camera, $view);
  $offsetY = (720 - $view->rows * 96) / 2;
  expect($offsetY)->toBeGreaterThan(0)
    ->and([$surface->left, $surface->top, $surface->right, $surface->bottom])->toBe([
      ($world->left - $origin['x'] * 48) * 2, $offsetY + ($world->top - $origin['y'] * 48) * 2,
      ($world->right - $origin['x'] * 48) * 2, $offsetY + ($world->bottom - $origin['y'] * 48) * 2,
    ]);
});
