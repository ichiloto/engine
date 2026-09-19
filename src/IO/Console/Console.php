<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;

use Exception;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Terminal;

/**
 * Represents the console.
 *
 * @package Ichiloto\Engine\IO\Console
 */
class Console
{
  /**
   * @var int How many batched frames are open.
   */
  private static int $frameDepth = 0;
  /**
   * @var bool Whether the alternate screen buffer is currently in use.
   */
  private static bool $usingAlternateScreen = false;
  /**
   * @var bool Whether the terminal has been handed back to the user.
   *
   * Once it has, nothing may draw to it. Quitting happens partway through a
   * frame, and whatever was midway through drawing would otherwise finish its
   * work on the user's shell.
   */
  private static bool $terminalHandedBack = false;
  /**
   * @var array<int, list<array{start: int, end: int}>> Changed cell spans by row.
   *
   * A frame stores dirtiness rather than a journal of intermediate paints.
   * Overlapping spans are coalesced, but separated changes remain separated.
   * This matters for windows: an empty content row changes its two border
   * cells, not every blank cell between them. Treating that row as one dirty
   * envelope made a visually sparse shop frame larger than a dense map and
   * exposed terminal-buffer limits during the first paint.
   */
  private static array $frameRows = [];

  /**
   * @var array<int, array{start: int, end: int}> Explicit repaint spans requested
   * while a complete screen is being recomposed.
   *
   * Ordinary recomposition discards intermediate dirtiness and derives the
   * final diff from the old and new buffers. An explicit repaint is different:
   * its caller is repairing terminal state that may already disagree with the
   * canonical buffer, so it must survive even when the logical cells compare
   * equal.
   */
  private static array $recomposeRepaintRows = [];

  /** Whether a complete-screen composition currently owns the outer frame. */
  private static bool $isRecomposing = false;

  /**
   * Placeholder marker used for continuation cells of wide terminal symbols.
   */
  private const string WIDE_SYMBOL_CONTINUATION = NormalizedRow::CONTINUATION;

  /**
   * How long to wait for the terminal to accept more output before retrying.
   */
  private const int WRITE_STALL_TIMEOUT_MICROSECONDS = 20000;

  /**
   * Maximum number of bytes offered to the terminal in one write.
   *
   * A complete styled field can be hundreds of kilobytes even though it is
   * only a few dozen terminal rows. Some PTYs accept an initial prefix of a
   * large fwrite() and then temporarily refuse the remainder. Keeping each
   * request below a conservative terminal-buffer size makes back-pressure
   * observable and portable instead of depending on one stream wrapper's
   * handling of an oversized write.
   */
  private const int WRITE_CHUNK_BYTES = 4096;

  /**
   * Clean cells cheaper to resend than to address with another cursor move.
   *
   * Keeping short gaps inside one dirty span preserves natural text runs
   * such as "row one" while still separating the distant vertical borders
   * of an otherwise empty window row.
   */
  private const int DIRTY_SPAN_MERGE_GAP = 8;

  /**
   * How many consecutive stalled writes to tolerate before reporting a
   * broken stream. At the timeout above this is roughly two seconds, far
   * longer than a terminal needs to drain.
   */
  private const int WRITE_MAX_STALLED_ATTEMPTS = 100;

  /**
   * @var Game|null $game The game instance.
   */
  private static ?Game $game = null;
  /**
   * @var array<int, list<string>> Authoritative scene cells, never serialized rows.
   */
  private static array $buffer = [];
  private static bool $trackLayers = false;
  private static ?string $activeLayer = null;
  private static int $activeLayerPriority = 0;
  private static bool $replaceUnderlyingLayer = false;
  /** @var array<string, int> Named layer priorities in drawing order. */
  private static array $layerPriorities = [];
  /** @var array<int, array<int, array{base: string, layers: array<string, string>}>> */
  private static array $layerCells = [];
  /** Retained transient surfaces, separate from the live scene beneath them. */
  private static array $overlays = [];

  /** @param list<string> $lines Opaque rows positioned in zero-based terminal cells. */
  public static function replaceOverlay(string $id, array $lines, int $x, int $y, int $priority): void
  {
    new PresentationTextLayer($id, $priority, []);
    if ($id === 'world' || isset(self::$layerPriorities[$id])) {
      throw new \InvalidArgumentException('An overlay requires its own presentation layer ID.');
    }
    if ($priority < PresentationLayerPolicy::WORLD) {
      throw new \InvalidArgumentException('An overlay cannot be placed below the world.');
    }
    $rows = [];
    foreach ($lines as $offset => $line) {
      $row = $y + $offset;
      if ($row < 0 || $row >= self::$height) { continue; }
      $column = $x;
      foreach (NormalizedRow::fromText($line)->cells as $symbol) {
        if ($symbol === self::WIDE_SYMBOL_CONTINUATION) { continue; }
        $width = max(1, TerminalText::getSymbolWidth($symbol));
        if ($column >= self::$width) { break; }
        for ($cell = max(0, $column); $cell < min(self::$width, $column + $width); $cell++) {
          $rows[$row][$cell] = $column < 0 || $column + $width > self::$width
            ? ' ' : ($cell === $column ? $symbol : self::WIDE_SYMBOL_CONTINUATION);
        }
        $column += $width;
      }
    }
    self::updateOverlay($id, $rows === [] ? null : ['priority' => $priority, 'rows' => $rows]);
  }

  public static function removeOverlay(string $id): void
  {
    if (isset(self::$overlays[$id])) { self::updateOverlay($id, null); }
  }

