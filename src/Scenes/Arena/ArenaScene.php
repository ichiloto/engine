<?php

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ActorStore;
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
 *
 * @package Ichiloto\Engine\Scenes\Arena
 */
class ArenaScene extends AbstractScene
{
  protected const int PANEL_WIDTH = 60;
  protected const int LIST_HEIGHT = 18;
  protected const int INFO_HEIGHT = BattleTestSetup::MAX_MEMBERS + 2;
  /** The setup option a caller may start the arena with, a {@see BattleTestSetup}. */
  public const string SETUP_OPTION = 'arena_setup';

  /**
   * @var list<array<string, mixed>> Each troop's data, built into a fresh troop for every fight.
   */
  protected array $troopData = [];
  /**
   * @var Troop[] The troops, built once for the list.
   */
  protected array $troops = [];
  protected ?ArenaSetupEditor $editor = null;
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

    // Booted with a troop named: skip the list and fight it.
    $wanted = strval($this->getGame()->options['arena_troop'] ?? '');

    if ($wanted !== '') {
      $this->fightByName($wanted);
    }
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
      );
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not start a battle: %s', $exception->getMessage()));
    }
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
   * project's starting party.
   *
   * @return ArenaSetupEditor|null Null when no party can be built.
   */
  protected function createEditor(): ?ArenaSetupEditor
  {
    try {
      $actors = ConfigStore::get(ActorStore::class);
      $setup = $this->getGame()->options[self::SETUP_OPTION] ?? null;
      $setup = $setup instanceof BattleTestSetup
        ? $setup
        : BattleTestSetup::getFromParty(GameLoader::getInstance($this->getGame())->loadNewGame()->party);

      return new ArenaSetupEditor(
        $setup,
        count($this->troops),
        $actors->getActorIds(),
        fn(string $actorId): array => array_map(static fn($slot): string => $slot->name, $this->createProbe($actorId)->equipment),
        fn(string $actorId, string $slotName): array => $this->getEquipmentChoices($actorId, $slotName),
        fn(string $actorId): int => $this->createProbe($actorId)->maxLevel,
      );
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not set up a party: %s', $exception->getMessage()));

      return null;
    }
  }

  /** A character of the actor as authored, to read its slots and limits. */
  protected function createProbe(string $actorId): Character
  {
    return ConfigStore::get(ActorStore::class)->require($actorId, 'the battle test setup')->createCharacter();
  }

  /**
   * What an actor can wear in a slot: nothing, then every equipment item
   * the slot accepts and the actor can equip, in authored order.
   *
   * @return list<array{id: ?string, name: string}>
   */
  protected function getEquipmentChoices(string $actorId, string $slotName): array
  {
    $character = $this->createProbe($actorId);
    $slot = array_find($character->equipment, static fn($slot): bool => $slot->name === $slotName);
    $items = ConfigStore::get(ItemStore::class);
    $choices = [['id' => null, 'name' => '(None)']];

    foreach ($slot === null ? [] : $items->getItemIds() as $id) {
      $item = $items->get($id);
      if ($item instanceof Equipment && $slot->acceptsType === $item::class && $slot->semanticSlot === $item->semanticSlot
        && $character->canEquip($item)) {
        $choices[] = ['id' => $id, 'name' => $item->name];
      }
    }

    return $choices;
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
    $editor = $this->editor;
    $focus = $editor?->focus ?? ArenaSetupEditor::TROOPS;

    [$title, $help, $rows, $selected] = match ($focus) {
      ArenaSetupEditor::MEMBER => $this->describeMember($innerWidth),
      ArenaSetupEditor::CHOOSER => $this->describeChoices(),
      default => ['Troop', $focus === ArenaSetupEditor::TROOPS ? 'enter:Fight  down:Party  q:Quit' : '',
        $this->troops === [] ? [' This project has no troops to fight.'] : array_map($this->describeTroop(...), $this->troops),
        $focus === ArenaSetupEditor::TROOPS ? ($editor?->troopIndex ?? 0) : null],
    };
    $this->listPanel?->setTitle($title);
    $this->listPanel?->setHelp($help);
    $this->listPanel?->setContent($this->formatList($rows, $selected, $innerWidth, self::LIST_HEIGHT - 2,
      $focus === ArenaSetupEditor::PARTY ? ArenaSetupEditor::TROOPS : $focus));
    $this->listPanel?->render();

    $this->infoPanel?->setHelp($focus === ArenaSetupEditor::PARTY ? 'enter:Edit  esc:Back' : '');
    $this->infoPanel?->setContent($this->describeParty($innerWidth));
    $this->infoPanel?->render();
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
   * Describes a troop for the list.
   *
   * @param Troop $troop The troop.
   * @return string The description.
   */
  protected function describeTroop(Troop $troop): string
  {
    $members = $troop->members->toArray();
    $levels = array_map(static fn(object $enemy): int => $enemy->level ?? 1, $members);

    return sprintf(
      '%-24s %d %s, level %s',
      $troop->name,
      count($members),
      count($members) === 1 ? 'enemy' : 'enemies',
      $levels === [] ? '?' : (min($levels) === max($levels) ? min($levels) : min($levels) . '-' . max($levels))
    );
  }

  /**
   * The member being edited: its actor, level, equipment and removal.
   *
   * @return array{0: string, 1: string, 2: list<string>, 3: int|null}
   */
  protected function describeMember(int $width): array
  {
    $editor = $this->editor;
    $member = $editor?->member;
    if ($editor === null || $member === null) {
      return ['Member', '', [], null];
    }
    $items = ConfigStore::get(ItemStore::class);
    $rows = [];
    foreach ($editor->fields as $field) {
      $rows[] = match (true) {
        $field === 'actor' => sprintf('%-12s< %s >', 'Actor', $this->getActorName($member->actorId)),
        $field === 'level' => sprintf('%-12s< %d >', 'Level', $member->level),
        $field === 'remove' => 'Remove from the party',
        default => sprintf('%-12s%s', substr($field, 5),
          ($id = $member->equipment[substr($field, 5)] ?? null) === null ? '(None)' : $items->displayNameFor($id)),
      };
    }

    return [$this->getActorName($member->actorId), 'left/right:Change  enter:Choose  esc:Done', $rows, $editor->fieldIndex];
  }

  /**
   * What the selected slot can hold.
   *
   * @return array{0: string, 1: string, 2: list<string>, 3: int|null}
   */
  protected function describeChoices(): array
  {
    $editor = $this->editor;
    $field = $editor?->fields[$editor->fieldIndex] ?? '';

    return [sprintf('%s for %s', substr($field, 5), $this->getActorName($editor?->member?->actorId ?? '')),
      'enter:Equip  esc:Back', array_column($editor?->getChoices() ?? [], 'name'), $editor?->choiceIndex];
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
    $members = [];

    foreach ($this->previewParty?->members?->toArray() ?? [] as $character) {
      $members[] = [$character->name, [
        'Lv' => (string) $character->level,
        'HP' => "{$character->stats->currentHp}/{$character->stats->totalHp}",
        'MP' => "{$character->stats->currentMp}/{$character->stats->totalMp}",
      ]];
    }

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
