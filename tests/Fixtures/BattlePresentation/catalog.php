<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

return new BattlePresentationCatalog(
  arenas: ['arena.test' => new BattleArenaDefinition('Test scene',
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)))],
  ui: new BattleCanvasLayout(1350, 720, partySlots: [new BattlerSlot(969, 265, 143, 181)]),
  defaultArena: 'arena.test',
  actors: ['Hero' => new BattlerArtwork('hero.png', 143, 181, 71.5, 181)],
  enemies: ['Twin' => new BattlerArtwork('twin.png', 143, 181, 71.5, 181)],
);