  private static function updateOverlay(string $id, ?array $overlay): void
  {
    if (self::$terminalHandedBack || (self::$overlays[$id] ?? null) === $overlay) { return; }
    if ($overlay !== null) { self::assertLayerCapacity($id); }
    if (self::$buffer === []) { self::$buffer = self::getEmptyBuffer(); }
    $previous = self::$overlays;
    $before = self::visibleCellRows();
    $previousRows = self::$frameRows;
    $previousDepth = self::$frameDepth;
    self::beginFrame();
    try {
      if ($overlay === null) { unset(self::$overlays[$id]); }
      else { self::$overlays[$id] = $overlay; }
      uasort(self::$overlays, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
      if (self::$terminalOutputEnabled && !self::$isRecomposing) {
        $after = self::visibleCellRows();
        foreach (array_unique([...array_keys($previous[$id]['rows'] ?? []), ...array_keys($overlay['rows'] ?? [])]) as $row) {
          if ($row >= self::$height) { continue; }
          foreach (self::changedCellSpansFromCells($before[$row] ?? [], $after[$row] ?? []) as $span) {
            self::writeBufferRow($row, $span['start'], $span['end'] - $span['start'] + 1);
          }
        }
      }
      self::endFrame();
    } catch (\Throwable $exception) {
      self::$overlays = $previous;
      self::$frameRows = $previousRows;
      self::$frameDepth = $previousDepth;
      throw $exception;
    }
  }

  /** @param string[] $cells @param array<string, true> $excluded @return string[] */
  private static function compositeOverlayRow(array $cells, int $row, array $excluded = []): array
  {
    foreach (self::$overlays as $id => $overlay) {
      if (isset($excluded[$id])) { continue; }
      foreach (self::overlayRowCells($overlay, $row, $excluded) as $x => $symbol) {
        if ($symbol === self::WIDE_SYMBOL_CONTINUATION) { continue; }
        $width = max(1, TerminalText::getSymbolWidth($symbol));
        self::clearCellRange($cells, $x, $width);
        $cells[$x] = $symbol;
        for ($cell = $x + 1; $cell < $x + $width; $cell++) { $cells[$cell] = self::WIDE_SYMBOL_CONTINUATION; }
      }
    }
    return $cells;
  }

  /** Whole visible glyphs shared by native and structured composition. */
  private static function overlayRowCells(array $overlay, int $row, array $excluded): array
  {
    $cells = [];
    if ($row >= self::$height) { return $cells; }
    foreach ($overlay['rows'][$row] ?? [] as $x => $symbol) {
      if ($symbol === self::WIDE_SYMBOL_CONTINUATION) { continue; }
      $width = max(1, TerminalText::getSymbolWidth($symbol));
      if ($x + $width > self::$width) { continue; }
      for ($column = $x; $column < $x + $width; $column++) {
        foreach (self::$layerCells[$row][$column]['layers'] ?? [] as $layer => $_) {
          if (!isset($excluded[$layer]) && (self::$layerPriorities[$layer] ?? 0) > $overlay['priority']) { continue 3; }
        }
      }
      $cells[$x] = $symbol;
      for ($column = $x + 1; $column < $x + $width; $column++) { $cells[$column] = self::WIDE_SYMBOL_CONTINUATION; }
    }
    return $cells;
  }

  private static function assertLayerCapacity(string $id): void
  {
    if (count(self::$layerPriorities) + count(self::$overlays) + 2 <= StyledPresentationFrame::MAX_TEXT_LAYERS) { return; }
    $ids = ['world' => true, $id => true] + array_fill_keys(array_keys(self::$overlays), true);
    foreach (self::$layerCells as $row) {
      foreach ($row as $cell) {
        foreach ($cell['layers'] as $layer => $_) { $ids[$layer] = true; }
      }
    }
    if (count($ids) > StyledPresentationFrame::MAX_TEXT_LAYERS) {
      throw new \OverflowException('Console world, named layers and overlays must fit the presentation layer limit.');
    }
  }

  /** Select full snapshot tracking; named writes also retain precedence for native overlays. */
  public static function setLayerTracking(bool $enabled): void
  {
    self::$trackLayers = $enabled;
    self::$layerCells = [];
    self::$layerPriorities = [];
  }

  public static function withLayer(string $id, callable $draw, int $priority = 0, bool $replaceUnderlying = false): void
  {
    new PresentationTextLayer($id, $priority, []);
    if ($id === 'world' || isset(self::$overlays[$id])) {
      throw new \InvalidArgumentException('Console layers and overlays require distinct IDs; world is reserved.');
    }
    if (self::$overlays !== []) { self::assertLayerCapacity($id); }
    $previous = self::$activeLayer;
    $previousPriority = self::$activeLayerPriority;
    $previousReplaceUnderlying = self::$replaceUnderlyingLayer;
    self::$activeLayer = $id;
    self::$activeLayerPriority = $priority;
    self::$replaceUnderlyingLayer = $replaceUnderlying;
    if ($previous !== $id) {
      unset(self::$layerPriorities[$id]);
      self::$layerPriorities[$id] = $priority;
    }
    try {
      $draw();
    } finally {
      self::$activeLayer = $previous;
      self::$activeLayerPriority = $previousPriority;
      self::$replaceUnderlyingLayer = $previousReplaceUnderlying;
    }
  }

  public static function isComposing(): bool
  {
    return self::$frameDepth !== 0 || self::$isRecomposing;
  }

  /** Release live writes without erasing later scene writes or another owner's cells. */
  public static function removeLayer(string $id, bool $repaint = true): void
  {
    if (!isset(self::$layerPriorities[$id])) { return; }
    $before = self::visibleCellRows();
    $saved = [self::$buffer, self::$layerCells, self::$layerPriorities, self::$frameRows,
      self::$frameDepth, self::$recomposeRepaintRows];
    $emit = $repaint && self::$terminalOutputEnabled && !self::$terminalHandedBack && !self::$isRecomposing;
    if ($emit) { self::beginFrame(); }
    try {
      unset(self::$layerPriorities[$id]);
      foreach (self::$layerCells as $row => &$entries) {
        $affected = false;
        foreach ($entries as &$entry) {
          if (array_key_exists($id, $entry['layers'])) { unset($entry['layers'][$id]); $affected = true; }
        }
        unset($entry);
        if (!$affected || !isset(self::$buffer[$row])) { continue; }
        $cells = self::$buffer[$row];
        foreach ($entries as $x => $entry) {
          $cells[$x] = $entry['base'];
          foreach ($entry['layers'] as $cell) { $cells[$x] = $cell; }
        }
        // A newer write can cover only one half of a retained wide underlay.
        // Drop visible fragments, retaining provenance so removing that owner restores it.
        self::$buffer[$row] = self::wholeGlyphCells($cells);
      }
      unset($entries);
      if ($emit) {
        $after = self::visibleCellRows();
        foreach ($after as $row => $cells) {
          foreach (self::changedCellSpansFromCells($before[$row] ?? [], $cells) as $span) {
            self::writeBufferRow($row, $span['start'], $span['end'] - $span['start'] + 1);
          }
        }
        self::endFrame();
      }
    } catch (\Throwable $exception) {
      [self::$buffer, self::$layerCells, self::$layerPriorities, self::$frameRows,
        self::$frameDepth, self::$recomposeRepaintRows] = $saved;
      throw $exception;
    }
  }

  /** Retained underlays may be partly occluded by a newer owner. Never display fragments. */
  private static function wholeGlyphCells(array $cells): array
  {
    foreach ($cells as $x => $cell) {
      if ($cell === self::WIDE_SYMBOL_CONTINUATION) {
        $anchor = self::resolveCellAnchor($cells, $x);
        if ($anchor === null || $anchor + TerminalText::getSymbolWidth($cells[$anchor]) <= $x) { $cells[$x] = ' '; }
        continue;
      }
      $width = TerminalText::getSymbolWidth($cell);
      for ($offset = 1; $offset < $width; $offset++) {
        if (($cells[$x + $offset] ?? null) !== self::WIDE_SYMBOL_CONTINUATION) { $cells[$x] = ' '; break; }
      }
    }
    return $cells;
  }

  /** @param string[] $before @param string[] $after */
  private static function recordLayerWrite(int $row, int $start, int $end, array $before, array $after): void
  {
    if ($end < $start) {
      return;
    }
    // Nested callbacks may consume capacity after their parent was admitted.
    // Check before this write changes either provenance or the live buffer.
    if (self::$activeLayer !== null && self::$overlays !== []) { self::assertLayerCapacity(self::$activeLayer); }
    // Writes through a wide continuation clear the entire previous glyph.
    $start = self::resolveCellAnchor($before, $start) ?? $start;
    $lastAnchor = self::resolveCellAnchor($before, $end) ?? $end;
    $end = min(self::$width - 1, max($end, $lastAnchor + TerminalText::getSymbolWidth($before[$lastAnchor] ?? ' ') - 1));
    for ($x = $start; $x <= $end; $x++) {
      if (self::$activeLayer === null) {
        unset(self::$layerCells[$row][$x]);
        continue;
      }
      $entry = self::$replaceUnderlyingLayer ? ['base' => ' ', 'layers' => []]
        : (self::$layerCells[$row][$x] ?? ['base' => $before[$x] ?? ' ', 'layers' => []]);
      self::$layerPriorities[self::$activeLayer] = self::$activeLayerPriority;
      // One entry per layer/cell bounds retained state even across repeated incremental redraws.
      unset($entry['layers'][self::$activeLayer]);
      $entry['layers'][self::$activeLayer] = $after[$x] ?? ' ';
      self::$layerCells[$row][$x] = $entry;
    }
  }
  /**
   * @var string $previousTerminalSettings The previous terminal settings.
   */
  private static string $previousTerminalSettings = '';
  /**
   * @var int $width The width of the console.
   */
  private static int $width = DEFAULT_SCREEN_WIDTH;
  /**
   * @var int $height The height of the console.
   */
  private static int $height = DEFAULT_SCREEN_HEIGHT;
  /**
   * @var ConsoleOutput|null $output The console output.
   */
  private static ?ConsoleOutput $output = null;
  /**
   * Dedicated terminal output descriptor on POSIX systems.
   *
   * Symfony writes through php://stdout. In a PTY that wrapper can accept a
   * complete frame into PHP while only a prefix reaches the terminal after
   * input polling has put its descriptor into non-blocking mode. Opening the
   * controlling terminal separately gives rendering its own blocking file
   * description and makes fwrite() delivery observable.
   *
   * @var resource|null
   */
  private static $terminalOutputStream = null;
  private static bool $terminalOutputEnabled = true;
  /** @var array{int, int, int, int}|null Physical and logical geometry last presented. */
  private static ?array $terminalViewport = null;
  private static bool $terminalViewportDirty = false;
  private static int $terminalOffsetX = 0;
  private static int $terminalOffsetY = 0;

  /** Select the physical sink before borrowing a terminal screen; buffers remain active. */
  public static function setTerminalOutputEnabled(bool $enabled): void
  {
    if (self::$terminalOutputEnabled === $enabled) {
      return;
    }
    if (self::$usingAlternateScreen || self::isComposing()) {
      throw new \LogicException('Select terminal output before composing a frame or entering the alternate screen.');
    }
    self::$terminalOutputEnabled = $enabled;
    if (!$enabled) {
      self::$terminalViewport = null;
      self::$terminalViewportDirty = false;
      self::$terminalOffsetX = self::$terminalOffsetY = 0;
      self::closeTerminalOutputStream();
    } elseif (self::$output !== null) {
      self::openTerminalOutputStream();
    }
  }

  public static function isTerminalOutputEnabled(): bool
  {
    return self::$terminalOutputEnabled;
  }

  /**
   * Console constructor.
   */
  private function __construct()
  {
  }

  /**
   * Initializes the console.
   *
   * @param Game $game
   * @param array{width: int, height: int} $options
   * @return void
   */
  public static function init(Game $game, array $options = [
    'width' => DEFAULT_SCREEN_WIDTH,
    'height' => DEFAULT_SCREEN_HEIGHT,
  ]): void
  {
    $availableSize = self::getAvailableSize();

    self::$game = $game;
    self::$terminalHandedBack = false;
    Console::cursor()->disableBlinking();
    self::$width = intval($options['width'] ?? $availableSize['width']);
    self::$height = intval($options['height'] ?? $availableSize['height']);
    self::syncTerminalViewport($availableSize['width'], $availableSize['height'], repaint: false);
    self::$output = new ConsoleOutput();
    self::openTerminalOutputStream();
    self::clear();
  }

  /**
   * Returns the currently available terminal size.
   *
   * @return array{width: int, height: int} The terminal width and height.
   */
  public static function getAvailableSize(): array
  {
    if ($size = self::readAvailableSizeFromStty()) {
      return $size;
    }

    if ($size = self::readAvailableSizeFromTput()) {
      return $size;
    }

    if ($size = self::readAvailableSizeFromSymfonyTerminal()) {
      return $size;
    }

    $width = getenv('COLUMNS');
    $height = getenv('LINES');
    $size = self::normalizeAvailableSize($width, $height);

    if ($size) {
      return $size;
    }

    return [
      'width' => max(1, self::$width ?: DEFAULT_SCREEN_WIDTH),
      'height' => max(1, self::$height ?: DEFAULT_SCREEN_HEIGHT),
    ];
  }

  /**
   * Resets the console.
   *
   * @return void
   */
  public static function reset(): void
  {
    // Leave the borrowed screen and hand the terminal back as found. A
    // `tput reset` here would also clear the user's scrollback and colours,
    // which is destruction rather than restoration.
    // Autowrap is disabled while the game owns the alternate screen so a
    // full-width write to its bottom row cannot scroll the terminal. Restore
    // the user's normal terminal mode before handing the screen back.
    self::enableLineWrap();
    self::leaveAlternateScreen();
    self::cursor()->show();
    self::cursor()->enableBlinking();
    self::closeTerminalOutputStream();
    self::$terminalHandedBack = true;
    self::$terminalViewport = null;
    self::$terminalViewportDirty = false;
    self::$terminalOffsetX = self::$terminalOffsetY = 0;
    self::$overlays = [];
  }

  /**
   * Enables the line wrap.
   *
   * @return void
   */
  public static function enableLineWrap(): void
  {
    self::emitControlSequence("\033[?7h");
  }

  /**
   * Disables the line wrap.
   *
   * @return void
   */
  public static function disableLineWrap(): void
  {
    self::emitControlSequence("\033[?7l");
  }

  /**
   * Returns the cursor.
   *
   * @return Cursor The cursor.
   */
  public static function cursor(): Cursor
  {
    return Cursor::getInstance();
  }

  /* Scrolling */
  /**
   * Enables scrolling.
   *
   * @param int|null $start The line to start scrolling.
   * @param int|null $end The line to end scrolling.
   * @return void
   */
  public static function enableScrolling(?int $start = null, ?int $end = null): void
  {
    self::emitControlSequence(match(true) {
      $start !== null && $end !== null => "\033[$start;{$end}r",
      $start !== null => "\033[{$start}r",
      $end !== null => "\033[;{$end}r",
      default => "\033[r",
    });
  }

  /**
   * Disables scrolling.
   *
   * @return void
   */
  public static function disableScrolling(): void
  {
    self::emitControlSequence("\033[?7l");
  }

  /**
   * Clears the console.
   *
   * @return void
   */
  public static function clear(): void
  {
    // Clearing is a rendered screen transition, so it must use the same
    // descriptor and delivery guarantees as every other frame. Delegating to
    // `clear`/`cls` writes through the process' inherited stdout instead of
    // the dedicated terminal descriptor. If that separate write is dropped
    // or arrives out of order, resetting the canonical buffer here makes the
    // next frame believe the terminal is already blank and stale menu rows
    // survive behind the field until a later redraw.
    //
    // Update the canonical buffer only after the physical clear succeeds. A
    // failed write therefore cannot leave engine state ahead of the screen.
    self::emitControlSequence("\033[0m\033[2J" . self::terminalHomeAddress());
    self::$buffer = self::getEmptyBuffer();
    self::$layerCells = [];
    self::$layerPriorities = [];
    self::$frameRows = [];
    self::$recomposeRepaintRows = [];
    self::beginFrame();
    foreach (self::$overlays as $overlay) {
      foreach (array_keys($overlay['rows']) as $row) {
        self::repaintRegion(0, $row, self::$width, 1);
      }
    }
    self::endFrame();
  }

  /**
   * Rebuilds the complete screen off-screen and emits only changed rows.
   *
   * Full-screen scenes must occasionally replace every layer at once: map,
   * sprites, windows, cues, and transient presentation. Physically clearing
   * the terminal before each rebuild exposes that intermediate blank screen
   * and produces visible flicker during camera pans and animations. Starting
   * from an empty logical buffer also matters, though, because otherwise a
   * layer that disappeared would remain on screen.
   *
   * This method provides both properties. The callback composes a fresh
   * logical screen inside one outer frame. Once composition succeeds, only
   * rows whose final content differs from the previous screen are flushed.
   * If composition fails, the authoritative buffer is restored and no
   * partial frame reaches the terminal.
   *
   * A presentation-ownership boundary can request a full repaint. This is
   * intentionally different from clearing: the complete new screen is sent
   * without exposing an intermediate blank frame. It also repairs the
   * physical terminal when output outside the engine, a dropped legacy
   * write, or a scene transition left it out of sync with the canonical
   * buffer.
   *
   * @param callable(): void $renderer The complete screen renderer.
   * @param bool $forceFullRepaint Whether to emit every terminal row after composition.
   * @return void
   */
  public static function recomposeFrame(callable $renderer, bool $forceFullRepaint = false): void
  {
    if (self::$frameDepth !== 0) {
      throw new \RuntimeException('A complete screen cannot be recomposed inside an active console frame.');
    }

    $previousBuffer = self::$buffer === [] ? self::getEmptyBuffer() : self::$buffer;
    $previousVisibleBuffer = self::visibleCellRows();
    $previousOverlays = self::$overlays;
    $previousLayerCells = self::$layerCells;
    $previousLayerPriorities = self::$layerPriorities;
    $previousFrameRows = self::$frameRows;
    $previousRecomposeRepaintRows = self::$recomposeRepaintRows;
    $previousIsRecomposing = self::$isRecomposing;

    self::$buffer = self::getEmptyBuffer();
    self::$layerCells = [];
    self::$layerPriorities = [];
    self::$frameRows = [];
    self::$recomposeRepaintRows = [];
    self::$isRecomposing = true;
    self::beginFrame();

    try {
      $renderer();

      if (self::$frameDepth !== 1) {
        throw new \RuntimeException('The screen renderer left an unbalanced console frame.');
      }

      // Intermediate writes merely built the new logical screen. Compare its
      // final rows to the old authoritative screen so rows that disappeared
      // are blanked while stable rows are not needlessly repainted.
      self::$frameRows = [];
      $visibleBuffer = self::visibleCellRows();
      $diffStart = LatencyTrace::getTimeNow();

      if (self::$terminalOutputEnabled && $forceFullRepaint) {
        for ($row = 0; $row < self::$height; $row++) {
          self::markFrameSpan($row, 0, self::$width - 1);
        }
      } elseif (self::$terminalOutputEnabled) {
        $emptyRow = array_fill(0, self::$width, ' ');

        for ($row = 0; $row < self::$height; $row++) {
          if (($visibleBuffer[$row] ?? $emptyRow) !== ($previousVisibleBuffer[$row] ?? $emptyRow)) {
            $spans = self::changedCellSpansFromCells(
              $previousVisibleBuffer[$row] ?? $emptyRow,
              $visibleBuffer[$row] ?? $emptyRow,
            );

            foreach ($spans as $span) {
              self::markFrameSpan($row, $span['start'], $span['end']);
            }
          }
        }
      }

      LatencyTrace::end('terminal.diff', $diffStart);

      // A persistent presentation may know that the physical terminal needs
      // repair even though its logical cells match the previous frame. Keep
      // those explicit spans after throwing away intermediate render writes.
      foreach (self::$recomposeRepaintRows as $row => $span) {
        self::markFrameSpan($row, $span['start'], $span['end']);
      }

      self::$recomposeRepaintRows = [];
      self::$isRecomposing = false;

      self::endFrame();
    } catch (\Throwable $throwable) {
      self::$buffer = $previousBuffer;
      self::$overlays = $previousOverlays;
      self::$layerCells = $previousLayerCells;
      self::$layerPriorities = $previousLayerPriorities;
      self::$frameRows = $previousFrameRows;
      self::$recomposeRepaintRows = $previousRecomposeRepaintRows;
      self::$isRecomposing = $previousIsRecomposing;
      self::$frameDepth = 0;
      throw $throwable;
    }
  }

  /**
   * Sets the terminal name.
   *
   * @param string $name The name of the terminal.
   * @return void
   */
  public static function setTerminalName(string $name): void
  {
    self::emitControlSequence("\033]0;$name\007");
  }

  /**
   * Sets the terminal size.
   *
   * @param int $width The width of the terminal.
   * @param int $height The height of the terminal.
   * @return void
   */
  public static function setTerminalSize(int $width, int $height): void
  {
    self::syncDimensions($width, $height);
    self::emitControlSequence(sprintf("\033[8;%d;%dt", self::$height, self::$width));
    // The resize request invalidates the old physical measurement. Use a
    // neutral origin until a fresh probe establishes the new margins.
    self::$terminalViewport = null;
    self::$terminalViewportDirty = false;
    self::refreshTerminalOrigin();
  }

  /**
   * Synchronizes the console's internal dimensions with the terminal.
   *
   * This updates the backing buffer without forcing the terminal emulator to
   * resize, which is useful when the user manually changes the window size.
   *
   * @param int $width The current terminal width.
   * @param int $height The current terminal height.
   * @return void
   */
  public static function syncDimensions(int $width, int $height): void
  {
    if (self::isComposing()) {
      throw new \LogicException('Console dimension changes require a completed logical frame.');
    }
    self::$width = max(1, $width);
    self::$height = max(1, $height);
    self::$buffer = self::getEmptyBuffer();
    self::$layerCells = [];
    self::$layerPriorities = [];
    self::$frameRows = [];
    self::$recomposeRepaintRows = [];
    // Keep the last presented tuple so the next viewport sync still repaints,
    // but make all writes use the new logical origin immediately.
    self::$terminalViewportDirty = self::$terminalViewport !== null;
    self::refreshTerminalOrigin();
  }

  /**
   * Center the complete logical surface inside the physical terminal. No TTY
   * probes or logical buffer/Camera changes occur at this output boundary.
   */
  public static function syncTerminalViewport(int $width, int $height, bool $repaint = true): bool
  {
    if (!self::$terminalOutputEnabled) { return false; }
    $viewport = [max(1, $width), max(1, $height), self::$width, self::$height];
    if ($viewport === self::$terminalViewport && !self::$terminalViewportDirty) { return false; }
    if (self::isComposing()) {
      throw new \LogicException('Terminal viewport changes require a completed logical frame.');
    }
    $previous = [self::$terminalViewport, self::$terminalOffsetX, self::$terminalOffsetY, self::$terminalViewportDirty];
    self::$terminalViewport = $viewport;
    self::$terminalViewportDirty = false;
    self::refreshTerminalOrigin();
    try {
      if ($repaint) {
        // A physical resize may reflow old terminal rows even if the logical
        // grid and origin are unchanged. Clear all stale margins, then replay.
        self::emitControlSequence("\033[0m\033[2J" . self::terminalHomeAddress());
        self::repaintRegion(0, 0, self::$width, self::$height);
      }
    } catch (\Throwable $error) {
      [self::$terminalViewport, self::$terminalOffsetX, self::$terminalOffsetY, self::$terminalViewportDirty] = $previous;
      throw $error;
    }
    return true;
  }

  private static function refreshTerminalOrigin(): void
  {
    self::$terminalOffsetX = intdiv(max(0, (self::$terminalViewport[0] ?? self::$width) - self::$width), 2);
    self::$terminalOffsetY = intdiv(max(0, (self::$terminalViewport[1] ?? self::$height) - self::$height), 2);
  }

  /** @return array{x: int, y: int} Zero-based physical margins, never logical coordinates. */
  public static function getTerminalOrigin(): array
  {
    return ['x' => self::$terminalOffsetX, 'y' => self::$terminalOffsetY];
  }

  /** Existing one-based cursor coordinates translated exactly once at output. */
  public static function terminalCursorAddress(int $column, int $row): string
  {
    return sprintf("\033[%d;%dH", $row + self::$terminalOffsetY, $column + self::$terminalOffsetX);
  }

  private static function terminalHomeAddress(): string
  {
    return self::$terminalOffsetX === 0 && self::$terminalOffsetY === 0
      ? "\033[H" : self::terminalCursorAddress(1, 1);
  }

  /**
   * Switches to the terminal's alternate screen buffer.
   *
   * The alternate buffer is how a full-screen program borrows the terminal
   * without destroying what was there: on exit the shell's scrollback,
   * prompt, and previous output come back exactly as the player left them.
   * The previous approach — drawing over the primary buffer and running
   * `tput reset` on the way out — wiped scrollback and colours instead of
   * restoring anything.
   *
   * @return void
   */
  public static function enterAlternateScreen(): void
  {
    self::$terminalHandedBack = false;
    if (!self::$terminalOutputEnabled) {
      return;
    }
    if (self::$usingAlternateScreen) {
      return;
    }

    self::emitControlSequence("\033[?1049h");
    self::$usingAlternateScreen = true;
    self::$terminalHandedBack = false;
  }

  /**
   * Returns to the primary screen buffer, restoring the prior terminal
   * contents. Safe to call more than once.
   *
   * @return void
   */
  public static function leaveAlternateScreen(): void
  {
    if (! self::$usingAlternateScreen) {
      return;
    }

    self::emitControlSequence("\033[?1049l");
    self::$usingAlternateScreen = false;
    self::$terminalHandedBack = true;
  }

  /**
   * Emits terminal control bytes through the authoritative rendering path.
   *
   * Cursor movement, screen clearing, and text drawing are one ordered
   * protocol from the terminal's point of view. Sending controls through
   * inherited stdout while frame content uses a dedicated descriptor lets
   * those halves be dropped or reordered independently. Keeping this public
   * lets the Cursor facade participate in the same delivery contract without
   * exposing the lower-level stream writer itself.
   */
  public static function emitControlSequence(string $sequence): void
  {
    self::writeToTerminal($sequence);
  }

  /**
   * Saves the terminal settings.
   *
   * @return void
   */
  public static function saveTerminalSettings(): void
  {
    self::$previousTerminalSettings = shell_exec('stty -g') ?? '';
  }

  /**
   * Restores the terminal settings.
   *
   * @return void
   */
  public static function restoreTerminalSettings(): void
  {
    shell_exec('stty ' . self::$previousTerminalSettings);
  }

  /**
   * Writes text to the console at the specified position.
   *
   * @param iterable|string $message The text to write.
   * @param int|float $x The x position.
   * @param int|float $y The y position.
   * @return void
   */
  public static function write(iterable|string $message, int|float $x, int|float $y): void
  {
    if (self::$terminalHandedBack) { return; }
    $textRows = is_string($message) ? explode("\n", $message) : $message;
    $y = (int)floor($y);
    $x = max(0, min((int)floor($x), self::$width - 1));
    foreach ($textRows as $rowIndex => $text) {
      $row = $y + $rowIndex;
      if ($row >= 0 && $row < self::$height) {
        self::writeNormalizedRow(NormalizedRow::fromText((string)$text, self::$width - $x), $x, $row);
      }
    }
  }

  /** @internal Shared write boundary for retained Camera rows and string authors. */
  public static function writeNormalizedRow(NormalizedRow $row, int|float $x, int|float $y): void
  {
    if (self::$terminalHandedBack) { return; }
    $y = (int)floor($y);
    if ($y < 0 || $y >= self::$height) { return; }
    $x = max(0, min((int)floor($x), self::$width - 1));
    $incoming = $row->clippedCells(self::$width - $x);
    $length = count($incoming);
    if ($length === 0) { return; }

    $started = LatencyTrace::getTimeNow();
    $before = self::$buffer[$y] ?? array_fill(0, self::$width, ' ');
    $visibleBefore = self::$overlays === [] || self::$isRecomposing || !self::$terminalOutputEnabled
      ? null : self::compositeOverlayRow($before, $y);
    $after = $before;
    // Only the two write boundaries can leave a fragment of an old glyph.
    // Everything inside the write is replaced by complete normalized cells.
    self::clearCellRange($after, $x, 1);
    self::clearCellRange($after, $x + $length - 1, 1);
    array_splice($after, $x, $length, $incoming);
    if (self::$trackLayers || self::$activeLayer !== null || isset(self::$layerCells[$y])) {
      self::recordLayerWrite($y, $x, $x + $length - 1, $before, $after);
    }
    self::$buffer[$y] = $after;
    LatencyTrace::end('terminal.compose', $started);
    if (!self::$terminalOutputEnabled || self::$isRecomposing) { return; }

    $diffStart = LatencyTrace::getTimeNow();
    $spans = self::changedCellSpansFromCells($visibleBefore ?? $before,
      $visibleBefore === null ? $after : self::compositeOverlayRow($after, $y));
    LatencyTrace::end('terminal.diff', $diffStart);
    foreach ($spans as $span) {
      self::markFrameSpan($y, $span['start'], $span['end']);
    }
    if (self::$frameDepth === 0) { self::endFrame(); }
  }

  /**
   * Erases output at the specified position.
   *
   * @param int $x The x position.
   * @param int $y The y position.
   * @return void
   */
  public static function erase(int $x, int $y): void
  {
    self::write(' ', $x, $y);
  }

  /**
   * Gets the buffer.
   *
   * @return string[] The buffer.
   */
  public static function getBuffer(): array
  {
    return array_map(self::cellsToRow(...), self::visibleCellRows());
  }

  /** @return array<int, list<string>> Derived overlay view of the single canonical scene. */
  private static function visibleCellRows(): array
  {
    if (self::$overlays === []) { return self::$buffer; }
    $rows = self::$buffer === [] ? self::getEmptyBuffer() : self::$buffer;
    $affected = [];
    foreach (self::$overlays as $overlay) {
      foreach (array_keys($overlay['rows']) as $row) { $affected[$row] = true; }
    }
    foreach (array_keys($affected) as $row) {
      if ($row < self::$height) {
        $rows[$row] = self::compositeOverlayRow($rows[$row] ?? array_fill(0, self::$width, ' '), $row);
      }
    }
    return $rows;
  }

  /**
   * Captures complete logical cells without touching terminal output or dirty state.
   * @param list<string> $excludedLayers Named terminal layers to omit from this copy only.
   */
  public static function snapshot(array $excludedLayers = []): ConsoleFrameSnapshot
  {
    if (self::$frameDepth !== 0 || self::$isRecomposing) {
      throw new RuntimeException('Cannot snapshot Console while a frame or screen recomposition is active.');
    }

    $excluded = array_fill_keys($excludedLayers, true);
    $rows = [];
    for ($y = 0; $y < self::$height; $y++) {
      $cells = self::snapshotCells($y);
      if ($excluded !== []) {
        foreach (self::$layerCells[$y] ?? [] as $x => $entry) {
          $cells[$x] = $entry['base'];
          foreach ($entry['layers'] as $id => $cell) {
            if (!isset($excluded[$id])) {
              $cells[$x] = $cell;
            }
          }
        }
      }
      $cells = self::wholeGlyphCells($cells);
      $cells = self::compositeOverlayRow($cells, $y, $excluded);
      foreach ($cells as &$cell) {
        if ($cell === self::WIDE_SYMBOL_CONTINUATION) {
          $cell = ' ';
          continue;
        }
        $cell = TerminalText::rendererScalar($cell);
      }
      unset($cell);
      $rows[] = implode('', $cells);
    }
    return new ConsoleFrameSnapshot(self::$width, self::$height, $rows);
  }

  /**
   * @param list<string> $excludedLayers Renderer-only exclusions; Console stays untouched.
   * @param array<string, array<int, array<int, true>>> $replacedLayerCells Layer/row/column masks.
   * Replacement removes the named contribution and its underlay, never later writes.
   */
  public static function presentationSnapshot(array $excludedLayers = [], array $replacedLayerCells = []): ConsolePresentationSnapshot
  {
    $cellsStart = LatencyTrace::getTimeNow();
    if (self::isComposing()) {
      throw new RuntimeException('Cannot snapshot Console while a frame or screen recomposition is active.');
    }
    $world = $named = [];
    $excluded = array_fill_keys($excludedLayers, true);
    for ($y = 0; $y < self::$height; $y++) {
      $world[$y] = self::snapshotCells($y);
      foreach (self::$layerCells[$y] ?? [] as $x => $entry) {
        $world[$y][$x] = $entry['base'];
        foreach ($entry['layers'] as $id => $cell) {
          if (isset($replacedLayerCells[$id][$y][$x])) {
            unset($world[$y][$x]);
            foreach (array_keys($entry['layers']) as $underlay) {
              if ($underlay === $id) { break; }
              unset($named[$underlay][$y][$x]);
            }
            continue;
          }
          if (!isset($excluded[$id])) { $named[$id][$y][$x] = $cell; }
        }
      }
    }
    LatencyTrace::end('console.cells', $cellsStart);
    $planes = ['world' => $world];
    $priorities = ['world' => PresentationLayerPolicy::WORLD];
    foreach (self::$layerPriorities as $id => $priority) {
      if (isset($named[$id])) {
        $planes[$id] = $named[$id];
        $priorities[$id] = $priority;
      }
    }
    // Live layers also cover whole glyphs, including when a removed layer exposes
    // a wide underlay beside a newer scene write. Only the snapshot copy is masked.
    foreach ($planes as $id => $rows) {
      foreach ($rows as $row => $cells) { $planes[$id][$row] = self::wholeGlyphCells($cells); }
    }
    foreach ($named as $id => $rows) {
      foreach ($rows as $row => $cells) {
        foreach ($planes as $lowerId => &$plane) {
          if ($lowerId === $id) { break; }
          if (!isset($plane[$row])) { continue; }
          foreach (array_keys($cells) as $column) {
            $anchor = self::resolveCellAnchor($plane[$row], $column);
            if ($anchor !== null && TerminalText::getSymbolWidth($plane[$row][$anchor] ?? ' ') > 1) {
              self::clearCellRange($plane[$row], $anchor, 1);
            }
          }
        }
        unset($plane);
      }
    }
    foreach (self::$overlays as $id => $overlay) {
      if (isset($excluded[$id])) { continue; }
      $rows = [];
      foreach (array_keys($overlay['rows']) as $row) {
        $rows[$row] = self::overlayRowCells($overlay, $row, $excluded);
        // A terminal cannot display half of a wide glyph. Repair snapshot copies
        // of lower planes too, without discarding their retained underlay.
        foreach ($planes as $lowerId => &$plane) {
          if ($priorities[$lowerId] > $overlay['priority'] || !isset($plane[$row])) { continue; }
          foreach (array_keys($rows[$row]) as $column) {
            $anchor = self::resolveCellAnchor($plane[$row], $column);
            if ($anchor !== null && TerminalText::getSymbolWidth($plane[$row][$anchor] ?? ' ') > 1) {
              self::clearCellRange($plane[$row], $anchor, 1);
            }
          }
        }
        unset($plane);
      }
      $planes[$id] = $rows;
      $priorities[$id] = $overlay['priority'];
    }
    $layers = [];
    foreach ($planes as $id => $rows) {
      $layers[] = new PresentationTextLayer((string)$id, $priorities[$id], self::presentationRuns($rows));
    }
    return new ConsolePresentationSnapshot(self::$width, self::$height, $layers);
  }

  /** @param array<int, array<int, string>> $rows @return list<PresentationTextRun> */
  private static function presentationRuns(array $rows): array
  {
    $started = LatencyTrace::getTimeNow();
    $parsing = 0;
    $runs = $cache = [];
    foreach ($rows as $y => $cells) {
      ksort($cells);
      $text = '';
      $start = $previous = -1;
      $style = ['foreground' => null, 'background' => null];
      foreach ($cells as $x => $cell) {
        if ($cell === self::WIDE_SYMBOL_CONTINUATION) {
          $next = $previous === $x - 1 ? $style : ['foreground' => null, 'background' => null];
          $glyph = ' ';
        } else {
          if (!isset($cache[$cell])) {
            $parseStart = LatencyTrace::getTimeNow();
            $cache[$cell] = [SgrColorParser::parse($cell), TerminalText::rendererScalar($cell)];
            if ($parseStart !== null) { $parsing += LatencyTrace::getTimeNow() - $parseStart; }
          }
          $parsed = $cache[$cell];
          [$next, $glyph] = $parsed;
        }
        if ($text !== '' && ($x !== $previous + 1 || $next != $style)) {
          $runs[] = new PresentationTextRun($y, $start, $text, $style['foreground'], $style['background']);
          $text = '';
        }
        if ($text === '') { $start = $x; }
        $text .= $glyph;
        $style = $next;
        $previous = $x;
      }
      if ($text !== '') {
        $runs[] = new PresentationTextRun($y, $start, $text, $style['foreground'], $style['background']);
      }
    }
    LatencyTrace::end('console.runs', $started, ['style_parse_ns' => $parsing,
      'unique_cells' => count($cache), 'runs' => count($runs)]);
    return $runs;
  }

  /**
   * Returns the character at the specified position.
   *
   * @param int $x The x position.
   * @param int $y The y position.
   * @return string The character at the specified position.
   */
  public static function charAt(int $x, int $y): string
  {
    if ($x < 0 || $x >= self::$width || $y < 0 || $y >= self::$height) {
      return '';
    }

    $cells = self::compositeOverlayRow(self::$buffer[$y] ?? array_fill(0, self::$width, ' '), $y);
    $anchorIndex = self::resolveCellAnchor($cells, $x);

    if ($anchorIndex === null) {
      return '';
    }

    $char = TerminalText::stripAnsi($cells[$anchorIndex] ?? ' ');

    return $char === '' ? ' ' : $char;
  }

  /**
   * Returns an empty buffer.
   *
   * @return array<int, list<string>> The empty buffer (rows share copy-on-write storage).
   */
  private static function getEmptyBuffer(): array
  {
    return array_fill(0, self::$height, array_fill(0, self::$width, ' '));
  }

  /** @return list<string> Validate snapshot copies, without parsing or mutating retained rows. */
  private static function snapshotCells(int $y): array
  {
    $cells = self::$buffer[$y] ?? array_fill(0, self::$width, ' ');
    if (!is_array($cells) || count($cells) !== self::$width || !array_is_list($cells)) {
      throw new RuntimeException("Console row {$y} must contain valid UTF-8 text.");
    }
    foreach ($cells as $cell) {
      if (!is_string($cell) || preg_match('//u', $cell) !== 1) {
        throw new RuntimeException("Console row {$y} must contain valid UTF-8 text.");
      }
    }
    return $cells;
  }

  /**
   * Attempts to read the current terminal size via stty.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromStty(): ?array
  {
    $commands = [
      'stty size < /dev/tty 2>/dev/null',
      'stty size 2>/dev/null',
    ];

    foreach ($commands as $command) {
      $size = self::parseSttySizeOutput(shell_exec($command) ?: '');

      if ($size) {
        return $size;
      }
    }

    return null;
  }

  /**
   * Attempts to read the current terminal size via tput.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromTput(): ?array
  {
    $width = shell_exec('tput cols 2>/dev/null');
    $height = shell_exec('tput lines 2>/dev/null');

    return self::normalizeAvailableSize($width, $height);
  }

  /**
   * Attempts to read the current terminal size from Symfony's terminal helper.
   *
   * Symfony caches width and height statically, so those cached values are
   * cleared before each probe to keep resize handling live.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromSymfonyTerminal(): ?array
  {
    try {
      $reflection = new ReflectionClass(Terminal::class);

      foreach (['width', 'height'] as $propertyName) {
        if (! $reflection->hasProperty($propertyName)) {
          continue;
        }

        $property = $reflection->getProperty($propertyName);
        $property->setValue(null, null);
      }
    } catch (\Throwable) {
      return null;
    }

    $terminal = new Terminal();

    return self::normalizeAvailableSize($terminal->getWidth(), $terminal->getHeight());
  }

  /**
   * Parses the output of `stty size`.
   *
   * @param string $output The raw `stty size` output.
   * @return array{width: int, height: int}|null The parsed size, if valid.
   */
  protected static function parseSttySizeOutput(string $output): ?array
  {
    if (! preg_match('/^\s*(\d+)\s+(\d+)\s*$/', trim($output), $matches)) {
      return null;
    }

    return self::normalizeAvailableSize($matches[2], $matches[1]);
  }

  /**
   * Normalizes raw terminal size values into positive integer dimensions.
   *
   * @param mixed $width The raw width.
   * @param mixed $height The raw height.
   * @return array{width: int, height: int}|null The normalized size, if valid.
   */
  protected static function normalizeAvailableSize(mixed $width, mixed $height): ?array
  {
    if (! is_numeric($width) || ! is_numeric($height)) {
      return null;
    }

    $width = max(1, intval($width));
    $height = max(1, intval($height));

    return ['width' => $width, 'height' => $height];
  }

  /**
   * Opens a batched frame.
   *
   * Row updates are collected instead of written immediately, so a frame
   * costs one terminal write rather than one per row. Terminal writes are
   * the expensive part on Windows consoles and over SSH, where a 45-row
   * scroll step otherwise means 45 cursor moves and 45 writes.
   *
   * Calls nest: the frame flushes when the outermost one closes.
   *
   * @return void
   */
  public static function beginFrame(): void
  {
    self::$frameDepth++;
  }

  /**
   * Closes a batched frame, flushing it when the outermost one closes.
   *
   * @return void
   */
  public static function endFrame(): void
  {
    if (self::$frameDepth > 0) {
      self::$frameDepth--;
    }

    if (self::$frameDepth > 0 || self::$frameRows === []) {
      return;
    }

    if (!self::$terminalOutputEnabled) {
      self::$frameRows = [];
      return;
    }

    $started = LatencyTrace::getTimeNow();
    ksort(self::$frameRows, SORT_NUMERIC);
    $payload = '';

    foreach (self::$frameRows as $row => $spans) {
      if (! isset(self::$buffer[$row])) {
        continue;
      }

      $cells = self::compositeOverlayRow(self::$buffer[$row], $row);
      foreach ($spans as $span) {
        $payload .= self::terminalRowSpan($row, $cells, $span['start'], $span['end']);
      }
    }

    LatencyTrace::end('terminal.serialize', $started, ['bytes' => strlen($payload)]);
    self::writeToTerminal($payload);
    self::$frameRows = [];
  }

  /**
   * Re-emits a canonical screen region even when its logical cells have not
   * changed.
   *
   * This is intentionally narrower than a full-screen repaint. Persistent
   * overlays such as a locator or status HUD can repair their own footprint
   * after a terminal-edge or external-output disturbance without forcing the
   * dense map beneath them through the PTY again.
   */
  public static function repaintRegion(int $x, int $y, int $width, int $height): void
  {
    if (!self::$terminalOutputEnabled) {
      return;
    }
    if ($width <= 0 || $height <= 0 || self::$width <= 0 || self::$height <= 0) {
      return;
    }

    $startX = max(0, min($x, self::$width - 1));
    $endX = min(self::$width - 1, $x + $width - 1);
    $startY = max(0, min($y, self::$height - 1));
    $endY = min(self::$height - 1, $y + $height - 1);

    if ($endX < $startX || $endY < $startY) {
      return;
    }

    self::beginFrame();

    try {
      for ($row = $startY; $row <= $endY; $row++) {
        self::markFrameSpan($row, $startX, $endX);

        if (self::$isRecomposing) {
          $current = self::$recomposeRepaintRows[$row] ?? null;
          self::$recomposeRepaintRows[$row] = [
            'start' => $current === null ? $startX : min($current['start'], $startX),
            'end' => $current === null ? $endX : max($current['end'], $endX),
          ];
        }
      }
    } finally {
      self::endFrame();
    }
  }

  /**
   * Writes a payload to the terminal, guaranteeing every byte is delivered.
   *
   * A complete field frame can exceed both PHP's userspace stream buffer and
   * the terminal's output buffer. Accepting bytes into the former is not the
   * same as delivering them to the latter: a final non-blocking flush can
   * leave only the first rows visible while the canonical screen already
   * believes the whole frame was painted. The stream is therefore made
   * unbuffered before any frame bytes are offered to it.
   *
   * Looping until the payload is fully written is the correct way to handle
   * both blocking and non-blocking descriptors; even a blocking write can be
   * cut short by a signal. The caller's blocking mode is deliberately left
   * alone. Turning a healthy blocking terminal non-blocking for a frame merely
   * moves delivery into PHP's buffering layer and recreates the truncation
   * this method exists to prevent.
   *
   * @param string $payload The terminal-ready payload to write.
   * @return void
   */
  private static function writeToTerminal(string $payload): void
  {
    if (!self::$terminalOutputEnabled || $payload === '') {
      return;
    }

    $stream = is_resource(self::$terminalOutputStream)
      ? self::$terminalOutputStream
      : self::$output?->getStream();

    if (! is_resource($stream)) {
      // PHP's CLI output layer loops until every byte is written, so the
      // echo fallback is already safe (and stays capturable by output
      // buffering, which tests rely on).
      echo $payload;
      return;
    }

    // php://stdout and /dev/tty may be buffered independently of the terminal
    // descriptor. Prefer an unbuffered stream so fwrite()'s byte count
    // describes terminal acceptance rather than temporary userspace
    // acceptance. macOS' /dev/tty wrapper can reject this request even though
    // it still buffers writes, so rejection is not proof that the descriptor
    // is direct. In that case every accepted chunk is explicitly drained
    // before the next one is offered. This keeps all large scenes reliable,
    // rather than relying on a final flush after PHP has already accepted the
    // complete frame into an opaque stream buffer.
    @stream_set_write_buffer($stream, 0);

    $totalBytes = strlen($payload);
    $bytesWritten = 0;
    $stalledAttempts = 0;

    while ($bytesWritten < $totalBytes) {
      $chunk = substr(
        $payload,
        $bytesWritten,
        min(self::WRITE_CHUNK_BYTES, $totalBytes - $bytesWritten),
      );
      $written = @fwrite($stream, $chunk);

      if ($written !== false && $written > 0) {
        $bytesWritten += $written;
        $stalledAttempts = 0;

        // PHP stream wrappers can claim to be unbuffered while an underlying
        // PTY, multiplexer, or capture layer still stages output. Drain every
        // accepted chunk; relying on the wrapper's advisory return value left
        // the last shop rows invisible until the player's next key press.
        if (! @fflush($stream)) {
          throw new RuntimeException(sprintf(
            'Terminal output failed to drain after %d of %d bytes.',
            $bytesWritten,
            $totalBytes,
          ));
        }

        continue;
      }

      // Either a non-blocking terminal buffer is full or the stream is
      // genuinely broken. Wait for writability. PHP's stdio wrapper is not
      // selectable on every PTY, so a failed/empty select must still yield
      // before retrying instead of burning through the retry budget.
      if (++$stalledAttempts > self::WRITE_MAX_STALLED_ATTEMPTS) {
        throw new RuntimeException(sprintf(
          'Terminal output stalled after %d of %d bytes.',
          $bytesWritten,
          $totalBytes,
        ));
      }

      $readStreams = [];
      $writeStreams = [$stream];
      $exceptStreams = [];

      $ready = @stream_select(
        $readStreams,
        $writeStreams,
        $exceptStreams,
        0,
        self::WRITE_STALL_TIMEOUT_MICROSECONDS,
      );

      if ($ready !== 1) {
        usleep(self::WRITE_STALL_TIMEOUT_MICROSECONDS);
      }
    }

    if (! @fflush($stream)) {
      throw new RuntimeException(sprintf(
        'Terminal output failed to flush after %d bytes.',
        $totalBytes,
      ));
    }
  }

  /**
   * Opens a rendering-only handle to the controlling terminal when one is
   * available.
   *
   * STDIN must remain non-blocking for the game loop. A separately opened
   * /dev/tty handle has independent descriptor flags, so output can remain
   * blocking without changing input behaviour. Non-POSIX environments and
   * redirected processes retain the existing ConsoleOutput fallback.
   */
  private static function openTerminalOutputStream(): void
  {
    if (!self::$terminalOutputEnabled) {
      return;
    }
    self::closeTerminalOutputStream();

    if (PHP_OS_FAMILY === 'Windows') {
      return;
    }

    if (function_exists('stream_isatty') && ! @stream_isatty(STDOUT)) {
      return;
    }

    try {
      $stream = @fopen('/dev/tty', 'wb');
    } catch (\Throwable) {
      // Sandboxed processes and redirected automation can expose the path
      // while forbidding access. They use the ConsoleOutput fallback below.
      return;
    }

    if (! is_resource($stream)) {
      return;
    }

    @stream_set_blocking($stream, true);
    @stream_set_write_buffer($stream, 0);
    self::$terminalOutputStream = $stream;
  }

  /** Closes the rendering-only terminal descriptor, if the console owns one. */
  private static function closeTerminalOutputStream(): void
  {
    if (is_resource(self::$terminalOutputStream)) {
      @fflush(self::$terminalOutputStream);
      @fclose(self::$terminalOutputStream);
    }

    self::$terminalOutputStream = null;
  }

  /**
   * Flushes a single buffered row to the terminal without adding a trailing newline.
   *
   * Avoiding a final line-feed prevents full-screen renders from triggering
   * terminal scrolling when the last visible row is repainted.
   *
   * @param int $row The zero-based buffer row to flush.
   * @return void
   */
  private static function writeBufferRow(int $row, int $start = 0, ?int $width = null): void
  {
    if (!isset(self::$buffer[$row])) {
      return;
    }

    $start = max(0, min($start, max(0, self::$width - 1)));
    $end = min(
      self::$width - 1,
      $width === null ? self::$width - 1 : $start + max(0, $width) - 1,
    );

    if ($end < $start) {
      return;
    }

    if (self::$frameDepth > 0) {
      // A later draw in the same frame may update this row again. Remember
      // the affected span and emit its final composition at outer close.
      self::markFrameSpan($row, $start, $end);
      return;
    }

    // Positioning and content form one indivisible terminal operation. If
    // they travel through different descriptors, the text may arrive before
    // its cursor move and corrupt an unrelated part of the screen.
    $cells = self::compositeOverlayRow(self::$buffer[$row], $row);
    self::writeToTerminal(self::terminalRowSpan($row, $cells, $start, $end));
  }

  /** Coalesces overlapping changed spans while preserving clean gaps. */
  private static function markFrameSpan(int $row, int $start, int $end): void
  {
    $start = max(0, min($start, max(0, self::$width - 1)));
    $end = max($start, min($end, max(0, self::$width - 1)));
    $spans = self::$frameRows[$row] ?? [];
    $spans[] = ['start' => $start, 'end' => $end];
    self::$frameRows[$row] = self::coalesceChangedSpans($spans);
  }

  /**
   * Finds changed runs between two already expanded cell buffers.
   *
   * @param string[] $before
   * @param string[] $after
   * @return list<array{start: int, end: int}>
   */
  private static function changedCellSpansFromCells(array $before, array $after): array
  {
    $spans = [];
    $start = null;

    for ($cell = 0; $cell < self::$width; $cell++) {
      if (($before[$cell] ?? ' ') === ($after[$cell] ?? ' ')) {
        if ($start !== null) {
          $spans[] = ['start' => $start, 'end' => $cell - 1];
          $start = null;
        }

        continue;
      }

      $start ??= $cell;
    }

    if ($start !== null) {
      $spans[] = ['start' => $start, 'end' => self::$width - 1];
    }

    return self::coalesceChangedSpans($spans);
  }

  /**
   * Merges nearby dirty runs when another cursor address would cost more.
   *
   * @param list<array{start: int, end: int}> $spans
   * @return list<array{start: int, end: int}>
   */
  private static function coalesceChangedSpans(array $spans): array
  {
    if ($spans === []) {
      return [];
    }

    usort($spans, static fn(array $left, array $right): int => $left['start'] <=> $right['start']);
    $merged = [];

    foreach ($spans as $span) {
      $lastIndex = count($merged) - 1;

      if (
        $lastIndex < 0
        || $span['start'] > $merged[$lastIndex]['end'] + self::DIRTY_SPAN_MERGE_GAP + 1
      ) {
        $merged[] = $span;
        continue;
      }

      $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $span['end']);
    }

    return $merged;
  }

