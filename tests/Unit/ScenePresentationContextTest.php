<?php

use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Cinematics\StagedActor;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Constructor-isolated host: every presentation method remains the real GameScene implementation. */
final class ContextFieldSceneProbe extends GameScene
{
    public function __construct(string $root)
    {
        $this->camera = new Camera($this, 42, 17);
        $this->mapManager = makeBareScene(MapManager::class);
        new ReflectionProperty(MapManager::class, 'gameScene')->setValue($this->mapManager, $this);
        $this->cinematicStage = new CinematicStageManager($this);
        $this->cinematicPresentation = new CinematicPresentationManager($this);
        $this->fieldEffects = new FieldEffectManager($root);
        $this->replaceMap(new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic',
            implode("\n", array_fill(0, 30, str_repeat('.', 120))))]));
    }

    public function getGame(): Game { throw new LogicException('Preview must not acquire runtime Game services.'); }
    public function getUI(): UIManager { throw new LogicException('Preview must not acquire runtime UI services.'); }
    public function installPlayer(?Player $player): void { $this->player = $player; }
    public function installNpcs(NpcManager $npcs): void { $this->npcManager = $npcs; }
    public function replaceMap(?MapLayerSet $layers): void
    {
        foreach (['layers' => $layers, 'presentationWorld' => null, 'presentationWorldPolicy' => null] as $key => $value) {
            new ReflectionProperty(MapManager::class, $key)->setValue($this->mapManager, $value);
        }
        $this->camera->worldSpace = $layers?->getComposedGrid() ?? [];
    }
}

final class ContextDialogueOwnerProbe implements LayeredPresentationInterface, DialoguePresentationProviderInterface
{
    public function getPresentationBounds(): Rect { return new Rect(0, 20, 80, 10); }
    public function getPresentationPriority(): PresentationPriority { return PresentationPriority::MODAL; }
    public function getDialogueSnapshot(): DialogueSnapshot
    {
        return new DialogueSnapshot('Replaceable speaker', 'An authored page.', 'An authored page.',
            false, 0, 1, false, WindowPosition::BOTTOM);
    }
}

