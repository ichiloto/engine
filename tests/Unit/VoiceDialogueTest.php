<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\SkitBeatPresentation;
use Ichiloto\Engine\Field\SkitManager;
use Ichiloto\Engine\Field\SkitSpeaker;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class VoiceTestTextBox extends TextBoxModal
{
  public function render(?int $x = null, ?int $y = null): void {}
  public function erase(?int $x = null, ?int $y = null): void {}
  public function getPageIndex(): int { return $this->currentPageIndex; }
  public function getIsTyping(): bool { return $this->isPrinting; }
  public function getHelp(): string { return $this->help ?? ''; }
  public function getPlayback(): DialoguePlayback { return $this->playback; }
}

final class VoiceTestHandle extends AudioPlayback
{
  public bool $running = true;
  public bool $isRunning { get => $this->running; }
  public function __construct(array $command) { $this->command = $command; $this->pid = null; }
  public function stop(): void { $this->wasInterrupted = $this->running; $this->running = false; }
  public function complete(int $code = 0): void { $this->exitCode = $code; $this->running = false; }
}

final class VoiceTestBackend implements AudioBackendInterface
{
  public function __construct(private bool $seekable = true, private bool $loops = true) {}
  public function getExecutableName(): string { return 'silent-test'; }
  public function isAvailable(): bool { return true; }
  public function supports(string $filePath): bool { return str_ends_with($filePath, '.mp3'); }
  public function supportsNativeLooping(): bool { return $this->loops; }
  public function supportsSeeking(): bool { return $this->seekable; }
  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
  { return [$filePath, (string) $volume, $loop ? 'loop' : 'once', (string) $startAtSeconds]; }
}

final class VoiceTestAudio extends AudioManager
{
  public array $handles = [];
  public array $warnings = [];
  public bool $failSpawn = false;
  public ?float $duration = 100.0;
  public array $settings = ['audio.music'=>true, 'audio.sfx'=>true, 'audio.master_volume'=>80];
  public function __construct(private array $testBackends = [new VoiceTestBackend()])
  { parent::__construct(new ReflectionClass(Game::class)->newInstanceWithoutConstructor()); }
  protected function createBackends(): array { return $this->testBackends; }
  protected function getProjectSetting(string $path, mixed $default = null): mixed { return $this->settings[$path] ?? $default; }
  protected function probeTrackDuration(?string $path): ?float { return $this->duration; }
  protected function spawn(array $command): ?AudioPlayback
  { return $this->failSpawn ? null : $this->handles[] = new VoiceTestHandle($command); }
  protected function warnOnce(string $key, string $message): void { $this->warnings[$key] = $message; }
}

final class VoiceTestScene extends GameScene
{
  public function __construct(private Game $testGame) { $this->gameState = new GameState(); $this->party = new Party(); }
  public function getGame(): Game { return $this->testGame; }
}

final class VoiceTestSkits extends SkitManager
{
  public array $shown = [];
  public bool $failPresentation = false;
  public function runSkit(array $skit): void { $this->play('sample', $skit); }
  protected function showBeat(array $beat, float $speed, DialoguePlayback $playback): void
  {
    $this->shown[] = [$beat, $speed, $playback->auto];
    if ($this->failPresentation) { throw new RuntimeException('interrupted'); }
    $playback->toggleAuto();
  }
}

beforeEach(function () {
  $this->originalDirectory = getcwd();
  $this->voiceTemp = sys_get_temp_dir() . '/ichiloto-voice-' . bin2hex(random_bytes(6));
  mkdir($this->voiceTemp . '/assets/Audio/Voice/Skits/sample', 0777, true);
  file_put_contents($this->voiceTemp . '/assets/Audio/Voice/Skits/sample/line.mp3', 'fake test audio');
  $this->voicePath = $this->voiceTemp . '/assets/Audio/Voice/Skits/sample/line.mp3';
  $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->configState = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  ConfigStore::remove(ProjectConfig::class);
  $this->eventState = new ReflectionClass(EventManager::class)->getStaticProperties();
  new ReflectionProperty(EventManager::class,'instance')->setValue(null,null);
  ConfigStore::put(PlaySettings::class,new PlaySettings(['width'=>80,'height'=>24]));
  new ReflectionProperty(Console::class,'width')->setValue(null,80);
  new ReflectionProperty(Console::class,'height')->setValue(null,24);
  Debug::configure(['log_directory'=>$this->voiceTemp]);
  $this->audio = new VoiceTestAudio();
  chdir($this->voiceTemp);
});

