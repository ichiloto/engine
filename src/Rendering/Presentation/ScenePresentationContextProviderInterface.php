<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

/** Optional host context; null preserves the scene's normal runtime presentation owners. */
interface ScenePresentationContextProviderInterface
{
    public function getPresentationContext(): ?ScenePresentationContext;
}
