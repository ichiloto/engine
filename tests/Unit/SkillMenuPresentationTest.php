<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Menu\AbilityMenu\Windows\AbilityListPanel;
use Ichiloto\Engine\Core\Menu\MagicMenu\Windows\MagicListPanel;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Abilities\AbilityLearningRequirement;
use Ichiloto\Engine\Entities\Abilities\LearnableAbility;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\LearnableSpell;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Magic\SpellLearningRequirement;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\AbilityMenuState;
use Ichiloto\Engine\Scenes\Game\States\MagicMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\PromptModal;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SkillMenuPresentation;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

class SkillMenuPresentationGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class SkillMenuPresentationReturnState extends MainMenuState
{
  public int $entries = 0;
  public function enter(): void { $this->entries++; }
}

function skillMenuPng(string $path, int $width = 53, int $height = 37): void
{
  $chunk = static fn($type, $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAA\xBB\xCC\xFF", $width), $height))) . $chunk('IEND', ''));
}

function skillMenuTheme(bool $alternative = false): array
{
  $base = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false, 'cursor' => 'cursor.png',
    'icons' => ['skill.ability' => 'ability.png', 'skill.magic' => 'magic.png'],
    'portraits' => ['actor.primary' => 'portrait.png']];
  if (!$alternative) { return $base; }
  $art = ['asset' => 'surface.png', 'cuts' => [3, 4, 3, 4]];
  return [...$base, 'colors' => ['text' => [43, 30, 10], 'background' => [237, 230, 210], 'panel' => [245, 235, 225]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88],
    'rowMetrics' => ['padding' => 24, 'iconWidth' => 32, 'iconHeight' => 12, 'cursorWidth' => 10, 'cursorHeight' => 20,
      'cursorInset' => 6, 'cursorTravel' => 3, 'cursorPeriod' => 2, 'separatorWidth' => 1.5],
    'rowArtwork' => ['normal' => $art, 'selected' => $art, 'focus' => ['asset' => 'focus.png'],
      'command.selected' => $art, 'command.focus' => ['asset' => 'focus.png']],
    'frames' => ['panel' => $art, 'quiet' => $art, 'portrait' => $art]];
}

function skillMenuText(PresentationCanvas $canvas): array
{
  $text = [];
  foreach ($canvas->textLayers as $layer) {
    $runs = array_filter($layer->runs, fn($run) => $run->foreground !== null);
    if ($runs !== []) { $text[$layer->id] = implode('', array_column($runs, 'text')); }
  }
  return $text;
}

