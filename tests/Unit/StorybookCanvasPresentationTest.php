<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventPresentationHostInterface;
use Ichiloto\Engine\Events\Interpreter\ModalEventPresentation;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Interfaces\StorybookReadingCapacityProviderInterface;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\StorybookModal;
use Ichiloto\Engine\UI\Modal\StorybookReadingCapacity;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\StorybookCanvasPresentation;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\PassiveGame;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class StorybookCanvasModalProbe extends StorybookModal
{
    public function selectControl(int $index): void { $this->activeIndex = $index; $this->submit(); }
}

/** Synthetic replaceable painting: any size, no production artwork. */
function storybookCanvasPainting(string $root, string $path, int $width, int $height): void
{
    is_dir(dirname($root . '/' . $path)) || mkdir(dirname($root . '/' . $path), 0777, true);
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 90, 140, 200));
    imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2),
        imagecolorallocate($image, 240, 210, 120));
    imagepng($image, $root . '/' . $path);
}

function storybookCanvasTheme(string $root, array $frames = [], bool $hints = true): MenuPresentationCatalog
{
    storybookCanvasPainting($root, 'frame.png', 24, 24);
    $frame = ['asset' => 'frame.png', 'cuts' => [6, 6, 6, 6]];
    return new MenuPresentationCatalog($root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => $hints,
        'metrics' => ['cellWidth' => 10, 'cellHeight' => 24, 'rowHeight' => 40, 'panelPadding' => 20, 'sectionGap' => 12],
        'frames' => ['panel' => $frame, 'quiet' => $frame, ...$frames]]);
}

function storybookCanvasBook(array $pages, string $title = 'The Lantern Keeper'): StorybookDefinition
{
    return StorybookDefinition::fromArray(['id' => 'synthetic-tale', 'title' => $title, 'narrator' => 'Storyteller',
        'pages' => $pages]);
}

/** A paragraph of distinct numbered words, so order and completeness are checkable. */
function storybookCanvasProse(int $words, string $prefix): string
{
    return implode(' ', array_map(static fn(int $index): string => "{$prefix}{$index}", range(1, $words)));
}

/** @return list<CanvasTextLayer> Every text layer that paints a glyph inside the rectangle. */
function storybookCanvasTextIn(PresentationCanvas $canvas, CanvasRectangle $area): array
{
    return array_values(array_filter($canvas->textLayers, static fn(CanvasTextLayer $layer): bool =>
        array_any($layer->runs, static fn($run): bool => trim($run->text) !== '')
        && $layer->x >= $area->x - 0.001 && $layer->y >= $area->y - 0.001
        && $layer->x + $layer->grid->columns * $layer->grid->cellWidth <= $area->x + $area->width + 0.001
        && $layer->y + $layer->grid->rows * $layer->grid->cellHeight <= $area->y + $area->height + 0.001));
}

/** The words a canvas paints, in reading order (row by row, left to right). */
function storybookCanvasWords(PresentationCanvas $canvas): array
{
    $cells = [];
    foreach ($canvas->textLayers as $layer) {
        foreach ($layer->runs as $run) {
            if (trim($run->text) === '') { continue; }
            $cells[] = [$layer->y + $run->row * $layer->grid->cellHeight, $layer->x + $run->column * $layer->grid->cellWidth, $run->text];
        }
    }
    usort($cells, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return preg_split('/\s+/', trim(implode(' ', array_column($cells, 2))));
}

beforeEach(function () {
    $this->canvasStatics = [];
    foreach ([ConfigStore::class, Console::class, InputManager::class, EventManager::class, Timers::class, ActionHints::class] as $class) {
        $this->canvasStatics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    // A wide Terminal leaves the graphical projection as the stricter capacity.
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 200, 'height' => 60]));
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['audio' => ['music' => false, 'sfx' => false, 'voice' => false]]));
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
    Timers::setFrameTick(null);
    InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::ESCAPE]],
        'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
    ActionHints::useProvider(null);
    InputManager::setInputSource(new FakeInputSource());
    foreach (['width' => 200, 'height' => 60, 'buffer' => array_fill(0, 60, array_fill(0, 200, ' ')),
        'frameDepth' => 0, 'frameRows' => [], 'terminalOutputStream' => null, 'output' => null] as $name => $value) {
        new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    $this->canvasGame = new PassiveGame();
    $silent = new class extends AudioManager {
        public function __construct() {}
        public function playSystemSound(SystemSound $sound): void {}
    };
    new ReflectionProperty(PassiveGame::class, 'audioManager')->setValue($this->canvasGame, $silent);
    $this->canvasRoot = sys_get_temp_dir() . '/ichiloto-storybook-canvas-' . bin2hex(random_bytes(5));
    mkdir($this->canvasRoot);
});

