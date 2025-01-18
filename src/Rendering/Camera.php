<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\NotImplementedException;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Camera. The camera.
 *
 * @package Ichiloto\Engine\Rendering
 */
class Camera implements CanStart, CanResume, CanRender, CanUpdate
{
  /**
   * @var Rect The drawable screen area.
   */
  public Rect $screen;
  /**
   * @var OutputInterface The output.
   */
  protected OutputInterface $output;
  /**
   * @var Vector2 The position of the camera.
   */
  protected Vector2 $center {
    get {
      return new Vector2(
        $this->screen->getX() / 2,
        $this->screen->getY() / 2
      );
    }

    set {
      $x = ($this->screen->getX() + $this->screen->getWidth() / 2) + $value->x;
      $y = ($this->screen->getY() + $this->screen->getHeight() / 2) + $value->y;
      $this->center = new Vector2($x, $y);
    }
  }
  /**
   * @var Vector2 The position of the camera.
   */
  public Vector2 $position {
    get {
      return $this->screen->position;
    }

    set {
      $this->screen->setX($value->x);
      $this->screen->setY($value->y);
    }
  }

  /**
   * @var string[] The world space.
   */
  public array $worldSpace = [];

  /**
   * Camera constructor.
   *
   * @param SceneInterface $scene The scene that this camera is rendering.
   * @param int $width The width of the camera.
   * @param int $height The height of the camera.
   * @param Vector2 $position The position of the camera.
   * @param Player|null $player The player.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT,
    Vector2 $position = new Vector2(0, 0),
    protected ?Player $player = null,
    array $worldSpace = []
  )
  {
    $this->output = new ConsoleOutput();
    $this->screen = new Rect(0, 0, $width, $height);
    $this->position = $position;

    if ($worldSpace) {
      $this->worldSpace = $worldSpace;
    } else {
      $this->worldSpace = array_fill(0, $this->screen->getHeight(), str_repeat(' ', $this->screen->getWidth()));
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->scene->getUI()->start();
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    $this->scene->getUI()->stop();
  }

  /**
   * Renders the map.
   *
   * @return void
   */
  public function renderMap(): void
  {
    for ($row = 0; $row < $this->screen->getHeight(); $row++) {
      $worldSpaceY = $this->position->y + $row;
      $content = substr($this->worldSpace[$worldSpaceY] ?? str_repeat(' ', $this->width), $this->position->x, $this->width);

      $screenSpaceY = $this->getScreenSpacePosition(new Vector2(0, $worldSpaceY))->y;
      $this->draw($content, $this->position->x, $screenSpaceY);
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    foreach ($this->scene->getRootGameObjects() as $gameObject) {
      if ($gameObject->isActive && $this->canSee($gameObject)) {
        $gameObject->render();
      }
    }

    $this->scene->getUI()->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    foreach ($this->scene->getRootGameObjects() as $gameObject) {
      if ($gameObject->isActive && $this->canSee($gameObject)) {
        $gameObject->erase();
      }
    }

    $this->scene->getUI()->erase();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->scene->getUI()->resume();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->scene->getUI()->suspend();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->scene->getUI()->update();
  }

  /**
   * Checks if a game object is visible.
   *
   * @param GameObject $gameObject The game object to check.
   * @return bool True if the game object is visible, false otherwise.
   */
  public function canSee(GameObject $gameObject): bool
  {
    if ($gameObject->position->x < $this->position->x) {
      return false;
    }

    if ($gameObject->position->x > $this->position->x + $this->width) {
      return false;
    }

    if ($gameObject->position->y < $this->position->y) {
      return false;
    }

    if ($gameObject->position->y > $this->position->y + $this->height) {
      return false;
    }

    return true;
  }

  /**
   * Draws content on the screen.
   *
   * @param iterable|string $content The content to draw.
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   */
  public function draw(iterable|string $content, int $x = 0, int $y = 0): void
  {
    if (is_string($content)) {
      $content = explode("\n", $content);
    }

    $buffer = [];

    foreach ($content as $index => $line) {
      if ($index > $this->screen->getHeight()) {
        break;
      }
      $buffer[] = mb_substr($line, 0, $this->screen->getWidth());
    }

    $content = $buffer;

    if (is_iterable($content)) {
      foreach ($content as $index => $line) {
        $row = $y + $index;
        $row = clamp($row, 0, $this->screen->getHeight());
        $column = $this->screen->getX() + $x;
        $column = clamp($column, 0, $this->screen->getWidth());
//        Console::cursor()->moveTo($this->screen->getX() + $x, $this->screen->getY() + $y + $row);
//        $this->output->write($line);
        Console::write($line, $column, $row);
      }
    } else {
      $row = clamp($y, 0, $this->screen->getHeight());
      $column = clamp($x, 0, $this->screen->getWidth());
      Console::write($content, $column, $row);
    }
  }

  /**
   * Moves the camera in a specified direction.
   *
   * @param Vector2 $direction The direction to move the camera.
   */
  public function move(Vector2 $direction): void
  {
    $this->moveBy($direction->x, $direction->y);
  }

  /**
   * Moves the camera by a specified amount.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   */
  public function moveBy(int $x, int $y): void
  {
    $x = $this->position->x + $x;
    $y = $this->position->y + $y;

    $this->moveTo($x, $y);
  }

  /**
   * Moves the camera to a new position.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   */
  public function moveTo(int $x, int $y): void
  {
    $this->position = new Vector2($x, $y);
  }

  /**
   * Gets the screen space position of a world space position.
   *
   * @param Vector2 $worldSpacePosition The world space position.
   * @return Vector2 The screen space position.
   */
  public function getScreenSpacePosition(Vector2 $worldSpacePosition): Vector2
  {
    $screenSpaceX = $worldSpacePosition->x - $this->position->x;
    $screenSpaceY = $worldSpacePosition->y - $this->position->y;
    return new Vector2($screenSpaceX, $screenSpaceY);
  }

  /**
   * Gets the world space position of a screen space position.
   *
   * @param Vector2 $screenSpacePosition The screen space position.
   * @return Vector2 The world space position.
   */
  public function getWorldSpacePosition(Vector2 $screenSpacePosition): Vector2
  {
    return Vector2::sum($screenSpacePosition, $this->position);
  }

  /**
   * Renders content on the screen.
   *
   * @param array $output The output to render.
   * @param Vector2 $worldSpacePosition The world space position.
   */
  public function renderOnScreen(array $output, Vector2 $worldSpacePosition): void
  {
    $screenSpacePosition = $this->getScreenSpacePosition($worldSpacePosition);
    Console::cursor()->moveTo($screenSpacePosition->x + 1, $screenSpacePosition->y +1);
    $this->output->write($output);
  }

  /**
   * Resets the position of the camera.
   *
   * @return void
   */
  public function resetPosition(): void
  {
    $x = 0;
    $y = 0;

    $this->screen->setX($x);
    $this->screen->setY($y);
  }
}