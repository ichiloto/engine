<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\TerminalText;
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
      'HP══════MP══ATB',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }

  /**
   * Sets the battlers to display.
   *
   * @param Character[] $characters The battlers to set.
   */
  public function setCharacters(array $characters): void
  {
    $this->characters = array_slice($characters, 0, self::MAX_VISIBLE_CHARACTERS);
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

    foreach ($this->characters as $index => $character) {
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
    $hpTotal = max(1, $character->effectiveStats->totalHp);
    $mpTotal = max(1, $character->effectiveStats->totalMp);
    $hpPercentage = $character->effectiveStats->currentHp / $hpTotal;
    $mpPercentage = $character->effectiveStats->currentMp / $mpTotal;

    if ($atbPercentage !== null) {
      return TerminalText::padRight(implode('', [
        TerminalText::padLeft(strval($character->effectiveStats->currentHp), self::HP_VALUE_WIDTH),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_HP_BAR_UNITS, $hpPercentage)->getRender(),
        str_repeat(' ', self::COLUMN_GAP),
        TerminalText::padLeft(strval($character->effectiveStats->currentMp), self::MP_VALUE_WIDTH),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_BAR_UNITS, $mpPercentage)->getRender(),
        str_repeat(' ', self::COLUMN_GAP),
        $this->createProgressBar(self::COMPACT_BAR_UNITS, $atbPercentage)->getRender(),
      ]), self::CONTENT_WIDTH);
    }

    return TerminalText::padRight(implode('', [
      TerminalText::padLeft(strval($character->effectiveStats->currentHp), self::HP_VALUE_WIDTH),
      str_repeat(' ', self::COLUMN_GAP),
      $this->createProgressBar(self::HP_BAR_UNITS, $hpPercentage)->getRender(),
      str_repeat(' ', self::INTER_STAT_GAP),
      TerminalText::padLeft(strval($character->effectiveStats->currentMp), self::MP_VALUE_WIDTH),
      str_repeat(' ', self::COLUMN_GAP),
      $this->createProgressBar(self::MP_BAR_UNITS, $mpPercentage)->getRender(),
    ]), self::CONTENT_WIDTH);
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

    if ($showAtb) {
      $this->writeHeaderLabel($cells, 'HP', $this->getCompactHpBarStart());
      $this->writeHeaderLabel($cells, 'MP', $this->getCompactMpBarStart());
      $this->writeHeaderLabel($cells, 'ATB', $this->getCompactAtbBarStart());

      return implode('', $cells);
    }

    $this->writeHeaderLabel($cells, 'HP', $this->getStandardHpBarStart());
    $this->writeHeaderLabel($cells, 'MP', $this->getStandardMpBarStart());

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