afterEach(function () {
  $this->audio->shutdown();
  chdir($this->originalDirectory);
  foreach ($this->debugState as $key=>$value) new ReflectionProperty(Debug::class,$key)->setValue(null,$value);
  foreach ($this->inputState as $key=>$value) new ReflectionProperty(InputManager::class,$key)->setValue(null,$value);
  foreach ($this->consoleState as $key=>$value) new ReflectionProperty(Console::class,$key)->setValue(null,$value);
  foreach ($this->configState as $key=>$value) new ReflectionProperty(ConfigStore::class,$key)->setValue(null,$value);
  foreach ($this->eventState as $key=>$value) new ReflectionProperty(EventManager::class,$key)->setValue(null,$value);
  $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->voiceTemp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
  rmdir($this->voiceTemp);
});

it('owns a single speech line without using or stopping sound effects', function () {
  $this->audio->playSoundEffect($this->voicePath);
  $effect = $this->audio->handles[0];
  $first = $this->audio->playSpeech($this->voicePath);
  $second = $this->audio->playSpeech($this->voicePath);
  $this->audio->stopSpeech($first);
  expect($first->isRunning)->toBeFalse()->and($second->isRunning)->toBeTrue()
    ->and($effect->isRunning)->toBeTrue()->and($this->audio->isSpeechPlaying($first))->toBeFalse();
  $this->audio->shutdown();
  expect($second->isRunning)->toBeFalse()->and($effect->isRunning)->toBeFalse();
});

it('respects existing mutes and live muting without resuming an interrupted line', function ($setting, $value) {
  $voice = $this->audio->playSpeech($this->voicePath);
  $this->audio->settings[$setting] = $value;
  expect($this->audio->isSpeechPlaying())->toBeFalse()->and($voice->wasInterrupted)->toBeTrue()
    ->and($this->audio->playSpeech($this->voicePath))->toBeNull();
  $this->audio->settings[$setting] = $setting === 'audio.master_volume' ? 80 : true;
  $this->audio->update();
  expect($this->audio->isSpeechPlaying())->toBeFalse()->and($this->audio->handles)->toHaveCount(1);
})->with([['audio.voice',false],['audio.master_volume',0]]);

it('keeps Voice independent of SFX mute in both directions', function () {
  $this->audio->settings['audio.sfx'] = false;
  $voice = $this->audio->playSpeech($this->voicePath);
  $this->audio->playSoundEffect($this->voicePath);
  expect($voice->isRunning)->toBeTrue()->and($this->audio->handles)->toHaveCount(1);
  $this->audio->settings['audio.sfx'] = true;
  $this->audio->settings['audio.voice'] = false;
  $this->audio->update();
  $this->audio->playSoundEffect($this->voicePath);
  expect($voice->wasInterrupted)->toBeTrue()->and($this->audio->isSpeechPlaying())->toBeFalse()
    ->and(end($this->audio->handles)->isRunning)->toBeTrue();
});

it('diagnoses missing unsupported spawn and corrupt voice failures without throwing', function () {
  expect($this->audio->playSpeech('missing.mp3'))->toBeNull();
  $this->audio->failSpawn = true;
  expect($this->audio->playSpeech($this->voicePath))->toBeNull();
  $this->audio->failSpawn = false;
  $voice = $this->audio->playSpeech($this->voicePath);
  $voice->complete(1);
  expect($this->audio->isSpeechPlaying())->toBeFalse()->and($this->audio->warnings)->toHaveCount(3);
  $unsupported = new VoiceTestAudio([]);
  expect($unsupported->playSpeech($this->voicePath))->toBeNull()->and($unsupported->warnings)->toHaveCount(1);
});

it('ducks only BGM and restores the current track volume at its current position', function () {
  $this->audio->playBackgroundMusic($this->voicePath);
  $voice = $this->audio->playSpeech($this->voicePath, 0.25);
  expect($voice->command[1])->toBe('0.8')->and(end($this->audio->handles)->command[1])->toBe('0.2');
  $this->audio->settings['audio.master_volume'] = 60;
  $voice->complete();
  $this->audio->update();
  expect(end($this->audio->handles)->command[1])->toBe('0.6')
    ->and((float)end($this->audio->handles)->command[3])->toBeGreaterThan(0.0)
    ->and($this->audio->currentBackgroundMusic)->toBe($this->voicePath);
});

