<?php

namespace Ichiloto\Engine\Rendering\Launch;

interface RendererExecutableResolverInterface
{
  /** Resolve an installed implementation, never a user-supplied executable. */
  public function resolve(string $rendererId): string;
}
