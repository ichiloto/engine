<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;

final readonly class RendererSessionConfig
{
  public const string SPRITE_SOURCE_RECT = 'sprite_source_rect';
  public string $assetRoot;

  /** @param list<string> $requiredCapabilities */
  public function __construct(
    public string $title,
    string $assetRoot,
    public RendererGridConfig $grid = new RendererGridConfig(),
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V1,
    public array $requiredCapabilities = [],
  )
  {
    if ($requiredCapabilities !== [] && $requiredCapabilities !== [self::SPRITE_SOURCE_RECT]) {
      throw new InvalidArgumentException('Renderer required capabilities must be a unique list of supported capability names.');
    }
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
      ...($this->requiredCapabilities === [] ? [] : ['requiredCapabilities' => $this->requiredCapabilities]),
    ], $this->protocol);
  }
}
