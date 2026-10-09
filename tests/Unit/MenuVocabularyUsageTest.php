<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\NewGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenAbilityMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenConfigMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenEquipmentMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenItemsMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenMagicMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenQuitMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenSaveMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenStatusMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenSummonsMenuCommand;
use Ichiloto\Engine\Core\Menu\Commands\OpenTitleOptionsCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\ToTitleMenuCommand;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\CharacterDetailPanel;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\ItemMenu\Windows\ItemTargetStatusPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\AbilityMenuState;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\Game\States\MagicMenuState;
use Ichiloto\Engine\Scenes\Game\States\SaveMenuState;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Shop\Shop;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\CharacterMenuPresentation;
use Ichiloto\Engine\UI\Presentation\CharacterMenuRows;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Presentation\ShopMenuPresentation;
use Ichiloto\Engine\UI\Presentation\SkillMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitlePlayback;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\SaveSlotWindow;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;

class MenuVocabularyGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

class MenuVocabularyAlerts extends ModalManager
{
  public array $messages = [];
  public function __construct() { $this->modals = new Stack(ModalInterface::class); }
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    $this->messages[] = $message;
  }
}

function getMenuVocabularyCanvasText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

function invokeMenuVocabularyMethod(object $owner, string $method, mixed ...$args): mixed
{
  return new ReflectionMethod($owner, $method)->invoke($owner, ...$args);
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, ConfigStore::class, AudioManager::class, ModalManager::class, SaveManager::class, Debug::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = createTestDirectory('ichiloto-menu-vocabulary-');
  Debug::configure(['log_directory' => $this->root . '/logs']);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  putSceneAudioConfig([]);
  $this->game = new MenuVocabularyGameProbe();
  $audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty($manager, 'game')->setValue($manager, $this->game);
  $this->scene = makeBareScene(GameScene::class);
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $manager);
  new ReflectionProperty($this->scene, 'gameState')->setValue($this->scene, new GameState());
  $ui = makeBareScene(UIManager::class);
  $ui->locationHUDWindow = makeBareScene(LocationHUDWindow::class);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, $ui);
  $this->party = new Party();
  $scope = new ItemScope(ItemScopeSide::ALLY);
  $this->spell = new MagicSkill('Authored Spell', 'Keep this authored description.', '?', 7, 0, $scope, Occasion::ALWAYS);
  $this->ability = new SpecialSkill('Authored Ability', 'Keep this ability description.', '?', 8, 0, $scope, Occasion::BATTLE_SCREEN);
  $this->actor = new Character('Synthetic Actor', 0, new Stats(totalHp: 105, totalMp: 37),
    abilityBook: new AbilityBook([$this->ability]), spellbook: new Spellbook([$this->spell]), actorId: 'actor.vocabulary');
  $this->party->addMember($this->actor);
  new ReflectionProperty($this->scene, 'party')->setValue($this->scene, $this->party);
  $this->context = new SceneStateContext($this->scene);
  $this->menu = $this->createMock(MenuInterface::class);
  $this->menu->method('getScene')->willReturn($this->scene);
  $this->alerts = new MenuVocabularyAlerts();
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->alerts);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $this->alerts);
  $saves = $this->createMock(SaveManager::class);
  $saves->method('hasSaveFiles')->willReturn(true);
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $saves);
});

