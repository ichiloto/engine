<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use InvalidArgumentException;

/** Resolved whole-label intent; font decoding and shaping belong to Renderer. */
final readonly class TextSpec
{
  /** @var list<FontReference> */
  public array $fonts;

  /** @param array<array-key, mixed> $fonts Validated and copied into an ordered FontReference list. */
  public function __construct(
    public string $text,
    array $fonts,
    public float $fontSize,
    public string $locale,
    public PresentationColor $foreground,
    public ?CanvasGlyphEffects $glyphEffects = null,
  ) {
    if (!array_is_list($fonts) || count($fonts) < 1 || count($fonts) > 16) {
      throw new InvalidArgumentException('Text requires an ordered list of 1..16 project font references.');
    }
    $copy = [];
    foreach ($fonts as $font) {
      if (!$font instanceof FontReference) {
        throw new InvalidArgumentException('Text fonts must be FontReference values.');
      }
      $copy[] = $font;
    }
    $this->fonts = $copy;
    if (!is_finite($fontSize) || $fontSize < 1 || $fontSize > 256) {
      throw new InvalidArgumentException('Text fontSize must be finite and in 1..256 logical pixels.');
    }
    if ($locale === '' || strlen($locale) > 128 || preg_match('//u', $locale) !== 1
      || preg_match('/\p{Cc}/u', $locale) === 1) {
      throw new InvalidArgumentException('Text locale must be nonempty UTF-8 without controls, at most 128 bytes.');
    }
    if (preg_match('//u', $text) !== 1 || preg_match('/(?!\n)\p{Cc}/u', $text) === 1
      || substr_count($text, "\n") + 1 > StyledPresentationFrame::MAX_TEXT_RUNS
      || mb_strlen($text, 'UTF-8') > StyledPresentationFrame::MAX_TEXT_SCALARS) {
      throw new InvalidArgumentException('Text exceeds UTF-8 scalar/line limits or contains controls other than newline.');
    }
  }

  /**
   * @return array{text: string, fonts: list<array{asset: string, faceIndex: int}>, fontSize: float,
   *   locale: string, foreground: array<string, int|string>, glyphEffects?: array<string, mixed>}
   */
  public function toArray(): array
  {
    return ['text' => $this->text,
      'fonts' => array_map(static fn(FontReference $font): array => $font->toArray(), $this->fonts),
      'fontSize' => $this->fontSize, 'locale' => $this->locale, 'foreground' => $this->foreground->toArray(),
      ...($this->glyphEffects === null ? [] : ['glyphEffects' => $this->glyphEffects->toArray()])];
  }
}
