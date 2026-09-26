<?php

namespace Ichiloto\Engine\Rendering\Transport;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
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
    public ?bool $active = null,
    public ?int $generation = null,
    public ?int $frame = null,
    public ?bool $presented = null,
    public ?int $expectedGeneration = null,
    public ?bool $resyncRequired = null,
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
    if (in_array($type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED], true) && ! is_string($message)) {
      throw new RendererProtocolException('Renderer error or frame rejection event requires a message string.');
    }
    $active = $object->active ?? null;
    if ($type === RendererEventType::WINDOW_ACTIVATION
      && ($protocol !== RendererProtocolVersion::V2 || !is_bool($active))) {
      throw new RendererProtocolException('Window activation requires protocol 2 and a boolean active state.');
    }
    if ($type !== RendererEventType::WINDOW_ACTIVATION && property_exists($object, 'active')) {
      throw new RendererProtocolException('Only window activation events carry active state.');
    }
    $retainedFields = match ($type) {
      RendererEventType::FRAME_ACK => ['generation', 'frame', 'presented'],
      RendererEventType::FRAME_REJECTED => ['generation', 'expectedGeneration', 'resyncRequired'],
      default => [],
    };
    if (($retainedFields !== [] || $type === RendererEventType::RESIZED) && $protocol !== RendererProtocolVersion::V2) {
      throw new RendererProtocolException('Retained frame feedback and resize notifications require protocol 2.');
    }
    foreach (['generation', 'frame', 'presented', 'expectedGeneration', 'resyncRequired'] as $field) {
      if (property_exists($object, $field) && !in_array($field, $retainedFields, true)) {
        throw new RendererProtocolException('Unexpected retained frame feedback field: ' . $field . '.');
      }
    }
    $generation = $frame = $presented = $expectedGeneration = $resyncRequired = null;
    if ($type === RendererEventType::FRAME_ACK) {
      $generation = self::requireSequence($object, 'generation', 1);
      $frame = self::requireSequence($object, 'frame');
      $presented = $object->presented ?? null;
      if (!is_bool($presented)) {
        throw new RendererProtocolException('Frame acknowledgement requires a boolean presented state.');
      }
    } elseif ($type === RendererEventType::FRAME_REJECTED) {
      $generation = self::requireSequence($object, 'generation');
      $expectedGeneration = self::requireSequence($object, 'expectedGeneration');
      $resyncRequired = $object->resyncRequired ?? null;
      if ($resyncRequired !== true) {
        throw new RendererProtocolException('Frame rejection requires resyncRequired: true.');
      }
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
      in_array($type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED], true) ? $message : null,
      $protocol, $capabilities, $active, $generation, $frame, $presented, $expectedGeneration, $resyncRequired);
  }

  private static function requireSequence(stdClass $object, string $field, int $minimum = 0): int
  {
    $value = $object->$field ?? null;
    if (!is_int($value) || $value < $minimum) {
      throw new RendererProtocolException('Renderer ' . $field . ' requires an integer of at least ' . $minimum . '.');
    }
    return $value;
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
