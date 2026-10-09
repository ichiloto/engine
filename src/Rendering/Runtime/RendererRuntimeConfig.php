<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;

final readonly class RendererRuntimeConfig
{
  /** The GPUI field text grid; the independent map ground cells retain their authored size. */
  public const int GPUI_CELL_WIDTH = 10;
  public const int GPUI_CELL_HEIGHT = 20;

  public string $assetRoot;

  /** @param list<string> $requiredCapabilities */
  public function __construct(
    public RendererProcessConfig $process,
    string $assetRoot,
    public int $cellWidth = 16,
    public int $cellHeight = 24,
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V2,
    public array $requiredCapabilities = [],
  )
  {
    if ($protocol !== RendererProtocolVersion::V2) {
      throw new InvalidArgumentException('Native GPUI requires protocol 2 retained presentation; the protocol 1 full-frame path was removed.');
    }
    $this->assetRoot = (new RendererSessionConfig('Renderer', $assetRoot,
      new RendererGridConfig(1, 1, $cellWidth, $cellHeight), $protocol, $requiredCapabilities))->assetRoot;
  }
}
