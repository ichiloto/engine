<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use RuntimeException;

/** Holds identity/layout, not combat state. Generating a frame has no gameplay effects. */
final class GraphicalBattlePresentation
{
  /** @var array<int, array{battler: CharacterInterface, party: bool, image: CanvasImage, art: BattlerArtwork, available: bool}> */
  private array $participants = [];

  private function __construct(public readonly BattleArenaDefinition $arena, private readonly BattleConfig $battle,
    private readonly string $assetRoot) {}

  /** @return list<string> */
  public function requiredCapabilities(): array
  {
    $cropped = $this->arena->background->sourceRect !== null
      || array_any($this->participants, static fn(array $participant) => $participant['image']->sourceRect !== null);
    return [RendererSessionConfig::GRAPHICAL_CANVAS,
      ...($cropped || $this->arena->skin !== null ? [RendererSessionConfig::SPRITE_SOURCE_RECT] : []),
      ...($this->arena->skin !== null ? [RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  }

  public static function prepare(BattleConfig $battle, BattlePresentationCatalog $catalog, string $assetRoot): ?self
  {
    $arena = $catalog->arenaFor($battle);
    if ($arena === null) { return null; }
    if ($catalog->ui !== null) { $arena = $arena->withDefaultUi($catalog->ui); }
    $presentation = new self($arena, $battle, $assetRoot);
    $images = PngAssetPreflight::getAvailableSize($assetRoot, $arena->background->asset) === null ? [] : [$arena->background];
    foreach ([[$battle->party->members->toArray(), $arena->partySlots, true],
      [$battle->troop->members->toArray(), $arena->enemySlots, false]] as [$members, $slots, $party]) {
      foreach ($members as $index => $member) {
        $key = $member instanceof Character ? $member->actorId : $member->name;
        $art = ($party ? $catalog->actors : $catalog->enemies)[$key] ?? null;
        $slot = $slots[$party ? 0 : $index] ?? null;
        if ($slot === null) { throw new RuntimeException("Graphical battle requires a formation slot for every participant: {$key}"); }
        if ($art === null) {
          Debug::warn("No battler artwork registered; using a name fallback: {$key}");
        }
        $probe = $art === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $art->asset);
        $available = $probe !== null;
        $art ??= new BattlerArtwork('unavailable-battler.png', 1, 1, 0.5, 1);
        // Artwork changes throughout development; the image on disk is this
        // moment's truth. Authored metadata reconciles to it and the battle
        // renders best-effort, with the mismatch logged for the author
        // rather than blocking play. Assets only fail here when missing,
        // not PNGs, or corrupt.
        $reconciled = $probe === null ? $art : $art->clampedTo($probe['width'], $probe['height']);
        if ($reconciled !== $art) {
          Debug::warn(sprintf(
            'Battler artwork changed; rendering reconciled image bounds: %s (%s)',
            $key,
            $art->asset,
          ));
          $art = $reconciled;
        }
        $identity = spl_object_id($member);
        if (isset($presentation->participants[$identity])) {
          throw new RuntimeException('A graphical battle participant must be a distinct combatant instance.');
        }
        $image = new CanvasImage('combatant-' . $identity, $art->asset, $slot->place($art), 100, $art->sourceRect);
        // The existing Party may bring reserves forward when the frontline falls.
        // Preflight every legal placement without freezing or changing that policy.
        foreach ($party ? $slots : [$slot] as $possibleSlot) {
          $bounds = $possibleSlot->place($art);
          $bounds->assertWithin($arena->width, $arena->height);
          $arena->skin?->targetCursor?->layout($bounds, $arena->width, $arena->height);
          if (min($bounds->width, $bounds->height) < 1) {
            throw new RuntimeException('Battler images must cover at least one graphical unit in each dimension.');
          }
        }
        $presentation->participants[$identity] = ['battler' => $member, 'party' => $party, 'image' => $image, 'art' => $art, 'available' => $available];
        if ($available) { $images[] = $image; }
      }
    }
    GraphicalBattleHud::preflight($arena, $assetRoot, $images);
    if (count($battle->party->battlers) > count($arena->partySlots)) {
      throw new RuntimeException('Graphical battle requires a slot for every active party member.');
    }
    // Resolve every image before battle-entry effects; no partially valid formation.
    new PresentationCanvas($arena->width, $arena->height, $images);
    return $presentation;
  }

  /** @param list<CanvasTextLayer> $ui */
  public function frame(?BattleFieldWindow $field = null, array $ui = [], ?BattleHudSnapshot $hud = null,
    ?string $focus = null, ?float $now = null): PresentationCanvas
  {
    $now ??= hrtime(true) / 1_000_000_000;
    $images = PngAssetPreflight::getAvailableSize($this->assetRoot, $this->arena->background->asset) === null
      ? [] : [$this->arena->background];
    $indicators = $text = $feedbackParticipants = [];
    $cursorBounds = [];
    $feedback = $field?->getFeedback() ?? [];
    $selected = $field?->getSelectedBattlers() ?? [];
    $skin = $this->arena->skin;
    $composition = $skin !== null && $hud !== null
      ? GraphicalBattleHud::compose($this->arena, $hud, $focus, $now, $this->assetRoot) : null;
    $hudBounds = array_map(static fn(CanvasImage $image) => $image->destination, $composition?->images ?? []);
    $focused = $field?->getFocusedBattlers() ?? [];
    $queued = $field?->getQueuedBattlers() ?? [];
    $frontline = $this->battle->party->battlers->toArray();
    $enemies = $this->battle->troop->members->toArray();
    foreach ($this->participants as $identity => $participant) {
      $battler = $participant['battler'];
      $image = $participant['image'];
      $index = array_search($battler, $participant['party'] ? $frontline : $enemies, true);
      if ($index === false) { continue; }
      $size = PngAssetPreflight::getAvailableSize($this->assetRoot, $image->asset);
      $participant['available'] = $size !== null;
      $art = $size === null ? $participant['art'] : $participant['art']->clampedTo($size['width'], $size['height']);
      $slot = ($participant['party'] ? $this->arena->partySlots : $this->arena->enemySlots)[$index];
      $image = new CanvasImage($image->id, $image->asset, $slot->place($art), $image->layer, $art->sourceRect);
      $popups = array_filter($feedback, static fn(array $popup) => $popup['battler'] === $battler);
      if (!$participant['party'] && $battler->isKnockedOut && $popups === []) { continue; }
      if ($participant['available']) {
        $images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
          $image->sourceRect, $participant['party'] && $battler->isKnockedOut ? 0.4 : 1);
      }
      $bounds = $image->destination;
      $lines = $participant['available'] ? [] : [['text' => $battler->name, 'color' => null]];
      if (in_array($battler, $selected, true)) {
        if ($skin === null && $participant['available']) {
          $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
            $bounds, (int)min(2, $bounds->width, $bounds->height), PresentationColor::ansi16(14), 200);
        } elseif ($skin !== null) {
          $hasSelectionArt = false;
          if ($skin->targetCursor !== null) {
            $cursor = $skin->targetCursor->layout($bounds, $this->arena->width, $this->arena->height, $now,
              $focus === 'target' && in_array($battler, $focused, true) && !\Ichiloto\Engine\UI\Accessibility::prefersReducedMotion(), $hudBounds);
            if ($cursor !== null && CanvasTextureFallback::isAvailable($cursor['texture'], $this->assetRoot)) {
              array_push($images, ...$cursor['texture']->images('target-cursor-' . $identity, $cursor['bounds'], 202));
              $cursorBounds[] = $cursor['envelope'];
              $hasSelectionArt = true;
            }
          } elseif (CanvasTextureFallback::isAvailable($skin->textures['target'], $this->assetRoot)) {
            array_push($images, ...$skin->textures['target']->images('selected-' . $identity, $bounds, 200));
            $hasSelectionArt = true;
          }
          if (!$hasSelectionArt && $participant['available']) {
            $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
              $bounds, (int)min(2, $bounds->width, $bounds->height), $skin->colors['focus'], 200);
          }
          if (in_array($battler, $queued, true)) {
            if (CanvasTextureFallback::isAvailable($skin->textures['queued'], $this->assetRoot)) {
              array_push($images, ...$skin->textures['queued']->images('queued-' . $identity,
                new CanvasRectangle(max(0, $bounds->x + $bounds->width - 32), $bounds->y, 32, 32), 201));
            } else { $lines[] = ['text' => 'Queued', 'color' => $skin->colors['selected']]; }
          }
          if ($skin->targetCursor === null && $focus === 'target' && $battler === ($focused[0] ?? null)
            && CanvasTextureFallback::isAvailable($skin->textures['selector'], $this->assetRoot)) {
            $offset = GraphicalBattleHud::cursorOffset($now, \Ichiloto\Engine\UI\Accessibility::prefersReducedMotion());
            array_push($images, ...$skin->textures['selector']->images('field-cursor',
              new CanvasRectangle(max(0, min($this->arena->width - 20, $bounds->x - 24)) + $offset,
                max(0, $bounds->y + ($bounds->height - 16) / 2), 16, 16), 202));
          }
        }
        if ($participant['available']) { $lines[] = ['text' => $battler->name, 'color' => null]; }
      }
      if ($field?->getActingBattler() === $battler) {
        if ($skin === null && $participant['available']) {
          $indicators[] = new CanvasIndicator('acting-' . $identity, $image->id, CanvasIndicatorKind::UNDERLINE,
            $bounds, (int)min(4, $bounds->width, $bounds->height), PresentationColor::ansi16(11), 201);
        } elseif ($skin !== null && CanvasTextureFallback::isAvailable($skin->textures['acting'], $this->assetRoot)) {
          array_push($images, ...$skin->textures['acting']->images('acting-' . $identity,
            new CanvasRectangle($bounds->x, min($this->arena->height - 6, $bounds->y + $bounds->height), $bounds->width, 6), 201));
        } else { $lines[] = ['text' => 'Acting', 'color' => $skin?->colors['focus']]; }
      }
      if ($participant['party'] && $battler->isKnockedOut && $popups === []) {
        $lines[] = ['text' => 'KO', 'color' => null];
      }
      if ($skin !== null) {
        $feedbackParticipants[] = ['id' => $identity, 'bounds' => $bounds, 'persistent' => $lines, 'popups' => $popups];
        continue;
      }
      foreach ($popups as $popup) {
        foreach ($popup['lines'] as $row => $line) {
          $color = SgrColorParser::parse($line['color']->value . 'x')['foreground'];
          $lines[] = ['text' => $line['text'], 'color' => $color];
        }
      }
      if ($lines !== []) { $text[] = $this->labels('feedback-' . $identity, $lines, $bounds, $ui); }
    }
    if ($composition !== null) {
      array_push($images, ...$composition->images);
      array_push($text, ...$composition->textLayers);
    }
    if ($skin !== null) {
      array_push($text, ...GraphicalBattleFeedback::compose($this->arena, $feedbackParticipants, $ui, $now, [...$hudBounds, ...$cursorBounds]));
    }
    return new PresentationCanvas($this->arena->width, $this->arena->height, $images, $indicators, [...$text, ...$ui]);
  }

  /** @param list<array{text: string, color: ?PresentationColor}> $lines @param list<CanvasTextLayer> $ui */
  private function labels(string $id, array $lines, CanvasRectangle $bounds, array $ui): CanvasTextLayer
  {
    $cellWidth = $this->arena->uiGrid->cellWidth;
    $cellHeight = $this->arena->uiGrid->cellHeight;
    $runs = [];
    $columns = 1;
    foreach ($lines as $row => $line) {
      $text = mb_substr(TerminalText::stripAnsi($line['text']), 0, min(128, intdiv($this->arena->width, $cellWidth)), 'UTF-8');
      $columns = max($columns, mb_strlen($text, 'UTF-8'));
      $runs[] = new PresentationTextRun($row, 0, $text, $line['color'], PresentationColor::rgb(15, 23, 30));
    }
    $grid = new RendererGridConfig($columns, count($runs), $cellWidth, $cellHeight);
    $placement = BattleFeedbackPlacement::place($bounds, $columns * $cellWidth, count($runs) * $cellHeight,
      $this->arena->width, $this->arena->height, $ui);
    $placement->assertWithin($this->arena->width, $this->arena->height);
    return new CanvasTextLayer($id, 300, $placement->x, $placement->y, $grid, $runs);
  }
}
