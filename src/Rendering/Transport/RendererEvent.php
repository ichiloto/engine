<?php

namespace Ichiloto\Engine\Rendering\Transport;

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use JsonException;
use stdClass;

final readonly class RendererEvent
{
  /** @param list<string> $capabilities */
  private function __construct(
    public RendererEventType $type,
    public ?string $key = null,
    public ?string $message = null,
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V1,
    public array $capabilities = [],
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
    $protocol = $object instanceof stdClass && is_int($object->protocol ?? null)
      ? RendererProtocolVersion::tryFrom($object->protocol) : null;
    if ($protocol === null) {
      throw new RendererProtocolException('Renderer event requires supported integer protocol version 1 or 2; excerpt=' . self::excerpt($line));
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
    $capabilities = property_exists($object, 'capabilities') ? $object->capabilities : [];
    if (!is_array($capabilities) || !array_is_list($capabilities) || count($capabilities) > 32) {
      throw new RendererProtocolException('Renderer capabilities must be a bounded list of strings.');
    }
    foreach ($capabilities as $capability) {
      if (!is_string($capability) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $capability) !== 1) {
        throw new RendererProtocolException('Malformed renderer capability name.');
      }
    }
    if (count($capabilities) !== count(array_unique($capabilities))
      || ($type !== RendererEventType::READY && property_exists($object, 'capabilities'))) {
      throw new RendererProtocolException('Capabilities must be unique and only appear on ready.');
    }
    return new self($type, $type === RendererEventType::KEY ? $key : null,
      $type === RendererEventType::ERROR ? $message : null, $protocol, $capabilities);
  }

  /** @param list<string> $required */
  public function requireCapabilities(array $required): void
  {
    if (($missing = array_diff($required, $this->capabilities)) !== []) {
      throw new RendererProtocolException('Renderer did not acknowledge required capabilities: '
        . implode(', ', $missing) . '. Install an updated renderer.');
    }
  }

  private static function excerpt(string $line): string
  {
    return json_encode(substr($line, 0, 256), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
  }
}
