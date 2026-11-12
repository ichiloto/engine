<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\StorybookPresentation;
use Ichiloto\Engine\UI\Modal\StorybookReadingCapacity;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/**
 * Graphical projection of the shared storybook reader: the selected page's painting and reading text in a
 * full reading frame. The modal's StorybookPlayback owns pages, continuations and navigation; this class only
 * measures the prose area a surface offers and paints the owner's immutable snapshot inside it.
 *
 * One layout serves both measurement and painting, so the negotiated capacity is exactly the prose area drawn.
 * A book with any picture reserves the painting slot when it is measured; a page without a picture centres the
 * same prose block in the whole body.
 */
final class StorybookCanvasPresentation
{
  /** The painting slot's share of the body width (or height on a portrait surface). */
  private const float PICTURE_SHARE = 0.4;
  /** The least frame padding that holds the reader position as a border caption. */
  private const int MIN_CAPTION_PADDING = 8;

  /** The prose area this surface offers every page of a book, excluding its header and controls. */
  public static function measureReadingCapacity(StorybookDefinition $book, MenuPresentationCatalog $theme,
    int $width, int $height): StorybookReadingCapacity
  {
    $pictured = array_any($book->pages, static fn($page): bool => $page->picture !== null);
    $layout = self::buildLayout($theme, $width, $height, $book->title, $book->narrator, $pictured);
    return new StorybookReadingCapacity($layout['columns'], $layout['rows']);
  }

