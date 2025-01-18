<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Elements\ProgressBar\ProgressBar;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Util\Debug;

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
    $hpProgressBar = new ProgressBar($this->camera, 10);
    $mpProgressBar = new ProgressBar($this->camera, 5);
    $hpPercentage = $character->effectiveStats->currentHp / $character->effectiveStats->totalHp;
    $mpPercentage = $character->effectiveStats->currentMp / $character->effectiveStats->totalMp;
    $hpProgressBar->fill($hpPercentage);
    $mpProgressBar->fill($mpPercentage);

    return sprintf(
      "%4d %-15s  %4d %-8s",
      $character->effectiveStats->currentHp,
      $hpProgressBar->getRender(),
      $character->effectiveStats->currentMp,
      $mpProgressBar->getRender()
    );
  }
}