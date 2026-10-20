<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextViewport;
use InvalidArgumentException;

/** One owner-formatted reading entry: a prose paragraph, or a labelled value laid out as a data record. */
final class MenuDetailEntry
{
  public bool $isField { get => $this->label !== ''; }

  /** A field's null colour takes the renderer style's value colour. */
  private function __construct(public readonly string $label, public readonly string $text, public readonly ?string $color)
  {
    if (trim($text) === '') { throw new InvalidArgumentException('Blank space is not a detail entry. Supply only real content.'); }
  }

  /** Prose never becomes a data record and receives no separator. */
  public static function createProse(string $text, string $color = 'text'): self
  {
    return new self('', TextViewport::normalize($text), $color);
  }

  /** The label names the value; renderers supply alignment and separators, never punctuation in the owner's label. */
  public static function createField(string $label, string $value, ?string $color = null): self
  {
    $label = trim(TextViewport::normalize($label));
    if ($label === '' || str_contains($label, "\n")) { throw new InvalidArgumentException('A detail field needs a one-line label.'); }
    return new self($label, TextViewport::normalize($value), $color);
  }
}
