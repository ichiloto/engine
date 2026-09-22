<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Progress\Achievement;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Quests\QuestObjective;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\QuestMenuState;
use Ichiloto\Engine\Scenes\Game\States\RecordsMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\PromptModal;
use Ichiloto\Engine\UI\Presentation\JournalMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class JournalMenuTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class JournalMenuReturnState extends MainMenuState
{
  public int $entries = 0;
  public function enter(): void { $this->entries++; }
}

function journalMenuKey(QuestMenuState|RecordsMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

function journalMenuText(PresentationCanvas $canvas): array
{
  $text = [];
  foreach ($canvas->textLayers as $layer) {
    $runs = array_filter($layer->runs, fn($run) => $run->foreground !== null);
    if ($runs !== []) { $text[$layer->id] = implode('', array_column($runs, 'text')); }
  }
  return $text;
}

function journalMenuTheme(bool $alternative = false): array
{
  $base = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false];
  if (!$alternative) { return $base; }
  $art = ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]];
  return [...$base, 'colors' => ['text' => [30, 20, 10], 'background' => [230, 220, 205], 'panel' => [240, 230, 220]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8],
    'rowMetrics' => ['padding' => 24, 'separatorWidth' => 1.5, 'cursorWidth' => 10, 'cursorHeight' => 20, 'cursorInset' => 6, 'cursorTravel' => 3], 'cursor' => 'surface.png',
    'rowArtwork' => ['normal' => $art, 'selected' => $art, 'focus' => ['asset' => 'surface.png']],
    'frames' => ['panel' => $art, 'quiet' => $art]];
}

