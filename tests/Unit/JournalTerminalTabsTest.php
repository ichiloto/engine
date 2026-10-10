<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicTabPanel;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
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
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\QuestMenuState;
use Ichiloto\Engine\Scenes\Game\States\RecordsMenuState;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

trait JournalTerminalHeaderProbe
{
  public int $exits = 0;
  public function setState(SceneStateInterface $state): void { $this->exits++; }
  public function header(): Window { return $this->summaryPanel; }
  public function list(): Window { return $this->listPanel; }
  public function info(): Window { return $this->infoPanel; }
}

class JournalTerminalQuestState extends QuestMenuState { use JournalTerminalHeaderProbe; }
class JournalTerminalRecordsState extends RecordsMenuState { use JournalTerminalHeaderProbe; }

function journalTerminalKey(QuestMenuState|RecordsMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

function assertJournalTerminalHeader(JournalTerminalQuestState|JournalTerminalRecordsState $state): void
{
  $header = $state->header();
  $content = $state->getPresentationContent();
  $lines = $header->getContent();
  $width = $header->getContentWidth();
  $segment = intdiv($width, count($content->tabs));
  $expected = '';
  foreach ($content->tabs as $i => $label) {
    $chunk = TerminalText::padCenter($label, $segment);
    $expected .= $i === $content->tabIndex ? SelectionStyle::apply($chunk) : $chunk;
    expect(TerminalText::stripAnsi($lines[0]))->toContain($label);
  }
  $highlighted = array_keys(array_filter(TerminalText::visibleSymbols($lines[0]), fn($symbol) => str_contains($symbol, "\033[")));
  expect($header)->toBeInstanceOf(MagicTabPanel::class)
    ->and($lines)->toHaveCount(2)
    ->and($lines[0])->toBe(TerminalText::padRight($expected, $width))
    ->and(SelectionStyle::resolveColor())->toBe(Color::YELLOW)
    ->and($highlighted)->toBe(range($content->tabIndex * $segment, ($content->tabIndex + 1) * $segment - 1))
    ->and($lines[1])->toBe($content->summary)
    ->and(TerminalText::displayWidth($lines[0]))->toBe($width);
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['ui' => ['menu' => ['selection_color' => Color::YELLOW]]]));
  Console::setTerminalOutputEnabled(false);
  new ReflectionProperty(Console::class, 'game')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  InputManager::setBindings(array_map(fn($key) => ['keys' => [$key]], [
    'confirm' => KeyCode::ENTER, 'cancel' => KeyCode::ESCAPE, 'character_next' => KeyCode::Q, 'character_previous' => KeyCode::E,
    'up' => KeyCode::UP, 'down' => KeyCode::DOWN,
  ]));
  $this->scene = makeBareScene(GameScene::class);
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, new GameState());
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, new Party());
  $quests = makeBareScene(QuestManager::class);
  $log = new QuestLog();
  $definitions = [];
  foreach (['active', 'completed'] as $id) {
    $definitions[$id] = new Quest($id, ucfirst($id), 'Journal text.', objectives: [new QuestObjective(QuestObjectiveType::FLAG, 'done')]);
    $log->accept($id, 1);
  }
  $log->markCompleted('completed');
  new ReflectionProperty($quests, 'log')->setValue($quests, $log);
  new ReflectionProperty($quests, 'quests')->setValue($quests, $definitions);
  new ReflectionProperty($quests, 'gameScene')->setValue($quests, $this->scene);
  new ReflectionProperty($this->scene, 'questManager')->setValue($this->scene, $quests);
  $achievements = makeBareScene(AchievementManager::class);
  new ReflectionProperty($achievements, 'achievements')->setValue($achievements, ['earned' => new Achievement('earned', 'Earned', 'Description.', points: 7)]);
  new ReflectionProperty($achievements, 'unlocked')->setValue($achievements, ['earned' => '2026-09-21']);
  new ReflectionProperty($this->scene, 'achievementManager')->setValue($this->scene, $achievements);
  $knowledge = new KnowledgeProgressService(new KnowledgeCatalog(['recordTypes' => ['place'], 'subjects' => [
    ['id' => 'place.known', 'recordType' => 'place', 'displayName' => 'Known place', 'quickCard' => 'Source card.'],
  ]]));
  $knowledge->discoverSubject('place.known');
  new ReflectionProperty($this->scene, 'knowledge')->setValue($this->scene, $knowledge);
  new ReflectionProperty($this->scene, 'mainMenuState')->setValue($this->scene, new MainMenuState(new SceneStateContext($this->scene)));
});

afterEach(function () {
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('uses the shared horizontal highlighted tab strip and preserves owner tab and detail actions', function (bool $records, int $width) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => $width, 'height' => 36]));
  Console::syncDimensions($width, 36);
  $state = $records ? new JournalTerminalRecordsState(new SceneStateContext($this->scene)) : new JournalTerminalQuestState(new SceneStateContext($this->scene));
  $state->enter();
  assertJournalTerminalHeader($state);
  $initial = $state->getPresentationContent()->tabIndex;
  foreach ([KeyCode::Q, KeyCode::E, KeyCode::TAB, KeyCode::SHIFT_TAB] as $key) {
    $previous = $state->getPresentationContent()->tabIndex;
    journalTerminalKey($state, $key);
    expect($state->getPresentationContent()->tabIndex)->toBe(1 - $previous);
    assertJournalTerminalHeader($state);
  }
  expect($state->getPresentationContent()->tabIndex)->toBe($initial);
  journalTerminalKey($state, KeyCode::ENTER);
  expect($state->getPresentationContent()->detailsOpen)->toBeTrue();
  assertJournalTerminalHeader($state);
  journalTerminalKey($state, KeyCode::ESCAPE);
  expect($state->getPresentationContent()->detailsOpen)->toBeFalse()->and($state->exits)->toBe(0);
  assertJournalTerminalHeader($state);
  journalTerminalKey($state, KeyCode::ESCAPE);
  expect($state->exits)->toBe(1);
})->with([false, true])->with([80, 135]);

