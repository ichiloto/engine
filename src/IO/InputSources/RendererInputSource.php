<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\KeyTransition;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * Consumes only keys. Renderer lifetime and other events belong to the shared client.
 *
 * With negotiated key transitions it also reports presses, releases and
 * resets; without them it remains an event-only source and claims no release.
 */
final readonly class RendererInputSource implements HeldInputSourceInterface
{
  public function __construct(private RendererClient $client)
  {
  }

  public function poll(): ?KeyCode
  {
    $key = $this->client->pollKey();
    if ($key === null) {
      return null;
    }
    $code = self::getKeyCode($key);
    LatencyTrace::returned($key, self::class);
    return $code;
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    $this->client->resetKeys($drainBufferedInput);
  }

  public function canReportHeldState(): bool
  {
    return $this->client->supports(RendererSessionConfig::KEY_TRANSITIONS);
  }

  public function drainTransitions(): array
  {
    return array_map(static fn($event): KeyTransition => match ($event->type) {
      RendererEventType::KEY => KeyTransition::press($event->control, self::getKeyCode($event->key)),
      RendererEventType::KEY_RELEASE => KeyTransition::release($event->control),
      default => KeyTransition::reset(),
    }, $this->client->drainKeyTransitions());
  }

  private static function getKeyCode(string $key): KeyCode
  {
    return KeyCode::tryFrom($key) ?? throw new RendererProtocolException(
      'Renderer key is not an Ichiloto KeyCode: ' . json_encode(substr($key, 0, 128), JSON_INVALID_UTF8_SUBSTITUTE));
  }
}
