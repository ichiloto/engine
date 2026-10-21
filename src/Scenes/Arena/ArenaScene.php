<?php

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\UI\Presentation\BattleTestMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Throwable;

/**
 * The battle test, as RPG Maker's Battle Test: pick a troop, set up the
 * party (its members, their levels and equipment), and fight it for real
 * with the game's own battle system. Every fight starts from the setup with
 * a fresh party and a fresh troop, so nothing one battle does (experience,
 * levels, gold, items, states, defeated enemies) carries into the next.
 *
 * Down from the troop list moves into the party; confirm edits a member;
 * left and right change its actor and level (a page step changes the level
 * by ten); confirm on a slot chooses its equipment; cancel steps back out.
 * In a graphical renderer, left and right on the troop list choose the
 * battle presentation's arena the fights take place in.
 *
 * After the troops, a row composes a test troop: pick enemies, move them
 * on the stage where a graphical renderer draws it, fight it, and keep it
 * as one of the game's troops where a keeper is given
 * ({@see BattleTestTroopKeeper}); otherwise it is for testing only.
 *
 * @package Ichiloto\Engine\Scenes\Arena
 */
class ArenaScene extends AbstractScene implements CanvasProviderInterface
{
  protected const int PANEL_WIDTH = 60;
  protected const int LIST_HEIGHT = 18;
  protected const int INFO_HEIGHT = BattleTestSetup::MAX_MEMBERS + 2;
  /** The setup option a caller may start the arena with, a {@see BattleTestSetup}. */
  public const string SETUP_OPTION = 'arena_setup';
  /** The option naming who keeps a composed test troop, a {@see BattleTestTroopKeeper}. */
  public const string KEEPER_OPTION = 'arena_troop_keeper';
  /** What a composed troop is called while it is fought, before it is kept. */
  public const string TEST_TROOP_NAME = 'Test Troop';

  /**
   * @var list<array<string, mixed>> Each troop's data, built into a fresh troop for every fight.
   */
  protected array $troopData = [];
  /**
   * @var Troop[] The troops, built once for the list.
   */
  protected array $troops = [];
  protected ?ArenaSetupEditor $editor = null;
  /** The project's battle test, once read: its troop preselects the list. */
  protected ?ProjectBattleTest $projectBattleTest = null;
  /**
   * @var Party|null The party the setup describes, for the party panel; never the one that fights.
   */
  protected ?Party $previewParty = null;
  /** @var array<string, int> Each list's scroll offset, by focus. */
  protected array $scrollOffsets = [];
  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;
  /** @var array<string, string> The arenas a fight may take place in, key => name; none when nothing draws them. */
  protected array $arenaChoices = [];
  /** The name of the arena a fight takes place in when none is chosen, if the presentation names one. */
  protected ?string $defaultArenaName = null;
  /** The battle presentation a graphical renderer draws fights with; none in the terminal. */
  protected ?BattlePresentationCatalog $battleCatalog = null;
  /** Who keeps a composed test troop; none when composed troops are for testing only. */
  protected ?BattleTestTroopKeeper $troopKeeper = null;
  /** What the last attempt to keep a composed troop came to, shown until the next. */
  protected ?string $keepStatus = null;
  /** The game's menu theme, once read; none draws the battle test in the terminal's windows. */
  private ?MenuPresentationCatalog $menuTheme = null;
  private bool $menuThemeLoaded = false;
  private ?string $presentationError = null;
  /** @var array{key: string, stage: ?BattleFormationLayout, issue: ?string}|null The stage last composed, kept while what it shows is unchanged. */
  private ?array $stage = null;

  /**
   * @inheritDoc
   */
  public function getBackgroundMusic(): ?string
  {
    return $this->getConfiguredBackgroundMusic('audio.bgm.battle');
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    parent::start();

    $this->loadTroops();
    $this->editor = $this->createEditor();
    $this->refreshPreview();
    $this->buildPanels();

    Console::clear();
    $this->render();

    // Booted with a troop named: skip the list and fight it. Otherwise the
    // list opens, on the project's battle test troop when it names one.
    $wanted = strval($this->getGame()->options['arena_troop'] ?? '');

    if ($wanted !== '') {
      $this->fightByName($wanted);
    } elseif (($troop = $this->projectBattleTest?->troop) !== null) {
      $this->selectTroopByName($troop) || Debug::warn(sprintf('The battle test troop %s is not among the troops.', $troop));
      $this->render();
    }
  }

