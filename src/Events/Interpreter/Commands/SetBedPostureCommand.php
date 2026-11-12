<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Field\BedPostureOperation;
use Ichiloto\Engine\Field\Enumerations\BedPosture;
use RuntimeException;

final class SetBedPostureCommand implements ScriptCommandHandlerInterface
{
    public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
    {
        if ($context->battle !== null) { throw new RuntimeException('set_bed_posture runs only in field scripts.'); }
        $id = $command['npc'] ?? null;
        $posture = $command['posture'] ?? null;
        if (!is_string($id) || trim($id) === '' || !is_string($posture) || ($posture = BedPosture::tryFrom($posture)) === null
            || (isset($command['seconds']) && !is_int($command['seconds']) && !is_float($command['seconds']))) {
            throw new RuntimeException('set_bed_posture requires an NPC, lying/sitting/standing posture and optional numeric seconds.');
        }
        $npc = $context->scene->npcManager?->findPresentById($id)
            ?? throw new RuntimeException('The selected bed occupant is not present.');
        if ($npc->bed === null) { throw new RuntimeException('The selected NPC has no bed binding.'); }
        $npc->bed->validateDestination($context->scene, $posture);
        if ($npc->bed->posture === $posture) { return ScriptCommandOutcome::complete(); }
        $operation = new BedPostureOperation($context->scene, $npc, $posture,
            isset($command['seconds']) ? (float)$command['seconds'] : null);
        try { return $operation->update(0) ? ScriptCommandOutcome::complete() : ScriptCommandOutcome::waitFor($operation); }
        catch (\Throwable $error) { $operation->cancel(); throw $error; }
    }
}
