<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

class StatusViewState extends GameSceneState
{
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
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeUI();
  }

  public function exit(): void
  {
    // Do nothing
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
    $this->leftMargin = (get_screen_width() - self::PROFILE_SUMMARY_PANEL_WIDTH) / 2;
    $this->topMargin = 0;
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
      'Status',
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
      'esc:Back',
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
    $currentHp = $this->character?->effectiveStats->currentHp ?? 0;
    $currentMp = $this->character?->effectiveStats->currentMp ?? 0;
    $currentAp = $this->character?->effectiveStats->currentAp ?? 0;
    $totalHp = $this->character?->effectiveStats->totalHp ?? 0;
    $totalMp = $this->character?->effectiveStats->totalMp ?? 0;
    $totalAp = $this->character?->effectiveStats->totalAp ?? 0;

    $this->profileSummaryPanel->setContent(array_pad([
      " {$this->character?->name}" ?? 'N/A',
      sprintf(
        "%19s Lv:%12s       %-14s %20d",
        ' ',
        $this->character?->level ?? 1,
        'Current EXP:', $this->character?->currentExp ?? 0,
      ),
      sprintf("%42s%-14s %20d",' ', 'To Next Level:', $this->character?->nextLevelExp ?? 0),
      sprintf("%19s HP:%12s", ' ', "{$currentHp} / {$totalHp}"),
      sprintf("%19s MP:%12s", ' ', "{$currentMp} / {$totalMp}"),
      sprintf("%19s AP:%12s", ' ', "{$currentAp} / {$totalAp}"),
    ], self::PROFILE_SUMMARY_PANEL_HEIGHT - 2, ''));

    $this->statsSummaryPanel->setContent(array_pad([
      sprintf(" Attack:%27s", $this->character?->effectiveStats->attack),
      sprintf(" Defence:%26s", $this->character?->effectiveStats->defence),
      sprintf(" M.Attack:%25s", $this->character?->effectiveStats->magicAttack),
      sprintf(" M.Defence:%24s", $this->character?->effectiveStats->magicDefence),
      sprintf(" Evasion:%26s", $this->character?->effectiveStats->evasion),
      sprintf(" Speed:%28s", $this->character?->effectiveStats->speed),
      sprintf(" Grace:%28s", $this->character?->effectiveStats->grace),
    ], self::STATS_SUMMARY_PANEL_HEIGHT - 2, ''));

    $this->equipmentSummaryPanel->setContent(array_pad(
      array_map(fn(EquipmentSlot $slot) => sprintf("  %-20s %s", "{$slot->name}:", $slot->equipment?->name ?? ''), $this->character?->equipment ?? []),
      self::EQUIPMENT_SUMMARY_PANEL_HEIGHT - 2,
      ''));

    $this->renderUI();
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
    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($h) > 0) {
      if ($h > 0) {
        $this->selectNextCharacter();
      } else {
        $this->selectPreviousCharacter();
      }
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