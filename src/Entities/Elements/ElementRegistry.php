<?php

namespace Ichiloto\Engine\Entities\Elements;

use Ichiloto\Engine\Battle\Resolution\CombatCalibration;
use Ichiloto\Engine\Entities\Enumerations\ElementType;
use InvalidArgumentException;

/** Canonical project element identities and affinity validation. */
final class ElementRegistry
{
  /** @var array<string, string> lowercase identity to canonical identity */
  private static array $elements = [];

  /**
   * Configures the canonical project vocabulary, falling back to Engine defaults.
   *
   * @param string[] $elements
   */
  public static function configure(array $elements = []): void
  {
    $elements = $elements === []
      ? array_map(static fn(ElementType $element): string => $element->value, ElementType::cases())
      : $elements;
    $canonical = [];

    foreach ($elements as $element) {
      if (! is_string($element) || trim($element) === '') {
        throw new InvalidArgumentException('Element identities must be non-empty strings.');
      }

      $identity = trim($element);
      $key = strtolower($identity);

      if (isset($canonical[$key])) {
        throw new InvalidArgumentException(sprintf('Duplicate canonical element identity: %s.', $identity));
      }

      $canonical[$key] = $identity;
    }

    self::$elements = $canonical;
  }

  /** @return string[] */
  public static function identities(): array
  {
    self::ensureConfigured();

    return array_values(self::$elements);
  }

  public static function canonicalize(?string $element): ?string
  {
    if ($element === null || trim($element) === '') {
      return null;
    }

    self::ensureConfigured();
    $identity = self::$elements[strtolower(trim($element))] ?? null;

    if ($identity === null) {
      throw new InvalidArgumentException(sprintf('Unknown element identity: %s.', trim($element)));
    }

    return $identity;
  }

  /**
   * @param array<mixed, mixed> $affinities
   * @return array<string, float>
   */
  public static function normalizeAffinities(array $affinities): array
  {
    $normalized = [];

    foreach ($affinities as $element => $factor) {
      if (! is_string($element) || ! is_numeric($factor)) {
        throw new InvalidArgumentException('Element affinities require canonical element keys and numeric factors.');
      }

      $identity = self::canonicalize($element);
      assert($identity !== null);
      $value = floatval($factor);

      if (! is_finite($value)
        || $value < -CombatCalibration::ORDINARY_WEAKNESS_CEILING
        || $value > CombatCalibration::ORDINARY_WEAKNESS_CEILING) {
        throw new InvalidArgumentException(sprintf('Invalid affinity factor for %s.', $identity));
      }

      $key = strtolower($identity);
      if (isset($normalized[$key])) {
        throw new InvalidArgumentException(sprintf('Duplicate affinity for %s.', $identity));
      }

      $normalized[$identity] = $value;
    }

    return $normalized;
  }

  private static function ensureConfigured(): void
  {
    if (self::$elements === []) {
      self::configure();
    }
  }
}
