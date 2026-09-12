<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

final readonly class RendererRuntimeConfig
{
  public string $assetRoot;

  public function __construct(
    public RendererProcessConfig $process,
    string $assetRoot,
    public int $cellWidth = 16,
    public int $cellHeight = 24,
    public RendererProtocolVersion $protocol = RendererProtocolVersion::V2,
  )
  {
    $this->assetRoot = (new RendererSessionConfig('Renderer', $assetRoot,
      new RendererGridConfig(1, 1, $cellWidth, $cellHeight)))->assetRoot;
  }
}
