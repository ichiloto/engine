<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheetAssetGuard;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * A field NPC: a sprite on the map the player can talk to.
 *
 * Authored per map under an `npcs` block:
 *
 * ```php
 * 'npcs' => [
 *   [
 *     'name' => 'Mom',
 *     'sprite' => '👩',
 *     'x' => 23, 'y' => 5,
 *     'movement' => 'fixed',                     // or 'wander'
 *     'wanderArea' => ['x' => 20, 'y' => 4, 'width' => 6, 'height' => 3],
 *     'directionFix' => false,                   // true: no turn to the player while talking
 *     // Either a plain page list, or conditional variants where the first
 *     // matching entry is spoken (see ConditionalDialogue).
 *     'dialogue' => [['name' => 'Mom', 'text' => '…'], …],
 *     'script' => [ …event commands… ],          // replaces dialogue when present
 *     'conditions' => [ …trigger conditions… ],  // NPC only appears while these hold
 *     'sets' => [ …trigger sets… ],              // applied after every conversation
 *   ],
 * ],
 * ```
 *
 * @package Ichiloto\Engine\Field
 */
class Npc implements EventSessionCompletionTargetInterface, GraphicalSpriteProviderInterface
{
  /**
   * @var float The next time this NPC may take a wander step.
   */
  public float $nextWanderTime = 0.0;

  protected(set) MovementHeading $heading = MovementHeading::SOUTH;

  /** @var array<int, array<string, mixed>> Variant writes awaiting script completion. */
  protected array $pendingConversationSets = [];
  protected(set) bool $conversationIsActive = false;
  /**
   * The heading the talk turn replaced, restored when that conversation
   * ends. Anything else that sets the heading or transform meanwhile (a
   * route turn or step, restored cinematic staging) clears it, so an
   * authored result is never snapped back.
   */
  private ?MovementHeading $headingBeforeTalk = null;
  private readonly string $graphicalSpriteId;
  private readonly CharacterWalkAnimation $walkAnimation;
  private readonly ?CharacterSheetAssetGuard $graphicalAssetGuard;

  /**
   * @param string $name The NPC's name (talk-to quests match it).
   * @param string $sprite The map glyph.
   * @param Vector2 $position The world position.
   * @param bool $wanders True when the NPC takes random steps.
   * @param array{x: int, y: int, width: int, height: int}|null $wanderArea Bounds for wandering; null wanders freely.
   * @param array<int, array<string, mixed>> $dialogue Dialogue pages (name + text).
   * @param array<int, array<string, mixed>> $script Event-script commands; replaces dialogue when non-empty.
   * @param array<int, array<string, mixed>> $conditions Visibility conditions (trigger shapes).
   * @param array<int, array<string, mixed>> $sets World-state writes applied after each conversation.
   * @param string|null $id Stable map-local script identity.
   * @param array<string, string> $directionalSprites Optional cardinal sprite glyphs.
   * @param CharacterSheet|null $graphicalSprites Optional RPG Maker character sheet.
   * @param string|null $graphicalSpriteId Map-scoped presentation identity, independent of script/save identity.
   * @param string|null $assetRoot Project asset root for graphical preflight.
   * @param bool $directionFix True keeps the heading when the player talks to it (RPG Maker's Direction Fix).
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $sprite,
    protected(set) Vector2 $position,
    protected(set) bool $wanders = false,
    protected(set) ?array $wanderArea = null,
    protected(set) array $dialogue = [],
    protected(set) array $script = [],
    protected(set) array $conditions = [],
    protected(set) array $sets = [],
    protected(set) ?string $id = null,
    protected(set) array $directionalSprites = [],
    private readonly ?CharacterSheet $graphicalSprites = null,
    ?string $graphicalSpriteId = null,
    ?string $assetRoot = null,
    protected(set) bool $directionFix = false,
  )
  {
    $this->id = $id !== null && trim($id) !== '' ? trim($id) : null;
    $this->graphicalSpriteId = $graphicalSpriteId ?? 'npc:object:' . spl_object_id($this);
    $this->walkAnimation = new CharacterWalkAnimation();
    $this->graphicalAssetGuard = $graphicalSprites === null ? null : new CharacterSheetAssetGuard(
      $assetRoot ?? getcwd() . '/assets', $this->graphicalSpriteId,
    );
  }

  public function getGraphicalSpriteId(): string
  {
    return $this->graphicalSpriteId;
  }

  /** Authored graphical role remains available while a staged visual owns presentation. */
  public function getGraphicalCharacterSheet(): ?CharacterSheet
  {
    return $this->graphicalSprites;
  }

  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition
  {
    $frame = $this->graphicalSprites === null ? null : $this->graphicalAssetGuard?->getFrameSize($this->graphicalSprites);
    if ($frame === null) {
      return null;
    }
    return $this->graphicalSprites->getFrame($this->heading, $this->walkAnimation->getPattern(), $frame);
  }

