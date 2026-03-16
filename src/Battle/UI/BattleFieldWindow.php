<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Windows\Window;
use RuntimeException;

/**
 * Represents the battlefield window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleFieldWindow extends Window
{
  const int TROOP_STEP_X_OFFSET = 3;
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
   * @var array<int, int> Queued player target counts keyed by troop index.
   */
  protected array $queuedTroopTargets = [];
  /**
   * @var int|null The currently focused party battler index.
   */
  protected ?int $focusedPartyIndex = null;
  /**
   * @var bool Whether the party focus marker should blink.
   */
  protected bool $blinkFocusedParty = false;
  /**
   * @var int|null The currently focused troop battler index.
   */
  protected ?int $focusedTroopIndex = null;
  /**
   * @var bool Whether the troop focus marker should blink.
   */
  protected bool $blinkFocusedTroop = false;

  /**
   * Creates a new instance of the battlefield window.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(protected BattleScreen $battleScreen)
  {
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
    $spriteData = $battler->image;
    $x = $this->position->x + $battler->position->x;
    $y = $this->position->y + $battler->position->y;

    $this->renderBattlerSprite($spriteData, $x, $y);
  }

  /**
   * Erases a battler from the battlefield.
   *
   * @param Character $battler The battler to erase.
   * @param Vector2 $position The position to erase the battler.
   */
  public function erasePartyBattler(Character $battler, Vector2 $position): void
  {
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
    $spriteData = $battler->image;
    $x = $this->position->x + $battler->position->x;
    $y = $this->position->y + $battler->position->y;

    $this->eraseBattlerSprite($spriteData, $x, $y);
  }

  /**
   * Erases a battler sprite.
   *
   * @param string[] $spriteData The sprite data.
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return void
   */
  protected function eraseBattlerSprite(array $spriteData, int $x, int $y): void
  {
    foreach ($spriteData as $rowIndex => $row) {
      Console::cursor()->moveTo($x, $y + $rowIndex);
      $output = str_repeat(' ', TerminalText::displayWidth($row));
      $this->output->write($output);
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
  protected function renderBattlerSprite(array $spriteData, float|int $x, float|int $y): void
  {
    foreach ($spriteData as $rowIndex => $row) {
      Console::cursor()->moveTo($x, $y + $rowIndex);
      $this->output->write($row);
    }
  }

  /**
   * Returns the idle position of the specified party battler.
   *
   * @param int $index The battler index.
   * @return Vector2
   */
  protected function getPartyIdlePosition(int $index): Vector2
  {
    return $this->partyBattlerPositions->idlePositions[$index] ?? throw new RuntimeException('Invalid party battler position.');
  }

  /**
   * Returns the active position of the specified party battler.
   *
   * @param int $index The battler index.
   * @return Vector2
   */
  protected function getPartyActivePosition(int $index): Vector2
  {
    return $this->partyBattlerPositions->activePositions[$index] ?? throw new RuntimeException('Invalid party battler active position.');
  }

  /**
   * Returns the active position of the specified troop battler.
   *
   * @param Enemy $battler The battler to inspect.
   * @return Vector2
   */
  protected function getTroopActivePosition(Enemy $battler): Vector2
  {
    return new Vector2($battler->position->x + self::TROOP_STEP_X_OFFSET, $battler->position->y);
  }

  /**
   * Renders the party on the battle screen.
   *
   * @param Party $party The party to render.
   * @return void
   */
  public function renderParty(Party $party): void
  {
    foreach ($party->battlers->toArray() as $index => $battler) {
      if ($battler->isKnockedOut) {
        continue;
      }

      $this->renderPartyBattler(
        $battler,
        $this->getPartyIdlePosition($index)
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
    foreach ($troop->members->toArray() as $battler) {
      if ($battler->isKnockedOut) {
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
    $partyBattlers = $this->battleScreen->party->battlers->toArray();
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

    if (is_int($this->focusedTroopIndex)) {
      $battler = $troopMembers[$this->focusedTroopIndex] ?? null;

      if ($battler instanceof Enemy && ! $battler->isKnockedOut) {
        $this->renderTroopFocusMarker($battler, $this->blinkFocusedTroop);
      }
    }

    if (is_int($this->focusedPartyIndex)) {
      $battler = $partyBattlers[$this->focusedPartyIndex] ?? null;

      if ($battler instanceof Character && ! $battler->isKnockedOut) {
        $this->renderPartyFocusMarker($battler, $this->focusedPartyIndex, $this->blinkFocusedParty);
      }
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
    $this->eraseTroopBattler($battler);
    $activePosition = $this->getTroopActivePosition($battler);
    $this->renderBattlerSprite(
      $battler->image,
      $this->position->x + $activePosition->x,
      $this->position->y + $activePosition->y
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
    $activePosition = $this->getTroopActivePosition($battler);
    $this->eraseBattlerSprite(
      $battler->image,
      $this->position->x + $activePosition->x,
      $this->position->y + $activePosition->y
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
    $this->focusedPartyIndex = $index >= 0 ? $index : null;
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
    if ($this->focusedPartyIndex === $index) {
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
    $this->focusedTroopIndex = $index >= 0 ? $index : null;
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
    $this->focusedTroopIndex = null;
    $this->blinkFocusedTroop = false;
  }

  /**
   * Clears any currently focused party battler.
   *
   * @return void
   */
  public function clearPartyFocus(): void
  {
    $this->focusedPartyIndex = null;
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
    $spriteWidth = $this->getSpriteWidth($battler->image);
    $badgeWidth = TerminalText::displayWidth($badge);
    $x = $this->position->x + $battler->position->x + max(0, intdiv(max(0, $spriteWidth - $badgeWidth), 2));
    $y = $this->position->y + $battler->position->y - 1;

    $this->renderIndicator($badge, $x, $y);
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

    $this->renderIndicator($badge, $x, $y);
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
    $x = $this->position->x + $battler->position->x - 2;
    $y = $this->position->y + $battler->position->y + intdiv(count($battler->image), 2);

    $this->renderIndicator($marker, $x, $y);
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

    $this->renderIndicator($marker, $x, $y);
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
    $indicatorWidth = TerminalText::displayWidth($text);
    $minX = $this->position->x + 1;
    $maxX = $this->position->x + $this->width - $indicatorWidth - 1;
    $minY = $this->position->y + 1;
    $maxY = $this->position->y + $this->height - 2;

    Console::cursor()->moveTo(
      clamp($x, $minX, max($minX, $maxX)),
      clamp($y, $minY, max($minY, $maxY))
    );
    $this->output->write($text);
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
    $prefix = $blink ? "\033[5m" : '';

    return $prefix . $this->battleScreen->getSelectionColor()->value . $text . Color::RESET->value;
  }

  /**
   * Returns the display width of the widest sprite row.
   *
   * @param string[] $spriteData The sprite rows.
   * @return int The widest row width.
   */
  protected function getSpriteWidth(array $spriteData): int
  {
    $width = 0;

    foreach ($spriteData as $row) {
      $width = max($width, TerminalText::displayWidth($row));
    }

    return $width;
  }
}
