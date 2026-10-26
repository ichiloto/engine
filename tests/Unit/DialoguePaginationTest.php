<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueCanvasPresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePageLayout;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class PaginationGameProbe extends Game
{
    public function __construct() {}
    public function __destruct() {}
}

final class PaginationTextBoxProbe extends TextBoxModal
{
    public function getPages(): array { return $this->messagePages; }
    public function getTerminalContentWidth(): int { return $this->window->getContentWidth(); }
    public function getTerminalLines(): array
    {
        return $this->convertMessageToLinesOfContent($this->currentPageMessage());
    }
    public function selectPage(int $index, int $visibleCharacters, bool $printing): void
    {
        $this->currentPageIndex = $index;
        $this->currentCharacterIndex = $visibleCharacters;
        $this->isPrinting = $printing;
    }
}

beforeEach(function () {
    $this->saved = [];
    foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class,
        Time::class, Debug::class] as $class) {
        $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->root = createTestDirectory('ichiloto-pagination-');
    Debug::configure(['log_directory' => $this->root]);
    InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]],
        'dialogue_auto' => ['keys' => [KeyCode::A]]]);
});

afterEach(function () {
    foreach ($this->saved as $class => $values) {
        foreach ($values as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
});

it('keeps empty pages authored blank paragraphs and normalized whitespace', function (string $message, array $pages) {
    $pagination = DialoguePaginationBuilder::buildPagination($message, '', '', new DialogueContext(), 80, 24);
    expect($pagination->pages)->toBe($pages)->and($pagination->windowHeight)->toBe(5);
})->with([
    'empty line' => ['', ['']],
    'empty paragraphs' => ["\n\n\n", ["\n\n", '']],
    'line endings tabs and trailing spaces' => ["  First  \r\n\r\nSecond\tword\rLast  ",
        ["  First\n\nSecond    word", 'Last']],
]);

it('retains the existing minimum width and bounded reading footprint', function (int $screenWidth,
    int $screenHeight, int $width, int $height, int $rows) {
    $pagination = DialoguePaginationBuilder::buildPagination("a\nb\nc\nd\ne\nf\ng", 'Speaker', '',
        new DialogueContext(), $screenWidth, $screenHeight);
    expect($pagination->windowWidth)->toBe($width)->and($pagination->windowHeight)->toBe($height)
        ->and($pagination->contentWidth)->toBe(max(1, $width - 4))
        ->and($pagination->linesPerPage)->toBe($rows);
    foreach ($pagination->pages as $page) {
        expect(count(explode("\n", $page)))->toBeLessThanOrEqual($rows);
    }
    expect(implode("\n", $pagination->pages))->toBe("a\nb\nc\nd\ne\nf\ng");
})->with([
    [135, 36, DEFAULT_DIALOG_WIDTH, 5, 3],
    [20, 4, 20, 4, 2],
    [3, 2, 4, 3, 1],
    [1, 1, 4, 3, 1],
]);

it('selects narration speech and explicit placements without trimming the speaker', function (string $speaker,
    ?WindowPosition $position, WindowPosition $expected) {
    $context = new DialogueContext(actorId: 'synthetic', emotion: 'Happy');
    $pagination = DialoguePaginationBuilder::buildPagination('A line.', $speaker, 'An authored hint.',
        $context, 80, 24, position: $position);
    expect($pagination->position)->toBe($expected)->and($pagination->speaker)->toBe($speaker)
        ->and($pagination->help)->toBe('An authored hint.')->and($pagination->context)->toBe($context);
})->with([
    ['', null, WindowPosition::TOP],
    ['  ', null, WindowPosition::TOP],
    [' Speaker ', null, WindowPosition::BOTTOM],
    ['', WindowPosition::MIDDLE, WindowPosition::MIDDLE],
    ['Speaker', WindowPosition::TOP, WindowPosition::TOP],
    ['', WindowPosition::BOTTOM, WindowPosition::BOTTOM],
]);

it('wraps words and long tokens without changing the existing wrap boundaries', function () {
    $words = DialoguePaginationBuilder::buildPagination('alpha beta gamma delta', '', '',
        new DialogueContext(), 16, 24);
    $token = DialoguePaginationBuilder::buildPagination(str_repeat('x', 27), '', '',
        new DialogueContext(), 14, 24);
    expect($words->pages)->toBe(["alpha beta\ngamma delta"])
        ->and($token->pages)->toBe([str_repeat('x', 10) . "\n" . str_repeat('x', 10) . "\n" . str_repeat('x', 7)]);
});

it('fits wide and combining characters in both graphical codepoints and terminal display cells', function (string $message) {
    $pagination = DialoguePaginationBuilder::buildPagination($message, '', '', new DialogueContext(), 14, 24);
    foreach ($pagination->pages as $page) {
        foreach (explode("\n", $page) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual($pagination->contentWidth)
                ->and(TerminalText::displayWidth($line))->toBeLessThanOrEqual($pagination->contentWidth);
        }
    }
    expect(str_replace("\n", '', implode('', $pagination->pages)))->toBe($message);
})->with([str_repeat("\u{754C}", 8), str_repeat("e\u{301}", 8)]);

it('honors the supplied graphical portrait skit and authored-help budgets', function (string $speaker,
    bool $portrait, bool $skit, string $help) {
    writeTestPng($this->root . '/portrait.png', 8, 12);
    $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
        'metrics' => ['cellWidth' => 12, 'panelPadding' => 28]]);
    $catalogue = new DialoguePresentationCatalog($portrait ? ['hero' => ['portrait' => 'portrait.png']] : [], $theme);
    $context = new DialogueContext(actorId: $portrait ? 'hero' : null, skitId: $skit ? 'synthetic' : null);
    $layout = new DialoguePageLayout($catalogue, $speaker, $context, $help, 700);
    $message = str_repeat('One two three four five six seven eight nine ten. ', 6);
    $pagination = DialoguePaginationBuilder::buildPagination($message, $speaker, $help, $context, 80, 24, $layout);
    expect($pagination->contentWidth)->toBe(min(76, $layout->columns))
        ->and($pagination->windowWidth)->toBe($pagination->contentWidth + 4)
        ->and($pagination->linesPerPage)->toBe($layout->proseRows)
        ->and($pagination->windowHeight)->toBe(5)->and(count($pagination->pages))->toBeGreaterThan(1);
    foreach ($pagination->pages as $index => $page) {
        foreach (explode("\n", $page) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual($pagination->contentWidth)
                ->and(TerminalText::displayWidth($line))->toBeLessThanOrEqual($pagination->contentWidth);
        }
        $canvas = DialogueCanvasPresentation::compose($pagination->getSnapshot($index), $catalogue, 700, 600);
        $prose = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'dialogue-text');
        expect($prose->grid->rows)->toBeLessThanOrEqual($layout->proseRows);
    }
    expect(trim(preg_replace('/\s+/', ' ', implode(' ', $pagination->pages))))->toBe(trim($message));
})->with([
    ['Speaker', true, false, 'Continue'],
    ['Speaker', true, false, "First\nSecond"],
    ['Speaker', false, false, 'Continue'],
    ['', false, false, ''],
    ['Speaker', true, true, 'Continue'],
]);

