<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;
use WeakMap;

/**
 * Owns condition slots around the reserved cursor envelopes for the life of a battle.
 *
 * Every badge belongs to the head of its battler's drawn body. Each condition slot has a home cell in the head
 * rows beside the cursor, so a badge follows its battler as it moves or changes pose and adding, curing or
 * selecting never moves another badge. Badges never cover a cursor envelope, another badge or their own body;
 * another battler's body may stand behind them, as battlers stand behind one another. A badge whose home is
 * taken keeps its last place beside the head while that stays free, and returns home once it frees.
 */
final class BattleHeadAttachmentLayout
{
    private readonly BattleConditionSlots $slots;
    /** @var WeakMap<object, array<int, array{float, float}>> Battler => slot => offset from its head. */
    private WeakMap $offsets;

    public function __construct()
    {
        $this->slots = new BattleConditionSlots();
        $this->offsets = new WeakMap();
    }

    /**
     * @param array<int, CanvasRectangle> $bodies Each battler's drawn body this frame.
     * @return array<int, list<array{entry: array, bounds: CanvasRectangle}>>
     */
    public function place(array $battlers, array $bodies, int $width, int $height, BattleConditionBadgeStyle $style,
        ?BattleTargetCursor $cursor): array
    {
        $reserved = [];
        foreach ($bodies as $body) {
            foreach ($cursor?->getPlacementOptions($body, $width, $height) ?? [] as $option) { $reserved[] = $option['envelope']; }
        }
        $result = $pending = $placed = $cells = [];
        $place = function (int $id, array $entry, CanvasRectangle $box) use (&$result, &$placed, $battlers, $bodies): void {
            $result[$id][$entry['slot']] = ['entry' => $entry, 'bounds' => $box];
            $placed[] = $box;
            $offsets = $this->offsets[$battlers[$id]] ?? [];
            $offsets[$entry['slot']] = [$box->x - self::getHeadX($bodies[$id]), $box->y - $bodies[$id]->y];
            $this->offsets[$battlers[$id]] = $offsets;
        };
        // Homes first, so a displaced badge never takes another battler's home.
        foreach ($battlers as $id => $battler) {
            if (!$battler->isFighting || !isset($bodies[$id])) { continue; }
            $entries = $this->slots->assign($battler, BattlerConditions::getEntries($battler));
            $this->offsets[$battler] = array_intersect_key($this->offsets[$battler] ?? [], array_column($entries, null, 'slot'));
            $cells[$id] = $this->getHeadCells($bodies[$id], $style, $cursor, $width, $height);
            foreach ($entries as $entry) {
                $home = $cells[$id][$entry['slot']] ?? null;
                if ($home !== null && self::canPlace($home, $width, $height, [...$reserved, $bodies[$id], ...$placed])) {
                    $place($id, $entry, $home);
                } else { $pending[] = [$id, $entry]; }
            }
        }
        $searching = [];
        foreach ($pending as [$id, $entry]) {
            $offset = ($this->offsets[$battlers[$id]] ?? [])[$entry['slot']] ?? null;
            [$x, $y] = $offset === null ? [-1, -1] : [self::getHeadX($bodies[$id]) + $offset[0], $bodies[$id]->y + $offset[1]];
            $box = $x < 0 || $y < 0 ? null : new CanvasRectangle($x, $y, $style->size, $style->height);
            if ($box !== null && self::canPlace($box, $width, $height, [...$reserved, $bodies[$id], ...$placed])) {
                $place($id, $entry, $box);
            } else { $searching[] = [$id, $entry]; }
        }
        foreach ($searching as [$id, $entry]) {
            $body = $bodies[$id];
            $home = $cells[$id][$entry['slot']] ?? null;
            [$homeX, $homeY] = [$home?->x ?? self::getHeadX($body), $home?->y ?? $body->y - $style->height - $style->headGap];
            $box = null;
            foreach ([$cells[$id], $this->getFallbackCells($style, $width, $height)] as $candidates) {
                usort($candidates, static fn(CanvasRectangle $a, CanvasRectangle $b): int =>
                    abs($a->x - $homeX) + abs($a->y - $homeY) <=> abs($b->x - $homeX) + abs($b->y - $homeY));
                $box = array_find($candidates, fn(CanvasRectangle $candidate): bool =>
                    self::canPlace($candidate, $width, $height, [...$reserved, $body, ...$placed]));
                if ($box !== null) { break; }
            }
            if ($box === null) { throw new RuntimeException('Battle condition badges cannot fit outside reserved battler and cursor slots.'); }
            $place($id, $entry, $box);
        }
        foreach ($result as &$entries) { ksort($entries); $entries = array_values($entries); }
        unset($entries);
        return $result;
    }

    private static function getHeadX(CanvasRectangle $body): float
    {
        return $body->x + $body->width / 2;
    }

    /**
     * The head rows, in slot order: building to the right of the cursor, wrapping upward at the column limit,
     * then the same rows on the left where the right runs off the canvas.
     * @return list<CanvasRectangle>
     */
    private function getHeadCells(CanvasRectangle $body, BattleConditionBadgeStyle $style, ?BattleTargetCursor $cursor,
        int $width, int $height): array
    {
        $stepX = $style->size + $style->gap;
        $stepY = $style->height + $style->gap;
        $middle = self::getHeadX($body);
        $clearance = ($cursor?->size ?? 0) / 2 + $style->headGap;
        $cells = [];
        foreach ([1, -1] as $side) {
            for ($row = 0; $row <= (int)ceil($height / $stepY); $row++) {
                for ($column = 0; $column < $style->maxColumns; $column++) {
                    $x = $side === 1 ? $middle + $clearance + $column * $stepX
                        : $middle - $clearance - $style->size - $column * $stepX;
                    $y = $body->y - $style->height - $style->headGap - $row * $stepY;
                    if ($x < 0 || $y < 0 || $x + $style->size > $width || $y + $style->height > $height) { continue; }
                    $cells[] = new CanvasRectangle($x, $y, $style->size, $style->height);
                }
            }
        }
        return $cells;
    }

    /**
     * Edge formations still have an explicit place for every badge, with no omission or cursor displacement.
     * @return list<CanvasRectangle>
     */
    private function getFallbackCells(BattleConditionBadgeStyle $style, int $width, int $height): array
    {
        $cells = [];
        for ($y = 0; $y + $style->height <= $height; $y += $style->height + $style->gap) {
            for ($x = 0; $x + $style->size <= $width; $x += $style->size + $style->gap) {
                $cells[] = new CanvasRectangle($x, $y, $style->size, $style->height);
            }
        }
        return $cells;
    }

    private static function canPlace(CanvasRectangle $box, int $width, int $height, array $obstacles): bool
    {
        return $box->x >= 0 && $box->y >= 0 && $box->x + $box->width <= $width && $box->y + $box->height <= $height
            && !array_any($obstacles, static fn($other): bool => $box->x < $other->x + $other->width
                && $box->x + $box->width > $other->x && $box->y < $other->y + $other->height && $box->y + $box->height > $other->y);
    }
}
