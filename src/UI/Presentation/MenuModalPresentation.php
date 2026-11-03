<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Menu-local modal projection. Existing modal owners retain input, selection and outcomes. */
final class MenuModalPresentation
{
  /** A stacked scene must compose all owners together, rather than baking its top modal into the base. */
  public static function requiresSceneComposition(UIManager $ui, string $assetRoot): bool
  {
    if (!file_exists($assetRoot . '/' . DialoguePresentationCatalog::FILE)) { return false; }
    $active = $ui->getActivePresentations();
    return count($active) > 1 || array_any($active,
      static fn($owner) => $owner instanceof DialoguePresentationProviderInterface);
  }

  /**
   * Shared graphical measurement for pagination and painting, with artwork and teaching controls reserved.
   * @return array{width:int,columns:int,artSize:float|int,messageWidth:float|int,messageColumns:int,titleHeight:int,noticeHints:array,noticeHintHeight:int,maximumRows:int}
   */
  public static function measureReadingArea(PresentationCanvas $base, ModalPresentation $modal, MenuPresentationCatalog $theme): array
  {
    $m = $theme->metrics; $p = $m->panelPadding; $gap = $m->sectionGap;
    $longest = static fn(string $text): int => max(array_map(mb_strlen(...), explode("\n",
      TerminalText::stripAnsi(TerminalText::formatStyles(MenuTextWrap::normalizeText($text))))));
    $buttonWidths = array_map(fn(string $label) => $longest($label) * $m->cellWidth + 2 * $theme->rows->metrics->padding, $modal->choices);
    $choicesWidth = $modal->singleConfirmation ? 2 * $buttonWidths[0]
      : ($modal->vertical ? max($buttonWidths) : array_sum($buttonWidths) + (count($buttonWidths) - 1) * $gap);
    $naturalWidth = max(36 * $m->cellWidth, $longest($modal->title) * $m->cellWidth,
      $longest($modal->sourceMessage ?? $modal->message) * $m->cellWidth, $choicesWidth);
    if ($modal->quantity !== null) {
      $naturalWidth = max($naturalWidth, (2 * strlen((string)$modal->quantity->maximum) + 3) * $m->cellWidth
        + 2 * ($m->cellHeight + $gap));
    }
    $width = (int)floor(min($base->width * 2 / 3 - 2 * $p, $naturalWidth) / $m->cellWidth) * $m->cellWidth;
    $columns = max(1, (int)floor($width / $m->cellWidth));
    $notice = $modal->notice;
    $hasArt = ($notice?->actorId !== null && isset($theme->portraits[$notice->actorId]))
      || ($notice?->iconRole !== null && isset($theme->icons?->icons[$notice->iconRole]));
    $artSize = $hasArt ? min($m->portraitSize, $width / 3) : 0;
    $messageWidth = $width - ($artSize > 0 ? $artSize + $gap : 0);
    $messageColumns = max(1, (int)floor($messageWidth / $m->cellWidth));
    $titleHeight = $modal->title === '' ? 0 : count(MenuCanvas::wrap($modal->title, $columns)) * $m->cellHeight;
    $noticeHints = $notice?->getActionHints() ?? [];
    $noticeHintHeight = $noticeHints === [] ? 0 : MenuActionHints::height($noticeHints, $theme, $messageWidth, required: true);
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
    $hintHeight = MenuActionHints::height($hints, $theme, $width);
    $quantityHeight = $modal->quantity === null ? 0 : 2 * $m->cellHeight + $gap;
    $availableBody = $base->height - 4 * $p - $titleHeight - $quantityHeight - $hintHeight
      - 2 * $gap - ($hintHeight > 0 ? $gap : 0) - $m->rowHeight - $m->cellHeight;
    if ($artSize > $availableBody) { throw new RuntimeException('Modal artwork exceeds its finite reading viewport.'); }
    $maximumRows = (int)floor(($availableBody - $noticeHintHeight - ($noticeHintHeight > 0 ? $gap : 0)) / $m->cellHeight);
    if ($maximumRows < 1) { throw new RuntimeException('Modal controls leave no finite reading area.'); }
    return compact('width', 'columns', 'artSize', 'messageWidth', 'messageColumns', 'titleHeight', 'noticeHints', 'noticeHintHeight', 'maximumRows');
  }

