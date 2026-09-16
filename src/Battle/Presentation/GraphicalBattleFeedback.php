<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Accessibility;

/** Existing literal results projected into bounded, glyph-only presentation. */
final class GraphicalBattleFeedback
{
  /** @param list<\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle> $occupied Actual opaque HUD surfaces.
   * @return list<CanvasTextLayer>
   */
  public static function compose(BattleArenaDefinition $arena, array $participants, array $ui, float $now, array $occupied = []): array
  {
    if ($arena->skin === null || $arena->feedbackArea === null) { return []; }
    $skin = $arena->skin;
    $effects = new CanvasGlyphEffects(2, $skin->colors['ink'], 0, 2, 1, 0.7, $skin->colors['ink']);
    $padding = $effects->padding();
    $layers = [];
    $reducedMotion = Accessibility::prefersReducedMotion();
    $blocks = [];
    // Stationary names and KO occupy space first, so later popups cannot displace them.
    foreach ($participants as $participant) {
      if ($participant['persistent'] !== []) {
        $blocks[] = ['id' => 'label-' . $participant['id'], 'owner' => $participant['id'], 'bounds' => $participant['bounds'],
          'lines' => $participant['persistent'], 'popup' => null];
      }
    }
    foreach ($participants as $participant) {
      foreach ($participant['popups'] as $popup) {
        if (BattleFeedbackTiming::duration($popup['durationSeconds']) === 0.0 || $popup['lines'] === []) { continue; }
        $blocks[] = ['id' => 'result-' . $participant['id'] . '-' . $popup['sequence'], 'owner' => $participant['id'],
          'bounds' => $participant['bounds'], 'lines' => $popup['lines'], 'popup' => $popup];
      }
    }
    foreach ($blocks as $block) {
      $popup = $block['popup'];
      $otherBattlers = array_column(array_filter($participants,
        static fn(array $participant) => $participant['id'] !== $block['owner']), 'bounds');
      $critical = array_any($block['lines'], static fn($line) => ($line['role'] ?? null) === BattleFeedbackRole::CRITICAL);
      $rise = $popup === null || $reducedMotion ? 0 : 32;
      $layout = self::layout($block['lines'], $popup === null, false, $arena->feedbackArea->width, $padding);
      $placement = BattleFeedbackPlacement::moving($block['bounds'], $layout['width'], $layout['height'], $arena->feedbackArea,
        $ui, $occupied, $rise, $otherBattlers, beside: $popup !== null);
      if ($popup !== null && $critical) {
        $enlarged = self::layout($block['lines'], false, true, $arena->feedbackArea->width, $padding);
        // Enlargement is optional; preserve the whole literal block and its clear travel envelope first.
        if (!$enlarged['wrappedDamage'] && $enlarged['height'] <= $arena->feedbackArea->height) {
          $candidate = BattleFeedbackPlacement::moving($block['bounds'], $enlarged['width'], $enlarged['height'],
            $arena->feedbackArea, $ui, $occupied, $rise, $otherBattlers, beside: true);
          if ($candidate['overlap'] === 0.0) { $layout = $enlarged; $placement = $candidate; }
        }
      }
      $occupied[] = $placement['envelope'];
      $motion = $popup === null ? ['rise' => 0.0, 'opacity' => 1.0]
        : BattleFeedbackTiming::motion($popup['shownAt'], $popup['durationSeconds'], $now,
          $placement['rise'], $reducedMotion);
      $y = $placement['bounds']->y - $motion['rise'];
      foreach ($layout['rows'] as $index => [$labels, $grid, $colorRole, $rowWidth, $rowHeight]) {
        $runs = [];
        foreach ($labels as $row => $label) { $runs[] = new PresentationTextRun($row, 0, $label, $skin->colors[$colorRole]); }
        $layers[] = new CanvasTextLayer($block['id'] . '-' . $index, 300,
          $placement['bounds']->x + ($layout['width'] - $rowWidth) / 2 + $padding['left'], $y + $padding['top'], $grid, $runs,
          opacity: $motion['opacity'], glyphEffects: $effects);
        $y += $rowHeight;
      }
    }
    return $layers;
  }

  /** One layer per semantic line; authored line breaks and wrapping become local grid rows, not control glyphs. */
  private static function layout(array $lines, bool $persistent, bool $critical, float $maximumWidth, array $padding): array
  {
    $rows = [];
    $width = $height = 0;
    $wrappedDamage = false;
    foreach ($lines as $line) {
      $role = $line['role'] ?? null;
      [$pitch, $lineHeight, $colorRole] = $persistent ? [10, 20, 'text'] : self::style($role, $critical);
      $capacity = max(1, min(512, (int)floor(($maximumWidth - $padding['left'] - $padding['right']) / $pitch)));
      $labels = [];
      foreach (preg_split('/\R/u', TerminalText::stripAnsi($line['text'])) as $label) {
        $length = mb_strlen($label, 'UTF-8');
        $wrappedDamage = $wrappedDamage || ($critical && $role === BattleFeedbackRole::DAMAGE && $length > $capacity);
        for ($offset = 0; $offset < max(1, $length); $offset += $capacity) {
          $labels[] = mb_substr($label, $offset, $capacity, 'UTF-8');
        }
      }
      $columns = max(1, ...array_map(static fn(string $label): int => mb_strlen($label, 'UTF-8'), $labels));
      $grid = new RendererGridConfig($columns, count($labels), $pitch, $lineHeight);
      $rowWidth = $columns * $pitch + $padding['left'] + $padding['right'];
      $rowHeight = count($labels) * $lineHeight + $padding['top'] + $padding['bottom'];
      $rows[] = [$labels, $grid, $colorRole, $rowWidth, $rowHeight];
      $width = max($width, $rowWidth);
      $height += $rowHeight;
    }
    return ['rows' => $rows, 'width' => $width, 'height' => $height, 'wrappedDamage' => $wrappedDamage];
  }

  private static function style(?BattleFeedbackRole $role, bool $critical): array
  {
    return match ($role) {
      BattleFeedbackRole::CRITICAL => [11, 22, 'focus'],
      BattleFeedbackRole::WEAK => [11, 22, 'damage'],
      BattleFeedbackRole::RESIST, BattleFeedbackRole::NULL => [11, 22, 'mp'],
      BattleFeedbackRole::ABSORB => [11, 22, 'healing'],
      BattleFeedbackRole::KO => [13, 26, 'focus'],
      BattleFeedbackRole::MISS => [17, 34, 'text'],
      BattleFeedbackRole::DAMAGE => $critical ? [24, 48, 'damage'] : [20, 40, 'damage'],
      BattleFeedbackRole::HEAL => [20, 40, 'healing'],
      BattleFeedbackRole::MP_LOSS, BattleFeedbackRole::MP_GAIN => [20, 40, 'mp'],
      default => [20, 40, 'text'],
    };
  }
}
