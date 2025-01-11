<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Windows\Window;
use RuntimeException;

/**
 * Represents the battlefield window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleFieldWindow extends Window
{
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
      $output = str_repeat(' ', mb_strlen($row));
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
   * Renders the party on the battle screen.
   *
   * @param Party $party The party to render.
   * @return void
   */
  public function renderParty(Party $party): void
  {
    foreach ($party->battlers->toArray() as $index => $battler) {
      $this->renderPartyBattler(
        $battler,
        $this->partyBattlerPositions->idlePositions[$index] ?? throw new RuntimeException('Invalid party battler position.')
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
      $this->renderTroopBattler($battler);
    }
  }

  /**
   * Selects a party battler. The selected battler will be indicated by a cursor and will step forward.
   *
   * @param int $index The index of the party battler to select.
   * @return void
   */
  public function selectPartyBattler(int $index): void
  {
    // TODO: Implement selectPartyBattler() method.
  }

  /**
   * Focuses on a party battler. The focused battler will be indicated by a cursor but will not step forward.
   *
   * @param int $index The index of the party battler to focus on.
   * @return void
   */
  public function focusPartyBattler(int $index): void
  {
    // TODO: Implement focusPartyBattler() method.
  }

  /**
   * Blurs a focussed party battler. The blurred battler will no longer be indicated by a cursor.
   *
   * @param int $index The index of the party battler to focus on.
   * @return void
   */
  public function blurPartyBattler(int $index): void
  {
    // TODO: Implement blurPartyBattler() method.
  }

  /**
   * Selects a troop battler. The selected battler will be indicated by a cursor and will step forward.
   *
   * @param int $index The index of the troop battler to select.
   * @return void
   */
  public function selectTroopBattler(int $index): void
  {
    // TODO: Implement selectTroopBattler() method.
  }

  /**
   * Focuses on a troop battler. The focused battler will be indicated by a cursor but will not step forward.
   *
   * @param int $index The index of the party battler to focus on.
   * @return void
   */
  public function focusOnTroopBattler(int $index): void
  {
    // TODO: Implement focusOnTroopBattler() method.
  }
}