<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

/** A scene can retain the last displayed frame while preparing its replacement. */
interface SceneFrameReadinessInterface
{
    public function canPresentSceneFrame(): bool;
}