it('centers terminal journals using the actual panel height sum', function (bool $records) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 50]));
  Console::syncDimensions(135, 50);
  $state = $records ? new JournalTerminalRecordsState(new SceneStateContext($this->scene))
    : new JournalTerminalQuestState(new SceneStateContext($this->scene));
  $state->enter();
  $height = $state->header()->getHeight() + $state->list()->getHeight() + $state->info()->getHeight();
  expect($state->header()->getPosition()->y)->toBe((float)intdiv(50 - $height, 2))
    ->and($state->info()->getPosition()->y + $state->info()->getHeight())->toBe($state->header()->getPosition()->y + $height);
})->with([false, true]);

it('puts list pagination in the supported bottom-left border and uses every content row', function (bool $records) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  if ($records) {
    $subjects = array_map(fn($i) => ['id' => 'place.' . $i, 'recordType' => 'place', 'displayName' => sprintf('Entry %02d', $i), 'quickCard' => 'Card.'], range(0, 39));
    $knowledge = new KnowledgeProgressService(new KnowledgeCatalog(['recordTypes' => ['place'], 'subjects' => $subjects]));
    foreach ($subjects as $subject) { $knowledge->discoverSubject($subject['id']); }
    new ReflectionProperty($this->scene, 'knowledge')->setValue($this->scene, $knowledge);
    $state = new JournalTerminalRecordsState(new SceneStateContext($this->scene));
  } else {
    $quests = [];
    $log = new QuestLog();
    foreach (range(0, 39) as $i) {
      $quests['quest.' . $i] = new Quest('quest.' . $i, sprintf('Entry %02d', $i));
      $log->accept('quest.' . $i, 0);
    }
    new ReflectionProperty($this->scene->questManager, 'quests')->setValue($this->scene->questManager, $quests);
    new ReflectionProperty($this->scene->questManager, 'log')->setValue($this->scene->questManager, $log);
    $state = new JournalTerminalQuestState(new SceneStateContext($this->scene));
  }
  $state->enter();
  $list = $state->list();
  $capacity = $list->getContentHeight();
  foreach ([false, true] as $last) {
    if ($last) { journalTerminalKey($state, KeyCode::UP); }
    $expected = $last ? sprintf('%d-40 / 40', 41 - $capacity) : sprintf('1-%d / 40', $capacity);
    expect($list->getHelp())->toBe($expected)
      ->and(count(array_filter($list->getContent(), fn($line) => trim(TerminalText::stripAnsi($line)) !== '')))->toBe($capacity)
      ->and(implode('', $list->getContent()))->not->toContain($expected);
    $border = new ReflectionMethod(Window::class, 'getBottomBorder')->invoke($list);
    expect($border)->toStartWith($list->getBorderPack()->getBottomLeftCorner() . $list->getBorderPack()->getHorizontalBorder() . $expected)
      ->and(TerminalText::displayWidth($border))->toBe($list->getWidth());
  }
  expect($state->getPresentationContent()->index)->toBe(39);
})->with([false, true]);

it('moves detail ranges to border help and keeps complete scrolling and back focus', function (bool $records) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  $text = implode("\n", array_map(fn($i) => 'Authored detail ' . $i, range(1, 60)));
  if ($records) {
    $knowledge = new KnowledgeProgressService(new KnowledgeCatalog(['recordTypes' => ['place'], 'subjects' => [
      ['id' => 'place.known', 'recordType' => 'place', 'displayName' => 'Known place', 'quickCard' => $text],
    ]]));
    $knowledge->discoverSubject('place.known');
    new ReflectionProperty($this->scene, 'knowledge')->setValue($this->scene, $knowledge);
    $state = new JournalTerminalRecordsState(new SceneStateContext($this->scene));
  } else {
    new ReflectionProperty(Quest::class, 'description')->setValue($this->scene->questManager->quests['active'], $text);
    $state = new JournalTerminalQuestState(new SceneStateContext($this->scene));
  }
  $state->enter();
  journalTerminalKey($state, KeyCode::ENTER);
  $panel = $records ? $state->info() : $state->list();
  $seen = [];
  for ($i = 0; $i < 100; $i++) {
    $page = $state->getPresentationPage($state->list()->getContentWidth() - 2, $panel->getContentHeight());
    expect($panel->getHelp())->toBe(($records ? 'Details - ' : '') . $page->range())
      ->and(array_slice($panel->getContent(), 0, count($page->lines)))->toBe($page->lines)
      ->and(implode('', $panel->getContent()))->not->toContain($page->range());
    foreach ($page->lines as $line => $value) { $seen[$page->first + $line] = $value; }
    if ($page->first + count($page->lines) === $page->total) { break; }
    journalTerminalKey($state, KeyCode::DOWN);
  }
  expect(implode("\n", $seen))->toContain($text)->and($state->getPresentationContent()->index)->toBe(0);
  journalTerminalKey($state, KeyCode::ESCAPE);
  expect($state->getPresentationContent()->detailsOpen)->toBeFalse()->and($state->list()->getHelp())->toBe('1-1 / 1')
    ->and($state->info()->getHelp())->toBe('')->and($state->exits)->toBe(0);
})->with([false, true]);
