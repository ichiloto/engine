<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Cinematics\StagePresentationOperation;
use RuntimeException;

/** Uses the existing stage timeline/session without replacing world or camera ownership. */
final class PlayStageCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    if ($context->battle !== null) { throw new RuntimeException('play_stage runs only in field scripts.'); }
    $reference = $command['stage'] ?? null;
    $seconds = $command['seconds'] ?? null;
    if (!is_string($reference) || (!is_int($seconds) && !is_float($seconds))
      || !is_finite((float)$seconds) || $seconds < 0) {
      throw new RuntimeException('play_stage requires a stage timeline identity and finite nonnegative seconds.');
    }
    EffectTimelineLibrary::assertId($reference);
    if ($seconds == 0) { return ScriptCommandOutcome::complete(); }
    $scene = $context->scene;
    $presentation = $scene->cinematicPresentation ?? throw new RuntimeException('No cinematic presentation host is available.');
    $session = null;
    if ($scene->isGraphicalFieldPresented()) {
      $root = $scene->getPresentationContext()?->assetRoot
        ?? $scene->getGame()->getRendererRuntime()?->getAssetRoot() ?? getcwd() . '/assets';
      $timeline = new EffectTimelineLibrary($root)->loadStage($reference);
      $session = $presentation->beginStagePresentation($timeline, $root, (float)$seconds);
    }
    return ScriptCommandOutcome::waitFor(new StagePresentationOperation((float)$seconds, $presentation, $session));
  }
}
