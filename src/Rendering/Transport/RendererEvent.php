<?php

namespace Ichiloto\Engine\Rendering\Transport;

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use JsonException;
use stdClass;

final readonly class RendererEvent
{
  private function __construct(
    public RendererEventType $type,
    public ?string $key = null,
    public ?string $message = null,
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V1,
  )
  {
  }

  public static function fromJson(string $line): self
  {
    try {
      $object = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      throw new RendererProtocolException('Malformed renderer JSON: ' . $error->getMessage()
        . '; excerpt=' . self::excerpt($line), previous: $error);
    }
    if (! $object instanceof stdClass || ($object->protocol ?? null) !== RendererProtocolVersion::V1->value) {
      throw new RendererProtocolException('Renderer event requires integer protocol version 1; excerpt=' . self::excerpt($line));
    }
    $type = is_string($object->type ?? null) ? RendererEventType::tryFrom($object->type) : null;
    if ($type === null) {
      throw new RendererProtocolException('Missing or unsupported renderer event type; excerpt=' . self::excerpt($line));
    }

    $key = $object->key ?? null;
    $message = $object->message ?? null;
    if ($type === RendererEventType::KEY && (! is_string($key) || $key === '')) {
      throw new RendererProtocolException('Renderer key event requires a nonempty key string.');
    }
    if ($type === RendererEventType::ERROR && ! is_string($message)) {
      throw new RendererProtocolException('Renderer error event requires a message string.');
    }
    return new self($type, $type === RendererEventType::KEY ? $key : null,
      $type === RendererEventType::ERROR ? $message : null);
  }

  private static function excerpt(string $line): string
  {
    return json_encode(substr($line, 0, 256), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
  }
}
