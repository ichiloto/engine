<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

/** Renderer-owned admission result for one immutable request and source attempt. */
final readonly class AssetHoldStatus
{
  public function __construct(public string $id, public int $revision, public int $attempt,
    public AssetHoldState $state, public ?AssetHoldReason $reason = null, public ?string $message = null,
    public ?int $regionBytes = null, public ?int $baseBytes = null)
  {
    AssetHoldValidation::readId($id);
    if ($message !== null && (strlen($message) > AssetHoldValidation::MAX_STATUS_MESSAGE_BYTES
      || preg_match('//u', $message) !== 1)) {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Hold status messages require at most 1024 UTF-8 bytes.');
    }
    AssetHoldValidation::readInteger($revision, 1, AssetHoldValidation::MAX_REVISION, AssetHoldRejection::SCHEMA);
    AssetHoldValidation::readInteger($attempt, 1, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    $needsBytes = in_array($state, [AssetHoldState::PENDING, AssetHoldState::HELD], true)
      || $reason === AssetHoldReason::OVER_BUDGET;
    if ($needsBytes || $regionBytes !== null || $baseBytes !== null) {
      AssetHoldValidation::readInteger($regionBytes, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
      AssetHoldValidation::readInteger($baseBytes, 0, PHP_INT_MAX, AssetHoldRejection::SCHEMA);
    }
    $validReason = match ($state) {
      AssetHoldState::PENDING, AssetHoldState::HELD => $reason === null && $message === null,
      AssetHoldState::REFUSED => $reason !== null && $reason !== AssetHoldReason::CHANGED,
      AssetHoldState::ENDED => $reason === AssetHoldReason::CHANGED && $message === null
        && $regionBytes === null && $baseBytes === null,
    };
    if (!$validReason) { AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Hold status fields do not match its state.'); }
  }

  public static function fromArray(array $value): self
  {
    $schema = AssetHoldRejection::SCHEMA;
    AssetHoldValidation::assertKeys($value, ['id', 'revision', 'attempt', 'state'],
      ['reason', 'message', 'regionBytes', 'baseBytes']);
    $state = is_string($value['state']) ? AssetHoldState::tryFrom($value['state']) : null;
    $reason = null;
    if (array_key_exists('reason', $value)) {
      $reason = is_string($value['reason']) ? AssetHoldReason::tryFrom($value['reason']) : null;
      if ($reason === null) { AssetHoldValidation::reject($schema, 'Unknown hold reason.'); }
    }
    if ($state === null || (array_key_exists('message', $value) && !is_string($value['message']))) {
      AssetHoldValidation::reject($schema, 'Unknown hold state or non-string message.');
    }
    $readBytes = static fn(string $key): ?int => array_key_exists($key, $value)
      ? AssetHoldValidation::readInteger($value[$key], 0, PHP_INT_MAX, $schema) : null;
    return new self(AssetHoldValidation::readId($value['id']),
      AssetHoldValidation::readInteger($value['revision'], 1, AssetHoldValidation::MAX_REVISION, $schema),
      AssetHoldValidation::readInteger($value['attempt'], 1, PHP_INT_MAX, $schema), $state, $reason,
      $value['message'] ?? null, $readBytes('regionBytes'), $readBytes('baseBytes'));
  }
}