  /** Puts the troop list's cursor on a troop, by name, without fighting it. */
  private function selectTroopByName(string $troopName): bool
  {
    foreach ($this->troops as $index => $troop) {
      if (strcasecmp($troop->name, $troopName) === 0) {
        $this->editor?->selectTroop($index);

        return true;
      }
    }

    return false;
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    parent::resume();

    // Back from a fight: the setup is unchanged, and the next fight builds
    // its own party and troop from it.
    Console::clear();
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();

    $editor = $this->editor;

    if ($this->troops === [] || $editor === null) {
      if (Input::isButtonDown('quit') || Input::isButtonDown('cancel')) {
        $this->getGame()->quit();
      }

      return;
    }

    $setup = $editor->setup;
    $vertical = Input::getAxis(AxisName::VERTICAL);
    $horizontal = Input::getAxis(AxisName::HORIZONTAL);
    $request = null;

    if (abs($vertical) > 0.1) {
      $editor->moveVertical($vertical > 0 ? 1 : -1);
    } elseif (abs($horizontal) > 0.1) {
      $editor->moveHorizontal($horizontal > 0 ? 1 : -1);
    } elseif (Input::isButtonDown('menu_page_next')) {
      $editor->stepLevel(10);
    } elseif (Input::isButtonDown('menu_page_previous')) {
      $editor->stepLevel(-10);
    } elseif (Input::isButtonDown('confirm') || Input::isButtonDown('action')) {
      $request = $editor->confirm();
    } elseif (Input::isButtonDown('cancel')
      || ($editor->focus === ArenaSetupEditor::TROOPS && Input::isAnyKeyPressed([KeyCode::Q, KeyCode::q]))) {
      $request = $editor->cancel();
    } else {
      return;
    }

    if ($request === ArenaSetupEditor::QUIT) {
      $this->getGame()->quit();

      return;
    }
    if ($request === ArenaSetupEditor::FIGHT) {
      $this->fight($editor->troopIndex);

      return;
    }
    if ($request === ArenaSetupEditor::FIGHT_TEST) {
      $this->fightTestTroop();

      return;
    }
    if ($request === ArenaSetupEditor::KEEP) {
      $this->keepTestTroop();
    }
    if ($editor->setup !== $setup) {
      $this->refreshPreview();
    }
    $this->render();
  }

  /**
   * Starts a fight against a troop, by name.
   *
   * Used to drop straight into one fight rather than the list.
   *
   * @param string $troopName The troop's name.
   * @return bool True when the troop exists and the fight started.
   */
  public function fightByName(string $troopName): bool
  {
    foreach ($this->troops as $index => $troop) {
      if (strcasecmp($troop->name, $troopName) === 0) {
        $this->editor?->selectTroop($index);
        $this->fight($index);

        return true;
      }
    }

    return false;
  }

  /**
   * Fights a troop for real, with a fresh party and troop built from the setup.
   *
   * @param int $index The troop's place in the list.
   * @return void
   */
  protected function fight(int $index): void
  {
    $setup = $this->editor?->setup;
    $data = $this->troopData[$index] ?? null;

    if ($setup === null || $data === null) {
      return;
    }

    try {
      $this->getGame()->sceneManager->loadBattleScene(
        $setup->createParty(ConfigStore::get(ActorStore::class), ConfigStore::get(ItemStore::class)),
        Troop::fromArray($data),
        extraSettings: $setup->getBattleSettings(),
      );
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not start a battle: %s', $exception->getMessage()));
    }
  }