it('uses all negotiated graphical columns across themes portraits skits and narration', function (int $canvasWidth,
    array $metrics, string $speaker, bool $portrait, bool $skit) {
    writeTestPng($this->root . '/portrait.png', 8, 12);
    $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'metrics' => $metrics]);
    $catalogue = new DialoguePresentationCatalog(['hero' => ['portrait' => 'portrait.png']], $theme);
    $context = new DialogueContext(actorId: $portrait ? 'hero' : null, skitId: $skit ? 'synthetic' : null);
    $help = 'Continue';
    $layout = new DialoguePageLayout($catalogue, $speaker, $context, $help, $canvasWidth);
    $message = implode(' ', array_map(static fn($index) => sprintf('word%02d', $index), range(1, 80)))
        . "\n\nAn authored final paragraph.";
    $pagination = DialoguePaginationBuilder::buildPagination($message, $speaker, $help, $context, 135, 36, $layout);
    expect($layout->columns)->toBeGreaterThan(DEFAULT_DIALOG_WIDTH - 4)
        ->and($pagination->contentWidth)->toBe($layout->columns)
        ->and($pagination->windowWidth)->toBe($layout->columns + 4)->toBeLessThanOrEqual(135)
        ->and($layout->portrait !== null)->toBe($portrait && !$skit)
        ->and($pagination->linesPerPage)->toBe(2)->and($pagination->windowHeight)->toBe(5)
        ->and(count($pagination->pages))->toBeGreaterThan(1);
    $lineWidths = [];
    foreach ($pagination->pages as $index => $page) {
        $lines = explode("\n", $page);
        expect(count($lines))->toBeLessThanOrEqual($layout->proseRows);
        foreach ($lines as $line) {
            $lineWidths[] = mb_strlen($line);
            expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($pagination->contentWidth);
        }
        $snapshot = $pagination->getSnapshot($index, 48, isPrinting: true);
        expect($snapshot->page)->toBe($page)->and($snapshot->visibleText)->toBe(mb_substr($page, 0, 48))
            ->and($snapshot->pageIndex)->toBe($index)->and($snapshot->pageCount)->toBe(count($pagination->pages));
        $canvas = DialogueCanvasPresentation::compose($pagination->getSnapshot($index), $catalogue, $canvasWidth, 720);
        $prose = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'dialogue-text');
        expect($prose->grid->columns)->toBe($layout->columns)
            ->and($prose->grid->rows)->toBe(count($lines))
            ->and(implode('', array_column($prose->runs, 'text')))->toBe(str_replace("\n", '', $page));
    }
    expect(max($lineWidths))->toBeGreaterThan(DEFAULT_DIALOG_WIDTH - 4)->toBeLessThanOrEqual($layout->columns)
        ->and(implode("\n", $pagination->pages))->toContain("\n\nAn authored final paragraph.")
        ->and(trim(preg_replace('/\s+/', ' ', implode(' ', $pagination->pages))))
        ->toBe(trim(preg_replace('/\s+/', ' ', $message)));
})->with([
    'compact dense theme' => [800, ['cellWidth' => 8, 'panelPadding' => 16]],
    'wide spacious theme' => [1350, ['cellWidth' => 12, 'panelPadding' => 28]],
])->with([
    'plain speech' => ['Speaker', false, false],
    'portrait speech' => ['Speaker', true, false],
    'skit speech' => ['Speaker', true, true],
    'narration' => ['', false, false],
]);

