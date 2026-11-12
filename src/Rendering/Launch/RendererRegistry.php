<?php

namespace Ichiloto\Engine\Rendering\Launch;

use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;

final class RendererRegistry
{
  public const string AVAILABILITY_SCHEMA = 'ichiloto.renderers/1';

  /** @var array<string, RendererDescriptor> */
  private array $descriptors = [];

  /** @param list<RendererDescriptor>|null $descriptors */
  public function __construct(?RendererExecutableResolverInterface $resolver = null, ?array $descriptors = null)
  {
    $resolver ??= new PackagedRendererExecutableResolver();
    $descriptors ??= [
      new RendererDescriptor('terminal', static fn(string $assetRoot): ?RendererRuntime => null,
        'Native Terminal', static fn(): RendererAvailability => new RendererAvailability(true)),
      new RendererDescriptor('gpui', static fn(string $assetRoot): RendererRuntime => new RendererRuntime(
        new RendererRuntimeConfig(new RendererProcessConfig([$resolver->resolve('gpui')]), $assetRoot,
          RendererRuntimeConfig::GPUI_CELL_WIDTH, RendererRuntimeConfig::GPUI_CELL_HEIGHT,
          // Optional surfaces select from the renderer's advertised capabilities after startup.
          // Merely installing a theme must not tighten the game's transport requirements.
          // Key transitions are an event subscription: requiring it is how the Game asks for
          // key presses and releases, which held field walking needs; modifier controls report Shift
          // held alone, which dashing holds.
          requiredCapabilities: [RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::TILE_BATCHES,
            RendererSessionConfig::KEY_TRANSITIONS, RendererSessionConfig::MODIFIER_CONTROLS]),
      ), 'GPUI', static function () use ($resolver): RendererAvailability {
        $resolver->resolve('gpui');
        return new RendererAvailability(true);
      }),
    ];
    foreach ($descriptors as $descriptor) {
      if (isset($this->descriptors[$descriptor->id])) {
        throw new InvalidArgumentException('Duplicate renderer ID: ' . $descriptor->id);
      }
      $this->descriptors[$descriptor->id] = $descriptor;
    }
  }

  /**
   * @return array{schema: string, renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string}
   */
  public function getReport(): array
  {
    $renderers = [];
    $availableIds = [];
    foreach ($this->descriptors as $descriptor) {
      $availability = $descriptor->getAvailability();
      $renderers[] = ['id' => $descriptor->id, 'displayName' => $descriptor->displayName,
        'available' => $availability->available, 'reason' => $availability->reason];
      if ($availability->available) { $availableIds[] = $descriptor->id; }
    }
    $preferredId = in_array('gpui', $availableIds, true) ? 'gpui'
      : (in_array('terminal', $availableIds, true) ? 'terminal' : ($availableIds[0] ?? null));
    return ['schema' => self::AVAILABILITY_SCHEMA, 'renderers' => $renderers, 'preferredId' => $preferredId];
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