afterEach(function () {
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('uses authored game and command vocabulary without changing command identities', function () {
  putSceneAudioConfig(['locale' => 'custom', 'vocab' => [
    'game' => ['new_game' => 'Begin Chronicle', 'continue' => 'Resume Chronicle', 'options' => 'Preferences',
      'save' => 'Record', 'shutdown' => 'Close Chronicle', 'to_title' => 'Frontispiece'],
    'command' => ['item' => 'Supplies', 'skill' => 'Techniques', 'magic' => 'Sorcery', 'summon' => 'Petition',
      'equip' => 'Outfit', 'status' => 'Condition', 'game_end' => 'Leave'],
    'custom' => ['command' => ['magic' => 'Arcana']],
  ]]);
  $loader = makeBareScene(GameLoader::class);
  $commands = [new NewGameCommand($this->menu, $loader), new ContinueGameCommand($this->menu, $loader),
    new OpenTitleOptionsCommand($this->menu), new OpenConfigMenuCommand($this->menu), new OpenSaveMenuCommand($this->menu),
    new QuitGameCommand($this->menu), new ToTitleMenuCommand($this->menu), new OpenItemsMenuCommand($this->menu),
    new OpenAbilityMenuCommand($this->menu), new OpenMagicMenuCommand($this->menu), new OpenSummonsMenuCommand($this->menu),
    new OpenEquipmentMenuCommand($this->menu), new OpenStatusMenuCommand($this->menu), new OpenQuitMenuCommand($this->menu)];
  expect(array_map(fn($command) => $command->getLabel(), $commands))->toBe([
    'Begin Chronicle', 'Resume Chronicle', 'Preferences', 'Preferences', 'Record', 'Close Chronicle', 'Frontispiece',
    'Supplies', 'Techniques', 'Arcana', 'Petition', 'Outfit', 'Condition', 'Leave',
  ]);
  expect(array_map('get_class', $commands))->toBe([
    NewGameCommand::class, ContinueGameCommand::class, OpenTitleOptionsCommand::class, OpenConfigMenuCommand::class,
    OpenSaveMenuCommand::class, QuitGameCommand::class, ToTitleMenuCommand::class, OpenItemsMenuCommand::class,
    OpenAbilityMenuCommand::class, OpenMagicMenuCommand::class, OpenSummonsMenuCommand::class,
    OpenEquipmentMenuCommand::class, OpenStatusMenuCommand::class, OpenQuitMenuCommand::class,
  ]);
});

it('retains explicit legacy quit labels including an empty term and falls back to game_end', function (mixed $legacy, string $expected) {
  putSceneAudioConfig(['vocab' => ['command' => ['quit_game' => $legacy, 'game_end' => 'Depart']]]);
  expect(new OpenQuitMenuCommand($this->menu)->getLabel())->toBe($expected);
})->with([['Legacy Leave', 'Legacy Leave'], ['', ''], [null, 'Depart'], [false, 'Depart']]);

it('keeps existing menu wording when no vocabulary is authored', function () {
  $commands = [new OpenItemsMenuCommand($this->menu), new OpenAbilityMenuCommand($this->menu),
    new OpenMagicMenuCommand($this->menu), new OpenEquipmentMenuCommand($this->menu), new OpenSummonsMenuCommand($this->menu),
    new OpenConfigMenuCommand($this->menu), new OpenQuitMenuCommand($this->menu)];
  expect(array_map(fn($command) => $command->getLabel(), $commands))->toBe(['Items', 'Abilities', 'Magic', 'Equipment', 'Summons', 'Config', 'Quit']);
});

it('uses legacy command game labels only when the game term is missing', function () {
  putSceneAudioConfig(['vocab' => ['command' => ['new_game' => 'Embark', 'continue' => 'Rejoin', 'options' => 'Adjust']]]);
  $loader = makeBareScene(GameLoader::class);
  expect(new NewGameCommand($this->menu, $loader)->getLabel())->toBe('Embark')
    ->and(new ContinueGameCommand($this->menu, $loader)->getLabel())->toBe('Rejoin')
    ->and(new OpenTitleOptionsCommand($this->menu)->getLabel())->toBe('Adjust');
});

it('shares renamed skill headings and resource terms across Terminal and canvas without changing source or tabs', function (bool $magic, bool $alternative) {
  putSceneAudioConfig(['vocab' => ['command' => ['magic' => 'Arcana', 'skill' => 'Techniques'],
    'stats' => ['level' => 'Rank', 'hp' => 'Vitality', 'mp' => 'Mana']]]);
  $state = $magic ? new MagicMenuState($this->context) : new AbilityMenuState($this->context);
  $state->character = $this->actor;
  $before = [$this->actor->actorId, $this->actor->stats->currentMp, $this->party->accountBalance,
    $this->spell->name, $this->spell->description, $this->ability->name, $this->ability->description];
  $content = $state->getPresentationContent();
  $term = $magic ? 'Arcana' : 'Techniques';
  expect($content->title)->toBe($term)->and($content->listTitle)->toBe(($magic ? 'Use ' : 'Ready ') . $term)
    ->and($content->tabs)->toBe($magic ? ['Use', 'Learn', 'Sort'] : ['Ready', 'Learn', 'Sort'])
    ->and($content->rows[0]->id)->toBe('0')->and($content->rows[0]->values[1]->text)->toBe(($magic ? '7' : '8') . ' Mana');
  $terminal = implode(' ', invokeMenuVocabularyMethod($state, 'buildSummaryLines'));
  expect($terminal)->toContain($term, 'Rank', 'Vitality', 'Mana')
    ->and(implode(' ', invokeMenuVocabularyMethod($state, $magic ? 'buildUseEntries' : 'buildReadyEntries')))->toContain('Mana')
    ->and(implode(' ', invokeMenuVocabularyMethod($state, $magic ? 'buildUseDetailLines' : 'buildReadyDetailLines')))->toContain('Mana Cost');
  $data = ['schema' => 'ichiloto.menu/1'];
  if ($alternative) { $data['metrics'] = ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8, 'portraitSize' => 88]; }
  $canvas = SkillMenuPresentation::compose($content, new MenuPresentationCatalog($this->root, $data));
  expect(getMenuVocabularyCanvasText($canvas))->toContain($term, 'Rank', 'Vitality', 'Mana', $magic ? $this->spell->description : $this->ability->description);
  expect([$this->actor->actorId, $this->actor->stats->currentMp, $this->party->accountBalance,
    $this->spell->name, $this->spell->description, $this->ability->name, $this->ability->description])->toBe($before);
})->with([false, true])->with([false, true]);

it('keeps all skill resource values when authored labels are identical or empty', function () {
  putSceneAudioConfig(['vocab' => ['stats' => ['level' => '', 'hp' => '', 'mp' => '']]]);
  $state = new MagicMenuState($this->context);
  $state->character = $this->actor;
  $canvas = SkillMenuPresentation::compose($state->getPresentationContent(), new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));
  $ids = array_column($canvas->textLayers, 'id');
  foreach (['0', '1', '2'] as $index) { expect($ids)->toContain('skill-resource-' . $index . '-text'); }
  expect(getMenuVocabularyCanvasText($canvas))->toContain('105', '37');
  new ReflectionProperty($state, 'pendingUseSpell')->setValue($state, $this->spell);
  new ReflectionProperty($state, 'targetCandidates')->setValue($state, [$this->actor]);
  new ReflectionProperty($state, 'activeTargetIndex')->setValue($state, 0);
  $content = $state->getPresentationContent();
  expect($content->fields)->toHaveKeys(['MP Cost', 'HP', 'MP'])
    ->and($content->fields['HP'])->toContain('105')->and($content->fields['MP'])->toContain('37');
  $canvas = SkillMenuPresentation::compose($content, new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));
  $fields = array_values(array_filter($canvas->textLayers, fn($layer) => preg_match('/^skill-detail-field-[0-9]+-text$/', $layer->id)));
  expect($fields)->toHaveCount(count($content->fields));
});

