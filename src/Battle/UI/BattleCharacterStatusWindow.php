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
    $this->characters = array_slice($characters, 0, 3);
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
    $content = [];

    foreach ($this->characters as $index => $character) {
      $content[] = $this->formatCharacterStats($character, $this->atbPercentages[$index] ?? null);
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
      return implode('', [
        TerminalText::padLeft(strval($character->effectiveStats->currentHp), 4),
        ' ',
        $this->createProgressBar(6, $hpPercentage)->getRender(),
        ' ',
        TerminalText::padLeft(strval($character->effectiveStats->currentMp), 4),
        ' ',
        $this->createProgressBar(3, $mpPercentage)->getRender(),
        ' ',
        $this->createProgressBar(3, $atbPercentage)->getRender(),
      ]);
    }

    return implode('', [
      TerminalText::padLeft(strval($character->effectiveStats->currentHp), 4),
      ' ',
      $this->createProgressBar(10, $hpPercentage)->getRender(),
      '  ',
      TerminalText::padLeft(strval($character->effectiveStats->currentMp), 4),
      ' ',
      $this->createProgressBar(5, $mpPercentage)->getRender(),
    ]);
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