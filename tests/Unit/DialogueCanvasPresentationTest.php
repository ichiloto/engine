<?php

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueCanvasPresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\SkitStageStyle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function writeDialogueTestPng(string $path, int $width = 48, int $height = 64): void
{
    $chunk = static fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xA0\xB0\xC0\xFF", $width), $height))) . $chunk('IEND', ''));
}

function getDialogueTestCatalog(string $root, bool $warm = false): DialoguePresentationCatalog
{
    $frame = ['asset' => 'frame.png', 'cuts' => [4, 4, 4, 4]];
    $theme = new MenuPresentationCatalog($root, ['schema' => 'ichiloto.menu/1',
        'colors' => $warm ? ['text' => [30, 20, 10], 'panel' => [230, 220, 200]] : [],
        'metrics' => ['cellWidth' => 10, 'cellHeight' => 28, 'rowHeight' => 40, 'panelPadding' => 28],
        'frames' => ['dialogue' => $frame, 'nameplate' => $frame, 'portrait' => $frame],
        'icons' => ['navigation.continue' => 'frame.png']]);
    return new DialoguePresentationCatalog([
        'hero' => ['portrait' => 'hero.png', 'bust' => 'hero.png',
            'emotions' => ['Happy' => ['portrait' => 'happy.png', 'bust' => 'happy.png']]],
        'friend' => ['portrait' => 'hero.png', 'bust' => 'hero.png'],
    ], $theme, ['Display Hero' => 'hero'], ['sample' => ['background' => 'background.png']]);
}

function getDialogueTestLine(string $speaker = 'Display Hero', ?DialogueContext $context = null,
    WindowPosition $position = WindowPosition::BOTTOM, bool $printing = false): DialogueSnapshot
{
    return new DialogueSnapshot($speaker, "A complete page.\nAnother line.", $printing ? 'A com' : "A complete page.\nAnother line.",
        $printing, 0, 2, false, $position, $context ?? new DialogueContext());
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ichiloto-dialogue-' . bin2hex(random_bytes(6));
    mkdir($this->root);
    foreach (['frame', 'hero', 'happy', 'background'] as $name) { writeDialogueTestPng($this->root . '/' . $name . '.png'); }
    $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
    $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
    $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
    Debug::configure(['log_directory' => $this->root]);
});

afterEach(function () {
    foreach ($this->debugState as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
    foreach ($this->consoleState as $key => $value) { new ReflectionProperty(Console::class, $key)->setValue(null, $value); }
    foreach ($this->inputState as $key => $value) { new ReflectionProperty(InputManager::class, $key)->setValue(null, $value); }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($this->root);
});

it('uses independent themes and actor roles without a whole-scene dialogue backing', function ($warm) {
    $catalogue = getDialogueTestCatalog($this->root, $warm);
    $canvas = DialogueCanvasPresentation::compose(getDialogueTestLine(context: new DialogueContext(emotion: 'Happy')), $catalogue);
    expect(array_column($canvas->images, 'asset'))->toContain('happy.png')
        ->and(array_column($canvas->textLayers, 'id'))->not->toContain('menu-background');
    $body = array_find($canvas->images, fn($image) => $image->id === 'dialogue-body-0-0');
    expect($body)->not->toBeNull()->and($body->destination->y)->toBeGreaterThan(400);
    $text = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-text');
    expect($text->runs[0]->foreground)->toBe($catalogue->theme->colors['text']);
    $backing = array_find($canvas->textLayers, fn($layer) => str_starts_with($layer->id, 'dialogue-portrait-backing'));
    expect($backing)->not->toBeNull()->and($backing->layer)->toBe(20);
})->with([false, true]);

it('loads non-actor speaker artwork without adding gameplay actor references', function () {
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1'],
        'actors' => ['hero' => ['portrait' => 'hero.png']],
        'resources' => ['innkeeper-art' => ['portrait' => 'hero.png',
            'emotions' => ['Happy' => ['portrait' => 'happy.png']]]],
        'speakers' => ['Innkeeper' => 'innkeeper-art'],
    ], true) . ';');
    $catalogue = DialoguePresentationCatalog::load($this->root);
    expect(array_keys($catalogue->actors))->toBe(['hero'])
        ->and(array_keys($catalogue->resources))->toBe(['innkeeper-art'])
        ->and($catalogue->resolveSpeakerId(null, 'Innkeeper'))->toBe('innkeeper-art')
        ->and($catalogue->resolveSpeakerId('hero', 'Innkeeper'))->toBe('hero')
        ->and($catalogue->getArtwork('innkeeper-art', 'Happy', 'portrait'))->toBe('happy.png')
        ->and($catalogue->getArtwork('innkeeper-art', 'Unknown', 'portrait'))->toBe('hero.png');
    $frame = DialogueCanvasPresentation::compose(getDialogueTestLine('Innkeeper', new DialogueContext(emotion: 'Happy')), $catalogue);
    expect(array_column($frame->images, 'asset'))->toContain('happy.png');
});