it('bounds graphical session fallback pages and their window to the supplied screen', function (int $screenWidth,
    int $windowWidth, int $contentWidth) {
    $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
        'metrics' => ['cellWidth' => 8, 'panelPadding' => 16]]);
    $catalogue = new DialoguePresentationCatalog([], $theme);
    $context = new DialogueContext();
    $layout = new DialoguePageLayout($catalogue, '', $context, '', 1600);
    $message = str_repeat('x', 400);
    $pagination = DialoguePaginationBuilder::buildPagination($message, '', '', $context, $screenWidth, 4, $layout);
    expect($pagination->windowWidth)->toBe($windowWidth)->toBeLessThanOrEqual(max(4, $screenWidth))
        ->and($pagination->contentWidth)->toBe($contentWidth)
        ->and($pagination->linesPerPage)->toBe(2)->and($pagination->windowHeight)->toBe(4)
        ->and(str_replace("\n", '', implode('', $pagination->pages)))->toBe($message);
    foreach ($pagination->pages as $page) {
        expect(count(explode("\n", $page)))->toBeLessThanOrEqual(2);
        foreach (explode("\n", $page) as $line) {
            expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($contentWidth);
        }
    }
})->with([
    [0, 4, 1],
    [1, 4, 1],
    [3, 4, 1],
    [4, 4, 1],
    [5, 5, 1],
    [20, 20, 16],
    [60, 60, 56],
    [135, 135, 131],
    [200, 190, 186],
]);

