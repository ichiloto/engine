<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

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

  public static function compose(MainMenuState $state, MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas
  {
    $mode = $state->getPresentationMode();
    $commandsFocused = $mode instanceof MainMenuCommandSelectionMode;
    if ((!$commandsFocused && !$mode instanceof MainMenuCharacterSelectionMode && !$mode instanceof MainMenuPartyOrderMode)
      || $state->mainMenu === null || $state->characterSelectionMenu === null) { return null; }

    $view = new MenuCanvas($theme, time: $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $verticalPadding = (int)min($p, floor($m->sectionGap / 3));
    $info = implode("\n", $state->infoPanel?->getContent() ?? []);
    $infoHeight = max(60, 2 * $verticalPadding + $m->cellHeight + self::textHeight($info, self::WIDTH - 2 * $p, $theme));
    self::summary($view, 'main-info', $state->infoPanel?->getTitle() ?? '', $info,
      self::box(0, 0, self::WIDTH, $infoHeight), $verticalPadding);

    $summaries = $state->getPresentationSummaries();
    $summaryHeights = [];
    foreach ($summaries as $id => $summary) {
      $summaryHeights[$id] = max($id === 'location' ? 80 : 60, 2 * $verticalPadding
        + self::textHeight($summary['title'], self::COMMAND_WIDTH - 2 * $p, $theme)
        + self::textHeight(implode("\n", $summary['lines']), self::COMMAND_WIDTH - 2 * $p, $theme));
    }
    $selection = $state->characterSelectionMenu;
    $help = $selection->getHelpText();
    if ($help === CharacterSelectionMenu::DEFAULT_HELP_TEXT) {
      $help = $commandsFocused ? 'Select a command.' : 'Select a character.';
    }
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
    $helpHeight = self::textHeight($help, self::PARTY_WIDTH - 2 * $p, $theme);
    $hintHeight = MenuActionHints::height($hints, $theme, self::PARTY_WIDTH - 2 * $p);
    // Location and help occupy one shared bottom row, regardless of hint visibility.
    $footerHeight = max($summaryHeights['location'] ?? 80,
      2 * $verticalPadding + $helpHeight + ($hintHeight > 0 ? $m->sectionGap + $hintHeight : 0));
    if (isset($summaryHeights['location'])) { $summaryHeights['location'] = $footerHeight; }

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
    $view->prose('main-help-text', $help, new CanvasRectangle($footer->x + $p,
      $footer->y + ($hintHeight > 0 ? $verticalPadding : ($footerHeight - $helpHeight) / 2),
      $footer->width - 2 * $p, $helpHeight));
    if ($hintHeight > 0) {
      $view->hints('main-hints', $hints, new CanvasRectangle($footer->x + $p,
        $footer->y + $footer->height - $verticalPadding - $hintHeight, $footer->width - 2 * $p, $hintHeight));
    }

    $members = $state->party->members->toArray();
    $cardInsets = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot,
      new CanvasRectangle(0, 0, self::PARTY_WIDTH, 140));
    $cardPadding = $cardInsets === null ? $verticalPadding : (int)ceil(max($verticalPadding, $cardInsets[1], $cardInsets[3]));
    $heights = [];
    for ($index = 0; $index < max(4, count($members)); $index++) {
      $heights[] = self::recordHeight($members[$index] ?? null, $theme, $cardPadding);
    }
    $partyBox = self::box(self::COMMAND_WIDTH, $infoHeight, self::PARTY_WIDTH, self::HEIGHT - $footerHeight - $infoHeight);
    [$first, $last] = $view->visibleRange('main-party', $heights, $partyBox, $commandsFocused ? 0 : $selection->getActivePanelIndex());
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
    $layout = self::recordLayout($theme, $identityBox);
    $nameHeight = $layout->heightFor($identity, $theme->rows->metrics, false);
    $insets = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot, $box);
    $treatment = $insets === null ? self::inset($box, $m->sectionGap, $verticalPadding)
      : new CanvasRectangle($box->x + $insets[0], $box->y + $insets[1],
        $box->width - $insets[0] - $insets[2], $box->height - $insets[1] - $insets[3]);
    $view->record($id, $identity, $layout, $treatment);
    $roleBox = new CanvasRectangle($identityBox->x + $theme->rows->metrics->padding, $identityBox->y + $nameHeight,
      $identityBox->width - 2 * $theme->rows->metrics->padding, $box->height - $nameHeight - 2 * $verticalPadding);
    $roleHeight = $view->prose($id . '-role', 'Role: ' . $member->role->name, $roleBox);
    $stats = $member->effectiveStats;
    $rows = [new MenuRow('level', 'Lv', [new MenuRowValue((string)$member->level)]),
      new MenuRow('hp', 'HP', [new MenuRowValue($stats->currentHp . ' / ' . $stats->totalHp)]),
      new MenuRow('mp', 'MP', [new MenuRowValue($stats->currentMp . ' / ' . $stats->totalMp)])];
    $valueWidth = max(array_map(fn(MenuRow $row) => mb_strlen($row->values[0]->text, 'UTF-8'), $rows));
    $resourceY = $roleBox->y + $roleHeight;
    $view->rows($id . '-resources', $rows, self::recordLayout($theme,
      new CanvasRectangle($identityBox->x, $resourceY, min(410, $identityBox->width), $box->y + $box->height - $verticalPadding - $resourceY),
      [new MenuRowColumn($valueWidth)]));
  }

  private static function recordHeight(?Character $member, MenuPresentationCatalog $theme, int $verticalPadding): int
  {
    if ($member === null) { return 140; }
    $m = $theme->metrics;
    $box = self::identityBox(new CanvasRectangle(0, 0, self::PARTY_WIDTH, self::HEIGHT), $theme, $verticalPadding);
    $layout = self::recordLayout($theme, $box);
    $nameHeight = $layout->heightFor(new MenuRow('identity', $member->name), $theme->rows->metrics, false);
    $roleHeight = self::textHeight('Role: ' . $member->role->name, $box->width - 2 * $theme->rows->metrics->padding, $theme);
    return max(140, $m->portraitSize + 2 * $m->sectionGap,
      2 * $verticalPadding + $nameHeight + $roleHeight + 3 * $layout->rowHeight);
  }

  private static function identityBox(CanvasRectangle $box, MenuPresentationCatalog $theme, int $verticalPadding): CanvasRectangle
  {
    $m = $theme->metrics;
    $offset = $m->panelPadding + $m->portraitSize + 3 * $m->sectionGap;
    return new CanvasRectangle($box->x + $offset, $box->y + $verticalPadding,
      $box->width - $offset - $m->panelPadding, $box->height - 2 * $verticalPadding);
  }

  private static function recordLayout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $columns = []): MenuRowLayout
  {
    $m = $theme->metrics;
    return new MenuRowLayout($box, $columns, (int)ceil(max($m->cellHeight + $theme->rows->metrics->separatorWidth,
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
