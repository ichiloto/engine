<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use RuntimeException;
use Ichiloto\Engine\Entities\RecruitmentLevelPolicy;

/** Field recruitment uses the same current actor factory as game start and save restoration. */
final class RecruitActorCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    if ($context->battle !== null) { throw new RuntimeException('recruit_actor runs only in field scripts.'); }
    $party = $context->scene->party ?? throw new RuntimeException('recruit_actor requires a configured party.');
    $reference = $command['actor'] ?? null;
    if (!is_string($reference) || trim($reference) === '') {
      throw new RuntimeException('recruit_actor requires an actor reference.');
    }
    $store = ConfigStore::get(ActorStore::class);
    if (!$store instanceof ActorStore) { throw new RuntimeException('recruit_actor requires the project actor catalogue.'); }
    $policy = $command['levelPolicy'] ?? RecruitmentLevelPolicy::AUTHORED->value;
    if (!is_string($policy) || ($policy = RecruitmentLevelPolicy::tryFrom($policy)) === null) {
      throw new RuntimeException('recruit_actor.levelPolicy must be authored or partyAverage.');
    }
    $party->recruitActor($store->require($reference, 'recruiting a party member'), $policy);
    return ScriptCommandOutcome::complete();
  }
}