it('refuses ambiguous actor and non-actor artwork identities', function () {
    expect(fn() => new DialoguePresentationCatalog(['hero' => ['portrait' => 'hero.png']],
        resources: ['hero' => ['portrait' => 'happy.png']]))
        ->toThrow(InvalidArgumentException::class, 'must be distinct');
});

it('preserves authored help and the modal page count inside its panel', function () {
    $line = new DialogueSnapshot('Display Hero', 'A page.', 'A page.', false, 1, 3, false,
        WindowPosition::BOTTOM, new DialogueContext(), 'An authored interaction hint.');
    $canvas = DialogueCanvasPresentation::compose($line, getDialogueTestCatalog($this->root));
    $help = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-authored-help');
    $page = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-page');
    expect($help->runs[0]->text)->toBe('An authored interaction hint.')
        ->and($page->runs[0]->text)->toBe('2 / 3')
        ->and($help->y)->toBeLessThan($page->y)
        ->and($page->y + $page->grid->rows * $page->grid->cellHeight)->toBeLessThanOrEqual(692);
});

it('keeps typing layout stable and only shows the ready cue after typing', function () {
    $catalogue = getDialogueTestCatalog($this->root);
    $typing = DialogueCanvasPresentation::compose(getDialogueTestLine(printing: true), $catalogue);
    $complete = DialogueCanvasPresentation::compose(getDialogueTestLine(), $catalogue);
    $body = fn($canvas) => array_find($canvas->images, fn($image) => $image->id === 'dialogue-body-0-0')->destination;
    expect($body($typing))->toEqual($body($complete))
        ->and(array_column($typing->images, 'id'))->not->toContain('dialogue-ready-1-1')
        ->and(array_column($complete->images, 'id'))->toContain('dialogue-ready-1-1');
});

it('removes the portrait gutter for plain speakers missing art and narration', function () {
    $catalogue = getDialogueTestCatalog($this->root);
    $actor = DialogueCanvasPresentation::compose(getDialogueTestLine(), $catalogue);
    foreach (['Plain speaker', ''] as $speaker) {
        $plain = DialogueCanvasPresentation::compose(getDialogueTestLine($speaker, position: WindowPosition::TOP), $catalogue);
        expect(array_column($plain->images, 'id'))->not->toContain('dialogue-portrait-1-1');
        $prose = array_find($plain->textLayers, fn($layer) => $layer->id === 'dialogue-text');
        expect($prose->x)->toBeLessThan(array_find($actor->textLayers, fn($layer) => $layer->id === 'dialogue-text')->x)
            ->and($prose->y)->toBeLessThan(150);
    }
    unlink($this->root . '/hero.png');
    expect($catalogue->getArtwork('hero', 'Neutral', 'portrait'))->toBeNull();
    $missing = DialogueCanvasPresentation::compose(getDialogueTestLine(), $catalogue);
    expect(array_column($missing->images, 'id'))->not->toContain('dialogue-portrait-1-1');
});