afterEach(function () {
    foreach ($this->canvasStatics as $class => $values) {
        foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->canvasRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($this->canvasRoot);
});

it('paints every reading state of the shared reader inside the capacity it measured, on large and small surfaces', function (int $width, int $height, bool $hints) {
    $theme = storybookCanvasTheme($this->canvasRoot, hints: $hints);
    storybookCanvasPainting($this->canvasRoot, 'Pictures/wide.png', 640, 360);
    storybookCanvasPainting($this->canvasRoot, 'Pictures/tall.png', 300, 520);
    $book = storybookCanvasBook([
        ['id' => 'opening', 'text' => storybookCanvasProse(40, 'a'), 'picture' => 'Pictures/wide.png'],
        ['id' => 'middle', 'text' => storybookCanvasProse(260, 'b') . "\n\n" . storybookCanvasProse(90, 'c'), 'picture' => 'Pictures/tall.png'],
        ['id' => 'closing', 'text' => storybookCanvasProse(12, 'd')],
    ]);
    $capacity = StorybookCanvasPresentation::measureReadingCapacity($book, $theme, $width, $height);
    $modal = new StorybookCanvasModalProbe($this->canvasGame, $book, $capacity);
    $painted = [];
    $states = 0;
    while (true) {
        $snapshot = $modal->getModalPresentation();
        expect($snapshot->storybook->capacity)->toEqual($capacity);
        $canvas = MenuModalPresentation::compose(new PresentationCanvas($width, $height), $snapshot, $theme);
        $reading = array_values(array_filter($canvas->textLayers, static fn(CanvasTextLayer $layer): bool =>
            $layer->grid->columns === $capacity->columns && array_any($layer->runs,
                static fn($run): bool => str_contains($snapshot->message, trim($run->text)) && trim($run->text) !== '')));
        expect($reading)->not->toBeEmpty();
        $lines = array_unique(array_merge(...array_map(static fn(CanvasTextLayer $layer): array =>
            array_map(static fn($run): int => $run->row, $layer->runs), $reading)));
        expect(count($lines))->toBe(count(array_filter(explode("\n", $snapshot->message), static fn(string $line): bool => trim($line) !== '')))
            ->and(max($lines))->toBeLessThan($capacity->rows);
        foreach ([...$canvas->images, ...$canvas->textLayers] as $part) {
            $box = $part instanceof CanvasImage ? ($part->clipRect ?? $part->destination) : $part->clipRect ?? $part->bounds;
            expect($box->x)->toBeGreaterThanOrEqual(0)->and($box->y)->toBeGreaterThanOrEqual(0)
                ->and($box->x + $box->width)->toBeLessThanOrEqual($width + 0.001)
                ->and($box->y + $box->height)->toBeLessThanOrEqual($height + 0.001);
        }
        $picture = array_values(array_filter($canvas->images, static fn(CanvasImage $image): bool => str_starts_with($image->asset, 'Pictures/')));
        if ($snapshot->storybook->page->picture === null) {
            expect($picture)->toBe([]);
        } else {
            expect($picture)->not->toBeEmpty()->and($picture[0]->asset)->toBe($snapshot->storybook->page->picture);
            foreach ($reading as $layer) {
                expect($layer->x)->toBeGreaterThanOrEqual(max(array_map(static fn(CanvasImage $image): float =>
                    $image->destination->x + $image->destination->width, $picture)));
            }
        }
        array_push($painted, ...preg_split('/\s+/', trim($snapshot->message)));
        $states++;
        if (!str_contains(implode(' ', $snapshot->choices), 'Next')) { break; }
        $modal->selectControl(array_search('Next', $snapshot->choices, true));
    }
    // Every authored word reaches the screen exactly once, in order, across pages and continuations.
    $authored = preg_split('/\s+/', trim(implode(' ', array_map(static fn($page): string => $page->text, $book->pages))));
    expect($painted)->toBe($authored)->and($states)->toBeGreaterThanOrEqual(3);
})->with([[1280, 720, true], [800, 480, true], [1280, 720, false], [800, 480, false]]);

it('reserves the painting slot for a pictured book and gives a picture-free book the whole body', function () {
    $theme = storybookCanvasTheme($this->canvasRoot);
    $pictured = storybookCanvasBook([['id' => 'one', 'text' => 'Words.', 'picture' => 'Pictures/any.png'],
        ['id' => 'two', 'text' => 'More words.']]);
    $plain = storybookCanvasBook([['id' => 'one', 'text' => 'Words.'], ['id' => 'two', 'text' => 'More words.']]);
    $withSlot = StorybookCanvasPresentation::measureReadingCapacity($pictured, $theme, 1280, 720);
    $withoutSlot = StorybookCanvasPresentation::measureReadingCapacity($plain, $theme, 1280, 720);
    $small = StorybookCanvasPresentation::measureReadingCapacity($pictured, $theme, 800, 480);
    expect($withoutSlot->columns)->toBeGreaterThan($withSlot->columns)
        ->and($withoutSlot->rows)->toBe($withSlot->rows)
        ->and($small->columns)->toBeLessThan($withSlot->columns)->and($small->rows)->toBeLessThan($withSlot->rows)
        ->and($small->rows)->toBeGreaterThanOrEqual(1);
});

it('contains replaced artwork of any shape without changing the negotiated reading area', function () {
    $theme = storybookCanvasTheme($this->canvasRoot);
    $book = storybookCanvasBook([['id' => 'page', 'text' => 'A lantern in the dark.', 'picture' => 'Pictures/page.png']]);
    $measure = static fn() => StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720);
    $drawn = [];
    foreach ([[900, 300], [200, 700], [64, 64]] as [$w, $h]) {
        storybookCanvasPainting($this->canvasRoot, 'Pictures/page.png', $w, $h);
        $capacity = $measure();
        $modal = new StorybookCanvasModalProbe($this->canvasGame, $book, $capacity);
        $canvas = MenuModalPresentation::compose(new PresentationCanvas(1280, 720), $modal->getModalPresentation(), $theme);
        $image = array_values(array_filter($canvas->images, static fn(CanvasImage $image): bool => $image->asset === 'Pictures/page.png'));
        expect($image)->toHaveCount(1);
        $destination = $image[0]->destination;
        expect(abs($destination->width / $destination->height - $w / $h))->toBeLessThan(0.01);
        $drawn[] = $capacity;
    }
    expect($drawn[1])->toEqual($drawn[0])->and($drawn[2])->toEqual($drawn[0]);
});

