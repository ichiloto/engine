<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Closure;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Events\Interpreter\EventPlayedDialoguePresentationInterface;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

/** One event-owned Skit, advanced on field ticks and cancelled with its event lane. */
final class SkitPlaybackOperation implements EventPendingOperationInterface
{
    private int $nextBeat = 0;
    private bool $opened = false;
    private bool $finished = false;
    private readonly DialoguePlayback $playback;

    /** @param Closure(): bool $isInterrupted @param Closure(bool): void $onFinish */
    public function __construct(
        private readonly SkitPlaybackPlan $plan,
        private readonly EventPlayedDialoguePresentationInterface $presentation,
        private readonly ?AudioManager $audio,
        private readonly Closure $isInterrupted,
        private readonly Closure $onFinish,
    ) {
        $this->playback = new DialoguePlayback($audio);
    }

    public function update(float $deltaSeconds): bool
    {
        if ($this->finished) { return true; }
        try {
            if (($this->isInterrupted)()) {
                throw new RuntimeException("Skit {$this->plan->id} was interrupted before completion.");
            }
            if (!$this->opened) {
                $this->playback->beginConversation();
                $this->opened = true;
                $this->playCue('open');
                return $this->beginNextBeat();
            }
            $this->presentation->update();
            $this->presentation->render();
            if (!$this->presentation->isComplete()) { return false; }
            $this->playback->finishLine();
            $this->presentation->reset();
            return $this->beginNextBeat();
        } catch (Throwable $error) {
            try { $this->cancel(); }
            catch (Throwable $cleanup) { Debug::warn("Skit {$this->plan->id} cleanup failed: " . $cleanup->getMessage()); }
            throw $error;
        }
    }

    private function beginNextBeat(): bool
    {
        $beat = $this->plan->beats[$this->nextBeat] ?? null;
        if ($beat === null) {
            $this->finish(true);
            return true;
        }
        ++$this->nextBeat;
        $this->playback->beginLine($beat['voicePath'], $this->plan->musicDuck);
        $this->presentation->beginPlayedDialogue($beat['text'], $beat['speaker'], $beat['presentation'],
            $this->playback, $this->plan->speed);
        return false;
    }

    public function cancel(): void
    {
        $this->finish(false);
    }

    private function finish(bool $completed): void
    {
        if ($this->finished) { return; }
        $this->finished = true;
        try {
            $this->presentation->reset();
        } finally {
            try {
                $this->playback->finishConversation();
            } finally {
                try {
                    if ($this->opened && !($this->isInterrupted)()) { $this->playCue('close'); }
                } finally {
                    ($this->onFinish)($completed);
                }
            }
        }
    }

    private function playCue(string $cue): void
    {
        if (isset($this->plan->sounds[$cue])) { $this->audio?->playSoundEffect($this->plan->sounds[$cue]); }
    }
}