  public function getGraphicalSpriteWorldPosition(): Vector2
  {
    return clone $this->position;
  }

  public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion
  {
    return $this->graphicalSprites === null ? null : $this->walkAnimation->getMotion($this->position);
  }

  /** @param CharacterStep|null $step The step taken; without one, one stride and no slide. */
  public function beginGraphicalStep(?CharacterStep $step = null): void
  {
    if ($this->graphicalSprites !== null) {
      $step === null ? $this->walkAnimation->stride() : $this->walkAnimation->step($step);
    } else {
      $this->stopGraphicalAnimation();
    }
  }

  public function advanceGraphicalAnimation(float $seconds): void
  {
    $this->walkAnimation->advance($seconds);
  }

  public function stopGraphicalAnimation(): void
  {
    $this->walkAnimation->stop();
  }

  /** Whether speaking to this NPC does anything: it has lines or a script. */
  public bool $isTalkable {
    get => $this->dialogue !== [] || $this->script !== [];
  }

  /**
   * Talks to the NPC: plays its script or dialogue, applies its `sets`,
   * and records the conversation for talk-to quests.
   *
   * @param GameScene $gameScene The running game scene.
   * @return void
   */
  public function talk(GameScene $gameScene): void
  {
    if ($this->conversationIsActive) {
      return;
    }

    if (! empty($this->script)) {
      $this->beginScript($gameScene, $this->script, []);
      return;
    } else {
      // Pick what to say from the state of the world, so a character can
      // acknowledge what the player has actually done.
      $variant = ConditionalDialogue::select($this->dialogue, $gameScene->gameState, $gameScene->party);

      foreach ($variant['lines'] as $page) {
        show_text(
          strval($page['text'] ?? ''),
          strval($page['name'] ?? $this->name),
          charactersPerSecond: dialogue_speed(),
          presentation: DialogueContext::getFromText($page),
        );
      }

      if (! empty($variant['script'])) {
        $this->beginScript($gameScene, $variant['script'], $variant['sets']);
        return;
      }

      // A variant's own writes land before the NPC's, so "first time you
      // report back" state is recorded by the line that said it.
      WorldStateWriter::applyAll($variant['sets'], $gameScene->gameState);
    }

    $this->applySets($gameScene);
    QuestManager::current()?->recordTalkTo($this->name);
    $this->endConversation($gameScene);
  }

  /**
   * Remembers the heading a talk turn replaced, to restore when the
   * conversation ends.
   *
   * @param MovementHeading $heading The heading before the turn.
   * @return void
   */
  public function rememberHeadingBeforeTalk(MovementHeading $heading): void
  {
    $this->headingBeforeTalk = $heading;
  }

  /**
   * Returns and forgets the heading to restore after a conversation.
   *
   * @return MovementHeading|null The heading, or null when nothing should be restored.
   */
  public function takeHeadingBeforeTalk(): ?MovementHeading
  {
    $heading = $this->headingBeforeTalk;
    $this->headingBeforeTalk = null;

    return $heading;
  }

