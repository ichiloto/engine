<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Structural wire validation; file resolution, decoding and admission belong to the renderer. */
final class AssetHoldValidation
{
  public const int MAX_LEASES = 1024;
  public const int MAX_STATUS_MESSAGE_BYTES = 1024;
  public const int MAX_TRANSACTION_OPERATIONS = 4096;
  public const int MAX_TRANSACTION_BYTES = 4194304;
  public const int MAX_REVISION = 9007199254740991;
  public const int MAX_ASSET_BYTES = 4096;

  public static function reject(AssetHoldRejection $reason, string $message): never
  {
    throw new InvalidArgumentException('asset_hold ' . $reason->value . ': ' . $message);
  }

  /** @param list<string> $required @param list<string> $optional */
  public static function assertKeys(array $value, array $required, array $optional = [],
    AssetHoldRejection $reason = AssetHoldRejection::SCHEMA): void
  {
    if (array_diff($required, array_keys($value)) !== []
      || array_diff(array_keys($value), [...$required, ...$optional]) !== []) {
      self::reject($reason, 'Required or unknown fields.');
    }
  }

  public static function readInteger(mixed $value, int $minimum, int $maximum, AssetHoldRejection $reason): int
  {
    if (!is_int($value) || $value < $minimum || $value > $maximum) {
      self::reject($reason, 'Expected an integer within ' . $minimum . '..' . $maximum . '.');
    }
    return $value;
  }

  public static function readNumber(mixed $value, float $minimum, float $maximum, AssetHoldRejection $reason): float
  {
    if ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < $minimum || $value > $maximum) {
      self::reject($reason, 'Expected a finite number within ' . $minimum . '..' . $maximum . '.');
    }
    return $value == 0 ? 0.0 : (float)$value;
  }

  public static function readBoolean(mixed $value, AssetHoldRejection $reason): bool
  {
    if (!is_bool($value)) { self::reject($reason, 'Expected a boolean.'); }
    return $value;
  }

  public static function readObject(mixed $value, AssetHoldRejection $reason): array
  {
    if ($value instanceof \stdClass) { return get_object_vars($value); }
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
      self::reject($reason, 'Expected an object.');
    }
    return $value;
  }

  public static function readId(mixed $value): string
  {
    if (!is_string($value)) { self::reject(AssetHoldRejection::INVALID_ID, 'Expected a string id.'); }
    try { CanvasValidation::id($value); }
    catch (InvalidArgumentException $error) { self::reject(AssetHoldRejection::INVALID_ID, $error->getMessage()); }
    return $value;
  }

  public static function readAsset(mixed $value): string
  {
    if (!is_string($value) || strlen($value) > self::MAX_ASSET_BYTES) {
      self::reject(AssetHoldRejection::INVALID_ASSET, 'Expected a relative asset path of at most 4096 bytes.');
    }
    try { SpriteValidation::validateAssetPath($value); }
    catch (InvalidArgumentException $error) { self::reject(AssetHoldRejection::INVALID_ASSET, $error->getMessage()); }
    return $value;
  }
}
