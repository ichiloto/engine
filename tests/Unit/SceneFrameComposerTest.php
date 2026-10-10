<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\ScreenSpaceSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

class CompositionSpriteProbe implements GraphicalSpriteProviderInterface
{
  public function __construct(public string $id, public Vector2 $position,
    public ?GraphicalSpriteDefinition $definition, public ?PresentationSpriteMotion $motion = null) {}
  public function getGraphicalSpriteId(): string { return $this->id; }
  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition { return $this->definition; }
  public function getGraphicalSpriteWorldPosition(): Vector2 { return $this->position; }
  public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion { return $this->motion; }
}

final class CompositionScreenSpriteProbe extends CompositionSpriteProbe implements ScreenSpaceSpriteProviderInterface {}

/** Uses the real projector, Camera, world and viewport contracts without a project interpreter. */
final class CompositionSceneProbe extends GameScene
{
  public array $providers = [];
  public ?PresentationWorld $world = null;
  public ?PresentationCanvas $canvas = null;
  public ?PresentationCanvas $cover = null;
  public int $refreshes = 0;
  public int $tileFrame = 7;
  public ?string $followId = 'actor';
  public array $screenIds = ['screen-effect'];
  public bool $invalidMembership = false;
  private array $textMembers = [];
  public function __construct(public RendererGridConfig $grid, UIManager $ui)
  {
    $this->uiManager = $ui;
    $this->camera = new Camera(makeCameraTestScene(), 8, 4,
      worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  }
  public function getGraphicalSpriteProviders(): iterable { return $this->providers; }
  public function getPresentationWorld(): ?PresentationWorld { return $this->world; }
  public function getPresentationCanvas(): ?PresentationCanvas { return $this->canvas; }
  public function getPresentationOverlay(int $width, int $height): ?PresentationCanvas { return $this->cover; }
  public function renderPresentationOverlay(): void { $this->refreshes++; }
  public function getExcludedOverlayLayers(): array { return ['cinematic-cover']; }
  public function getPresentationViewport(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites, array $tiles = []): ?PresentationViewport
  {
    // As in GameScene, the scene owns complete membership when the consumer supplies only changed rows.
    if ($snapshot instanceof ConsolePresentationSnapshot) {
      $this->textMembers = array_column($snapshot->textLayers, null, 'id');
    } else {
      if ($snapshot->reset) { $this->textMembers = []; }
      foreach ($snapshot->removedIds as $id) { unset($this->textMembers[$id]); }
      foreach ($snapshot->layers as $layer) {
        $this->textMembers[$layer['id']] = new PresentationTextLayer($layer['id'], $layer['layer'], []);
      }
    }
    $viewport = $this->world === null ? null : new FieldViewport($this->grid)->createViewport(array_values($this->textMembers),
      $sprites, $tiles, $this->world->id, $this->camera->getWorldOrigin(), $this->tileFrame,
      $this->followId, $this->screenIds);
    return $this->invalidMembership ? new PresentationViewport(1, 0, 0, $viewport->clipRect,
      spriteIds: ['not-in-frame']) : $viewport;
  }
}

final class CompositionNoticesProbe extends NotificationManager
{
  public function __construct(public PresentationCanvas $notice) {}
  public function __destruct() {}
  public function getExcludedPresentationLayers(): array { return ['notice']; }
  public function composePresentation(?PresentationCanvas $base, int $width, int $height, array $protected = []): ?PresentationCanvas
  {
    return $base === null ? $this->notice : PresentationCanvas::composeOverlay($base, $this->notice);
  }
}

function createCompositionTextCanvas(string $id, int $width, int $height): PresentationCanvas
{
  return new PresentationCanvas($width, $height, textLayers: [new CanvasTextLayer($id, 1, 0, 0,
    new RendererGridConfig(1, 1, 16, 24), [new PresentationTextRun(0, 0, '*')])]);
}

function createCompositionPresenter(string $root, RendererGridConfig $grid): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Isolated synthetic preview', $root, $grid, RendererProtocolVersion::V2);
  $client->start($session);
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $session->getNegotiableCapabilities()]))];
  $client->pump();
  return [new RendererPresentation($client, $grid), $transport];
}

