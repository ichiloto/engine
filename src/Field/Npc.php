<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheetAssetGuard;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\ThicketSubjectInterface;
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
 *     'facing' => 'south',                       // heading it stands with: north, east, south (default) or west
 *     // Interaction pages: talking runs the first page whose conditions
 *     // hold (see NpcPages and NpcPage). Each page shows its lines, runs at
 *     // most one of script, scriptId or cinematicId, applies its sets and
 *     // shows its own cue while it is the page talking would run.
 *     'dialogue' => [
 *       ['id' => 'assignment', 'conditions' => [ … ], 'scriptId' => 'first-assignment', 'reusable' => false,
 *        'cue' => ['symbol' => '!', 'color' => 'bright-yellow']],
 *       ['lines' => [['name' => 'Mom', 'text' => '…']]],
 *     ],
 *     // Shorthand for one unconditional page: a plain line list, or a
 *     // top-level script, with that page's cue on the NPC itself.
 *     // 'dialogue' => [['name' => 'Mom', 'text' => '…'], …],
 *     // 'script' => [ …event commands… ],
 *     // 'cue' => ['symbol' => '!', 'color' => 'bright-yellow', 'conditions' => [ … ]],
 *     'conditions' => [ …trigger conditions… ],  // NPC only appears while these hold
 *     'sets' => [ …trigger sets… ],              // applied after every conversation
 *   ],
 * ],
 * ```
 *
 * @package Ichiloto\Engine\Field
 */
class Npc implements EventSessionCompletionTargetInterface, ThicketSubjectInterface
{
  /** An NPC walks among its cell's foliage, unless drawn from an object sheet such as a chest. */
  public bool $standsInFoliage {
    get => $this->graphicalSprites?->isObject === false;
  }
  /**
   * @var float The next time this NPC may take a wander step.
   */
  public float $nextWanderTime = 0.0;

  protected(set) MovementHeading $heading = MovementHeading::SOUTH;

  /** The page whose action is running, completed when its session ends. */
  protected ?NpcPage $pendingPage = null;
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
  private ?string $mapId = null;
  private ?GameState $gameState = null;
  private ?Party $party = null;

  /**
   * The page talking runs now: the first page whose conditions hold and which
   * has not retired. Null before the NPC is bound to its map's world state.
   */
  public ?NpcPage $currentPage {
    get => $this->mapId === null || $this->gameState === null ? null
      : $this->pages->selectPage($this->mapId, $this->gameState, $this->party);
  }

  /** The cue of the page talking runs now: the NPC's guidance always matches what talking does. */
  public ?EventCue $cue {
    get => $this->currentPage?->cue;
  }

  /**
   * @param string $name The NPC's name (talk-to quests match it).
   * @param string $sprite The map glyph.
   * @param Vector2 $position The world position.
   * @param bool $wanders True when the NPC takes random steps.
   * @param array{x: int, y: int, width: int, height: int}|null $wanderArea Bounds for wandering; null wanders freely.
   * @param NpcPages|null $pages Interaction pages; none when null.
   * @param array<int, array<string, mixed>> $conditions Visibility conditions (trigger shapes).
   * @param array<int, array<string, mixed>> $sets World-state writes applied after each conversation.
   * @param string|null $id Stable map-local script identity.
   * @param array<string, string> $directionalSprites Optional cardinal sprite glyphs.
   * @param CharacterSheet|null $graphicalSprites Optional RPG Maker character sheet.
   * @param string|null $graphicalSpriteId Map-scoped presentation identity, independent of script/save identity.
   * @param string|null $assetRoot Project asset root for graphical preflight.
   * @param bool $directionFix True keeps the heading when the player talks to it (RPG Maker's Direction Fix).
   * @param MovementHeading $facing The heading it stands with until something turns it.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $sprite,
    protected(set) Vector2 $position,
    protected(set) bool $wanders = false,
    protected(set) ?array $wanderArea = null,
    protected(set) ?NpcPages $pages = null,
    protected(set) array $conditions = [],
    protected(set) array $sets = [],
    protected(set) ?string $id = null,
    protected(set) array $directionalSprites = [],
    private readonly ?CharacterSheet $graphicalSprites = null,
    ?string $graphicalSpriteId = null,
    ?string $assetRoot = null,
    protected(set) bool $directionFix = false,
    MovementHeading $facing = MovementHeading::SOUTH,
  )
  {
    $this->pages ??= NpcPages::fromEntry([]);
    $this->id = $id !== null && trim($id) !== '' ? trim($id) : null;
    // The authored sprite is the look for the default south heading; another heading uses its directional glyph.
    if ($facing !== MovementHeading::SOUTH) {
      $this->face($facing->getDirection());
    }
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

  /** Whether speaking to this NPC can do anything: some page has lines or an action. */
  public bool $isTalkable {
    get => $this->pages->isTalkable;
  }

  /**
   * Binds the NPC to its map and the world state its pages read, as map
   * events are bound.
   */
  public function bind(string $mapId, GameState $gameState, ?Party $party = null): void
  {
    $this->mapId = $mapId;
    $this->gameState = $gameState;
    $this->party = $party;
  }

  /**
   * Talks to the NPC: runs the current page (its lines, then its script,
   * shared event script or Cinematic), applies its `sets` and the NPC's,
   * retires a one-shot page and records the conversation for talk-to quests.
   *
   * @param GameScene $gameScene The running game scene.
   * @return void
   */
  public function talk(GameScene $gameScene): void
  {
    if ($this->conversationIsActive) {
      return;
    }
    $this->bind($this->mapId ?? $gameScene->currentMapId, $gameScene->gameState, $gameScene->party);
    $page = $this->currentPage;

    if ($page === null) {
      // Every page is gated out: nothing is said, but the talk still counts.
      $this->applySets($gameScene);
      QuestManager::current()?->recordTalkTo($this->name);
      $this->endConversation($gameScene);
      return;
    }

    foreach ($page->lines as $line) {
      $gameScene->cinematicController?->prepareDialogue(strval($line['name'] ?? $this->name), $line);
      show_text(
        strval($line['text'] ?? ''),
        strval($line['name'] ?? $this->name),
        charactersPerSecond: dialogue_speed(),
        presentation: DialogueContext::getFromText($line),
      );
    }

    if ($page->hasAction) {
      $this->beginAction($gameScene, $page);
      return;
    }

    $this->finishPage($gameScene, $page);
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
   * Starts the asynchronous action of a page: inline commands, a shared
   * event script (run as a common event, from the same assets/Events files
   * map script events use) or a first-class Cinematic.
   */
  protected function beginAction(GameScene $gameScene, NpcPage $page): void
  {
    $mapId = $this->mapId ?? $gameScene->currentMapId;
    $this->pendingPage = $page;
    // State-only scripts may finish before the session start returns. Set
    // the guard first so the completion callback remains authoritative.
    $this->conversationIsActive = true;
    if ($page->cinematicId !== null) {
      $session = $gameScene->startCinematic($page->cinematicId, $this);
    } else {
      $session = $gameScene->startEventScript(
        $page->scriptId === null ? $page->script : [['type' => 'common_event', 'id' => $page->scriptId]],
        $page->scriptId ?? sprintf('npc:%s:%s', $mapId, $this->id ?? $this->name),
        $this,
        [
          'map' => $mapId,
          'trigger' => static::class,
          'npc' => $this->id ?? $this->name,
          'page' => $page->id ?? $page->index,
        ],
      );
    }

    if ($session === null && $this->conversationIsActive) {
      $this->conversationIsActive = false;
      $this->pendingPage = null;
      $this->endConversation($gameScene);
    }
  }

  /**
   * Completes a page: its own writes land before the NPC's, so "first time
   * you report back" state is recorded by the page that said it.
   */
  protected function finishPage(GameScene $gameScene, NpcPage $page): void
  {
    WorldStateWriter::applyAll($page->sets, $gameScene->gameState);
    $this->pages->retire($page, $this->mapId ?? $gameScene->currentMapId, $gameScene->gameState);
    $this->applySets($gameScene);
    QuestManager::current()?->recordTalkTo($this->name);
    $this->endConversation($gameScene);
  }

  /** Turns the NPC back to the heading it had before the player talked to it. */
  protected function endConversation(GameScene $gameScene): void
  {
    $gameScene->npcManager?->restoreNpcHeadingAfterTalk($this);
  }

  /** @inheritDoc */
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $page = $this->pendingPage;
    $this->pendingPage = null;
    $this->conversationIsActive = false;
    if ($page === null) {
      // Every page is gated out: nothing is said, but the talk still counts.
      $this->applySets($gameScene);
      QuestManager::current()?->recordTalkTo($this->name);
      $this->endConversation($gameScene);
      return;
    }
    $this->finishPage($gameScene, $page);
  }

  /** @inheritDoc */
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->pendingPage = null;
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
