<?php

namespace Ichiloto\Engine\Rendering\Launch;

use Closure;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use InvalidArgumentException;

final readonly class RendererDescriptor
{
  /** @var Closure(string): ?RendererRuntime */
  private Closure $factory;

  /** @var (Closure(): RendererAvailability)|null */
  private ?Closure $availabilityProbe;

  public string $displayName;

  /**
   * @param callable(string): ?RendererRuntime $factory
   * @param (callable(): RendererAvailability)|null $availabilityProbe A read-only check, never the runtime factory.
   */
  public function __construct(
    public string $id,
    callable $factory,
    ?string $displayName = null,
    ?callable $availabilityProbe = null,
  )
  {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $id)) {
      throw new InvalidArgumentException('Renderer descriptors require a canonical lowercase ID.');
    }
    $this->displayName = trim($displayName ?? $id);
    if ($this->displayName === '') {
      throw new InvalidArgumentException('Renderer descriptors require a display name.');
    }
    $this->factory = Closure::fromCallable($factory);
    $this->availabilityProbe = $availabilityProbe === null ? null : Closure::fromCallable($availabilityProbe);
  }

  public function getAvailability(): RendererAvailability
  {
    if ($this->availabilityProbe === null) {
      return new RendererAvailability(false, 'Availability discovery is not supported by this renderer.');
    }
    try {
      return ($this->availabilityProbe)();
    } catch (RendererUnavailableException $error) {
      return new RendererAvailability(false, $error->getMessage());
    }
  }

  public function createRuntime(string $assetRoot): ?RendererRuntime
  {
    return ($this->factory)($assetRoot);
  }
}