  /** Fights the composed test troop, built afresh from its data like every troop. */
  protected function fightTestTroop(): void
  {
    $setup = $this->editor?->setup;
    $troop = $this->editor?->composer?->troop;

    if ($setup === null || $troop === null || $troop->members === []) {
      return;
    }

    try {
      $this->getGame()->sceneManager->loadBattleScene(
        $setup->createParty(ConfigStore::get(ActorStore::class), ConfigStore::get(ItemStore::class)),
        Troop::fromArray($troop->toTroopData(self::TEST_TROOP_NAME)),
        extraSettings: $setup->getBattleSettings(),
      );
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not start a battle with the test troop: %s', $exception->getMessage()));
    }
  }

  /** Keeps the composed test troop as one of the game's troops, through the keeper, and lists the troops again. */
  protected function keepTestTroop(): void
  {
    $troop = $this->editor?->composer?->troop;
    if ($troop === null || $this->troopKeeper === null) {
      return;
    }

    try {
      $this->keepStatus = $this->troopKeeper->keepTroop($troop->toMembers());
    } catch (Throwable $exception) {
      $this->keepStatus = 'Not kept: ' . $exception->getMessage();

      return;
    }
    $this->loadTroops();
    $this->editor?->setTroopCount(count($this->troops));
  }

  /**
   * The test troop composer: the project's enemies, each new member placed
   * on the terminal field by the Engine's rule beside the others, and, where
   * a graphical battle draws the stage, members moved where the battle
   * stands them. None when the project has no enemies.
   */
  protected function createComposer(): ?TestTroopComposer
  {
    $enemies = ConfigStore::has(EnemyStore::class) ? ConfigStore::get(EnemyStore::class)->all() : [];
    if ($enemies === []) {
      return null;
    }
    $field = new TerminalBattleEffects();
    $positionFor = static function (BattleTestTroop $troop, string $enemy) use ($enemies, $field): array {
      $members = array_map(static fn(array $member): array => ['position' => new Vector2(...$member['position']),
        'sprite' => $enemies[$member['enemy']]->image ?? []], $troop->members);
      $place = $field->findTroopPlace($members, $enemies[$enemy]->image ?? []);

      return [(int) $place->x, (int) $place->y];
    };
    $catalog = $this->battleCatalog;
    $assetRoot = $this->getGame()->getRendererRuntime()?->getAssetRoot();
    $placesFor = $catalog?->ui === null || $assetRoot === null ? null
      : static fn(BattleTestTroop $troop): array => BattleFormationLayout::arrangeTroop($catalog, $troop->getEnemies(),
        $troop->getPlaces(), $assetRoot);

    return new TestTroopComposer(array_map(strval(...), array_keys($enemies)), $positionFor, $placesFor, $this->troopKeeper !== null);
  }

  /**
   * Loads the project's troops: their data for fights, and each built once for the list.
   *
   * @return void
   */
  protected function loadTroops(): void
  {
    $this->troopData = [];
    $this->troops = [];

    foreach ((array) asset('Data/troops.php', true) as $data) {
      if (! is_array($data)) {
        continue;
      }

      try {
        $this->troops[] = Troop::fromArray($data);
        $this->troopData[] = $data;
      } catch (Throwable $exception) {
        Debug::warn(sprintf('The arena skipped a troop: %s', $exception->getMessage()));
      }
    }
  }

  /**
   * The setup editor, starting from the setup a caller passed or else the
   * project's battle test ({@see ProjectBattleTest}): its party, or the
   * starting party when it keeps none, and its arena.
   *
   * @return ArenaSetupEditor|null Null when no party can be built.
   */
  protected function createEditor(): ?ArenaSetupEditor
  {
    $this->loadArenaChoices();

    try {
      $actors = ConfigStore::get(ActorStore::class);
      $choices = new BattleTestChoices($actors, ConfigStore::get(ItemStore::class), BattleTestLoadoutCatalog::getProjectCatalog());
      $this->projectBattleTest = $this->getGame()->consumeLaunchBattleTest() ?? ProjectBattleTest::loadFromProject();
      $setup = $this->getGame()->options[self::SETUP_OPTION] ?? null;
      $setup = $setup instanceof BattleTestSetup
        ? $setup
        : $this->projectBattleTest->createSetup($actors);
      $keeper = $this->getGame()->options[self::KEEPER_OPTION] ?? null;
      $this->troopKeeper = $keeper instanceof BattleTestTroopKeeper ? $keeper : $this->getGame()->consumeLaunchTroopKeeper();

      return new ArenaSetupEditor(
        $setup,
        count($this->troops),
        $actors->getActorIds(),
        $choices->getSlotNames(...),
        $choices->getEquipmentChoices(...),
        $choices->getMaxLevel(...),
        // The member as edited, in its place in the setup as it stands.
        fn(BattleTestMember $member, string $field): array => $choices->getLoadoutChoices(
          $this->editor->setup->withMember($this->editor->memberIndex, $member), $this->editor->memberIndex, $field),
        array_map(strval(...), array_keys($this->arenaChoices)),
        $this->createComposer(),
      );
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not set up a party: %s', $exception->getMessage()));

      return null;
    }
  }