function skillMenuKey(AbilityMenuState|MagicMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

function skillMenuOwner(object $fixture, bool $magic): AbilityMenuState|MagicMenuState
{
  $state = $magic ? new MagicMenuState(new SceneStateContext($fixture->scene))
    : new AbilityMenuState(new SceneStateContext($fixture->scene));
  new ReflectionProperty($fixture->scene, 'state')->setValue($fixture->scene, $state);
  $state->enter();
  return $state;
}

function skillMenuRuntime(object $fixture, ?array $theme, ?array $caps = null): void
{
  if ($theme !== null) { file_put_contents($fixture->root . '/Data/Presentation/menus.php', '<?php return ' . var_export($theme, true) . ';'); }
  $caps ??= MenuPresentationCatalog::CAPABILITIES;
  $fixture->transport = new FakeRendererTransport();
  $fixture->transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $fixture->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $fixture->root,
    requiredCapabilities: $caps), $fixture->transport);
  $fixture->runtime->start('Skill menu fixture', 135, 36);
  $fixture->game->useRendererRuntime($fixture->runtime);
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, ConfigStore::class, Debug::class, ModalManager::class, AudioManager::class, Time::class,
    \Ichiloto\Engine\Events\EventManager::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-skill-menu-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  foreach (['portrait', 'cursor', 'surface', 'focus', 'ability', 'magic'] as $name) { skillMenuPng($this->root . '/' . $name . '.png'); }
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  $token = new Item('Training Token', 'Learning cost.', '!', 1, 3, id: 'token.training');
  $store = makeBareScene(ItemStore::class);
  $store->set($token->id, $token);
  ConfigStore::put(ItemStore::class, $store);
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  new ReflectionProperty(Time::class, 'time')->setValue(null, 600.0);
  $this->game = new SkillMenuPresentationGame();
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
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->spell = new MagicSkill('Alpha Restore', 'Restores one eligible ally. Complete authored description.', 'NOT A NATIVE ICON', 3, 0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS, effects: [new HPRecoverSkillEffect('25', variance: 0.0)]);
  $this->ability = new SpecialSkill('Alpha Guard', 'Protect one ally. Complete authored description.', 'NOT A NATIVE ICON', 7, 0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $this->learnSpell = new LearnableSpell(new MagicSkill('Zeta Spell', 'Full learned spell description.', '?', 12, 0,
    new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN),
    new SpellLearningRequirement(10, 2, 120, ['token.training' => 2], ['trial.complete']), 2, note: 'Owner archive');
  $this->learnAbility = new LearnableAbility(new SpecialSkill('Zeta Ability', 'Full learned ability description.', '?', 14, 0,
    new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN),
    new AbilityLearningRequirement(10, 300, 120, ['token.training' => 2], ['trial.complete']), note: 'Owner mentor');
  $this->actor = new Character('Primary Actor', 100, new Stats(), abilityBook: new AbilityBook([$this->ability], [$this->learnAbility]),
    spellbook: new Spellbook([$this->spell], [$this->learnSpell]), actorId: 'actor.primary');
  $this->ally = new Character('Other Actor', 0, new Stats(), actorId: 'actor.other');
  foreach ([$this->actor, $this->ally] as $actor) {
    $actor->stats->totalHp = 100;
    $actor->stats->totalMp = 20;
    $actor->stats->currentHp = 30;
    $actor->stats->currentMp = 20;
  }
  $this->party = new Party();
  $this->party->addMember($this->actor);
  $this->party->addMember($this->ally);
  $this->party->accountBalance = 300;
  $this->party->inventory->addItems(clone $token);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->returnState = new SkillMenuPresentationReturnState(new SceneStateContext($this->scene));
  new ReflectionProperty($this->scene, 'mainMenuState')->setValue($this->scene, $this->returnState);
  $modalManager = makeBareScene(ModalManager::class);
  new ReflectionProperty($modalManager, 'modals')->setValue($modalManager, new Stack(ModalInterface::class));
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $modalManager);
  InputManager::setBindings(array_map(fn($key) => ['keys' => [$key]], ['confirm' => KeyCode::ENTER, 'cancel' => KeyCode::ESCAPE,
    'up' => KeyCode::UP, 'down' => KeyCode::DOWN, 'left' => KeyCode::LEFT, 'right' => KeyCode::RIGHT,
    'character_next' => KeyCode::Q, 'character_previous' => KeyCode::E]));
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

it('keeps scrolled terminal rows contiguous and selects the actual owner index', function (string $class) {
  $panel = new $class('List', '', new Vector2(0, 0), 32, 6, new DefaultBorderPack());
  $entries = array_map(fn($i) => 'Entry ' . $i, range(0, 29));
  $panel->setEntries($entries, 25);
  $content = $panel->getContent();
  expect(array_keys($content))->toBe([0, 1, 2, 3]);
  expect(array_map(fn($line) => trim(TerminalText::stripAnsi($line)), $content))->toBe(['Entry 22', 'Entry 23', 'Entry 24', 'Entry 25']);
  expect($content[3])->toBe(SelectionStyle::apply(str_pad('Entry 25', 28)));
  $panel->setEntries($entries, 0);
  expect(trim(TerminalText::stripAnsi($panel->getContent()[0])))->toBe('Entry 0');
})->with([MagicListPanel::class, AbilityListPanel::class]);

