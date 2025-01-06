<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

class BattleScreen implements CanRender
{
  const int WIDTH = 135;
  const int HEIGHT = 36;

  protected(set) Rect $screenDimensions;
  protected(set) BattleCharacterNameWindow $characterNameWindow;
  protected(set) BattleCharacterStatusWindow $characterStatusWindow;
  protected(set) BattleCommandContextWindow $commandContextWindow;
  protected(set) BattleCommandWindow $commandWindow;
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
   * @var BattleSceneState|null The state of the battle scene.
   */
  protected(set) ?BattleSceneState $state = null;

  /**
   * Create a new instance of the battle screen.
   *
   * @param BattleScene $battleScene The battle scene.
   */
  public function __construct(protected BattleScene $battleScene)
  {
    $leftMargin = intval((get_screen_width() - self::WIDTH) / 2);
    Debug::log(var_export([
      'screen_width' => get_screen_width(),
      'WIDTH' => self::WIDTH,
      'leftMargin' => $leftMargin,
    ], true));
    $topMargin = 0;

    $this->screenDimensions = new Rect($leftMargin, $topMargin, self::WIDTH, self::HEIGHT);
    $borderPack = config(ProjectConfig::class, 'ui.menu.border', new DefaultBorderPack());
    if (! $borderPack instanceof BorderPackInterface) {
      throw new InvalidArgumentException('The border pack must implement the BorderPackInterface.');
    }

    $this->borderPack = $borderPack;
    $this->initializeWindows();
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
    $this->characterStatusWindow = new BattleCharacterStatusWindow($this);
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
   * @param BattleSceneState $state The state of the battle scene.
   * @return void
   */
  public function setState(BattleSceneState $state): void
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
}