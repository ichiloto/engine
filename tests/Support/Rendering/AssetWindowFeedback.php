<?php

use Ichiloto\Engine\Rendering\Assets\{AssetHoldFeedback, AssetHoldLeases};

function replyAssetWindow(AssetHoldLeases $leases, array $requests, string $state = 'held', int $attempt = 1): void
{
  $leases->applyFeedback(AssetHoldFeedback::fromArray(['holds' => array_map(static fn($r): array => [
    'id' => $r->id, 'revision' => $r->revision, 'attempt' => $attempt, 'state' => $state,
    ...($state === 'ended' ? ['reason' => 'changed'] : ['regionBytes' => 4096, 'baseBytes' => 0]),
    ...($state === 'refused' ? ['reason' => 'over_budget'] : [])], $requests),
    'holdCapacity' => createTestAssetHoldCapacity(0, 0)]));
}
