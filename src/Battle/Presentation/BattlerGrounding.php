<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * How one battler meets the surface in a graphical battle: its elevation, in
 * reference heights (positive hovers above its feet point, negative sits that
 * far below the surface line, which hides the art beneath it; none takes the
 * theme's height for air enemies, else zero), what is drawn where it meets
 * the surface ({@see BattlerContact}; none takes the default for its habitat
 * and elevation), whether its artwork already paints its own shadow (so the
 * Engine never draws a second, and the art keeps the height it was painted
 * at), and the contact's width and strength against the theme's. Bound per
 * identity in battlers.php ({@see BattlerBindings}); presentation only.
 */
final readonly class BattlerGrounding
{
  private const array KEYS = ['elevation', 'contact', 'bakedShadow', 'contactWidth', 'contactOpacity'];

  public function __construct(
    public ?float $elevation = null,
    public ?BattlerContact $contact = null,
    public bool $bakedShadow = false,
    public float $contactWidth = 1.0,
    public float $contactOpacity = 1.0,
  ) {
    if ($elevation !== null && (!is_finite($elevation) || abs($elevation) > 4)) {
      throw new InvalidArgumentException('A battler\'s elevation is within -4..4 reference heights.');
    }
    if (!is_finite($contactWidth) || $contactWidth <= 0 || $contactWidth > 4
      || !is_finite($contactOpacity) || $contactOpacity < 0 || $contactOpacity > 4) {
      throw new InvalidArgumentException('A battler\'s contact scales its width within 0..4 and its strength within 0..4 of the theme\'s.');
    }
    if ($bakedShadow && (($elevation ?? 0.0) !== 0.0 || !in_array($contact, [null, BattlerContact::SHADOW], true))) {
      throw new InvalidArgumentException('Artwork that paints its own shadow stands at the height it was painted, with that shadow: give it an elevation or another contact once its art is shadow-free.');
    }
  }

  /** @throws InvalidArgumentException Naming the entry, by its path in the file. */
  public static function fromArray(mixed $data, string $where): self
  {
    if (!is_array($data) || array_diff(array_keys($data), self::KEYS) !== []) {
      throw new InvalidArgumentException(sprintf('%s: expected %s.', $where, implode(', ', self::KEYS)));
    }
    $number = static function (string $key) use ($data, $where): ?float {
      if (!array_key_exists($key, $data)) { return null; }
      return is_int($data[$key]) || is_float($data[$key]) ? (float) $data[$key]
        : throw new InvalidArgumentException("{$where}.{$key}: expected a number.");
    };
    $contact = null;
    if (array_key_exists('contact', $data)) {
      $contact = (is_string($data['contact']) ? BattlerContact::tryFrom($data['contact']) : null)
        ?? throw new InvalidArgumentException(sprintf('%s.contact: expected one of %s.', $where,
          implode(', ', array_column(BattlerContact::cases(), 'value'))));
    }
    if (array_key_exists('bakedShadow', $data) && !is_bool($data['bakedShadow'])) {
      throw new InvalidArgumentException("{$where}.bakedShadow: expected true or false.");
    }
    try {
      return new self($number('elevation'), $contact, $data['bakedShadow'] ?? false,
        $number('contactWidth') ?? 1.0, $number('contactOpacity') ?? 1.0);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error);
    }
  }

  /** @return array<string, bool|float|string> The authored form, leaving out what reading would assume. */
  public function toArray(): array
  {
    return array_filter(['elevation' => $this->elevation, 'contact' => $this->contact?->value,
      'bakedShadow' => $this->bakedShadow ?: null,
      'contactWidth' => $this->contactWidth === 1.0 ? null : $this->contactWidth,
      'contactOpacity' => $this->contactOpacity === 1.0 ? null : $this->contactOpacity], static fn($value): bool => $value !== null);
  }
}