function journalMenuPng(string $path, int $width = 53, int $height = 37): void
{
  $chunk = static fn($type, $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAA\xBB\xCC\xFF", $width), $height))) . $chunk('IEND', ''));
}

function journalMenuOwner(object $fixture, bool $records): QuestMenuState|RecordsMenuState
{
  $state = $records ? new RecordsMenuState(new SceneStateContext($fixture->scene)) : new QuestMenuState(new SceneStateContext($fixture->scene));
  new ReflectionProperty($fixture->scene, 'state')->setValue($fixture->scene, $state);
  $state->enter();
  return $state;
}

function journalMenuRuntime(object $fixture, ?array $theme, ?array $caps = null): void
{
  if ($theme !== null) { file_put_contents($fixture->root . '/Data/Presentation/menus.php', '<?php return ' . var_export($theme, true) . ';'); }
  $caps ??= MenuPresentationCatalog::CAPABILITIES;
  $fixture->transport = new FakeRendererTransport();
  $fixture->transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $fixture->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $fixture->root,
    requiredCapabilities: $caps), $fixture->transport);
  $fixture->runtime->start('Journal fixture', 135, 36);
  $fixture->game->useRendererRuntime($fixture->runtime);
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class, AudioManager::class, Time::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-journal-menu-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  journalMenuPng($this->root . '/surface.png');
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  new ReflectionProperty(Time::class, 'time')->setValue(null, 600.0);
  $this->game = new JournalMenuTestGame();
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, null);
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
  };
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  $this->flags = new GameState();
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, $this->flags);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, new Party());
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->quests = makeBareScene(QuestManager::class);
  new ReflectionProperty($this->quests, 'gameScene')->setValue($this->quests, $this->scene);
  new ReflectionProperty($this->quests, 'log')->setValue($this->quests, new QuestLog());
  $items = makeBareScene(ItemStore::class);
  $items->set('reward.tonic', new Item('Reward Tonic', 'Current reward.', '!', 1, id: 'reward.tonic'));
  ConfigStore::put(ItemStore::class, $items);
  $objective = new QuestObjective(QuestObjectiveType::TALK_TO, 'private-target', 2, 'Ask the witness.', 'SECRET REVEALED TARGET', [['type' => 'switch', 'name' => 'reveal', 'value' => true]]);
  $this->longText = implode("\n", array_map(fn($i) => sprintf('Authored line %03d with manual and S-Potion.', $i), range(1, 60)));
  $entries = [];
  foreach (range(0, 39) as $i) {
    $quest = new Quest('quest.' . $i, 'Quest ' . $i, $this->longText, 'The Giver', [$objective],
      ['gold' => 123, 'experience' => 456, 'items' => [['item' => 'reward.tonic', 'quantity' => 3]]], isOptional: true);
    $entries[$quest->id] = $quest;
    $this->quests->log->accept($quest->id, 1);
  }
  new ReflectionProperty($this->quests, 'quests')->setValue($this->quests, $entries);
  new ReflectionProperty($this->scene, 'questManager')->setValue($this->scene, $this->quests);
  $this->achievements = makeBareScene(AchievementManager::class);
  new ReflectionProperty($this->achievements, 'achievements')->setValue($this->achievements, [
    'secret' => new Achievement('secret', 'SECRET NAME', 'SECRET DESCRIPTION', 'PRIVATE ICON', true, 50),
    'public' => new Achievement('public', 'Public record', $this->longText, '*', false, 25),
  ]);
  new ReflectionProperty($this->achievements, 'unlocked')->setValue($this->achievements, ['public' => '2026-09-21']);
  new ReflectionProperty($this->scene, 'achievementManager')->setValue($this->scene, $this->achievements);
  $catalog = new KnowledgeCatalog(['recordTypes' => ['place'], 'subjects' => [
    ['id' => 'place.known', 'recordType' => 'place', 'displayName' => 'Known Place', 'family' => 'Family', 'species' => 'Species',
      'quickCard' => 'Quick source card.', 'deepCard' => $this->longText, 'observations' => ['visit']],
    ['id' => 'place.hidden', 'recordType' => 'place', 'displayName' => 'UNDISCOVERED NAME', 'quickCard' => 'UNDISCOVERED CARD', 'hidden' => true],
  ], 'reports' => [
    ['id' => 'report.known', 'subject' => 'place.known', 'title' => 'Earned Report', 'summary' => 'Report summary.', 'details' => $this->longText . "\nFINAL REPORT DETAIL"],
    ['id' => 'report.unearned', 'subject' => 'place.known', 'title' => 'UNEARNED REPORT', 'summary' => 'UNEARNED SUMMARY', 'displayOrder' => -1],
  ]]);
  $this->knowledge = new KnowledgeProgressService($catalog);
  $this->knowledge->discoverSubject('place.known');
  new ReflectionProperty($this->scene, 'knowledge')->setValue($this->scene, $this->knowledge);
  $this->returnState = new JournalMenuReturnState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'mainMenuState')->setValue($this->scene, $this->returnState);
  $modal = makeBareScene(ModalManager::class);
  new ReflectionProperty($modal, 'modals')->setValue($modal, new Stack(ModalInterface::class));
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modal);
  InputManager::setBindings(array_map(fn($key) => ['keys' => [$key]], ['confirm' => KeyCode::ENTER, 'cancel' => KeyCode::ESCAPE,
    'up' => KeyCode::UP, 'down' => KeyCode::DOWN, 'character_next' => KeyCode::Q, 'character_previous' => KeyCode::E]));
  $this->runtime = null;
  ob_start();
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('keeps owner content and focus identical through two themes within serialized budgets', function (bool $records, bool $alternative) {
  $state = journalMenuOwner($this, $records);
  $theme = new MenuPresentationCatalog($this->root, journalMenuTheme($alternative));
  $before = [$this->quests->log->toArray(), $this->knowledge->progress->toArray(), $this->achievements->toArray()];
  foreach ([false, true] as $details) {
    if ($details) { journalMenuKey($state, KeyCode::ENTER); }
    $content = $state->getPresentationContent();
    $page = $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme));
    $frame = JournalMenuPresentation::compose($content, $theme, $page);
    $text = journalMenuText($frame);
    expect($content->detailsOpen)->toBe($details)->and(implode('', $text))
      ->not->toContain('SECRET')->not->toContain('UNEARNED')->not->toContain('UNDISCOVERED');
    if ($records) { expect(implode('', $text))->toContain($details ? 'Details - Lines' : 'View details'); }
    $ids = [...array_column($frame->textLayers, 'id'), ...array_column($frame->images, 'id')];
    expect(count(array_unique($ids)))->toBe(count($ids))->and(count($frame->textLayers))->toBeLessThanOrEqual(64)
      ->and($frame->toArray()['width'])->toBe(1350);
    if ($alternative) { expect($frame->images)->not->toBeEmpty(); }
  }
  expect([$this->quests->log->toArray(), $this->knowledge->progress->toArray(), $this->achievements->toArray()])->toBe($before);
})->with([false, true])->with([false, true]);