it('uses renamed terms for empty and sort headings without translating the tab identities', function (bool $magic) {
  putSceneAudioConfig(['vocab' => ['command' => ['magic' => 'Arcana', 'skill' => 'Techniques']]]);
  new ReflectionProperty($this->actor, $magic ? 'spellbook' : 'abilityBook')
    ->setValue($this->actor, $magic ? new Spellbook() : new AbilityBook());
  $state = $magic ? new MagicMenuState($this->context) : new AbilityMenuState($this->context);
  $state->character = $this->actor;
  $term = $magic ? 'Arcana' : 'Techniques';
  expect($state->getPresentationContent()->emptyText)->toBe('No learned ' . $term . '.')
    ->and(invokeMenuVocabularyMethod($state, $magic ? 'buildUseDetailLines' : 'buildReadyDetailLines')[0])->toBe('No learned ' . $term . '.');
  new ReflectionProperty($state, 'activeTabIndex')->setValue($state, 1);
  expect($state->getPresentationContent()->emptyText)->toBe('No discovered ' . $term . '.')
    ->and(invokeMenuVocabularyMethod($state, 'buildLearnDetailLines')[0])->toBe('No discovered ' . $term . '.');
  new ReflectionProperty($state, 'activeTabIndex')->setValue($state, 2);
  expect($state->getPresentationContent()->detailTitle)->toBe($term . ' Order')
    ->and(invokeMenuVocabularyMethod($state, 'buildSortDetailLines')[0])->toBe($term . ' Order');
})->with([false, true]);

