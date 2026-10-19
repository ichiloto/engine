<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;

/** Optional graphical metadata; legacy text-only adapters retain their existing contract. */
interface EventDialoguePresentationInterface extends EventPresentationInterface
{
  public function beginDialogue(string $text, string $speaker, DialogueContext $context): void;
}