it('keeps the narration readable in place of a missing painting', function () {
    $theme = storybookCanvasTheme($this->canvasRoot);
    $book = storybookCanvasBook([['id' => 'page', 'text' => 'Nobody painted this yet.', 'picture' => 'Pictures/absent.png']]);
    $capacity = StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720);
    $modal = new StorybookCanvasModalProbe($this->canvasGame, $book, $capacity);
    $canvas = MenuModalPresentation::compose(new PresentationCanvas(1280, 720), $modal->getModalPresentation(), $theme);
    expect(array_filter($canvas->images, static fn(CanvasImage $image): bool => str_starts_with($image->asset, 'Pictures/')))->toBe([])
        ->and(storybookCanvasWords($canvas))->toContain('Nobody', 'painted', 'this', 'yet.');
});

it('draws the title, narrator, page position and centred reader controls from the owner snapshot', function () {
    $theme = storybookCanvasTheme($this->canvasRoot);
    $book = storybookCanvasBook([['id' => 'one', 'text' => 'First.'], ['id' => 'two', 'text' => 'Second.']]);
    $modal = new StorybookCanvasModalProbe($this->canvasGame, $book,
        StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720));
    $modal->selectControl(0);
    $snapshot = $modal->getModalPresentation();
    expect($snapshot->choices)->toBe(['Previous', 'Close']);
    $canvas = MenuModalPresentation::compose(new PresentationCanvas(1280, 720), $snapshot, $theme);
    $words = storybookCanvasWords($canvas);
    expect($words)->toContain('The', 'Lantern', 'Keeper', 'Storyteller', '2/2', 'Second.', 'Previous', 'Close');
    // Equal buttons form one group centred on the frame; each label is centred in its own button.
    $labels = [];
    foreach ($canvas->textLayers as $layer) {
        foreach ($layer->runs as $run) {
            if (in_array(trim($run->text), ['Previous', 'Close'], true)) {
                $left = $layer->x + $run->column * $layer->grid->cellWidth;
                $labels[trim($run->text)] = $left + mb_strlen(trim($run->text)) * $layer->grid->cellWidth / 2;
            }
        }
    }
    expect($labels)->toHaveCount(2)
        ->and(abs(($labels['Previous'] + $labels['Close']) / 2 - 640))->toBeLessThanOrEqual($theme->metrics->cellWidth);
});

