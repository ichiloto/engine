<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use LogicException;

/** Canonical typed-operation bytes defined by the shared hold transaction contract, without file I/O. */
final class AssetHoldWireSize
{
  /** @param array<string|int, AssetHoldRequest> $requests */
  public static function assertRequestsFitReset(array $requests): void
  {
    $bytes = array_sum(array_map(static fn(AssetHoldRequest $request): int => $request->serializedBytes, $requests));
    if (count($requests) > AssetHoldValidation::MAX_TRANSACTION_OPERATIONS || $bytes > AssetHoldValidation::MAX_TRANSACTION_BYTES) {
      AssetHoldValidation::reject(AssetHoldRejection::TRANSACTION_LIMIT,
        'Desired resource metadata exceeds the atomic reset transaction limit.');
    }
  }

  public static function getPutBytes(AssetHoldRequest $request): int
  {
    $value = ['revision' => $request->revision, 'reread' => $request->reread, ...$request->resource->toArray()];
    $adjustment = 0;
    $countFractions = static function (array $data) use (&$countFractions, &$adjustment): array {
      foreach ($data as $key => $number) {
        if (is_array($number)) { $data[$key] = $countFractions($number); }
        elseif (is_float($number)) {
          $adjustment += self::getNumberBytes($number) - 1;
          $data[$key] = 0;
        }
      }
      return $data;
    };
    $operation = $countFractions(['op' => 'put', 'kind' => 'asset_hold', 'id' => $request->id, 'value' => $value]);
    return strlen(json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR)) + $adjustment;
  }

  /** The brightness/fade boundary keeps its existing fraction-only validation. */
  public static function getFractionBytes(float $value): int
  {
    if (!is_finite($value) || $value < 0 || $value > 1) {
      throw new LogicException('Hold wire fractions require finite values within 0..1.');
    }
    return self::getNumberBytes($value);
  }

  /** Length of the backend's shortest binary64 decimal, including logical drawing extents.
   * Fixed notation covers decimal exponents -5..15 (serde_json's pinned zmij formatter).
   * Explicit locale-free precision leaves machine locale and serialize_precision untouched.
   */
  public static function getNumberBytes(float $value): int
  {
    if (!is_finite($value)) { throw new LogicException('Hold wire numbers require finite values.'); }
    $negative = (ord(pack('E', $value)[0]) & 128) !== 0;
    if ($value == 0) { return $negative ? 4 : 3; }
    $value = abs($value);
    for ($precision = 1; $precision <= 17; $precision++) {
      $parts = explode('E', sprintf('%.*H', $precision, $value));
      $mantissa = explode('.', $parts[0]);
      $allDigits = implode('', $mantissa);
      $digits = ltrim($allDigits, '0');
      $exponent = (int)($parts[1] ?? 0) + strlen($mantissa[0]) - (strlen($allDigits) - strlen($digits)) - 1;
      $coefficient = (int)str_pad(rtrim($digits, '0'), $precision, '0');
      $power = $exponent - $precision + 1;
      // A neighbouring decimal can round-trip when the nearest decimal cannot.
      foreach ([$coefficient, $coefficient - 1, $coefficient + 1] as $candidate) {
        if ($candidate > 0 && (float)($candidate . 'E' . $power) === $value) {
          $whole = (string)$candidate;
          $length = strlen(rtrim($whole, '0'));
          $exponent = $power + strlen($whole) - 1;
          if ($exponent >= -5 && $exponent <= 15) {
            $bytes = $exponent < 0 ? 1 - $exponent + $length
              : ($exponent + 1 >= $length ? $exponent + 3 : $length + 1);
          } else { $bytes = $length + (int)($length > 1) + 2 + strlen((string)abs($exponent)); }
          return $bytes + (int)$negative;
        }
      }
    }
    throw new LogicException('A binary64 number has no round-tripping decimal within 17 significant digits.');
  }
}