it('uses explicit screen dimensions without acquiring or mutating runtime singletons', function () {
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
    Console::syncDimensions(135, 36);
    $before = [];
    foreach (array_keys($this->saved) as $class) { $before[$class] = new ReflectionClass($class)->getStaticProperties(); }
    $pagination = DialoguePaginationBuilder::buildPagination("One\nTwo\nThree", '', '', new DialogueContext(), 8, 4);
    expect($pagination->pages)->toBe(["One\nTwo", "Thre\ne"])
        ->and($pagination->windowWidth)->toBe(8)->and($pagination->windowHeight)->toBe(4);
    foreach ($before as $class => $values) {
        expect(new ReflectionClass($class)->getStaticProperties())->toBe($values);
    }
});

it('projects author-paced pages and multibyte typing prefixes with the original metadata', function () {
    $context = new DialogueContext(actorId: 'hero', emotion: 'Happy');
    $pagination = DialoguePaginationBuilder::buildPagination("caf\u{00E9}\nSecond\nThird\nLast", 'Speaker', 'Help',
        $context, 80, 24);
    $typing = $pagination->getSnapshot(0, 4, isPrinting: true, auto: true);
    $last = $pagination->getSnapshot(1);
    expect($typing->page)->toBe("caf\u{00E9}\nSecond\nThird")->and($typing->visibleText)->toBe("caf\u{00E9}")
        ->and($typing->isPrinting)->toBeTrue()->and($typing->auto)->toBeTrue()
        ->and($typing->pageIndex)->toBe(0)->and($typing->pageCount)->toBe(2)
        ->and($typing->speaker)->toBe('Speaker')->and($typing->help)->toBe('Help')->and($typing->context)->toBe($context)
        ->and($last->page)->toBe('Last')->and($last->visibleText)->toBe('Last')
        ->and($last->isPrinting)->toBeFalse()->and($last->auto)->toBeFalse()->and($last->pageIndex)->toBe(1);
    expect(fn() => $pagination->getSnapshot(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $pagination->getSnapshot(2))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $pagination->getSnapshot(0, -1))->toThrow(InvalidArgumentException::class);
});

it('preserves the shared UTF-8 validation and minimum wrapping width', function () {
    expect(fn() => DialoguePaginationBuilder::buildPagination("\xFF", '', '', new DialogueContext(), 80, 24))
        ->toThrow(InvalidArgumentException::class)
        ->and(DialoguePaginationBuilder::wrapMessageIntoLines('ab', 0))->toBe(['a', 'b']);
});

it('shares exactly the runtime modal pages geometry and snapshots with an isolated owner', function (bool $graphical) {
    $theme = ['schema' => 'ichiloto.menu/1', 'metrics' => ['cellWidth' => 12, 'panelPadding' => 28]];
    writeTestPng($this->root . '/portrait.png', 8, 12);
    mkdir($this->root . '/Data/Presentation', 0700, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1', 'theme' => $theme,
        'actors' => ['hero' => ['portrait' => 'portrait.png']],
    ], true) . ';');
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 70, 'height' => 30]));
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    $game = new PaginationGameProbe();
    $runtime = null;
    if ($graphical) {
        $caps = ['graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'sprite_source_rect'];
        $peer = new FakeRendererTransport();
        $peer->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
        $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']),
            $this->root, cellWidth: 10, cellHeight: 20, requiredCapabilities: $caps), $peer);
        $runtime->start('Pagination fixture', 70, 30);
        $game->useRendererRuntime($runtime);
    }
    try {
        $message = str_repeat("One complete sentence.\n\n", 8) . "caf\u{00E9}";
        $context = new DialogueContext(actorId: 'hero');
        $help = 'Continue';
        $layout = $runtime?->getDialoguePageLayout('Speaker', $context, $help);
        $pagination = DialoguePaginationBuilder::buildPagination($message, 'Speaker', $help, $context, 70, 30,
            $layout, WindowPosition::MIDDLE);
        $modal = new PaginationTextBoxProbe($game, $message, 'Speaker', $help,
            WindowPosition::MIDDLE, presentation: $context);
        expect($modal->getPages())->toBe($pagination->pages)
            ->and($modal->rect->getWidth())->toBe($pagination->windowWidth)
            ->and($modal->rect->getHeight())->toBe($pagination->windowHeight)
            ->and($modal->rect->position)->toEqual(WindowPosition::MIDDLE->getCoordinates(
                $pagination->windowWidth, $pagination->windowHeight));
        foreach ($pagination->pages as $index => $page) {
            foreach ([0, 4, mb_strlen($page)] as $visibleCharacters) {
                $printing = $visibleCharacters < mb_strlen($page);
                $modal->selectPage($index, $visibleCharacters, $printing);
                expect($modal->getDialogueSnapshot())->toEqual($pagination->getSnapshot($index, $visibleCharacters, $printing));
            }
        }
    } finally { $runtime?->shutdown(); }
})->with([false, true]);

