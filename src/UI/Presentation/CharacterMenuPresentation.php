<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuCommandSelectionMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSelectionMode;
use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentSlotSelectionMode;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Engine-owned Equipment/Status composition over live owner data and one project theme. */
final class CharacterMenuPresentation
{
  private const int LEFT = 125;
  private const int TOP = 10;
  private const int WIDTH = 1100;
  private const int HEIGHT = 700;
  private const int PROFILE_WIDTH = 400;

  public static function equipment(EquipmentMenuState $state, ?EquipmentMenuMode $mode,
    MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas
  {
    $character = $state->character;
    if ($character === null || $state->equipmentMenu === null) { return null; }
    $view = new MenuCanvas($theme, time: $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $info = $state->equipmentInfoPanel?->getPresentationText() ?? '';
    $hints = self::characterHints();
    $footerCells = (int)floor((self::WIDTH - 2 * $p) / $m->cellWidth);
    $hintHeight = MenuActionHints::height($hints, $theme, self::WIDTH - 2 * $p);
    $hintGap = $hintHeight > 0 ? $m->sectionGap : 0;
    $infoHeight = max(80, 2 * $p + $hintHeight
      + ($info === '' ? 0 : $hintGap + count(MenuCanvas::wrap($info, $footerCells)) * $m->cellHeight));
    $bodyHeight = self::HEIGHT - $infoHeight;
    if ($bodyHeight < 300) { throw new RuntimeException('Equipment footer leaves insufficient menu viewport.'); }
    $profile = self::box(0, 0, self::PROFILE_WIDTH, $bodyHeight);
    $view->frame('equipment-profile', $profile);
    $identityHeight = $view->prose('equipment-identity', $character->name . "\n" . $character->role->name, self::inset($profile, $p));
    $portraitY = self::TOP + $p + $identityHeight + $m->sectionGap;
    $view->portrait($character->actorId, new CanvasRectangle(self::LEFT + (self::PROFILE_WIDTH - $m->portraitSize) / 2,
      $portraitY, $m->portraitSize, $m->portraitSize));
    $statsY = $portraitY + $m->portraitSize + $m->sectionGap;
    $statsBox = new CanvasRectangle($profile->x + $p, $statsY, $profile->width - 2 * $p,
      $profile->y + $profile->height - $p - $statsY);
    $stats = CharacterMenuRows::stats($character, $theme, true, $state->characterDetailPanel?->getPresentationPreview());
    $columns = self::numericColumns($stats);
    // Compact comparison text still has explicit current/preview columns, even for large numbers.
    $cells = 9 + array_sum(array_map(fn(MenuRowColumn $column) => $column->cells + $theme->rows->metrics->gapCells, $columns));
    $cw = min($m->cellWidth, (int)floor(($statsBox->width - 2 * $theme->rows->metrics->padding) / $cells));
    if ($cw < 4) { throw new RuntimeException('Equipment comparisons require more horizontal space.'); }
    $rowHeight = min($m->rowHeight, (int)floor($statsBox->height / count($stats)));
    $view->rows('equipment-stats', $stats, new MenuRowLayout($statsBox, $columns, $rowHeight, $cw, $m->cellHeight, true));

    $commands = $state->equipmentMenu->getItems()->toArray();
    $commandHeight = $m->rowHeight + 2 * $p;
    $commandBox = self::box(self::PROFILE_WIDTH, 0, self::WIDTH - self::PROFILE_WIDTH, $commandHeight);
    $view->frame('equipment-commands', $commandBox, 'quiet');
    $buttonWidth = ($commandBox->width - 2 * $p - (count($commands) - 1) * $m->sectionGap) / max(1, count($commands));
    foreach ($commands as $index => $command) {
      $selected = $index === $state->equipmentMenu->activeIndex;
      $row = new MenuRow('command-' . $index, $command->getLabel(), kind: MenuRowKind::BUTTON,
        selected: $selected, focused: $selected && $mode instanceof EquipmentMenuCommandSelectionMode, disabled: $command->isDisabled());
      $bounds = new CanvasRectangle($commandBox->x + $p + $index * ($buttonWidth + $m->sectionGap),
        $commandBox->y + $p, $buttonWidth, $m->rowHeight);
      $view->rows('equipment', [$row], self::layout($theme, $bounds));
    }
    $assignment = self::box(self::PROFILE_WIDTH, $commandHeight, self::WIDTH - self::PROFILE_WIDTH, $bodyHeight - $commandHeight);
    $view->frame('equipment-assignment', $assignment);
    $content = self::inset($assignment, $p);
    if ($mode instanceof EquipmentSelectionMode) {
      $rows = [];
      foreach ($mode->getPresentationCandidates() as $index => $candidate) {
        $item = $candidate['equipment'];
        $rows[] = new MenuRow('candidate-' . $index, $item->name, [new MenuRowValue((string)$candidate['available'])],
          icon: CharacterMenuRows::icon($item, $mode->equipmentSlot?->semanticSlot), selected: $candidate['current'],
          focused: $index === $mode->getPresentationIndex(), disabled: !$candidate['current'] && $candidate['available'] < 1);
      }
      $view->prose('equipment-slot-name', ($mode->equipmentSlot === null ? '' : $mode->equipmentSlot->name) . ' / Available',
        new CanvasRectangle($content->x, $content->y, $content->width, $m->cellHeight));
      $listBox = new CanvasRectangle($content->x, $content->y + $m->cellHeight + $m->sectionGap,
        $content->width, $content->height - $m->cellHeight - $m->sectionGap);
      $view->rows('equipment-candidates', $rows, self::layout($theme, $listBox, self::numericColumns($rows)), $mode->getPresentationIndex());
    } else {
      $index = $state->equipmentAssignmentPanel === null ? -1 : $state->equipmentAssignmentPanel->activeSlotIndex;
      $rows = CharacterMenuRows::slots($character, $index, $mode instanceof EquipmentSlotSelectionMode);
      $view->rows('equipment-slots', $rows, self::slotLayout($theme, $content, $rows), $index);
    }
    $infoBox = self::box(0, $bodyHeight, self::WIDTH, $infoHeight);
    $view->frame('equipment-info', $infoBox, 'quiet');
    if ($info !== '') {
      $view->prose('equipment-description', $info, new CanvasRectangle($infoBox->x + $p, $infoBox->y + $p,
        $infoBox->width - 2 * $p, $infoHeight - 2 * $p - $hintHeight - $hintGap));
    }
    if ($hintHeight > 0) {
      $view->hints('equipment-hints', $hints, new CanvasRectangle($infoBox->x + $p,
        $infoBox->y + $infoHeight - $p - $hintHeight, $infoBox->width - 2 * $p, $hintHeight));
    }
    return $view->finish();
  }

  public static function status(Character $character, MenuPresentationCatalog $theme, float $time = 0): PresentationCanvas
  {
    $view = new MenuCanvas($theme, time: $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $identityWidth = max(180, $m->portraitSize);
    $nameHeight = count(MenuCanvas::wrap($character->name, (int)floor($identityWidth / $m->cellWidth))) * $m->cellHeight;
    $roleHeight = count(MenuCanvas::wrap($character->role->name, (int)floor(360 / $m->cellWidth))) * $m->cellHeight;
    $profileHeight = max(180, 2 * $p + $roleHeight + 4 * ($m->cellHeight + 1) + $m->sectionGap,
      2 * $p + $nameHeight + $m->sectionGap + $m->portraitSize);
    $profile = self::box(0, 0, self::WIDTH, $profileHeight);
    $view->frame('status-profile', $profile);
    $nameHeight = $view->prose('status-name', $character->name,
      new CanvasRectangle($profile->x + $p, $profile->y + $p, $identityWidth, $profileHeight - 2 * $p - $m->portraitSize - $m->sectionGap));
    $view->portrait($character->actorId, new CanvasRectangle($profile->x + $p, $profile->y + $p + $nameHeight + $m->sectionGap,
      $m->portraitSize, $m->portraitSize));
    $resourceX = $profile->x + $p + $identityWidth + $m->sectionGap;
    $view->prose('status-role', $character->role->name, new CanvasRectangle($resourceX, $profile->y + $p, 360, $roleHeight));
    $stats = $character->effectiveStats;
    $resources = [new MenuRow('level', 'Lv', [new MenuRowValue((string)$character->level)])];
    foreach (['Hp', 'Mp', 'Ap'] as $resource) {
      $resources[] = new MenuRow(strtolower($resource), strtoupper($resource),
        [new MenuRowValue($stats->{'current' . $resource} . ' / ' . $stats->{'total' . $resource})]);
    }
    $resourceBox = new CanvasRectangle($resourceX, $profile->y + $p + $roleHeight + $m->sectionGap, 360, 4 * ($m->cellHeight + 1));
    $view->rows('status-resources', $resources, new MenuRowLayout($resourceBox, self::numericColumns($resources),
      $m->cellHeight + 1, $m->cellWidth, $m->cellHeight, true));
    $exp = [new MenuRow('current', 'Current EXP', [new MenuRowValue(number_format($character->currentExp))]),
      new MenuRow('next', 'To Next Level', [new MenuRowValue(number_format($character->nextLevelExp))])];
    $expX = $resourceX + 360 + $m->sectionGap;
    $view->rows('status-exp', $exp, self::layout($theme, new CanvasRectangle($expX, $resourceBox->y,
      $profile->x + $profile->width - $p - $expX, $profileHeight - $p - ($resourceBox->y - $profile->y)), self::numericColumns($exp)));
    $hints = self::characterHints();
    $hintHeight = MenuActionHints::height($hints, $theme, self::WIDTH - 2 * $p);
    $infoHeight = $hintHeight > 0 ? max(80, 2 * $p + $hintHeight) : 0;
    $bodyHeight = self::HEIGHT - $profileHeight - $infoHeight;
    $statBox = self::box(0, $profileHeight, self::PROFILE_WIDTH, $bodyHeight);
    $slotBox = self::box(self::PROFILE_WIDTH, $profileHeight, self::WIDTH - self::PROFILE_WIDTH, $bodyHeight);
    $view->frame('status-stats', $statBox);
    $view->frame('status-equipment', $slotBox);
    $rows = CharacterMenuRows::stats($character, $theme);
    $view->rows('status-stats', $rows, self::layout($theme, self::inset($statBox, $p), self::numericColumns($rows)));
    $slots = CharacterMenuRows::slots($character);
    $view->rows('status-slots', $slots, self::slotLayout($theme, self::inset($slotBox, $p), $slots));
    if ($hintHeight > 0) {
      $infoBox = self::box(0, self::HEIGHT - $infoHeight, self::WIDTH, $infoHeight);
      $view->frame('status-info', $infoBox, 'quiet');
      $view->hints('status-hints', $hints, self::inset($infoBox, $p));
    }
    return $view->finish();
  }

  /** @return list<ActionHint> */
  private static function characterHints(): array
  {
    // GameSceneState retains these cycling aliases even without semantic bindings.
    return [ActionHints::resolve('character_next', 'Next', KeyCode::TAB),
      ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB), ActionHints::resolve('back', 'Back')];
  }

  private static function box(float $x, float $y, float $width, float $height): CanvasRectangle
  {
    return new CanvasRectangle(self::LEFT + $x, self::TOP + $y, $width, $height);
  }

  private static function inset(CanvasRectangle $box, int $padding): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $padding, $box->y + $padding, $box->width - 2 * $padding, $box->height - 2 * $padding);
  }