it('projects both real skill owners and every tab through two themes without mutating outcomes', function (bool $magic, bool $alternative) {
  $state = skillMenuOwner($this, $magic);
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme($alternative));
  $before = [$this->party->accountBalance, $this->actor->stats->currentMp, $this->learnSpell->isLearned, $this->learnAbility->isLearned];
  for ($tab = 0; $tab < 3; $tab++) {
    $content = $state->getPresentationContent();
    $frame = SkillMenuPresentation::compose($content, $theme);
    $text = skillMenuText($frame);
    $flat = implode('', $text);
    expect($text['skill-identity'])->toBe(($magic ? 'Magic' : 'Abilities') . $this->actor->name . 'Role: ' . $this->actor->role->name)
      ->and($text['skill-resource-1-text'])->toBe('HP30 / ' . $this->actor->effectiveStats->totalHp)
      ->and($text['skill-resource-2-text'])->toBe('MP20 / ' . $this->actor->effectiveStats->totalMp)
      ->and($text['skill-description'])->toBe($content->description)
      ->and($flat)->not->toContain('NOT A NATIVE ICON');
    foreach ($content->fields as $label => $value) { expect($flat)->toContain($label)->toContain($value); }
    foreach ($content->tabs as $index => $label) {
      $layers = array_column($frame->textLayers, null, 'id');
      $layer = $layers['skill-tab-' . $index . '-text'];
      expect($text[$layer->id])->toBe($label)
        ->and($layer->x + $layer->grid->columns * $layer->grid->cellWidth / 2)->toBe($layer->clipRect->x + $layer->clipRect->width / 2);
    }
    expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'skill-tab-') && str_ends_with($image->id, '-cursor')))->toBeEmpty();
    if ($tab === 1) { expect($flat)->toContain('trial.complete [Needed]')->toContain('Training Token 3/2')->toContain('Gold 300/120')->toContain('EXP 100/10'); }
    $ids = [...array_column($frame->images, 'id'), ...array_column($frame->textLayers, 'id')];
    expect(count(array_unique($ids)))->toBe(count($ids))->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
    expect($frame->toArray()['width'])->toBe(1350);
    skillMenuKey($state, KeyCode::RIGHT);
  }
  expect([$this->party->accountBalance, $this->actor->stats->currentMp, $this->learnSpell->isLearned, $this->learnAbility->isLearned])->toBe($before);
})->with([false, true])->with([false, true]);

it('agrees with learning flags in terminal and canvas and only spends costs on owner confirmation', function (bool $magic) {
  $state = skillMenuOwner($this, $magic);
  skillMenuKey($state, KeyCode::RIGHT);
  $learnable = $magic ? $this->learnSpell : $this->learnAbility;
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme());
  expect($state->getPresentationContent()->fields['Status'])->toBe($magic ? 'In Progress' : 'Locked');
  skillMenuKey($state, KeyCode::ENTER);
  expect($learnable->isLearned)->toBeFalse()->and($this->party->accountBalance)->toBe(300);
  $this->flags->recordStoryEvent('trial.complete');
  $state->resume();
  $content = $state->getPresentationContent();
  expect($content->fields['Status'])->toBe('Ready')->and($content->detailText)->toContain('trial.complete [Done]');
  $terminal = new ReflectionMethod($state, 'buildLearnDetailLines')->invoke($state);
  expect(implode(' ', $terminal))->toContain('Ready')->toContain('trial.complete [Done]');
  for ($redraw = 0; $redraw < 3; $redraw++) { SkillMenuPresentation::compose($state->getPresentationContent(), $theme); }
  expect($this->party->inventory->getQuantity('token.training'))->toBe(3)->and($this->party->accountBalance)->toBe(300);
  skillMenuKey($state, KeyCode::ENTER);
  expect($learnable->isLearned)->toBeTrue()->and($this->party->accountBalance)->toBe(180)
    ->and($this->party->inventory->getQuantity('token.training'))->toBe(1)
    ->and($state->getPresentationContent()->fields['Status'])->toBe('Learned');
  skillMenuKey($state, KeyCode::ENTER);
  expect($this->party->accountBalance)->toBe(180)->and($this->party->inventory->getQuantity('token.training'))->toBe(1);
})->with([false, true]);

