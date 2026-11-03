<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\EventStorybookPresentationInterface;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\PassiveGame;

final class StorybookEventScene extends GameScene
{
    public function __construct(private Game $probeGame, private EventExecutionScope $scope = EventExecutionScope::FIELD)
    {
        $this->gameState = new GameState();
        $this->party = new Party();
        $this->currentMapId = 'synthetic/room';
    }
    public function getGame(): Game { return $this->probeGame; }
    public function getEventScope(): EventExecutionScope { return $this->scope; }
    public function onEventSessionStarted(EventExecutionSession $session): void {}
    public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
}

class StorybookEventSurface implements EventPresentationInterface
{
    public bool $complete = false;
    public bool $failUpdate = false;
    public int $resets = 0;
    public function beginText(string $text, string $speaker): void { $this->complete = false; }
    public function beginChoice(string $prompt, array $options, string $title = ''): void {}
    public function update(): void { if ($this->failUpdate) { throw new RuntimeException('Synthetic reader failure'); } }
    public function render(): void {}
    public function isComplete(): bool { return $this->complete; }
    public function choiceResult(): ?int { return null; }
    public function reset(): void { $this->resets++; $this->complete = false; }
}

final class StorybookEventReader extends StorybookEventSurface implements EventStorybookPresentationInterface
{
    public array $shown = [];
    public function beginStorybook(StorybookDefinition $book): void { $this->shown[] = $book; $this->complete = false; }
}

beforeEach(function () {
    $this->bookCwd = getcwd();
    $this->bookRoot = createTestDirectory('storybook-event-');
    mkdir($this->bookRoot . '/assets/Data/Storybooks', 0700, true);
    $source = ['id' => 'selected', 'title' => 'A journey', 'narrator' => 'Traveller',
        'pages' => [['id' => 'first', 'text' => 'First words.'], ['id' => 'last', 'text' => 'Last words.']]];
    $this->bookFile = $this->bookRoot . '/assets/Data/Storybooks/selected.php';
    file_put_contents($this->bookFile, '<?php return ' . var_export($source, true) . ';');
    $this->bookDebug = new ReflectionClass(Debug::class)->getStaticProperties();
    Debug::configure(['log_directory' => $this->bookRoot]);
    chdir($this->bookRoot);
    $this->bookScene = new StorybookEventScene(new PassiveGame());
    $this->bookSurface = new StorybookEventReader();
    $this->bookCatalog = ScriptCommandCatalog::createEngineCatalog();
});