  /**
   * Faces a direction, using authored directional art when available.
   *
   * @param Vector2 $direction The cardinal direction vector.
   * @return void
   */
  public function face(Vector2 $direction): void
  {
    // Whatever turns the NPC now owns its heading; the talk turn-back is forgotten.
    $this->headingBeforeTalk = null;
    $this->heading = match (true) {
      $direction->y < 0 => MovementHeading::NORTH,
      $direction->y > 0 => MovementHeading::SOUTH,
      $direction->x < 0 => MovementHeading::WEST,
      $direction->x > 0 => MovementHeading::EAST,
      default => $this->heading,
    };
    $authored = $this->directionalSprites[$this->heading->name] ?? $this->directionalSprites[strtolower($this->heading->name)] ?? null;

    if (is_string($authored) && $authored !== '') {
      $this->sprite = $authored;
    }
  }

  /** Restore staging only; eligibility and conversation/story state remain live. */
  public function restoreFieldTransform(Vector2 $position, MovementHeading $heading, string $sprite): void
  {
    $this->headingBeforeTalk = null;
    $this->position->x = $position->x;
    $this->position->y = $position->y;
    $this->heading = $heading;
    $this->sprite = $sprite;
    $this->stopGraphicalAnimation();
  }

  /**
   * Starts the asynchronous script portion of an NPC conversation.
   *
   * @param array<int, array<string, mixed>> $script The commands to run.
   * @param array<int, array<string, mixed>> $variantSets Writes belonging to the chosen dialogue variant.
   */
  protected function beginScript(GameScene $gameScene, array $script, array $variantSets): void
  {
    $identity = sprintf(
      'npc:%s:%s',
      $gameScene->currentMapId,
      $this->id ?? $this->name,
    );
    $this->pendingConversationSets = $variantSets;
    // State-only scripts may finish before startEventScript() returns. Set
    // the guard first so the completion callback remains authoritative.
    $this->conversationIsActive = true;
    $session = $gameScene->startEventScript($script, $identity, $this, [
      'map' => $gameScene->currentMapId,
      'trigger' => static::class,
      'npc' => $this->id ?? $this->name,
    ]);

    if ($session === null) {
      $this->conversationIsActive = false;
      $this->pendingConversationSets = [];
      $this->endConversation($gameScene);
    }
  }

  /** Turns the NPC back to the heading it had before the player talked to it. */
  protected function endConversation(GameScene $gameScene): void
  {
    $gameScene->npcManager?->restoreNpcHeadingAfterTalk($this);
  }

  /** @inheritDoc */
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    WorldStateWriter::applyAll($this->pendingConversationSets, $gameScene->gameState);
    $this->applySets($gameScene);
    QuestManager::current()?->recordTalkTo($this->name);
    $this->pendingConversationSets = [];
    $this->conversationIsActive = false;
    $this->endConversation($gameScene);
  }

  /** @inheritDoc */
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->pendingConversationSets = [];
    $this->conversationIsActive = false;
    $this->endConversation($gameScene);
  }

  /**
   * Determines whether a wander step stays inside the NPC's area.
   *
   * @param int $x The destination x.
   * @param int $y The destination y.
   * @return bool True when the destination is allowed.
   */
  public function allowsWanderTo(int $x, int $y): bool
  {
    if ($this->wanderArea === null) {
      return true;
    }

    return $x >= $this->wanderArea['x']
      && $x < $this->wanderArea['x'] + $this->wanderArea['width']
      && $y >= $this->wanderArea['y']
      && $y < $this->wanderArea['y'] + $this->wanderArea['height'];
  }

  /**
   * Applies the NPC's world-state writes.
   *
   * @param GameScene $gameScene The running game scene.
   * @return void
   */
  protected function applySets(GameScene $gameScene): void
  {
    WorldStateWriter::applyAll($this->sets, $gameScene->gameState);
  }
}
