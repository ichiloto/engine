<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;

final readonly class RendererSessionConfig
{
  public string $assetRoot;

  public function __construct(
    public string $title,
    string $assetRoot,
    public RendererGridConfig $grid = new RendererGridConfig(),
  )
  {
    if ($title === '' || strlen($title) > 4096 || preg_match('//u', $title) !== 1
      || preg_match('/\p{Cc}/u', $title) === 1) {
      throw new InvalidArgumentException('Renderer title must be nonempty UTF-8 without control characters (at most 4096 bytes).');
    }

    $absolute = str_starts_with($assetRoot, DIRECTORY_SEPARATOR)
      || preg_match('~^[A-Za-z]:[\\\\/]~', $assetRoot) === 1;
    $canonical = $absolute ? realpath($assetRoot) : false;
    if ($canonical === false || ! is_dir($canonical) || ! is_readable($canonical)) {
      throw new InvalidArgumentException('assetRoot must be an absolute, existing, readable directory.');
    }
    $this->assetRoot = $canonical;
  }

  public function hello(): RendererMessage
  {
    return new RendererMessage(RendererMessageType::HELLO, [
      'title' => $this->title,
      'assetRoot' => $this->assetRoot,
      'grid' => $this->grid->toArray(),
    ]);
  }
}
