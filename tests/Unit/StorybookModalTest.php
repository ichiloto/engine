<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\StorybookModal;
use Ichiloto\Engine\UI\Modal\StorybookReadingCapacity;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\PassiveGame;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class StorybookModalProbe extends StorybookModal
{
    public function selectControl(int $index): void { $this->activeIndex = $index; $this->submit(); }
}

function makeModalStorybook(string $text = 'A first paragraph.'): StorybookDefinition
{
    return StorybookDefinition::fromArray(['id' => 'tale', 'title' => 'A journey', 'narrator' => 'Traveller',
        'pages' => [['id' => 'first', 'text' => $text, 'picture' => 'Graphics/first.png'],
            ['id' => 'second', 'text' => 'An ending.', 'picture' => 'Graphics/second.png']]]);
}

beforeEach(function () {
    $this->storyStatics = [];
    foreach ([ConfigStore::class, Console::class, InputManager::class, EventManager::class, Timers::class, ActionHints::class] as $class) {
        $this->storyStatics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['audio' => ['music' => false, 'sfx' => false, 'voice' => false]]));
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
    Timers::setFrameTick(null);
    InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::ESCAPE]],
        'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
    ActionHints::useProvider(null);
    InputManager::setInputSource(new FakeInputSource());
    foreach (['width' => 80, 'height' => 24, 'buffer' => array_fill(0, 24, array_fill(0, 80, ' ')),
        'frameDepth' => 0, 'frameRows' => [], 'terminalOutputStream' => null, 'output' => null] as $name => $value) {
        new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    $this->storyGame = new PassiveGame();
    $silent = new class extends AudioManager {
        public function __construct() {}
        public function playSystemSound(SystemSound $sound): void {}
    };
    new ReflectionProperty(PassiveGame::class, 'audioManager')->setValue($this->storyGame, $silent);
});

afterEach(function () {
    foreach ($this->storyStatics as $class => $values) {
        foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
});

it('uses one immutable modal snapshot while forward and previous controls move the reader', function () {
    $book = makeModalStorybook();
    $modal = new StorybookModalProbe($this->storyGame, $book);
    $first = $modal->getModalPresentation();
    expect($first->storybook->page)->toBe($book->pages[0])->and($first->choices)->toBe(['Next'])
        ->and($first->storybook->pageNumber)->toBe(1)->and($first->storybook->pageCount)->toBe(2)
        ->and($first->storybook->readingText)->toBe($first->message)
        ->and($first->storybook->narrator)->toBe($book->narrator)
        ->and($first->storybook->capacity->columns)->toBe(72)->and($first->storybook->capacity->rows)->toBe(16);
    $modal->selectControl(0);
    $second = $modal->getModalPresentation();
    expect($second->choices)->toBe(['Previous', 'Close'])->and($second->activeIndex)->toBe(1)
        ->and($second->storybook->page)->toBe($book->pages[1])->and($first->storybook->page)->toBe($book->pages[0]);
    $modal->buttons[0] = 'Rebound label';
    $modal->selectControl(0);
    expect($modal->getModalPresentation()->storybook->pageNumber)->toBe(1)
        ->and($first->storybook->pageNumber)->toBe(1)->and($first->message)->toBe($book->pages[0]->text);
});

it('negotiates the stricter common reading area and keeps its painting through continuations', function () {
    $book = makeModalStorybook('First words. More words. Further words.');
    $modal = new StorybookModalProbe($this->storyGame, $book, new StorybookReadingCapacity(12, 2));
    $view = $modal->getModalPresentation()->storybook;
    expect($view->capacity->columns)->toBe(12)->and($view->capacity->rows)->toBe(2)
        ->and($view->continuationCount)->toBeGreaterThan(1);
    $modal->selectControl(0);
    $next = $modal->getModalPresentation()->storybook;
    expect($next->page)->toBe($view->page)->and($next->continuationNumber)->toBe(2)
        ->and($next->page->picture)->toBe('Graphics/first.png')->and($view->continuationNumber)->toBe(1);
    $wide = new StorybookModalProbe($this->storyGame, $book, new StorybookReadingCapacity(200, 200));
    expect($wide->getModalPresentation()->storybook->capacity->columns)->toBe(72)
        ->and($wide->getModalPresentation()->storybook->capacity->rows)->toBe(16);
});

it('renders a bounded Terminal frame with readable prose and a centered button', function () {
    $modal = new StorybookModalProbe($this->storyGame, makeModalStorybook("First paragraph.\n\nSecond paragraph."));
    ob_start();
    try { $modal->render(); } finally { ob_end_clean(); }
    $lines = array_map(TerminalText::stripAnsi(...), Console::getBuffer());
    expect(implode("\n", $lines))->toContain('◇', 'A journey', 'Traveller', 'First paragraph.', 'Second paragraph.', 'Next', '1/2');
    $bounds = $modal->getPresentationBounds();
    expect($bounds->getX())->toBeGreaterThanOrEqual(0)->and($bounds->getY())->toBeGreaterThanOrEqual(0)
        ->and($bounds->getX() + $bounds->getWidth())->toBeLessThanOrEqual(80)
        ->and($bounds->getY() + $bounds->getHeight())->toBeLessThanOrEqual(24);
    $button = array_values(array_filter($lines, static fn(string $line): bool => str_contains($line, 'Next')))[0];
    expect(TerminalText::displayWidth(substr($button, 0, strpos($button, 'Next'))))->toBe(intdiv(80 - strlen('Next'), 2));
});

it('consumes opening and closing actions while preserving unread caller input', function (KeyCode $closing) {
    $source = new FakeInputSource(KeyCode::ENTER, $closing, KeyCode::RIGHT);
    InputManager::setInputSource($source);
    InputManager::handleInput();
    $modal = new StorybookModalProbe($this->storyGame, makeModalStorybook());
    $modal->show();
    expect(Input::isButtonDown('confirm'))->toBeFalse()->and($modal->isShowing())->toBeTrue();
    InputManager::handleInput();
    if ($closing === KeyCode::ENTER) {
        $modal->update(); // Advance to the last authored page.
        $source->keys = [KeyCode::ENTER, KeyCode::RIGHT];
        InputManager::handleInput();
    }
    ob_start();
    try { $modal->update(); } finally { ob_end_clean(); }
    expect($modal->isShowing())->toBeFalse()->and(InputManager::getPressedKeyCode())->toBeNull()
        ->and($source->resets)->not->toContain(true);
    InputManager::handleInput();
    expect(Input::isButtonDown('right'))->toBeTrue();
    $modal->close();
    expect(Input::isButtonDown('right'))->toBeTrue();
})->with([[KeyCode::ENTER], [KeyCode::ESCAPE]]);

it('keeps generic modal snapshots compatible and refuses mixed reader controls', function () {
    expect(new ModalPresentation('Title', 'Text', ['OK'], 0)->storybook)->toBeNull();
    $reader = new StorybookModalProbe($this->storyGame, makeModalStorybook())->getModalPresentation()->storybook;
    expect(fn() => new ModalPresentation('Title', 'Text', ['OK'], 0, vertical: true, storybook: $reader))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new StorybookReadingCapacity(0, 3))->toThrow(InvalidArgumentException::class);
});

