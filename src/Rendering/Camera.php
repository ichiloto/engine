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
use Ichiloto\Engine\IO\Console\TerminalText;
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
  public array $worldSpace = [] {
    get {
      return $this->worldSpace;
    }

    set {
      $this->worldSpace = $value;
      $this->worldSpaceHeight = count($value);
      $this->worldSpaceWidth = array_reduce($value, function ($carry, $item) {
        if (is_array($item)) {
          return max($carry, count($item));
        }

        return max($carry, TerminalText::symbolCount((string)$item));
      }, 0);
    }
  }
  /**
   * @var int The width of the world space.
   */
  public int $worldSpaceWidth = 0;
  /**
   * @var int The height of the world space.
   */
  public int $worldSpaceHeight = 0;

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
    $renderOffset = $this->getRenderOffset();
    $visibleWidth = $this->getVisibleWorldWidth();
    $visibleHeight = $this->getVisibleWorldHeight();

    for ($row = 0; $row < $visibleHeight; $row++) {
      $worldSpaceY = $this->position->y + $row;
      $worldRow = $this->worldSpace[$worldSpaceY] ?? array_fill(0, $visibleWidth, ' ');
      $content = is_array($worldRow)
        ? implode('', array_slice($worldRow, $this->position->x, $visibleWidth))
        : TerminalText::sliceSymbols((string)$worldRow, $this->position->x, $visibleWidth);
      $content = TerminalText::padRight($content, $visibleWidth);

      $this->draw($content, $renderOffset->x, $renderOffset->y + $row);
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

    if ($gameObject->position->x > $this->position->x + $this->width - 1) {
      return false;
    }

    if ($gameObject->position->y < $this->position->y) {
      return false;
    }

    if ($gameObject->position->y > $this->position->y + $this->height - 1) {
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
      if ($index >= $this->screen->getHeight()) {
        break;
      }
      $buffer[] = TerminalText::truncateToWidth((string)$line, $this->screen->getWidth());
    }

    $content = $buffer;

    if (is_iterable($content)) {
      foreach ($content as $index => $line) {
        $row = $y + $index;
        $row = clamp($row, 0, max(0, $this->screen->getHeight() - 1));
        $column = clamp($x, 0, max(0, $this->screen->getWidth() - 1));
        Console::write($line, $column, $row);
      }
    } else {
      $row = clamp($y, 0, max(0, $this->screen->getHeight() - 1));
      $column = clamp($x, 0, max(0, $this->screen->getWidth() - 1));
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
    $renderOffset = $this->getRenderOffset();
    $screenSpaceX = $worldSpacePosition->x - $this->position->x + $renderOffset->x;
    $screenSpaceY = $worldSpacePosition->y - $this->position->y + $renderOffset->y;
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
    $renderOffset = $this->getRenderOffset();

    return new Vector2(
      $screenSpacePosition->x - $renderOffset->x + $this->position->x,
      $screenSpacePosition->y - $renderOffset->y + $this->position->y,
    );
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
   * @param Player $player
   * @return void
   */
  public function resetPosition(Player $player): void
  {
    $x = 0;
    $y = 0;
    $maxX = max(0, $this->worldSpaceWidth - $this->screen->getWidth());
    $maxY = max(0, $this->worldSpaceHeight - $this->screen->getHeight());

    if ($this->worldSpaceWidth > $this->screen->getWidth()) {
      $x = clamp(intval($player->position->x) - $this->getHorizontalFocusPosition(), 0, $maxX);
    }

    if ($this->worldSpaceHeight > $this->screen->getHeight()) {
      $y = clamp(intval($player->position->y) - $this->getVerticalFocusPosition(), 0, $maxY);
    }

    $this->screen->setX($x);
    $this->screen->setY($y);
  }

  /**
   * Resizes the camera viewport to match the current screen size.
   *
   * @param int $width The new viewport width.
   * @param int $height The new viewport height.
   * @return void
   */
  public function resizeViewport(int $width, int $height): void
  {
    $this->width = max(1, $width);
    $this->height = max(1, $height);
    $this->screen->setWidth($this->width);
    $this->screen->setHeight($this->height);

    $maxX = max(0, $this->worldSpaceWidth - $this->screen->getWidth());
    $maxY = max(0, $this->worldSpaceHeight - $this->screen->getHeight());

    $this->screen->setX(clamp($this->screen->getX(), 0, $maxX));
    $this->screen->setY(clamp($this->screen->getY(), 0, $maxY));
  }

  /**
   * Returns the horizontal focus column used to keep the player centered.
   *
   * @return int The focus column.
   */
  public function getHorizontalFocusPosition(): int
  {
    return intdiv(max(0, $this->screen->getWidth() - 1), 2);
  }

  /**
   * Returns the vertical focus row used to keep the player centered.
   *
   * @return int The focus row.
   */
  public function getVerticalFocusPosition(): int
  {
    return intdiv(max(0, $this->screen->getHeight() - 1), 2);
  }

  /**
   * Returns the viewport render offset used to center smaller maps.
   *
   * The camera position remains in world space so that scrolling logic does
   * not change. Only the on-screen render origin is adjusted.
   *
   * @return Vector2 The render offset.
   */
  protected function getRenderOffset(): Vector2
  {
    $x = $this->worldSpaceWidth < $this->screen->getWidth()
      ? intdiv($this->screen->getWidth() - $this->worldSpaceWidth, 2)
      : 0;
    $y = $this->worldSpaceHeight < $this->screen->getHeight()
      ? intdiv($this->screen->getHeight() - $this->worldSpaceHeight, 2)
      : 0;

    return new Vector2(max(0, $x), max(0, $y));
  }

  /**
   * Returns the width of the world currently visible in the viewport.
   *
   * @return int The visible world width.
   */
  protected function getVisibleWorldWidth(): int
  {
    return min($this->screen->getWidth(), max(0, $this->worldSpaceWidth - $this->position->x));
  }

  /**
   * Returns the height of the world currently visible in the viewport.
   *
   * @return int The visible world height.
   */
  protected function getVisibleWorldHeight(): int
  {
    return min($this->screen->getHeight(), max(0, $this->worldSpaceHeight - $this->position->y));
  }
}
