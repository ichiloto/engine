<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Text;

use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;

/** The host owns transport polling and session retirement; consumers own their tickets. */
interface TextPreparationProviderInterface
{
  /** Opaque live-session identity; changes on restart and is never persisted. */
  public function getTextPreparationSession(): ?object;

  public function requestTextPreparation(TextSpec $spec): ?RendererTextPreparationRequest;
  public function releaseTextPreparation(RendererTextPreparationRequest $request): void;
}
