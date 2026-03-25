<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the character detail panel.
 *
 * @package Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows
 */
class CharacterDetailPanel extends Window
{
  /**
   * The width reserved for each stat value column.
   */
  private const int STAT_VALUE_WIDTH = 6;

  /**
   * @var Character|null The character to display.
   */
  protected ?Character $character = null;
  /**
   * @var Stats|null The stats of the character.
   */
  protected ?Stats $previewStats = null;

  /**
   * Create a new instance of the character detail panel.
   *
   * @param MenuInterface $menu The menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack.
   */
  public function __construct(
    protected MenuInterface $menu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
      '',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );

    $this->updateContent();
  }

  /**
   * Update the content of the window.
   *
   * @param Character $character The character to display.
   * @param Stats|null $previewStats The stats of the character.
   * @return void
   */
  public function setDetails(Character $character, ?Stats $previewStats = null): void
  {
    $this->character = $character;
    $this->previewStats = $previewStats;
    $this->updateContent();
  }

  /**
   * Update the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $totalHp = $this->previewStats?->totalHp ?? null;
    $totalMp = $this->previewStats?->totalMp ?? null;
    $attack = $this->previewStats?->attack ?? null;
    $defence = $this->previewStats?->defence ?? null;
    $magicAttack = $this->previewStats?->magicAttack ?? null;
    $magicDefence = $this->previewStats?->magicDefence ?? null;
    $evasion = $this->previewStats?->evasion ?? null;
    $speed = $this->previewStats?->speed ?? null;
    $grace = $this->previewStats?->grace ?? null;

    $content = [
      "  {$this->character?->name}" ?? '',
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      $this->formatStatLine('HP', $this->character?->effectiveStats->totalHp, $totalHp),
      $this->formatStatLine('MP', $this->character?->effectiveStats->totalMp, $totalMp),
      $this->formatStatLine('Attack', $this->character?->effectiveStats->attack, $attack),
      $this->formatStatLine('Defence', $this->character?->effectiveStats->defence, $defence),
      $this->formatStatLine('M.Attack', $this->character?->effectiveStats->magicAttack, $magicAttack),
      $this->formatStatLine('M.Defence', $this->character?->effectiveStats->magicDefence, $magicDefence),
      $this->formatStatLine('Evasion', $this->character?->effectiveStats->evasion, $evasion),
      $this->formatStatLine('Speed', $this->character?->effectiveStats->speed, $speed),
      $this->formatStatLine('Grace', $this->character?->effectiveStats->grace, $grace),
    ];

    $this->setContent(array_pad($content, $this->height - 2, ''));
    $this->render();
  }

  /**
   * Formats a stat row so the current and preview columns stay aligned.
   *
   * @param string $label The stat label.
   * @param int|null $currentStat The current effective stat.
   * @param int|null $previewStat The preview stat after the pending change.
   * @return string The formatted stat line.
   */
  private function formatStatLine(string $label, ?int $currentStat, ?int $previewStat): string
  {
    return sprintf(
      "  %-10s %6s  ->  %6s %1s",
      $label,
      $this->formatStatValue($currentStat),
      $this->formatStatValue($previewStat ?? $currentStat),
      $this->getStatIndicator($currentStat, $previewStat)
    );
  }

  /**
   * Formats a stat value for the right-aligned preview columns.
   *
   * @param int|null $stat The stat value to format.
   * @return string The formatted stat value.
   */
  private function formatStatValue(?int $stat): string
  {
    if (! isset($stat)) {
      return '';
    }

    return str_pad(number_format($stat), self::STAT_VALUE_WIDTH, ' ', STR_PAD_LEFT);
  }

  /**
   * Returns the visual indicator for a stat preview change.
   *
   * @param int|null $currentStat The current stat.
   * @param int|null $previewStat The preview stat.
   * @return string The comparison indicator.
   */
  private function getStatIndicator(?int $currentStat, ?int $previewStat): string
  {
    if (isset($currentStat)  && isset($previewStat)) {
      return match(true) {
        $currentStat < $previewStat => '↑',
        $currentStat > $previewStat => '↓',
        default => '',
      };
    }

    return '';
  }
}