  public static function compose(PresentationCanvas $base, ModalPresentation $modal,
    MenuPresentationCatalog $theme, float $time = 0, ?string $ownerLayerId = null): PresentationCanvas
  {
    $book = $modal->storybook ?? throw new RuntimeException('A storybook projection requires the reader snapshot.');
    $m = $theme->metrics;
    $layout = self::buildLayout($theme, $base->width, $base->height, $modal->title, $book->narrator, $book->page->picture !== null);
    $capacity = $book->capacity;
    $prose = $layout['prose'];
    $blockWidth = $capacity->columns * $m->cellWidth;
    $blockHeight = $capacity->rows * $m->cellHeight;
    if ($blockWidth > $prose->width || $blockHeight > $prose->height) {
      throw new RuntimeException('Storybook reading capacity exceeds this surface; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $base->width, $base->height, $time, background: false);
    $view->frame('storybook-frame', $layout['frame'], 'storybook');
    $view->protect($layout['frame']);
    if ($layout['title'] !== null) {
      $view->prose('storybook-title', $modal->title, $layout['title'], 'focus', HorizontalAlignment::CENTER);
    }
    if ($layout['narrator'] !== null) {
      $view->prose('storybook-narrator', $book->narrator, $layout['narrator'], 'muted', HorizontalAlignment::CENTER);
    }
    if ($layout['picture'] !== null) { self::paintPicture($view, $theme, $book, $layout['picture']); }
    $block = new CanvasRectangle($prose->x + ($prose->width - $blockWidth) / 2,
      $prose->y + ($prose->height - $blockHeight) / 2, $blockWidth, $blockHeight);
    $view->prose('storybook-reading', $modal->message, $block);
    $view->renderBorderCaption('storybook-page', self::getPageLabel($book), $layout['page']);
    self::paintButtons($view, $theme, $modal, $layout['buttons']);
    if ($layout['hints'] !== null) { $view->hints('storybook-hints', self::getHints(), $layout['hints']); }
    $result = MenuCanvas::overlay($base, $view->finish(), $theme);
    return $ownerLayerId === null ? $result : new PresentationCanvas($result->width, $result->height,
      $result->images, $result->indicators, $result->textLayers, $result->composites, $result->protectedAreas,
      [...$result->presentationOwners, $ownerLayerId], $result->labels, $result->viewportFit);
  }

  /** The reader's position, in the Terminal reader's own form. */
  public static function getPageLabel(StorybookPresentation $book): string
  {
    return sprintf('%d/%d', $book->pageNumber, $book->pageCount)
      . ($book->continuationCount > 1 ? sprintf(' (%d/%d)', $book->continuationNumber, $book->continuationCount) : '');
  }

  /** @return list<\Ichiloto\Engine\IO\ActionHint> The reader's semantic actions, as the Terminal reader lists them. */
  private static function getHints(): array
  {
    return [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Close')];
  }

  /**
   * Frame, header, body, page label, button row and hints for one surface.
   * @return array{frame: CanvasRectangle, title: ?CanvasRectangle, narrator: ?CanvasRectangle, picture: ?CanvasRectangle,
   *   prose: CanvasRectangle, page: CanvasRectangle, buttons: CanvasRectangle, hints: ?CanvasRectangle, columns: int, rows: int}
   */
  private static function buildLayout(MenuPresentationCatalog $theme, int $width, int $height, string $title,
    string $narrator, bool $pictured): array
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $margin = MenuLayout::OUTER_MARGIN;
    $frame = new CanvasRectangle($margin, $margin, $width - 2 * $margin, $height - 2 * $margin);
    $innerX = $frame->x + $p;
    $innerWidth = $frame->width - 2 * $p;
    $columns = (int)floor($innerWidth / $m->cellWidth);
    if ($columns < 1) { throw new RuntimeException('Storybook frame leaves no reading width.'); }
    $lines = static fn(string $text): int => $text === '' ? 0 : count(MenuCanvas::wrap($text, $columns));
    $titleHeight = $lines($title) * $m->cellHeight;
    $narratorHeight = $lines($narrator) * $m->cellHeight;
    $hintHeight = MenuActionHints::height(self::getHints(), $theme, $innerWidth);
    $buttonHeight = MenuLayout::getButtonHeight($theme);
    $y = $frame->y + $p;
    $titleBox = $titleHeight > 0 ? new CanvasRectangle($innerX, $y, $innerWidth, $titleHeight) : null;
    $y += $titleHeight;
    $narratorBox = $narratorHeight > 0 ? new CanvasRectangle($innerX, $y, $innerWidth, $narratorHeight) : null;
    $y += $narratorHeight + ($titleHeight + $narratorHeight > 0 ? $gap : 0);
    $bottom = $frame->y + $frame->height - $p;
    $hints = $hintHeight > 0 ? new CanvasRectangle($innerX, $bottom - $hintHeight, $innerWidth, $hintHeight) : null;
    $bottom -= $hintHeight + ($hintHeight > 0 ? $gap : 0);
    $buttons = new CanvasRectangle($innerX, $bottom - $buttonHeight, $innerWidth, $buttonHeight);
    $bottom -= $buttonHeight + $gap;
    // The reader position is compact metadata: it sits in the frame's bottom padding, as the Terminal reader keeps
    // it on its border, unless the theme leaves no padding to hold it.
    if ($p >= self::MIN_CAPTION_PADDING) {
      $page = new CanvasRectangle($innerX, $frame->y + $frame->height - $p, $innerWidth, $p);
    } else {
      $page = new CanvasRectangle($innerX, $bottom - $m->cellHeight, $innerWidth, $m->cellHeight);
      $bottom -= $m->cellHeight + $gap;
    }
    $body = new CanvasRectangle($innerX, $y, $innerWidth, $bottom - $y);
    if ($body->height < $m->cellHeight) {
      throw new RuntimeException('Storybook header and controls leave no finite reading area.');
    }
    $picture = null;
    $prose = $body;
    if ($pictured) {
      if ($body->width >= $body->height) {
        $share = floor(($body->width - $gap) * self::PICTURE_SHARE);
        $picture = new CanvasRectangle($body->x, $body->y, $share, $body->height);
        $prose = new CanvasRectangle($body->x + $share + $gap, $body->y, $body->width - $share - $gap, $body->height);
      } else {
        $share = floor(($body->height - $gap) * self::PICTURE_SHARE);
        $picture = new CanvasRectangle($body->x, $body->y, $body->width, $share);
        $prose = new CanvasRectangle($body->x, $body->y + $share + $gap, $body->width, $body->height - $share - $gap);
      }
    }
    $proseColumns = (int)floor($prose->width / $m->cellWidth);
    $proseRows = (int)floor($prose->height / $m->cellHeight);
    if ($proseColumns < 1 || $proseRows < 1) {
      throw new RuntimeException('Storybook painting leaves no finite reading area.');
    }
    return ['frame' => $frame, 'title' => $titleBox, 'narrator' => $narratorBox, 'picture' => $picture,
      'prose' => $prose, 'page' => $page, 'buttons' => $buttons, 'hints' => $hints,
      'columns' => $proseColumns, 'rows' => $proseRows];
  }

  /** The page's painting contained in its slot; an unavailable one leaves the slot's quiet backing. */
  private static function paintPicture(MenuCanvas $view, MenuPresentationCatalog $theme,
    StorybookPresentation $book, CanvasRectangle $slot): void
  {
    $inset = 0;
    if (isset($theme->frames['storybook.picture'])) {
      $view->frame('storybook-picture-frame', $slot, 'storybook.picture', 21);
      $inset = min($theme->metrics->sectionGap, $slot->width / 4, $slot->height / 4);
    }
    $box = new CanvasRectangle($slot->x + $inset, $slot->y + $inset, $slot->width - 2 * $inset, $slot->height - 2 * $inset);
    $images = MenuIconRegistry::containAsset($theme->assetRoot, 'storybook-picture', (string)$book->page->picture,
      $box, 22, $box);
    if ($images === []) {
      $view->surface('storybook-picture-missing', $box, 'panel', 22);
      return;
    }
    $view->picture($box, $images);
  }

  /** The owner's controls as one centred group of equal buttons, the active one focused. */
  private static function paintButtons(MenuCanvas $view, MenuPresentationCatalog $theme, ModalPresentation $modal,
    CanvasRectangle $row): void
  {
    $m = $theme->metrics;
    $gap = $m->sectionGap;
    $count = count($modal->choices);
    $available = ($row->width - ($count - 1) * $gap) / $count;
    $buttonWidth = max(array_map(static fn(string $label): float => MenuLayout::getButtonWidth($theme, $label, $available),
      $modal->choices));
    if ($buttonWidth <= 2 * $theme->rows->metrics->padding) {
      throw new RuntimeException('Storybook buttons require a wider reading frame.');
    }
    $left = $row->x + ($row->width - ($count * $buttonWidth + ($count - 1) * $gap)) / 2;
    foreach ($modal->choices as $index => $choice) {
      $active = $index === $modal->activeIndex;
      $view->rows('storybook-control', [new MenuRow((string)$index, $choice, kind: MenuRowKind::BUTTON,
        selected: $active, focused: $active)], MenuLayout::getButtonLayout($theme,
          new CanvasRectangle($left + $index * ($buttonWidth + $gap), $row->y, $buttonWidth, $row->height), wrapText: true));
    }
  }
}