it('keeps optional spell flag arguments backward compatible and reflects all required events', function () {
  expect($this->learnSpell->getStatusLabel($this->actor, $this->party))->toBe('In Progress');
  $requirement = $this->learnSpell->requirement;
  expect($requirement->describeProgress($this->actor, $this->party, 2))->toContain('trial.complete [Needed]');
  $requirement->requiredEvents[] = 'second.flag';
  expect($requirement->describeProgress($this->actor, $this->party, 2, ['trial.complete']))
    ->toContain('trial.complete [Done]')->toContain('second.flag [Needed]');
  expect($this->learnSpell->getStatusLabel($this->actor, $this->party, ['trial.complete']))->toBe('In Progress')
    ->and($this->learnSpell->getStatusLabel($this->actor, $this->party, ['trial.complete', 'second.flag']))->toBe('Ready');
});

it('keeps sort selection distinct from focus until the same owner applies it', function (bool $magic) {
  $state = skillMenuOwner($this, $magic);
  skillMenuKey($state, KeyCode::RIGHT);
  skillMenuKey($state, KeyCode::RIGHT);
  skillMenuKey($state, KeyCode::DOWN);
  $content = $state->getPresentationContent();
  expect($content->rows[0]->selected)->toBeTrue()->and($content->rows[0]->focused)->toBeFalse()
    ->and($content->rows[1]->selected)->toBeFalse()->and($content->rows[1]->focused)->toBeTrue();
  $frame = SkillMenuPresentation::compose($content, new MenuPresentationCatalog($this->root, skillMenuTheme(true)));
  $layers = array_column($frame->textLayers, null, 'id');
  foreach ([0, 1] as $index) {
    $label = $layers['skill-entry-' . $index . '-text'];
    expect($label->x + $label->grid->columns * $label->grid->cellWidth / 2)->toBe($label->clipRect->x + $label->clipRect->width / 2);
    expect($layers)->not->toHaveKey('skill-entry-' . $index . '-separator');
  }
  skillMenuKey($state, KeyCode::ENTER);
  expect($state->getPresentationContent()->summary['Current Order'])->toBe('Z-A')
    ->and($state->getPresentationContent()->rows[1]->selected)->toBeTrue();
  skillMenuKey($state, KeyCode::RIGHT);
  expect($state->getPresentationContent()->tabIndex)->toBe(0);
})->with([false, true]);

it('cycles stable actor roles using semantic bindings and existing Tab aliases then returns to Main', function (bool $magic) {
  $state = skillMenuOwner($this, $magic);
  skillMenuKey($state, KeyCode::Q);
  expect($state->character)->toBe($this->ally);
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme());
  $canvas = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
  expect(array_filter($canvas->images, fn($image) => $image->asset === 'portrait.png'))->toBeEmpty();
  expect(implode('', skillMenuText($canvas)))->toContain($magic ? 'No learned magic.' : 'No learned abilities.');
  skillMenuKey($state, KeyCode::TAB);
  expect($state->character)->toBe($this->actor);
  skillMenuKey($state, KeyCode::SHIFT_TAB);
  expect($state->character)->toBe($this->ally);
  skillMenuKey($state, KeyCode::E);
  expect($state->character)->toBe($this->actor);
  skillMenuKey($state, KeyCode::ESCAPE);
  expect($this->scene->state)->toBe($this->returnState)->and($this->returnState->entries)->toBe(1);
})->with([false, true]);