  /**
   * Reads the arenas a graphical renderer can draw a fight in. A terminal
   * renderer draws none, so it is offered none.
   */
  protected function loadArenaChoices(): void
  {
    $this->arenaChoices = [];
    $this->defaultArenaName = null;
    $this->battleCatalog = null;
    $runtime = $this->getGame()->getRendererRuntime();

    if ($runtime === null) {
      return;
    }

    try {
      $catalog = BattlePresentationCatalog::load($runtime->getAssetRoot());
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not read the battle presentation: %s', $exception->getMessage()));

      return;
    }

    $this->battleCatalog = $catalog;
    $this->arenaChoices = $catalog?->getArenaChoices() ?? [];
    $this->defaultArenaName = $catalog?->defaultArena === null ? null : ($this->arenaChoices[$catalog->defaultArena] ?? null);
  }

  /**
   * Rebuilds the party the panel shows from the setup.
   *
   * @return void
   */
  protected function refreshPreview(): void
  {
    try {
      $this->previewParty = $this->editor?->setup->createParty(ConfigStore::get(ActorStore::class), ConfigStore::get(ItemStore::class));
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not build its party: %s', $exception->getMessage()));
      $this->previewParty = null;
    }
  }

  /**
   * Builds the screen's panels.
   *
   * @return void
   */
  protected function buildPanels(): void
  {
    $borderPack = new DefaultBorderPack();
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::PANEL_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - (self::LIST_HEIGHT + self::INFO_HEIGHT), 2));

    // The list is of the troops to fight, so it says so.
    $this->listPanel = new Window(
      'Troop',
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      self::PANEL_WIDTH,
      self::LIST_HEIGHT,
      $borderPack
    );

    $this->infoPanel = new Window(
      'Party',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::LIST_HEIGHT),
      self::PANEL_WIDTH,
      self::INFO_HEIGHT,
      $borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $innerWidth = self::PANEL_WIDTH - 4;
    $focus = $this->editor?->focus ?? ArenaSetupEditor::TROOPS;
    $list = $this->describeList();
    $arena = $list->namesArena ? $this->getArenaName() : null;
    $this->listPanel?->setTitle($arena === null ? $list->title : sprintf('%s · Arena: < %s >', $list->title, $arena));
    $this->listPanel?->setHelp($list->help);
    $this->listPanel?->setContent($this->formatList($list->getTerminalRows(), $list->selected, $innerWidth,
      self::LIST_HEIGHT - 2, $focus === ArenaSetupEditor::PARTY ? ArenaSetupEditor::TROOPS : $focus));
    $this->listPanel?->render();

    $this->infoPanel?->setHelp($focus === ArenaSetupEditor::PARTY ? 'enter:Edit  esc:Back' : '');
    $this->infoPanel?->setContent($this->describeParty($innerWidth));
    $this->infoPanel?->render();
  }

  /**
   * The list the battle test shows now, as every presentation draws it: the
   * troops (with composing a test troop after them), a member being set up
   * or what one of its slots can hold, or the test troop composer.
   */
  public function describeList(): BattleTestMenuList
  {
    $editor = $this->editor;
    $focus = $editor?->focus ?? ArenaSetupEditor::TROOPS;

    return match ($focus) {
      ArenaSetupEditor::MEMBER => $this->describeMember(),
      ArenaSetupEditor::CHOOSER => $this->describeChoices(),
      ArenaSetupEditor::COMPOSER => $this->describeComposer(),
      default => new BattleTestMenuList('Troop', $this->listTroopRows(),
        $focus === ArenaSetupEditor::TROOPS ? ($editor?->troopIndex ?? 0) : null,
        $focus === ArenaSetupEditor::PARTY ? [['confirm', 'Edit'], ['cancel', 'Back']]
          : [['confirm', ($editor?->onComposeRow ?? false) ? 'Compose' : 'Fight'], ...(($editor?->offersArenas ?? false) ? [['left', 'Arena']] : []),
            ['down', 'Party'], ['cancel', 'Quit']],
        $focus === ArenaSetupEditor::TROOPS
          ? ($editor?->offersArenas ?? false ? 'enter:Fight  left/right:Arena  down:Party  q:Quit' : 'enter:Fight  down:Party  q:Quit') : '',
        '%-24s %s', namesArena: true),
    };
  }

  /** @return list<array{0: string, 1: string}> The troop list's rows: each troop, then composing a test troop where one can be. */
  protected function listTroopRows(): array
  {
    $rows = array_map($this->describeTroop(...), $this->troops);
    if ($this->editor?->composer !== null) {
      $count = count($this->editor->composer->troop->members);
      $rows[] = [$count === 0 ? '+ Compose a test troop' : sprintf('+ Test troop (%d %s)', $count, $count === 1 ? 'enemy' : 'enemies'), ''];
    }

    return $rows === [] ? [[' This project has no troops to fight.', '']] : $rows;
  }

  /** The test troop composer: its rows, the enemies to add, or what can be done to a member. */
  protected function describeComposer(): BattleTestMenuList
  {
    $composer = $this->editor?->composer;
    if ($composer === null) {
      return new BattleTestMenuList('Test Troop', [], null);
    }
    $arenas = $this->editor?->offersArenas ?? false;
    $member = $composer->memberIndex === null ? null : $composer->troop->members[$composer->memberIndex] ?? null;

    return match ($composer->focus) {
      TestTroopComposer::ENEMIES => new BattleTestMenuList('Add an enemy', array_map(static fn(string $enemy): array => [$enemy, ''], $composer->enemies),
        $composer->enemyIndex, [['confirm', 'Add'], ['cancel', 'Back']], 'enter:Add  esc:Back'),
      TestTroopComposer::MEMBER => new BattleTestMenuList(sprintf('%d %s', $composer->memberIndex + 1, $member['enemy'] ?? ''),
        array_map(static fn(string $action): array => [$action === 'move' ? 'Move on the stage' : 'Remove from the troop', ''], $composer->actions),
        $composer->actionIndex, [['confirm', 'Choose'], ['cancel', 'Back']], 'enter:Choose  esc:Back'),
      TestTroopComposer::PLACING => new BattleTestMenuList(sprintf('Moving %d %s', $composer->memberIndex + 1, $member['enemy'] ?? ''), [], null,
        [['left', 'Across'], ['up', 'Back and forward'], ['confirm', 'Done'], ['cancel', 'Put back']], 'arrows:Move  enter:Done  esc:Put back'),
      default => new BattleTestMenuList('Test Troop',
        array_map(fn(string $row): array => [$this->describeComposerRow($composer, $row), ''], $composer->rows),
        $composer->rowIndex, [['confirm', 'Choose'], ...($arenas ? [['left', 'Arena']] : []), ['cancel', 'Back']],
        'enter:Choose  esc:Back' . ($arenas ? '  left/right:Arena' : ''), note: $this->keepStatus, namesArena: true),
    };
  }

  /** One of the composer's rows, as a list shows it. */
  public function describeComposerRow(TestTroopComposer $composer, string $row): string
  {
    if (str_starts_with($row, 'member:')) {
      $index = (int) substr($row, 7);

      return sprintf('%d %s', $index + 1, $composer->troop->members[$index]['enemy'] ?? '');
    }

    return match ($row) {
      'add' => '+ Add an enemy',
      'fight' => 'Fight',
      'keep' => 'Keep as a troop',
      default => $row,
    };
  }

  /**
   * The battle test as a graphical renderer draws it, in the game's menu
   * theme ({@see BattleTestMenuPresentation}); none in the terminal, or
   * without a theme, where the arena's windows are drawn.
   */
  public function getPresentationCanvas(): ?PresentationCanvas
  {
    $runtime = $this->getGame()->getRendererRuntime();
    $editor = $this->editor;
    if ($runtime === null || $editor === null) {
      return null;
    }
    try {
      if (! $this->menuThemeLoaded) {
        $this->menuThemeLoaded = true;
        $theme = MenuPresentationCatalog::load($runtime->getAssetRoot());
        foreach ($theme === null ? [] : MenuPresentationCatalog::CAPABILITIES as $capability) {
          if (! $runtime->supports($capability)) {
            throw new \RuntimeException("The renderer lacks {$capability} for the battle test menu.");
          }
        }
        $this->menuTheme = $theme;
      }
      if ($this->menuTheme === null) {
        return null;
      }
      $focus = $editor->focus;
      $composer = $editor->composer;
      $composing = $focus === ArenaSetupEditor::COMPOSER || $editor->onComposeRow;
      $outlined = $focus === ArenaSetupEditor::COMPOSER ? $composer?->memberIndex : null;
      ['stage' => $stage, 'issue' => $issue] = $this->composeStage($runtime->supports(RendererSessionConfig::SPRITE_SOURCE_RECT),
        $runtime->getAssetRoot(), $composing);

      return BattleTestMenuPresentation::compose($this->menuTheme, $this->describeList(),
        $focus !== ArenaSetupEditor::PARTY, $this->listPartyStats(),
        in_array($focus, [ArenaSetupEditor::PARTY, ArenaSetupEditor::MEMBER, ArenaSetupEditor::CHOOSER], true) ? $editor->memberIndex : null,
        $this->getArenaName(), $stage, $outlined, $composer?->focus === TestTroopComposer::PLACING, $issue);
    } catch (Throwable $error) {
      if ($this->presentationError !== $error->getMessage()) {
        Debug::error('The battle test menu is drawn in the terminal\'s windows: ' . $error->getMessage());
        $this->presentationError = $error->getMessage();
      }

      return null;
    }
  }

  /**
   * The stage the menu shows: the battle's own picture at rest of the troop
   * under the cursor, or the test troop while it is composed, on the chosen
   * arena with the party standing beside it; kept while none of that
   * changes. None, with why, when nothing graphical draws it.
   *
   * @return array{stage: ?BattleFormationLayout, issue: ?string}
   */
  private function composeStage(bool $crops, string $assetRoot, bool $composing): array
  {
    $catalog = $this->battleCatalog;
    $editor = $this->editor;
    if ($catalog?->ui === null || $editor === null || ! $crops) {
      return ['stage' => null, 'issue' => $catalog?->ui === null ? null : 'The renderer cannot crop battle art, so the stage is not drawn.'];
    }
    $enemies = [];
    if ($composing && $editor->composer !== null) {
      foreach ($editor->composer->troop->members as $member) {
        $enemies[] = ['enemyId' => $member['enemy'], 'slot' => $member['place']];
      }
    } else {
      foreach ($this->troopData[$editor->troopIndex]['enemies'] ?? [] as $index => $member) {
        $place = null;
        if (is_array($member['graphicalPlacement'] ?? null)) {
          try {
            $place = BattleStageSlot::fromArray($member['graphicalPlacement'], "troop member {$index}");
          } catch (\InvalidArgumentException) {
            $place = null;
          }
        }
        $enemies[] = ['enemyId' => strval($member['enemy'] ?? ''), 'slot' => $place];
      }
    }
    $party = array_map(static fn(BattleTestMember $member): string => $member->actorId, $editor->setup->members);
    $party = $catalog->ui->partySlots === [] ? $party : array_slice($party, 0, count($catalog->ui->partySlots));
    $key = serialize([$enemies, $party, $editor->setup->arena]);
    if (($this->stage['key'] ?? null) !== $key) {
      try {
        $stage = BattleFormationLayout::compose($catalog, $editor->setup->arena, array_values(array_filter($enemies,
          static fn(array $enemy): bool => $enemy['enemyId'] !== '')), $party, $assetRoot);
        $this->stage = ['key' => $key, 'stage' => $stage, 'issue' => null];
      } catch (Throwable $error) {
        $this->stage = ['key' => $key, 'stage' => null, 'issue' => 'The stage cannot be drawn: ' . $error->getMessage()];
      }
    }

    return ['stage' => $this->stage['stage'], 'issue' => $this->stage['issue']];
  }

  /** The arena fights take place in, by name, where arenas are offered; none in the terminal. */
  public function getArenaName(): ?string
  {
    if ($this->editor === null || ! $this->editor->offersArenas) {
      return null;
    }
    $arena = $this->editor->setup->arena;

    return $arena === null
      ? ($this->defaultArenaName === null ? 'Default' : $this->defaultArenaName . ' (default)')
      : ($this->arenaChoices[$arena] ?? $arena);
  }

  /**
   * Fits a list into a panel: the selected row highlighted and kept in
   * view, each list keeping its own scroll position.
   *
   * @param list<string> $rows The rows.
   * @param int|null $selected The selected row, or null.
   * @param string $list Which list it is, for its scroll position.
   * @return list<string> The visible rows, padded to the panel's height.
   */
  protected function formatList(array $rows, ?int $selected, int $width, int $visibleRows, string $list): array
  {
    $offset = min($this->scrollOffsets[$list] ?? 0, max(0, count($rows) - $visibleRows));
    if ($selected !== null && $selected < $offset) {
      $offset = $selected;
    } elseif ($selected !== null && $selected >= $offset + $visibleRows) {
      $offset = $selected - $visibleRows + 1;
    }
    $this->scrollOffsets[$list] = $offset;
    $content = [];
    foreach (array_slice($rows, $offset, $visibleRows, true) as $index => $row) {
      $line = TerminalText::padRight(sprintf(' %s %s', $index === $selected ? '>' : ' ', $row), $width);
      $content[] = $index === $selected ? SelectionStyle::apply($line) : $line;
    }

    return array_pad($content, $visibleRows, '');
  }

  /**
   * Describes a troop for the list: its name, and how many enemies of what levels.
   *
   * @return array{0: string, 1: string}
   */
  protected function describeTroop(Troop $troop): array
  {
    $members = $troop->members->toArray();
    $levels = array_map(static fn(object $enemy): int => $enemy->level ?? 1, $members);

    return [$troop->name, sprintf(
      '%d %s, %s %s',
      count($members),
      count($members) === 1 ? 'enemy' : 'enemies',
      Vocabulary::getTerm('stats.level', 'level'),
      $levels === [] ? '?' : (min($levels) === max($levels) ? min($levels) : min($levels) . '-' . max($levels))
    )];
  }

  /** The member being edited: its actor, level, equipment, test loadout and removal. */
  protected function describeMember(): BattleTestMenuList
  {
    $editor = $this->editor;
    $member = $editor?->member;
    if ($editor === null || $member === null) {
      return new BattleTestMenuList('Member', [], null);
    }
    $items = ConfigStore::get(ItemStore::class);
    $rows = [];
    foreach ($editor->fields as $field) {
      $rows[] = match (true) {
        $field === 'actor' => ['Actor', sprintf('< %s >', $this->getActorName($member->actorId))],
        $field === 'level' => ['Level', sprintf('< %d >', $member->level)],
        $field === 'remove' => ['Remove from the party', ''],
        $field === 'commands' => ['Commands', $member->commands === null ? '(Normal)' : implode(', ', array_column($member->commands, 'value'))],
        $field === 'skills' || $field === 'magic' => [ucfirst($field), 'Choose extra learned actions'],
        $field === 'summons' => ['Summons', $member->summons === [] ? '(No extra grants)' : implode(', ', $member->summons)],
        default => [substr($field, 5),
          ($id = $member->equipment[substr($field, 5)] ?? null) === null ? '(None)' : $items->displayNameFor($id)],
      };
    }

    return new BattleTestMenuList($this->getActorName($member->actorId), $rows, $editor->fieldIndex,
      [['left', 'Change'], ['confirm', 'Choose'], ['cancel', 'Done']], 'left/right:Change  enter:Choose  esc:Done', '%-12s%s');
  }

  /** What the selected slot can hold, or the test grants a loadout field can be given. */
  protected function describeChoices(): BattleTestMenuList
  {
    $editor = $this->editor;
    $field = $editor?->fields[$editor->fieldIndex] ?? '';
    $equipment = str_starts_with($field, 'slot:');
    $choices = $editor?->getChoices() ?? [];
    $rows = array_map(static fn($choice): array => [$equipment ? $choice['name']
      : (($editor?->isChoiceSelected($choice['id']) ? '[x] ' : '[ ] ') . $choice['name']), ''], $choices);
    return new BattleTestMenuList(sprintf('%s for %s', $equipment ? substr($field, 5) : ucfirst($field), $this->getActorName($editor?->member?->actorId ?? '')),
      $rows, $editor?->choiceIndex, $equipment ? [['confirm', 'Equip'], ['cancel', 'Back']] : [['confirm', 'Toggle'], ['cancel', 'Done']],
      $equipment ? 'enter:Equip  esc:Back' : 'enter:Toggle  esc:Done  test grants only');
  }

  protected function getActorName(string $actorId): string
  {
    return ConfigStore::get(ActorStore::class)->get($actorId)?->data()['name'] ?? $actorId;
  }

  /**
   * Describes the party for the info panel, an empty row for each place
   * the party has left.
   *
   * @param int $width The panel's inner width.
   * @return string[] The rows.
   */
  protected function describeParty(int $width): array
  {
    $members = $this->listPartyStats();

    if ($members === []) {
      return [' No party could be built from this project.'];
    }
    $rows = self::formatPartyRows($members, $width);
    while (count($rows) < BattleTestSetup::MAX_MEMBERS) {
      $rows[] = TerminalText::padRight(' (empty)', $width);
    }
    $editor = $this->editor;
    if ($editor !== null && $editor->focus !== ArenaSetupEditor::TROOPS && isset($rows[$editor->memberIndex])) {
      $rows[$editor->memberIndex] = SelectionStyle::apply($rows[$editor->memberIndex]);
    }

    return $rows;
  }

  /**
   * The party the setup describes, as every presentation shows it: each
   * member's name and its level, HP and MP, figures by their label.
   *
   * @return list<array{0: string, 1: array<string, string>}>
   */
  public function listPartyStats(): array
  {
    $members = [];
    foreach ($this->previewParty?->members?->toArray() ?? [] as $character) {
      $members[] = [$character->name, [
        Vocabulary::getTerm('stats.level_short', Vocabulary::getTerm('stats.level', 'Lv')) => (string) $character->level,
        Vocabulary::getTerm('stats.hp', 'HP') => "{$character->stats->currentHp}/{$character->stats->totalHp}",
        Vocabulary::getTerm('stats.mp', 'MP') => "{$character->stats->currentMp}/{$character->stats->totalMp}",
      ]];
    }

    return $members;
  }

  /**
   * Lays the party out in justified rows: each name on the left, and the
   * stats on the right, ending at the panel's edge. Every stat keeps its
   * label in place and right-aligns its figure to the widest of its
   * column, so the figures line up from row to row.
   *
   * @param list<array{0: string, 1: array<string, string>}> $members Each member's name and stats, figures by label.
   * @param int $width The panel's inner width.
   * @return list<string> The rows, each exactly the width.
   */
  public static function formatPartyRows(array $members, int $width): array
  {
    $widths = [];
    foreach ($members as [, $stats]) {
      foreach ($stats as $label => $figure) {
        $widths[$label] = max($widths[$label] ?? 0, TerminalText::displayWidth($figure));
      }
    }

    $rows = [];
    foreach ($members as [$name, $stats]) {
      $columns = [];
      foreach ($stats as $label => $figure) {
        $columns[] = $label . ' ' . TerminalText::padLeft($figure, $widths[$label]);
      }
      $right = implode('  ', $columns) . ' ';
      $rows[] = TerminalText::padRight(' ' . $name, max(TerminalText::displayWidth(' ' . $name) + 1,
        $width - TerminalText::displayWidth($right))) . $right;
    }

    return $rows;
  }
}
