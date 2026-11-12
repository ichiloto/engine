<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use RuntimeException;

/** Commits one reached source-owned destination on the real current NPC. */
final class CommitNpcPlacementCommand implements ScriptCommandHandlerInterface
{
    public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
    {
        if ($context->battle !== null || !is_string($command['npc'] ?? null) || !is_string($command['placement'] ?? null)) {
            throw new RuntimeException('commit_npc_placement requires a current field NPC and declared placement.');
        }
        $npc = $context->scene->npcManager?->findPresentById($command['npc'])
            ?? throw new RuntimeException('The selected NPC is not present.');
        $placement = $npc->placementState ?? throw new RuntimeException('The NPC has no declared named placements.');
        $session = $context->scene->eventInterpreter?->activeSession();
        $session?->claimMovementSubject($npc);
        try { $placement->commitPlacement($context->scene, $command['placement']); }
        finally { $session?->releaseMovementSubject($npc); }
        return ScriptCommandOutcome::complete();
    }
}