  public static function compose(PresentationCanvas $base, ModalPresentation $modal,
    MenuPresentationCatalog $theme, float $time = 0, ?string $ownerLayerId = null): PresentationCanvas
  {
    // A reader snapshot keeps its own full reading frame, measured by the same layout that negotiated it.
    if ($modal->storybook !== null) { return StorybookCanvasPresentation::compose($base, $modal, $theme, $time, $ownerLayerId); }
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $reading = self::measureReadingArea($base, $modal, $theme);
    $width = $reading['width'];
    $x = ($base->width - $width) / 2;
    $cells = $reading['columns'];
    $notice = $modal->notice;
    $hasPortrait = $notice?->actorId !== null && isset($theme->portraits[$notice->actorId]);
    $hasIcon = $notice?->iconRole !== null && isset($theme->icons?->icons[$notice->iconRole]);
    $artSize = $reading['artSize'];
    $messageX = $x + ($artSize > 0 ? $artSize + $gap : 0);
    $messageWidth = $reading['messageWidth'];
    $messageCells = $reading['messageColumns'];
    $titleHeight = $reading['titleHeight'];
    $messageHeight = $modal->message === '' ? 0 : count(MenuCanvas::wrap($modal->message, $messageCells)) * $m->cellHeight;
    $noticeHints = $reading['noticeHints'];
    $noticeHintHeight = $reading['noticeHintHeight'];
    $noticeHintGap = $noticeHintHeight > 0 && $messageHeight > 0 ? $gap : 0;
    $readingHeight = $messageHeight + $noticeHintGap + $noticeHintHeight;
    $bodyHeight = max($artSize, $modal->singleConfirmation ? max($readingHeight, 3 * $m->cellHeight) : $readingHeight);
    $quantityHeight = $modal->quantity === null ? 0 : 2 * $m->cellHeight + $gap;
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
    $hintHeight = MenuActionHints::height($hints, $theme, $width);
    $hintGap = $hintHeight > 0 ? $gap : 0;
    $available = $base->height - 4 * $p - $titleHeight - $bodyHeight - $quantityHeight - $hintHeight - 2 * $gap - $hintGap;
    if ($available < $m->rowHeight + $m->cellHeight) {
      throw new RuntimeException('Modal content exceeds its finite menu viewport; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $base->width, $base->height, $time);
    $rows = [];
    foreach ($modal->choices as $index => $choice) {
      $rows[] = new MenuRow((string)$index, $choice,
        kind: $modal->vertical ? MenuRowKind::COMMAND : MenuRowKind::BUTTON,
        selected: $index === $modal->activeIndex, focused: $index === $modal->activeIndex);
    }
    $singleButton = !$modal->vertical && count($rows) === 1;
    $buttonWidth = $singleButton ? MenuLayout::getButtonWidth($theme, $modal->choices[0], $width)
      : ($modal->vertical ? $width : ($width - (count($rows) - 1) * $gap) / count($rows));
    if ($buttonWidth <= 2 * $theme->rows->metrics->padding) {
      throw new RuntimeException('Modal buttons require a wider menu viewport.');
    }
    $layout = new MenuRowLayout(new CanvasRectangle(0, 0, $buttonWidth, $available), rowHeight: $m->rowHeight,
      cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
    $heights = array_map(fn(MenuRow $row) => $layout->heightFor($row, $theme->rows->metrics, false), $rows);
    $choiceHeight = $modal->vertical ? min($available, array_sum($heights)) : max($heights);
    if ($choiceHeight > $available) { throw new RuntimeException('Modal button text exceeds its finite menu viewport.'); }
    $height = 2 * $p + $titleHeight + $bodyHeight + $quantityHeight + $choiceHeight + $hintHeight + 2 * $gap + $hintGap;
    $top = ($base->height - $height) / 2;
    $box = new CanvasRectangle($x - $p, $top, $width + 2 * $p, $height);
    $view->frame('menu-modal-frame', $box);
    $view->protect($box);
    $y = $top + $p;
    if ($titleHeight > 0) {
      $view->prose('menu-modal-title', $modal->title, new CanvasRectangle($x, $y, $width, $titleHeight),
        alignment: HorizontalAlignment::CENTER);
    }
    $y += $titleHeight + $gap;
    if ($artSize > 0) {
      $artBox = new CanvasRectangle($x, $y + ($bodyHeight - $artSize) / 2, $artSize, $artSize);
      if ($hasPortrait) { $view->portrait($notice->actorId, $artBox, 'menu-modal-notice-portrait'); }
      elseif ($hasIcon) { $view->icon('menu-modal-notice-icon', $notice->iconRole, $artBox); }
    }
    $readingY = $y + ($bodyHeight - $readingHeight) / 2;
    if ($messageHeight > 0) {
      $view->prose('menu-modal-message', $modal->message,
        new CanvasRectangle($messageX, $readingY, $messageWidth, $messageHeight),
        alignment: $messageHeight === $m->cellHeight
          ? HorizontalAlignment::CENTER : HorizontalAlignment::LEFT);
    }
    $requiredHintCanvas = $noticeHintHeight === 0 ? null : MenuActionHints::compose($base->width, $base->height,
      'menu-modal-notice-hints', $noticeHints, $theme, new CanvasRectangle($messageX,
        $readingY + $messageHeight + $noticeHintGap, $messageWidth, $noticeHintHeight), required: true);
    $y += $bodyHeight + $gap;
    $controls = new MenuControls($theme);
    if ($modal->quantity !== null) {
      $quantity = $modal->quantity;
      $center = $base->width / 2;
      $valueWidth = (2 * strlen((string)$quantity->maximum) + 3) * $m->cellWidth;
      $left = $center - $valueWidth / 2;
      $view->prose('menu-modal-quantity', $quantity->value . ' / ' . $quantity->maximum,
        new CanvasRectangle($left, $y + $m->cellHeight / 2, $valueWidth, $m->cellHeight), alignment: HorizontalAlignment::CENTER);
      $arrowX = $left + $valueWidth + $gap;
      $controls->renderChevron('menu-modal-quantity-up', MenuDirection::UP->value,
        new CanvasRectangle($arrowX, $y, $m->cellHeight, $m->cellHeight), $quantity->value < $quantity->maximum);
      $controls->renderChevron('menu-modal-quantity-down', MenuDirection::DOWN->value,
        new CanvasRectangle($arrowX, $y + $m->cellHeight, $m->cellHeight, $m->cellHeight), $quantity->value > $quantity->minimum);
      $y += $quantityHeight;
    }
    if ($modal->vertical) {
      $view->rows('menu-modal-choice', $rows, new MenuRowLayout(new CanvasRectangle($x, $y, $width, $choiceHeight),
        rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true), $modal->activeIndex);
    } else {
      foreach ($rows as $index => $row) {
        $view->rows('menu-modal-choice', [$row], new MenuRowLayout(
          new CanvasRectangle($x + ($singleButton ? ($width - $buttonWidth) / 2 : $index * ($buttonWidth + $gap)),
            $y, $buttonWidth, $choiceHeight),
          rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
      }
    }
    if ($hintHeight > 0) {
      $view->hints('menu-modal-hints', $hints, new CanvasRectangle($x, $y + $choiceHeight + $hintGap, $width, $hintHeight));
    }
    $body = $view->finish();
    if ($requiredHintCanvas !== null) { $body = MenuCanvas::overlay($body, $requiredHintCanvas, $theme); }
    $dialog = MenuCanvas::overlay($body, $controls->finish($base->width, $base->height), $theme);
    $result = MenuCanvas::overlay($base, $dialog, $theme);
    return $ownerLayerId === null ? $result : new PresentationCanvas($result->width, $result->height,
      $result->images, $result->indicators, $result->textLayers, $result->composites, $result->protectedAreas,
      [...$result->presentationOwners, $ownerLayerId], viewportFit: $result->viewportFit);
  }
}