it('retains target selection and owner resources across redraw cancel and confirmation', function (bool $alternative) {
  $state = skillMenuOwner($this, true);
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme($alternative));
  skillMenuKey($state, KeyCode::ENTER);
  $content = $state->getPresentationContent();
  expect($content->targeting)->toBeTrue()->and(array_column($content->rows, 'label'))->toBe(['Primary Actor', 'Other Actor']);
  skillMenuKey($state, KeyCode::DOWN);
  skillMenuKey($state, KeyCode::Q);
  $content = $state->getPresentationContent();
  expect($content->index)->toBe(1)->and($content->character)->toBe($this->actor)->and($content->fields['Target'])->toBe('Other Actor');
  for ($i = 0; $i < 3; $i++) {
    $canvas = SkillMenuPresentation::compose($content, $theme);
    expect(implode('', skillMenuText($canvas)))->toContain('CasterPrimary Actor')->toContain('TargetOther Actor');
    expect(count($canvas->textLayers))->toBeLessThanOrEqual(64);
  }
  skillMenuKey($state, KeyCode::ESCAPE);
  expect($state->getPresentationContent()->targeting)->toBeFalse()->and($this->actor->stats->currentMp)->toBe(20)
    ->and($this->ally->stats->currentHp)->toBe(30)->and($this->scene->state)->toBe($state);
  skillMenuKey($state, KeyCode::ENTER);
  skillMenuKey($state, KeyCode::DOWN);
  skillMenuKey($state, KeyCode::ENTER);
  expect($this->actor->stats->currentMp)->toBe(17)->and($this->ally->stats->currentHp)->toBe(55)
    ->and($state->getPresentationContent()->targeting)->toBeFalse()
    ->and($state->getPresentationContent()->status)->toBe('Primary Actor cast Alpha Restore on Other Actor.');
  SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
})->with([false, true]);

it('keeps field casting failures inspectable without spending MP', function () {
  $state = skillMenuOwner($this, true);
  $this->actor->stats->currentMp = 0;
  skillMenuKey($state, KeyCode::ENTER);
  expect($state->getPresentationContent()->targeting)->toBeFalse()
    ->and($state->getPresentationContent()->status)->toBe('Not enough MP for Alpha Restore.');
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme());
  expect(implode('', skillMenuText(SkillMenuPresentation::compose($state->getPresentationContent(), $theme))))
    ->toContain('Not enough MP for Alpha Restore.')->toContain('3 MP');
  expect($this->actor->stats->currentMp)->toBe(0)->and($this->ally->stats->currentHp)->toBe(30);
});

it('submits an actual menu canvas with a supported modal and restores it without consuming menu input', function (bool $magic) {
  skillMenuRuntime($this, skillMenuTheme());
  $state = skillMenuOwner($this, $magic);
  $before = $state->getPresentationContent();
  expect($this->runtime->present($this->scene))->toBeTrue();
  $messages = array_filter($this->transport->sent, fn($message) => isset($message->payload['canvas']));
  expect($messages)->not->toBeEmpty();
  $modal = new AlertModal($this->game, 'Owner notice.', 'Notice');
  $stack = new ReflectionProperty($this->game->modalManager, 'modals')->getValue($this->game->modalManager);
  $stack->push($modal);
  new ReflectionProperty($modal, 'isShowing')->setValue($modal, true);
  expect($this->runtime->present($this->scene))->toBeTrue();
  $canvas = $this->scene->getPresentationCanvas();
  expect(implode('', skillMenuText($canvas)))->toContain('Owner notice.')->toContain($this->actor->name);
  $baseLayers = array_filter($canvas->textLayers, fn($layer) => !str_starts_with($layer->id, 'menu-modal-'));
  $modalLayers = array_filter($canvas->textLayers, fn($layer) => str_starts_with($layer->id, 'menu-modal-'));
  expect(min(array_column($modalLayers, 'layer')))->toBeGreaterThan(max(array_column($baseLayers, 'layer')));
  new ReflectionProperty($modal, 'isShowing')->setValue($modal, false);
  expect(implode('', skillMenuText($this->scene->getPresentationCanvas())))->not->toContain('Owner notice.');
  expect($state->getPresentationContent())->toEqual($before)->and($this->scene->state)->toBe($state);
  $stack->pop();
  $prompt = new PromptModal($this->game, 'Real prompt', 'Prompt');
  $stack->push($prompt);
  new ReflectionProperty($prompt, 'isShowing')->setValue($prompt, true);
  expect($this->scene->getPresentationCanvas())->toBeNull()->and($state->getPresentationContent())->toEqual($before);
  $stack->pop();
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
})->with([false, true]);

