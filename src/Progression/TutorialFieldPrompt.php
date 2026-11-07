<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Messaging\Notifications\ActionHintNotification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\UI\Presentation\PresentationDeliveryReceipt;

/** Delivers the field entry control for a live menu lesson. */
final class TutorialFieldPrompt
{
  private ?TutorialDefinition $tutorial = null;
  private ?PresentationDeliveryReceipt $delivery = null;
  private bool $introduced = false;

  public function __construct(private readonly Game $game) {}

  public function update(?TutorialDefinition $tutorial): void
  {
    if ($tutorial !== $this->tutorial) {
      $this->suspend();
      $this->tutorial = $tutorial;
      $this->introduced = false;
    }
    if ($tutorial === null || $this->introduced) { return; }
    if ($this->delivery?->isComplete) {
      $this->introduced = true;
      $this->delivery = null;
      return;
    }
    if ($this->delivery !== null && !$this->delivery->isTerminal) { return; }

    $hint = ActionHints::resolve('menu', 'Open menu');
    if ($hint->control === null) { return; }
    $this->delivery = NotificationManager::getInstance($this->game)->notifyTracked(
      new ActionHintNotification($this->game, 'Guide', '', [$hint]),
    );
  }

  public function dismissForMenu(): void
  {
    $this->introduced = true;
    $this->suspend();
  }

  public function suspend(): void
  {
    if ($this->delivery === null) { return; }
    if ($this->delivery->isComplete) {
      $this->introduced = true;
    } elseif (!$this->delivery->isTerminal) {
      NotificationManager::getInstance($this->game)->cancelDelivery($this->delivery);
    }
    $this->delivery = null;
  }
}