it('does not restart or leave music attenuated when live ducking cannot safely seek', function ($seekable, $duration) {
  $audio = new VoiceTestAudio([new VoiceTestBackend($seekable)]);
  $audio->duration = $duration;
  $audio->playBackgroundMusic($this->voicePath);
  $music = $audio->handles[0];
  $voice = $audio->playSpeech($this->voicePath, 0.2);
  $voice->complete(); $audio->update();
  expect($music->isRunning)->toBeTrue()->and($music->command[1])->toBe('0.8')
    ->and($audio->handles)->toHaveCount(2)->and($audio->warnings)->toHaveCount(1);
  $audio->shutdown();
})->with([[false,100.0],[true,null]]);

it('waits for both voice end and completed typing in Auto mode', function () {
  $flow = new DialoguePlayback($this->audio, true);
  $flow->beginLine($this->voicePath); $flow->beginPage('Hello');
  expect($flow->canAdvance(10.0, false, true))->toBeFalse();
  $this->audio->handles[0]->complete();
  expect($flow->canAdvance(11.0, true, true))->toBeFalse()
    ->and($flow->canAdvance(12.0, false, true))->toBeTrue();
});

it('uses text length after typing for unvoiced failed and muted lines', function ($mode) {
  if ($mode === 'muted') $this->audio->settings['audio.voice'] = false;
  $flow = new DialoguePlayback($this->audio, true);
  $flow->beginLine($mode === 'unvoiced' ? null : $this->voicePath);
  $flow->beginPage(str_repeat('a', 60));
  if ($mode === 'failed') $this->audio->handles[0]->complete(1);
  expect($flow->canAdvance(10.0,true,true))->toBeFalse()
    ->and($flow->canAdvance(100.0,false,true))->toBeFalse()
    ->and($flow->canAdvance(103.9,false,true))->toBeFalse()
    ->and($flow->canAdvance(104.0,false,true))->toBeTrue();
})->with(['unvoiced','failed','muted']);

it('gives each wrapped page reading time and preserves Auto between lines', function () {
  $flow = new DialoguePlayback($this->audio, true);
  $flow->beginLine($this->voicePath); $flow->beginPage('First');
  expect($flow->canAdvance(0.0,false,false))->toBeFalse()->and($flow->canAdvance(1.0,false,false))->toBeTrue();
  $this->audio->handles[0]->complete(); $flow->beginPage('Last');
  expect($flow->canAdvance(2.0,false,true))->toBeFalse()->and($flow->canAdvance(3.0,false,true))->toBeTrue();
  $flow->finishLine(); $flow->beginLine();
  expect($flow->auto)->toBeTrue();
  $flow->toggleAuto();
  expect($flow->canAdvance(100.0,false,true))->toBeFalse();
});

it('softly resolves optional emotion and voice without allowing paths outside the skit', function () {
  $catalogue = new DialoguePresentationCatalog(['Actor'=>['emotions'=>['Concerned'=>'portrait.png']]]);
  $assets = $this->voiceTemp.'/assets';
  $beat = SkitBeatPresentation::getFromBeat($assets,'sample',['actor'=>'Actor','emotion'=>'Concerned','voice'=>'line'],$catalogue, 'Actor');
  expect($beat->emotion)->toBe('Concerned')->and($beat->voicePath)->toBe(realpath($this->voicePath));
  foreach (['../line','/line','line.wav','missing',[]] as $voice) {
    $beat = SkitBeatPresentation::getFromBeat($assets,'sample',['emotion'=>[],'voice'=>$voice],$catalogue);
    expect($beat->emotion)->toBe('Neutral')->and($beat->voicePath)->toBeNull();
  }
  expect(file_get_contents($this->voiceTemp.'/warning.log'))->toContain('Unknown skit emotion','Voice file missing','Invalid voice reference');
});

