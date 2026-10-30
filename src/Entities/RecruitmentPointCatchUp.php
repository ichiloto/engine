<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities;

use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryProgress;
use InvalidArgumentException;

/** A prepared recruitment award and explicitly authorized legacy history initialization. */
final readonly class RecruitmentPointCatchUp
{
    /** @param list<array{progress: MasteryProgress, points: int, learned: array, pending: int, spent: int}> $histories */
    private function __construct(public int $points, private array $histories) {}

    /** @param list<Character> $members */
    public static function prepare(array $members, RecruitmentPointPolicy $policy, ?MasteryCatalog $catalog = null): ?self
    {
        if ($policy === RecruitmentPointPolicy::AUTHORED) { return null; }
        if ($members === []) { throw new InvalidArgumentException('Party-average SP recruitment requires an existing party.'); }
        $totals = $histories = [];
        foreach ($members as $member) {
            $progress = $member->mastery;
            $earned = $progress->earnedPoints;
            if ($earned === null) {
                if ($policy !== RecruitmentPointPolicy::PARTY_AVERAGE_ESTIMATED_EARNED) {
                    throw new InvalidArgumentException("{$member->name} has unknown historical SP spending; select an explicit legacy estimate policy or migrate that history.");
                }
                $catalog ??= MasteryCatalog::getProjectCatalog();
                $map = $catalog->findMap($member->actorId)
                    ?? throw new InvalidArgumentException("{$member->name} needs a current Mastery map to estimate historical SP spending.");
                $spent = $progress->pendingSpentPoints;
                foreach ($progress->unrecordedNodes as $id) {
                    $node = $map->findNode($id);
                    if ($node === null || $node->cost === null) {
                        throw new InvalidArgumentException("{$member->name} cannot estimate learned node '{$id}' without a current SP price; migrate that historical node explicitly.");
                    }
                    if ($node->cost > PHP_INT_MAX - $spent) {
                        throw new InvalidArgumentException('Estimated Mastery spending exceeds the supported integer range.');
                    }
                    $spent += $node->cost;
                }
                // Validate every history before the roster or any existing member is changed.
                $prepared = new MasteryProgress($progress->points, $progress->learned, $spent);
                $earned = $prepared->earnedPoints;
                $histories[] = ['progress' => $progress, 'points' => $progress->points, 'learned' => $progress->learned,
                    'pending' => $progress->pendingSpentPoints, 'spent' => $spent];
            }
            $totals[] = $earned;
        }
        // Quotients and remainders avoid overflowing an integer while averaging valid totals.
        $count = count($totals);
        $whole = $remainder = 0;
        foreach ($totals as $total) { $whole += intdiv($total, $count); $remainder += $total % $count; }
        return new self($whole + intdiv($remainder, $count), $histories);
    }

    public function initializePreparedHistories(): void
    {
        foreach ($this->histories as $history) {
            if ($history['progress']->points !== $history['points'] || $history['progress']->learned !== $history['learned']
                || $history['progress']->pendingSpentPoints !== $history['pending'] || $history['progress']->spentPoints !== null) {
                throw new InvalidArgumentException('Recruitment SP history changed after preparation; prepare the award again.');
            }
        }
        foreach ($this->histories as $history) { $history['progress']->initializeSpentPoints($history['spent']); }
    }
}
