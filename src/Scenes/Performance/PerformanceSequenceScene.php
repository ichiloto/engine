<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Performance;

use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Events\Interpreter\EventPresentationHostInterface;
use Ichiloto\Engine\Events\Interpreter\ModalEventPresentation;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\SceneExcursionSession;
use InvalidArgumentException;
use LogicException;

/** Runs one untimed sitting with the same choice presentation used by field events. */
final class PerformanceSequenceScene extends AbstractScene implements EventPresentationHostInterface
{
  private ?PerformanceSceneConfiguration $configuration = null;
  private ?PerformanceSequence $sequence = null;
  private ?SceneExcursionSession $session = null;
  private ?ModalEventPresentation $choices = null;
  private ?AudioPlayback $accompaniment = null;
  private bool $hasBegun = false;

  public function configure(SceneConfigurationInterface $config): void
  {
    if (!$config instanceof PerformanceSceneConfiguration) {
      throw new InvalidArgumentException('A performance requires its sequence configuration.');
    }
    $this->configuration = $config;
    $this->sequence = new PerformanceSequence($config);
  }

  public function start(): void
  {
    $this->session = $this->sceneManager->getSceneExcursionSession($this)
      ?? throw new LogicException('A performance must be entered through a scene excursion.');
    $this->choices = new ModalEventPresentation($this);
    parent::start();
  }

  public function update(): void
  {
    parent::update();
    if ($this->sequence === null || $this->session === null || $this->choices === null || $this->configuration === null) {
      return;
    }
    if (!$this->hasBegun) {
      $this->hasBegun = true;
      if ($this->configuration->accompaniment !== null) {
        $this->accompaniment = $this->getGame()->audioManager->startSoundEffect($this->configuration->accompaniment);
      }
      $this->showCurrentPhrase();
      return;
    }
    $this->choices->update();
    if (!$this->choices->isComplete()) { return; }
    $choice = $this->choices->choiceResult();
    if ($choice === null || $choice < 0) {
      $this->sequence->cancel();
    } else {
      $this->sequence->confirmChoice($choice);
    }
    if ($this->sequence->outcome === null) {
      $this->showCurrentPhrase();
    } else {
      $this->sceneManager->finishSceneExcursion($this->session, $this->sequence->outcome);
    }
  }

  private function showCurrentPhrase(): void
  {
    $phrase = $this->sequence?->getCurrentPhrase();
    if ($phrase !== null) {
      $this->choices?->beginChoice($phrase->prompt, $phrase->choices, $this->configuration?->title ?? '');
    }
  }

  public function render(): void { $this->choices?->render(); }

  public function restoreAfterEventOverlay(): void {}

  public function stop(): void
  {
    try {
      $this->choices?->reset();
    } finally {
      try {
        $this->accompaniment?->stop();
      } finally {
        $this->configuration = $this->sequence = $this->session = $this->choices = $this->accompaniment = null;
        $this->hasBegun = false;
        parent::stop();
      }
    }
  }
}
