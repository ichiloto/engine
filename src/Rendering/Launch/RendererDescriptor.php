<?php

namespace Ichiloto\Engine\Rendering\Launch;

use Closure;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use InvalidArgumentException;

final readonly class RendererDescriptor
{
  /** @var Closure(string): ?RendererRuntime */
  private Closure $factory;

  /** @param callable(string): ?RendererRuntime $factory */
  public function __construct(public string $id, callable $factory)
  {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $id)) {
      throw new InvalidArgumentException('Renderer descriptors require a canonical lowercase ID.');
    }
    $this->factory = Closure::fromCallable($factory);
  }

  public function createRuntime(string $assetRoot): ?RendererRuntime
  {
    return ($this->factory)($assetRoot);
  }
}