beforeEach(function () {
    $this->globals = [];
    foreach ([Console::class, InputManager::class, ConfigStore::class, Time::class, Debug::class] as $class) {
        $this->globals[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->root = createTestDirectory('ichiloto-scene-context-');
    Debug::configure(['log_directory' => $this->root]);
    putSceneAudioConfig(['graphics' => ['field' => ['zoom' => 1]], 'accessibility' => ['reducedMotion' => false]]);
    writeTestPng($this->root . '/Graphics/strip.png', 8, 2);
    writeTestPng($this->root . '/Graphics/edge.png', 3, 3);
    mkdir($this->root . '/Animations/energy', 0700, true);
    file_put_contents($this->root . '/Animations/energy/energy.timeline.php', '<?php return ' . var_export([
        'fps' => 4, 'lengthFrames' => 4, 'playback' => 'loop', 'restFrame' => 2,
        'tracks' => [['id' => 'motes', 'type' => 'image', 'asset' => 'Graphics/strip.png',
            'sheet' => ['columns' => 4, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1],
            'depth' => 'front', 'keyframes' => array_map(static fn($frame) => ['frame' => $frame,
                'sourceFrame' => $frame], range(0, 3))]]], true) . ';');
    mkdir($this->root . '/Data/Presentation', 0700, true);
    $edges = array_fill_keys(FieldPresentationCatalog::DIRECTIONS, ['asset' => 'Graphics/edge.png']);
    $edges['east']['quarterTurns'] = 1;
    file_put_contents($this->root . '/Data/Presentation/field.php', '<?php return ' . var_export([
        'cues' => ['bright-yellow' => ['effect' => 'energy', 'edges' => $edges]]], true) . ';');
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1', 'theme' => ['schema' => 'ichiloto.menu/1']], true) . ';');
    $this->scene = new ContextFieldSceneProbe($this->root);
    $this->player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
        ->onlyMethods(['getGraphicalSpriteDefinition'])->getMock();
    $this->player->method('getGraphicalSpriteDefinition')->willReturn(new GraphicalSpriteDefinition(
        'Graphics/Replaceable.png', 48, 96, sourceRect: new SpriteSourceRect(48, 0, 48, 96), lift: 6));
    new ReflectionProperty(Player::class, 'position')->setValue($this->player, new Vector2(7, 4));
    // Preview presentation is not gameplay activation; the host explicitly owns field eligibility.
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, false);
    $this->scene->installPlayer($this->player);
    $this->grid = new RendererGridConfig(80, 30, 16, 24);
    $this->owners = [];
    $this->seconds = 0.0;
    $this->capabilities = fn($feature) => true;
    $this->createContext = fn(bool $graphical = true, bool $fieldActive = true) => new ScenePresentationContext(
        $this->grid, $this->capabilities, $graphical, $fieldActive,
        collectPresentations: fn() => $this->owners, readTime: fn() => $this->seconds);
    $this->composer = new SceneFrameComposer($this->root);
    $this->captures = [];
    $this->capture = function (array $excluded, array $worldIds): ConsolePresentationSnapshot {
        $this->captures[] = [$excluded, $worldIds];
        $layers = [new PresentationTextLayer('world', 0, [new PresentationTextRun(0, 0, '.')]),
            new PresentationTextLayer('cinematic-cover', 3000, [new PresentationTextRun(0, 0, '#')]),
            ...array_map(static fn($owner) => new PresentationTextLayer('ui:' . spl_object_id($owner), 1020,
                [new PresentationTextRun(20, 0, 'An authored page.')]), $this->owners)];
        return new ConsolePresentationSnapshot($this->grid->columns, $this->grid->rows,
            array_values(array_filter($layers, static fn($layer) => !in_array($layer->id, [...$excluded, ...$worldIds], true))));
    };
    $this->compose = function () {
        $context = $this->scene->getPresentationContext();
        return $this->composer->composeFrame($this->scene, $context->grid, $context->supports(...), $this->capture);
    };
});

afterEach(function () {
    foreach ($this->globals as $class => $values) {
        foreach ($values as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
});

it('selects the real retained field without a FieldState, gameplay activation or runtime services', function () {
    expect($this->scene->getPresentationWorld())->toBeNull()
        ->and(iterator_to_array($this->scene->getGraphicalSpriteProviders()))->toBe([]);
    $before = [];
    foreach ([Console::class, InputManager::class, Time::class, ConfigStore::class] as $class) {
        $before[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->scene->setPresentationContext(($this->createContext)());
    $frame = ($this->compose)();
    expect($this->scene->state)->toBeNull()->and($this->scene->fieldState)->toBeNull()
        ->and($this->player->isActive)->toBeFalse()->and($this->scene->isGraphicalFieldPresented())->toBeTrue()
        ->and([$this->scene->camera->screen->getWidth(), $this->scene->camera->screen->getHeight()])->toBe([26, 15])
        ->and($frame->world)->toBe($this->scene->mapManager->getPresentationWorld())
        ->and($frame->sprites)->toHaveCount(1)->and($frame->sprites[0]->id)->toBe('player')
        ->and($frame->sprites[0]->sourceRect->toArray())->toBe(['x' => 48, 'y' => 0, 'width' => 48, 'height' => 96])
        ->and($frame->sprites[0]->lift)->toBe(6)->and($frame->viewport->spriteIds)->toBe(['player'])
        ->and($frame->viewport->worldId)->toBe($frame->world->id);
    foreach ($before as $class => $values) {
        expect(new ReflectionClass($class)->getStaticProperties())->toBe($values);
    }
});

it('keeps isolated dialogue transparent over the real field and its held cinematic cover', function () {
    $this->owners = [new ContextDialogueOwnerProbe()];
    $this->scene->setPresentationContext(($this->createContext)());
    $this->scene->cinematicPresentation->hideField('#');
    $frame = ($this->compose)();
    expect($frame->world)->not->toBeNull()->and($frame->viewport)->not->toBeNull()
        ->and($frame->sprites)->toHaveCount(1)->and($frame->canvas)->not->toBeNull()
        ->and($frame->canvas->images)->toBe([])
        ->and(array_column($frame->canvas->textLayers, 'id'))->toContain('dialogue-text')
        ->and($frame->screenOverlay->composites[0]->opacity)->toBe(1.0)
        ->and($this->captures[0][0])->toContain('ui:' . spl_object_id($this->owners[0]), 'cinematic-cover', 'transition');
    $this->owners = [];
    $this->scene->cinematicPresentation->clear();
    $cleared = ($this->compose)();
    expect($cleared->canvas)->toBeNull()->and($cleared->screenOverlay)->toBeNull()
        ->and($cleared->world)->toBe($frame->world)->and($cleared->sprites)->toEqual($frame->sprites);
});

it('uses the host playhead for tiles and existing effect sessions without advancing during inspection or pause', function (bool $reduced) {
    putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reduced]]);
    $this->scene->setPresentationContext(($this->createContext)());
    $world = $this->scene->getPresentationWorld();
    $animated = makeBareScene(PresentationWorld::class);
    new ReflectionMethod(PresentationWorld::class, '__construct')->invoke($animated,
        $world->id, $world->operations, $world->textLayerIds, $world->estimatedSourceBytes, true);
    new ReflectionProperty(MapManager::class, 'presentationWorld')->setValue($this->scene->mapManager, $animated);
    $this->scene->fieldEffects->startEffect('attached', 'energy', FieldEffectAnchor::fromArray(['object' => 'player']));
    $this->scene->advanceFieldPresentation(0.25);
    $this->seconds = 1.25;
    $before = new ReflectionClass(Time::class)->getStaticProperties();
    $frame = ($this->compose)();
    $paused = ($this->compose)();
    $effect = array_find($frame->sprites, static fn($sprite) => str_starts_with($sprite->id, 'field-effect:'));
    expect($frame->viewport->tileFrame)->toBe($reduced ? 0 : 2)
        ->and($effect->sourceRect->x)->toBe($reduced ? 4 : 2)
        ->and($paused)->toEqual($frame)->and($this->scene->fieldEffects->count)->toBe(1)
        ->and(new ReflectionClass(Time::class)->getStaticProperties())->toBe($before);
    $this->seconds = 0.0;
    expect(($this->compose)()->viewport->tileFrame)->toBe(0);
})->with([false, true]);

it('advances player, NPC and staged visuals together without completing a gameplay arrival', function () {
    $this->scene->setPresentationContext(($this->createContext)());
    $walk = new CharacterWalkAnimation();
    $walk->step(new CharacterStep(new Vector2(6, 4), $this->player->position, 0.5));
    new ReflectionProperty(Player::class, 'walkAnimation')->setValue($this->player, $walk);
    $arrivals = 0;
    new ReflectionProperty(Player::class, 'pendingArrival')->setValue($this->player,
        function () use (&$arrivals) { $arrivals++; });
    $npcs = $this->getMockBuilder(NpcManager::class)->disableOriginalConstructor()
        ->onlyMethods(['advanceGraphicalAnimation', 'getGraphicalSpriteProviders'])->getMock();
    $npcs->expects($this->exactly(2))->method('advanceGraphicalAnimation')->with(0.25);
    $npcs->method('getGraphicalSpriteProviders')->willReturn([]);
    $this->scene->installNpcs($npcs);
    $actor = new StagedActor('pulse', ['P'], new Vector2(9, 4),
        graphicalSprites: FieldPoseAnimation::fromArray(['asset' => 'Graphics/strip.png',
            'animation' => ['columns' => 4, 'rows' => 1, 'frames' => [0, 1, 2, 3], 'fps' => 4]]),
        assetRoot: $this->root);
    new ReflectionProperty(CinematicStageManager::class, 'actors')->setValue($this->scene->cinematicStage, ['pulse' => $actor]);
    $this->scene->fieldEffects->startEffect('attached', 'energy', FieldEffectAnchor::fromArray(['object' => 'player']));
    $before = [];
    foreach ([Console::class, InputManager::class, Time::class, ConfigStore::class] as $class) {
        $before[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->scene->advanceFieldPresentation(0.25);
    expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(2)
        ->and($walk->isSliding)->toBeTrue()->and($arrivals)->toBe(0);
    $this->scene->advanceFieldPresentation(0.25);
    expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4)
        ->and($walk->isSliding)->toBeFalse()->and($arrivals)->toBe(0)
        ->and([$this->player->position->x, $this->player->position->y])->toBe([7.0, 4.0])
        ->and($this->scene->state)->toBeNull()->and($this->scene->fieldState)->toBeNull();
    foreach ($before as $class => $values) {
        expect(new ReflectionClass($class)->getStaticProperties())->toBe($values);
    }
});

it('refuses invalid presentation deltas before mutation and does not advance a stopping scene', function () {
    $this->scene->setPresentationContext(($this->createContext)());
    $this->scene->fieldEffects->startEffect('attached', 'energy', FieldEffectAnchor::fromArray(['object' => 'player']));
    $before = ($this->compose)();
    foreach ([-0.1, NAN, INF, -INF] as $seconds) {
        expect(fn() => $this->scene->advanceFieldPresentation($seconds))->toThrow(InvalidArgumentException::class)
            ->and(($this->compose)())->toEqual($before);
    }
    new ReflectionProperty(GameScene::class, 'isStopping')->setValue($this->scene, true);
    $this->scene->advanceFieldPresentation(0.25);
    new ReflectionProperty(GameScene::class, 'isStopping')->setValue($this->scene, false);
    expect(($this->compose)())->toEqual($before);
    $this->scene->advanceFieldPresentation(0.25);
    expect(($this->compose)())->not->toEqual($before);
});

it('keeps story edge cues screen-pinned and degrades missing turns and image support through existing field rules', function () {
    $cue = new ScriptEventTrigger(new Rect(100, 7, 1, 1), ['mode' => 'action', 'reusable' => false],
        mapId: 'synthetic', marker: 'E', cue: ['symbol' => '!', 'color' => 'bright-yellow', 'kind' => 'story']);
    $this->scene->fieldEffects->installMap('synthetic', [], null, [$cue]);
    $this->scene->setPresentationContext(($this->createContext)());
    $frame = ($this->compose)();
    $edge = array_find($frame->sprites, static fn($sprite) => str_ends_with($sprite->id, ':edge'));
    expect($edge)->not->toBeNull()->and($edge->quarterTurns)->toBe(1)
        ->and($frame->viewport->spriteIds)->not->toContain($edge->id)
        ->and($edge->x)->toBeGreaterThanOrEqual(0)->and($edge->x)->toBeLessThan($this->grid->columns);
    $this->capabilities = fn($feature) => $feature !== RendererSessionConfig::SPRITE_QUARTER_TURNS;
    $this->scene->setPresentationContext(($this->createContext)());
    $degraded = ($this->compose)();
    expect(array_column($degraded->sprites, 'id'))->not->toContain($edge->id)
        ->and($degraded->sprites)->toHaveCount(2);
    $this->capabilities = fn($feature) => $feature !== RendererSessionConfig::SPRITE_SOURCE_RECT;
    $this->scene->setPresentationContext(($this->createContext)());
    expect(($this->compose)()->sprites)->toHaveCount(1)->and($this->scene->fieldEffects->canPresentCue($cue))->toBeFalse()
        ->and($this->scene->fieldEffects->count)->toBe(1);
});

it('keeps Terminal previews image-free and never conceals the original cover or dialogue layers', function () {
    $this->owners = [new ContextDialogueOwnerProbe()];
    $this->scene->cinematicPresentation->hideField('#');
    $this->scene->setPresentationContext(($this->createContext)(graphical: false));
    $frame = ($this->compose)();
    expect($this->scene->isGraphicalFieldPresented())->toBeFalse()
        ->and([$this->scene->camera->screen->getWidth(), $this->scene->camera->screen->getHeight()])->toBe([80, 30])
        ->and($frame->world)->toBeNull()->and($frame->viewport)->toBeNull()->and($frame->sprites)->toBe([])
        ->and($frame->canvas)->toBeNull()->and($frame->screenOverlay)->toBeNull()
        ->and($this->captures[0][0])->toBe([])
        ->and(array_column($frame->snapshot->textLayers, 'id'))->toContain('cinematic-cover', 'ui:' . spl_object_id($this->owners[0]));
});

it('gates covers and field eligibility independently without inventing a legacy viewport capability requirement', function () {
    $this->capabilities = fn($feature) => !in_array($feature,
        [RendererSessionConfig::FRAME_VIEWPORT, RendererSessionConfig::CANVAS_COMPOSITING, RendererSessionConfig::FIELD_MOTION], true);
    $this->scene->setPresentationContext(($this->createContext)());
    $this->scene->cinematicPresentation->hideField('#');
    $frame = ($this->compose)();
    expect($frame->viewport)->not->toBeNull()->and($frame->viewport->follow)->toBeNull()
        ->and($frame->world)->not->toBeNull()->and($frame->screenOverlay)->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
    $this->scene->setPresentationContext(($this->createContext)(fieldActive: false));
    $inactive = ($this->compose)();
    expect($inactive->world)->toBeNull()->and($inactive->viewport)->toBeNull()->and($inactive->sprites)->toBe([]);
});

it('leaves runtime field identity and player activation requirements unchanged after context detachment', function () {
    $field = makeBareScene(FieldState::class);
    new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $field);
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
    expect($this->scene->getPresentationWorld())->toBeNull();
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $world = $this->scene->getPresentationWorld();
    expect($world)->not->toBeNull();
    $this->scene->setPresentationContext(($this->createContext)(fieldActive: false));
    expect($this->scene->getPresentationWorld())->toBeNull();
    $this->scene->setPresentationContext(null);
    expect($this->scene->getPresentationWorld())->toBe($world);
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, makeBareScene(FieldState::class));
    expect($this->scene->getPresentationWorld())->toBeNull();
});

it('does not request a state-owned graphical canvas for explicit Terminal presentation', function () {
    $canvas = new PresentationCanvas(1280, 720);
    $state = $this->getMockBuilder(MainMenuState::class)->disableOriginalConstructor()
        ->onlyMethods(['getPresentationCanvas'])->getMock();
    $state->expects($this->once())->method('getPresentationCanvas')->willReturn($canvas);
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $state);
    expect($this->scene->getPresentationCanvas())->toBe($canvas);
    $this->scene->setPresentationContext(($this->createContext)(graphical: false));
    expect($this->scene->getPresentationCanvas())->toBeNull()
        ->and($this->scene->getPresentationOverlay(1280, 720))->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
});