beforeEach(function () {
  $this->states = [];
  foreach ([Console::class, Debug::class, InputManager::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = createTestDirectory('ichiloto-composition-');
  Debug::configure(['log_directory' => $this->root]);
  $this->grid = new RendererGridConfig(8, 4, 48, 48);
  $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()
    ->onlyMethods(['getActivePresentations'])->getMock();
  $ui->method('getActivePresentations')->willReturn([]);
  $this->scene = new CompositionSceneProbe($this->grid, $ui);
  $this->scene->world = PresentationWorld::getFromLayers(new MapLayerSet([
    new MapLayer('terrain', 0, false, 'synthetic', '....')]), 'synthetic-world');
  $this->scene->camera->moveTo(3, 2);
  $definition = GraphicalSpriteDefinition::fromArray(['asset' => 'Replaceable/Actor.png',
    'layer' => 10, 'sourceRect' => ['x' => 48, 'y' => 96, 'width' => 48, 'height' => 96],
    'cells' => ['width' => 1, 'height' => 2]]);
  $definition = new GraphicalSpriteDefinition($definition->asset, $definition->width, $definition->height,
    layer: $definition->layer, sourceRect: $definition->sourceRect, lift: 6, quarterTurns: 1);
  $this->actor = new CompositionSpriteProbe('actor', new Vector2(7, 4), $definition, new PresentationSpriteMotion(0.2));
  $this->scene->providers = [$this->actor,
    new CompositionSpriteProbe('edge', new Vector2(2, 3), $definition),
    new CompositionScreenSpriteProbe('screen-effect', new Vector2(1, 1), $definition),
    new CompositionSpriteProbe('terminal-only', new Vector2(6, 3), null)];
  $this->text = array_map(static fn($id) => new PresentationTextLayer($id,
    str_starts_with($id, 'ui:') ? 1000 : 1, [new PresentationTextRun(0, 0, '.')]),
    ['map:terrain', 'actor', 'edge', 'screen-effect', 'terminal-only', 'ui:dialogue']);
  $this->captures = [];
  $this->capture = function (array $excluded, array $worldLayers): ConsolePresentationSnapshot {
    $this->captures[] = [$excluded, $worldLayers];
    return new ConsolePresentationSnapshot($this->grid->columns, $this->grid->rows,
      array_values(array_filter($this->text, static fn($layer) => !in_array($layer->id, [...$excluded, ...$worldLayers], true))));
  };
  $this->composer = new SceneFrameComposer($this->root);
});

afterEach(function () {
  foreach ($this->states as $class => $state) {
    foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

it('shares Camera projection and map identity while retaining field-edge and screen-pinned sprites', function () {
  $camera = $this->scene->camera->captureState();
  $frame = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  $actor = array_find($frame->sprites, fn($sprite) => $sprite->id === 'actor');
  $edge = array_find($frame->sprites, fn($sprite) => $sprite->id === 'edge');
  $screen = array_find($frame->sprites, fn($sprite) => $sprite->id === 'screen-effect');
  expect([$actor->x, $actor->y])->toBe([4, 2])->and([$edge->x, $edge->y])->toBe([-1, 1])
    ->and([$screen->x, $screen->y])->toBe([1, 1])->and($actor->sourceRect)->toBe($this->actor->definition->sourceRect)
    ->and($actor->motion)->toBe($this->actor->motion)->and($actor->lift)->toBe(6)->and($actor->quarterTurns)->toBe(1)
    ->and($frame->world)->toBe($this->scene->world)
    ->and($frame->viewport->worldOriginX)->toBe(3)->and($frame->viewport->worldOriginY)->toBe(2)
    ->and($frame->viewport->spriteIds)->toBe(['actor', 'edge'])->and($frame->viewport->follow->spriteId)->toBe('actor')
    ->and($frame->viewport->tileFrame)->toBe(7)->and($this->captures[0][0])->toBe(['actor', 'screen-effect'])
    ->and($this->captures[0][1])->toBe($frame->world->textLayerIds)
    ->and(array_column($frame->snapshot->textLayers, 'id'))->toBe(['edge', 'terminal-only', 'ui:dialogue'])
    ->and($this->scene->camera->captureState())->toEqual($camera);
  $this->actor->definition = GraphicalSpriteDefinition::fromArray(['asset' => 'Other/Replacement.png', 'layer' => 10]);
  $replacement = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  expect($replacement->world)->toBe($frame->world)
    ->and(array_find($replacement->sprites, fn($sprite) => $sprite->id === 'actor')->asset)->toBe('Other/Replacement.png');
});

it('keeps reduced-motion provider choices and degrades unsupported slides without changing committed cells', function (bool $reduced) {
  if ($reduced) { $this->actor->motion = null; $this->scene->tileFrame = 0; $this->scene->followId = null; }
  $normal = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  $degraded = $this->composer->composeFrame($this->scene, $this->grid,
    fn($feature) => $feature !== RendererSessionConfig::FIELD_MOTION, $this->capture);
  expect($degraded->viewport->follow)->toBeNull()->and($degraded->viewport->tileFrame)->toBe($reduced ? 0 : 7)
    ->and(array_column($degraded->sprites, 'motion'))->toBe([null, null, null])
    ->and(array_column($degraded->sprites, 'x'))->toBe(array_column($normal->sprites, 'x'))
    ->and(array_column($degraded->sprites, 'y'))->toBe(array_column($normal->sprites, 'y'));
})->with([false, true]);

it('collects an isolated layered field through the production Console capture boundary', function () {
  $prior = new ReflectionClass(Console::class)->getStaticProperties();
  $draw = static function (): void {
    Console::withLayer('map:terrain', fn() => Console::write('....', 0, 0));
    Console::withLayer('actor', fn() => Console::write('A', 4, 2), 100);
    Console::withLayer('terminal-only', fn() => Console::write('T', 3, 1), 100);
    Console::withLayer('ui:dialogue', fn() => Console::write('Speak', 0, 3), 1000);
  };
  $frame = $this->composer->composeFrame($this->scene, $this->grid, fn($capability) => true,
    fn(array $excluded, array $worldLayers) => Console::capturePresentation($this->grid->columns,
      $this->grid->rows, $draw, $excluded, $worldLayers, true));
  expect(array_column($frame->snapshot->textLayers, 'id'))->toBe(['world', 'terminal-only', 'ui:dialogue'])
    ->and($frame->snapshot->textLayers[0]->runs)->toBe([])
    ->and($frame->snapshot->textLayers[1]->runs[0]->text)->toBe('T')
    ->and($frame->snapshot->textLayers[2]->runs[0]->text)->toBe('Speak')
    ->and($frame->viewport->spriteIds)->toBe(['actor', 'edge'])
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($prior);
});

it('composes full preview snapshots without consuming runtime text deltas or acquiring input', function () {
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  Console::setTerminalOutputEnabled(false);
  Console::setLayerTracking(true);
  Console::syncDimensions(8, 4);
  Console::getRetainedPresentationChanges(reset: true);
  Console::withLayer('runtime-only', fn() => Console::write('LIVE', 0, 0), 1000);
  $cursor = new ReflectionProperty(Console::class, 'retainedPresentation')->getValue();
  $input = InputManager::getInputSource();
  $frame = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  expect($frame->snapshot)->toBeInstanceOf(ConsolePresentationSnapshot::class)
    ->and(new ReflectionProperty(Console::class, 'retainedPresentation')->getValue())->toBe($cursor)
    ->and(InputManager::getInputSource())->toBe($input)
    ->and(array_column(Console::getRetainedPresentationChanges()->layers, 'id'))->toContain('runtime-only');
});

it('prepares the same retained field from full snapshots and reset changes without uploading during composition', function () {
  $full = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  $reset = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true,
    fn($excluded, $worldLayers) => new ConsolePresentationChanges(8, 4, true,
      array_map(fn($layer) => ['id' => $layer->id, 'layer' => $layer->layer,
        'rows' => [['row' => 0, 'runs' => $layer->runs]]], $full->snapshot->textLayers),
      order: array_column($full->snapshot->textLayers, 'id')));
  [$preview, $previewTransport] = createCompositionPresenter($this->root, $this->grid);
  [$runtime, $runtimeTransport] = createCompositionPresenter($this->root, $this->grid);
  $previewFrame = $full->prepareFrame($preview);
  $runtimeFrame = $reset->prepareFrame($runtime);
  expect($previewTransport->sent)->toBe([])->and($runtimeTransport->sent)->toBe([]);
  $preview->presentFrame($previewFrame, $full->screenOverlay);
  $runtime->presentFrame($runtimeFrame, $reset->screenOverlay);
  expect(RetainedFrameState::getLatestFrame($previewTransport->sent))->toBe(RetainedFrameState::getLatestFrame($runtimeTransport->sent));
  // Preview consumes the existing retained wire envelope, never serialized DTO/internal frame fields.
  $wire = json_decode($previewTransport->sent[0]->encode(), true, flags: JSON_THROW_ON_ERROR);
  expect($wire['protocol'])->toBe(2)->and($wire['type'])->toBe('frame')
    ->and($wire)->toHaveKeys(['generation', 'baseGeneration', 'frame', 'reset', 'present', 'operations', 'viewport'])
    ->and($wire)->not->toHaveKeys(['world', 'sprites', 'snapshot', 'values', 'textRows'])
    ->and($wire['reset'])->toBeTrue()->and($wire['present'])->toBeTrue()
    ->and(json_encode($wire['viewport'], JSON_THROW_ON_ERROR))
      ->toBe(json_encode($full->viewport->toArray(), JSON_THROW_ON_ERROR));
  $worldPut = array_find($wire['operations'], fn($operation) => $operation['op'] === 'put' && $operation['kind'] === 'world');
  $actorPut = array_find($wire['operations'], fn($operation) => $operation['op'] === 'put'
    && $operation['kind'] === 'sprite' && $operation['id'] === 'actor');
  expect($worldPut['id'])->toBe($full->world->id)
    ->and(array_column($wire['operations'], 'op'))->toContain('worldRows')
    ->and($actorPut['value']['sourceRect'])->toBe($this->actor->definition->sourceRect->toArray())
    ->and($actorPut['value']['motion'])->toBe($this->actor->motion->toArray());
  $preview->acknowledge(1, true);
  expect($preview->presentFrame($full->prepareFrame($preview), $full->screenOverlay))->toBeFalse()
    ->and($previewTransport->sent)->toHaveCount(1);
});

it('keeps dialogue transparent over the field and screen cover and notices above it with terminal fallback', function (bool $graphics) {
  mkdir($this->root . '/Data/Presentation', 0700, true);
  file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
    'schema' => 'ichiloto.dialogue/1', 'theme' => ['schema' => 'ichiloto.menu/1']], true) . ';');
  $owner = $this->getMockBuilder(TextBoxModal::class)->disableOriginalConstructor()
    ->onlyMethods(['getDialogueSnapshot'])->getMock();
  $owner->method('getDialogueSnapshot')->willReturn(new DialogueSnapshot('Synthetic speaker', 'Synthetic page.',
    'Synthetic page.', false, 0, 1, false, WindowPosition::BOTTOM, new DialogueContext()));
  $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()
    ->onlyMethods(['getActivePresentations'])->getMock();
  $ui->method('getActivePresentations')->willReturn([$owner]);
  new ReflectionProperty(GameScene::class, 'uiManager')->setValue($this->scene, $ui);
  $this->grid = $this->scene->grid = new RendererGridConfig(80, 30, 16, 24);
  $this->scene->cover = createCompositionTextCanvas('cover', 1280, 720);
  $notices = new CompositionNoticesProbe(createCompositionTextCanvas('notice', 1280, 720));
  $frame = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => $graphics, $this->capture, $notices);
  expect($frame->world)->toBe($this->scene->world)->and($frame->sprites)->toHaveCount(3)
    ->and($this->captures[0][0])->toContain('notice');
  if ($graphics) {
    expect($frame->canvas)->not->toBeNull()->and($frame->snapshot)->not->toBeNull()
      ->and($frame->canvas->images)->toBe([])->and(array_column($frame->canvas->textLayers, 'id'))->not->toContain('menu-background')
      ->and($this->captures[0][0])->toContain('ui:' . spl_object_id($owner), 'cinematic-cover')
      ->and(array_column($frame->screenOverlay->textLayers, 'id'))->toBe(['cover', 'notice'])
      ->and($frame->screenOverlay->textLayers[1]->layer)->toBeGreaterThan($frame->screenOverlay->textLayers[0]->layer)
      ->and($this->scene->refreshes)->toBe(1);
    [$presenter, $transport] = createCompositionPresenter($this->root, $this->grid);
    $presenter->presentFrame($frame->prepareFrame($presenter), $frame->screenOverlay);
    $retained = RetainedFrameState::getLatestFrame($transport->sent);
    $dialogueLayer = array_find($retained['canvas']['textLayers'], fn($layer) => $layer['id'] === 'dialogue-text');
    $coverLayer = array_find($retained['canvas']['textLayers'], fn($layer) => $layer['id'] === 'cover');
    $noticeLayer = array_find($retained['canvas']['textLayers'], fn($layer) => $layer['id'] === 'notice');
    expect($retained['sprites'])->toHaveCount(3)->and($retained['worlds'])->toHaveCount(1)
      ->and($coverLayer['layer'])->toBeGreaterThan($dialogueLayer['layer'])
      ->and($noticeLayer['layer'])->toBeGreaterThan($coverLayer['layer']);
  } else {
    expect($frame->canvas)->toBeNull()->and($this->captures[0][0])->not->toContain('ui:' . spl_object_id($owner), 'cinematic-cover')
      ->and(array_column($frame->screenOverlay->textLayers, 'id'))->toBe(['notice'])->and($this->scene->refreshes)->toBe(0);
  }
})->with([true, false]);

