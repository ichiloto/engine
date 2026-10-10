<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Localization\Vocabulary;

use Ichiloto\Engine\Core\Menu\MainMenu\CharacterSelectionMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCharacterSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuPartyOrderMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** Main Menu layout over live owners, with no command, selection or party-order state of its own. */
final class MainMenuPresentation
{
  private const int WIDTH = MenuLayout::MAX_WIDTH;
  private const int HEIGHT = MenuLayout::MAX_HEIGHT;
  private const int COMMAND_WIDTH = 300;
  private const int PARTY_WIDTH = self::WIDTH - self::COMMAND_WIDTH;
  private const int RESOURCE_WIDTH = 410;

  public static function compose(MainMenuState $state, MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas
  {
    $mode = $state->getPresentationMode();
    $commandsFocused = $mode instanceof MainMenuCommandSelectionMode;
    if ((!$commandsFocused && !$mode instanceof MainMenuCharacterSelectionMode && !$mode instanceof MainMenuPartyOrderMode)
      || $state->mainMenu === null || $state->characterSelectionMenu === null) { return null; }

    $view = new MenuCanvas($theme, time: $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $layout = self::getLayout($state, $theme);
    $verticalPadding = $layout['verticalPadding'];
    $info = implode("\n", $state->infoPanel?->getContent() ?? []);
    $infoHeight = $layout['infoHeight'];
    self::summary($view, 'main-info', $state->infoPanel?->getTitle() ?? '', $info,
      self::box(0, 0, self::WIDTH, $infoHeight), $verticalPadding);

    $summaries = $state->getPresentationSummaries();
    $summaryHeights = $layout['summaryHeights'];
    $selection = $state->characterSelectionMenu;
    $helpHeight = $layout['helpHeight'];
    $hintHeight = $layout['hintHeight'];
    $footerHeight = $layout['footerHeight'];

    $commandsHeight = self::HEIGHT - $infoHeight - array_sum($summaryHeights);
    $commandBox = self::box(0, $infoHeight, self::COMMAND_WIDTH, $commandsHeight);
    $view->frame('main-commands', $commandBox);
    $rows = [];
    foreach ($state->mainMenu->getItems() as $index => $command) {
      $selected = $index === $state->mainMenu->activeIndex;
      $rows[] = new MenuRow((string)$index, $command->getLabel(), kind: MenuRowKind::COMMAND,
        selected: $selected, focused: $commandsFocused && $selected, disabled: $command->isDisabled());
    }
    $compactHeight = (int)ceil(max($m->cellHeight + $theme->rows->metrics->separatorWidth,
      (int)ceil(min($m->rowHeight, $m->cellHeight + $m->sectionGap / 3)), $theme->rows->metrics->cursorHeight));
    $view->rows('main-command', $rows, new MenuRowLayout(self::inset($commandBox, $p, $p),
      rowHeight: $compactHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true), $state->mainMenu->activeIndex);
    $y = $infoHeight + $commandsHeight;
    foreach ($summaries as $id => $summary) {
      self::summary($view, 'main-' . $id, $summary['title'], implode("\n", $summary['lines']),
        self::box(0, $y, self::COMMAND_WIDTH, $summaryHeights[$id]), $verticalPadding, HorizontalAlignment::RIGHT);
      $y += $summaryHeights[$id];
    }

    $footer = self::box(self::COMMAND_WIDTH, self::HEIGHT - $footerHeight, self::PARTY_WIDTH, $footerHeight);
    $view->frame('main-help', $footer, 'quiet');
    $upperHeight = max($helpHeight, $layout['pagerWidth'] > 0 ? MenuPager::getHeight($theme) : 0);
    $upperY = $footer->y + ($hintHeight > 0 ? $layout['footerPadding'] : ($footerHeight - $upperHeight) / 2);
    $footerHorizontalPadding = $layout['footerHorizontalPadding'];
    $view->prose('main-help-text', $layout['help'], new CanvasRectangle($footer->x + $footerHorizontalPadding,
      $upperY + ($upperHeight - $helpHeight) / 2, $layout['helpWidth'], $helpHeight));
    if ($hintHeight > 0) {
      $view->hints('main-hints', $layout['hints'], new CanvasRectangle($footer->x + $footerHorizontalPadding,
        $footer->y + $footer->height - $layout['footerPadding'] - $hintHeight,
        $footer->width - 2 * $footerHorizontalPadding, $hintHeight));
    }

    $members = $state->party->members->toArray();
    $cardPadding = $layout['cardPadding'];
    $heights = $layout['heights'];
    $pages = $layout['pagination'];
    $pageIndex = $pages->getPageIndex($state->getPartyPresentationIndex());
    if ($layout['pagerWidth'] > 0) {
      MenuPager::render($view, 'main-party-pager', $pages, $pageIndex,
        new CanvasRectangle($footer->x + $footer->width - $footerHorizontalPadding - $layout['pagerWidth'],
          $upperY + ($upperHeight - MenuPager::getHeight($theme)) / 2, $layout['pagerWidth'], MenuPager::getHeight($theme)));
    }
    $partyBox = self::box(self::COMMAND_WIDTH, $infoHeight, self::PARTY_WIDTH, self::HEIGHT - $footerHeight - $infoHeight);
    ['first' => $first, 'last' => $last] = $pages->pages[$pageIndex];
    $y = $partyBox->y;
    for ($index = $first; $index <= $last; $index++) {
      $box = new CanvasRectangle($partyBox->x, $y, $partyBox->width, $heights[$index]);
      $id = 'main-party-' . $index;
      $view->frame($id . '-frame', $box, borderLayer: 20);
      $member = $members[$index] ?? null;
      if ($member instanceof Character) {
        self::character($view, $id, $member, $box, $cardPadding,
          $index === $selection->getMarkedPanelIndex(), !$commandsFocused && $index === $selection->getActivePanelIndex());
      }
      $y += $heights[$index];
    }
    return $view->finish();
  }

  public static function getPartyPagination(MainMenuState $state, MenuPresentationCatalog $theme): MenuPagination
  {
    return self::getLayout($state, $theme)['pagination'];
  }

  /** Measurement is shared by semantic page navigation and painting; neither mutates the party.
   * @return array{verticalPadding:int, infoHeight:int, summaryHeights:array<string,int>, help:string,
   *   helpHeight:int, helpWidth:int, hints:list<\Ichiloto\Engine\IO\ActionHint>, hintHeight:int,
   *   footerHeight:int, footerPadding:int, footerHorizontalPadding:int, pagerWidth:int, cardPadding:int,
   *   heights:non-empty-list<int>, pagination:MenuPagination}
   */
  private static function getLayout(MainMenuState $state, MenuPresentationCatalog $theme): array
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $verticalPadding = (int)min($p, floor($m->sectionGap / 3));
    $info = implode("\n", $state->infoPanel?->getContent() ?? []);
    $infoHeight = max(60, 2 * $verticalPadding + $m->cellHeight + self::textHeight($info, self::WIDTH - 2 * $p, $theme));
    $summaryHeights = [];
    foreach ($state->getPresentationSummaries() as $id => $summary) {
      $summaryHeights[$id] = max($id === 'location' ? 80 : 60, 2 * $verticalPadding
        + self::textHeight($summary['title'], self::COMMAND_WIDTH - 2 * $p, $theme)
        + self::textHeight(implode("\n", $summary['lines']), self::COMMAND_WIDTH - 2 * $p, $theme));
    }
    $help = $state->characterSelectionMenu?->getHelpText() ?? CharacterSelectionMenu::DEFAULT_HELP_TEXT;
    if ($help === CharacterSelectionMenu::DEFAULT_HELP_TEXT) {
      $help = $state->getPresentationMode() instanceof MainMenuCommandSelectionMode ? 'Select a command.' : 'Select a character.';
    }
    $helpWidth = self::PARTY_WIDTH - 2 * $p;
    $helpHeight = self::textHeight($help, $helpWidth, $theme);
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
    $hintHeight = MenuActionHints::height($hints, $theme, self::PARTY_WIDTH - 2 * $p);
    $footerPadding = $verticalPadding;
    $footerHorizontalPadding = $p;
    $footerHeight = max($summaryHeights['location'] ?? 80,
      2 * $footerPadding + $helpHeight + ($hintHeight > 0 ? $m->sectionGap + $hintHeight : 0));
    $cardInsets = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot,
      new CanvasRectangle(0, 0, self::PARTY_WIDTH, 140));
    $cardPadding = $cardInsets === null ? $verticalPadding : (int)ceil(max($verticalPadding, $cardInsets[1], $cardInsets[3]));
    $members = $state->party->members->toArray();
    $heights = [];
    for ($index = 0; $index < max(4, count($members)); $index++) {
      $heights[] = self::recordHeight($members[$index] ?? null, $theme, $cardPadding);
    }
    if ($members !== [] && count($members) < 4 && array_sum($heights) > self::HEIGHT - $footerHeight - $infoHeight) {
      $heights = array_slice($heights, 0, count($members));
    }
    $pagerWidth = 0;
    if (array_sum($heights) > self::HEIGHT - $footerHeight - $infoHeight) {
      $pagerWidth = MenuPager::getWidth($theme, count($heights));
      $footerInsets = ($theme->frames['quiet'] ?? $theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot,
        new CanvasRectangle(0, 0, self::PARTY_WIDTH, $footerHeight));
      if ($footerInsets !== null) {
        $footerPadding = (int)ceil(max($footerPadding, $footerInsets[1], $footerInsets[3]));
        $footerHorizontalPadding = (int)ceil(max($p, $footerInsets[0], $footerInsets[2]));
      }
      $helpWidth = self::PARTY_WIDTH - 2 * $footerHorizontalPadding - $pagerWidth - $m->sectionGap;
      $helpHeight = self::textHeight($help, $helpWidth, $theme);
      $hints = [...$hints, ActionHints::resolve(MenuPager::PREVIOUS_ACTION, 'Prev'), ActionHints::resolve(MenuPager::NEXT_ACTION, 'Next')];
      $hintHeight = MenuActionHints::height($hints, $theme, self::PARTY_WIDTH - 2 * $footerHorizontalPadding);
      $footerHeight = max($footerHeight, 2 * $footerPadding + max($helpHeight, MenuPager::getHeight($theme))
        + ($hintHeight > 0 ? $m->sectionGap + $hintHeight : 0));
    }
    if (isset($summaryHeights['location'])) { $summaryHeights['location'] = $footerHeight; }
    $pagination = new MenuPagination($heights, self::HEIGHT - $footerHeight - $infoHeight);
    return compact('verticalPadding', 'infoHeight', 'summaryHeights', 'help', 'helpHeight', 'helpWidth',
      'hints', 'hintHeight', 'footerHeight', 'footerPadding', 'footerHorizontalPadding', 'pagerWidth', 'cardPadding', 'heights', 'pagination');
  }