it('resolves stable skit actor identity and notices legacy ids without treating display names as ids', function () {
  $actors = new ActorStore($this->voiceTemp . '/actors');
  $definition = new ActorDefinition('hero-id', ['name'=>'Before']);
  $actors->set('hero-file', $definition);
  $explicit = SkitSpeaker::getFromBeat(['actor'=>'hero-id'], $actors);
  expect($explicit->actorId)->toBe('hero-id')->and($explicit->name)->toBe('Before')->and($explicit->notices)->toBeEmpty();
  $renamed = new ActorStore($this->voiceTemp . '/actors');
  $renamed->set('hero-file', new ActorDefinition('hero-id', ['name'=>'After']));
  expect(SkitSpeaker::getFromBeat(['actor'=>'hero-id'], $renamed)->name)->toBe('After');
  $legacy = SkitSpeaker::getFromBeat(['speaker'=>'hero-id'], $renamed);
  expect($legacy->actorId)->toBe('hero-id')->and($legacy->name)->toBe('After')->and($legacy->notices)->toHaveCount(1);
  expect(SkitSpeaker::getFromBeat(['speaker'=>'After'], $renamed)->actorId)->toBeNull()
    ->and(SkitSpeaker::getFromBeat(['actor'=>'After'], $renamed)->errors)->not->toBeEmpty()
    ->and(SkitSpeaker::getFromBeat(['actor'=>'missing'], $renamed)->errors)->not->toBeEmpty()
    ->and(SkitSpeaker::getFromBeat(['actor'=>'hero-id','speaker'=>'After'], $renamed)->errors)->not->toBeEmpty();
  $catalogue = new DialoguePresentationCatalog(['hero-id'=>['emotions'=>['Concerned'=>'portrait.png']]]);
  $plain = SkitBeatPresentation::getFromBeat($this->voiceTemp.'/assets','sample', ['speaker'=>'After','emotion'=>'Concerned'], $catalogue);
  expect($plain->emotion)->toBe(SkitBeatPresentation::NEUTRAL_EMOTION);
});

it('accepts an empty dialogue catalogue placeholder and displays the registered actor name', function () {
  mkdir($this->voiceTemp.'/assets/Data/Presentation', 0777, true);
  file_put_contents($this->voiceTemp.'/assets/Data/Presentation/dialogue.php', "<?php\n\n");
  $actors = new ActorStore($this->voiceTemp.'/actors');
  $actors->set('hero', new ActorDefinition('hero', ['name'=>'Current Name']));
  ConfigStore::put(ActorStore::class, $actors);
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $skits = new VoiceTestSkits(new VoiceTestScene($game));
  $skits->runSkit(['speed'=>20, 'beats'=>[['actor'=>'hero','text'=>'Hello']]]);
  expect($skits->shown[0][0]['speaker'])->toBe('Current Name')
    ->and(is_file($this->voiceTemp.'/warning.log'))->toBeFalse();
});

it('keeps legacy skits and missing optional presentation playable and marks them seen', function () {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(Game::class,'audioManager')->setValue($game,$this->audio);
  $scene = new VoiceTestScene($game); $skits = new VoiceTestSkits($scene);
  $skits->runSkit(['speed'=>20,'beats'=>[
    ['speaker'=>'Actor','text'=>'Legacy line'],
    ['speaker'=>'Actor','text'=>'Missing line','voice'=>'missing','emotion'=>'Unknown'],
    ['speaker'=>'Actor','text'=>'Voiced line','voice'=>'line'],
  ]]);
  expect($skits->shown)->toHaveCount(3)->and(array_column($skits->shown,2))->toBe([false,true,false])
    ->and($scene->gameState->hasStoryEvent('skit_seen:sample'))->toBeTrue()
    ->and($this->audio->isSpeechPlaying())->toBeFalse();
});

it('releases speech on interrupted presentation without marking an unfinished skit seen', function () {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(Game::class,'audioManager')->setValue($game,$this->audio);
  $scene = new VoiceTestScene($game); $skits = new VoiceTestSkits($scene); $skits->failPresentation = true;
  expect(fn()=>$skits->runSkit(['speed'=>20,'beats'=>[['text'=>'Line','voice'=>'line']]]))->toThrow(RuntimeException::class)
    ->and($this->audio->isSpeechPlaying())->toBeFalse()
    ->and($scene->gameState->hasStoryEvent('skit_seen:sample'))->toBeFalse();
});

it('supplies a remappable Auto action without claiming an authored key', function () {
  InputManager::setBindings(['left'=>['keys'=>[KeyCode::SPACE, KeyCode::x, KeyCode::X]],'info'=>['keys'=>[]]]);
  expect(InputManager::getBindings()['dialogue_auto']['keys'])->toBe([]);
  InputManager::setBindings(['dialogue_auto'=>['keys'=>[KeyCode::x]]]);
  expect(InputManager::getBindings()['dialogue_auto']['keys'])->toBe([KeyCode::x]);
});

