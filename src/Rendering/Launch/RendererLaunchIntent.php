<?php

namespace Ichiloto\Engine\Rendering\Launch;

final readonly class RendererLaunchIntent
{
  public string $id;

  public function __construct(?string $id = null)
  {
    $id = strtolower(trim($id ?? ''));
    $this->id = $id === '' ? 'terminal' : $id;
  }

  public static function fromEnvironment(): self
  {
    $value = getenv('ICHILOTO_RENDERER');
    return new self($value === false ? null : $value);
  }
}
