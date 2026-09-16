<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

return new BattlePresentationCatalog(
  arenas: ['Twins' => new BattleArenaDefinition(1350, 720,
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)),
    [new BattlerSlot(969, 265, 143, 181)],
    [new BattlerSlot(375, 467, 173, 197), new BattlerSlot(497, 233, 197, 119)])],
  actors: ['Hero' => new BattlerArtwork('hero.png', 143, 181, 71.5, 181)],
  enemies: ['Twin' => new BattlerArtwork('twin.png', 143, 181, 71.5, 181)],
);
