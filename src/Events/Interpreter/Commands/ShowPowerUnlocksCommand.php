<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Progression\PowerUnlockShowcase;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\UI\Modal\NoticeSequenceOperation;
use RuntimeException;

/** Presents actual granted powers and keeps the field event pending through acknowledgement. */
final class ShowPowerUnlocksCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    if ($context->battle !== null) { throw new RuntimeException('show_power_unlocks runs only in field scripts.'); }
    $definition = ScriptCommandCatalog::createEngineCatalog()->findDefinition('show_power_unlocks');
    $problems = $definition?->findProblems($command) ?? ['Missing power showcase command definition.'];
    if ($problems !== []) { throw new RuntimeException(implode(' ', $problems)); }
    $scene = $context->scene;
    $state = $scene->gameState ?? throw new RuntimeException('show_power_unlocks requires world state.');
    $actor = null;
    if (isset($command['actor'])) {
      $matches = array_values(array_filter($scene->party?->members->toArray() ?? [],
        static fn($member): bool => $member instanceof Character && $member->actorId === $command['actor']));
      if (count($matches) !== 1) { throw new RuntimeException('Select one current party actor for the power showcase.'); }
      $actor = $matches[0];
    }
    $notices = PowerUnlockShowcase::getNotices(WorldAbilityCatalog::getProjectCatalog(),
      array_column($command['abilities'] ?? [], 'ability'), $state, $scene->party, $actor,
      $command['includeCrisis'] ?? false, $command['announcement'] ?? '');
    return ScriptCommandOutcome::waitFor(new NoticeSequenceOperation($scene->getGame()->modalManager, $notices));
  }
}
