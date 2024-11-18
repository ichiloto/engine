<?php

namespace Ichiloto\Engine\UI\Windows\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\IO\Enumerations\Color;

/**
 * Interface WindowInterface. The interface for all windows.
 */
interface WindowInterface extends CanRender, SubjectInterface
{
  /**
   * Returns the window's title.
   *
   * @return string The window's title.
   */
  public function getTitle(): string;

  /**
   * Sets the window's title.
   *
   * @param string $title The window's title.
   * @return void
   */
  public function setTitle(string $title): void;

  /**
   * Returns the window's help.
   *
   * @return string The window's help.
   */
  public function getHelp(): string;

  /**
   * Sets the window's help.
   *
   * @param string $help The window's help.
   * @return void
   */
  public function setHelp(string $help): void;

  /**
   * Returns the window's border pack. The border pack determines the window's border.
   *
   * @return BorderPackInterface The window's border pack.
   */
  public function getBorderPack(): BorderPackInterface;

  /**
   * Sets the window's border pack. The border pack determines the window's border.
   *
   * @param BorderPackInterface $borderPack The window's border pack.
   * @return void
   */
  public function setBorderPack(BorderPackInterface $borderPack): void;

  /**
   * Returns the window's alignment.
   *
   * @return WindowAlignment The window's alignment.
   */
  public function getAlignment(): WindowAlignment;

  /**
   * Gets the window's background color.
   *
   * @return Color The window's background color.
   */
  public function getBackgroundColor(): Color;

  /**
   * Sets the window's background color.
   *
   * @param Color $backgroundColor The window's background color.
   * @return void
   */
  public function setBackgroundColor(Color $backgroundColor): void;

  /**
   * Gets the window's foreground color.
   *
   * @return Color|null The window's foreground color.
   */
  public function getForegroundColor(): ?Color;

  /**
   * Sets the window's foreground color.
   *
   * @param Color|null $foregroundColor The window's foreground color.
   * @return void
   */
  public function setForegroundColor(?Color $foregroundColor): void;
}