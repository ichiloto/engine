<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Closure;
use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\PlayerDash;
use Ichiloto\Engine\Messaging\Notifications\ActionHintNotification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Presentation\PresentationDeliveryReceipt;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Field-owned first-use delivery; availability and passage completion keep their existing owners. */
final class WorldAbilityPrompts
{
  private ?WorldAbility $pending = null;
  private ?GameState $state = null;
  private ?WorldAbilityCatalog $catalog = null;
  private ?Party $party = null;
  private ?PresentationDeliveryReceipt $delivery = null;
  private ?FieldEffectSession $effect = null;
  private ?FieldEffectManager $effects = null;
  /** @var (Closure(GameScene): bool)|null */
  private ?Closure $canContinueInScene = null;
  /** @var array<string,true> Broken presentation sources are diagnosed once per field ownership interval. */
  private array $rejected = [];

  public function __construct(private readonly Game $game) {}

  /** Called only by controlled field gameplay, never by rendering. */
  public function updateForScene(GameScene $scene): void
  {
    if ($scene->getUI()->getActivePresentations() !== [] || $scene->isStopping) { $this->cancel(); return; }
    if ($this->pending !== null && ($this->state !== $scene->gameState
      || !$this->catalog->isUnlocked($this->pending->id, $this->state, $scene->party)
      || ($this->canContinueInScene !== null && !($this->canContinueInScene)($scene)))) { $this->cancel(); }
    $this->update();
    $catalog = $scene->gameState->getWorldAbilities();
    if ($this->pending !== null || $scene->player === null || $catalog === null) { return; }
    if ($this->canDashInScene($scene)) {
      foreach ($catalog->getRequiredAbilities(PlayerDash::getSettings($this->game)->conditions) as $ability) {
        if ($this->offer($ability, $catalog, $scene->gameState, $scene->party, $scene->fieldEffects,
          FieldEffectAnchor::fromArray(['object' => $scene->player->getGraphicalSpriteId()]))) {
          $this->canContinueInScene = $this->canDashInScene(...);
          return;
        }
      }
    }
    foreach ($scene->player->getSensedPassages() as $passage) {
      foreach ($catalog->getRequiredAbilities([...$passage->conditions, ...($passage->cue?->conditions ?? [])]) as $ability) {
        $position = $passage->cue->findVisiblePositions($passage->area, $scene->player->position)[0];
        if ($this->offer($ability, $catalog, $scene->gameState, $scene->party, $scene->fieldEffects,
          FieldEffectAnchor::createAtPosition($position))) {
          // Another placement of the same trigger can be visible after this anchor leaves range.
          $this->canContinueInScene = static fn(GameScene $current): bool =>
            $current->player !== null
            && $passage->cue->isInRange($position, $current->player->position)
            && in_array($passage, $current->player->getSensedPassages(), strict: true);
          return;
        }
      }
    }
  }

  private function canDashInScene(GameScene $scene): bool
  {
    return PlayerDash::canDash($scene->mapManager?->isDashingDisabled ?? false, $scene->gameState, $scene->party, $this->game);
  }

  public function offer(WorldAbility $ability, WorldAbilityCatalog $catalog, GameState $state, ?Party $party = null,
    ?FieldEffectManager $effects = null, ?FieldEffectAnchor $anchor = null): bool
  {
    $ability = $catalog->getAbility($ability->id);
    if ($this->pending !== null || isset($this->rejected[$ability->id]) || $ability->firstUse === null
      || $state->hasSeenWorldAbilityPrompt($ability->id) || !$catalog->isUnlocked($ability->id, $state, $party)) { return false; }
    $source = $ability->firstUse;
    $hints = $source->getActionHints($this->game);
    // A control unavailable on this input profile cannot be taught here.
    if (array_any($hints, static fn($hint): bool => $hint->control === null)) { return false; }
    $this->pending = $ability; $this->state = $state; $this->effects = $effects; $this->catalog = $catalog; $this->party = $party;
    try {
      if ($source->effect !== null) {
        if ($effects === null || $anchor === null) { $this->cancel(); return false; }
        $this->effect = $effects->startOneShotEffect('world-ability-prompt:' . $ability->id, $source->effect, $anchor);
      }
      if (trim($source->message) !== '' || $hints !== []) {
        $this->delivery = NotificationManager::getInstance($this->game)->notifyTracked(
          new ActionHintNotification($this->game, $ability->name, $source->message, $hints));
      }
      return true;
    } catch (Throwable $error) {
      $this->releasePending(); $this->rejected[$ability->id] = true;
      Debug::warn('First-use presentation ' . $ability->id . ': ' . $error->getMessage());
      return false;
    }
  }

  public function update(): void
  {
    if ($this->pending === null) { return; }
    if ($this->game->hasStopped() || !$this->catalog->isUnlocked($this->pending->id, $this->state, $this->party) || ($this->delivery?->isTerminal && !$this->delivery->isComplete)
      || ($this->effect !== null && $this->effect->playback->isPaused && !$this->effect->playback->isCompleted)) {
      $this->cancel(); return;
    }
    if (($this->delivery === null || $this->delivery->isComplete)
      && ($this->effect === null || $this->effect->playback->isCompleted)) {
      $this->state->markWorldAbilityPromptSeen($this->pending->id);
      $this->releasePending();
    }
  }

  public function cancel(): void
  {
    $this->releasePending();
    $this->rejected = [];
  }

  private function releasePending(): void
  {
    if ($this->delivery !== null) { NotificationManager::getInstance($this->game)->cancelDelivery($this->delivery); }
    if ($this->effect !== null) { $this->effects?->stopEffect($this->effect); }
    $this->pending = null; $this->state = null; $this->delivery = null; $this->effect = null; $this->effects = null; $this->catalog = null; $this->party = null;
    $this->canContinueInScene = null;
  }
}