it('keeps terminal owners operational when theme or capabilities are unavailable', function (bool $magic, string $reason) {
  $theme = $reason === 'absent' ? null : skillMenuTheme();
  if ($reason === 'invalid') { $theme['portraits']['actor.primary'] = 'missing.png'; }
  skillMenuRuntime($this, $theme, $reason === 'capabilities' ? [] : null);
  $state = skillMenuOwner($this, $magic);
  expect($this->scene->getPresentationCanvas())->toBeNull();
  skillMenuKey($state, KeyCode::RIGHT);
  expect($state->getPresentationContent()->tabIndex)->toBe(1);
  $list = new ReflectionProperty($state, 'listPanel')->getValue($state);
  expect(implode('', $list->getContent()))->toContain($magic ? 'Zeta Spell' : 'Zeta Ability');
  if ($reason !== 'absent') { expect(file_get_contents($this->root . '/logs/error.log'))->toContain('Menu presentation degraded to terminal'); }
})->with([false, true])->with(['absent', 'capabilities', 'invalid']);

it('keeps long lists source complete and follows the active row within a bounded canvas', function (bool $magic, bool $alternative) {
  $skills = [];
  $class = $magic ? MagicSkill::class : SpecialSkill::class;
  for ($i = 0; $i < 70; $i++) {
    $skills[] = new $class(sprintf('Skill %02d with a descriptive authored name', $i), 'Full source description for ' . $i, 'ASCII', 1234, 0,
      new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL), $i % 2 ? Occasion::NEVER : Occasion::BATTLE_SCREEN);
  }
  new ReflectionProperty($this->actor, $magic ? 'spellbook' : 'abilityBook')
    ->setValue($this->actor, $magic ? new Spellbook($skills) : new AbilityBook($skills));
  $state = skillMenuOwner($this, $magic);
  skillMenuKey($state, KeyCode::UP);
  $content = $state->getPresentationContent();
  expect($content->index)->toBe(69)->and($content->rows)->toHaveCount(70);
  $frame = SkillMenuPresentation::compose($content, new MenuPresentationCatalog($this->root, skillMenuTheme($alternative)));
  $text = skillMenuText($frame);
  expect(implode('', $text))->toContain('Skill 69 with a descriptive authored name')->toContain('1234 MP')->toContain('Locked')
    ->and($text['skill-entry-range'])->toEndWith('70 / 70')->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
  $terminal = new ReflectionProperty($state, 'listPanel')->getValue($state)->getContent();
  expect(implode('', $terminal))->toContain('Skill 69');
})->with([false, true])->with([false, true]);

it('uses stable portrait identity and decodes replacement artwork proportions on redraw', function () {
  $state = skillMenuOwner($this, false);
  $theme = new MenuPresentationCatalog($this->root, skillMenuTheme());
  $first = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
  skillMenuPng($this->root . '/portrait.png', 21, 89);
  new ReflectionProperty($this->actor, 'name')->setValue($this->actor, 'Renamed Actor');
  $second = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
  $before = array_values(array_filter($first->images, fn($image) => $image->asset === 'portrait.png'))[0];
  $after = array_values(array_filter($second->images, fn($image) => $image->asset === 'portrait.png'))[0];
  expect($after->asset)->toBe($before->asset)->and($after->destination->height)->toBeGreaterThan($after->destination->width)
    ->and($before->destination->width)->toBeGreaterThan($before->destination->height)
    ->and(implode('', skillMenuText($second)))->toContain('Renamed Actor');
  $after->destination->assertWithin($second->width, $second->height);
});