  /**
   * Formats a native write without changing its logical source cells.
   * @param string[] $cells The complete, composed logical row.
   */
  private static function terminalRowSpan(int $row, array $cells, int $start, int $end): string
  {
    $visibleWidth = min(self::$width, (self::$terminalViewport[0] ?? self::$width) - self::$terminalOffsetX);
    $visibleHeight = min(self::$height, (self::$terminalViewport[1] ?? self::$height) - self::$terminalOffsetY);
    $start = max(0, $start);
    $end = min($end, $visibleWidth - 1);
    if ($row < 0 || $row >= $visibleHeight || $end < $start) { return ''; }

    // A dirty span can touch either half of a glyph. Replay the whole glyph
    // when it fits, but blank its visible fragment at the physical boundary.
    $start = self::resolveCellAnchor($cells, $start) ?? $start;
    $lastAnchor = self::resolveCellAnchor($cells, $end) ?? $end;
    $glyphEnd = $lastAnchor + max(1, TerminalText::getSymbolWidth($cells[$lastAnchor] ?? ' ')) - 1;
    $end = min($visibleWidth - 1, max($end, $glyphEnd));
    if ($glyphEnd >= $visibleWidth) { self::clearCellRange($cells, $lastAnchor, 1); }

    return self::terminalCursorAddress($start + 1, $row + 1)
      . self::cellsToRow(array_slice($cells, $start, $end - $start + 1));
  }

