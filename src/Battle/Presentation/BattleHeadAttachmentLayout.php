<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;
use WeakMap;

/** Owns condition slots around the reserved cursor envelopes for the life of a battle. */
final class BattleHeadAttachmentLayout
{
    private readonly BattleConditionSlots $slots;
    private WeakMap $offsets;

    public function __construct()
    {
        $this->slots = new BattleConditionSlots();
        $this->offsets = new WeakMap();
    }

    /** @return array<int, list<array{entry: array, bounds: CanvasRectangle}>> */
    public function place(array $battlers, array $bounds, int $width, int $height, BattleConditionBadgeStyle $style,
        ?BattleTargetCursor $cursor): array
    {
        $obstacles = array_values($bounds);
        foreach ($bounds as $body) {
            foreach ($cursor?->getPlacementOptions($body, $width, $height) ?? [] as $option) { $obstacles[] = $option['envelope']; }
        }
        $result = $pending = $occupied = [];
        // Keep all existing badges first, so a newly applied condition never displaces another battler's badge.
        foreach ($battlers as $id => $battler) {
            if (!$battler->isFighting || !isset($bounds[$id])) { continue; }
            $entries = $this->slots->assign($battler, BattlerConditions::getEntries($battler));
            $offsets = array_intersect_key($this->offsets[$battler] ?? [], array_column($entries, null, 'slot'));
            $body = $bounds[$id];
            foreach ($entries as $entry) {
                $offset = $offsets[$entry['slot']] ?? null;
                $x = $body->x + $body->width / 2 + ($offset[0] ?? 0);
                $y = $body->y + ($offset[1] ?? 0);
                $box = $offset === null || $x < 0 || $y < 0 || $x + $style->size > $width || $y + $style->height > $height
                    ? null : new CanvasRectangle($x, $y, $style->size, $style->height);
                if ($box !== null && self::canPlace($box, $width, $height, [...$obstacles, ...$occupied])) {
                    $result[$id][$entry['slot']] = ['entry' => $entry, 'bounds' => $box];
                    $occupied[] = $box;
                } else { $pending[$id][] = $entry; unset($offsets[$entry['slot']]); }
            }
            $this->offsets[$battler] = $offsets;
        }
        foreach ($pending as $id => $entries) {
            $body = $bounds[$id];
            $offsets = $this->offsets[$battlers[$id]];
            foreach ($entries as $entry) {
                $box = null;
                foreach ($this->getCandidates($body, $style, $cursor, $width, $height) as $candidate) {
                    if (self::canPlace($candidate, $width, $height, [...$obstacles, ...$occupied])) { $box = $candidate; break; }
                }
                if ($box === null) { throw new RuntimeException('Battle condition badges cannot fit outside reserved battler and cursor slots.'); }
                $result[$id][$entry['slot']] = ['entry' => $entry, 'bounds' => $box];
                $occupied[] = $box;
                $offsets[$entry['slot']] = [$box->x - $body->x - $body->width / 2, $box->y - $body->y];
            }
            $this->offsets[$battlers[$id]] = $offsets;
        }
        foreach ($result as &$entries) { ksort($entries); $entries = array_values($entries); }
        unset($entries);
        return $result;
    }

    /** Preferred head rows build to the right of the cursor; bounded edge fallbacks retain separate slots. */
    private function getCandidates(CanvasRectangle $body, BattleConditionBadgeStyle $style, ?BattleTargetCursor $cursor,
        int $width, int $height): iterable
    {
        $stepX = $style->size + $style->gap;
        $stepY = $style->height + $style->gap;
        $middle = $body->x + $body->width / 2;
        $clearance = ($cursor?->size ?? 0) / 2 + $style->headGap;
        foreach ([1, -1] as $side) {
            for ($row = 0; $row <= (int)ceil($height / $stepY); $row++) {
                for ($column = 0; $column < $style->maxColumns; $column++) {
                    $x = $side === 1 ? $middle + $clearance + $column * $stepX
                        : $middle - $clearance - $style->size - $column * $stepX;
                    $y = $body->y - $style->height - $style->headGap - $row * $stepY;
                    if ($x < 0 || $y < 0 || $x + $style->size > $width || $y + $style->height > $height) { continue; }
                    yield new CanvasRectangle($x, $y, $style->size, $style->height);
                }
            }
        }
        // Edge formations still have an explicit place for every badge, with no omission or cursor displacement.
        $fallback = [];
        for ($y = 0; $y + $style->height <= $height; $y += $stepY) {
            for ($x = 0; $x + $style->size <= $width; $x += $stepX) {
                $fallback[] = new CanvasRectangle($x, $y, $style->size, $style->height);
            }
        }
        usort($fallback, static fn($a, $b): int => (abs($a->x - $middle) + abs($a->y - $body->y))
            <=> (abs($b->x - $middle) + abs($b->y - $body->y)));
        yield from $fallback;
    }

    private static function canPlace(CanvasRectangle $box, int $width, int $height, array $obstacles): bool
    {
        return $box->x >= 0 && $box->y >= 0 && $box->x + $box->width <= $width && $box->y + $box->height <= $height
            && !array_any($obstacles, static fn($other): bool => $box->x < $other->x + $other->width
                && $box->x + $box->width > $other->x && $box->y < $other->y + $other->height && $box->y + $box->height > $other->y);
    }
}