it('pages complete description and status with fixed Info bounds and separate rebound hints', function (bool $magic) {
  $state = skillMenuOwner($this, $magic);
  $data = skillMenuTheme();
  $data['showInputHints'] = true;
  InputManager::setBinding('confirm', [KeyCode::F10]);
  InputManager::setBinding('cancel', [KeyCode::X]);
  $skill = $magic ? $this->spell : $this->ability;
  new ReflectionProperty($skill, 'description')->setValue($skill, str_repeat('Wrapped detail. ', 15));
  new ReflectionProperty($state, 'statusMessage')->setValue($state, str_repeat('Owner status. ', 8));
  $theme = new MenuPresentationCatalog($this->root, $data);
  $seen = '';
  $infoBounds = null;
  do {
    $frame = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
    $text = skillMenuText($frame);
    $layers = array_column($frame->textLayers, null, 'id');
    $bounds = $layers['skill-info-backing']->clipRect;
    $infoBounds ??= $bounds;
    expect($bounds)->toEqual($infoBounds)->and(implode('', $text))->toContain('F10', 'X');
    foreach (['skill-description', 'skill-status'] as $id) {
      $seen .= $text[$id] ?? '';
      if (!isset($layers[$id])) { continue; }
      foreach ($frame->textLayers as $layer) {
        if (str_starts_with($layer->id, 'skill-hints-')) {
          expect($layer->y)->toBeGreaterThanOrEqual($layers[$id]->y + $layers[$id]->bounds->height);
        }
      }
    }
    $page = $state->menuInfoText->lastPage;
    expect($page->rows)->toBe(2);
    $state->menuInfoText->advance();
  } while ($page->nextOffset !== 0);
  expect($seen)->toBe($skill->description . $state->getPresentationContent()->status);
})->with([false, true]);

it('keeps long Info readable and removes only graphical Magic source metadata', function (string $field) {
  skillMenuRuntime($this, skillMenuTheme());
  $state = skillMenuOwner($this, true);
  if ($field === 'description') { new ReflectionProperty($this->spell, 'description')->setValue($this->spell, str_repeat('Complete prose ', 500)); }
  else {
    $this->learnSpell->note = str_repeat('Source requirement ', 500);
    skillMenuKey($state, KeyCode::RIGHT);
  }
  $content = $state->getPresentationContent();
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class)
    ->and($this->scene->state)->toBe($state)->and($content->fields)->not->toHaveKey('Source');
  if ($field === 'description') {
    expect($content->description)->toBe($this->spell->description)
      ->and($state->menuInfoText->lastPage->source)->toBe($this->spell->description);
  } else { expect($this->learnSpell->note)->toBe(str_repeat('Source requirement ', 500)); }
})->with(['description', 'source']);

it('retains the neutral canvas without optional artwork and semantic hints remain live', function (bool $magic) {
  $state = skillMenuOwner($this, $magic);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $canvas = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
  expect($canvas->images)->toBeEmpty()->and(implode('', skillMenuText($canvas)))->toContain('Enter')->toContain('Escape');
  for ($tab = 0; $tab < 3; $tab++) {
    skillMenuKey($state, KeyCode::RIGHT);
    expect(SkillMenuPresentation::compose($state->getPresentationContent(), $theme))->toBeInstanceOf(PresentationCanvas::class);
  }
  InputManager::setBinding('confirm', []);
  InputManager::setBinding('cancel', []);
  $canvas = SkillMenuPresentation::compose($state->getPresentationContent(), $theme);
  expect(implode('', skillMenuText($canvas)))->toContain('Unbound')->not->toContain('Escape');
  expect($state->getPresentationContent()->index)->toBe(0);
})->with([false, true]);

it('retains every occasion and cost including field-ineligible learned skills', function (bool $magic) {
  $class = $magic ? MagicSkill::class : SpecialSkill::class;
  $skills = array_map(fn($occasion) => new $class($occasion->name, 'Owner description.', '?', 9, 0,
    new ItemScope(ItemScopeSide::USER), $occasion), Occasion::cases());
  new ReflectionProperty($this->actor, $magic ? 'spellbook' : 'abilityBook')
    ->setValue($this->actor, $magic ? new Spellbook($skills) : new AbilityBook($skills));
  $state = skillMenuOwner($this, $magic);
  $content = $state->getPresentationContent();
  expect($content->rows)->toHaveCount(4);
  $occasions = array_map(fn($row) => $row->values[0]->text, $content->rows);
  expect($occasions)->toContain('Both')->toContain('Battle')->toContain('Field')->toContain('Locked');
  foreach ($content->rows as $row) { expect($row->values[1]->text)->toBe('9 MP'); }
})->with([false, true]);