it('uses wider negotiated modal pages and the same terminal fallback window at 135 columns', function () {
    mkdir($this->root . '/Data/Presentation', 0700, true);
    file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1', 'metrics' => ['cellWidth' => 10, 'panelPadding' => 28]],
    ], true) . ';');
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    $peer = new FakeRendererTransport();
    $caps = ['graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'sprite_source_rect'];
    $peer->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
    $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']),
        $this->root, cellWidth: 10, cellHeight: 20, requiredCapabilities: $caps), $peer);
    $game = new PaginationGameProbe();
    try {
        $runtime->start('Wide pagination fixture', 135, 36);
        $game->useRendererRuntime($runtime);
        $message = str_repeat('A long synthetic sentence fills the negotiated reading area while every word remains available to the player. ', 10);
        $context = new DialogueContext();
        $layout = $runtime->getDialoguePageLayout('Speaker', $context, '');
        expect($layout)->not->toBeNull()->and($layout->columns)->toBeGreaterThan(DEFAULT_DIALOG_WIDTH - 4);
        $pagination = DialoguePaginationBuilder::buildPagination($message, 'Speaker', '', $context, 135, 36, $layout);
        $terminal = DialoguePaginationBuilder::buildPagination($message, 'Speaker', '', $context, 135, 36);
        $modal = new PaginationTextBoxProbe($game, $message, 'Speaker', presentation: $context);
        expect($modal->getPages())->toBe($pagination->pages)
            ->and(count($modal->getPages()))->toBeGreaterThan(1)->toBeLessThan(count($terminal->pages))
            ->and($modal->rect->getWidth())->toBe($layout->columns + 4)->toBeGreaterThan(DEFAULT_DIALOG_WIDTH)
            ->toBeLessThanOrEqual(135)
            ->and($modal->getTerminalContentWidth())->toBe($layout->columns);
        $catalogue = DialoguePresentationCatalog::load($this->root);
        $canvasWidth = min($runtime->grid->columns * $runtime->grid->cellWidth, PresentationCanvas::DEFAULT_WIDTH);
        $canvasHeight = $runtime->grid->rows * $runtime->grid->cellHeight;
        $longestLine = 0;
        foreach ($modal->getPages() as $index => $page) {
            $modal->selectPage($index, mb_strlen($page), false);
            expect($modal->getTerminalLines())->toBe(explode("\n", $page))
                ->and($modal->getDialogueSnapshot())->toEqual($pagination->getSnapshot($index));
            $canvas = DialogueCanvasPresentation::compose($modal->getDialogueSnapshot(), $catalogue, $canvasWidth, $canvasHeight);
            $prose = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'dialogue-text');
            expect($prose->grid->columns)->toBe($layout->columns)
                ->and($prose->grid->rows)->toBeLessThanOrEqual($layout->proseRows);
            foreach (explode("\n", $page) as $line) { $longestLine = max($longestLine, mb_strlen($line)); }
            $modal->selectPage($index, 48, true);
            expect($modal->getTerminalLines())->toBe(explode("\n", mb_substr($page, 0, 48)))
                ->and($modal->getDialogueSnapshot())->toEqual($pagination->getSnapshot($index, 48, true));
        }
        expect($longestLine)->toBeGreaterThan(DEFAULT_DIALOG_WIDTH - 4)->toBeLessThanOrEqual($layout->columns)
            ->and(trim(preg_replace('/\s+/', ' ', implode(' ', $modal->getPages()))))->toBe(trim($message))
            ->and($peer->starts)->toBe(1);
    } finally { $runtime->shutdown(); }
});
