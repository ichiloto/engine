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
  protected(set) Rect $screen;
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
        $this->center->x - $this->screen->getX() / 2,
        $this->center->y - $this->screen->getY() / 2
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
  protected Vector2 $position {
    get {
      return $this->screen->position;
    }

    set {
      $this->screen->setX($value->x);
      $this->screen->setY($value->y);
    }
  }

  /**
   * Camera constructor.
   *
   * @param SceneInterface $scene The scene that this camera is rendering.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected int $width = DEFAULT_SCREEN_WIDTH,
    protected int $height = DEFAULT_SCREEN_HEIGHT,
    Vector2 $position = new Vector2(0, 0),
    protected ?Player $player = null
  )
  {
    $this->output = new ConsoleOutput();
    $this->screen = new Rect(0, 0, $width, $height);
    $this->position = $position;
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
      foreach ($content as $index => $row) {
        Console::write($row, $this->screen->getX() + $x, $this->screen->getY() + $y + $index);
      }
    } else {
      Console::write($content, $this->screen->getX() + $x, $this->screen->getY() + $y);
    }
  }
}