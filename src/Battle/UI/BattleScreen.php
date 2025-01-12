<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Battle\UI\States\BattleScreenState;
use Ichiloto\Engine\Battle\UI\States\PlayerActionState;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class BattleScreen. Represents the battle screen.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleScreen implements CanRender, CanUpdate
{
  /**
   * The width of the battle screen.
   */
  const int WIDTH = 135;
  /**
   * The height of the battle screen.
   */
  const int HEIGHT = 36;
  /**
   * @var Rect The dimensions of the screen.
   */
  protected(set) Rect $screenDimensions;
  /**
   * @var BattleCharacterNameWindow The character name window.
   */
  protected(set) BattleCharacterNameWindow $characterNameWindow;
  /**
   * @var BattleCharacterStatusWindow The character status window.
   */
  protected(set) BattleCharacterStatusWindow $characterStatusWindow;
  /**
   * @var BattleCommandContextWindow The command context window.
   */
  protected(set) BattleCommandContextWindow $commandContextWindow;
  /**
   * @var BattleCommandWindow The command window.
   */
  protected(set) BattleCommandWindow $commandWindow;
  /**
   * @var BattleFieldWindow The field window.
   */
  protected(set) BattleFieldWindow $fieldWindow;
  /**
   * @var BattleMessageWindow The message window for the battle screen.
   */
  protected(set) BattleMessageWindow $messageWindow;
  /**
   * @var BorderPackInterface The border pack for the battle screen.
   */
  protected(set) BorderPackInterface $borderPack;
  /**
   * @var Party The party in the battle scene.
   */
  public Party $party {
    get {
      return $this->battleScene->party ?? throw new RuntimeException('The party is not set in the battle scene.');
    }
  }
  /**
   * @var BattleScreenState|null The state of the battle screen.
   */
  protected(set) ?BattleScreenState $state = null;
  /**
   * @var bool Whether the battle screen is alerting.
   */
  protected bool $isAlerting = false {
    get {
      return $this->isAlerting;
    }

    set {
      $this->isAlerting = $value;

      if ($this->isAlerting) {
        $this->alertHideTime = Time::getTime() + $this->alertDuration;
      } else {
        $this->messageWindow->hide();
      }
    }
  }
  /**
   * @var float The duration of the message.
   */
  protected float $alertDuration = 3.0; // seconds
  /**
   * @var float The time to hide the message.
   */
  protected float $alertHideTime = 0;
  /**
   * @var Camera The camera.
   */
  public Camera $camera {
    get {
      return $this->battleScene->camera;
    }
  }
  /**
   * @var CharacterInterface|null The active character.
   */
  public ?CharacterInterface $activeCharacter = null;
  protected(set) PlayerActionState $playerActionState;

  /**
   * Create a new instance of the battle screen.
   *
   * @param BattleScene $battleScene The battle scene.
   */
  public function __construct(protected BattleScene $battleScene)
  {
    $leftMargin = intval((get_screen_width() - self::WIDTH) / 2);
    $topMargin = 0;

    $this->screenDimensions = new Rect($leftMargin, $topMargin, self::WIDTH, self::HEIGHT);
    $borderPack = config(ProjectConfig::class, 'ui.menu.border', new DefaultBorderPack());

    if (! $borderPack instanceof BorderPackInterface) {
      throw new InvalidArgumentException('The border pack must implement the BorderPackInterface.');
    }

    $this->borderPack = $borderPack;
    $this->initializeWindows();
    $this->initializeScreenStates();
  }

  /**
   * Initialize the windows for the battle screen.
   *
   * @return void
   */
  protected function initializeWindows(): void
  {
    $this->fieldWindow = new BattleFieldWindow($this);
    $this->messageWindow = new BattleMessageWindow($this);
    $this->commandWindow = new BattleCommandWindow($this);
    $this->commandContextWindow = new BattleCommandContextWindow($this);
    $this->characterNameWindow = new BattleCharacterNameWindow($this);
    $this->characterStatusWindow = new BattleCharacterStatusWindow($this, $this->battleScene->camera);
  }

  /**
   * Hide the controls for the battle screen.
   *
   * @return void
   */
  public function hideControls(): void
  {
    $this->commandWindow->erase();
    $this->commandContextWindow->erase();
    $this->characterNameWindow->erase();
    $this->characterStatusWindow->erase();
  }

  /**
   * Show the controls for the battle screen.
   *
   * @return void
   */
  public function showControls(): void
  {
    $this->commandWindow->render();
    $this->commandContextWindow->render();
    $this->characterNameWindow->render();
    $this->characterStatusWindow->render();
  }

  /**
   * Set the state of the battle scene.
   *
   * @param BattleScreenState $state The state of the battle scene.
   * @return void
   */
  public function setState(BattleScreenState $state): void
  {
     $this->state?->exit();
     $this->state = $state;
     $this->state->enter();
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->fieldWindow->render();
    $this->showControls();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->fieldWindow->erase();
    $this->messageWindow->erase();
    $this->hideControls();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if ($this->isAlerting) {
      if (Time::getTime() >= $this->alertHideTime) {
        $this->isAlerting = false;
      }
    }
  }

  /**
   * Show an alert message on the battle screen.
   *
   * @param string $text The text to display.
   * @return void
   */
  public function alert(string $text): void
  {
    $this->messageWindow->setText($text);
    $this->isAlerting = true;
  }

  protected function initializeScreenStates(): void
  {
    $this->playerActionState = new PlayerActionState($this);
  }
}