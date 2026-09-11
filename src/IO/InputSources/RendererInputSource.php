<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;

/** Consumes only keys. Renderer lifetime and other events belong to the shared client. */
final readonly class RendererInputSource implements InputSourceInterface
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
    return KeyCode::tryFrom($key) ?? throw new RendererProtocolException(
      'Renderer key is not an Ichiloto KeyCode: ' . json_encode(substr($key, 0, 128), JSON_INVALID_UTF8_SUBSTITUTE));
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    $this->client->resetKeys($drainBufferedInput);
  }
}
