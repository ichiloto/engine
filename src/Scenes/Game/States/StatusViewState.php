<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Localization\Vocabulary;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\UI\Presentation\CharacterMenuRows;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\UI\Text\TerminalConditions;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\CharacterMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvasState;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/**
 * Displays one party member's full profile and allows cycling between members.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class StatusViewState extends GameSceneState implements CanvasProviderInterface
{
  use MenuCanvasState;

  protected function composeMenuCanvas(MenuPresentationCatalog $theme, float $time): ?PresentationCanvas
  {
    return $this->character === null ? null : CharacterMenuPresentation::status($this->character, $theme, $time);
  }
  protected const int PROFILE_SUMMARY_PANEL_WIDTH = 110;
  protected const int PROFILE_SUMMARY_PANEL_HEIGHT = 9;
  protected const int STATS_SUMMARY_PANEL_WIDTH = 40;
  protected const int CONDITIONS_PANEL_HEIGHT = 8;
  protected const int STATS_SUMMARY_PANEL_HEIGHT = 35 - self::PROFILE_SUMMARY_PANEL_HEIGHT - self::CONDITIONS_PANEL_HEIGHT;
  protected const int EQUIPMENT_SUMMARY_PANEL_WIDTH = 70;
  protected const int EQUIPMENT_SUMMARY_PANEL_HEIGHT = self::STATS_SUMMARY_PANEL_HEIGHT;
  protected const int INFO_PANEL_WIDTH = 110;
  protected const int INFO_PANEL_HEIGHT = self::CONDITIONS_PANEL_HEIGHT;
  /** Terminal field columns: stats fill their panel; resources sit beside the name, experience to their right. */
  protected const int STATS_FIELD_WIDTH = 35;
  protected const int PROFILE_FIELD_INDENT = 20;
  protected const int PROFILE_FIELD_WIDTH = 37;
  protected const int EXPERIENCE_FIELD_GAP = 5;
  protected const int EXPERIENCE_FIELD_WIDTH = 35;
  /**
   * @var int The left margin of the status view.
   */
  protected int $leftMargin = 0;
  /**
   * @var int The top margin of the status view.
   */
  protected int $topMargin = 0;
  /**
   * @var BorderPackInterface|null The border pack for the status view.
   */
  protected ?BorderPackInterface $borderPack = null;
  /**
   * @var Window|null The profile panel for the status view.
   */
  protected ?Window $profileSummaryPanel = null;
  /**
   * @var Window|null The stats panel for the status view.
   */
  protected ?Window $statsSummaryPanel = null;
  /**
   * @var Window|null The equipment panel for the status view.
   */
  protected ?Window $equipmentSummaryPanel = null;
  /**
   * @var Window|null The conditions panel for the status view: the character's current states.
   */
  protected ?Window $infoPanel = null;
  /**
   * @var Character|null The character to display the status of.
   */
  public ?Character $character = null;
  /**
   * @var ItemList|null The list of characters to display the status of.
   */
  protected ?ItemList $characters = null;
  /**
   * @var int The current character index if found, otherwise -1.
   */
  protected int $currentCharacterIndex {
    get {
      return $this->getGameScene()->party->members->findIndex(fn(Character $character) => $character === $this->character);
    }
  }

  public function enter(): void
  {
    $this->resetMenuPresentation();
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->character ??= $this->getGameScene()->party->leader;
    $this->calculateMargins();
    $this->initializeUI();
  }

  public function exit(): void
  {
    if (isset($this->getGameScene()->mainMenuState)) {
      $this->getGameScene()->mainMenuState->rememberPartyPresentationCharacter($this->character);
    }
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $this->handleActions();
    $this->handleNavigation();
  }

  /**
   * Calculate the margins for the status view.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::PROFILE_SUMMARY_PANEL_WIDTH, 2));
    $this->topMargin = max(0, intdiv(
      get_screen_height() - (self::PROFILE_SUMMARY_PANEL_HEIGHT + self::STATS_SUMMARY_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT),
      2
    ));
  }

  /**
   * Initialize the UI for the status view.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();

    $this->profileSummaryPanel = new Window(
      Vocabulary::getTerm('command.status', 'Status'),
      '',
      new Vector2($this->leftMargin, $this->topMargin),
      self::PROFILE_SUMMARY_PANEL_WIDTH,
      self::PROFILE_SUMMARY_PANEL_HEIGHT,
      $this->borderPack
    );
    $this->statsSummaryPanel = new Window(
      '',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::PROFILE_SUMMARY_PANEL_HEIGHT),
      self::STATS_SUMMARY_PANEL_WIDTH,
      self::STATS_SUMMARY_PANEL_HEIGHT,
      $this->borderPack
    );
    $this->equipmentSummaryPanel = new Window(
      '',
      '',
      new Vector2($this->leftMargin + self::STATS_SUMMARY_PANEL_WIDTH, $this->topMargin + self::PROFILE_SUMMARY_PANEL_HEIGHT),
      self::EQUIPMENT_SUMMARY_PANEL_WIDTH,
      self::EQUIPMENT_SUMMARY_PANEL_HEIGHT,
      $this->borderPack
    );
    $this->infoPanel = new Window(
      get_engine_message('status.conditions'),
      'tab:Next shift+Tab:Prev esc:Back',
      new Vector2($this->leftMargin, $this->topMargin + self::PROFILE_SUMMARY_PANEL_HEIGHT + self::STATS_SUMMARY_PANEL_HEIGHT),
      self::INFO_PANEL_WIDTH,
      self::INFO_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->updateContent();
  }

  /**
   * Update the content of the status view.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $this->profileSummaryPanel->setContent($this->buildProfileSummaryContent());

    $stats = $this->character?->effectiveStats;
    $fields = [];
    foreach (['attack' => 'Attack', 'defence' => 'Defence', 'magicAttack' => 'M.Attack', 'magicDefence' => 'M.Defence',
      'evasion' => 'Evasion', 'speed' => 'Speed', 'grace' => 'Grace'] as $stat => $fallback) {
      $fields[Vocabulary::getTerm('stats.' . $stat, $fallback)] = (string)($stats?->$stat ?? '');
    }
    $this->statsSummaryPanel->setContent(array_pad(self::composeFields($fields, self::STATS_FIELD_WIDTH, ' '),
      self::STATS_SUMMARY_PANEL_HEIGHT - 2, ''));

    $this->equipmentSummaryPanel->setContent(array_pad(
      array_map(function(EquipmentSlot $slot) {
        $equipmentName = '';
        if ($slot->equipment) {
          $equipmentName = $slot->equipment->name;
        }
        $type = $this->character === null ? $slot->semanticSlot : CharacterMenuRows::getSlotIcon($this->character, $slot);
        $label = TerminalMenuRow::composeIconLabel(EquipmentIcon::getTerminalGlyph($type),
          $slot->name, 2);
        $width = $this->equipmentSummaryPanel->getContentWidth();
        return TerminalMenuRow::compose($label, $equipmentName, $width, prefix: '  ',
          valueAlignment: 'left', labelWidth: 20);
      }, $this->character?->equipment ?? []),
      self::EQUIPMENT_SUMMARY_PANEL_HEIGHT - 2,
      ''));

    $this->infoPanel->setContent($this->buildConditionsContent());

    $this->renderUI();
  }

  /**
   * Builds the character identity and resource summary shown by the status view.
   *
   * @return string[] The profile summary rows.
   */
  protected function buildProfileSummaryContent(): array
  {
    $currentHp = $this->character?->effectiveStats->currentHp ?? 0;
    $currentMp = $this->character?->effectiveStats->currentMp ?? 0;
    $currentAp = $this->character?->effectiveStats->currentAp ?? 0;
    $totalHp = $this->character?->effectiveStats->totalHp ?? 0;
    $totalMp = $this->character?->effectiveStats->totalMp ?? 0;
    $totalAp = $this->character?->effectiveStats->totalAp ?? 0;

    $resources = self::composeFields([
      'Role' => $this->character?->role->name ?? 'N/A',
      Vocabulary::getTerm('stats.level_short') => (string)($this->character?->level ?? 1),
      Vocabulary::getTerm('stats.hp', 'HP') => "{$currentHp} / {$totalHp}",
      Vocabulary::getTerm('stats.mp', 'MP') => "{$currentMp} / {$totalMp}",
      Vocabulary::getTerm('stats.ap', 'AP') => "{$currentAp} / {$totalAp}",
    ], self::PROFILE_FIELD_WIDTH, str_repeat(' ', self::PROFILE_FIELD_INDENT));
    $experience = self::composeFields([
      get_engine_message('exp_total', Vocabulary::getTerm('stats.exp', 'EXP')) => (string)($this->character?->currentExp ?? 0),
      get_engine_message('exp_next', Vocabulary::getTerm('stats.level')) => (string)($this->character?->nextLevelExp ?? 0),
    ], self::EXPERIENCE_FIELD_WIDTH);
    $gap = str_repeat(' ', self::EXPERIENCE_FIELD_GAP);

    return array_pad([
      sprintf(' %s', $this->character?->name ?? 'N/A'),
      $resources[0],
      $resources[1] . $gap . $experience[0],
      str_repeat(' ', self::PROFILE_FIELD_WIDTH) . $gap . $experience[1],
      ...array_slice($resources, 2),
    ], self::PROFILE_SUMMARY_PANEL_HEIGHT - 2, '');
  }

  /**
   * Composes label/value fields in the shared Terminal style, with one value column for the group.
   *
   * @param array<string, string> $fields Labels mapped to formatted values.
   * @return string[] One line per field.
   */
  private static function composeFields(array $fields, int $width, string $prefix = ''): array
  {
    $labelWidth = max(array_map(static fn($label): int => TerminalText::displayWidth((string)$label),
      array_keys($fields)));
    $lines = [];
    foreach ($fields as $label => $value) {
      $lines[] = TerminalMenuRow::composeField((string)$label, $value, $width, $prefix, 'right', $labelWidth);
    }
    return $lines;
  }

  /**
   * Builds the character's current conditions: each with its badge, name and
   * description, or only badges and names when the descriptions do not fit.
   *
   * @return string[] The conditions rows.
   */
  protected function buildConditionsContent(): array
  {
    $rows = self::INFO_PANEL_HEIGHT - 2;
    $width = $this->infoPanel->getContentWidth() - 2;

    if ($this->character === null || BattlerConditions::getEntries($this->character) === []) {
      return array_pad([' ' . get_engine_message('status.no_conditions')], $rows, '');
    }

    $lines = TerminalConditions::composeLines($this->character, $width);

    if (count($lines) > $rows) {
      $names = array_map(static fn(array $entry): string => TerminalMenuRow::composeIconLabel($entry['badge'], $entry['name']),
        BattlerConditions::getEntries($this->character));
      $lines = TerminalText::wrapToWidth(implode('   ', $names), $width);
    }

    return array_pad(array_map(static fn(string $line): string => ' ' . $line, array_slice($lines, 0, $rows)), $rows, '');
  }

  /**
   * Render the UI for the status view.
   *
   * @return void
   */
  protected function renderUI(): void
  {
    $this->profileSummaryPanel->render();
    $this->statsSummaryPanel->render();
    $this->equipmentSummaryPanel->render();
    $this->infoPanel->render();
  }

  /**
   * Handle view navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    if ($this->isNextCharacterRequested()) {
      play_sound(SystemSound::CURSOR);
      $this->selectNextCharacter();
      return;
    }

    if ($this->isPreviousCharacterRequested()) {
      play_sound(SystemSound::CURSOR);
      $this->selectPreviousCharacter();
    }
  }

  /**
   * Handle the actions for the status view.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("back")) {
      play_sound(SystemSound::CANCEL);
      $this->setState($this->getGameScene()->mainMenuState);
    }
  }

  /**
   * Select the previous character.
   *
   * @return void
   */
  protected function selectPreviousCharacter(): void
  {
    $previousCharacterIndex = wrap($this->currentCharacterIndex - 1, 0, $this->getGameScene()->party->members->count() - 1);
    $this->character = $this->getGameScene()->party->members->toArray()[$previousCharacterIndex];
    $this->updateContent();
  }

  /**
   * Select the next character.
   *
   * @return void
   */
  protected function selectNextCharacter(): void
  {
    $previousCharacterIndex = wrap($this->currentCharacterIndex + 1, 0, $this->getGameScene()->party->members->count() - 1);
    $this->character = $this->getGameScene()->party->members->toArray()[$previousCharacterIndex];
    $this->updateContent();
  }
}
