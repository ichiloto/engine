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
  protected const int STATS_SUMMARY_PANEL_HEIGHT = 31 - self::PROFILE_SUMMARY_PANEL_HEIGHT;
  protected const int EQUIPMENT_SUMMARY_PANEL_WIDTH = 70;
  protected const int EQUIPMENT_SUMMARY_PANEL_HEIGHT = self::STATS_SUMMARY_PANEL_HEIGHT;
  protected const int INFO_PANEL_WIDTH = 110;
  protected const int INFO_PANEL_HEIGHT = 35 - (self::PROFILE_SUMMARY_PANEL_HEIGHT + self::STATS_SUMMARY_PANEL_HEIGHT);
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
   * @var Window|null The info panel for the status view.
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
      'Info',
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

    $this->statsSummaryPanel->setContent(array_pad([
      sprintf(" %s:%27s", Vocabulary::getTerm('stats.attack', 'Attack'), $this->character?->effectiveStats->attack),
      sprintf(" %s:%26s", Vocabulary::getTerm('stats.defence', 'Defence'), $this->character?->effectiveStats->defence),
      sprintf(" %s:%25s", Vocabulary::getTerm('stats.magicAttack', 'M.Attack'), $this->character?->effectiveStats->magicAttack),
      sprintf(" %s:%24s", Vocabulary::getTerm('stats.magicDefence', 'M.Defence'), $this->character?->effectiveStats->magicDefence),
      sprintf(" %s:%26s", Vocabulary::getTerm('stats.evasion', 'Evasion'), $this->character?->effectiveStats->evasion),
      sprintf(" %s:%28s", Vocabulary::getTerm('stats.speed', 'Speed'), $this->character?->effectiveStats->speed),
      sprintf(" %s:%28s", Vocabulary::getTerm('stats.grace', 'Grace'), $this->character?->effectiveStats->grace),
    ], self::STATS_SUMMARY_PANEL_HEIGHT - 2, ''));

    $this->equipmentSummaryPanel->setContent(array_pad(
      array_map(function(EquipmentSlot $slot) {
        $equipmentName = '';
        if ($slot->equipment) {
          $equipmentName = $slot->equipment->name;
        }
        $type = $this->character === null ? $slot->semanticSlot : CharacterMenuRows::getSlotIcon($this->character, $slot);
        $icon = TerminalText::padRight(EquipmentIcon::getTerminalGlyph($type), 2);
        return '  ' . TerminalText::padRight("{$icon} {$slot->name}:", 20) . ' ' . $equipmentName;
      }, $this->character?->equipment ?? []),
      self::EQUIPMENT_SUMMARY_PANEL_HEIGHT - 2,
      ''));

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

    return array_pad([
      sprintf(' %s', $this->character?->name ?? 'N/A'),
      sprintf("%19s Role:%12s", ' ', $this->character?->role->name ?? 'N/A'),
      sprintf(
        "%19s %s:%12s       %-14s %20d",
        ' ',
        Vocabulary::getTerm('stats.level', 'Lv'),
        $this->character?->level ?? 1,
        get_message('exp_total', Vocabulary::getTerm('shop.exp_total', 'Current %1'), Vocabulary::getTerm('stats.exp', 'EXP')) . ':', $this->character?->currentExp ?? 0,
      ),
      sprintf("%42s%-14s %20d",' ', get_message('exp_next', 'To Next %1', Vocabulary::getTerm('stats.level', 'Level')) . ':', $this->character?->nextLevelExp ?? 0),
      sprintf("%19s %s:%12s", ' ', Vocabulary::getTerm('stats.hp', 'HP'), "{$currentHp} / {$totalHp}"),
      sprintf("%19s %s:%12s", ' ', Vocabulary::getTerm('stats.mp', 'MP'), "{$currentMp} / {$totalMp}"),
      sprintf("%19s %s:%12s", ' ', Vocabulary::getTerm('stats.ap', 'AP'), "{$currentAp} / {$totalAp}"),
    ], self::PROFILE_SUMMARY_PANEL_HEIGHT - 2, '');
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
