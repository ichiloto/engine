<?php

namespace Ichiloto\Engine\UI\Elements;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredUIElementInterface;
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
class LocationHUDWindow extends Window implements LayeredUIElementInterface
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 25;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 4;

  /** Whether the physical terminal must receive the complete HUD footprint. */
  protected bool $requiresPhysicalRepaint = true;

  /**
   * @inheritDoc
   */
  protected(set) bool $isActive = false;

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
    $topMargin = get_screen_height() - self::HEIGHT;
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

    $content = [
      "Coordinates: ({$this->coordinates->x}, {$this->coordinates->y})",
      "Heading: {$this->heading->value}"
    ];

    if ($content !== $this->getContent()) {
      $this->requiresPhysicalRepaint = true;
    }

    $this->setContent($content);
  }

  /**
   * Repositions the HUD to match the current screen size.
   *
   * @return void
   */
  public function refreshLayout(): void
  {
    $this->setPosition(new Vector2(1, get_screen_height() - self::HEIGHT));
    $this->requiresPhysicalRepaint = true;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function render(?int $x = null, ?int $y = null): void
  {
    if ($this->isPresentationVisible()) {
      Console::beginFrame();

      try {
        parent::render();

        if ($this->requiresPhysicalRepaint) {
          $bounds = $this->getPresentationBounds();
          Console::repaintRegion(
            intval($bounds->getX()),
            intval($bounds->getY()),
            intval($bounds->getWidth()),
            intval($bounds->getHeight()),
          );
          $this->requiresPhysicalRepaint = false;
        }
      } finally {
        Console::endFrame();
      }
    }
  }

  /** @inheritDoc */
  public function isPresentationVisible(): bool
  {
    return config(ProjectConfig::class, 'ui.hud.location', false) && $this->isActive;
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->isActive = true;
    $this->requiresPhysicalRepaint = true;
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    $this->isActive = false;
  }

  /** @inheritDoc */
  public function getPresentationBounds(): Rect
  {
    return $this->getBounds();
  }

  /** @inheritDoc */
  public function getPresentationPriority(): PresentationPriority
  {
    return PresentationPriority::FIELD_HUD;
  }
}
