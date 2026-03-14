<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\UI\States\BattleScreenState;
use Ichiloto\Engine\Battle\UI\States\PlayerActionState;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\Util\Config\ProjectConfig;
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
   * @var Troop The troop in the battle scene.
   */
  public Troop $troop {
    get {
      return $this->battleScene->troop ?? throw new RuntimeException('The troop is not set in the battle scene.');
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
        $this->hideMessage();
      }
    }
  }
  /**
   * @var float The duration of the message.
   */
  protected float $alertDuration = 3.0; // seconds
  /**
   * @var BattlePacing The active pacing profile.
   */
  protected BattlePacing $pacing;
  /**
   * @var Color The configured selection highlight color.
   */
  protected Color $selectionColor;
  /**
   * @var float The time to hide the message.
   */
  protected float $alertHideTime = 0;
  /**
   * @var bool Whether the info panel is visible.
   */
  protected bool $isMessageVisible = false;
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
    $this->screenDimensions = $this->resolveScreenDimensions();
    $borderPack = config(ProjectConfig::class, 'ui.menu.border', new DefaultBorderPack());

    if (! $borderPack instanceof BorderPackInterface) {
      throw new InvalidArgumentException('The border pack must implement the BorderPackInterface.');
    }

    $this->borderPack = $borderPack;
    $this->pacing = BattlePacing::fromConfig();
    $this->selectionColor = $this->resolveSelectionColor(
      config(ProjectConfig::class, 'ui.battle.selection_color', Color::LIGHT_BLUE)
    );
    $this->alertDuration = $this->pacing->getMessageDurationSeconds();
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
    $this->renderField();
    $this->showControls();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->fieldWindow->erase();
    $this->hideMessage();
    $this->hideControls();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->state?->update();

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
    $this->showMessage($text);
    $this->isAlerting = true;
  }

  /**
   * Shows the provided text in the info panel until it is hidden.
   *
   * @param string $text The text to display.
   * @return void
   */
  public function showMessage(string $text): void
  {
    if ($this->isAlerting) {
      $this->isAlerting = false;
    }

    $this->isMessageVisible = true;
    $this->messageWindow->setText($text);
  }

  /**
   * Hides the info panel.
   *
   * @return void
   */
  public function hideMessage(): void
  {
    $this->isMessageVisible = false;
    $this->messageWindow->hide();
  }

  /**
   * Returns the pacing profile for the battle screen.
   *
   * @return BattlePacing
   */
  public function getPacing(): BattlePacing
  {
    return $this->pacing;
  }

  /**
   * Renders the battlefield and all active battlers.
   *
   * @return void
   */
  public function renderField(): void
  {
    $this->fieldWindow->render();
    $this->fieldWindow->renderParty($this->party);
    $this->fieldWindow->renderTroop($this->troop);
  }

  /**
   * Refreshes the battle UI without rebuilding its state.
   *
   * @return void
   */
  public function refresh(): void
  {
    $this->fieldWindow->erase();
    $this->renderField();
    $this->showControls();

    if ($this->isMessageVisible) {
      $this->messageWindow->render();
    }
  }

  /**
   * Refreshes only the battlefield.
   *
   * @return void
   */
  public function refreshField(): void
  {
    $this->fieldWindow->erase();
    $this->renderField();

    if ($this->isMessageVisible) {
      $this->messageWindow->render();
    }
  }

  protected function initializeScreenStates(): void
  {
    $this->playerActionState = new PlayerActionState($this);
  }

  /**
   * Recomputes the battle layout to match the current terminal size.
   *
   * @return void
   */
  public function refreshLayout(): void
  {
    $this->screenDimensions = $this->resolveScreenDimensions();
    $this->fieldWindow->setPosition($this->getWindowPosition(0, 0));
    $this->messageWindow->setPosition($this->getWindowPosition(2, 1));
    $this->commandWindow->setPosition($this->getWindowPosition(0, $this->fieldWindow->height));
    $this->commandContextWindow->setPosition($this->getWindowPosition($this->commandWindow->width, $this->fieldWindow->height));
    $this->characterNameWindow->setPosition(
      $this->getWindowPosition(
        $this->commandWindow->width + $this->commandContextWindow->width,
        $this->fieldWindow->height
      )
    );
    $this->characterStatusWindow->setPosition(
      $this->getWindowPosition(
        $this->commandWindow->width + $this->commandContextWindow->width + $this->characterNameWindow->width,
        $this->fieldWindow->height
      )
    );
  }

  /**
   * Returns the selection highlight color for battle input windows.
   *
   * @return Color The configured highlight color.
   */
  public function getSelectionColor(): Color
  {
    return $this->selectionColor;
  }

  /**
   * Applies battle-selection styling to a line of content.
   *
   * @param string $text The content line to style.
   * @param bool $blink Whether the line should blink to indicate pending input.
   * @return string The styled line.
   */
  public function styleSelectionLine(string $text, bool $blink = false): string
  {
    $prefix = $blink ? "\033[5m" : '';

    return $prefix . $this->selectionColor->value . $text . Color::RESET->value;
  }

  /**
   * Resolves the configured battle selection color.
   *
   * @param mixed $configuredColor The configured color value.
   * @return Color The resolved color.
   */
  protected function resolveSelectionColor(mixed $configuredColor): Color
  {
    if ($configuredColor instanceof Color) {
      return $configuredColor;
    }

    if (is_string($configuredColor)) {
      $normalizedName = strtoupper(str_replace([' ', '-'], '_', $configuredColor));

      foreach (Color::cases() as $color) {
        if ($color->name === $normalizedName || $color->value === $configuredColor) {
          return $color;
        }
      }
    }

    return Color::LIGHT_BLUE;
  }

  /**
   * Resolves the screen-space frame used to center the battle layout.
   *
   * @return Rect The centered battle frame.
   */
  protected function resolveScreenDimensions(): Rect
  {
    $leftMargin = max(0, intdiv(get_screen_width() - self::WIDTH, 2));
    $topMargin = max(0, intdiv(get_screen_height() - self::HEIGHT, 2));

    return new Rect($leftMargin, $topMargin, self::WIDTH, self::HEIGHT);
  }

  /**
   * Returns a position relative to the centered battle frame.
   *
   * @param int $xOffset The x offset inside the battle frame.
   * @param int $yOffset The y offset inside the battle frame.
   * @return \Ichiloto\Engine\Core\Vector2 The resolved window position.
   */
  protected function getWindowPosition(int $xOffset, int $yOffset): \Ichiloto\Engine\Core\Vector2
  {
    return new \Ichiloto\Engine\Core\Vector2(
      $this->screenDimensions->getLeft() + $xOffset,
      $this->screenDimensions->getTop() + $yOffset,
    );
  }
}
