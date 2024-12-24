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
      sprintf("  %-13s%4s    ->    %4s", 'HP', $this->character?->effectiveStats->totalHp ?? '', $this->highlightStat($this->character?->effectiveStats->totalHp, $totalHp)),
      sprintf("  %-15s%2s    ->     %3s", 'MP', $this->character?->effectiveStats->totalMp ?? '', $this->highlightStat($this->character?->effectiveStats->totalMp, $totalMp)),
      sprintf("  %-15s%2s    ->     %3s", 'Attack', $this->character?->effectiveStats->attack ?? '', $this->highlightStat($this->character?->effectiveStats->attack, $attack)),
      sprintf("  %-15s%2s    ->     %3s", 'Defence', $this->character?->effectiveStats->defence ?? '', $this->highlightStat($this->character?->effectiveStats->defence, $defence)),
      sprintf("  %-15s%2s    ->     %3s", 'M.Attack', $this->character?->effectiveStats->magicAttack ?? '', $this->highlightStat($this->character?->effectiveStats->magicAttack, $magicAttack)),
      sprintf("  %-15s%2s    ->     %3s", 'M.Defence', $this->character?->effectiveStats->magicDefence ?? '', $this->highlightStat($this->character?->effectiveStats->magicDefence, $magicDefence)),
      sprintf("  %-15s%2s    ->     %3s", 'Evasion', $this->character?->effectiveStats->evasion ?? '', $this->highlightStat($this->character?->effectiveStats->evasion, $evasion)),
      sprintf("  %-15s%2s    ->     %3s", 'Speed', $this->character?->effectiveStats->speed ?? '', $this->highlightStat($this->character?->effectiveStats->speed, $speed)),
      sprintf("  %-15s%2s    ->     %3s", 'Grace', $this->character?->effectiveStats->grace ?? '', $this->highlightStat($this->character?->effectiveStats->grace, $grace)),
    ];
    $contentSize = count($content);

    $content = [...$content, ...array_fill($contentSize - 1, $this->height - $contentSize - 2, '')]; // Subtract 2 for the border.
    $this->setContent($content);
    $this->render();
  }

  /**
   * Colorize the stat.
   *
   * @param int|null $currentStat The previous stat.
   * @param int|null $previewStat The stat to colorize.
   * @return string The colorized stat.
   */
  private function highlightStat(?int $currentStat, ?int $previewStat): string
  {
    $suffix = '';
    if (isset($currentStat)  && isset($previewStat)) {
      $suffix = match(true) {
        $currentStat < $previewStat => '↑',
        $currentStat > $previewStat => '↓',
        default => '',
      };
    }
    $value = $previewStat ?? '';

    return "{$value} {$suffix}";
  }
}