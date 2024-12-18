<?php

namespace Ichiloto\Engine\UI;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Override;

/**
 * Class LocationHUDWindow. Represents the location HUD window.
 *
 * @package Ichiloto\Engine\UI
 */
class LocationHUDWindow extends Window implements UIElementInterface
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 25;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 4;

  /**
   * @inheritDoc
   */
  protected(set) bool $isActive = true;

  /**
   * LocationHUDWindow constructor.
   *
   * @param Vector2 $coordinates The coordinates of the window.
   * @param MovementHeading $heading The heading of the window.
   * @param BorderPackInterface $borderPack The border pack of the window.
   */
  public function __construct(
    public Vector2 $coordinates,
    public MovementHeading $heading,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    $leftMargin = 1;
    $topMargin = DEFAULT_SCREEN_HEIGHT - self::HEIGHT;
    parent::__construct('', '', new Vector2($leftMargin, $topMargin), self::WIDTH, self::HEIGHT, $borderPack);
    $this->updateDetails($coordinates, $heading);
  }

  /**
   * Sets the details of the window.
   *
   * @param Vector2 $coordinates The coordinates.
   * @param MovementHeading $heading The heading.
   */
  public function updateDetails(Vector2 $coordinates, MovementHeading $heading): void
  {
    $this->coordinates = $coordinates;
    $this->heading = $heading;

    $this->setContent([
      "Coordinates: ({$this->coordinates->x}, {$this->coordinates->y})",
      "Heading: {$this->heading->value}"
    ]);
    $this->render();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function render(?int $x = null, ?int $y = null): void
  {
    if (config(ProjectConfig::class, 'ui.hud.location', false) && $this->isActive) {
      parent::render();
    }
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->isActive = true;
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    $this->isActive = false;
  }
}