it('shares renamed stat and experience labels in status and equipment with stable row identities', function () {
  putSceneAudioConfig(['vocab' => ['stats' => ['level' => 'Rank', 'hp' => 'Vitality', 'mp' => 'Mana', 'ap' => 'Focus',
    'exp' => 'Insight', 'attack' => 'Power'], 'shop' => ['exp_total' => 'Collected %1']]]);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $status = new StatusViewState($this->context);
  $status->character = $this->actor;
  expect(implode(' ', invokeMenuVocabularyMethod($status, 'buildProfileSummaryContent')))->toContain('Rank', 'Vitality', 'Mana', 'Focus', 'Collected Insight', 'To Next Rank');
  expect(getMenuVocabularyCanvasText(CharacterMenuPresentation::status($this->actor, $theme)))->toContain('Rank', 'Vitality', 'Mana', 'Focus', 'Collected Insight', 'To Next Rank', 'Power');
  $rows = CharacterMenuRows::stats($this->actor, $theme, true);
  expect(array_column($rows, 'id'))->toContain('totalHp', 'totalMp', 'attack')
    ->and(array_column($rows, 'label'))->toContain('Vitality', 'Mana', 'Power');
  $detail = new CharacterDetailPanel($this->menu, new Rect(0, 0, 50, 23), new DefaultBorderPack());
  $detail->setDetails($this->actor);
  expect(implode(' ', $detail->getContent()))->toContain('Vitality', 'Mana', 'Power');
  $card = new CharacterPanel(new Rect(0, 0, 50, 10));
  $card->setDetails('Actor', 3, '105/105', '37/37');
  expect(implode(' ', $card->getContent()))->toContain('Rank', 'Vitality', 'Mana');
  $itemStatus = new ItemTargetStatusPanel(new ItemMenuState($this->context), new Rect(0, 0, 50, 5), new DefaultBorderPack());
  $itemStatus->setTarget($this->actor);
  expect(implode(' ', $itemStatus->getContent()))->toContain('Rank', 'Vitality', 'Mana');
});

it('preserves authored full experience messages while substituting optional vocabulary arguments', function () {
  putSceneAudioConfig(['vocab' => ['stats' => ['exp' => 'Insight', 'level' => 'Rank'], 'shop' => ['exp_total' => 'Do not replace the message']],
    'messages' => ['exp_total' => 'Owned %1', 'exp_next' => 'Until %1']]);
  $status = new StatusViewState($this->context);
  $status->character = $this->actor;
  $terminal = implode(' ', invokeMenuVocabularyMethod($status, 'buildProfileSummaryContent'));
  $canvas = CharacterMenuPresentation::status($this->actor, new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));
  foreach ([$terminal, getMenuVocabularyCanvasText($canvas)] as $text) {
    expect($text)->toContain('Owned Insight', 'Until Rank')->not->toContain('Do not replace');
  }
});

