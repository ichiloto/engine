<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * How one battler meets the ground in a graphical battle: how high its body
 * hovers over its feet point (in reference heights; none takes the theme's
 * default for its habitat, a flier's height or zero) and who draws its
 * contact shadow, with its width and strength against the theme's. Bound per
 * identity in battlers.php ({@see BattlerBindings}); presentation only.
 */
final readonly class BattlerGrounding
{
  private const array KEYS = ['hover', 'shadow', 'shadowWidth', 'shadowOpacity'];

  public function __construct(
    public ?float $hover = null,
    public BattlerShadowSource $shadow = BattlerShadowSource::ENGINE,
    public float $shadowWidth = 1.0,
    public float $shadowOpacity = 1.0,
  ) {
    if ($hover !== null && (!is_finite($hover) || $hover < 0 || $hover > 4)) {
      throw new InvalidArgumentException('A battler hovers between 0 and 4 reference heights.');
    }
    if (!is_finite($shadowWidth) || $shadowWidth <= 0 || $shadowWidth > 4
      || !is_finite($shadowOpacity) || $shadowOpacity < 0 || $shadowOpacity > 4) {
      throw new InvalidArgumentException('A battler shadow scales its width within 0..4 and its strength within 0..4 of the theme\'s.');
    }
    if ($hover !== null && $hover > 0 && $shadow === BattlerShadowSource::ARTWORK) {
      throw new InvalidArgumentException('Artwork that paints its own shadow cannot hover: the shadow would rise with the body. Hover it once its art is shadow-free and its shadow is the engine\'s.');
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
    $shadow = $data['shadow'] ?? BattlerShadowSource::ENGINE->value;
    $source = is_string($shadow) ? BattlerShadowSource::tryFrom($shadow) : null;
    if ($source === null) {
      throw new InvalidArgumentException(sprintf('%s.shadow: expected one of %s.', $where,
        implode(', ', array_column(BattlerShadowSource::cases(), 'value'))));
    }
    try {
      return new self($number('hover'), $source, $number('shadowWidth') ?? 1.0, $number('shadowOpacity') ?? 1.0);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error);
    }
  }

  /** @return array<string, float|string> The authored form, leaving out what reading would assume. */
  public function toArray(): array
  {
    return array_filter(['hover' => $this->hover, 'shadow' => $this->shadow === BattlerShadowSource::ENGINE ? null : $this->shadow->value,
      'shadowWidth' => $this->shadowWidth === 1.0 ? null : $this->shadowWidth,
      'shadowOpacity' => $this->shadowOpacity === 1.0 ? null : $this->shadowOpacity], static fn($value): bool => $value !== null);
  }
}
