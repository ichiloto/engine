<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;
use RuntimeException;

/** Project-owned inward-pointing artwork; PHP owns placement and selection motion. */
final readonly class BattleTargetCursor
{
  /** @param array<string, CanvasNineSlice> $textures Above points down; left/right point toward the battler. */
  public function __construct(
    public array $textures,
    public BattleCursorPlacement $placement = BattleCursorPlacement::ABOVE,
    public int $size = 32,
    public int $gap = 6,
  ) {
    if (count($textures) !== 3 || $size < 16 || $size > 64 || $gap < 0 || $gap > 32) {
      throw new InvalidArgumentException('Target cursor requires three orientations, size 16..64 and gap 0..32.');
    }
    foreach (BattleCursorPlacement::cases() as $placement) {
      $texture = $textures[$placement->value] ?? null;
      if (!$texture instanceof CanvasNineSlice) {
        throw new InvalidArgumentException('Target cursor requires typed above, left and right textures.');
      }
      $texture->images('cursor-preflight', new CanvasRectangle(0, 0, $size, $size), 202);
    }
  }

  /** @param list<CanvasRectangle> $occupied
   * @return array{texture: CanvasNineSlice, bounds: CanvasRectangle, envelope: CanvasRectangle}|null
   */
  public function layout(CanvasRectangle $battler, int $width, int $height, float $now = 0, bool $animate = false,
    array $occupied = []): ?array
  {
    $offset = GraphicalBattleHud::cursorOffset($now, !$animate);
    $options = $this->getPlacementOptions($battler, $width, $height);
    foreach ($options as $option) {
      $envelope = $option['envelope'];
      if (array_any($occupied, static fn(CanvasRectangle $obstacle) => $envelope->x < $obstacle->x + $obstacle->width
        && $envelope->x + $envelope->width > $obstacle->x && $envelope->y < $obstacle->y + $obstacle->height
        && $envelope->y + $envelope->height > $obstacle->y)) { continue; }
      return ['texture' => $option['texture'], 'bounds' => new CanvasRectangle($option['bounds']->x + $option['dx'] * $offset,
        $option['bounds']->y + $option['dy'] * $offset, $this->size, $this->size), 'envelope' => $envelope];
    }
    // A temporary modal may obscure every valid placement without invalidating the formation.
    if ($options !== []) { return null; }
    throw new RuntimeException('Target cursor cannot fit outside the battler within the graphical canvas.');
  }

  /** All potential cursor slots are reserved even while the battler is not selected. */
  public function getPlacementOptions(CanvasRectangle $battler, int $width, int $height): array
  {
    $travel = 4;
    $options = [];
    foreach ([$this->placement, ...array_filter(BattleCursorPlacement::cases(), fn($p) => $p !== $this->placement)] as $placement) {
      [$x, $y, $dx, $dy] = match ($placement) {
        BattleCursorPlacement::ABOVE => [$battler->x + ($battler->width - $this->size) / 2,
          $battler->y - $this->gap - $this->size, 0, -1],
        BattleCursorPlacement::LEFT => [$battler->x - $this->gap - $this->size,
          $battler->y + ($battler->height - $this->size) / 2, -1, 0],
        BattleCursorPlacement::RIGHT => [$battler->x + $battler->width + $this->gap,
          $battler->y + ($battler->height - $this->size) / 2, 1, 0],
      };
      $left = $x + min(0, $dx * $travel);
      $top = $y + min(0, $dy * $travel);
      $envelopeWidth = $this->size + abs($dx) * $travel;
      $envelopeHeight = $this->size + abs($dy) * $travel;
      if ($left < 0 || $top < 0 || $left + $envelopeWidth > $width || $top + $envelopeHeight > $height) { continue; }
      $options[] = ['texture' => $this->textures[$placement->value], 'placement' => $placement,
        'bounds' => new CanvasRectangle($x, $y, $this->size, $this->size), 'dx' => $dx, 'dy' => $dy,
        'envelope' => new CanvasRectangle($left, $top, $envelopeWidth, $envelopeHeight)];
    }
    return $options;
  }
}