it('preserves long speaker names and explicit top middle bottom positions', function ($position) {
    $canvas = DialogueCanvasPresentation::compose(getDialogueTestLine(str_repeat('Long speaker name ', 6), position: $position),
        getDialogueTestCatalog($this->root), 800, 600);
    $name = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-speaker');
    expect(trim(preg_replace('/\s+/', ' ', implode(' ', array_column($name->runs, 'text')))))->toBe(trim(str_repeat('Long speaker name ', 6)))
        ->and($name->grid->rows)->toBeGreaterThan(1);
})->with([WindowPosition::TOP, WindowPosition::MIDDLE, WindowPosition::BOTTOM]);

it('contains current artwork dimensions without mirroring or stale metadata', function () {
    $catalogue = getDialogueTestCatalog($this->root);
    $first = DialogueCanvasPresentation::compose(getDialogueTestLine(), $catalogue);
    writeDialogueTestPng($this->root . '/hero.png', 100, 200);
    $second = DialogueCanvasPresentation::compose(getDialogueTestLine(), $catalogue);
    $image = fn($canvas) => array_find($canvas->images, fn($item) => $item->id === 'dialogue-portrait-1-1');
    expect($image($second)->destination->width / $image($second)->destination->height)->toBe(0.5)
        ->and($image($second)->sourceRect->width)->toBe(100)
        ->and($image($second)->sourceRect->height)->toBe(200)
        ->and($image($first)->destination)->not->toEqual($image($second)->destination);
});

it('stages borderless busts with contextual backgrounds and removes redundant cast name labels', function (int $width, int $height) {
    $context = new DialogueContext('hero', 'Happy', 'sample', 'A scene', 'Town', [
        ['actorId' => 'hero', 'name' => 'Hero', 'emotion' => 'Happy'],
        ['actorId' => 'friend', 'name' => 'Friend', 'emotion' => 'Neutral'],
    ]);
    $canvas = DialogueCanvasPresentation::compose(getDialogueTestLine('Hero', $context), getDialogueTestCatalog($this->root), $width, $height);
    expect(array_column($canvas->images, 'id'))->toContain('skit-background', 'skit-bust-0', 'skit-bust-1')
        ->not->toContain('dialogue-portrait-1-1');
    $bust = array_find($canvas->images, fn($image) => $image->id === 'skit-bust-0');
    expect($bust->asset)->toBe('happy.png')->and($bust->opacity)->toBe(1.0)
        ->and($bust->brightness)->toBe(1.0)
        ->and($bust->sourceRect)->toBeNull()->and($bust->layer)->toBeLessThan(10);
    $inactive = array_find($canvas->images, fn($image) => $image->id === 'skit-bust-1');
    expect($inactive->brightness)->toBe(0.60)->and($inactive->opacity)->toBe(1.0)
        ->and($inactive->destination->height / $bust->destination->height)->toEqualWithDelta(0.92, 0.000001);
    $panel = array_find($canvas->images, fn($image) => $image->id === 'dialogue-body-0-1');
    foreach ([$bust, $inactive] as $image) {
        expect($image->destination->y + $image->destination->height)
            ->toEqualWithDelta($panel->destination->y + 1, 0.000001);
    }
    expect(array_column($canvas->textLayers, 'id'))->toContain('skit-title', 'skit-location');
    expect(array_any($canvas->textLayers, fn($layer) => preg_match('/^skit-(name|participant|active)-/', $layer->id) === 1))->toBeFalse();
    $speaker = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-speaker');
    expect($speaker->runs[0]->text)->toBe('Hero');
})->with([[1280, 720], [800, 480]]);

