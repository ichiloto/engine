<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;

/** A cooperative dialogue surface sharing a conversation's voice and Auto lifecycle. */
interface EventPlayedDialoguePresentationInterface extends EventDialoguePresentationInterface
{
    public function beginPlayedDialogue(string $text, string $speaker, DialogueContext $context,
        DialoguePlayback $playback, float $charactersPerSecond): void;
}
