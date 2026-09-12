<?php

namespace Ichiloto\Engine\Rendering\Launch;

use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use InvalidArgumentException;

final class RendererRegistry
{
  /** @var array<string, RendererDescriptor> */
  private array $descriptors = [];

  /** @param list<RendererDescriptor>|null $descriptors */
  public function __construct(?RendererExecutableResolverInterface $resolver = null, ?array $descriptors = null)
  {
    $resolver ??= new PackagedRendererExecutableResolver();
    $descriptors ??= [
      new RendererDescriptor('terminal', static fn(string $assetRoot): ?RendererRuntime => null),
      new RendererDescriptor('gpui', static fn(string $assetRoot): RendererRuntime => new RendererRuntime(
        new RendererRuntimeConfig(new RendererProcessConfig([$resolver->resolve('gpui')]), $assetRoot, 10, 20),
      )),
    ];
    foreach ($descriptors as $descriptor) {
      if (isset($this->descriptors[$descriptor->id])) {
        throw new InvalidArgumentException('Duplicate renderer ID: ' . $descriptor->id);
      }
      $this->descriptors[$descriptor->id] = $descriptor;
    }
  }

  /** @return list<RendererDescriptor> */
  public function all(): array
  {
    return array_values($this->descriptors);
  }

  public function find(string $id): ?RendererDescriptor
  {
    return $this->descriptors[strtolower(trim($id))] ?? null;
  }

  public function require(string $id): RendererDescriptor
  {
    return $this->find($id) ?? throw new InvalidArgumentException(sprintf(
      'Unknown renderer "%s". Valid renderer IDs: %s.', $id, implode(', ', array_keys($this->descriptors)),
    ));
  }
}