it('joins busts to an unskinned dialogue panel without changing source artwork', function () {
    $base = getDialogueTestCatalog($this->root);
    $catalogue = new DialoguePresentationCatalog($base->actors,
        new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']), skits: $base->skits);
    $context = new DialogueContext('hero', skitId: 'sample', participants: [
        ['actorId' => 'hero', 'name' => 'Hero', 'emotion' => 'Neutral'],
    ]);
    $canvas = DialogueCanvasPresentation::compose(getDialogueTestLine('Hero', $context), $catalogue);
    $bust = array_find($canvas->images, fn($image) => $image->id === 'skit-bust-0');
    $panel = array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-body');
    expect($bust->destination->y + $bust->destination->height)->toEqualWithDelta($panel->y + 1, 0.000001)
        ->and($bust->sourceRect)->toBeNull()->and($bust->clipRect)->toBeNull()
        ->and($bust->layer)->toBeLessThan($panel->layer);
});

it('changes active bust emphasis without moving centres baselines or assets', function (int $width, int $height) {
    $catalogue = getDialogueTestCatalog($this->root);
    $participants = [
        ['actorId' => 'hero', 'name' => 'Hero', 'emotion' => 'Neutral'],
        ['actorId' => 'friend', 'name' => 'Friend', 'emotion' => 'Neutral'],
    ];
    $make = fn($id) => DialogueCanvasPresentation::compose(getDialogueTestLine($id,
        new DialogueContext($id, skitId: 'sample', skitTitle: 'A scene', participants: $participants)), $catalogue, $width, $height);
    $first = $make('hero');
    $second = $make('friend');
    foreach ([0, 1] as $index) {
        $a = array_find($first->images, fn($image) => $image->id === 'skit-bust-' . $index);
        $b = array_find($second->images, fn($image) => $image->id === 'skit-bust-' . $index);
        $active = $index === 0 ? $a : $b;
        $inactive = $index === 0 ? $b : $a;
        expect($a->asset)->toBe($b->asset)->and($a->opacity)->toBe(1.0)->and($b->opacity)->toBe(1.0)
            ->and($active->brightness)->toBe(1.0)->and($inactive->brightness)->toBe(0.60)
            ->and($a->destination->x + $a->destination->width / 2)->toEqualWithDelta($b->destination->x + $b->destination->width / 2, 0.000001)
            ->and($a->destination->y + $a->destination->height)->toEqualWithDelta($b->destination->y + $b->destination->height, 0.000001)
            ->and($inactive->destination->width / $active->destination->width)->toEqualWithDelta(0.92, 0.000001)
            ->and($inactive->destination->height / $active->destination->height)->toEqualWithDelta(0.92, 0.000001);
    }
})->with([[1280, 720], [800, 480]]);

it('preserves image tone alpha and source geometry when composing a canvas overlay', function () {
    $catalogue = getDialogueTestCatalog($this->root);
    $bounds = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(20, 20, 48, 64);
    $source = new \Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect(0, 0, 48, 64);
    $image = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('dimmed-image', 'hero.png',
        $bounds, 5, $source, opacity: 0.8, clipRect: $bounds, brightness: 0.6);
    $overlay = new PresentationCanvas(1280, 720, [$image]);
    $frame = \Ichiloto\Engine\UI\Presentation\MenuCanvas::overlay(new PresentationCanvas(1280, 720), $overlay, $catalogue->theme);
    $combined = array_find($frame->images, fn($item) => $item->id === $image->id);
    expect($combined->brightness)->toBe(0.6)->and($combined->opacity)->toBe(0.8)
        ->and($combined->destination)->toEqual($bounds)->and($combined->sourceRect)->toEqual($source)
        ->and($combined->clipRect)->toEqual($bounds)->and($combined->layer)->toBe(6);
});

it('allows games to configure emphasis and retains scaling without unsupported image tone', function () {
    $base = getDialogueTestCatalog($this->root);
    $catalogue = new DialoguePresentationCatalog($base->actors, $base->theme, $base->speakers, $base->skits,
        new SkitStageStyle(0.85, 0.45));
    $context = new DialogueContext('hero', skitId: 'sample', participants: [
        ['actorId' => 'hero', 'name' => 'Hero', 'emotion' => 'Neutral'],
        ['actorId' => 'friend', 'name' => 'Friend', 'emotion' => 'Neutral'],
    ]);
    $line = getDialogueTestLine('Hero', $context);
    $normal = DialogueCanvasPresentation::compose($line, $catalogue);
    $legacy = DialogueCanvasPresentation::compose($line, $catalogue, supportsImageTone: false);
    $busts = fn($canvas) => array_values(array_filter($canvas->images, fn($image) => str_starts_with($image->id, 'skit-bust-')));
    expect($busts($normal)[1]->brightness)->toBe(0.45)
        ->and($busts($normal)[1]->destination->height / $busts($normal)[0]->destination->height)->toEqualWithDelta(0.85, 0.000001)
        ->and($busts($legacy)[1]->brightness)->toBe(1.0)
        ->and($busts($legacy)[1]->destination)->toEqual($busts($normal)[1]->destination)
        ->and($busts($legacy)[1]->opacity)->toBe(1.0);
});

it('validates skit emphasis configuration without accepting strings nulls or unknown options', function () {
    foreach ([['inactiveScale' => 0.49], ['inactiveScale' => 1.01], ['inactiveBrightness' => -0.1],
        ['inactiveBrightness' => INF], ['inactiveScale' => NAN], ['inactiveScale' => '0.9'],
        ['inactiveBrightness' => null], ['unknown' => 1]] as $data) {
        expect(fn() => SkitStageStyle::getFromArray($data))->toThrow(InvalidArgumentException::class);
    }
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ["schema" => "ichiloto.dialogue/1",
        "skitStage" => ["inactiveScale" => 0.88, "inactiveBrightness" => 0.5]];');
    expect(DialoguePresentationCatalog::load($this->root)->skitStage)->toEqual(new SkitStageStyle(0.88, 0.5));
});

it('honours negotiated image tone in scene composition and diagnoses its absence only once', function (bool $supportsTone) {
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1', 'theme' => ['schema' => 'ichiloto.menu/1'],
        'actors' => ['hero' => ['bust' => 'hero.png'], 'friend' => ['bust' => 'hero.png']],
        'skits' => ['sample' => ['background' => 'background.png']],
    ], true) . ';');
    $context = new DialogueContext('hero', skitId: 'sample', participants: [
        ['actorId' => 'hero', 'name' => 'Hero', 'emotion' => 'Neutral'],
        ['actorId' => 'friend', 'name' => 'Friend', 'emotion' => 'Neutral'],
    ]);
    $owner = $this->getMockBuilder(TextBoxModal::class)->disableOriginalConstructor()->onlyMethods(['getDialogueSnapshot'])->getMock();
    $owner->method('getDialogueSnapshot')->willReturn(getDialogueTestLine('Hero', $context));
    $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['getActivePresentations'])->getMock();
    $ui->method('getActivePresentations')->willReturn([$owner]);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getUI'])->getMock();
    $scene->method('getUI')->willReturn($ui);
    $presentation = new DialogueScenePresentation($this->root);
    foreach (range(0, 1) as $iteration) {
        $surface = $presentation->compose($scene, null, 1280, 720, true, supportsImageTone: $supportsTone);
        $inactive = array_find($surface->canvas->images, fn($image) => $image->id === 'skit-bust-1');
        expect($inactive->brightness)->toBe($supportsTone ? 0.60 : 1.0)
            ->and($inactive->opacity)->toBe(1.0)->and($surface->isOverlay)->toBeFalse();
    }
    $log = $this->root . '/warning.log';
    expect(is_file($log) ? substr_count(file_get_contents($log), 'Renderer lacks canvas_image_tone') : 0)
        ->toBe($supportsTone ? 0 : 1);
})->with([true, false]);

