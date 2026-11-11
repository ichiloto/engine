<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Util\Stores\{ActorStore, ItemStore};

/** Public dependencies for a reader fixture; no native bootstrap or global loader replacement. */
function createTestGameLoader(Game $game, ?ActorStore $actors = null, ?ItemStore $items = null, ?string $assetRoot = null): GameLoader
{
    $items ??= new class extends ItemStore { public function __construct() {} };
    return new GameLoader($game, $actors ?? new ActorStore(definitions: []), $items,
        $assetRoot ?? getTestTemporaryDirectory());
}