it('reflows after a viewport resize while retaining the authored page and reading position', function () {
    $text = implode(' ', array_map(static fn(int $i): string => 'word' . $i, range(1, 90)));
    $book = makeModalStorybook($text);
    $modal = new StorybookModalProbe($this->storyGame, $book, new StorybookReadingCapacity(40, 3));
    $modal->selectControl(0);
    $modal->selectControl(1);
    $before = $modal->getModalPresentation()->storybook;
    $anchor = preg_split('/\s+/u', trim($before->readingText))[0];
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 40, 'height' => 20]));
    ob_start();
    try { $modal->update(); } finally { ob_end_clean(); }
    $after = $modal->getModalPresentation()->storybook;
    expect($after->page)->toBe($before->page)->and($after->readingText)->toContain($anchor)
        ->and($after->capacity->columns)->toBe(32)->and($after->capacity->rows)->toBe(3)
        ->and($modal->getPresentationBounds()->getX() + $modal->getPresentationBounds()->getWidth())->toBeLessThanOrEqual(40);
});

it('refuses an unreadable frame before changing the reader snapshot', function () {
    $modal = new StorybookModalProbe($this->storyGame, makeModalStorybook());
    $before = $modal->getModalPresentation()->storybook;
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 12, 'height' => 10]));
    expect(fn() => $modal->update())->toThrow(RuntimeException::class, 'wider Terminal viewport')
        ->and($modal->getModalPresentation()->storybook->page)->toBe($before->page)
        ->and($modal->getModalPresentation()->storybook->capacity->columns)->toBe($before->capacity->columns);
});

it('refuses a modal whose generic text differs from its reader snapshot', function () {
    $view = new StorybookModalProbe($this->storyGame, makeModalStorybook())->getModalPresentation()->storybook;
    expect(fn() => new ModalPresentation('Title', 'Wrong text', ['OK'], 0, storybook: $view))
        ->toThrow(InvalidArgumentException::class, 'owner-selected narration');
});

it('refreshes control hints and their reading budget when the display profile changes', function () {
    $modal = new StorybookModalProbe($this->storyGame, makeModalStorybook());
    $before = $modal->getModalPresentation()->storybook;
    ActionHints::useProvider(new class implements \Ichiloto\Engine\IO\ActionHintProvider {
        public function controlForAction(string $action): ?\Ichiloto\Engine\IO\ControlHint
        {
            return new \Ichiloto\Engine\IO\ControlHint('controller', $action,
                $action === 'confirm' ? 'Primary controller button with a readable extended control label' : 'Secondary button');
        }
    });
    ob_start();
    try { $modal->update(); $modal->render(); } finally { ob_end_clean(); }
    $after = $modal->getModalPresentation()->storybook;
    $lines = array_map(TerminalText::stripAnsi(...), Console::getBuffer());
    $shown = preg_replace('/\s+/u', ' ', implode(' ', $lines));
    expect($after->page)->toBe($before->page)->and($after->capacity->rows)->toBeLessThan($before->capacity->rows)
        ->and($shown)->toContain('Primary controller button with a readable extended control label', 'Secondary button');
});

