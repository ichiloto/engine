<?php

namespace Ichiloto\Engine\Rendering\Transport;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMetrics;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class RendererEvent
{
  /** A control identity names a physical key independently of its text, case and modifiers. */
  public const string CONTROL_PATTERN = '/^[a-z0-9_]{1,32}$/D';
  /** The Rust protocol and process transport share this whole-line ceiling, not a smaller message limit. */
  public const int MAX_TEXT_REPLY_BYTES = 4194304;

  /**
   * @param list<string> $capabilities
   * @param list<string> $layoutIds
   */
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
    /** Negotiated key transitions: the control a key or key_release event belongs to. */
    public ?string $control = null,
    /** Negotiated key transitions: true when a key event repeats an already held control. */
    public ?bool $repeat = null,
    public ?string $requestId = null,
    public ?string $layoutId = null,
    public ?TextMetrics $textMetrics = null,
    public array $layoutIds = [],
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
    if (in_array($type, [RendererEventType::TEXT_PREPARED, RendererEventType::TEXT_PREPARE_FAILED, RendererEventType::TEXT_RELEASED], true)
      && strlen($line) > self::MAX_TEXT_REPLY_BYTES) {
      throw new RendererProtocolException('Text reply exceeds the protocol 4 MiB line limit.');
    }

    $key = $object->key ?? null;
    $message = $object->message ?? null;
    if ($type === RendererEventType::KEY && (! is_string($key) || $key === '')) {
      throw new RendererProtocolException('Renderer key event requires a nonempty key string.');
    }
    if (in_array($type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED], true) && ! is_string($message)) {
      throw new RendererProtocolException('Renderer error or frame rejection event requires a message string.');
    }
    if ($type === RendererEventType::TEXT_PREPARE_FAILED && !is_string($message)) {
      throw new RendererProtocolException('Text preparation failure requires a message string.');
    }
    [$requestId, $layoutId, $textMetrics, $layoutIds] = self::requireTextReply($object, $type, $protocol);
    [$control, $repeat] = self::requireKeyTransition($object, $type, $protocol);
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
      in_array($type, [RendererEventType::ERROR, RendererEventType::FRAME_REJECTED, RendererEventType::TEXT_PREPARE_FAILED], true) ? $message : null,
      $protocol, $capabilities, $active, $generation, $frame, $presented, $expectedGeneration, $resyncRequired,
      $control, $repeat, $requestId, $layoutId, $textMetrics, $layoutIds);
  }

  /** @return array{?string, ?string, ?TextMetrics, list<string>} */
  private static function requireTextReply(stdClass $object, RendererEventType $type, RendererProtocolVersion $protocol): array
  {
    $fields = match ($type) {
      RendererEventType::TEXT_PREPARED => ['requestId', 'layoutId', 'metrics'],
      RendererEventType::TEXT_PREPARE_FAILED => ['requestId', 'message'],
      RendererEventType::TEXT_RELEASED => ['layoutIds'],
      default => [],
    };
    foreach (['requestId', 'layoutId', 'metrics', 'layoutIds', 'textMetrics'] as $field) {
      if (property_exists($object, $field) && !in_array($field, $fields, true)) {
        throw new RendererProtocolException('Unexpected text reply field: ' . $field . '.');
      }
    }
    if ($fields === []) { return [null, null, null, []]; }
    if ($protocol !== RendererProtocolVersion::V2
      || array_diff(array_keys(get_object_vars($object)), ['protocol', 'type', ...$fields]) !== []) {
      throw new RendererProtocolException('Text replies require protocol 2 and only their defined fields.');
    }
    try {
      if ($type === RendererEventType::TEXT_RELEASED) {
        $ids = $object->layoutIds ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 64) {
          throw new RendererProtocolException('Text release requires a list of at most 64 layout IDs.');
        }
        $layoutIds = [];
        foreach ($ids as $id) { $layoutIds[] = self::requireTextId($id); }
        return [null, null, null, $layoutIds];
      }
      $requestId = self::requireTextId($object->requestId ?? null);
      if ($type === RendererEventType::TEXT_PREPARE_FAILED) { return [$requestId, null, null, []]; }
      $layoutId = self::requireTextId($object->layoutId ?? null);
      if (!($object->metrics ?? null) instanceof stdClass) {
        throw new RendererProtocolException('Text preparation requires a metrics object.');
      }
      $metrics = get_object_vars($object->metrics);
      foreach (['lineBounds', 'inkBounds', 'paintBounds'] as $field) {
        if ($field === 'inkBounds' && array_key_exists($field, $metrics) && $metrics[$field] === null) { continue; }
        if (!($metrics[$field] ?? null) instanceof stdClass) {
          throw new RendererProtocolException('Text metrics require bounds objects and nullable inkBounds.');
        }
        $metrics[$field] = get_object_vars($metrics[$field]);
      }
      return [$requestId, $layoutId, TextMetrics::fromArray($metrics), []];
    } catch (InvalidArgumentException $error) {
      throw new RendererProtocolException('Invalid renderer text reply: ' . $error->getMessage(), previous: $error);
    }
  }

  private static function requireTextId(mixed $value): string
  {
    if (!is_string($value)) {
      throw new RendererProtocolException('Text reply IDs must be strings.');
    }
    CanvasValidation::id($value);
    return $value;
  }

  /**
   * Key transitions are a protocol 2 subscription. A transition key names its control and
   * whether it repeats a held one; a release names only its control; a reset carries nothing.
   *
   * @return array{?string, ?bool}
   */
  private static function requireKeyTransition(stdClass $object, RendererEventType $type, RendererProtocolVersion $protocol): array
  {
    $transition = in_array($type, [RendererEventType::KEY_RELEASE, RendererEventType::INPUT_RESET], true)
      || ($type === RendererEventType::KEY && property_exists($object, 'control'));
    if ($transition && $protocol !== RendererProtocolVersion::V2) {
      throw new RendererProtocolException('Key transitions require protocol 2.');
    }
    if ((property_exists($object, 'control') && !in_array($type, [RendererEventType::KEY, RendererEventType::KEY_RELEASE], true))
      || (property_exists($object, 'repeat') && !($type === RendererEventType::KEY && property_exists($object, 'control')))) {
      throw new RendererProtocolException('Only transition key and key_release events carry control identity; only transition keys repeat.');
    }
    if (!$transition || $type === RendererEventType::INPUT_RESET) {
      return [null, null];
    }
    $control = $object->control ?? null;
    if (!is_string($control) || preg_match(self::CONTROL_PATTERN, $control) !== 1) {
      throw new RendererProtocolException('Key transitions require a control identity of 1..32 lowercase letters, digits or underscores.');
    }
    $repeat = $object->repeat ?? null;
    if ($type === RendererEventType::KEY && !is_bool($repeat)) {
      throw new RendererProtocolException('A transition key event requires a boolean repeat state.');
    }
    return [$control, $repeat];
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
