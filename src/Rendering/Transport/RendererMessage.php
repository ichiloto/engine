<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;
use JsonException;

/** An outbound envelope. Application payloads are opaque to the transport. */
final readonly class RendererMessage
{
  /** @param array<string, mixed> $payload */
  public function __construct(
    public RendererMessageType $type,
    public array $payload = [],
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V1,
  )
  {
    foreach (array_keys($payload) as $key) {
      if (! is_string($key) || in_array($key, ['protocol', 'type'], true)) {
        throw new InvalidArgumentException('Renderer payload keys must be strings and cannot replace the envelope.');
      }
    }
  }

  /** @throws JsonException */
  public function encode(): string
  {
    return json_encode([
      'protocol' => $this->protocol->value,
      'type' => $this->type->value,
      ...$this->payload,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  }
}
