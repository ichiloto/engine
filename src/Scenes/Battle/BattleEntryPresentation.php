<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePauseSkin;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;

/** Validated presentation for a single incoming battle before the scene takes ownership. */
final readonly class BattleEntryPresentation
{
  /** @param list<string> $assetPaths */
  public function __construct(
    public BattleConfig $config,
    public ?RendererRuntime $runtime,
    public ?GraphicalBattlePresentation $battlefield,
    public ?BattleCanvasLayout $layout,
    public ?BattleResultsSkin $results,
    public ?BattlePauseSkin $pause,
    public array $assetPaths,
  ) {}
}
