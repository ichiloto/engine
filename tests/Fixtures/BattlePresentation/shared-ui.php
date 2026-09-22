<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

return new BattlePresentationCatalog([], [], [], ui: new BattleCanvasLayout(1350, 720,
  skin: new BattleUiSkin(
    array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting'],
      new CanvasNineSlice('skin.png', new SpriteSourceRect(0, 0, 32, 48))),
    array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'],
      PresentationColor::rgb(200, 200, 200)),
  ), feedbackArea: new CanvasRectangle(0, 80, 1350, 452)));
