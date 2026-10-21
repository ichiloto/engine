<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\UI\Presentation\MenuRowColumn;
use Ichiloto\Engine\UI\Presentation\MenuTableText;

/**
 * One list of the battle test as it stands, for any presentation to draw:
 * its title, its rows (each a one-line label and a figure for each column),
 * the columns those figures line up in, the row the cursor is on, what the
 * semantic actions do here, and the terminal's own help line. The Terminal
 * and graphical presentations lay the rows out by the same columns.
 */
final readonly class BattleTestMenuList
{
  /**
   * @param list<array{0: string, 1: list<string>}> $rows Each row's label and its figures, one per column.
   * @param list<MenuRowColumn> $columns
   * @param list<array{0: string, 1: string}> $hints Each semantic action and what it does here.
   * @param string|null $note What the last action here came to, such as keeping a troop, shown beneath the rows.
   * @param bool $namesArena Whether the arena fights take place in belongs with this list, where arenas are offered.
   * @param ?int $anchor The row kept in view while the cursor is elsewhere (on the troop being fought while the party is set up).
   */
  public function __construct(
    public string $title,
    public array $rows,
    public ?int $selected,
    public array $hints = [],
    public string $help = '',
    public array $columns = [],
    public ?string $note = null,
    public bool $namesArena = false,
    public ?int $anchor = null,
  ) {}

  /** @return list<string> The rows as the terminal lists them in a width, the note after a blank line. */
  public function getTerminalRows(int $width): array
  {
    $labels = array_column($this->rows, 0);
    $cells = MenuTableText::getLabelCells($labels, $this->columns, $width);
    return [...array_map(fn(array $row): string => MenuTableText::formatRow($row[0], $row[1], $this->columns, $cells), $this->rows),
      ...($this->note === null ? [] : ['', ' ' . $this->note])];
  }
}