it('reaches complete long quest and earned report text in terminal and graphical views', function (bool $records, bool $graphical) {
  $this->knowledge->unlockReport('place.known', 'report.known', 'fixture');
  $state = journalMenuOwner($this, $records);
  if ($graphical) { journalMenuRuntime($this, journalMenuTheme()); }
  journalMenuKey($state, KeyCode::ENTER);
  $seen = [];
  for ($i = 0; $i < 180; $i++) {
    $frame = $graphical ? $state->getPresentationCanvas() : null;
    if ($graphical) { expect($frame)->toBeInstanceOf(PresentationCanvas::class); }
    $page = new ReflectionProperty($state, 'displayedPage')->getValue($state);
    foreach ($page->lines as $line => $text) { $seen[$page->first + $line] = $text; }
    if ($graphical) {
      $painted = array_filter(journalMenuText($frame), fn($id) => $id === 'journal-text' || str_starts_with($id, 'journal-text-'), ARRAY_FILTER_USE_KEY);
      expect(implode('', $painted))->toBe(implode('', $page->lines));
    }
    else {
      $panel = new ReflectionProperty($state, $records ? 'infoPanel' : 'listPanel')->getValue($state);
      $painted = array_slice($panel->getContent(), 0, count($page->lines));
      expect(array_map(TerminalText::stripAnsi(...), $painted))->toBe($page->lines);
    }
    if ($page->first + count($page->lines) === $page->total) { break; }
    journalMenuKey($state, KeyCode::DOWN);
  }
  ksort($seen);
  expect(array_values($seen))->toBe(array_column(TextViewport::wrap($page->source, $page->columns), 1))
    ->and(implode('', $seen))->toContain('Authored line 001')->toContain('Authored line 060')
    ->toContain($records ? 'FINAL REPORT DETAIL' : 'Reward: 123 G, 456 EXP, Reward Tonic x3');
  expect($state->getPresentationContent()->index)->toBe(0);
  journalMenuKey($state, KeyCode::ESCAPE);
  expect($state->getPresentationContent()->detailsOpen)->toBeFalse()->and($this->returnState->entries)->toBe(0);
  journalMenuKey($state, KeyCode::ESCAPE);
  expect($this->returnState->entries)->toBe(1);
})->with([false, true])->with([false, true]);

it('keeps secret and discovery masking and resets reading when earned content changes', function () {
  $state = journalMenuOwner($this, true);
  expect($state->getPresentationContent()->detailText)->toContain('Quick source card.')->not->toContain('Authored line');
  journalMenuKey($state, KeyCode::Q);
  $secret = $state->getPresentationContent();
  expect($secret->rows[0]['label'])->toBe('???')->and($secret->detailText)->not->toContain('SECRET')->not->toContain('PRIVATE');
  journalMenuKey($state, KeyCode::DOWN);
  journalMenuKey($state, KeyCode::ENTER);
  journalMenuKey($state, KeyCode::DOWN);
  expect($state->getPresentationPage(104, 4)->first)->toBeGreaterThan(0);
  journalMenuKey($state, KeyCode::Q);
  expect($state->getPresentationContent()->detailsOpen)->toBeFalse()->and($state->getPresentationContent()->index)->toBe(0);
  $this->knowledge->unlockReport('place.known', 'report.known', 'fixture');
  $state->resume();
  expect($state->getPresentationPage(104, 4)->first)->toBe(0)
    ->and($state->getPresentationContent()->detailText)->toContain('Authored line 060')->toContain('Earned Report')->not->toContain('UNEARNED');
  $this->knowledge->withdrawReport('place.known', 'report.known', 'fixture');
  expect($state->getPresentationContent()->detailText)->toContain('Withdrawn: Report summary.');
});

it('follows long lists preserves same selection on back and retains completed tab semantics', function () {
  $state = journalMenuOwner($this, false);
  for ($i = 0; $i < 35; $i++) { journalMenuKey($state, KeyCode::DOWN); }
  $panel = new ReflectionProperty($state, 'listPanel')->getValue($state);
  expect(count($panel->getContent()))->toBe($panel->getContentHeight())
    ->and(implode('', array_map(TerminalText::stripAnsi(...), $panel->getContent())))->toContain('Quest 35');
  journalMenuKey($state, KeyCode::ENTER);
  journalMenuKey($state, KeyCode::TAB);
  expect($state->getPresentationContent()->tabIndex)->toBe(0)->and($state->getPresentationContent()->detailsOpen)->toBeTrue();
  journalMenuKey($state, KeyCode::ESCAPE);
  expect($state->getPresentationContent()->index)->toBe(35);
  $this->quests->log->markCompleted('quest.35');
  journalMenuKey($state, KeyCode::TAB);
  expect($state->getPresentationContent()->tabIndex)->toBe(1)->and($state->getPresentationContent()->detailText)->toContain('[Complete] Ask the witness. (2/2)');
  $this->flags->setSwitch('reveal', true);
  $state->resume();
  expect($state->getPresentationContent()->detailText)->toContain('SECRET REVEALED TARGET')->not->toContain('Ask the witness.');
});