afterEach(function () {
    chdir($this->bookCwd);
    foreach ($this->bookDebug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
});

it('opens a selected book cooperatively and resumes the script only when the reader closes', function (EventExecutionScope $scope) {
    $scene = new StorybookEventScene(new PassiveGame(), $scope);
    $runner = new EventInterpreter($scene, $this->bookSurface, $this->bookCatalog);
    $session = $runner->run([['type' => 'play_storybook', 'storybook' => 'selected'],
        ['type' => 'set_switch', 'name' => 'after', 'value' => true]], 'book-event');
    expect($session->status)->toBe(EventExecutionStatus::YIELDED)->and($this->bookSurface->shown)->toHaveCount(1)
        ->and($this->bookSurface->shown[0]->id)->toBe('selected')->and($scene->gameState->getSwitch('after'))->toBeFalse();
    $runner->update(0.1);
    expect($session->status)->toBe(EventExecutionStatus::YIELDED)->and($scene->gameState->getSwitch('after'))->toBeFalse();
    $this->bookSurface->complete = true;
    $runner->update(0.1);
    expect($session->status)->toBe(EventExecutionStatus::COMPLETED)->and($scene->gameState->getSwitch('after'))->toBeTrue();
})->with([EventExecutionScope::FIELD, EventExecutionScope::BATTLE]);

it('keeps book playback repeatable and free of hidden world-state writes', function () {
    $runner = new EventInterpreter($this->bookScene, $this->bookSurface, $this->bookCatalog);
    $state = $this->bookScene->gameState->toArray();
    $bytes = file_get_contents($this->bookFile);
    $script = [['type' => 'play_storybook', 'storybook' => 'selected']];
    for ($repeat = 0; $repeat < 2; $repeat++) {
        $session = $runner->run($script);
        expect($session->status)->toBe(EventExecutionStatus::YIELDED);
        $this->bookSurface->complete = true;
        $runner->update(0);
        expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
            ->and($this->bookScene->gameState->toArray())->toBe($state)->and(file_get_contents($this->bookFile))->toBe($bytes);
    }
    expect($this->bookSurface->shown)->toHaveCount(2);
});

it('releases a cancelled or failed book and permits a normal retry', function (bool $failure) {
    $runner = new EventInterpreter($this->bookScene, $this->bookSurface, $this->bookCatalog);
    $script = [['type' => 'play_storybook', 'storybook' => 'selected'], ['type' => 'set_switch', 'name' => 'after']];
    $session = $runner->run($script);
    if ($failure) { $this->bookSurface->failUpdate = true; $runner->update(0); }
    else { $runner->failActiveSession('Interrupted book'); }
    expect($session->status)->toBe(EventExecutionStatus::FAILED)
        ->and($this->bookScene->gameState->getSwitch('after'))->toBeFalse()->and($this->bookSurface->resets)->toBeGreaterThan(0);
    $this->bookSurface->failUpdate = false;
    $retry = $runner->run($script);
    expect($retry->status)->toBe(EventExecutionStatus::YIELDED)->and($this->bookSurface->shown)->toHaveCount(2);
    $runner->failActiveSession('Fixture complete');
})->with([true, false]);

it('refuses a missing book or unsupported presentation without continuing story commands', function (bool $unsupported) {
    $surface = $unsupported ? new StorybookEventSurface() : $this->bookSurface;
    $runner = new EventInterpreter($this->bookScene, $surface, $this->bookCatalog);
    $session = $runner->run([['type' => 'play_storybook', 'storybook' => $unsupported ? 'selected' : 'missing'],
        ['type' => 'set_switch', 'name' => 'after']], 'bad-book');
    expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($this->bookScene->gameState->getSwitch('after'))->toBeFalse()
        ->and($session->failureMessage)->toContain($unsupported ? 'cooperative storybook presentation' : 'missing');
})->with([true, false]);

it('refuses competing presentation lanes and preserves the ordinary presentation lock', function () {
    $runner = new EventInterpreter($this->bookScene, $this->bookSurface, $this->bookCatalog);
    $session = $runner->run([['type' => 'parallel', 'lanes' => [
        ['id' => 'reader', 'commands' => [['type' => 'play_storybook', 'storybook' => 'selected']]],
        ['id' => 'talker', 'commands' => [['type' => 'text', 'text' => 'Competing dialogue']]],
    ]]]);
    $runner->update(0);
    expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($this->bookSurface->shown)->toHaveCount(1)
        ->and($this->bookSurface->resets)->toBeGreaterThan(0);
});

it('exports the same selectable source and validates its stable reference before runtime', function () {
    CinematicScriptValidator::validate([['type' => 'play_storybook', 'storybook' => 'selected']], 'synthetic');
    $schema = CinematicCommandSchema::export()['storybookPlayback'];
    expect($schema['referenceField'])->toBe('storybook')->and($schema['definitionReader'])->toBe(StorybookDefinition::class)
        ->and($schema['referenceSource'])->toBe('assets/Data/Storybooks/*.php:id');
});

it('refuses malformed authored book references', function (mixed $id) {
    expect(fn() => CinematicScriptValidator::validate([['type' => 'play_storybook', 'storybook' => $id]], 'synthetic'))
        ->toThrow(InvalidArgumentException::class);
})->with(['empty' => '', 'null' => null, 'parent' => '../other', 'space' => ' selected ', 'uppercase' => 'Selected',
    'array' => [[]], 'numeric' => 4, 'nul' => "bad\0"]);
