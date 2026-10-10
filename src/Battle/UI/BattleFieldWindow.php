<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCell;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackTiming;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Battle\Presentation\TerminalBattlePresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\UI\Windows\Window;
use InvalidArgumentException;
use RuntimeException;

/**
 * Represents the battlefield window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleFieldWindow extends Window implements \Ichiloto\Engine\Battle\Presentation\BattlePresentationState
{
  private ?CharacterInterface $actingBattler = null;
  private ?BattleCommandPlayback $commandPlayback = null;
  private float $poseSeconds = 0;
  private ?\Ichiloto\Engine\Battle\Presentation\BattleConditionEffects $conditionEffects = null;

  public function setConditionEffects(\Ichiloto\Engine\Battle\Presentation\BattleConditionEffects $effects): void
  {
    $this->conditionEffects = $effects;
  }

  public function advancePoseTime(float $seconds): void
  {
    if (!is_finite($seconds)) { throw new InvalidArgumentException('Battle pose time must be finite.'); }
    if ($this->pausedAt === null) { $this->poseSeconds += max(0, $seconds); }
  }

  public function getPoseElapsedSeconds(): float { return $this->poseSeconds; }

  public function getCommandPlayback(): ?BattleCommandPlayback { return $this->commandPlayback; }

  public function setCommandPlayback(?BattleCommandPlayback $playback): void
  {
    // Binding lifetime is independent of the next field redraw or terminal output.
    if ($this->commandPlayback !== $playback) { $this->clearBattleFlashState(repaint: false); }
    $this->commandPlayback = $playback;
    $this->actingBattler = $playback?->actor;
  }
  /** @var list<array{sequence: int, battler: CharacterInterface, lines: array, shownAt: float, durationSeconds: float}> */
  private array $feedback = [];
  private int $feedbackSequence = 0;
  private ?BattleFeedbackTiming $feedbackTiming = null;
  private ?float $pausedAt = null;

  public function pauseTiming(): void
  {
    $this->commandPlayback?->pause();
    $this->pausedAt ??= ($this->feedbackTiming ??= new BattleFeedbackTiming())->now();
  }

  public function resumeTiming(bool $discard = false): void
  {
    if ($discard) { $this->commandPlayback?->cancel(); $this->conditionEffects = null; }
    if ($this->pausedAt !== null && !$discard) {
      $elapsed = max(0, ($this->feedbackTiming ??= new BattleFeedbackTiming())->now() - $this->pausedAt);
      foreach ($this->feedback as &$feedback) { $feedback['shownAt'] += $elapsed; }
      unset($feedback);
    }
    $this->pausedAt = null;
    $this->commandPlayback?->resume();
  }

  public function render(?int $x = null, ?int $y = null): void
  {
    if (!$this->battleScreen->usesGraphicalField()) { parent::render($x, $y); }
  }

  public function erase(?int $x = null, ?int $y = null): void
  {
    if (!$this->battleScreen->usesGraphicalField()) { parent::erase($x, $y); }
  }

  public function getActingBattler(): ?CharacterInterface { return $this->actingBattler; }

  /**
   * Feedback observes the existing result formatter and explicit clear lifetime.
   * shownAt uses monotonic presentation seconds; reads and redraws never restart it.
   * @return list<array{sequence: int, battler: CharacterInterface, lines: list<array{text: string, color: Color, role?: BattleFeedbackRole}>, shownAt: float, durationSeconds: float}>
   */
  public function getFeedback(): array { return $this->feedback; }

  /** @return list<CharacterInterface> Current focus, including focused knocked-out targets. */
  public function getFocusedBattlers(): array
  {
    return $this->getBattlersAtIndexes($this->focusedPartyIndexes, $this->focusedTroopIndexes, true);
  }

  /** @return list<CharacterInterface> Living queued targets, independent of focus and acting. */
  public function getQueuedBattlers(): array
  {
    return $this->getBattlersAtIndexes(array_keys($this->queuedPartyTargets), array_keys($this->queuedTroopTargets), false);
  }

  /** @return list<CharacterInterface> */
  private function getBattlersAtIndexes(array $partyIndexes, array $troopIndexes, bool $includeKnockedOut): array
  {
    $battlers = [];
    foreach ([[$this->battleScreen->partyBattlers, $partyIndexes],
      [$this->battleScreen->troop->members->toArray(), $troopIndexes]] as [$members, $indexes]) {
      foreach ($indexes as $index) {
        $member = $members[$index] ?? null;
        if ($member instanceof CharacterInterface && ($includeKnockedOut || ! $member->isKnockedOut)) {
          $battlers[] = $member;
        }
      }
    }
    return $battlers;
  }

  /** @return list<CharacterInterface> Actual instances, not display names or glyph positions. */
  public function getSelectedBattlers(): array
  {
    $selected = [];
    foreach ([[$this->battleScreen->partyBattlers, $this->focusedPartyIndexes, $this->queuedPartyTargets],
      [$this->battleScreen->troop->members->toArray(), $this->focusedTroopIndexes, $this->queuedTroopTargets]] as [$members, $focus, $queue]) {
      foreach (array_unique([...$focus, ...array_keys($queue)]) as $index) {
        $member = $members[$index] ?? null;
        if ($member instanceof CharacterInterface && (in_array($index, $focus, true) || !$member->isKnockedOut)) {
          $selected[] = $member;
        }
      }
    }
    return $selected;
  }
  const int TROOP_STEP_X_OFFSET = 3;
  /**
   * Left-most column available to enemy battlers inside the field border.
   */
  const int TROOP_ZONE_LEFT = 2;
  /**
   * Empty columns kept between the enemy and player-party render zones.
   */
  const int BATTLE_SIDE_GAP = 3;
  /**
   * Horizontal offset applied to summon cutscene draw commands: one cell for
   * the window border plus a one-column inset.
   */
  const int SUMMON_CUTSCENE_OFFSET_X = 2;
  /**
   * Vertical offset applied to summon cutscene draw commands: one cell for
   * the window border plus a one-row inset.
   */
  const int SUMMON_CUTSCENE_OFFSET_Y = 2;
  private const string FLASH_OVERLAY_ID = 'battle-effect-flash';
  /** Battlefield effects sit above the field HUD but below modal interaction. */
  private const int FLASH_LAYER = PresentationLayerPolicy::UI + PresentationPriority::FIELD_HUD->value + 1;
  /**
   * @var string The marker shown for the active troop focus.
   */
  protected const string TROOP_FOCUS_MARKER = '>';
  /**
   * @var string The marker shown for the active party focus.
   */
  protected const string PARTY_FOCUS_MARKER = '<';
  /**
   * The width of the window.
   */
  const int WIDTH = 135;
  /**
   * The height of the window.
   */
  const int HEIGHT = 30;
  /**
   * @var PartyBattlerPositions $partyBattlerPositions The positions of the party battlers.
   */
  protected PartyBattlerPositions $partyBattlerPositions;
  /**
   * @var array<int, int> Queued player target counts keyed by party index.
   */
  protected array $queuedPartyTargets = [];
  /**
   * @var array<int, array{text: string, x: int, y: int}> Active target indicators currently drawn on screen.
   */
  protected array $renderedTargetIndicators = [];
  /**
   * @var array<int, int> Queued player target counts keyed by troop index.
   */
  protected array $queuedTroopTargets = [];
  /**
   * @var int[] The currently focused party battler indexes.
   */
  protected array $focusedPartyIndexes = [];
  /**
   * @var bool Whether the party focus marker should blink.
   */
  protected bool $blinkFocusedParty = false;
  /**
   * @var int[] The currently focused troop battler indexes.
   */
  protected array $focusedTroopIndexes = [];
  /**
   * @var bool Whether the troop focus marker should blink.
   */
  protected bool $blinkFocusedTroop = false;
  /**
   * @var array<int, array{text: string, x: int, y: int}> Active floating stat-change popups.
   */
  protected array $statChangePopups = [];
  /**
   * @var array<int, array{text: string, x: int, y: int}> Active magic cast effects.
   */
  protected array $magicCastEffects = [];
  /** @var array{target: CharacterInterface, screen: bool, color: Color, start: int, end: int}|null */
  private ?array $battleFlash = null;
  /** @var array{start: int, end: int, amplitude: int}|null */
  private ?array $summonShake = null;
  /**
   * @var int[] Party battler indices whose sprites should remain visible during a popup.
   */
  protected array $popupPartyIndices = [];
  /**
   * @var int[] Troop battler indices whose sprites should remain visible during a popup.
   */
  protected array $popupTroopIndices = [];

  /**
   * Creates a new instance of the battlefield window.
   *
   * @param BattleScreen $battleScreen The battle screen.
   * @param BattleFeedbackTiming|null $feedbackTiming Optional presentation-only clock policy.
   */
  public function __construct(protected BattleScreen $battleScreen, ?BattleFeedbackTiming $feedbackTiming = null)
  {
    $this->feedbackTiming = $feedbackTiming;
    $leftMargin = $this->battleScreen->screenDimensions->getLeft();
    $topMargin = $this->battleScreen->screenDimensions->getTop();

    $position = new Vector2($leftMargin, $topMargin);
    $this->partyBattlerPositions = new PartyBattlerPositions();

    parent::__construct(
      '',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
  }

  /**
   * Places a battler on the battlefield.
   *
   * @param Character $battler The battler to place.
   * @param Vector2 $position The position to place the battler.
   */
  protected function renderPartyBattler(Character $battler, Vector2 $position): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $spriteData = $battler->images->battle;
    $x = $this->position->x + $position->x;
    $y = $this->position->y + $position->y;

    $this->renderBattlerSprite($spriteData, $x, $y);
  }

  /**
   * Renders a troop battler.
   *
   * @param Enemy $battler The battler to render.
   * @return void
   */
  protected function renderTroopBattler(Enemy $battler): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $spriteData = \Ichiloto\Engine\Battle\Presentation\EnemyDefeatStyle::applyTerminalTreatment($battler->image,
      $this->commandPlayback?->getEnemyDefeatTreatment($battler, \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion()));
    $position = $this->getTroopPresentedPosition($battler);
    $x = $this->position->x + $position->x;
    $y = $this->position->y + $position->y;

    $this->renderBattlerSprite(
      $spriteData,
      $x,
      $y,
      $this->getTroopAvailableWidth($position),
    );
  }

  /**
   * Erases a battler from the battlefield.
   *
   * @param Character $battler The battler to erase.
   * @param Vector2 $position The position to erase the battler.
   */
  public function erasePartyBattler(Character $battler, Vector2 $position): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $spriteData = $battler->images->battle;
    $x = $this->position->x + $position->x;
    $y = $this->position->y + $position->y;

    $this->eraseBattlerSprite($spriteData, $x, $y);
  }

  /**
   * Erases a troop battler.
   *
   * @param Enemy $battler The battler to erase.
   * @return void
   */
  public function eraseTroopBattler(Enemy $battler): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $spriteData = $battler->image;
    $position = $this->getTroopIdlePosition($battler);
    $x = $this->position->x + $position->x;
    $y = $this->position->y + $position->y;

    $this->eraseBattlerSprite(
      $spriteData,
      $x,
      $y,
      $this->getTroopAvailableWidth($position),
    );
  }

  /**
   * Erases a battler sprite.
   *
   * @param string[] $spriteData The sprite data.
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return void
   */
  protected function eraseBattlerSprite(array $spriteData, int $x, int $y, ?int $maxWidth = null): void
  {
    foreach ($spriteData as $rowIndex => $row) {
      $rowWidth = TerminalText::displayWidth($row);
      $output = str_repeat(' ', $maxWidth === null ? $rowWidth : min($rowWidth, max(0, $maxWidth)));
      Console::write($output, max(0, $x - 1), max(0, $y + $rowIndex - 1));
    }
  }

  /**
   * Renders a battler sprite.
   *
   * @param string[] $spriteData The sprite data.
   * @param float|int $x The x-coordinate.
   * @param float|int $y The y-coordinate.
   * @return void
   */
  protected function renderBattlerSprite(
    array $spriteData,
    float|int $x,
    float|int $y,
    ?int $maxWidth = null,
  ): void
  {
    foreach (TerminalBattlePresentation::getSpriteDraws($spriteData, $x, $y, $maxWidth) as $draw) {
      $position = TerminalBattlePresentation::getDrawPosition($draw['x'], $draw['y']);
      Console::write($draw['text'], $position['x'], $position['y']);
    }
  }

  private function getTerminalEffects(): TerminalBattleEffects
  {
    return new TerminalBattleEffects($this->partyBattlerPositions, $this->position, $this->width, $this->height);
  }

  private function getTerminalGeometry(): TerminalBattleEffects
  {
    return new TerminalBattleEffects($this->partyBattlerPositions);
  }

  /**
   * Returns the idle position of the specified party battler.
   *
   * @param int $index The battler index.
   * @return Vector2
   */
  protected function getPartyIdlePosition(int $index): Vector2
  {
    return $this->getTerminalGeometry()->getPartyIdlePosition($index);
  }

  /**
   * Returns the active position of the specified party battler.
   *
   * @param int $index The battler index.
   * @return Vector2
   */
  protected function getPartyActivePosition(int $index): Vector2
  {
    return $this->getTerminalGeometry()->getPartyActivePosition($index);
  }

  private function getPartyPresentedPosition(int $index): Vector2
  {
    return $this->isCommandAdvanced($this->battleScreen->partyBattlers[$index])
      ? $this->getPartyActivePosition($index) : $this->getPartyIdlePosition($index);
  }

  private function getTroopPresentedPosition(Enemy $battler): Vector2
  {
    return $this->isCommandAdvanced($battler) ? $this->getTroopActivePosition($battler) : $this->getTroopIdlePosition($battler);
  }

  private function isCommandAdvanced(CharacterInterface $battler): bool
  {
    return TerminalBattleEffects::isCommandAdvanced($battler, $this->commandPlayback,
      \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
  }

  /**
   * Returns the active position of the specified troop battler.
   *
   * @param Enemy $battler The battler to inspect.
   * @return Vector2
   */
  protected function getTroopActivePosition(Enemy $battler): Vector2
  {
    return $this->getTerminalGeometry()->getTroopActivePosition($battler);
  }

  /**
   * Resolves an authored enemy position inside the troop render zone.
   *
   * The step-forward animation is included in the fit calculation so an
   * acting enemy can never overwrite the player party. Authored vertical
   * placement is preserved.
   *
   * @param Enemy $battler The enemy battler to position.
   * @return Vector2 The safe idle position.
   */
  protected function getTroopIdlePosition(Enemy $battler): Vector2
  {
    return $this->getTerminalGeometry()->getTroopIdlePosition($battler);
  }

  /**
   * Returns the first column reserved for player-party sprites.
   *
   * Both idle and active positions participate because either may be on
   * screen during an action.
   *
   * @return int The left edge of the player-party render zone.
   */
  protected function getPartyZoneLeft(): int
  {
    return $this->getTerminalGeometry()->getPartyZoneLeft();
  }

  /**
   * Returns the number of columns available before the party-side gap.
   *
   * This is also a final safety net for exceptionally wide authored sprites:
   * they are clipped at their side boundary instead of erasing party art.
   *
   * @param Vector2 $position The enemy render position.
   * @return int The available width in terminal cells.
   */
  protected function getTroopAvailableWidth(Vector2 $position): int
  {
    return $this->getTerminalGeometry()->getTroopAvailableWidth($position);
  }

  /**
   * Returns the portion of an enemy sprite which is visible in its zone.
   *
   * @param Enemy $battler The enemy battler to measure.
   * @param Vector2 $position Its resolved render position.
   * @return int The visible sprite width in terminal cells.
   */
  protected function getTroopVisibleSpriteWidth(Enemy $battler, Vector2 $position): int
  {
    return $this->getTerminalGeometry()->getTroopVisibleSpriteWidth($battler, $position);
  }

  /**
   * Renders the party on the battle screen.
   *
   * @param Party $party The party to render.
   * @return void
   */
  public function renderParty(Party $party): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    foreach ($this->battleScreen->partyBattlers as $index => $battler) {
      if ($battler->isKnockedOut && ! in_array($index, $this->popupPartyIndices, true)) {
        continue;
      }

      $this->renderPartyBattler(
        $battler,
        $this->getPartyPresentedPosition($index)
      );
    }
  }

  /**
   * Renders the troop of enemies on the battle screen.
   *
   * @param Troop $troop The troop to render.
   * @return void
   */
  public function renderTroop(Troop $troop): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    foreach ($troop->members->toArray() as $index => $battler) {
      $defeat = $this->commandPlayback?->getEnemyDefeatTreatment($battler, \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
      if ($battler->isKnockedOut && ($defeat !== null ? !$defeat['visible'] : !in_array($index, $this->popupTroopIndices, true))) {
        continue;
      }

      $this->renderTroopBattler($battler);
    }
  }

  /**
   * Renders queued-target badges and the current focus marker.
   *
   * @return void
   */
  public function renderTargetIndicators(): void
  {
    if (! isset($this->battleScreen)) {
      return;
    }
    if ($this->battleScreen->usesGraphicalField()) { return; }

    $this->renderedTargetIndicators = [];
    $partyBattlers = $this->battleScreen->partyBattlers;
    $troopMembers = $this->battleScreen->troop->members->toArray();

    foreach ($this->queuedPartyTargets as $index => $count) {
      $battler = $partyBattlers[$index] ?? null;

      if (! $battler instanceof Character || $battler->isKnockedOut || $count < 1) {
        continue;
      }

      $this->renderPartyQueueBadge($battler, $index, $count);
    }

    foreach ($this->queuedTroopTargets as $index => $count) {
      $battler = $troopMembers[$index] ?? null;

      if (! $battler instanceof Enemy || $battler->isKnockedOut || $count < 1) {
        continue;
      }

      $this->renderTroopQueueBadge($battler, $count);
    }

    foreach ($this->focusedTroopIndexes as $index) {
      $battler = $troopMembers[$index] ?? null;

      if ($battler instanceof Enemy) {
        $this->renderTroopFocusMarker($battler, $this->blinkFocusedTroop);
      }
    }

    foreach ($this->focusedPartyIndexes as $index) {
      $battler = $partyBattlers[$index] ?? null;

      if ($battler instanceof Character) {
        $this->renderPartyFocusMarker($battler, $index, $this->blinkFocusedParty);
      }
    }
  }

  /**
   * Redraws only the target indicator layer without rebuilding the battlefield.
   *
   * @return void
   */
  public function redrawTargetIndicators(): void
  {
    $this->clearRenderedTargetIndicators();
    $this->renderTargetIndicators();
  }

  /**
   * Renders any active stat-change popups on top of the battlefield.
   *
   * @return void
   */
  public function renderStatChangePopups(): void
  {
    foreach ($this->statChangePopups as $popup) {
      $this->renderIndicator($popup['text'], $popup['x'], $popup['y']);
    }
  }

  /**
   * Renders any active magic cast effects on top of the caster sprite.
   *
   * @return void
   */
  public function renderMagicCastEffects(): void
  {
    $flashes = null;
    if ($this->commandPlayback !== null && !$this->battleScreen->usesGraphicalField()) {
      $flashes = $this->renderCommandEffects();
    }
    foreach ($this->magicCastEffects as $effect) {
      $this->renderIndicator($effect['text'], $effect['x'], $effect['y']);
    }
    if (!$this->battleScreen->usesGraphicalField() && $this->conditionEffects !== null) {
      $effects = new TerminalBattleEffects(origin: $this->position, width: $this->width, height: $this->height);
      foreach ([...$this->battleScreen->partyBattlers, ...$this->battleScreen->troop->members->toArray()] as $battler) {
        $condition = $this->conditionEffects->createPlayback($battler, $this->poseSeconds);
        if ($condition === null) { continue; }
        $frame = $effects->compose($condition, $this->resolveActionAnimationOrigin(...),
          \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
        foreach ($frame['draws'] as $draw) { $this->renderIndicator($draw['text'], $draw['x'], $draw['y']); }
      }
    }
    if ($flashes !== null) { $this->renderBattleFlashes($flashes); }
  }

  /** @return list<array{target: CharacterInterface, screen: bool, color: Color}> */
  private function renderCommandEffects(): array
  {
    $effects = new TerminalBattleEffects(origin: $this->position, width: $this->width, height: $this->height);
    $frame = $effects->compose($this->commandPlayback, $this->resolveActionAnimationOrigin(...),
      \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
    $this->magicCastEffects = $frame['draws'];
    return $frame['flashes'];
  }

  /**
   * Displays floating stat-change text beside the provided battler.
   *
   * @param CharacterInterface $battler The battler receiving the popup.
   * @param array<int, array{text: string, color?: Color, role?: BattleFeedbackRole}> $lines The popup lines to display.
   * @param bool $clearExisting Whether to replace the existing popups.
   * @param float $durationSeconds Existing popup hold duration; omission adds no animation interval.
   * @return void
   */
  public function showStatChangePopup(
    CharacterInterface $battler,
    array $lines,
    bool $clearExisting = true,
    float $durationSeconds = 0.0,
  ): void
  {
    if ($clearExisting) {
      $this->clearStatChangePopups();
    }

    $graphical = isset($this->battleScreen) && $this->battleScreen->usesGraphicalField();
    $anchor = $graphical ? null : $this->resolveStatChangePopupAnchor($battler);
    if (!$graphical && $anchor === null) { return; }
    $formattedLines = $this->normalizeStatChangePopupLines($lines);

    if (empty($formattedLines)) {
      return;
    }

    $this->feedback[] = [
      'sequence' => ++$this->feedbackSequence,
      'battler' => $battler,
      'lines' => $formattedLines,
      'shownAt' => ($this->feedbackTiming ??= new BattleFeedbackTiming())->now(),
      'durationSeconds' => BattleFeedbackTiming::duration($durationSeconds),
    ];
    if ($graphical) { return; }

    $formattedLines = array_values(array_filter($formattedLines,
      static fn(array $line): bool => ($line['role'] ?? null) !== BattleFeedbackRole::KO));
    $startY = $anchor['y'] - max(0, count($formattedLines) - 1);

    foreach ($formattedLines as $index => $line) {
      $text = $this->formatStatChangePopupLine(
        strval($line['text']),
        $line['color'] ?? Color::WHITE
      );
      $textWidth = TerminalText::displayWidth($text);

      $this->statChangePopups[] = [
        'text' => $text,
        'x' => $anchor['x'] - intdiv($textWidth, 2),
        'y' => $startY + $index,
      ];
    }

    if (isset($anchor['partyIndex'])) {
      $this->popupPartyIndices[] = $anchor['partyIndex'];
    }

    if (isset($anchor['troopIndex'])) {
      $this->popupTroopIndices[] = $anchor['troopIndex'];
    }
  }

  /**
   * Validates and normalizes the single stat-popup line contract.
   *
   * @param array<int, mixed> $lines Raw popup line definitions.
   * @return list<array{text: string, color: Color, role?: BattleFeedbackRole}> Normalized non-empty lines.
   */
  protected function normalizeStatChangePopupLines(array $lines): array
  {
    $normalized = [];

    foreach ($lines as $index => $line) {
      if (! is_array($line) || ! array_key_exists('text', $line)) {
        throw new InvalidArgumentException(sprintf(
          'Stat-change popup line %d must be an array containing text and an optional Color.',
          $index,
        ));
      }

      $text = strval($line['text']);

      if ($text === '') {
        continue;
      }

      $color = $line['color'] ?? Color::WHITE;

      if (! $color instanceof Color) {
        throw new InvalidArgumentException(sprintf(
          'Stat-change popup line %d color must be a Color.',
          $index,
        ));
      }

      $normalizedLine = ['text' => $text, 'color' => $color];
      if (array_key_exists('role', $line)) {
        if (! $line['role'] instanceof BattleFeedbackRole) {
          throw new InvalidArgumentException(sprintf(
            'Stat-change popup line %d role must be a BattleFeedbackRole.',
            $index,
          ));
        }
        $normalizedLine['role'] = $line['role'];
      }
      $normalized[] = $normalizedLine;
    }

    return $normalized;
  }

  /**
   * Clears any active stat-change popups from the battlefield.
   *
   * @return void
   */
  public function clearStatChangePopups(): void
  {
    $this->feedback = [];
    $this->statChangePopups = [];
    $this->popupPartyIndices = [];
    $this->popupTroopIndices = [];
  }

  /**
   * Displays a single magic cast animation frame around the acting party battler.
   *
   * @param Character $battler The casting battler.
   * @param int $index The party battler index.
   * @param Color $color The effect color.
   * @param int $sequenceStep The clockwise frame index.
   * @return void
   */
  public function showPartyMagicCastEffect(Character $battler, int $index, Color $color, int $sequenceStep): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $positions = $this->resolvePartyMagicCastEffectPositions($battler, $index);
    $frame = $positions[$sequenceStep] ?? null;

    $this->clearMagicCastEffects();

    if (! is_array($frame)) {
      return;
    }

    $this->magicCastEffects[] = [
      'text' => $this->formatMagicCastEffect($color),
      'x' => $frame['x'],
      'y' => $frame['y'],
    ];

    $this->renderMagicCastEffects();
  }

  /**
   * Clears any active magic cast effects from the battlefield.
   *
   * @return void
   */
  public function clearMagicCastEffects(): void
  {
    $effects = $this->magicCastEffects;
    $this->magicCastEffects = [];
    foreach ($effects as $effect) {
      $this->eraseIndicator($effect['text'], $effect['x'], $effect['y']);
    }
  }

  /**
   * Steps the specified party battler forward.
   *
   * @param Character $battler The battler to move.
   * @param int $index The battler index.
   * @return void
   */
  public function stepPartyBattlerForward(Character $battler, int $index): void
  {
    $this->actingBattler = $battler;
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->erasePartyBattler($battler, $this->getPartyIdlePosition($index));
    $this->renderPartyBattler($battler, $this->getPartyActivePosition($index));
  }

  /**
   * Returns the specified party battler to idle position.
   *
   * @param Character $battler The battler to move.
   * @param int $index The battler index.
   * @return void
   */
  public function stepPartyBattlerBack(Character $battler, int $index): void
  {
    if ($this->actingBattler === $battler) { $this->actingBattler = null; }
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->erasePartyBattler($battler, $this->getPartyActivePosition($index));
    $this->renderPartyBattler($battler, $this->getPartyIdlePosition($index));
  }

  /**
   * Steps the specified enemy battler forward.
   *
   * @param Enemy $battler The battler to move.
   * @return void
   */
  public function stepTroopBattlerForward(Enemy $battler): void
  {
    $this->actingBattler = $battler;
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->eraseTroopBattler($battler);
    $activePosition = $this->getTroopActivePosition($battler);
    $this->renderBattlerSprite(
      $battler->image,
      $this->position->x + $activePosition->x,
      $this->position->y + $activePosition->y,
      $this->getTroopAvailableWidth($activePosition),
    );
  }

  /**
   * Returns the specified enemy battler to idle position.
   *
   * @param Enemy $battler The battler to move.
   * @return void
   */
  public function stepTroopBattlerBack(Enemy $battler): void
  {
    if ($this->actingBattler === $battler) { $this->actingBattler = null; }
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $activePosition = $this->getTroopActivePosition($battler);
    $this->eraseBattlerSprite(
      $battler->image,
      $this->position->x + $activePosition->x,
      $this->position->y + $activePosition->y,
      $this->getTroopAvailableWidth($activePosition),
    );
    $this->renderTroopBattler($battler);
  }

  /**
   * Applies a steady focus marker to the specified party battler.
   *
   * @param int $index The index of the party battler to select.
   * @return void
   */
  public function selectPartyBattler(int $index): void
  {
    $this->focusPartyBattler($index);
  }

  /**
   * Focuses on a party battler without animating their sprite position.
   *
   * @param int $index The index of the party battler to focus on.
   * @param bool $blink Whether the focus marker should blink.
   * @return void
   */
  public function focusPartyBattler(int $index, bool $blink = false): void
  {
    $this->focusPartyBattlers([$index], $blink);
  }

  /** @param int[] $indexes The party battlers to highlight together. */
  public function focusPartyBattlers(array $indexes, bool $blink = false): void
  {
    $this->focusedPartyIndexes = array_values(array_unique(array_filter($indexes, static fn(int $index): bool => $index >= 0)));
    $this->blinkFocusedParty = $blink;
  }

  /**
   * Removes the focus marker from the specified party battler.
   *
   * @param int $index The index of the party battler to blur.
   * @return void
   */
  public function blurPartyBattler(int $index): void
  {
    $this->focusedPartyIndexes = array_values(array_filter($this->focusedPartyIndexes, static fn(int $focused): bool => $focused !== $index));
    if ($this->focusedPartyIndexes === []) {
      $this->clearPartyFocus();
    }
  }

  /**
   * Applies a steady focus marker to the specified troop battler.
   *
   * @param int $index The index of the troop battler to select.
   * @return void
   */
  public function selectTroopBattler(int $index): void
  {
    $this->focusOnTroopBattler($index);
  }

  /**
   * Focuses on a troop battler without animating their sprite position.
   *
   * @param int $index The index of the troop battler to focus on.
   * @param bool $blink Whether the focus marker should blink.
   * @return void
   */
  public function focusOnTroopBattler(int $index, bool $blink = false): void
  {
    $this->focusTroopBattlers([$index], $blink);
  }

  /** @param int[] $indexes The troop battlers to highlight together. */
  public function focusTroopBattlers(array $indexes, bool $blink = false): void
  {
    $this->focusedTroopIndexes = array_values(array_unique(array_filter($indexes, static fn(int $index): bool => $index >= 0)));
    $this->blinkFocusedTroop = $blink;
  }

  /**
   * Clears all battlefield targeting indicators.
   *
   * @return void
   */
  public function clearTargetIndicators(): void
  {
    $this->queuedPartyTargets = [];
    $this->queuedTroopTargets = [];
    $this->clearPartyFocus();
    $this->clearTroopFocus();
    $this->clearRenderedTargetIndicators();
  }

  /**
   * Updates queued target counts for party battlers.
   *
   * @param array<int, int> $targetCounts Queued target counts keyed by party index.
   * @return void
   */
  public function setPartyTargetQueue(array $targetCounts): void
  {
    $this->queuedPartyTargets = array_filter(
      $targetCounts,
      static fn(mixed $count): bool => is_int($count) && $count > 0
    );
  }

  /**
   * Updates queued target counts for troop battlers.
   *
   * @param array<int, int> $targetCounts Queued target counts keyed by troop index.
   * @return void
   */
  public function setTroopTargetQueue(array $targetCounts): void
  {
    $this->queuedTroopTargets = array_filter(
      $targetCounts,
      static fn(mixed $count): bool => is_int($count) && $count > 0
    );
  }

  /**
   * Clears any currently focused troop battler.
   *
   * @return void
   */
  public function clearTroopFocus(): void
  {
    $this->focusedTroopIndexes = [];
    $this->blinkFocusedTroop = false;
  }

  /**
   * Clears any currently focused party battler.
   *
   * @return void
   */
  public function clearPartyFocus(): void
  {
    $this->focusedPartyIndexes = [];
    $this->blinkFocusedParty = false;
  }

  /**
   * Renders the queue badge for a troop battler.
   *
   * @param Enemy $battler The battler to decorate.
   * @param int $count The number of queued player actions targeting the battler.
   * @return void
   */
  protected function renderTroopQueueBadge(Enemy $battler, int $count): void
  {
    $badge = $this->formatIndicator(sprintf('x%d', $count));
    $position = $this->getTroopIdlePosition($battler);
    $spriteWidth = $this->getTroopVisibleSpriteWidth($battler, $position);
    $badgeWidth = TerminalText::displayWidth($badge);
    $x = $this->position->x + $position->x + max(0, intdiv(max(0, $spriteWidth - $badgeWidth), 2));
    $y = $this->position->y + $position->y - 1;

    $this->renderTrackedTargetIndicator($badge, $x, $y);
  }

  /**
   * Renders the queue badge for a party battler.
   *
   * @param Character $battler The battler to decorate.
   * @param int $index The party battler index.
   * @param int $count The number of queued player actions targeting the battler.
   * @return void
   */
  protected function renderPartyQueueBadge(Character $battler, int $index, int $count): void
  {
    $position = $this->getPartyIdlePosition($index);
    $badge = $this->formatIndicator(sprintf('x%d', $count));
    $spriteWidth = $this->getSpriteWidth($battler->images->battle);
    $badgeWidth = TerminalText::displayWidth($badge);
    $x = $this->position->x + $position->x + max(0, intdiv(max(0, $spriteWidth - $badgeWidth), 2));
    $y = $this->position->y + $position->y - 1;

    $this->renderTrackedTargetIndicator($badge, $x, $y);
  }

  /**
   * Renders the focus marker beside a troop battler.
   *
   * @param Enemy $battler The battler being focused.
   * @param bool $blink Whether the focus marker should blink.
   * @return void
   */
  protected function renderTroopFocusMarker(Enemy $battler, bool $blink): void
  {
    $marker = $this->formatIndicator(self::TROOP_FOCUS_MARKER, $blink);
    $position = $this->getTroopIdlePosition($battler);
    $x = $this->position->x + $position->x - 2;
    $y = $this->position->y + $position->y + intdiv(count($battler->image), 2);

    $this->renderTrackedTargetIndicator($marker, $x, $y);
  }

  /**
   * Renders the focus marker beside a party battler.
   *
   * @param Character $battler The battler being focused.
   * @param int $index The party battler index.
   * @param bool $blink Whether the focus marker should blink.
   * @return void
   */
  protected function renderPartyFocusMarker(Character $battler, int $index, bool $blink): void
  {
    $position = $this->getPartyIdlePosition($index);
    $marker = $this->formatIndicator(self::PARTY_FOCUS_MARKER, $blink);
    $x = $this->position->x + $position->x + $this->getSpriteWidth($battler->images->battle) + 1;
    $y = $this->position->y + $position->y + intdiv(count($battler->images->battle), 2);

    $this->renderTrackedTargetIndicator($marker, $x, $y);
  }

  /**
   * Tracks and renders a target indicator so it can be cleared without repainting the field.
   *
   * @param string $text The indicator text.
   * @param int $x The preferred x-coordinate.
   * @param int $y The preferred y-coordinate.
   * @return void
   */
  protected function renderTrackedTargetIndicator(string $text, int $x, int $y): void
  {
    $this->renderedTargetIndicators[] = [
      'text' => $text,
      'x' => $x,
      'y' => $y,
    ];

    $this->renderIndicator($text, $x, $y);
  }

  /**
   * Clears the currently rendered target indicator layer.
   *
   * @return void
   */
  protected function clearRenderedTargetIndicators(): void
  {
    $indicators = $this->renderedTargetIndicators;
    $this->renderedTargetIndicators = [];
    foreach ($indicators as $indicator) {
      $this->eraseIndicator($indicator['text'], $indicator['x'], $indicator['y']);
    }
  }

  /**
   * Renders an indicator inside the battlefield bounds.
   *
   * @param string $text The indicator text.
   * @param int $x The preferred x-coordinate.
   * @param int $y The preferred y-coordinate.
   * @return void
   */
  protected function renderIndicator(string $text, int $x, int $y): void
  {
    ['x' => $renderX, 'y' => $renderY] = $this->resolveIndicatorPosition($text, $x, $y);
    Console::write(
      TerminalText::stabilize($text),
      max(0, $renderX - 1),
      max(0, $renderY - 1),
    );
  }

  /**
   * Erases an indicator inside the battlefield bounds.
   *
   * @param string $text The indicator text.
   * @param int $x The preferred x-coordinate.
   * @param int $y The preferred y-coordinate.
   * @return void
   */
  protected function eraseIndicator(string $text, int $x, int $y): void
  {
    ['x' => $renderX, 'y' => $renderY] = $this->resolveIndicatorPosition($text, $x, $y);
    Console::write(
      str_repeat(' ', TerminalText::displayWidth($text)),
      max(0, $renderX - 1),
      max(0, $renderY - 1),
    );
  }

  /**
   * Applies battle-selection styling to a battlefield indicator.
   *
   * @param string $text The indicator text.
   * @param bool $blink Whether the indicator should blink.
   * @return string The styled indicator.
   */
  protected function formatIndicator(string $text, bool $blink = false): string
  {
    $prefix = ($blink && \Ichiloto\Engine\UI\Accessibility::allowsBlink()) ? "\033[5m" : '';

    return $prefix . $this->battleScreen->getSelectionColor()->value . $text . Color::RESET->value;
  }

  /**
   * Applies styling to a magic cast animation glyph.
   *
   * @param Color $color The effect color.
   * @return string
   */
  protected function formatMagicCastEffect(Color $color): string
  {
    return $color->value . '*' . Color::RESET->value;
  }

  /**
   * Applies popup styling to floating stat-change text.
   *
   * @param string $text The popup text.
   * @param Color $color The popup color.
   * @return string The styled popup line.
   */
  protected function formatStatChangePopupLine(string $text, Color $color): string
  {
    return $color->value . $text . Color::RESET->value;
  }

  /**
   * Resolves the battlefield anchor used to place a battler's popup text.
   *
   * @param CharacterInterface $battler The battler receiving the popup.
   * @return array{x: int, y: int, partyIndex?: int, troopIndex?: int}|null The popup anchor.
   */
  protected function resolveStatChangePopupAnchor(CharacterInterface $battler): ?array
  {
    return $this->getTerminalEffects()->getBattlerAnchor($battler, $this->battleScreen->partyBattlers,
      $this->battleScreen->troop->members->toArray(), $this->commandPlayback,
      \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
  }

  /**
   * Resolves the direct-draw coordinates for a battlefield indicator.
   *
   * @param string $text The indicator text.
   * @param int $x The preferred x-coordinate.
   * @param int $y The preferred y-coordinate.
   * @return array{x: int, y: int}
   */
  protected function resolveIndicatorPosition(string $text, int $x, int $y): array
  {
    return new TerminalBattleEffects(origin: $this->position, width: $this->width, height: $this->height)
      ->getIndicatorPosition($text, $x, $y);
  }

  /**
   * Resolves the four clockwise magic cast effect positions around a party battler.
   *
   * @param Character $battler The casting battler.
   * @param int $index The party battler index.
   * @return array<int, array{x: int, y: int}>
   */
  protected function resolvePartyMagicCastEffectPositions(Character $battler, int $index): array
  {
    $position = $this->getPartyActivePosition($index);
    $spriteWidth = $this->getSpriteWidth($battler->images->battle);
    $spriteHeight = count($battler->images->battle);
    $baseX = $this->position->x + $position->x;
    $baseY = $this->position->y + $position->y;
    $minimumY = $this->battleScreen->screenDimensions->getTop() + 5;

    return [
      ['x' => $baseX - 1, 'y' => max($minimumY, $baseY - 1)],
      ['x' => $baseX + $spriteWidth, 'y' => max($minimumY, $baseY - 1)],
      ['x' => $baseX + $spriteWidth, 'y' => $baseY + $spriteHeight],
      ['x' => $baseX - 1, 'y' => $baseY + $spriteHeight],
    ];
  }


  /**
   * Displays one editor-authored action animation frame anchored to the battler.
   *
   * @param CharacterInterface $battler The battler receiving the animation.
   * @param Animation $animation The animation to render.
   * @param int $frameIndex The frame index to display.
   * @return void
   */
  public function showActionAnimationFrame(CharacterInterface $battler, Animation $animation, int $frameIndex): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->clearMagicCastEffects();
    $origin = $this->resolveActionAnimationOrigin($battler, $animation->position);

    if ($origin === null) {
      return;
    }

    foreach ($animation->getFrame($frameIndex)->getCells() as $cell) {
      $this->magicCastEffects[] = [
        'text' => $this->formatAnimationCell($cell),
        'x' => $origin['x'] + $cell->x,
        'y' => $origin['y'] + $cell->y,
      ];
    }

    $this->renderMagicCastEffects();
    $this->renderBattleFlash($frameIndex);
  }

  /**
   * Displays one compiled summon cutscene frame across the battlefield.
   *
   * @param SummonCompiledCutscene $cutscene The compiled summon cutscene.
   * @param int $frameIndex The frame index to display.
   * @return void
   */
  public function showSummonCutsceneFrame(SummonCompiledCutscene $cutscene, int $frameIndex): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->clearMagicCastEffects();
    $segments = [];
    foreach ($cutscene->playbackSegments as $segment) {
      $startFrame = intval($segment['startFrame'] ?? -1);
      $endFrame = intval($segment['endFrame'] ?? -1);

      if ($frameIndex < $startFrame || $frameIndex > $endFrame) {
        continue;
      }
      $segments[] = $segment;
    }
    usort($segments, static fn(array $left, array $right): int =>
      intval($left['drawCommands'][0]['zIndex'] ?? 0) <=> intval($right['drawCommands'][0]['zIndex'] ?? 0));
    $commands = [];
    foreach ($segments as $segment) {
      if (boolval($segment['clearBeforeDraw'] ?? false)) { $commands = []; }
      foreach (array_values(array_filter($segment['drawCommands'] ?? [], 'is_array')) as $drawCommand) {
        $commands[] = $drawCommand;
      }
    }
    $offset = $this->getSummonShakeOffset($frameIndex);
    foreach ($commands as $drawCommand) {
      $this->queueSummonDrawCommand($drawCommand, $offset);
    }
    $this->renderMagicCastEffects();
    $this->renderBattleFlash($frameIndex);
  }

  /** Start a finite recolour pulse over the target or the entire battlefield. */
  public function beginBattleFlash(CharacterInterface $target, bool $screen, string $colorName, int $frame, int $durationFrames): void
  {
    $color = $this->resolveNamedColor($colorName);
    if ($color === null || $durationFrames < 1) { return; }
    $this->battleFlash = ['target' => $target, 'screen' => $screen, 'color' => $color,
      'start' => $frame, 'end' => $frame + $durationFrames];
  }

  public function clearBattleFlash(): void
  {
    $this->clearBattleFlashState();
  }

  private function clearBattleFlashState(bool $repaint = true): void
  {
    $this->battleFlash = null;
    Console::removeOverlay(self::FLASH_OVERLAY_ID, $repaint);
  }

  /** Timeline shakes shift effect art only, keeping combatants and controls stable. */
  public function beginSummonShake(int $frame, int $durationFrames, int $amplitude = 1): void
  {
    if ($durationFrames < 1) { return; }
    $this->summonShake = ['start' => $frame, 'end' => $frame + $durationFrames,
      'amplitude' => max(0, min(intdiv(max(0, $this->width - 2), 2), $amplitude))];
  }

  public function clearSummonShake(): void
  {
    $this->summonShake = null;
  }

  /** @return array{x: int, y: int} */
  private function getSummonShakeOffset(int $frame): array
  {
    if ($this->summonShake === null || $frame < $this->summonShake['start']) { return ['x' => 0, 'y' => 0]; }
    if ($frame >= $this->summonShake['end']) { $this->summonShake = null; return ['x' => 0, 'y' => 0]; }
    $step = $frame - $this->summonShake['start'];
    return ['x' => ($step % 2 === 0 ? 1 : -1) * $this->summonShake['amplitude'], 'y' => 0];
  }

  private function renderBattleFlash(int $frame): void
  {
    $flash = $this->battleFlash;
    if ($flash === null) { return; }
    if ($frame >= $flash['end']) { $this->clearBattleFlash(); return; }
    if ($frame < $flash['start']) { return; }

    $this->renderBattleFlashes([$flash]);
  }

  /** @param list<array{target: CharacterInterface, screen: bool, color: Color}> $flashes */
  private function renderBattleFlashes(array $flashes): void
  {
    $effects = new TerminalBattleEffects(origin: $this->position, width: $this->width, height: $this->height);
    $regions = $effects->getFlashRegions($flashes, $this->resolveStatChangePopupAnchor(...),
      fn(CharacterInterface $target): int => $target instanceof Enemy
        ? $this->getTroopVisibleSpriteWidth($target, $this->getTroopPresentedPosition($target))
        : $this->getSpriteWidth($target->images->battle));
    // Read the live underlay, not a previous frame or the flash overlay itself.
    $overlay = TerminalBattleEffects::composeFlashOverlay(Console::getBuffer([self::FLASH_OVERLAY_ID]),
      $regions, Console::getWidth(), Console::getHeight());
    if ($overlay === null) { Console::removeOverlay(self::FLASH_OVERLAY_ID); return; }
    // One foreground-only layer preserves styled gaps and avoids per-recipient layer-budget growth.
    Console::replaceOverlay(self::FLASH_OVERLAY_ID, $overlay['lines'], $overlay['x'], $overlay['y'], self::FLASH_LAYER);
  }

  /**
   * Displays one summon transition frame over the battlefield.
   *
   * @param float $progress The normalized transition progress.
   * @param string $direction The transition direction.
   * @param string|null $colorName The optional transition color.
   * @return void
   */
  public function showSummonTransitionFrame(float $progress, string $direction = "in", ?string $colorName = null): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->queueSummonTransitionFrame($progress, $direction, $colorName);
    $this->renderMagicCastEffects();
  }

  private function queueSummonTransitionFrame(float $progress, string $direction, ?string $colorName): void
  {
    $glyphs = [".", ":", "*", "#"];
    $normalizedProgress = max(0.0, min(1.0, $progress));
    $glyphIndex = intval(floor($normalizedProgress * max(1, count($glyphs) - 1)));

    if ($direction === "out") {
      $glyphIndex = max(0, count($glyphs) - 1 - $glyphIndex);
    }

    $glyph = $glyphs[max(0, min(count($glyphs) - 1, $glyphIndex))];
    $color = $this->resolveNamedColor($colorName) ?? Color::DARK_GRAY;
    $innerWidth = max(1, $this->width - 2 * self::SUMMON_CUTSCENE_OFFSET_X);
    $innerHeight = max(1, $this->height - 2 * self::SUMMON_CUTSCENE_OFFSET_Y);

    $this->clearMagicCastEffects();

    for ($row = 0; $row < $innerHeight; $row++) {
      $this->queueSummonOverlayLine(
        $this->formatSummonDrawCommandLine(str_repeat($glyph, $innerWidth), $color),
        $this->position->x + self::SUMMON_CUTSCENE_OFFSET_X,
        $this->position->y + self::SUMMON_CUTSCENE_OFFSET_Y + $row,
      );
    }

  }

  /**
   * Displays a simple summon title card over the cleared battlefield.
   *
   * @param string $summonName The summon display name.
   * @param string|null $casterName The optional caster banner line.
   * @return void
   */
  public function showSummonTitleCard(string $summonName, ?string $casterName = null): void
  {
    if ($this->battleScreen->usesGraphicalField()) { return; }
    $this->queueSummonTitleCard($summonName, $casterName);
    $this->renderMagicCastEffects();
  }

  private function queueSummonTitleCard(string $summonName, ?string $casterName): void
  {
    $title = "[ " . strtoupper(trim($summonName)) . " ]";

    if (trim($summonName) === "") {
      return;
    }

    $this->clearMagicCastEffects();

    $innerWidth = max(1, $this->width - 2);
    $titleX = $this->position->x + 1 + max(0, intdiv($innerWidth - TerminalText::displayWidth($title), 2));
    $titleY = $this->position->y + 4;

    $this->queueSummonOverlayLine(
      $this->formatSummonDrawCommandLine($title, Color::LIGHT_RED),
      $titleX,
      $titleY,
    );

    if ($casterName !== null && trim($casterName) !== "") {
      $subtitle = $casterName;
      $subtitleX = $this->position->x + 1 + max(0, intdiv($innerWidth - TerminalText::displayWidth($subtitle), 2));
      $this->queueSummonOverlayLine(
        $this->formatSummonDrawCommandLine($subtitle, Color::WHITE),
        $subtitleX,
        $titleY + 2,
      );
    }

  }

  /**
   * Queues a summon overlay line into the reusable overlay layer.
   *
   * @param string $text The line text.
   * @param int $x The target x-coordinate.
   * @param int $y The target y-coordinate.
   * @return void
   */
  protected function queueSummonOverlayLine(string $text, int $x, int $y): void
  {
    $this->magicCastEffects[] = [
      "text" => $text,
      "x" => $x,
      "y" => $y,
    ];
  }

  /**
   * Resolves an optional color name into a terminal color.
   *
   * @param string|null $colorName The color name.
   * @return Color|null
   */
  protected function resolveNamedColor(?string $colorName): ?Color
  {
    return TerminalBattleEffects::resolveNamedColor($colorName);
  }
  /**
   * Queues a summon draw command for the overlay renderer.
   *
   * @param array<string, mixed> $drawCommand The compiled draw command.
   * @return void
   */
  protected function queueSummonDrawCommand(array $drawCommand, array $offset = ['x' => 0, 'y' => 0]): void
  {
    $effects = new TerminalBattleEffects(origin: $this->position, width: $this->width, height: $this->height);
    array_push($this->magicCastEffects, ...$effects->getLegacyDraws($drawCommand, $offset));
  }

  /**
   * @param array<string, mixed> $drawCommand
   * @return string[]
   */
  protected function resolveSummonDrawCommandLines(array $drawCommand): array
  {
    return TerminalBattleEffects::getCommandLines($drawCommand);
  }

  /**
   * @param array<string, mixed> $drawCommand
   * @return Color|null
   */
  protected function resolveSummonDrawCommandColor(array $drawCommand): ?Color
  {
    return TerminalBattleEffects::resolveNamedColor($drawCommand['color'] ?? null);
  }

  /**
   * Applies optional color styling to one summon cutscene draw line.
   *
   * @param string $line The line to style.
   * @param Color|null $color The optional color.
   * @return string
   */
  protected function formatSummonDrawCommandLine(string $line, ?Color $color): string
  {
    return TerminalBattleEffects::formatLine($line, $color);
  }

  /**
   * Resolves the anchor point for an action animation on the target battler.
   *
   * @param CharacterInterface $battler The battler receiving the animation.
   * @param AnimationTargetPosition $position The configured animation anchor.
   * @return array{x: int, y: int}|null
   */
  protected function resolveActionAnimationOrigin(
    CharacterInterface $battler,
    AnimationTargetPosition $position,
  ): ?array
  {
    return $this->getTerminalEffects()->getAnimationOrigin($battler, $position, $this->battleScreen->partyBattlers,
      $this->battleScreen->troop->members->toArray(), $this->commandPlayback,
      \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
  }

  /**
   * Applies optional color styling to an animation cell.
   *
   * @param AnimationCell $cell The animation cell.
   * @return string
   */
  protected function formatAnimationCell(AnimationCell $cell): string
  {
    if ($cell->color === null || $cell->color === '') {
      return $cell->symbol;
    }

    foreach (Color::cases() as $color) {
      if (strtolower($color->name) === strtolower($cell->color)) {
        return $color->value . $cell->symbol . Color::RESET->value;
      }
    }

    return $cell->symbol;
  }

  /**
   * Returns the display width of the widest sprite row.
   *
   * @param string[] $spriteData The sprite rows.
   * @return int The widest row width.
   */
  protected function getSpriteWidth(array $spriteData): int
  {
    return TerminalBattleEffects::getSpriteWidth($spriteData);
  }
}