it('toggles shared TextBox Auto through a rebound action and dismisses only after voice ends', function () {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $flow = new DialoguePlayback($this->audio);
  $flow->beginLine($this->voicePath);
  $modal = new VoiceTestTextBox($game,'X','Actor',playback:$flow);
  InputManager::setBindings(['dialogue_auto'=>['keys'=>[KeyCode::x]]]);
  InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(KeyCode::x,null,null));
  $modal->show(); InputManager::handleInput(); $modal->update();
  expect($flow->auto)->toBeTrue()->and($modal->isShowing)->toBeTrue();
  InputManager::handleInput(); $modal->update();
  expect($modal->isShowing)->toBeTrue();
  $this->audio->handles[0]->complete(); InputManager::handleInput(); $modal->update();
  expect($modal->isShowing)->toBeTrue();
  new ReflectionProperty(DialoguePlayback::class,'textCompletedAt')->setValue($flow,microtime(true)-2.0);
  $modal->update();
  expect($modal->isShowing)->toBeFalse();
});

it('keeps voice while confirm finishes typing and stops it when the line is dismissed', function () {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $flow = new DialoguePlayback($this->audio);
  $flow->beginLine($this->voicePath);
  $modal = new VoiceTestTextBox($game,'Long enough to type','Actor',charactersPerSecond:1,playback:$flow);
  InputManager::setBindings(['confirm'=>['keys'=>[KeyCode::x]],'cancel'=>['keys'=>[KeyCode::c]]]);
  InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(KeyCode::x,null,KeyCode::x));
  $modal->show(); InputManager::handleInput(); $modal->update();
  expect($this->audio->isSpeechPlaying())->toBeTrue()->and($modal->isShowing)->toBeTrue();
  InputManager::handleInput(); $modal->update();
  expect($modal->getIsTyping())->toBeFalse();
  InputManager::handleInput(); $modal->update();
  expect($this->audio->isSpeechPlaying())->toBeFalse()->and($modal->isShowing)->toBeFalse();
});

it('does not instantly dismiss an ended voiced line when Auto is re-enabled', function () {
  $flow = new DialoguePlayback($this->audio);
  $flow->beginLine($this->voicePath); $flow->beginPage('Hello');
  $this->audio->handles[0]->complete();
  $flow->toggleAuto();
  expect($flow->canAdvance(10.0,false,true))->toBeFalse()
    ->and($flow->canAdvance(11.0,false,true))->toBeTrue();
  $flow->toggleAuto(); $flow->toggleAuto();
  expect($flow->canAdvance(20.0,false,true))->toBeFalse()
    ->and($flow->canAdvance(21.0,false,true))->toBeTrue();
});

it('preserves authored help while appending live rebound Auto hints without duplication', function () {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $flow = new DialoguePlayback();
  InputManager::setBindings(['confirm'=>['keys'=>[KeyCode::ENTER]], 'dialogue_auto'=>['keys'=>[KeyCode::x]]]);
  InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(null, KeyCode::x, null));
  $modal = new VoiceTestTextBox($game, 'A line still typing.', help:'Remember the blue door.', playback:$flow);
  $modal->show();
  expect($modal->getHelp())->toBe('Remember the blue door. ENTER:continue x:Auto off');
  InputManager::handleInput(); $modal->update();
  expect($modal->getHelp())->toBe('Remember the blue door. ENTER:continue x:Auto off');
  InputManager::handleInput(); $modal->update();
  expect($modal->getHelp())->toBe('Remember the blue door. ENTER:continue x:Auto on');
  InputManager::handleInput(); $modal->update();
  expect($modal->getHelp())->toBe('Remember the blue door. ENTER:continue x:Auto on');
  $modal->hide();
});