it('uses the theme storybook frame role and falls back to the panel frame', function () {
    storybookCanvasPainting($this->canvasRoot, 'book.png', 30, 30);
    $themed = storybookCanvasTheme($this->canvasRoot, ['storybook' => ['asset' => 'book.png', 'cuts' => [8, 8, 8, 8]]]);
    $plain = storybookCanvasTheme($this->canvasRoot);
    $book = storybookCanvasBook([['id' => 'page', 'text' => 'Framed.']]);
    foreach ([[$themed, 'book.png'], [$plain, 'frame.png']] as [$theme, $asset]) {
        $modal = new StorybookCanvasModalProbe($this->canvasGame, $book,
            StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720));
        $canvas = MenuModalPresentation::compose(new PresentationCanvas(1280, 720), $modal->getModalPresentation(), $theme);
        expect(array_filter($canvas->images, static fn(CanvasImage $image): bool =>
            str_starts_with($image->id, 'storybook-frame') && $image->asset === $asset))->not->toBeEmpty();
    }
});

it('refuses a snapshot whose capacity this surface cannot hold rather than clipping narration', function () {
    $theme = storybookCanvasTheme($this->canvasRoot);
    $book = storybookCanvasBook([['id' => 'page', 'text' => storybookCanvasProse(30, 'w')]]);
    $modal = new StorybookCanvasModalProbe($this->canvasGame, $book,
        StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720));
    expect(fn() => MenuModalPresentation::compose(new PresentationCanvas(800, 480), $modal->getModalPresentation(), $theme))
        ->toThrow(RuntimeException::class, 'terminal presentation retained');
});

it('opens a Terminal reader through a measuring host that reports no graphical projection', function () {
    $host = new class($this->canvasGame) implements EventPresentationHostInterface, StorybookReadingCapacityProviderInterface {
        public int $asked = 0;
        public function __construct(private \Ichiloto\Engine\Core\Game $game) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->game; }
        public function restoreAfterEventOverlay(): void {}
        public function getStorybookReadingCapacity(StorybookDefinition $book): ?StorybookReadingCapacity { $this->asked++; return null; }
    };
    $surface = new ModalEventPresentation($host);
    $surface->beginStorybook(storybookCanvasBook([['id' => 'page', 'text' => 'Plain cells.']]));
    $modal = new ReflectionProperty($surface, 'modal')->getValue($surface);
    expect($host->asked)->toBe(1)->and($modal->getModalPresentation()->storybook->capacity->columns)->toBe(192);
    ob_start();
    try { $surface->reset(); } finally { ob_end_clean(); }
});

it('accepts a graphical session whose host reports scene owners in Terminal cells', function () {
    $runtime = new ReflectionClass(RendererRuntime::class)->newInstanceWithoutConstructor();
    new ReflectionProperty($runtime, 'grid')->setValue($runtime, new RendererGridConfig());
    $game = new class($runtime) extends PassiveGame {
        public function __construct(private RendererRuntime $runtime) {}
        public function getRendererRuntime(): ?RendererRuntime { return $this->runtime; }
    };
    $host = new class($game) implements EventPresentationHostInterface, StorybookReadingCapacityProviderInterface {
        public function __construct(private \Ichiloto\Engine\Core\Game $game) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->game; }
        public function restoreAfterEventOverlay(): void {}
        public function getStorybookReadingCapacity(StorybookDefinition $book): ?StorybookReadingCapacity { return null; }
    };
    $surface = new ModalEventPresentation($host);
    $surface->beginStorybook(storybookCanvasBook([['id' => 'page', 'text' => 'Plain cells.']]));
    expect($surface->isComplete())->toBeFalse();
    ob_start();
    try { $surface->reset(); } finally { ob_end_clean(); }
});

it('measures through the scene presentation with the project dialogue theme, and defers to the Terminal without one', function () {
    $book = storybookCanvasBook([['id' => 'page', 'text' => 'Measured.', 'picture' => 'Pictures/page.png']]);
    $scene = new \Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation($this->canvasRoot);
    expect($scene->getStorybookReadingCapacity($book, 1280, 720))->toBeNull();
    mkdir($this->canvasRoot . '/Data/Presentation', 0777, true);
    file_put_contents($this->canvasRoot . '/Data/Presentation/dialogue.php', '<?php return ' . var_export(['schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1', 'metrics' => ['cellWidth' => 12, 'cellHeight' => 30]]], true) . ';');
    $themed = new \Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation($this->canvasRoot);
    $theme = new MenuPresentationCatalog($this->canvasRoot, ['schema' => 'ichiloto.menu/1', 'metrics' => ['cellWidth' => 12, 'cellHeight' => 30]]);
    expect($themed->getStorybookReadingCapacity($book, 1280, 720))
        ->toEqual(StorybookCanvasPresentation::measureReadingCapacity($book, $theme, 1280, 720))
        ->and($themed->getStorybookReadingCapacity($book, 1280, 720)->columns)->toBeGreaterThan(1);
});
