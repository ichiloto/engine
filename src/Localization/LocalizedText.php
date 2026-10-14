<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use InvalidArgumentException;

/** Authored message identity and base-language text, never a prepared renderer handle. */
final readonly class LocalizedText
{
  public function __construct(public string $message, public string $default)
  {
    if (strlen($message) > 256 || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*\z/', $message) !== 1
      || preg_match('//u', $default) !== 1 || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $default) === 1
      || mb_strlen($default, 'UTF-8') > 4096) {
      throw new InvalidArgumentException('Localized labels require a message path and single-line UTF-8 base text of at most 4096 scalars.');
    }
  }

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['message', 'default']) !== []
      || !is_string($data['message'] ?? null) || !is_string($data['default'] ?? null)) {
      throw new InvalidArgumentException('Localized text requires exactly message and default strings.');
    }
    return new self($data['message'], $data['default']);
  }

  public function getText(): string
  {
    $text = MessageCatalog::get($this->message, $this->default);
    if (preg_match('//u', $text) !== 1 || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $text) === 1
      || mb_strlen($text, 'UTF-8') > 4096) {
      throw new InvalidArgumentException('Localized label resolution must remain single-line UTF-8 within 4096 scalars.');
    }
    return $text;
  }

  public function toArray(): array { return ['message' => $this->message, 'default' => $this->default]; }
}
