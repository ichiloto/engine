<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;

/** One immutable lease revision. Runtime-only identity; it never enters game resources or saves. */
final readonly class AssetHoldRequest
{
  public int $serializedBytes;
  public function __construct(public string $id, public int $revision, public AssetHoldResource $resource,
    public bool $reread = false)
  {
    AssetHoldValidation::readId($id);
    AssetHoldValidation::readInteger($revision, 1, AssetHoldValidation::MAX_REVISION, AssetHoldRejection::INVALID_REVISION);
    $this->serializedBytes = AssetHoldWireSize::getPutBytes($this);
  }

  /** @param array<string, mixed> $value */
  public static function fromArray(string $id, array $value): self
  {
    AssetHoldValidation::assertKeys($value, [], ['revision', 'asset', 'sourceRect', 'base', 'reread', 'drawSize', 'canvas']);
    $revision = AssetHoldValidation::readInteger($value['revision'] ?? null, 1,
      AssetHoldValidation::MAX_REVISION, AssetHoldRejection::INVALID_REVISION);
    $resource = array_intersect_key($value, array_flip(['asset', 'sourceRect', 'base', 'drawSize', 'canvas']));
    return new self($id, $revision, AssetHoldResource::fromArray($resource),
      array_key_exists('reread', $value)
        ? AssetHoldValidation::readBoolean($value['reread'], AssetHoldRejection::SCHEMA) : false);
  }

  /** A remove returns null after validating its id; it has no value and requests no file work. */
  public static function readOperation(array $operation): ?self
  {
    $put = ($operation['op'] ?? null) === 'put';
    if (!$put && ($operation['op'] ?? null) !== 'remove') {
      AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Expected put or remove.');
    }
    AssetHoldValidation::assertKeys($operation, $put ? ['op', 'kind', 'id', 'value'] : ['op', 'kind', 'id']);
    if ($operation['kind'] !== 'asset_hold') { AssetHoldValidation::reject(AssetHoldRejection::SCHEMA, 'Expected asset_hold kind.'); }
    $id = AssetHoldValidation::readId($operation['id']);
    return $put ? self::fromArray($id, AssetHoldValidation::readObject($operation['value'], AssetHoldRejection::SCHEMA)) : null;
  }

  public static function fromCanvasImage(string $id, int $revision, CanvasImage $image, bool $reread = false): self
  {
    return new self($id, $revision, AssetHoldResource::fromCanvasImage($image), $reread);
  }

  public function toArray(): array
  {
    return ['revision' => $this->revision, ...($this->reread ? ['reread' => true] : []), ...$this->resource->toArray()];
  }

  public function createPutOperation(): array
  {
    return ['op' => 'put', 'kind' => 'asset_hold', 'id' => $this->id, 'value' => $this->toArray()];
  }
}
