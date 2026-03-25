<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Elements\ProgressBar\ProgressBar;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Entities\Character;

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
      'HP═══════════════════MP',
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
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];
    foreach ($this->characters as $character) {
      $content[] = $this->formatCharacterStats($character);
    }

    $content = array_pad($content, self::HEIGHT - 2, '');

    $this->setContent($content);
    $this->render();
  }

  /**
   * Formats the character stats.
   *
   * @param Character $character The character.
   * @return string The formatted character stats.
   */
  public function formatCharacterStats(Character $character): string
  {
    $hpTotal = max(1, $character->effectiveStats->totalHp);
    $mpTotal = max(1, $character->effectiveStats->totalMp);
    $hpPercentage = $character->effectiveStats->currentHp / $hpTotal;
    $mpPercentage = $character->effectiveStats->currentMp / $mpTotal;
    $hpProgressBar = $this->createProgressBar(10, $hpPercentage);
    $mpProgressBar = $this->createProgressBar(5, $mpPercentage);

    return implode('', [
      TerminalText::padLeft(strval($character->effectiveStats->currentHp), 4),
      ' ',
      $hpProgressBar->getRender(),
      '  ',
      TerminalText::padLeft(strval($character->effectiveStats->currentMp), 4),
      ' ',
      $mpProgressBar->getRender(),
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