it('consumes confirm and back once even when a binding also drives navigation', function (bool $records) {
  $state = journalMenuOwner($this, $records);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::DOWN]], 'down' => ['keys' => [KeyCode::DOWN]],
    'cancel' => ['keys' => [KeyCode::UP]], 'up' => ['keys' => [KeyCode::UP]]]);
  journalMenuKey($state, KeyCode::DOWN);
  expect($state->getPresentationContent()->detailsOpen)->toBeTrue()->and($state->getPresentationContent()->index)->toBe(0)
    ->and($state->getPresentationPage(100, 4)->first)->toBe(0);
  journalMenuKey($state, KeyCode::UP);
  expect($state->getPresentationContent()->detailsOpen)->toBeFalse()->and($this->returnState->entries)->toBe(0);
})->with([false, true]);

it('submits through the existing renderer and retains reading position around supported and unsupported modals', function (bool $records) {
  $this->knowledge->unlockReport('place.known', 'report.known', 'fixture');
  journalMenuRuntime($this, journalMenuTheme());
  $state = journalMenuOwner($this, $records);
  journalMenuKey($state, KeyCode::ENTER);
  journalMenuKey($state, KeyCode::DOWN);
  $before = $state->getPresentationPage(100, 4);
  expect($this->runtime->present($this->scene))->toBeTrue();
  expect(array_filter($this->transport->sent, fn($message) => isset($message->payload['canvas'])))->not->toBeEmpty();
  $stack = new ReflectionProperty($this->game->modalManager, 'modals')->getValue($this->game->modalManager);
  $modal = new AlertModal($this->game, 'Owner notice.', 'Notice');
  $stack->push($modal);
  new ReflectionProperty($modal, 'isShowing')->setValue($modal, true);
  expect($this->runtime->present($this->scene))->toBeTrue()
    ->and(implode('', journalMenuText($this->scene->getPresentationCanvas())))->toContain('Owner notice.');
  new ReflectionProperty($modal, 'isShowing')->setValue($modal, false);
  expect(implode('', journalMenuText($this->scene->getPresentationCanvas())))->not->toContain('Owner notice.');
  $stack->pop();
  $prompt = new PromptModal($this->game, 'Actual prompt', 'Prompt');
  $stack->push($prompt);
  new ReflectionProperty($prompt, 'isShowing')->setValue($prompt, true);
  expect($this->scene->getPresentationCanvas())->toBeNull()->and($state->getPresentationPage(100, 4))->toEqual($before);
  $stack->pop();
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class)
    ->and($state->getPresentationContent()->detailsOpen)->toBeTrue()->and($this->scene->state)->toBe($state);
})->with([false, true]);

it('retains useful terminal reading and diagnostics for absent or unavailable graphical presentation', function (bool $records, string $reason) {
  $theme = $reason === 'absent' ? null : journalMenuTheme();
  if ($reason === 'viewport') { $theme['metrics'] = ['panelPadding' => 200]; }
  journalMenuRuntime($this, $theme, $reason === 'capabilities' ? [] : null);
  $state = journalMenuOwner($this, $records);
  expect($this->scene->getPresentationCanvas())->toBeNull();
  journalMenuKey($state, KeyCode::ENTER);
  journalMenuKey($state, KeyCode::DOWN);
  expect($state->getPresentationContent()->detailsOpen)->toBeTrue();
  $panel = new ReflectionProperty($state, $records ? 'infoPanel' : 'listPanel')->getValue($state);
  expect(implode('', $panel->getContent()))->not->toBe('');
  if ($reason !== 'absent') { expect(file_get_contents($this->root . '/logs/error.log'))->toContain('Menu presentation degraded to terminal'); }
})->with([false, true])->with(['absent', 'capabilities', 'viewport']);

