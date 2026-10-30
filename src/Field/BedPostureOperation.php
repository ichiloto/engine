<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Field\Enumerations\BedPosture;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Accessibility;
use InvalidArgumentException;
use RuntimeException;

/** A cancellable posture change: old state stays authoritative until the exit and pose commit. */
final class BedPostureOperation implements EventPendingOperationInterface
{
    private readonly NpcBed $bed;
    private readonly BedPosture $before;
    private readonly ?EventExecutionSession $session;
    private float $elapsed = 0;
    private readonly float $duration;
    private bool $complete = false;

    public function __construct(private readonly GameScene $scene, private readonly Npc $npc,
        private readonly BedPosture $target, ?float $seconds = null)
    {
        if ($seconds !== null && (!is_finite($seconds) || $seconds < 0 || $seconds > 30)) {
            throw new InvalidArgumentException('Bed transition seconds must be finite within 0..30.');
        }
        $this->bed = $npc->bed ?? throw new RuntimeException('The selected NPC has no bed binding.');
        $this->bed->validateDestination($scene, $target);
        $this->before = $this->bed->posture;
        $definition = $this->bed->definition->transitions[$target->value] ?? null;
        $this->duration = $seconds ?? ($definition instanceof FieldPoseAnimation ? $definition->durationSeconds : 0);
        $this->session = $scene->eventInterpreter?->activeSession();
        $this->session?->claimMovementSubject($npc);
        try {
            if ($this->session?->cinematic !== null) { $scene->cinematicStage?->captureSubject($npc); }
            $this->bed->beginTransition($target);
        } catch (\Throwable $error) {
            $this->session?->releaseMovementSubject($npc);
            throw $error;
        }
    }

    public function update(float $deltaSeconds): bool
    {
        if ($this->complete) { return true; }
        if (!is_finite($deltaSeconds) || $deltaSeconds < 0) { throw new InvalidArgumentException('Bed transition delta must be finite and non-negative.'); }
        $this->bed->requireObject($this->scene);
        if ($this->bed->posture !== $this->before) { throw new RuntimeException('The bed posture changed during its owned transition.'); }
        $this->elapsed = min($this->duration, $this->elapsed + $deltaSeconds);
        $this->bed->advanceTransition($deltaSeconds);
        if ($this->elapsed < $this->duration && !Accessibility::prefersReducedMotion()) { return false; }
        $this->bed->commitPosture($this->target);
        // Posture is a committed world-state write; later cinematic cleanup restores this new transform.
        $this->scene->cinematicStage?->commitSubjectTransforms($this->npc);
        $this->complete = true;
        $this->session?->releaseMovementSubject($this->npc);
        $this->scene->requestFieldPresentationReconciliation();
        return true;
    }

    public function cancel(): void
    {
        if ($this->complete) { return; }
        $this->bed->cancelTransition();
        $this->session?->releaseMovementSubject($this->npc);
        $this->complete = true;
        $this->scene->requestFieldPresentationReconciliation();
    }
}