  private static function layout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $columns = []): MenuRowLayout
  {
    $m = $theme->metrics;
    return new MenuRowLayout($box, $columns, $m->rowHeight, $m->cellWidth, $m->cellHeight, true);
  }

  /** @param list<MenuRow> $rows */
  private static function slotLayout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $rows): MenuRowLayout
  {
    $m = $theme->metrics;
    $row = $theme->rows->metrics;
    $label = max(1, ...array_map(fn(MenuRow $r) => mb_strlen($r->label), $rows));
    $icon = $theme->icons === null ? 0 : (int)ceil($row->iconWidth / $m->cellWidth) + $row->gapCells;
    $cells = (int)floor(($box->width - 2 * $row->padding) / $m->cellWidth) - $label - $icon - $row->gapCells;
    return self::layout($theme, $box, [new MenuRowColumn(max(1, $cells), HorizontalAlignment::LEFT)]);
  }

  /** @param list<MenuRow> $rows
   * @return list<MenuRowColumn>
   */
  private static function numericColumns(array $rows): array
  {
    $sizes = [];
    foreach ($rows as $row) {
      foreach ($row->values as $index => $value) { $sizes[$index] = max($sizes[$index] ?? 1, mb_strlen($value->text)); }
    }
    return array_map(fn(int $cells) => new MenuRowColumn($cells), $sizes);
  }
}
