<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use RuntimeException;

final class SelectConversationGroupCommand implements ScriptCommandHandlerInterface
{
    public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
    {
        if ($context->battle !== null || !is_string($command['group'] ?? null) || trim($command['group']) === '') {
            throw new RuntimeException('select_conversation_group requires a field cinematic and a declared group.');
        }
        ($context->scene->cinematicController ?? throw new RuntimeException('No cinematic controller is available.'))
            ->selectConversationGroup($command['group']);
        return ScriptCommandOutcome::complete();
    }
}
