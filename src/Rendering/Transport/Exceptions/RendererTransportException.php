<?php

namespace Ichiloto\Engine\Rendering\Transport\Exceptions;

use Ichiloto\Engine\Exceptions\IchilotoException;
use Throwable;

class RendererTransportException extends IchilotoException
{
  public readonly string $reason;

  public function __construct(
    string $message,
    public readonly string $diagnostics = '',
    public readonly ?int $exitCode = null,
    ?Throwable $previous = null,
  )
  {
    $this->reason = $message;
    parent::__construct($message
      . ($exitCode !== null ? " [exit={$exitCode}]" : '')
      . ($diagnostics !== '' ? "\nRenderer stderr tail: " . $diagnostics : ''),
      self::RUNTIME, $previous);
  }
}
