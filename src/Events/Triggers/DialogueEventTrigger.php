<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\ShowDialogAction;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
use Ichiloto\Engine\Messaging\Dialogue\Dialogue;
use Ichiloto\Engine\Quests\QuestManager;

/**
 * The DialogueEventTrigger class. This class is used to trigger a dialogue event.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class DialogueEventTrigger extends EventTrigger
{
  /**
   * @var array<int, mixed> The authored dialogue, kept raw so conditional
   * variants can be resolved when the player actually talks rather than when
   * the map loads.
   */
  protected(set) array $authoredDialogue = [];
  /**
   * @var array<int, array<string, mixed>> Writes applied by the variant last spoken.
   */
  protected array $pendingSets = [];

  /**
   * @var Dialogue[] The pages to show, resolved against the world state.
   */
  public array $dialogue {
    get {
      return $this->resolveDialogue();
    }
  }

  /**
   * @throws RequiredFieldException
   */
  public function configure(): void
  {
    $this->authoredDialogue = json_decode(json_encode($this->data->dialogue ?? []), true) ?: [];
  }

  /**
   * Resolves the pages to show for the current world state.
   *
   * Plain page lists are returned as-is; a list of conditional variants
   * yields the first whose conditions hold, so a speaker can acknowledge
   * what the player has done.
   *
   * @return Dialogue[] The dialogue pages.
   * @throws RequiredFieldException If a page is missing a required field.
   */
  protected function resolveDialogue(): array
  {
    if ($this->gameState === null) {
      // Unbound (no world state yet): fall back to the authored order.
      $pages = ConditionalDialogue::isVariantList($this->authoredDialogue)
        ? []
        : $this->authoredDialogue;
      $this->pendingSets = [];
    } else {
      $variant = ConditionalDialogue::select($this->authoredDialogue, $this->gameState, $this->party);
      $pages = $variant['lines'];
      $this->pendingSets = $variant['sets'];
    }

    $dialogue = [];

    foreach ($pages as $page) {
      if (is_array($page)) {
        $dialogue[] = Dialogue::fromArray($page);
      }
    }

    return $dialogue;
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);
    $context->player->erase();
    $context->player->availableAction = new ShowDialogAction($this);
    $context->player->render();
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);
    $context->player->erase();
    $context->player->availableAction = null;
    $context->player->render();
  }

  /**
   * @inheritDoc
   *
   * Finishing a conversation counts toward talk-to quest objectives. The
   * NPC's identity is the trigger's `npcId`, falling back to the first
   * speaker's name.
   */
  public function complete(): void
  {
    // The variant that was actually spoken records its own state first, so
    // "the first time you report back" can be captured by the line saying it.
    if ($this->gameState !== null && ! empty($this->pendingSets)) {
      WorldStateWriter::applyAll($this->pendingSets, $this->gameState);
      $this->pendingSets = [];
    }

    parent::complete();

    $npcName = trim(strval($this->data->npcId ?? ''));

    if ($npcName === '') {
      $npcName = trim($this->dialogue[0]->name ?? '');
    }

    if ($npcName !== '') {
      QuestManager::current()?->recordTalkTo($npcName);
    }
  }
}