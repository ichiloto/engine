<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use RuntimeException;

/** Story participation belongs to the original, saved Party, never a staged clone. */
final class PartyParticipationCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    if ($context->battle !== null) { throw new RuntimeException('Party participation changes run only in field scripts.'); }
    $party = $context->scene->party ?? throw new RuntimeException('Party participation requires a configured party.');
    switch ($command['type']) {
      case 'set_party_size':
        if (!is_int($command['size'] ?? null)) { throw new RuntimeException('Party size must be an integer.'); }
        $party->setBattleSize($command['size']);
        break;
      case 'select_party':
        $members = $command['members'] ?? null;
        if (!is_array($members) || !array_is_list($members)
          || array_any($members, static fn($entry): bool => !is_array($entry) || !is_string($entry['actor'] ?? null))) {
          throw new RuntimeException('Select party requires a list of actor references.');
        }
        $party->selectParticipants(array_column($members, 'actor'));
        break;
      case 'regroup_party':
        $party->regroupParticipants();
        break;
      default:
        throw new RuntimeException('Unknown party participation command.');
    }
    return ScriptCommandOutcome::complete();
  }
}
