<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\SpeechSequence;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPlayedDialoguePresentationInterface;
use Ichiloto\Engine\Field\SkitManager;
use Ichiloto\Engine\Field\SkitPlaybackPlan;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

final class ScriptedSkitAudio extends AudioManager
{
    public array $cues = [];
    public array $voices = [];
    public int $sequences = 0;
    public int $released = 0;
    public function __construct() {}
    public function __destruct() {}
    public function playSoundEffect(string $path): void { $this->cues[] = $path; }
    public function beginSpeechSequence(): SpeechSequence { ++$this->sequences; return new SpeechSequence(); }
    public function endSpeechSequence(SpeechSequence $owner): void { ++$this->released; }
    public function releaseSpeechDucking(?SpeechSequence $owner = null): void {}
    public function playSpeech(string $path, float $musicDuckFactor = 1.0): ?AudioPlayback
    { $this->voices[] = [$path, $musicDuckFactor]; return null; }
}

final class ScriptedSkitGame extends Game
{
    public bool $stopped = false;
    public function __construct() { $this->audioManager = new ScriptedSkitAudio(); }
    public function __destruct() {}
    public function hasStopped(): bool { return $this->stopped; }
}

final class ScriptedSkitScene extends GameScene
{
    public function __construct(public ScriptedSkitGame $probeGame)
    {
        $this->gameState = new GameState();
        $this->party = new Party();
        $this->currentMapId = 'synthetic/room';
        $this->skitManager = new SkitManager($this);
    }
    public function getGame(): Game { return $this->probeGame; }
    public function onEventSessionStarted(EventExecutionSession $session): void {}
    public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
}

final class ScriptedSkitPresentation implements EventPlayedDialoguePresentationInterface, \Ichiloto\Engine\Events\Interpreter\EventPausablePresentationInterface
{
    public array $shown = [];
    public bool $complete = false;
    public bool $failUpdate = false;
    public bool $failPause = false;
    public int $resets = 0;
    public int $updates = 0;
    public array $pauseTimes = [];
    public array $resumeTimes = [];
    public function pausePlayback(float $now): void
    {
        $this->pauseTimes[] = $now;
        if ($this->failPause) { throw new RuntimeException('Synthetic pause failure'); }
    }
    public function resumePlayback(float $now): void { $this->resumeTimes[] = $now; }
    public function beginText(string $text, string $speaker): void {}
    public function beginDialogue(string $text, string $speaker, DialogueContext $context): void {}
    public function beginPlayedDialogue(string $text, string $speaker, DialogueContext $context,
        DialoguePlayback $playback, float $charactersPerSecond): void
    { $this->shown[] = [$text, $speaker, $context, $playback, $charactersPerSecond]; $this->complete = false; }
    public function beginChoice(string $prompt, array $options, string $title = ''): void {}
    public function update(): void { ++$this->updates; if ($this->failUpdate) { throw new RuntimeException('Synthetic presentation failure'); } }
    public function render(): void {}
    public function isComplete(): bool { return $this->complete; }
    public function choiceResult(): ?int { return null; }
    public function reset(): void { ++$this->resets; $this->complete = false; }
}

beforeEach(function () {
    $this->oldDirectory = getcwd();
    $this->root = createTestDirectory('scripted-skit-');
    mkdir($this->root . '/assets/Data/Skits', 0700, true);
    mkdir($this->root . '/assets/Data/Actors', 0700, true);
    mkdir($this->root . '/assets/Audio/Voice/Skits/selected', 0700, true);
    file_put_contents($this->root . '/assets/Audio/Voice/Skits/selected/line.mp3', 'synthetic silent fixture');
    $skits = [
        'optional' => ['id' => 'optional', 'title' => 'Another available conversation', 'beats' => [['text' => 'Optional']]],
        'selected' => ['id' => 'selected', 'title' => 'Selected conversation', 'trigger' => 'script',
            'where' => 'synthetic/room', 'backdrop' => 'Graphics/Synthetic/Room.png', 'speed' => 42,
            'conditions' => [['type' => 'switch', 'name' => 'ready']],
            'sounds' => ['open' => 'SyntheticOn', 'close' => 'SyntheticOff'],
            'beats' => [['speaker' => 'Guide', 'text' => 'First', 'voice' => 'line'], ['speaker' => 'Guest', 'text' => 'Second']]],
    ];
    foreach ($skits as $id => $definition) {
        file_put_contents($this->root . '/assets/Data/Skits/' . $id . '.php', '<?php return ' . var_export($definition, true) . ';');
    }
    $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
    Debug::configure(['log_directory' => $this->root]);
    chdir($this->root);
    $this->game = new ScriptedSkitGame();
    $this->scene = new ScriptedSkitScene($this->game);
    $this->scene->gameState->setSwitch('ready', true);
    $this->presentation = new ScriptedSkitPresentation();
});