it('retains map ownership across transfer and restores local text geometry on unload and detach', function () {
    $this->scene->setPresentationContext(($this->createContext)());
    $stage = $this->scene->cinematicStage->add(['id' => 'actor', 'sprite' => 'A', 'x' => -1, 'y' => 2,
        'sprites2d' => ['asset' => 'Graphics/Replaceable.png', 'layer' => 10]]);
    $before = ($this->compose)();
    expect(array_column($before->sprites, 'id'))->toContain($stage->getGraphicalSpriteId())
        ->and($before->viewport->spriteIds)->toContain($stage->getGraphicalSpriteId());
    $this->scene->cinematicPresentation->hideField('#');
    $this->scene->cinematicStage->clear();
    $this->scene->fieldEffects->clear();
    $this->scene->replaceMap(new MapLayerSet([new MapLayer('terrain', 0, false, 'replacement', 'xx')]));
    $this->scene->synchronizeFieldViewport();
    $after = ($this->compose)();
    expect($after->world)->not->toBe($before->world)->and($after->sprites)->toHaveCount(1)
        ->and($after->screenOverlay->composites[0]->opacity)->toBe(1.0);
    $this->scene->replaceMap(null);
    $this->scene->synchronizeFieldViewport();
    expect(($this->compose)()->viewport)->toBeNull()
        ->and([$this->scene->camera->screen->getWidth(), $this->scene->camera->screen->getHeight()])->toBe([80, 30]);
    $this->scene->cinematicPresentation->clear();
    $this->scene->setPresentationContext(null);
    expect($this->scene->getPresentationContext())->toBeNull()->and($this->scene->getPresentationWorld())->toBeNull()
        ->and(new GraphicalSpriteCollector()->collect($this->scene))->toBe([])
        ->and([$this->scene->camera->screen->getWidth(), $this->scene->camera->screen->getHeight()])->toBe([42, 17]);
});