it('replaces field inputs for full canvases without capturing text and resumes a clean terminal-only scene', function () {
  $this->scene->canvas = createCompositionTextCanvas('replacement', 384, 192);
  $this->scene->cover = createCompositionTextCanvas('cover', 384, 192);
  $frame = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true,
    fn() => throw new LogicException('Full canvases must not capture field text.'));
  expect($frame->snapshot)->toBeNull()->and($frame->world)->toBeNull()->and($frame->viewport)->toBeNull()
    ->and($frame->sprites)->toBe([])->and($frame->canvas)->toBe($this->scene->canvas)
    ->and($frame->screenOverlay)->toBe($this->scene->cover);
  [$presenter, $transport] = createCompositionPresenter($this->root, $this->grid);
  $presenter->presentFrame($frame->prepareFrame($presenter), $frame->screenOverlay);
  expect(RetainedFrameState::getLatestFrame($transport->sent)['canvas']['textLayers'])->toHaveCount(2);
  $this->scene->canvas = $this->scene->cover = null;
  $this->scene->providers = [];
  $this->scene->world = null;
  $terminal = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => false, $this->capture);
  expect($terminal->sprites)->toBe([])->and($terminal->canvas)->toBeNull()->and($terminal->screenOverlay)->toBeNull()
    ->and($terminal->viewport)->toBeNull()->and($terminal->world)->toBeNull()
    ->and(array_column($terminal->snapshot->textLayers, 'id'))->toContain('actor', 'map:terrain', 'terminal-only');
  $presenter->presentFrame($terminal->prepareFrame($presenter), $terminal->screenOverlay);
  expect(RetainedFrameState::getLatestFrame($transport->sent))->not->toHaveKeys(['canvas', 'viewport', 'worlds']);
});