it('handles empty tabs without synthetic records or detail focus and resumes after source removal', function (bool $records) {
  if ($records) {
    new ReflectionProperty($this->scene, 'knowledge')->setValue($this->scene, new KnowledgeProgressService(KnowledgeCatalog::empty()));
  } else { new ReflectionProperty($this->quests, 'quests')->setValue($this->quests, []); }
  $state = journalMenuOwner($this, $records);
  journalMenuKey($state, KeyCode::ENTER);
  $content = $state->getPresentationContent();
  $theme = new MenuPresentationCatalog($this->root, journalMenuTheme());
  $frame = JournalMenuPresentation::compose($content, $theme, $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme)));
  expect($content->rows)->toBeEmpty()->and($content->detailsOpen)->toBeFalse()
    ->and(implode('', journalMenuText($frame)))->toContain($content->emptyText)->not->toContain('View details');
  expect(array_filter($frame->textLayers, fn($layer) => str_starts_with($layer->id, 'journal-entry')))->toBeEmpty();
  $state->resume();
  expect($state->getPresentationContent()->index)->toBe(0);
})->with([false, true]);

it('marks long localized list previews and keeps their full values readable with replaceable theme artwork', function (bool $alternative) {
  $name = str_repeat("\u{65E5}\u{672C}\u{8A9E} cafe\u{0301} ", 25);
  $quest = $this->quests->quests['quest.0'];
  new ReflectionProperty($quest, 'name')->setValue($quest, $name);
  new ReflectionProperty($quest, 'giver')->setValue($quest, 'Giver ' . str_repeat('Longword', 40));
  $objectives = array_map(fn($i) => new QuestObjective(QuestObjectiveType::FLAG, 'flag.' . $i, 1, 'Objective ' . $i), range(1, 30));
  new ReflectionProperty($quest, 'objectives')->setValue($quest, $objectives);
  $state = journalMenuOwner($this, false);
  $theme = new MenuPresentationCatalog($this->root, journalMenuTheme($alternative));
  $content = $state->getPresentationContent();
  $page = $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme, 960, 540));
  $frame = JournalMenuPresentation::compose($content, $theme, $page, width: 960, height: 540);
  expect(implode('', array_filter(journalMenuText($frame), fn($id) => str_starts_with($id, 'journal-entry-0-'), ARRAY_FILTER_USE_KEY)))->toContain('...')
    ->and($content->detailText)->toContain($name)->toContain($quest->giver)->toContain('Objective 30 (0/1)');
  journalMenuPng($this->root . '/surface.png', 79, 43);
  $second = JournalMenuPresentation::compose($content, $theme, $page, width: 960, height: 540);
  expect($second->toArray()['width'])->toBe(960)->and(journalMenuText($second))->toBe(journalMenuText($frame));
  journalMenuKey($state, KeyCode::ENTER);
  $reading = new ReflectionProperty($state, 'reading')->getValue($state);
  $snapshot = clone $reading;
  $state->getPresentationPage(40, 3);
  $state->getPresentationPage(100, 20);
  expect($reading)->toEqual($snapshot);
})->with([false, true]);

it('keeps quest navigation visible while independently presenting structured sections and optional icons', function (bool $alternative) {
  $quest = $this->quests->quests['quest.0'];
  new ReflectionProperty($quest, 'description')->setValue($quest, 'A short account of this quest.');
  $state = journalMenuOwner($this, false);
  $data = journalMenuTheme($alternative);
  $data['icons'] = array_fill_keys(['journal.quests', 'quest.optional', 'journal.description', 'journal.objectives',
    'journal.rewards', 'objective.open'], 'surface.png');
  $theme = new MenuPresentationCatalog($this->root, $data);
  foreach ([[1350, 720], [960, 540], [1600, 900]] as [$width, $height]) {
    $content = $state->getPresentationContent();
    $size = JournalMenuPresentation::pageSize($content, $theme, $width, $height);
    $frame = JournalMenuPresentation::compose($content, $theme, $state->getPresentationPage(...$size), width: $width, height: $height);
    $labels = journalMenuText($frame);
    expect(implode('', $labels))->toContain('Quest 0')->toContain('Description');
    $list = array_find($frame->textLayers, fn($layer) => str_starts_with($layer->id, 'journal-entry-0-') && $layer->runs[0]->foreground !== null);
    $detail = array_find($frame->textLayers, fn($layer) => $layer->id === 'journal-text');
    expect($list->x + $list->bounds->width)->toBeLessThan($detail->x);
    expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'journal-icon-')))->not->toBeEmpty();
    $frame->toArray();
  }
  journalMenuKey($state, KeyCode::ENTER);
  $content = $state->getPresentationContent();
  $frame = JournalMenuPresentation::compose($content, $theme, $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme)));
  expect(array_filter(journalMenuText($frame), fn($id) => str_starts_with($id, 'journal-entry-0-'), ARRAY_FILTER_USE_KEY))->not->toBeEmpty()
    ->and(implode('', journalMenuText($frame)))->toContain('Description')->toContain('Objectives')->toContain('Rewards');
  expect(array_keys(array_filter(journalMenuText($frame), fn($id) => str_starts_with($id, 'journal-marker-'), ARRAY_FILTER_USE_KEY)))->toBeEmpty();
  $unknownOnly = new MenuPresentationCatalog($this->root, [...journalMenuTheme(), 'icons' => ['unknown' => 'surface.png']]);
  $plain = JournalMenuPresentation::compose($content, $unknownOnly, $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $unknownOnly)));
  expect($plain->images)->toBeEmpty()->and(implode('', journalMenuText($plain)))->toContain("\u{25CB}")->toContain('Objectives');
})->with([false, true]);