afterEach(function () {
    chdir($this->oldDirectory);
    foreach ($this->debugState as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('selects the requested Skit, yields across beats and commits seen state only after completion', function () {
    $manager = $this->scene->skitManager;
    expect($manager->getAvailablePrompt()->id)->toBe('optional');
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $script = [['type' => 'play_skit', 'skit' => 'selected'], ['type' => 'set_switch', 'name' => 'after', 'value' => true]];
    $session = $interpreter->run($script);
    expect($session->status)->toBe(EventExecutionStatus::YIELDED)
        ->and($manager->isPlaying)->toBeTrue()->and($manager->getAvailablePrompt())->toBeNull()
        ->and($this->presentation->shown)->toBeEmpty()->and($this->game->audioManager->cues)->toBeEmpty();
    $interpreter->update(0.01);
    expect(array_column($this->presentation->shown, 0))->toBe(['First'])
        ->and($this->presentation->shown[0][2]->skitId)->toBe('selected')
        ->and($this->presentation->shown[0][2]->backdrop)->toBe('Graphics/Synthetic/Room.png')
        ->and($this->presentation->shown[0][4])->toBe(42.0)
        ->and($this->game->audioManager->cues)->toBe(['SyntheticOn'])
        ->and($this->game->audioManager->voices)->toHaveCount(1)
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse();
    $interpreter->update(1);
    expect($this->presentation->shown)->toHaveCount(1)->and($this->scene->gameState->getSwitch('after'))->toBeFalse();
    $this->presentation->complete = true;
    $interpreter->update(0.01);
    expect(array_column($this->presentation->shown, 0))->toBe(['First', 'Second'])
        ->and($this->presentation->shown[1][3])->toBe($this->presentation->shown[0][3])
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse();
    $this->presentation->complete = true;
    $interpreter->update(0.01);
    expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
        ->and($this->scene->gameState->getSwitch('after'))->toBeTrue()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeTrue()
        ->and($manager->isPlaying)->toBeFalse()->and($manager->getAvailablePrompt()->id)->toBe('optional')
        ->and($this->game->audioManager->cues)->toBe(['SyntheticOn', 'SyntheticOff'])
        ->and($this->game->audioManager->sequences)->toBe(1)->and($this->game->audioManager->released)->toBe(1);
    $interpreter->run($script);
    expect($this->presentation->shown)->toHaveCount(2)->and($this->game->audioManager->cues)->toHaveCount(2);
});

it('cancels owned conversation resources and permits a retry without advancing story commands', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $script = [['type' => 'play_skit', 'skit' => 'selected'], ['type' => 'set_switch', 'name' => 'after']];
    $session = $interpreter->run($script);
    $interpreter->update(0);
    $interpreter->failActiveSession('Synthetic interrupted event');
    expect($session->status)->toBe(EventExecutionStatus::FAILED)
        ->and($this->scene->skitManager->isPlaying)->toBeFalse()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse()
        ->and($this->scene->gameState->getSwitch('after'))->toBeFalse()
        ->and($this->game->audioManager->cues)->toBe(['SyntheticOn', 'SyntheticOff'])
        ->and($this->game->audioManager->released)->toBe(1);
    $retry = $interpreter->run($script);
    $interpreter->update(0);
    expect($retry->status)->toBe(EventExecutionStatus::YIELDED)
        ->and($this->presentation->shown)->toHaveCount(2)->and($this->game->audioManager->sequences)->toBe(2);
    $interpreter->failActiveSession('End fixture');
    expect($this->game->audioManager->released)->toBe(2);
});

it('releases playback after a presentation failure or stopped game without recording completion', function (bool $stop) {
    $operation = $this->scene->skitManager->beginSkitPlayback('selected', $this->presentation);
    $operation->update(0);
    $this->game->stopped = $stop;
    $this->presentation->failUpdate = !$stop;
    expect(fn() => $operation->update(0))->toThrow(RuntimeException::class)
        ->and($this->scene->skitManager->isPlaying)->toBeFalse()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse()
        ->and($this->game->audioManager->released)->toBe(1)
        ->and($this->game->audioManager->cues)->toBe($stop ? ['SyntheticOn'] : ['SyntheticOn', 'SyntheticOff']);
    $operation->cancel();
    expect($this->game->audioManager->released)->toBe(1);
})->with([true, false]);

it('refuses unknown, gated or overlapping conversations and exports the selected resource contract', function () {
    expect(fn() => $this->scene->skitManager->beginSkitPlayback('absent', $this->presentation))->toThrow(RuntimeException::class);
    $this->scene->gameState->setSwitch('ready', false);
    expect(fn() => $this->scene->skitManager->beginSkitPlayback('selected', $this->presentation))->toThrow(RuntimeException::class)
        ->and($this->scene->skitManager->isPlaying)->toBeFalse();
    $this->scene->gameState->setSwitch('ready', true);
    $operation = $this->scene->skitManager->beginSkitPlayback('selected', $this->presentation);
    expect(fn() => $this->scene->skitManager->beginSkitPlayback('optional', $this->presentation))->toThrow(RuntimeException::class);
    $operation->cancel();
    expect($this->game->audioManager->cues)->toBeEmpty()->and($this->game->audioManager->sequences)->toBe(0);
    CinematicScriptValidator::validate([['type' => 'play_skit', 'skit' => 'selected']], 'synthetic');
    expect(fn() => CinematicScriptValidator::validate([['type' => 'play_skit']], 'synthetic'))
        ->toThrow(InvalidArgumentException::class)
        ->and(CinematicCommandSchema::export()['skitPlayback']['referenceField'])->toBe('skit');
    foreach ([['other' => 'cue'], ['open' => 42], ['close' => '']] as $sounds) {
        expect(fn() => SkitPlaybackPlan::readSounds($sounds, 'Synthetic'))->toThrow(InvalidArgumentException::class);
    }
});

it('keeps completion in restored world state and rejects competing presentation lanes', function () {
    $operation = $this->scene->skitManager->beginSkitPlayback('selected', $this->presentation);
    $operation->update(0);
    $this->presentation->complete = true;
    $operation->update(0);
    $this->presentation->complete = true;
    $operation->update(0);
    $restored = GameState::fromArray($this->scene->gameState->toArray());
    $this->scene->gameState->restoreSnapshot($restored->toArray());
    $newManager = new SkitManager($this->scene);
    expect($newManager->beginSkitPlayback('selected', $this->presentation))->toBeNull()
        ->and($this->game->audioManager->sequences)->toBe(1);

    // A fresh scripted conversation may not take the modal from a parallel text lane.
    $this->scene->gameState->restoreSnapshot(new GameState()->toArray());
    $this->scene->gameState->setSwitch('ready', true);
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $session = $interpreter->run([['type' => 'parallel', 'lanes' => [
        ['id' => 'dialogue', 'commands' => [['type' => 'text', 'text' => 'Other owner']]],
        ['id' => 'skit', 'commands' => [['type' => 'play_skit', 'skit' => 'selected']]],
    ]]]);
    $interpreter->update(0);
    expect($session->status)->toBe(EventExecutionStatus::FAILED)
        ->and($this->scene->skitManager->isPlaying)->toBeFalse()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse()
        ->and($this->game->audioManager->sequences)->toBe(1);
});


it('shares the typed playback reader without resolving assets or mutating authored fields', function () {
    $source = ['trigger' => 'script', 'sounds' => ['open' => 'DeviceOn'], 'title' => 'Authored title'];
    $definition = \Ichiloto\Engine\Field\SkitPlaybackDefinition::fromArray($source, 'Synthetic');
    expect($definition->trigger)->toBe(\Ichiloto\Engine\Field\Enumerations\SkitTrigger::SCRIPT)
        ->and($definition->sounds)->toBe(['open' => 'DeviceOn'])
        ->and($source)->toBe(['trigger' => 'script', 'sounds' => ['open' => 'DeviceOn'], 'title' => 'Authored title'])
        ->and(\Ichiloto\Engine\Field\SkitPlaybackDefinition::fromArray([])->trigger)
        ->toBe(\Ichiloto\Engine\Field\Enumerations\SkitTrigger::PROMPT);
    foreach ([['trigger' => 'unknown'], ['trigger' => []], ['sounds' => ['open' => '']], ['sounds' => ['other' => 'cue']]] as $invalid) {
        expect(fn() => \Ichiloto\Engine\Field\SkitPlaybackDefinition::fromArray($invalid, 'Synthetic'))
            ->toThrow(InvalidArgumentException::class, 'Synthetic');
    }
});

it('reports malformed authored files with their source and reason instead of silently dropping them', function (mixed $invalid, string $reason) {
    $filename = $this->root . '/assets/Data/Skits/broken.php';
    file_put_contents($filename, '<?php return ' . var_export($invalid, true) . ';');
    try {
        SkitManager::loadSkits();
        test()->fail('Malformed authored Skit was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain($filename, $reason)
            ->and($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
    unlink($filename);
    expect(array_keys(SkitManager::loadSkits()))->toBe(['optional', 'selected']);
})->with([
    'non-array source' => [false, 'non-empty string id'],
    'missing identity' => [['beats' => [['text' => 'Line']]], 'non-empty string id'],
    'missing beats' => [['id' => 'broken'], 'non-empty list'],
    'empty beats' => [['id' => 'broken', 'beats' => []], 'non-empty list'],
    'malformed beat' => [['id' => 'broken', 'beats' => ['Line']], 'beat 0 requires text'],
    'invalid trigger' => [['id' => 'broken', 'beats' => [['text' => 'Line']], 'trigger' => 'wrong'], 'trigger must'],
    'invalid sound' => [['id' => 'broken', 'beats' => [['text' => 'Line']], 'sounds' => ['open' => false]], 'sounds.open'],
]);

it('reports parse failures and duplicate identities without installing a partial catalog', function () {
    $filename = $this->root . '/assets/Data/Skits/broken.php';
    file_put_contents($filename, '<?php return [;');
    try {
        SkitManager::loadSkits();
        test()->fail('Parse failure was hidden.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain($filename)
            ->and($exception->getPrevious())->toBeInstanceOf(ParseError::class);
    }
    file_put_contents($filename, '<?php return ["id" => "selected", "beats" => [["text" => "Other"]]];');
    expect(fn() => SkitManager::loadSkits())->toThrow(RuntimeException::class, "already defined");
    unlink($filename);
    expect(SkitManager::loadSkits()['selected']['beats'][0]['text'])->toBe('First');
});


it('uses the shared location names in Skit context while preserving authored place and region', function () {
    $this->scene->party->location = new \Ichiloto\Engine\Entities\PartyLocation('Synthetic Port', 'Synthetic Port');
    $operation = $this->scene->skitManager->beginSkitPlayback('selected', $this->presentation);
    $operation->update(0);
    expect($this->presentation->shown[0][2]->location)->toBe('Synthetic Port')
        ->and($this->scene->party->location->region)->toBe('Synthetic Port');
    $operation->cancel();
});


it('freezes a cooperative Skit and parallel wait, resumes the same beat, and releases a cancelled pause', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $session = $interpreter->run([['type' => 'parallel', 'lanes' => [
        ['id' => 'speech', 'commands' => [['type' => 'play_skit', 'skit' => 'selected']]],
        ['id' => 'timer', 'commands' => [['type' => 'wait', 'seconds' => 1],
            ['type' => 'set_switch', 'name' => 'timer_done']]],
    ]], ['type' => 'set_switch', 'name' => 'after']]);
    $interpreter->update(0);
    $interpreter->update(0);
    expect($this->presentation->shown)->toHaveCount(1);
    $shown = $this->presentation->shown;
    $updates = $this->presentation->updates;
    expect($interpreter->pausePlayback(10))->toBeTrue()->and($interpreter->pausePlayback(20))->toBeFalse();
    $interpreter->update(120);
    expect($this->presentation->shown)->toBe($shown)->and($this->presentation->updates)->toBe($updates)
        ->and($this->scene->gameState->getSwitch('timer_done'))->toBeFalse()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse();
    $interpreter->resumePlayback(110);
    $interpreter->resumePlayback(120);
    expect($this->presentation->pauseTimes)->toBe([10.0])->and($this->presentation->resumeTimes)->toBe([110.0]);
    $interpreter->update(0.5);
    expect($this->presentation->shown)->toBe($shown)->and($this->scene->gameState->getSwitch('timer_done'))->toBeFalse();
    expect($interpreter->pausePlayback())->toBeTrue();
    $interpreter->failActiveSession('Synthetic exit to title');
    expect($session->status)->toBe(EventExecutionStatus::FAILED)
        ->and($interpreter->isPlaybackPaused)->toBeFalse()
        ->and($this->presentation->resumeTimes)->toHaveCount(2)
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse()
        ->and($this->scene->gameState->getSwitch('after'))->toBeFalse()
        ->and($this->scene->skitManager->isPlaying)->toBeFalse();
});

it('skips only the current Skit and applies its enclosing outcomes once', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $session = $interpreter->run([['type' => 'play_skit', 'skit' => 'selected'],
        ['type' => 'set_variable', 'name' => 'completed', 'op' => 'add', 'value' => 1]]);
    $interpreter->update(0);
    $interpreter->pausePlayback();
    expect($interpreter->canSkipPlayback())->toBeTrue()->and($interpreter->skipActivePlayback())->toBeTrue()
        ->and($session->status)->toBe(EventExecutionStatus::COMPLETED)
        ->and($interpreter->isPlaybackPaused)->toBeFalse()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeTrue()
        ->and($this->scene->gameState->getVariable('completed'))->toBe(1)
        ->and($this->presentation->shown)->toHaveCount(1)
        ->and($this->game->audioManager->released)->toBe(1);
    expect($interpreter->skipActivePlayback())->toBeFalse();
    $interpreter->update(999);
    expect($this->scene->gameState->getVariable('completed'))->toBe(1);
});


it('counts a paused field event as paused play and protects the unadvanced field presentation', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($this->scene, $interpreter);
    $field = new \Ichiloto\Engine\Scenes\Game\States\FieldState(new \Ichiloto\Engine\Scenes\SceneStateContext($this->scene));
    new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
    $interpreter->run([['type' => 'play_skit', 'skit' => 'selected']]);
    $interpreter->pausePlayback();
    expect($this->scene->getPlayActivity())->toBe(\Ichiloto\Engine\Core\PlayActivity::PAUSED);
    // No player, camera or map is installed: a paused presentation must touch none of them.
    $this->scene->advanceFieldPresentation(100);
    $this->scene->update();
    $interpreter->failActiveSession('End paused field fixture');
});


it('skips a displayed Skit without bypassing an enclosing cinematic that forbids whole-scene skip', function () {
    $cinematic = \Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition::fromArrays([
        'id' => 'synthetic-skit-cinematic', 'name' => 'Synthetic sequence', 'skip' => ['policy' => 'forbidden'],
    ], [['type' => 'play_skit', 'skit' => 'selected'], ['type' => 'wait', 'seconds' => 10],
        ['type' => 'set_switch', 'name' => 'after']]);
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $session = $interpreter->runCinematic($cinematic);
    $interpreter->update(0);
    $interpreter->pausePlayback();
    expect($interpreter->skipActivePlayback())->toBeTrue()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeTrue()
        ->and($session->status)->toBe(EventExecutionStatus::YIELDED)
        ->and($session->pendingCommand['type'])->toBe('wait')
        ->and($this->scene->gameState->getSwitch('after'))->toBeFalse()
        ->and($interpreter->canSkipPlayback())->toBeFalse();
    $interpreter->failActiveSession('End enclosing-cinematic fixture');
});

it('leaves suspended battle ownership intact when field pause or skip is requested', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $session = $interpreter->start([['type' => 'wait', 'seconds' => 10]]);
    $session->suspendFor(['type' => 'start_battle']);
    expect($interpreter->canPausePlayback())->toBeFalse()->and($interpreter->pausePlayback())->toBeFalse()
        ->and($interpreter->canSkipPlayback())->toBeFalse()->and($interpreter->skipActivePlayback())->toBeFalse()
        ->and($session->status)->toBe(EventExecutionStatus::SUSPENDED);
    $interpreter->failActiveSession('End suspended owner fixture');
});


it('releases a failed presentation pause without advancing its beat or retaining an audio owner', function () {
    $interpreter = new EventInterpreter($this->scene, $this->presentation);
    $interpreter->run([['type' => 'play_skit', 'skit' => 'selected']]);
    $interpreter->update(0);
    $this->presentation->failPause = true;
    expect(fn() => $interpreter->pausePlayback(10))->toThrow(RuntimeException::class, 'Synthetic pause failure')
        ->and($interpreter->isPlaybackPaused)->toBeFalse()
        ->and($this->presentation->resumeTimes)->toBe([10.0])
        ->and(new ReflectionProperty(AudioManager::class, 'pauseOwners')->getValue($this->game->audioManager))->toBeEmpty()
        ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse();
    $this->presentation->failPause = false;
    expect($interpreter->pausePlayback(20))->toBeTrue();
    $interpreter->failActiveSession('End failed-pause fixture');
});


final class ScriptedPauseMenuProbe extends \Ichiloto\Engine\Events\Interpreter\EventPlaybackPauseMenu
{
    public function getModal(): ?\Ichiloto\Engine\UI\Modal\SelectModal { return $this->modal; }
}

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

describe('cooperative playback pause menu', function () {
    beforeEach(function () {
        $this->pauseUiGlobals = [];
        foreach ([\Ichiloto\Engine\IO\Console\Console::class, \Ichiloto\Engine\IO\InputManager::class,
            \Ichiloto\Engine\Util\Config\ConfigStore::class, \Ichiloto\Engine\Events\EventManager::class] as $class) {
            $this->pauseUiGlobals[$class] = new ReflectionClass($class)->getStaticProperties();
        }
        \Ichiloto\Engine\IO\Console\Console::setTerminalOutputEnabled(false);
        \Ichiloto\Engine\IO\Console\Console::syncDimensions(80, 24);
        \Ichiloto\Engine\Util\Config\ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,
            new \Ichiloto\Engine\Util\Config\PlaySettings(['width' => 80, 'height' => 24]));
        \Ichiloto\Engine\IO\InputManager::setBindings([
            'confirm' => ['keys' => [\Ichiloto\Engine\IO\Enumerations\KeyCode::x]],
            'cancel' => ['keys' => [\Ichiloto\Engine\IO\Enumerations\KeyCode::c]],
            'pause' => ['keys' => [\Ichiloto\Engine\IO\Enumerations\KeyCode::P]],
        ]);
        new ReflectionProperty(\Ichiloto\Engine\Events\EventManager::class, 'instance')->setValue(null, null);
        $this->interpreter = new EventInterpreter($this->scene, $this->presentation);
        $this->interpreter->run([['type' => 'play_skit', 'skit' => 'selected'],
            ['type' => 'set_switch', 'name' => 'after']]);
        $this->interpreter->update(0);
        $this->titles = 0;
        $this->quits = 0;
        $this->redraws = 0;
        $this->menu = new ScriptedPauseMenuProbe($this->game, $this->interpreter,
            function () { ++$this->titles; }, function () { ++$this->quits; }, function () { ++$this->redraws; });
        $this->choose = function (int $index): void {
            $this->menu->getModal()->setActiveButton($index);
            \Ichiloto\Engine\IO\InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(
                \Ichiloto\Engine\IO\Enumerations\KeyCode::x));
            \Ichiloto\Engine\IO\InputManager::handleInput();
            $this->menu->update();
        };
    });
    afterEach(function () {
        $this->menu->close();
        $this->interpreter->failActiveSession('End pause-menu fixture');
        foreach ($this->pauseUiGlobals as $class => $properties) {
            foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
        }
    });

    it('resumes the same Skit after Cancel and keeps Exit cancellation inside the pause menu', function () {
        $shown = $this->presentation->shown;
        expect($this->menu->open())->toBeTrue()->and($this->menu->open())->toBeFalse()
            ->and($this->menu->getModal()->getModalPresentation()->choices)->toBe(['Skip', 'Exit', 'Cancel']);
        ($this->choose)(1);
        expect($this->menu->getModal()->getModalPresentation()->choices)->toBe(['To Title', 'Quit Game', 'Cancel'])
            ->and($this->interpreter->isPlaybackPaused)->toBeTrue();
        ($this->choose)(2);
        expect($this->menu->isOpen)->toBeTrue()->and($this->interpreter->isPlaybackPaused)->toBeTrue()
            ->and($this->menu->getModal()->getModalPresentation()->choices)->toBe(['Skip', 'Exit', 'Cancel']);
        ($this->choose)(2);
        expect($this->menu->isOpen)->toBeFalse()->and($this->interpreter->isPlaybackPaused)->toBeFalse()
            ->and($this->presentation->shown)->toBe($shown)->and($this->titles)->toBe(0)->and($this->quits)->toBe(0)
            ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse();
    });

    it('skips once through the real shared selection modal', function () {
        $this->menu->open();
        ($this->choose)(0);
        expect($this->menu->isOpen)->toBeFalse()->and($this->scene->gameState->getSwitch('after'))->toBeTrue()
            ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeTrue()
            ->and($this->game->audioManager->released)->toBe(1)->and($this->redraws)->toBe(1);
        $this->menu->update();
        expect($this->game->audioManager->released)->toBe(1);
    });

    it('exits through the selected continuation without consuming the unfinished Skit', function (int $choice) {
        $this->menu->open();
        ($this->choose)(1);
        ($this->choose)($choice);
        expect($this->menu->isOpen)->toBeFalse()->and($this->interpreter->isPlaybackPaused)->toBeFalse()
            ->and($this->scene->gameState->hasStoryEvent('skit_seen:selected'))->toBeFalse()
            ->and($this->scene->gameState->getSwitch('after'))->toBeFalse()
            ->and($this->titles)->toBe($choice === 0 ? 1 : 0)->and($this->quits)->toBe($choice === 1 ? 1 : 0)
            ->and($this->scene->skitManager->isPlaying)->toBeFalse();
    })->with([0, 1]);

    it('repositions the pause modal on resize and leaves a later session alone', function () {
        $this->menu->open();
        $this->menu->getModal()->setActiveButton(1);
        \Ichiloto\Engine\IO\Console\Console::syncDimensions(24, 20);
        \Ichiloto\Engine\Util\Config\ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,
            new \Ichiloto\Engine\Util\Config\PlaySettings(['width' => 24, 'height' => 20]));
        $this->menu->update();
        $bounds = $this->menu->getModal()->getPresentationBounds();
        expect($bounds->getX())->toBeGreaterThanOrEqual(0)
            ->and($bounds->getX() + $bounds->getWidth())->toBeLessThanOrEqual(24)
            ->and($this->menu->getModal()->getActiveIndex())->toBe(1);
        $this->interpreter->failActiveSession('Synthetic abandoned session');
        $this->interpreter->run([['type' => 'wait', 'seconds' => 10]]);
        $this->interpreter->pausePlayback();
        $this->menu->update();
        expect($this->menu->isOpen)->toBeFalse()->and($this->interpreter->isPlaybackPaused)->toBeTrue();
    });

    it('opens from the rebound semantic pause action before field objects can advance', function () {
        new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($this->scene, $this->interpreter);
        new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene,
            new \Ichiloto\Engine\Scenes\Game\States\FieldState(new \Ichiloto\Engine\Scenes\SceneStateContext($this->scene)));
        \Ichiloto\Engine\IO\InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(
            \Ichiloto\Engine\IO\Enumerations\KeyCode::P));
        \Ichiloto\Engine\IO\InputManager::handleInput();
        $this->scene->update();
        $menu = new ReflectionProperty(GameScene::class, 'eventPauseMenu')->getValue($this->scene);
        expect($menu->isOpen)->toBeTrue()->and($this->interpreter->isPlaybackPaused)->toBeTrue();
        $menu->close();
    });
});