it('removes stale field world sprites and cover on transfer rather than retaining them in the composer', function () {
  [$presenter, $transport] = createCompositionPresenter($this->root, $this->grid);
  $this->scene->cover = createCompositionTextCanvas('cover', 384, 192);
  $before = $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture);
  $presenter->presentFrame($before->prepareFrame($presenter), $before->screenOverlay);
  $after = $this->composer->composeFrame(null, $this->grid, fn($feature) => true,
    fn() => new ConsolePresentationSnapshot(8, 4, []));
  $presenter->presentFrame($after->prepareFrame($presenter), $after->screenOverlay);
  $latest = RetainedFrameState::getLatestFrame($transport->sent);
  expect($after->sprites)->toBe([])->and($after->world)->toBeNull()->and($after->viewport)->toBeNull()
    ->and($after->screenOverlay)->toBeNull()->and($latest['sprites'])->toBe([])
    ->and($latest)->not->toHaveKeys(['worlds', 'viewport', 'canvas']);
});

it('resynchronizes runtime text after composition validation fails and leaves ordinary deltas incremental', function () {
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(8, 4);
  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => [RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::FRAME_VIEWPORT,
      RendererSessionConfig::FIELD_MOTION, RendererSessionConfig::TILE_COVERS]]))];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['unused-headless']),
    $this->root, cellWidth: 48, cellHeight: 48), $transport);
  try {
    $runtime->start('Synthetic runtime', 8, 4);
    Console::withLayer('ui:kept', fn() => Console::write('BEFORE', 0, 0), 1000);
    expect($runtime->present($this->scene))->toBeTrue()->and($runtime->present($this->scene))->toBeFalse()
      ->and($this->scene->refreshes)->toBe(0);
    Console::withLayer('ui:kept', fn() => Console::write('AFTER!', 0, 0), 1000);
    $this->scene->invalidMembership = true;
    expect(fn() => $runtime->present($this->scene))->toThrow(InvalidArgumentException::class)
      ->and($transport->sent)->toHaveCount(1);
    $this->scene->invalidMembership = false;
    expect($runtime->present($this->scene))->toBeTrue()->and($runtime->present($this->scene))->toBeFalse();
    $latest = RetainedFrameState::getLatestFrame($transport->sent);
    expect(array_find($latest['textLayers'], fn($layer) => $layer['id'] === 'ui:kept')['runs'][0]['text'])->toBe('AFTER!')
      ->and($latest['sprites'])->toHaveCount(3)->and($latest['worlds'])->toHaveCount(1);
    $worldUploads = array_filter(array_merge(...array_column(array_column($transport->sent, 'payload'), 'operations')),
      fn($operation) => $operation['op'] === 'put' && ($operation['kind'] ?? '') === 'world');
    expect($worldUploads)->toHaveCount(1);
  } finally { $runtime->shutdown(); }
});

it('rejects mixed replacement and field ownership and invalid field layering instead of hiding them', function () {
  expect(fn() => new SceneFrameComposition(null))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new SceneFrameComposition(null, world: $this->scene->world, canvas: new PresentationCanvas(384, 192)))
      ->toThrow(InvalidArgumentException::class);
  $this->actor->definition = GraphicalSpriteDefinition::fromArray(['asset' => 'Replaceable/Actor.png', 'layer' => 1000]);
  expect(fn() => $this->composer->composeFrame($this->scene, $this->grid, fn($feature) => true, $this->capture))
    ->toThrow(InvalidArgumentException::class);
});