it('retains semantic section metadata through wrapping and source normalization without parsing labels', function () {
  $document = new \Ichiloto\Engine\UI\Presentation\JournalDocument([
    new \Ichiloto\Engine\UI\Presentation\JournalSection('Translated heading', [
      ['text' => "First\r\nSecond\ttext", 'icon' => 'objective.open'],
      ['text' => str_repeat('word ', 20), 'icon' => 'objective.complete', 'color' => 'increase'],
    ], 'journal.objectives'),
    new \Ichiloto\Engine\UI\Presentation\JournalSection('A different heading', [['text' => 'Reward body.']]),
  ]);
  foreach ([8, 23, 60] as $columns) {
    $lines = $document->lines($columns);
    expect(array_column($lines, 'text'))->toBe(array_column(TextViewport::wrap($document->text, $columns), 1));
    expect(count(array_filter($lines, fn($line) => $line['icon'] === 'objective.complete')))->toBe(1)
      ->and(count(array_filter($lines, fn($line) => $line['icon'] === 'objective.open')))->toBe(1);
    expect(array_find($lines, fn($line) => str_contains($line['text'], 'word'))['color'])->toBe('increase');
  }
});

it('bounds dense journal layers and measures wrapped category tabs', function (array $metrics, int $width, int $height) {
  $quest = $this->quests->quests['quest.0'];
  new ReflectionProperty($quest, 'description')->setValue($quest, 'Brief description.');
  new ReflectionProperty($quest, 'objectives')->setValue($quest, array_map(fn($i) =>
    new QuestObjective(QuestObjectiveType::FLAG, 'step.' . $i, 1, 'Objective ' . $i), range(1, 100)));
  $state = journalMenuOwner($this, false);
  $theme = new MenuPresentationCatalog($this->root, [...journalMenuTheme(), 'metrics' => $metrics]);
  $content = $state->getPresentationContent();
  $page = $state->getPresentationPage(...JournalMenuPresentation::pageSize($content, $theme, $width, $height));
  $frame = JournalMenuPresentation::compose($content, $theme, $page, width: $width, height: $height);
  expect(count($frame->textLayers))->toBeLessThanOrEqual(64)->and($frame->toArray()['width'])->toBe($width)
    ->and(implode('', journalMenuText($frame)))->toContain('Completed (0)')->toContain('Objectives');
})->with([
  'dense' => [['cellWidth' => 6, 'cellHeight' => 12, 'rowHeight' => 24, 'panelPadding' => 16, 'sectionGap' => 8], 1350, 720],
  'wide glyphs' => [['cellWidth' => 16], 960, 540],
]);


it('keeps journal text and healthy artwork when a panel frame is unavailable', function (bool $records) {
  $theme = journalMenuTheme(true);
  $theme['frames']['panel'] = ['asset' => 'missing.png'];
  journalMenuRuntime($this, $theme);
  $state = journalMenuOwner($this, $records);
  $before = $state->getPresentationContent();
  $frame = $this->scene->getPresentationCanvas();
  expect($frame)->toBeInstanceOf(PresentationCanvas::class)
    ->and(implode('', journalMenuText($frame)))->not->toBe('')
    ->and(array_column($frame->images, 'asset'))->toContain('surface.png')->not->toContain('missing.png')
    ->and($state->getPresentationContent())->toEqual($before)
    ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('missing.png');
  journalMenuKey($state, KeyCode::ENTER);
  journalMenuKey($state, KeyCode::DOWN);
  expect($state->getPresentationContent()->detailsOpen)->toBeTrue()
    ->and($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
})->with([false, true]);