it('shares renamed shop buttons and possession while routing by their original owners', function () {
  putSceneAudioConfig(['vocab' => ['shop' => ['buy' => 'Acquire', 'sell' => 'Trade Away', 'cancel' => 'Depart', 'possession' => 'Held']]]);
  $state = new ShopState($this->context);
  $state->merchandise = [new Item('Synthetic Tonic', 'Authored item prose.', '!', 10, id: 'item.vocabulary')];
  $state->enter();
  expect(array_map(fn($command) => $command->getLabel(), $state->shopMenu->getItems()->toArray()))->toBe(['Acquire', 'Trade Away', 'Depart']);
  expect(getMenuVocabularyCanvasText(ShopMenuPresentation::compose($state, new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']))))
    ->toContain('Acquire', 'Trade Away', 'Depart');
  $state->shopMenu->getItemByIndex(0)->execute();
  expect(implode(' ', $state->detailPanel->getContent()))->toContain('Held');
  expect($state->mode)->toBeInstanceOf(\Ichiloto\Engine\Core\Menu\ShopMenu\Modes\ShopMerchandiseSelectionMode::class)
    ->and($state->merchandise[0]->id)->toBe('item.vocabulary');
});

it('uses vocabulary for save and load headings and retains authored prompts and explicit presentation titles', function () {
  putSceneAudioConfig(['vocab' => ['game' => ['save' => 'Record', 'load' => 'Recall']],
    'messages' => ['prompt' => ['save' => 'Owned save prompt.', 'load' => 'Owned load prompt.']]]);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $save = new SaveMenuState($this->context);
  invokeMenuVocabularyMethod($save, 'initializeUI');
  $window = new ReflectionProperty($save, 'infoWindow')->getValue($save);
  expect($window->getTitle())->toBe('Record');
  $canvas = invokeMenuVocabularyMethod($save, 'composeMenuCanvas', $theme, 0.0);
  expect(getMenuVocabularyCanvasText($canvas))->toContain('Record', 'Owned save prompt.');
  expect(getMenuVocabularyCanvasText(SaveLoadMenuPresentation::compose([], 0, $theme, new MenuInfoText())))
    ->toContain('Recall', 'Owned load prompt.');
  expect(getMenuVocabularyCanvasText(SaveLoadMenuPresentation::compose([], 0, $theme, new MenuInfoText(), title: 'Authored Title', prompt: 'Authored prompt.')))
    ->toContain('Authored Title', 'Authored prompt.')->not->toContain('Recall');
});

it('uses currency vocabulary in shop refusal but preserves an authored full message and balances', function () {
  $item = new Item('Synthetic Tonic', '', '!', 10, id: 'item.vocabulary');
  $shop = new Shop([$item]);
  putSceneAudioConfig(['vocab' => ['currency' => ['name' => 'Crowns']]]);
  $shop->sell($item, 1, $this->party);
  expect($this->alerts->messages)->toBe(['Not enough Crowns!']);
  putSceneAudioConfig(['vocab' => ['currency' => ['name' => 'Crowns']], 'messages' => ['shop' => ['insufficient_funds' => 'Need more %1.']]]);
  $shop->sell($item, 1, $this->party);
  expect($this->alerts->messages)->toBe(['Not enough Crowns!', 'Need more Crowns.'])
    ->and($this->party->accountBalance)->toBe(0)->and($this->party->inventory->getQuantity($item->id))->toBe(0);
});

it('shares the title owner continuation and options terms between Terminal and actual canvas overlays', function () {
  putSceneAudioConfig(['vocab' => ['game' => ['continue' => 'Resume Chronicle', 'load' => 'Recall', 'options' => 'Preferences'],
    'command' => ['continue' => 'Rejoin', 'options' => 'Adjust']],
    'messages' => ['prompt' => ['load' => 'Owned load prompt.']]]);
  $title = makeBareScene(TitleScene::class);
  $manager = new ReflectionProperty($this->scene, 'sceneManager')->getValue($this->scene);
  new ReflectionProperty($title, 'sceneManager')->setValue($title, $manager);
  invokeMenuVocabularyMethod($title, 'initializeOptionsWindow');
  invokeMenuVocabularyMethod($title, 'initializeContinueMenuWindows');
  expect(new ReflectionProperty($title, 'optionsWindow')->getValue($title)->getTitle())->toBe('Preferences')
    ->and(new ReflectionProperty($title, 'continueInfoWindow')->getValue($title)->getTitle())->toBe('Resume Chronicle');
  $runtime = makeBareScene(RendererRuntime::class);
  new ReflectionProperty($runtime, 'config')->setValue($runtime,
    new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $this->root));
  new ReflectionProperty(Game::class, 'rendererRuntime')->setValue($this->game, $runtime);
  $menu = new \Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu($title, '');
  new ReflectionProperty($title, 'menu')->setValue($title, $menu);
  new ReflectionProperty($title, 'titlePlayback')->setValue($title, new TitlePlayback());
  new ReflectionProperty($title, 'titleCatalogLoaded')->setValue($title, true);
  new ReflectionProperty($title, 'titleCatalog')->setValue($title, new TitlePresentationCatalog($this->root, [
    'schema' => 'ichiloto.title/1', 'theme' => ['schema' => 'ichiloto.menu/1'], 'logo' => 'absent-logo.png',
    'scenes' => ['day' => ['background' => 'absent-day.png'], 'night' => ['background' => 'absent-night.png']],
  ]));
  new ReflectionProperty($title, 'optionsManager')->setValue($title, makeBareScene(TitleOptionsSettingsManager::class));
  new ReflectionProperty($title, 'showingOptions')->setValue($title, true);
  expect(getMenuVocabularyCanvasText($title->getPresentationCanvas()))->toContain('Preferences');
  new ReflectionProperty($title, 'showingOptions')->setValue($title, false);
  new ReflectionProperty($title, 'showingContinueMenu')->setValue($title, true);
  expect(getMenuVocabularyCanvasText($title->getPresentationCanvas()))->toContain('Resume Chronicle', 'Owned load prompt.')
    ->not->toContain('Recall');
  putSceneAudioConfig(['vocab' => ['command' => ['continue' => 'Rejoin', 'options' => 'Adjust']]]);
  expect(invokeMenuVocabularyMethod($title, 'getContinueMenuTitle'))->toBe('Rejoin')
    ->and(invokeMenuVocabularyMethod($title, 'getOptionsMenuTitle'))->toBe('Adjust');
  putSceneAudioConfig([]);
  expect(invokeMenuVocabularyMethod($title, 'getContinueMenuTitle'))->toBe('Continue')
    ->and(invokeMenuVocabularyMethod($title, 'getOptionsMenuTitle'))->toBe('Options');
});

it('shares file and level terms in Terminal and canvas slots without changing serialized metadata', function (bool $selected) {
  $slot = new SaveSlot(7, $this->root . '/synthetic.iedata', false, 'Authored Location', 'Authored Leader', 4, 125, 1000);
  $before = $slot->__serialize();
  $window = new SaveSlotWindow(new \Ichiloto\Engine\Core\Vector2(), 110);
  $window->setSlot($slot);
  expect($slot->getLeaderSummary())->toBe('Authored Leader Lv 4')->and($window->getTitle())->toBe('File 7');
  putSceneAudioConfig(['vocab' => ['stats' => ['level' => 'Rank']], 'messages' => ['file' => 'Archive']]);
  $window->setSlot($slot, $selected);
  expect($slot->getLeaderSummary())->toBe('Authored Leader Rank 4')->and($window->getTitle())->toContain('Archive 7')
    ->and(implode(' ', $window->getContent()))->toContain('Authored Leader Rank 4', 'Authored Location');
  $canvas = SaveLoadMenuPresentation::compose([$slot], 0,
    new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']), new MenuInfoText());
  expect(getMenuVocabularyCanvasText($canvas))->toContain('Archive 7', 'Authored Leader Rank 4', 'Authored Location')
    ->and($slot->__serialize())->toBe($before)->and(unserialize(serialize($slot))->__serialize())->toBe($before);
})->with([false, true]);
