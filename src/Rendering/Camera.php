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
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
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
  protected(set) bool $followsPlayer = true;
  protected ?CameraStateSnapshot $detachedSnapshot = null;
  /** @var array<int, NormalizedRow> Current map only; discarded on source/policy changes. */
  private array $normalizedRows = [];
  /** @var array<int, list<string>> String-authored rows are formatted once, before width policy. */
  private array $stringRowSymbols = [];
  private ?bool $normalizationPolicy = null;
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
      // Own the authored value: PHP array references must not mutate the map
      // behind its setter and leave the retained rows stale.
      $this->worldSpace = array_map(static fn($row) => is_array($row)
        ? array_map(static fn($symbol) => $symbol, $row) : $row, $value);
      $this->normalizedRows = [];
      $this->stringRowSymbols = [];
      $this->normalizationPolicy = null;
      $this->worldSpaceHeight = count($value);
      $this->worldSpaceWidth = 0;
      foreach ($this->worldSpace as $y => $source) {
        if (is_array($source)) {
          $this->worldSpaceWidth = max($this->worldSpaceWidth, count($source));
        } else {
          $symbols = TerminalText::visibleSymbols((string)$source);
          $this->worldSpaceWidth = max($this->worldSpaceWidth, count($symbols));
          $this->stringRowSymbols[$y] = $symbols;
        }
      }
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
    $this->renderMapRows();
  }

  public function renderLayeredMap(MapLayerSet $layers): void
  {
    $this->renderMapRows($layers);
  }

  private function renderMapRows(?MapLayerSet $layers = null): void
  {
    $renderOffset = $this->getRenderOffset();
    $visibleWidth = $this->getVisibleWorldWidth();

    // One terminal write for the whole map instead of one per row: the
    // difference is felt most while scrolling, and on consoles where each
    // write is expensive.
    Console::beginFrame();

    for ($row = 0, $height = $this->getVisibleWorldHeight(); $row < $height; $row++) {
      $started = LatencyTrace::getTimeNow();
      $content = $this->normalizedMapRow((int)$this->position->y + $row)
        ->select((int)$this->position->x, $visibleWidth, $visibleWidth);
      LatencyTrace::end('terminal.select', $started);
      if ($layers === null) {
        Console::writeNormalizedRow($content, $renderOffset->x, $renderOffset->y + $row);
      } else {
        $this->renderLayeredMapRow($content, (int)$this->position->y + $row, $layers, $renderOffset->x, $renderOffset->y + $row);
      }
    }

    Console::endFrame();
  }

  private function renderLayeredMapRow(NormalizedRow $content, int $worldY, MapLayerSet $layers, int $screenX, int $screenY): void
  {
    $logicalX = (int)$this->position->x;
    $group = [];
    $groupStart = 0;
    $owner = null;
    foreach ($content->cells as $column => $cell) {
      if ($cell === NormalizedRow::CONTINUATION) { continue; }
      $next = $layers->getGameplayLayerAt($logicalX++, $worldY);
      if ($owner !== null && $next !== $owner) {
        PresentationLayerPolicy::drawMapLayer($owner, fn() => Console::writeNormalizedRow(
          NormalizedRow::fromSymbols($group), $screenX + $groupStart, $screenY));
        $group = [];
      }
      if ($group === []) { $groupStart = $column; }
      $group[] = $cell;
      $owner = $next;
    }
    if ($owner !== null) {
      PresentationLayerPolicy::drawMapLayer($owner, fn() => Console::writeNormalizedRow(
        NormalizedRow::fromSymbols($group), $screenX + $groupStart, $screenY));
    }
  }

  /** @return iterable<int, list<string>> Visible authored symbols, without synthetic padding. */
  public function visibleMapRows(): iterable
  {
    $width = $this->getVisibleWorldWidth();
    $height = $this->getVisibleWorldHeight();
    for ($row = 0; $row < $height; $row++) {
      $y = (int)$this->position->y + $row;
      $symbols = $this->worldSpace[$y] ?? [];
      if (!is_array($symbols)) { $symbols = $this->stringRowSymbols[$y]; }
      yield $y => array_values(array_slice($symbols, (int)$this->position->x, $width));
    }
  }

  private function normalizedMapRow(int $y): NormalizedRow
  {
    $policy = TerminalCapabilities::supportsCompositeEmoji();
    if ($this->normalizationPolicy !== $policy) {
      $this->normalizedRows = [];
      $this->normalizationPolicy = $policy;
    }
    return $this->normalizedRows[$y] ??= NormalizedRow::fromSymbols(
      array_values($this->stringRowSymbols[$y] ?? $this->worldSpace[$y] ?? [])
    );
  }

  /** Restore one logical tile through the same normalized write path as a pan. */
  public function renderBackgroundTile(int $x, int $y): void
  {
    $position = $this->getScreenSpacePosition(new Vector2($x, $y));
    if ($position->x < 0 || $position->y < 0
      || $position->x >= $this->screen->getWidth() || $position->y >= $this->screen->getHeight()) { return; }
    $row = $x >= 0 && $x < $this->worldSpaceWidth && $y >= 0 && $y < $this->worldSpaceHeight
      ? $this->normalizedMapRow($y)->select($x, 1, $this->screen->getWidth(), pad: false) : null;
    if ($row === null || $row->cells === []) { $row = NormalizedRow::fromText(' '); }
    Console::writeNormalizedRow($row, $position->x, $position->y);
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
    $this->position = $this->clampPosition(new Vector2($x, $y));
  }

  public function detach(): void
  {
    if ($this->followsPlayer) {
      $this->detachedSnapshot = $this->captureState();
    }

    $this->followsPlayer = false;
  }

  public function attach(?Player $player = null): void
  {
    $this->player = $player ?? $this->player;
    $this->followsPlayer = true;

    if ($this->player !== null) {
      $this->resetPosition($this->player);
    }
  }

  public function captureState(): CameraStateSnapshot
  {
    return new CameraStateSnapshot(
      new Vector2(intval($this->position->x), intval($this->position->y)),
      $this->followsPlayer,
    );
  }

  public function restoreState(CameraStateSnapshot $snapshot): void
  {
    $this->followsPlayer = $snapshot->followsPlayer;
    $this->position = $this->clampPosition($snapshot->position);
  }

  public function restorePrevious(): void
  {
    if ($this->detachedSnapshot !== null) {
      $snapshot = $this->detachedSnapshot;
      $this->detachedSnapshot = null;
      $this->restoreState($snapshot);
      return;
    }

    $this->attach();
  }

  public function focusOn(Vector2 $worldPosition): void
  {
    $this->position = $this->positionForFocus($worldPosition);
  }

  public function positionForFocus(Vector2 $worldPosition): Vector2
  {
    return $this->clampPosition(new Vector2(
      intval($worldPosition->x) - $this->getHorizontalFocusPosition(),
      intval($worldPosition->y) - $this->getVerticalFocusPosition(),
    ));
  }

  public function clampPosition(Vector2 $position): Vector2
  {
    return new Vector2(
      clamp(intval($position->x), 0, max(0, $this->worldSpaceWidth - $this->screen->getWidth())),
      clamp(intval($position->y), 0, max(0, $this->worldSpaceHeight - $this->screen->getHeight())),
    );
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

    // Routed through Console so the cell buffer stays a faithful picture of
    // the screen. Writing sprites straight to the terminal used to leave the
    // buffer unaware of them, which made "this row is unchanged" an unsafe
    // conclusion and left sprite trails behind the player.
    foreach (array_values($output) as $rowIndex => $row) {
      Console::write(
        TerminalText::stabilize((string) $row),
        (int) $screenSpacePosition->x,
        (int) $screenSpacePosition->y + $rowIndex
      );
    }
  }

  /**
   * Renders output directly at the provided screen-space position.
   *
   * This is useful for overlays such as wide player sprites whose terminal
   * cell width should not alter the camera's world-space bookkeeping.
   *
   * @param string[]|string $output The output to render.
   * @param Vector2 $screenSpacePosition The zero-based screen-space position.
   * @return void
   */
  public function renderAtScreenPosition(array|string $output, Vector2 $screenSpacePosition): void
  {
    $rows = is_array($output) ? $output : [$output];

    foreach (array_values($rows) as $rowIndex => $row) {
      Console::write(
        TerminalText::stabilize((string) $row),
        (int) $screenSpacePosition->x,
        (int) $screenSpacePosition->y + $rowIndex
      );
    }
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