it('wraps a long title and narrator without reducing the reading area below one row', function () {
    $book = StorybookDefinition::fromArray(['id' => 'long-labels', 'title' => str_repeat('Long title ', 12),
        'narrator' => str_repeat('Traveller ', 10), 'pages' => [['id' => 'page', 'text' => 'Narration remains readable.']]]);
    $modal = new StorybookModalProbe($this->storyGame, $book);
    ob_start();
    try { $modal->render(); } finally { ob_end_clean(); }
    expect($modal->getModalPresentation()->title)->toBe($book->title)
        ->and($modal->getModalPresentation()->storybook->narrator)->toBe($book->narrator)
        ->and($modal->getModalPresentation()->storybook->capacity->rows)->toBeGreaterThanOrEqual(1)
        ->and($modal->getPresentationBounds()->getY() + $modal->getPresentationBounds()->getHeight())->toBeLessThanOrEqual(24);
});

it('opens and resets a cooperative book through the existing event presentation host', function () {
    $host = new class($this->storyGame) implements \Ichiloto\Engine\Events\Interpreter\EventPresentationHostInterface {
        public int $restored = 0;
        public function __construct(private \Ichiloto\Engine\Core\Game $game) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->game; }
        public function restoreAfterEventOverlay(): void { $this->restored++; }
    };
    $surface = new \Ichiloto\Engine\Events\Interpreter\ModalEventPresentation($host);
    $surface->beginStorybook(makeModalStorybook());
    expect($surface->isComplete())->toBeFalse();
    $source = new FakeInputSource(KeyCode::ESCAPE);
    InputManager::setInputSource($source);
    InputManager::handleInput();
    ob_start();
    try { $surface->update(); } finally { ob_end_clean(); }
    expect($surface->isComplete())->toBeTrue();
    $surface->reset();
    $surface->reset();
    expect($host->restored)->toBe(1)->and(InputManager::getPressedKeyCode())->toBeNull();
});

it('uses the presentation hosts measured graphical capacity without launching a renderer', function () {
    $host = new class($this->storyGame) implements \Ichiloto\Engine\Events\Interpreter\EventPresentationHostInterface,
        \Ichiloto\Engine\UI\Interfaces\StorybookReadingCapacityProviderInterface {
        public function __construct(private \Ichiloto\Engine\Core\Game $game) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->game; }
        public function restoreAfterEventOverlay(): void {}
        public function getStorybookReadingCapacity(StorybookDefinition $book): StorybookReadingCapacity
        { return new StorybookReadingCapacity(30, 3); }
    };
    $surface = new \Ichiloto\Engine\Events\Interpreter\ModalEventPresentation($host);
    $surface->beginStorybook(makeModalStorybook());
    $modal = new ReflectionProperty($surface, 'modal')->getValue($surface);
    expect($modal->getModalPresentation()->storybook->capacity->columns)->toBe(30)
        ->and($modal->getModalPresentation()->storybook->capacity->rows)->toBe(3);
    ob_start();
    try { $surface->reset(); } finally { ob_end_clean(); }
});

it('requires measured capacity for a graphical host before replacing its current presentation', function () {
    $runtime = new ReflectionClass(\Ichiloto\Engine\Rendering\Runtime\RendererRuntime::class)->newInstanceWithoutConstructor();
    new ReflectionProperty($runtime, 'grid')->setValue($runtime, new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig());
    $game = new class($runtime) extends PassiveGame {
        public function __construct(private \Ichiloto\Engine\Rendering\Runtime\RendererRuntime $runtime) {}
        public function getRendererRuntime(): ?\Ichiloto\Engine\Rendering\Runtime\RendererRuntime { return $this->runtime; }
    };
    $host = new class($game) implements \Ichiloto\Engine\Events\Interpreter\EventPresentationHostInterface {
        public int $restored = 0;
        public function __construct(private \Ichiloto\Engine\Core\Game $game) {}
        public function getGame(): \Ichiloto\Engine\Core\Game { return $this->game; }
        public function restoreAfterEventOverlay(): void { $this->restored++; }
    };
    $surface = new \Ichiloto\Engine\Events\Interpreter\ModalEventPresentation($host);
    $owned = new StorybookModalProbe($game, makeModalStorybook());
    new ReflectionProperty($surface, 'modal')->setValue($surface, $owned);
    InputManager::setInputSource(new FakeInputSource(KeyCode::ENTER));
    InputManager::handleInput();
    expect(fn() => $surface->beginStorybook(makeModalStorybook()))
        ->toThrow(RuntimeException::class, 'measured reading-capacity provider')
        ->and(new ReflectionProperty($surface, 'modal')->getValue($surface))->toBe($owned)
        ->and($host->restored)->toBe(0)->and(Input::isButtonDown('confirm'))->toBeTrue();
});