it('keeps larger casts readable in stable groups with the active speaker present', function () {
    $cast = array_map(fn($index) => ['actorId' => 'hero' . $index, 'name' => 'Actor ' . $index, 'emotion' => 'Neutral'], range(0, 7));
    $context = new DialogueContext('hero7', skitId: 'sample', skitTitle: 'Group scene', participants: $cast);
    $base = getDialogueTestCatalog($this->root);
    $catalogue = new DialoguePresentationCatalog(array_fill_keys(array_column($cast, 'actorId'), ['bust' => 'hero.png']),
        $base->theme, skits: $base->skits);
    $canvas = DialogueCanvasPresentation::compose(getDialogueTestLine('Actor 7', $context), $catalogue, 800, 480);
    $names = implode(' ', array_merge(...array_map(fn($layer) => array_column($layer->runs, 'text'), $canvas->textLayers)));
    $busts = array_values(array_filter($canvas->images, fn($image) => str_starts_with($image->id, 'skit-bust-')));
    expect($names)->toContain('Actor 7')->not->toContain('Actor 0', 'Actor 6', '* Actor 7')
        ->and($busts)->toHaveCount(2)->and($busts[0]->brightness)->toBe(0.60)->and($busts[1]->brightness)->toBe(1.0);
});

it('rejects unsafe catalog references and permits an empty legacy placeholder', function () {
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php');
    expect(DialoguePresentationCatalog::load($this->root)->actors)->toBe([]);
    expect(fn() => new DialoguePresentationCatalog(['hero' => ['bust' => '../private.png']]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new DialoguePresentationCatalog(speakers: ['Label' => 'unknown']))->toThrow(InvalidArgumentException::class);
});

it('drops an opaque base canvas rather than hiding terminal dialogue when optional composition fails', function (bool $supportsCanvas) {
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ["schema" => "invalid"];');
    $owner = $this->getMockBuilder(TextBoxModal::class)->disableOriginalConstructor()->onlyMethods(['getDialogueSnapshot'])->getMock();
    $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['getActivePresentations'])->getMock();
    $ui->method('getActivePresentations')->willReturn([$owner]);
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getUI'])->getMock();
    $scene->method('getUI')->willReturn($ui);
    $surface = new DialogueScenePresentation($this->root)->compose($scene, new PresentationCanvas(1280, 720), 1280, 720,
        supportsOverlay: true, supportsCanvas: $supportsCanvas);
    expect($surface)->not->toBeNull()->and($surface->canvas)->toBeNull()
        ->and($surface->excludedLayers)->toBe([])
        ->and(file_get_contents($this->root . '/warning.log'))->toContain('terminal');
})->with([true, false]);

