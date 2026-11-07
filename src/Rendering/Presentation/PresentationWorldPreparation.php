<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Core\FramePreparation;

/** @extends FramePreparation<PresentationWorld> */
final class PresentationWorldPreparation extends FramePreparation
{
    public function advance(int $budgetNanoseconds, bool $canPublish = true): ?PresentationWorld
    {
        return parent::advance($budgetNanoseconds, $canPublish);
    }
}
