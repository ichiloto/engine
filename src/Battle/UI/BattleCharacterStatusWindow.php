<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Elements\ProgressBar\ProgressBar;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the battle character status window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCharacterStatusWindow extends Window
{
  protected const int MAX_VISIBLE_CHARACTERS = self::HEIGHT - 2;
  protected const int HP_VALUE_WIDTH = 4;
  protected const int MP_VALUE_WIDTH = 4;
  protected const int HP_BAR_UNITS = 10;
  protected const int MP_BAR_UNITS = 5;
  protected const int COMPACT_HP_BAR_UNITS = 6;
  protected const int COMPACT_BAR_UNITS = 3;
  protected const int INTER_STAT_GAP = 2;
  protected const int COLUMN_GAP = 1;
  /**
   * The width of the window.
   */
  const int WIDTH = 35;
  /**
   * The height of the window.
   */
  const int HEIGHT = 6;
  /**
   * The visible content width inside the bordered status window.
   */
  protected const int CONTENT_WIDTH = self::WIDTH - 4;
  /**
   * @var Character[] The characters to display.
   */
  protected array $characters = [];
  private int $firstVisibleIndex = 0;
  /**
   * @var float[] The optional ATB fill percentages.
   */
  protected array $atbPercentages = [];

  /**
   * Creates a new instance of the battle character status window.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(
    protected BattleScreen $battleScreen,
    protected Camera $camera
  )
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft() +
      $this->battleScreen->commandWindow->width +
      $this->battleScreen->commandContextWindow->width +
      $this->battleScreen->characterNameWindow->width;
    $topMargin = $this->battleScreen->screenDimensions->getTop() +
      $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);
    parent::__construct(
      '',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
    $this->setTitle($this->formatHeaderLine(true));
  }

  /**
   * Sets the battlers to display.
   *
   * @param Character[] $characters The battlers to set.
   */
  public function setCharacters(array $characters): void
  {
    $this->characters = array_values($characters);
    $this->firstVisibleIndex = min($this->firstVisibleIndex,
      intdiv(max(0, count($characters) - 1), self::MAX_VISIBLE_CHARACTERS) * self::MAX_VISIBLE_CHARACTERS);
    $this->updateContent();
  }

  public function setFirstVisibleIndex(int $index): void
  {
    $this->firstVisibleIndex = max(0, $index);
    $this->updateContent();
  }

  /**
   * Sets optional ATB gauge percentages for the current battlers.
   *
   * @param float[] $atbPercentages The ATB percentages.
   * @return void
   */
  public function setAtbPercentages(array $atbPercentages): void
  {
    $this->atbPercentages = array_values($atbPercentages);
    $this->updateContent();
  }

  /**
   * Clears the ATB gauge display.
   *
   * @return void
   */
  public function clearAtbPercentages(): void
  {
    $this->atbPercentages = [];
    $this->updateContent();
  }

  /** Reads current effective vitals, not the last rendered progress-bar strings. */
  public function presentationSnapshot(): BattleHudStatusSnapshot
  {
    $rows = [];
    $showAtb = $this->usesAtbLayout();
    foreach (array_slice($this->characters, $this->firstVisibleIndex, self::MAX_VISIBLE_CHARACTERS, true) as $index => $character) {
      $rows[] = $this->snapshotRow($index, $character, $showAtb ? ($this->atbPercentages[$index] ?? 0.0) : null);
    }

    return new BattleHudStatusSnapshot($this->title, $this->help, $rows, self::MAX_VISIBLE_CHARACTERS);
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $showAtb = $this->usesAtbLayout();
    $content = [];
    $this->setTitle($this->formatHeaderLine($showAtb));

    foreach (array_slice($this->characters, $this->firstVisibleIndex, self::MAX_VISIBLE_CHARACTERS, true) as $index => $character) {
      $content[] = $this->formatCharacterStats(
        $character,
        $showAtb ? ($this->atbPercentages[$index] ?? 0.0) : null
      );
    }

    $content = array_pad($content, self::HEIGHT - 2, '');

    $this->setContent($content);
    $this->render();
  }

  /**
   * Formats the character stats.
   *
   * @param Character $character The character.
   * @param float|null $atbPercentage The optional ATB gauge percentage.
   * @return string The formatted character stats.
   */
  public function formatCharacterStats(Character $character, ?float $atbPercentage = null): string
  {
    $row = $this->snapshotRow(0, $character, $atbPercentage);
    $hpPercentage = $row->hpRatio;
    $mpPercentage = $row->mpRatio;
    if ($row->currentAp !== null) {
      $layout = $this->getActionPointLayout($row->atbRatio !== null, $row->currentAp);
      $hp = TerminalText::padLeft(strval($row->currentHp), self::HP_VALUE_WIDTH);
      $mp = TerminalText::padLeft(strval($row->currentMp), self::MP_VALUE_WIDTH);
      if ($layout['hpBar'] > 0) {
        $hp .= ' ' . $this->createProgressBar($layout['hpBar'], $hpPercentage)->getRender();
        $mp .= ' ' . $this->createProgressBar($layout['mpBar'], $mpPercentage)->getRender();
      }
      $line = $hp . ' ' . $mp . ' ' . TerminalText::padRight(strval($row->currentAp), $layout['apWidth']);
      if ($layout['timeBar'] > 0) {
        $line .= ' ' . $this->createProgressBar($layout['timeBar'], $row->atbRatio)->getRender();
      }
      return TerminalText::padRight($line, self::CONTENT_WIDTH);
    }

    if ($atbPercentage !== null) {
      return TerminalText::padRight(implode('', [
        TerminalText::padLeft(strval($row->currentHp), self::HP_VALUE_WIDTH),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_HP_BAR_UNITS, $hpPercentage)->getRender(),
        str_repeat(' ', self::COLUMN_GAP),
        TerminalText::padLeft(strval($row->currentMp), self::MP_VALUE_WIDTH),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_BAR_UNITS, $mpPercentage)->getRender(),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_BAR_UNITS, $atbPercentage)->getRender(),
      ]), self::CONTENT_WIDTH);
    }

    return TerminalText::padRight(implode('', [
      TerminalText::padLeft(strval($row->currentHp), self::HP_VALUE_WIDTH),
      str_repeat(' ', self::COLUMN_GAP),
      $this->createProgressBar(self::HP_BAR_UNITS, $hpPercentage)->getRender(),
      str_repeat(' ', self::INTER_STAT_GAP),
      TerminalText::padLeft(strval($row->currentMp), self::MP_VALUE_WIDTH),
      str_repeat(' ', self::COLUMN_GAP),
      $this->createProgressBar(self::MP_BAR_UNITS, $mpPercentage)->getRender(),
    ]), self::CONTENT_WIDTH);
  }

  private function snapshotRow(int $index, Character $character, ?float $atbPercentage): BattleHudStatusRow
  {
    $stats = $character->effectiveStats;

    $hp = isset($this->battleScreen) && $this->battleScreen->usesGraphicalField()
      ? ($this->battleScreen->fieldWindow->getCommandPlayback()?->getPresentedHp($character) ?? $stats->currentHp)
      : $stats->currentHp;
    return new BattleHudStatusRow(
      $index, $hp, $stats->totalHp, $stats->currentMp, $stats->totalMp, $atbPercentage,
      $this->usesActionPoints() ? $stats->currentAp : null,
    );
  }

  /**
   * Formats the header row so labels follow the same geometry as the stat lines.
   *
   * @param bool $showAtb Whether the ATB column is currently visible.
   * @return string The aligned header line.
   */
  protected function formatHeaderLine(bool $showAtb): string
  {
    $horizontal = $this->borderPack::getHorizontalBorder();
    $cells = array_fill(0, self::CONTENT_WIDTH, $horizontal);

    if ($this->usesActionPoints()) {
      $layout = $this->getActionPointLayout($showAtb);
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.hp'), 0);
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.mp'), $layout['mpStart']);
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.ap'), $layout['apStart']);
      if ($layout['timeBar'] > 0) {
        $this->writeHeaderLabel($cells, Vocabulary::getTerm('battle.atb', 'Time'), $layout['timeStart']);
      }
      return implode('', $cells);
    }

    if ($showAtb) {
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.hp', 'HP'), $this->getCompactHpBarStart());
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.mp', 'MP'), $this->getCompactMpBarStart());
      $this->writeHeaderLabel($cells, Vocabulary::getTerm('battle.atb', 'Time'), $this->getCompactAtbBarStart());

      return implode('', $cells);
    }

    $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.hp', 'HP'), $this->getStandardHpBarStart());
    $this->writeHeaderLabel($cells, Vocabulary::getTerm('stats.mp', 'MP'), $this->getStandardMpBarStart());

    return implode('', $cells);
  }

  /**
   * Writes a border title label into the provided header cell buffer.
   *
   * @param array<int, string> $cells The mutable header cells.
   * @param string $label The label to stamp into the border title.
   * @param int $start The zero-based content start column.
   * @return void
   */
  protected function writeHeaderLabel(array &$cells, string $label, int $start): void
  {
    foreach (TerminalText::visibleSymbols($label) as $offset => $symbol) {
      $cellIndex = $start + $offset;

      if ($cellIndex < 0 || $cellIndex >= self::CONTENT_WIDTH) {
        continue;
      }

      $cells[$cellIndex] = $symbol;
    }
  }

  /**
   * Returns the HP bar start column for the standard battle layout.
   *
   * @return int
   */
  protected function getStandardHpBarStart(): int
  {
    return self::HP_VALUE_WIDTH + self::COLUMN_GAP;
  }

  /**
   * Returns the MP bar start column for the standard battle layout.
   *
   * @return int
   */
  protected function getStandardMpBarStart(): int
  {
    return $this->getStandardHpBarStart()
      + $this->getProgressBarWidth(self::HP_BAR_UNITS)
      + self::INTER_STAT_GAP
      + self::MP_VALUE_WIDTH
      + self::COLUMN_GAP;
  }

  /**
   * Returns the HP bar start column for the compact ATB layout.
   *
   * @return int
   */
  protected function getCompactHpBarStart(): int
  {
    return self::HP_VALUE_WIDTH + self::COLUMN_GAP;
  }

  /**
   * Returns the MP bar start column for the compact ATB layout.
   *
   * @return int
   */
  protected function getCompactMpBarStart(): int
  {
    return $this->getCompactHpBarStart()
      + $this->getProgressBarWidth(self::COMPACT_HP_BAR_UNITS)
      + self::COLUMN_GAP
      + self::MP_VALUE_WIDTH
      + self::COLUMN_GAP;
  }

  /**
   * Returns the ATB bar start column for the compact ATB layout.
   *
   * @return int
   */
  protected function getCompactAtbBarStart(): int
  {
    return $this->getCompactMpBarStart()
      + $this->getProgressBarWidth(self::COMPACT_BAR_UNITS)
      + self::COLUMN_GAP;
  }

  /**
   * Returns the display width of a progress bar for the given unit count.
   *
   * @param int $units The number of fill units.
   * @return int
   */
  protected function getProgressBarWidth(int $units): int
  {
    return TerminalText::displayWidth($this->createProgressBar($units, 0.0)->getRender());
  }

  /**
   * Returns whether the status window should include the ATB gauge column.
   *
   * @return bool True when the ATB layout is active.
   */
  protected function usesAtbLayout(): bool
  {
    return $this->atbPercentages !== [];
  }

  private function usesActionPoints(): bool
  {
    return isset($this->battleScreen) && $this->battleScreen->getBattleConfig()?->actionPointPolicy !== null;
  }

  /**
   * Share column geometry between the Terminal header and values. Reserve the
   * full AP balance width before assigning any space to secondary bars.
   *
   * @return array{apWidth: int, hpBar: int, mpBar: int, timeBar: int, mpStart: int, apStart: int, timeStart: int}
   */
  private function getActionPointLayout(bool $showAtb, int $currentAp = 0): array
  {
    $apWidth = max(2, strlen(strval($currentAp)));
    foreach ($this->characters as $character) {
      $apWidth = max($apWidth, strlen(strval($character->stats->totalAp)));
    }
    $baseWidth = self::HP_VALUE_WIDTH + self::MP_VALUE_WIDTH + $apWidth + 2;
    $room = self::CONTENT_WIDTH - $baseWidth;
    $timeBar = $showAtb && $room >= 5 ? 2 : 0;
    $timeWidth = $timeBar > 0 ? 1 + $this->getProgressBarWidth($timeBar) : 0;
    $room -= $timeWidth;
    $hpBar = $room >= 12 ? 4 : ($room >= 8 ? 1 : 0);
    $mpBar = $room >= 12 ? 2 : $hpBar;
    $mpStart = self::HP_VALUE_WIDTH + 1 + ($hpBar > 0 ? 1 + $this->getProgressBarWidth($hpBar) : 0);
    $apStart = $mpStart + self::MP_VALUE_WIDTH + 1 + ($mpBar > 0 ? 1 + $this->getProgressBarWidth($mpBar) : 0);
    return ['apWidth' => $apWidth, 'hpBar' => $hpBar, 'mpBar' => $mpBar, 'timeBar' => $timeBar,
      'mpStart' => $mpStart, 'apStart' => $apStart, 'timeStart' => $apStart + $apWidth + 1];
  }

  /**
   * Creates a progress bar for the given percentage.
   *
   * @param int $units The number of units in the bar.
   * @param float $percentage The filled percentage.
   * @return ProgressBar The configured progress bar.
   */
  protected function createProgressBar(int $units, float $percentage): ProgressBar
  {
    $progressBar = new ProgressBar($this->camera, $units);
    $progressBar->fill($percentage);

    return $progressBar;
  }
}