it('reveals words at their final wrapped positions without reflowing during typing', function () {
    $catalogue = getDialogueTestCatalog($this->root);
    $page = str_repeat('a', 44) . ' ' . 'wrappingword' . "\nNext paragraph";
    $make = fn($count) => DialogueCanvasPresentation::compose(new DialogueSnapshot('Display Hero', $page,
        mb_substr($page, 0, $count), true, 0, 1, false, WindowPosition::BOTTOM, new DialogueContext()), $catalogue, 700, 600);
    $typing = array_find($make(48)->textLayers, fn($layer) => $layer->id === 'dialogue-text');
    $complete = array_find($make(mb_strlen($page))->textLayers, fn($layer) => $layer->id === 'dialogue-text');
    expect($typing->grid)->toEqual($complete->grid)
        ->and($typing->runs[1]->text)->toBe('wra')
        ->and($typing->runs[1]->row)->toBe(1)
        ->and($complete->runs[1]->row)->toBe(1)
        ->and($complete->runs[2]->text)->toBe('Next paragraph');
});

it('reveals only the owner prefix after graphical whitespace normalization', function (string $page) {
    $catalogue = getDialogueTestCatalog($this->root);
    $make = fn($count) => DialogueCanvasPresentation::compose(new DialogueSnapshot('Display Hero', $page,
        mb_substr($page, 0, $count), true, 0, 1, false, WindowPosition::BOTTOM), $catalogue, 700, 600);
    $prose = fn($canvas) => array_find($canvas->textLayers, fn($layer) => $layer->id === 'dialogue-text');
    $complete = $prose($make(mb_strlen($page)));
    foreach (range(0, mb_strlen($page)) as $count) {
        $typing = $prose($make($count));
        $expected = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], mb_substr($page, 0, $count));
        expect($typing->grid)->toEqual($complete->grid)
            ->and(implode('', array_column($typing->runs, 'text')))->toBe(str_replace("\n", '', $expected));
        foreach ($typing->runs as $run) {
            $final = array_find($complete->runs, fn($candidate) => $candidate->row === $run->row);
            expect($final)->not->toBeNull()->and($final->text)->toStartWith($run->text);
        }
    }
})->with([
    'tab before wrapping' => [str_repeat('a', 42) . "\tword\nNext"],
    'windows paragraphs' => ["One\r\n\r\nNext"],
    'carriage return paragraphs' => ["One\rNext"],
    'multibyte and spaces' => ["caf\u{00E9}\t\u{65E5}\u{672C}\u{8A9E}  \nNext "],
]);

