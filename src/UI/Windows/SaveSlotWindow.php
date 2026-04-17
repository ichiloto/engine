<?php

namespace Ichiloto\Engine\UI\Windows;

use Ichiloto\Engine\Core\Enumerations\ChronoUnit;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;

/**
 * Displays a single save-slot summary in save/load screens.
 *
 * @package Ichiloto\Engine\UI\Windows
 */
class SaveSlotWindow extends Window
{
  /**
   * The fixed height of an individual save slot window.
   */
  public const int HEIGHT = 4;

  /**
   * SaveSlotWindow constructor.
   *
   * @param Vector2 $position The top-left position of the slot window.
   * @param int $width The slot window width.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
  public function __construct(
    Vector2 $position,
    int $width,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    parent::__construct(
      '',
      '',
      $position,
      $width,
      self::HEIGHT,
      $borderPack
    );
  }

  /**
   * Updates the slot summary and highlight state.
   *
   * @param SaveSlot $slot The slot to display.
   * @param bool $isSelected Whether the slot is currently selected.
   * @return void
   */
  public function setSlot(SaveSlot $slot, bool $isSelected = false): void
  {
    $this->title = sprintf('File %d', $slot->slot);
    $this->foregroundColor = $isSelected ? SelectionStyle::resolveColor() : null;
    $this->setContent([
      $slot->isEmpty ? 'Empty File' : $slot->locationName,
      $this->buildFooterLine($slot),
    ]);
  }

  /**
   * Builds the second line of the slot summary.
   *
   * @param SaveSlot $slot The slot to describe.
   * @return string The formatted footer line.
   */
  protected function buildFooterLine(SaveSlot $slot): string
  {
    if ($slot->isEmpty) {
      return '';
    }

    if (! $slot->isLoadable) {
      $contentWidth = max(0, $this->width - 4);
      return TerminalText::padRight(strval($slot->statusMessage ?? 'Cannot be loaded.'), $contentWidth);
    }

    $contentWidth = max(0, $this->width - 4);
    $leaderSummary = $slot->getLeaderSummary();
    $playTime = Time::formatDuration($slot->playTimeSeconds, ChronoUnit::SECONDS);

    if ($leaderSummary === '') {
      return TerminalText::padLeft($playTime, $contentWidth);
    }

    $leftWidth = max(0, $contentWidth - TerminalText::displayWidth($playTime) - 1);

    return TerminalText::padRight($leaderSummary, $leftWidth) . ' ' . $playTime;
  }
}