  /**
   * Converts a cell buffer back into a rendered row string.
   *
   * @param string[] $cells The cell buffer.
   * @return string The rendered row.
   */
  private static function cellsToRow(array $cells): string
  {
    $output = [];

    foreach (array_slice($cells, 0, self::$width) as $cell) {
      $output[] = $cell === self::WIDE_SYMBOL_CONTINUATION ? '' : $cell;
    }

    return implode('', $output);
  }

  /**
   * Clears a cell range, removing any wide glyphs that overlap the target span.
   *
   * @param array<int, string> $cells The cell buffer.
   * @param int $start The starting cell.
   * @param int $width The number of cells to clear.
   * @return void
   */
  private static function clearCellRange(array &$cells, int $start, int $width): void
  {
    if ($width <= 0 || $start >= self::$width) {
      return;
    }

    $anchorsToClear = [];
    $end = min(self::$width - 1, max($start, $start + $width - 1));

    for ($cellIndex = max(0, $start); $cellIndex <= $end; $cellIndex++) {
      $anchorIndex = self::resolveCellAnchor($cells, $cellIndex);

      if ($anchorIndex === null) {
        continue;
      }

      $anchorsToClear[$anchorIndex] = true;
    }

    foreach (array_keys($anchorsToClear) as $anchorIndex) {
      $symbol = $cells[$anchorIndex] ?? ' ';
      $symbolWidth = $symbol === self::WIDE_SYMBOL_CONTINUATION
        ? 1
        : max(1, TerminalText::getSymbolWidth($symbol));

      for ($offset = 0; $offset < $symbolWidth && $anchorIndex + $offset < self::$width; $offset++) {
        $cells[$anchorIndex + $offset] = ' ';
      }
    }
  }