  private static function character(MenuCanvas $view, string $id, Character $member, CanvasRectangle $box,
    int $verticalPadding, bool $marked, bool $focused): void
  {
    $theme = $view->theme;
    $m = $theme->metrics;
    $portraitSize = $m->portraitSize + 2 * $m->sectionGap;
    $view->portrait($member->actorId, new CanvasRectangle($box->x + $m->panelPadding,
      $box->y + ($box->height - $portraitSize) / 2, $portraitSize, $portraitSize), $id . '-portrait');
    $identity = new MenuRow('identity', $member->name, selected: $marked || $focused, focused: $focused, showCursor: false);
    $identityBox = self::identityBox($box, $theme, $verticalPadding);
    $layout = self::getRecordLayout($theme, $identityBox, separateTreatment: true);
    $nameHeight = $layout->heightFor($identity, $theme->rows->metrics, false);
    $insets = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot, $box);
    $treatment = $insets === null ? self::inset($box, $m->sectionGap, $verticalPadding)
      : new CanvasRectangle($box->x + $insets[0], $box->y + $insets[1],
        $box->width - $insets[0] - $insets[2], $box->height - $insets[1] - $insets[3]);
    $view->record($id, $identity, $layout, $treatment);
    $rows = self::getResourceRows($member);
    $resourceY = $identityBox->y + $nameHeight;
    $view->rows($id . '-resources', $rows, self::getResourceLayout($theme,
      new CanvasRectangle($identityBox->x, $resourceY, $identityBox->width,
        $box->y + $box->height - $verticalPadding - $resourceY), $rows));
  }

  private static function recordHeight(?Character $member, MenuPresentationCatalog $theme, int $verticalPadding): int
  {
    if ($member === null) { return 140; }
    $m = $theme->metrics;
    $box = self::identityBox(new CanvasRectangle(0, 0, self::PARTY_WIDTH, self::HEIGHT), $theme, $verticalPadding);
    $layout = self::getRecordLayout($theme, $box, separateTreatment: true);
    $nameHeight = $layout->heightFor(new MenuRow('identity', $member->name), $theme->rows->metrics, false);
    $rows = self::getResourceRows($member);
    $resources = self::getResourceLayout($theme, $box, $rows);
    $resourceHeight = array_sum(array_map(fn(MenuRow $row) => $resources->heightFor($row, $theme->rows->metrics, false), $rows));
    return max(140, $m->portraitSize + 2 * $m->sectionGap,
      2 * $verticalPadding + $nameHeight + $resourceHeight);
  }

  /** @return list<MenuRow> */
  private static function getResourceRows(Character $member): array
  {
    $stats = $member->effectiveStats;
    return [new MenuRow('role', 'Role', [new MenuRowValue($member->role->name)]),
      new MenuRow('level', Vocabulary::getTerm('stats.level', 'Lv'), [new MenuRowValue((string)$member->level)]),
      new MenuRow('hp', Vocabulary::getTerm('stats.hp', 'HP'), [new MenuRowValue($stats->currentHp . ' / ' . $stats->totalHp)]),
      new MenuRow('mp', Vocabulary::getTerm('stats.mp', 'MP'), [new MenuRowValue($stats->currentMp . ' / ' . $stats->totalMp)])];
  }

  /** @param list<MenuRow> $rows */
  private static function getResourceLayout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $rows): MenuRowLayout
  {
    $viewport = new CanvasRectangle($box->x, $box->y, min(self::RESOURCE_WIDTH, $box->width), $box->height);
    $layout = self::getRecordLayout($theme, $viewport);
    $labelWidth = max(array_map(fn(MenuRow $row) => mb_strlen($row->label, 'UTF-8'), $rows));
    $valueWidth = max(array_map(fn(MenuRow $row) => mb_strlen($row->values[0]->text, 'UTF-8'), $rows));
    $valueWidth = min($valueWidth, $layout->textCells($theme->rows->metrics) - $labelWidth - $theme->rows->metrics->gapCells);
    return self::getRecordLayout($theme, $viewport, [new MenuRowColumn($valueWidth)]);
  }

  private static function identityBox(CanvasRectangle $box, MenuPresentationCatalog $theme, int $verticalPadding): CanvasRectangle
  {
    $m = $theme->metrics;
    $offset = $m->panelPadding + $m->portraitSize + 3 * $m->sectionGap;
    return new CanvasRectangle($box->x + $offset, $box->y + $verticalPadding,
      $box->width - $offset - $m->panelPadding, $box->height - 2 * $verticalPadding);
  }

  private static function getRecordLayout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $columns = [],
    bool $separateTreatment = false): MenuRowLayout
  {
    $m = $theme->metrics;
    $separator = $separateTreatment ? 0 : $theme->rows->metrics->separatorWidth;
    return new MenuRowLayout($box, $columns, (int)ceil(max($m->cellHeight + $separator,
      $theme->rows->metrics->cursorHeight)), $m->cellWidth, $m->cellHeight, true);
  }

  private static function summary(MenuCanvas $view, string $id, string $title, string $text, CanvasRectangle $box,
    int $verticalPadding, HorizontalAlignment $alignment = HorizontalAlignment::LEFT): void
  {
    $view->frame($id, $box, 'quiet');
    $body = self::inset($box, $view->theme->metrics->panelPadding, $verticalPadding);
    $titleHeight = $view->prose($id . '-title', $title, $body, 'accent');
    $view->prose($id . '-value', $text, new CanvasRectangle($body->x, $body->y + $titleHeight,
      $body->width, $body->height - $titleHeight), alignment: $alignment);
  }

  private static function textHeight(string $text, float $width, MenuPresentationCatalog $theme): int
  {
    return count(MenuCanvas::wrap($text, (int)floor($width / $theme->metrics->cellWidth))) * $theme->metrics->cellHeight;
  }

  private static function box(float $x, float $y, float $width, float $height): CanvasRectangle
  {
    $host = MenuLayout::getBounds();
    return new CanvasRectangle($host->x + $x, $host->y + $y, $width, $height);
  }

  private static function inset(CanvasRectangle $box, int $x, int $y): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $x, $box->y + $y, $box->width - 2 * $x, $box->height - 2 * $y);
  }
}