it('integrates active event-owned dialogue with the retained field and removes only its overlay on close', function (bool $graphical) {
    mkdir($this->root . '/Data/Presentation', 0777, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1', 'metrics' => ['cellWidth' => 10, 'cellHeight' => 28,
            'rowHeight' => 40, 'panelPadding' => 28]],
        'actors' => ['hero' => ['portrait' => 'hero.png', 'bust' => 'hero.png']],
        'speakers' => ['Display Hero' => 'hero'],
    ], true) . ';');
    $peer = new FakeRendererTransport();
    $capabilities = $graphical ? ['graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'sprite_source_rect'] : [];
    $peer->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
    $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root), $peer);
    $owner = $this->getMockBuilder(TextBoxModal::class)->disableOriginalConstructor()->onlyMethods(['getDialogueSnapshot'])->getMock();
    $owner->method('getDialogueSnapshot')->willReturn(getDialogueTestLine(context: new DialogueContext('hero')));
    $active = [];
    $ui = $this->getMockBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['getActivePresentations'])->getMock();
    $ui->method('getActivePresentations')->willReturnCallback(function () use (&$active) { return $active; });
    $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 1, false, 'field', 'Floor')]));
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
        ->onlyMethods(['getUI', 'getPresentationCanvas', 'getPresentationWorld', 'getPresentationViewport', 'getGraphicalSpriteProviders'])->getMock();
    $scene->method('getUI')->willReturn($ui);
    $scene->method('getPresentationCanvas')->willReturn(null);
    $scene->method('getPresentationWorld')->willReturn($world);
    $scene->method('getGraphicalSpriteProviders')->willReturn([]);
    $scene->method('getPresentationViewport')->willReturn(new \Ichiloto\Engine\Rendering\Presentation\PresentationViewport(
        1, 0, 0, new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0, 0, 1440, 720), worldId: 'field'));
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, new Camera($scene, 90, 30));
    Console::syncDimensions(90, 30);
    try {
        $runtime->start('Dialogue fixture', 90, 30);
        $runtime->present($scene);
        $active = [$owner];
        $runtime->present($scene);
        if ($graphical) {
            $root = array_find($peer->sent[1]->payload['operations'], fn($op) => ($op['kind'] ?? null) === 'canvas');
            expect($root['value']['mode'])->toBe('overlay');
            $frames = RetainedFrameState::replay($peer->sent);
            expect($frames[1]['worlds'])->toBe($frames[0]['worlds'])->and($frames[1]['viewport'])->toBe($frames[0]['viewport']);
            $active = [];
            $runtime->present($scene);
            expect(array_unique(array_column($peer->sent[2]->payload['operations'], 'op')))->toBe(['remove'])
                ->and(array_any($peer->sent[2]->payload['operations'], fn($op) => !str_starts_with($op['kind'], 'canvas')))->toBeFalse();
        } else {
            expect($peer->sent)->toHaveCount(1)->and(file_get_contents($this->root . '/warning.log'))->toContain('terminal presentation retained');
        }
    } finally { $runtime->shutdown(); }
})->with([true, false]);
