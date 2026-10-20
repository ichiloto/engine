<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextViewport;
use InvalidArgumentException;

/**
 * One owner-supplied reading entry: prose, a section heading, or a labelled field holding text, a number or an
 * icon list. Owners state what a value is; the renderer's MenuDetailStyle decides alignment, placement and
 * punctuation.
 */
final class MenuDetailEntry
{
  public bool $isField { get => $this->kind->isField(); }

  /** @param list<MenuDetailIcon> $icons A field's null colour takes the renderer style's value colour. */
  private function __construct(public readonly MenuDetailKind $kind, public readonly string $label,
    public readonly string $text, public readonly ?string $color, public readonly array $icons = [])
  {
    if ($kind !== MenuDetailKind::ICONS && trim($text) === '') {
      throw new InvalidArgumentException('Blank space is not a detail entry. Supply only real content.');
    }
  }

  /** Prose never becomes a data record and receives no separator. */
  public static function createProse(string $text, string $color = 'text'): self
  {
    return new self(MenuDetailKind::PROSE, '', TextViewport::normalize($text), $color);
  }

  /** A section heading; the style supplies its colour, backing and height. */
  public static function createHeading(string $title): self
  {
    return new self(MenuDetailKind::HEADING, '', self::createLabel($title), null);
  }

  /** A word or sentence value. */
  public static function createField(string $label, string $value, ?string $color = null): self
  {
    return new self(MenuDetailKind::TEXT, self::createLabel($label), TextViewport::normalize($value), $color);
  }

  /** An owner-formatted number, such as "76" or "120 / 150". */
  public static function createNumber(string $label, string $value, ?string $color = null): self
  {
    return new self(MenuDetailKind::NUMBER, self::createLabel($label), TextViewport::normalize($value), $color);
  }

  /** A list of icons such as elements. An empty list is a real answer ("none") and keeps its row.
   * @param list<MenuDetailIcon> $icons
   */
  public static function createIcons(string $label, array $icons, ?string $color = null): self
  {
    if (!array_is_list($icons) || !array_all($icons, static fn($icon) => $icon instanceof MenuDetailIcon)) {
      throw new InvalidArgumentException('Detail icon values must be a list of typed icons.');
    }
    return new self(MenuDetailKind::ICONS, self::createLabel($label),
      implode(', ', array_map(static fn(MenuDetailIcon $icon): string => $icon->name, $icons)), $color, $icons);
  }

  private static function createLabel(string $label): string
  {
    $label = trim(TextViewport::normalize($label));
    if ($label === '' || str_contains($label, "\n")) { throw new InvalidArgumentException('A detail label needs one line of text.'); }
    return $label;
  }
}
