<?php

namespace Ichiloto\Engine\Animations;

/**
 * Represents a single animation key frame.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationFrame
{
  /**
   * @var AnimationCell[] $cells
   */
  protected array $cells = [];

  /**
   * @param int $index The 1-based frame index.
   * @param AnimationCell[] $cells The cells to render on this frame.
   */
  public function __construct(
    public int $index,
    array $cells = [],
  )
  {
    $this->index = max(1, $index);

    foreach ($cells as $cell) {
      if ($cell instanceof AnimationCell) {
        $this->setCell($cell->x, $cell->y, $cell->symbol, $cell->color);
      }
    }
  }

  /**
   * Hydrates a frame from an array payload.
   *
   * @param array<string, mixed> $data The serialized frame data.
   * @return self
   */
  public static function fromArray(array $data): self
  {
    $cells = array_map(
      static fn(array $cell): AnimationCell => AnimationCell::fromArray($cell),
      array_values(array_filter($data['cells'] ?? [], 'is_array'))
    );

    return new self(
      intval($data['index'] ?? 1),
      $cells,
    );
  }

  /**
   * Returns the cells that belong to this frame.
   *
   * @return AnimationCell[]
   */
  public function getCells(): array
  {
    return array_values($this->cells);
  }

  /**
   * Places or replaces a cell at the given frame offset.
   *
   * @param int $x The horizontal offset.
   * @param int $y The vertical offset.
   * @param string $symbol The symbol to draw.
   * @param string|null $color The optional color name.
   * @return void
   */
  public function setCell(int $x, int $y, string $symbol, ?string $color = null): void
  {
    $key = $x . ':' . $y;

    if (trim($symbol) === '') {
      unset($this->cells[$key]);
      return;
    }

    $this->cells[$key] = new AnimationCell($symbol, $x, $y, $color);
  }

  /**
   * Returns the cell at the requested offset.
   *
   * @param int $x The horizontal offset.
   * @param int $y The vertical offset.
   * @return AnimationCell|null
   */
  public function getCellAt(int $x, int $y): ?AnimationCell
  {
    return $this->cells[$x . ':' . $y] ?? null;
  }

  /**
   * Returns the serialized frame payload.
   *
   * @return array{index: int, cells: array<int, array<string, mixed>>}
   */
  public function toArray(): array
  {
    return [
      'index' => $this->index,
      'cells' => array_map(
        static fn(AnimationCell $cell): array => $cell->toArray(),
        $this->getCells()
      ),
    ];
  }
}
