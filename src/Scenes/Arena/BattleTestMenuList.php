<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

/**
 * One list of the battle test as it stands, for any presentation to draw:
 * its title, its rows (each a label and a value, either possibly empty),
 * the row the cursor is on, what the semantic actions do here, and the
 * terminal's own help line and row format, so the Terminal battle test
 * reads as it always has.
 */
final readonly class BattleTestMenuList
{
  /**
   * @param list<array{0: string, 1: string}> $rows
   * @param list<array{0: string, 1: string}> $hints Each semantic action and what it does here.
   * @param string $format How the terminal writes a row with a value: a sprintf of label then value.
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
    public string $format = '%s %s',
    public ?string $note = null,
    public bool $namesArena = false,
    public ?int $anchor = null,
  ) {}

  /** A row as the terminal writes it: the label alone, or label and value in the list's format. */
  public function formatRow(array $row): string
  {
    return $row[1] === '' ? $row[0] : sprintf($this->format, $row[0], $row[1]);
  }

  /** @return list<string> The rows as the terminal lists them, the note after a blank line. */
  public function getTerminalRows(): array
  {
    return [...array_map($this->formatRow(...), $this->rows), ...($this->note === null ? [] : ['', ' ' . $this->note])];
  }
}