it('uses contextual Space Auto and consumes the opening edge without discarding the next press', function () {
  InputManager::setBindings(['action'=>['keys'=>[KeyCode::SPACE]], 'confirm'=>['keys'=>[KeyCode::ENTER]]]);
  expect(InputManager::getBindings()['dialogue_auto']['keys'])->toBe([KeyCode::SPACE])
    ->and(InputManager::getBindings()['dialogue_auto']['controllers'])->toBe(InputManager::DIALOGUE_AUTO_CONTROLLERS);
  InputManager::setInputSource(new \Tests\Support\Input\FakeInputSource(KeyCode::SPACE, KeyCode::SPACE, KeyCode::ENTER));
  InputManager::handleInput();
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $modal = new VoiceTestTextBox($game, 'A line that is still typing.', charactersPerSecond:1);
  $modal->show(); $modal->update();
  expect($modal->getPlayback()->auto)->toBeFalse()->and($modal->getIsTyping())->toBeTrue();
  InputManager::handleInput(); $modal->update();
  expect($modal->getPlayback()->auto)->toBeTrue()->and($modal->getIsTyping())->toBeTrue();
  InputManager::handleInput(); $modal->update(); $modal->updateContent();
  expect($modal->getIsTyping())->toBeFalse()->and($modal->isShowing)->toBeTrue();
  $modal->hide();
  InputManager::setBindings(['confirm'=>['keys'=>[KeyCode::SPACE]]]);
  expect(InputManager::getBindings()['dialogue_auto']['keys'])->toBe([KeyCode::x, KeyCode::X]);
});

it('persists Auto across ordinary dialogue skits and reloaded configuration and honours Config changes', function () {
  file_put_contents($this->voiceTemp.'/config.php', "<?php return [];\n");
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());
  $first = new DialoguePlayback();
  $first->toggleAuto();
  $first->finishLine();
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $modal = new VoiceTestTextBox($game, 'Ordinary dialogue');
  expect($modal->getPlayback()->auto)->toBeTrue();
  $skits = new VoiceTestSkits(new VoiceTestScene($game));
  $skits->runSkit(['speed'=>20,'beats'=>[['speaker'=>'Narrator','text'=>'A skit']]]);
  expect($skits->shown[0][2])->toBeTrue();
  $catalogue = new \Ichiloto\Engine\Settings\SettingsCatalog();
  $catalogue->write('dialogue_auto', true); $catalogue->persist();
  expect($modal->getPlayback()->auto)->toBeTrue();
  $catalogue->write('dialogue_auto', false); $catalogue->persist();
  expect(new DialoguePlayback()->auto)->toBeFalse()->and($modal->getPlayback()->auto)->toBeFalse();
  foreach ([new \Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager(), new \Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager()] as $manager) {
    expect(array_column($manager->getSettings(), 'key'))->toContain('dialogue_auto');
  }
});

it('holds one duck across consecutive voiced beats and restores for silence or conversation completion', function () {
  $this->audio->playBackgroundMusic($this->voicePath);
  $flow = new DialoguePlayback($this->audio);
  $flow->beginConversation();
  $flow->beginLine($this->voicePath, 0.25);
  $voice = $this->audio->handles[1];
  $duckedMusic = end($this->audio->handles);
  expect($this->audio->handles)->toHaveCount(3);
  $voice->complete(); $this->audio->update();
  $flow->finishLine(); $flow->beginLine($this->voicePath, 0.25);
  expect($this->audio->handles)->toHaveCount(4)->and($duckedMusic->isRunning)->toBeTrue();
  $flow->beginLine();
  expect($this->audio->handles)->toHaveCount(5)->and($duckedMusic->isRunning)->toBeFalse()
    ->and(end($this->audio->handles)->command[1])->toBe('0.8');
  $flow->beginLine($this->voicePath, 0.25);
  $flow->finishConversation();
  expect(end($this->audio->handles)->command[1])->toBe('0.8')->and($this->audio->isSpeechPlaying())->toBeFalse();
});

it('releases conversation ducking when the next voice fails and ignores stale sequence owners', function () {
  $this->audio->playBackgroundMusic($this->voicePath);
  $old = $this->audio->beginSpeechSequence();
  $this->audio->playSpeech($this->voicePath, 0.25);
  $this->audio->playSpeech('missing.mp3', 0.25);
  expect(end($this->audio->handles)->command[1])->toBe('0.8');
  $next = $this->audio->beginSpeechSequence();
  $voice = $this->audio->playSpeech($this->voicePath, 0.25);
  $this->audio->endSpeechSequence($old);
  $this->audio->releaseSpeechDucking($old);
  expect($voice->isRunning)->toBeTrue()->and(end($this->audio->handles)->command[1])->toBe('0.2');
  $this->audio->endSpeechSequence($next);
  expect($voice->isRunning)->toBeFalse()->and(end($this->audio->handles)->command[1])->toBe('0.8');
});
