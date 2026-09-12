<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

final readonly class PresentationTextRun
{
  public function __construct(
    public int $row,
    public int $column,
    public string $text,
    public ?PresentationColor $foreground = null,
    public ?PresentationColor $background = null,
  )
  {
    if ($row < 0 || $column < 0 || preg_match('//u', $text) !== 1 || preg_match('/\p{Cc}/u', $text) === 1) {
      throw new InvalidArgumentException('Text runs require nonnegative coordinates and UTF-8 scalars without controls.');
    }
  }

  public function assertFits(int $width, int $height): void
  {
    if ($this->row >= $height || $this->column >= $width || mb_strlen($this->text, 'UTF-8') > $width - $this->column) {
      throw new InvalidArgumentException('Text run must fit completely within the renderer grid.');
    }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['row' => $this->row, 'column' => $this->column, 'text' => $this->text,
      'foreground' => $this->foreground?->toArray(), 'background' => $this->background?->toArray()];
  }
}