  /**
   * Resolves the leading cell index for the symbol occupying the given cell.
   *
   * @param array<int, string> $cells The cell buffer.
   * @param int $cellIndex The cell index to inspect.
   * @return int|null The anchor cell index, or null if out of bounds.
   */
  private static function resolveCellAnchor(array $cells, int $cellIndex): ?int
  {
    if ($cellIndex < 0 || $cellIndex >= self::$width) {
      return null;
    }

    if (($cells[$cellIndex] ?? ' ') !== self::WIDE_SYMBOL_CONTINUATION) {
      return $cellIndex;
    }

    for ($anchorIndex = $cellIndex - 1; $anchorIndex >= 0; $anchorIndex--) {
      if (($cells[$anchorIndex] ?? ' ') !== self::WIDE_SYMBOL_CONTINUATION) {
        return $anchorIndex;
      }
    }

    return null;
  }

  /**
   * Shows an alert dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog.
   * @param int $width The width of the dialog.
   * @return void
   * @throws Exception
   */
  public static function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    ModalManager::getInstance(self::$game)->alert($message, $title, $width);
  }

  /**
   * Shows a confirm dialog with the given message and title. Returns true if the user confirmed, false otherwise.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog.
   * @param int $width The width of the dialog.
   * @return bool Whether the user confirmed or not.
   * @throws Exception If the game instance is not set.
   */
  public static function confirm(string $message, string $title = 'Confirm', int $width = DEFAULT_DIALOG_WIDTH): bool
  {
    if (!self::$game) {
      throw new RuntimeException('The game instance is not set.');
    }

    return ModalManager::getInstance(self::$game)->confirm($message, $title, $width);
  }

  /**
   * Shows a prompt dialog with the given message and title. Returns the user's input.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "Prompt".
   * @param string $default The default value of the input. Defaults to an empty string.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return string The user's input.
   */
  public static function prompt(
    string $message,
    string $title = 'Prompt',
    string $default = '',
    int    $width = DEFAULT_DIALOG_WIDTH
  ): string
  {
    return ModalManager::getInstance(self::$game)->prompt($message, $title, $default, $width);
  }

  /**
   * Shows a select dialog with the given message and options. Returns the index of the selected option.
   *
   * @param string $message The message to show.
   * @param array $options The options to show.
   * @param string $title The title of the dialog. Defaults to "".
   * @param int $default The default option. Defaults to 0.
   * @param Vector2|null $position The position of the dialog. Defaults to null.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return int The index of the selected option.
   */
  public static function select(
    string   $message,
    array    $options,
    string   $title = '',
    int      $default = 0,
    ?Vector2 $position = null,
    int      $width = DEFAULT_SELECT_DIALOG_WIDTH
  ): int
  {
    return ModalManager::getInstance(self::$game)->select($message, $options, $title, $default, $position, $width);
  }

  /**
   * Shows a text dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "".
   * @param string $help The help text to show. Defaults to "".
   * @param WindowPosition $position The position of the dialog. Defaults to BOTTOM (i.e. the bottom of the screen).
   * @param float $charactersPerSecond The number of characters to display per second.
   * @return void
   */
  public static function showText(
    string         $message,
    string         $title = '',
    string         $help = '',
    WindowPosition $position = WindowPosition::BOTTOM,
    float          $charactersPerSecond = 1
  ): void
  {
    ModalManager::getInstance(self::$game)->showText($message, $title, $help, $position, $charactersPerSecond);
  }

  /**
   * Returns the width of the console.
   *
   * @return int The width of the console.
   */
  public static function getWidth(): int
  {
    return self::$width;
  }

  /**
   * Returns the height of the console.
   *
   * @return int The height of the console.
   */
  public static function getHeight(): int
  {
    return self::$height;
  }
}