it('can present an owned empty field and retires the context even when unrelated shutdown fails', function () {
    $this->scene->installPlayer(null);
    $this->scene->setPresentationContext(($this->createContext)());
    expect(($this->compose)()->world)->not->toBeNull()->and(($this->compose)()->sprites)->toBe([]);
    // This deliberately isolated scene cannot run Camera's runtime UI stop hook.
    expect(fn() => $this->scene->stop())->toThrow(LogicException::class, 'Preview must not acquire runtime UI services.');
    expect($this->scene->getPresentationContext())->toBeNull()->and($this->scene->fieldEffects->count)->toBe(0)
        ->and($this->scene->cinematicStage->all())->toBe([])->and($this->scene->cinematicPresentation->hasTransitionCover())->toBeFalse();
    $this->scene->setPresentationContext(($this->createContext)());
    expect($this->scene->getPresentationWorld())->toBeNull()->and(new GraphicalSpriteCollector()->collect($this->scene))->toBe([]);
    $this->scene->setPresentationContext(null);
});

it('rejects invalid host presentation times instead of silently substituting a runtime clock', function ($seconds) {
    $context = new ScenePresentationContext($this->grid, fn() => true, readTime: fn() => $seconds);
    expect(fn() => $context->getPresentationTime())->toThrow(InvalidArgumentException::class);
})->with([-1, INF, NAN, '1']);

it('rejects untyped or unordered presentation owners', function ($owners) {
    $context = new ScenePresentationContext($this->grid, fn() => true, collectPresentations: fn() => $owners);
    expect(fn() => $context->getActivePresentations())->toThrow(InvalidArgumentException::class);
})->with([[['not-a-provider']], [['owner' => new ContextDialogueOwnerProbe()]]]);
