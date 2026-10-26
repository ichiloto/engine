<?php

namespace Ichiloto\Engine\Rendering\Presentation;

interface RetainedWorldProviderInterface
{
    public function getPresentationWorld(): ?PresentationWorld;
}
