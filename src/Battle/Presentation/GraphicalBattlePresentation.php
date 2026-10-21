<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextBatch;
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
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;

/** Holds identity/layout, not combat state. Generating a frame has no gameplay effects. */
final class GraphicalBattlePresentation
{
  /** @var array<int, array{battler: CharacterInterface, party: bool, image: CanvasImage, art: BattlerArtwork, available: bool, poses: ?BattlePoseSet, scale: ?BattlerScale, grounding: ?BattlerGrounding, habitat: ?EnemyHabitat}> */
  private array $participants = [];
  private array $poseDiagnostics = [];
  private array $compositionDiagnostics = [];
  private readonly BattleConditionEffects $conditionEffects;
  private readonly BattleHeadAttachmentLayout $headAttachments;
  private readonly EnemyDefeatStyle $defeatStyle;
  /** Where this battle's party and troop stand, fixed for the battle. */
  private BattleStaging $staging;
  /** @var array<int, BattlerSlot> Troop member instance => its slot; defeat never renumbers survivors. */
  private array $enemySlots = [];

  private function __construct(public readonly BattleArenaDefinition $arena, public readonly BattleCanvasLayout $layout,
    private readonly BattleConfig $battle,
    private readonly string $assetRoot, private readonly ?BattleScale $scale)
  {
    $this->headAttachments = new BattleHeadAttachmentLayout();
    $this->defeatStyle = EnemyDefeatStyle::createFromConfig();
    $this->conditionEffects = BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($assetRoot));
  }

  private function placeBaseArtwork(BattlerSlot $slot, ?BattlerArtwork $art, ?BattlePoseSet $poses, int $identity,
    ?BattlerScale $profile = null): CanvasRectangle
  {
    $placed = BattleFormationLayout::getBasePlacement($slot, $art, $this->layout, $poses, $profile, $this->scale);
    $diagnostic = $identity . ':base-placement:' . $slot->x . ':' . $slot->y;
    if ($placed['diagnostic'] !== null && !isset($this->poseDiagnostics[$diagnostic])) {
      Debug::warn($placed['diagnostic']);
      $this->poseDiagnostics[$diagnostic] = true;
    }
    return $placed['bounds'];
  }

  /** Where a participant stands: its frontline slot, or its troop place on this arena's ground. */
  public function getSlot(CharacterInterface $battler): BattlerSlot
  {
    $identity = spl_object_id($battler);
    $participant = $this->participants[$identity] ?? throw new RuntimeException('Not a participant of this graphical battle.');
    if (!$participant['party']) {
      return $this->enemySlots[$identity] ?? throw new RuntimeException('A troop participant lost its graphical formation slot.');
    }
    $index = array_search($battler, $this->battle->partyRoster->battlers, true);
    return $index === false ? throw new RuntimeException('Only frontline members stand in the party formation.')
      : $this->staging->party[$index] ?? throw new RuntimeException('Graphical battle requires a slot for every active party member.');
  }

  public function getStaging(): BattleStaging
  {
    return $this->staging;
  }

  private function stage(): BattleStaging
  {
    $getSize = function (CharacterInterface $battler, ?BattleStageSlot $place): array {
      $participant = $this->participants[spl_object_id($battler)];
      return BattleFormationLayout::getStandingSize($participant['available'] ? $participant['art'] : null,
        $participant['poses'], $participant['scale'], $this->scale, $place, $this->assetRoot);
    };
    $enemies = $this->battle->troop->members->toArray();
    $places = array_map(fn($enemy): ?BattleStageSlot => $this->battle->troop->getStagePlace($enemy), $enemies);
    $staging = BattleStaging::stage($this->layout, $this->arena,
      array_map(static fn(CharacterInterface $battler): array => $getSize($battler, null), $this->battle->partyRoster->battlers),
      $places, array_map($getSize, $enemies, $places),
      array_map(static fn($enemy): EnemyHabitat => $enemy instanceof Enemy ? $enemy->habitat : EnemyHabitat::LAND, $enemies));
    foreach ($enemies as $index => $enemy) { $this->enemySlots[spl_object_id($enemy)] = $staging->enemies[$index]; }
    return $staging;
  }

  /** @return list<string> */
  public function requiredCapabilities(): array
  {
    // Effects may introduce crops and alpha after entry; negotiate them before
    // combat starts, not when the first command reaches its impact.
    return [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::SPRITE_SOURCE_RECT,
      RendererSessionConfig::CANVAS_CLIP_OPACITY,
      ...($this->layout->skin !== null ? [RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  }

  public static function prepare(BattleConfig $battle, BattlePresentationCatalog $catalog, string $assetRoot): ?self
  {
    $arena = $catalog->getArenaFor($battle);
    if ($arena === null) { return null; }
    $layout = ($catalog->ui ?? throw new RuntimeException('A graphical arena requires an explicit shared battle canvas layout.'))->getForArena($arena);
    $arena->background->destination->assertWithin($layout->width, $layout->height);
    $presentation = new self($arena, $layout, $battle, $assetRoot, $catalog->scale);
    $images = PngAssetPreflight::getAvailableSize($assetRoot, $arena->background->asset) === null ? [] : [$arena->background];
    foreach ([[$battle->party->members->toArray(), true],
      [$battle->troop->members->toArray(), false]] as [$members, $party]) {
      foreach ($members as $member) {
        $key = $member instanceof Character ? $member->actorId : $member->name;
        $art = ($party ? $catalog->actors : $catalog->enemies)[$key] ?? null;
        if ($art === null) {
          Debug::warn("No battler artwork registered; using a name fallback: {$key}");
        }
        $probe = $art === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $art->asset);
        $available = $probe !== null;
        $art ??= BattleFormationLayout::getUnavailableArtwork();
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
        $poses = ($party ? $catalog->actorPoses : $catalog->enemyPoses)[$key] ?? null;
        $profile = $catalog->scale?->getProfile($key, $party);
        $presentation->participants[$identity] = ['battler' => $member, 'party' => $party,
          'image' => new CanvasImage('combatant-' . $identity, $art->asset, new CanvasRectangle(0, 0, 1, 1), 100, $art->sourceRect),
          'art' => $art, 'available' => $available, 'poses' => $poses, 'scale' => $profile,
          'grounding' => ($party ? $catalog->actorGrounding : $catalog->enemyGrounding)[$key] ?? null,
          'habitat' => $member instanceof Enemy ? $member->habitat : null];
      }
    }
    if ($layout->partySlots !== [] && count($battle->partyRoster->battlers) > count($layout->partySlots)) {
      throw new RuntimeException('Graphical battle requires a slot for every active party member.');
    }
    $presentation->staging = $presentation->stage();
    foreach ($presentation->participants as $identity => $participant) {
      $available = $participant['available'];
      $art = $participant['art'];
      $member = $participant['battler'];
      // Explicit reserve replacement may place any member in an active slot.
      // Asset preparation must not change the battle's participation policy.
      $slots = $participant['party'] ? $presentation->staging->party : [$presentation->enemySlots[$identity]];
      foreach ($slots as $possibleSlot) {
        $bounds = $presentation->placeBaseArtwork($possibleSlot, $available ? $art : null, $participant['poses'], $identity,
          $participant['scale']);
        $bounds->assertWithin($layout->width, $layout->height);
        if ($available) { $layout->skin?->targetCursor?->layout($bounds, $layout->width, $layout->height); }
        if (min($bounds->width, $bounds->height) < 1) {
          throw new RuntimeException('Battler images must cover at least one graphical unit in each dimension.');
        }
      }
      $slot = $slots[0] ?? null;
      if ($slot === null) { throw new RuntimeException("Graphical battle requires a formation slot for every participant: {$member->name}"); }
      $image = new CanvasImage('combatant-' . $identity, $art->asset,
        $presentation->placeBaseArtwork($slot, $available ? $art : null, $participant['poses'], $identity, $participant['scale']),
        100, $art->sourceRect);
      $presentation->participants[$identity]['image'] = $image;
      if ($available) { $images[] = $image; }
    }
    GraphicalBattleHud::preflight($layout, $assetRoot, $images);
    // Resolve every image before battle-entry effects; no partially valid formation.
    new PresentationCanvas($layout->width, $layout->height, $images);
    if ($layout->battlerArea !== null || $layout->enemyArea !== null || $layout->partyArea !== null) {
      $resting = $presentation->frame();
      $partyImages = $enemyImages = [];
      $partyIndices = array_flip(array_map(spl_object_id(...), $battle->partyRoster->battlers));
      $enemyIndices = array_flip(array_map(spl_object_id(...), $battle->troop->members->toArray()));
      $labels = [];
      foreach ($presentation->participants as $identity => $participant) {
        $image = array_find($resting->images, static fn(CanvasImage $image): bool => $image->id === 'combatant-' . $identity);
        if ($image === null) { continue; }
        if ($participant['party']) { $partyImages[] = $image; } else { $enemyImages[] = $image; }
        $labels[$image->id] = BattleFormationClearance::getBattlerLabel($participant['battler']->name,
          $participant['party'], ($participant['party'] ? $partyIndices : $enemyIndices)[$identity]);
      }
      foreach (BattleFormationClearance::inspect($layout, $partyImages, $enemyImages, $assetRoot, $labels) as $id => $issues) {
        foreach ($issues as $issue) { Debug::warn('Battle formation ' . $labels[$id] . ': ' . $issue); }
      }
    }
    return $presentation;
  }

  /** @param list<CanvasTextLayer> $ui */
  public function frame(?BattlePresentationState $field = null, array $ui = [], ?BattleHudSnapshot $hud = null,
    ?string $focus = null, ?float $now = null, bool $imageFlips = true, bool $compositing = true,
    ?bool $reducedMotion = null): PresentationCanvas
  {
    $now ??= hrtime(true) / 1_000_000_000;
    $reducedMotion ??= Accessibility::prefersReducedMotion();
    try {
      return $this->composeFrame($field, $ui, $hud, $focus, $now, true, $imageFlips, $compositing, $reducedMotion);
    } catch (\InvalidArgumentException|RuntimeException $error) {
      // A failure in the base battlefield remains an error. Only optional pose
      // and effect composition can degrade, without losing combat or feedback.
      $fallback = $this->composeFrame($field, $ui, $hud, $focus, $now, false, $imageFlips, $compositing, $reducedMotion);
      $field?->getCommandPlayback()?->recordPresentationFailure($error);
      if (!isset($this->compositionDiagnostics[$error->getMessage()])) {
        Debug::warn('Optional battle poses/effects unavailable; retaining base battlefield: ' . $error->getMessage());
        $this->compositionDiagnostics[$error->getMessage()] = true;
      }
      return $fallback;
    }
  }

  /** @param list<CanvasTextLayer> $ui */
  private function composeFrame(?BattlePresentationState $field, array $ui, ?BattleHudSnapshot $hud,
    ?string $focus, float $now, bool $includeOptionalArtwork, bool $imageFlips, bool $compositing,
    bool $reducedMotion): PresentationCanvas
  {
    $playback = $field?->getCommandPlayback();
    $stageFrame = $includeOptionalArtwork && $playback !== null && !$playback->isCompleted
      ? $playback->plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion) : null;
    $stageCanvas = $stageFrame === null ? null : CinematicStagePresentation::compose($stageFrame,
      $playback->plan->summon->playbackSegments, $this->assetRoot, $this->layout->width, $this->layout->height,
      $imageFlips, $compositing);
    if ($stageFrame?->active) {
      CanvasImagePreflight::inspect($stageCanvas->images, $this->assetRoot, $stageCanvas->composites);
      return $stageCanvas;
    }
    $images = PngAssetPreflight::getAvailableSize($this->assetRoot, $this->arena->background->asset) === null
      ? [] : [$this->arena->background];
    $indicators = $text = $composites = $feedbackParticipants = [];
    $cursorBounds = [];
    $feedback = $field?->getFeedback() ?? [];
    $selected = $field?->getSelectedBattlers() ?? [];
    $skin = $this->layout->skin;
    $composition = $skin !== null && $hud !== null
      ? GraphicalBattleHud::compose($this->layout, $hud, $focus, $now, $this->assetRoot) : null;
    $hudBounds = array_map(static fn(CanvasImage $image) => $image->destination, $composition?->images ?? []);
    $focused = $field?->getFocusedBattlers() ?? [];
    $queued = $field?->getQueuedBattlers() ?? [];
    $frontline = $this->battle->partyRoster->battlers;
    $enemies = $this->battle->troop->members->toArray();
    $combatantBounds = [];
    $combatantImages = [];
    $conditionBattlers = [];
    $groundAnchors = [];
    $partySides = [];
    $protectedCombatants = [];
    foreach ($this->getDrawOrder($frontline, $enemies) as $identity => $participant) {
      $battler = $participant['battler'];
      $image = $participant['image'];
      $index = array_search($battler, $participant['party'] ? $frontline : $enemies, true);
      if ($index === false) { continue; }
      $size = PngAssetPreflight::getAvailableSize($this->assetRoot, $image->asset);
      $participant['available'] = $size !== null;
      $art = $size === null ? $participant['art'] : $participant['art']->clampedTo($size['width'], $size['height']);
      $slot = $this->getSlot($battler);
      $bounds = $this->placeBaseArtwork($slot, $participant['available'] ? $art : null,
        $participant['poses'], $identity, $participant['scale']);
      $artworkScale = $participant['available'] ? $bounds->width / $art->width / $slot->displayScale : null;
      $role = $playback?->getPoseRole($battler) ?? BattlePoseRole::getRestingRole($battler);
      $pose = $includeOptionalArtwork ? $participant['poses']?->getPose($role) : null;
      $usesKnockoutPose = false;
      $usesConcedePose = false;
      if ($includeOptionalArtwork && $pose === null && $role !== BattlePoseRole::IDLE) {
        $diagnostic = $identity . ':' . $role->value . ':unregistered';
        if (!isset($this->poseDiagnostics[$diagnostic])) {
          $actorId = $battler instanceof Character ? $battler->actorId : $battler->name;
          Debug::warn("No battle pose registered for {$actorId}: {$role->value}; retaining available resting artwork.");
          $this->poseDiagnostics[$diagnostic] = true;
        }
      }
      $candidates = $includeOptionalArtwork ? array_unique([$role->value,
        BattlePoseRole::getRestingRole($battler)->value, BattlePoseRole::IDLE->value]) : [];
      foreach ($candidates as $candidateRole) {
        $pose = $participant['poses']?->getPose(BattlePoseRole::from($candidateRole));
        if ($pose === null) { continue; }
        try {
          $posed = $pose->getArtwork($this->assetRoot,
            $playback !== null && $candidateRole === $role->value && $role !== BattlePoseRole::IDLE
              ? $playback->getPoseElapsedSeconds($battler) : ($field?->getPoseElapsedSeconds() ?? 0), $reducedMotion);
          if ($posed === null) { throw new RuntimeException('Pose image is unavailable.'); }
          $posedBounds = BattleFormationLayout::getPosePlacement($slot, $posed, $artworkScale,
            $this->layout, $participant['scale'], $this->scale, $pose->scaleSpan, $participant['poses']);
          $art = $posed;
          $bounds = $posedBounds;
          $participant['available'] = true;
          $usesKnockoutPose = $candidateRole === BattlePoseRole::KNOCKOUT->value;
          $usesConcedePose = $candidateRole === BattlePoseRole::CONCEDE->value;
          break;
        } catch (\InvalidArgumentException|RuntimeException $error) {
          $diagnostic = $identity . ':' . $candidateRole . ':' . $pose->asset;
          if (!isset($this->poseDiagnostics[$diagnostic])) {
            Debug::warn('Battle pose unavailable; retaining available resting artwork: ' . $diagnostic . ': ' . $error->getMessage());
            $this->poseDiagnostics[$diagnostic] = true;
          }
        }
      }
      // The body rises or sinks by its elevation, hidden below the surface line; a knocked-out one lies on the ground.
      $elevation = BattlerGroundContact::getElevation($participant['grounding'], $participant['habitat'], $this->layout,
        $battler->isKnockedOut);
      $contact = BattlerGroundContact::getContact($participant['grounding'], $participant['habitat'], $elevation);
      [$bounds, $clip] = BattlerGroundContact::place($bounds, BattlerGroundContact::getOffset($elevation, $slot, $this->scale),
        $slot->y, $this->layout);
      $advance = !$reducedMotion && $playback?->actor === $battler ? $playback->getAdvanceFraction() : 0;
      $recoil = ($playback?->getRecoilFraction($battler, $reducedMotion) ?? 0)
        + ($playback?->getShakeFraction($battler, $reducedMotion) ?? 0);
      $unmovedX = $bounds->x;
      $unmovedY = $bounds->y;
      $bounds = new CanvasRectangle(clamp($bounds->x + $slot->width * $recoil
        + BattleEffectDirection::getOpponentsSign($participant['party']) * $slot->width * .25 * $advance,
        0, max(0, $this->layout->width - $bounds->width)),
        clamp($bounds->y, 0, max(0, $this->layout->height - $bounds->height)), $bounds->width, $bounds->height);
      $groundAnchors[$identity] = new Vector2($slot->x + ($bounds->x - $unmovedX), $slot->y + ($bounds->y - $unmovedY));
      $partySides[$identity] = $participant['party'];
      $image = new CanvasImage($image->id, $art->asset, $bounds, $image->layer, $art->sourceRect,
        clipRect: $clip === null ? null : new CanvasRectangle($bounds->x, $clip->y, $bounds->width, $clip->height));
      $combatantBounds[$identity] = $bounds;
      $popups = array_filter($feedback, static fn(array $popup) => $popup['battler'] === $battler);
      $defeat = $playback?->getEnemyDefeatTreatment($battler, $reducedMotion);
      if (!$participant['party'] && $battler->isKnockedOut
        && ($defeat !== null ? !$defeat['visible'] : $popups === [])) { continue; }
      $conditionBattlers[$identity] = $battler;
      if ($participant['available']) {
        $images[] = $combatantImages[$identity] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
          $image->sourceRect, clipRect: $image->clipRect, opacity: $defeat['opacity'] ?? match (true) {
            $participant['party'] && $battler->isKnockedOut && !$usesKnockoutPose => 0.4,
            // Without a concede pose, the enemy's current artwork is held, subdued.
            !$participant['party'] && ($battler->hasConceded ?? false) && !$usesConcedePose => $this->defeatStyle->concededOpacity,
            default => 1,
          });
        // Its contact follows its feet through steps, recoil and poses, and fades with it. Engine-drawn
        // contacts are composites; a ripple or burrow sheet is art and needs none.
        $profile = $participant['scale'];
        $surface = BattlerGroundContact::compose('contact-' . $identity, $groundAnchors[$identity]->x, $groundAnchors[$identity]->y,
          $profile !== null && $this->scale !== null ? $this->scale->referenceHeight * $profile->relativeSize * $slot->displayScale
            : $bounds->height, ($profile?->horizontal ?? false) || $usesKnockoutPose, $bounds->width, $elevation, $contact,
          $this->layout, $this->arena, $participant['grounding'], $this->assetRoot, $field?->getPoseElapsedSeconds() ?? 0,
          $reducedMotion, $combatantImages[$identity]->opacity);
        array_push($images, ...$surface['images']);
        if ($compositing) { array_push($composites, ...$surface['composites']); }
        if (($defeat['pulse'] ?? false) && $compositing && $includeOptionalArtwork) {
          try {
            $composites[] = \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint::compose(
              'enemy-defeat-' . $identity, $combatantImages[$identity], $defeat['color'], .6, $this->assetRoot);
          } catch (\Throwable $error) { $playback?->recordPresentationFailure($error); }
        } elseif (($defeat['pulse'] ?? false) && !$compositing) {
          $playback?->recordPresentationFailure(new RuntimeException('Renderer cannot tint enemy defeat pulses; fade and clear remain.'));
        }
        $protectedCombatants[] = $bounds;
      }
      $bounds = $image->destination;
      $lines = $participant['available'] ? [] : [['text' => $battler->name, 'color' => null]];
      if (in_array($battler, $selected, true)) {
        if ($skin === null && $participant['available']) {
          $indicators[] = new CanvasIndicator('selected-' . $identity, $image->id, CanvasIndicatorKind::OUTLINE,
            $bounds, (int)min(2, $bounds->width, $bounds->height), PresentationColor::ansi16(14), 200);
        } elseif ($skin !== null && $participant['available']) {
          $hasSelectionArt = false;
          if ($skin->targetCursor !== null) {
            $cursor = $skin->targetCursor->layout($bounds, $this->layout->width, $this->layout->height, $now,
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
              new CanvasRectangle(max(0, min($this->layout->width - 20, $bounds->x - 24)) + $offset,
                max(0, $bounds->y + ($bounds->height - 16) / 2), 16, 16), 202));
          }
        }
        if (!$participant['available']) {
          $lines[0]['color'] = $skin?->colors['focus'] ?? PresentationColor::ansi16(14);
          if (in_array($battler, $queued, true)) { $lines[] = ['text' => 'Queued', 'color' => $skin?->colors['selected']]; }
        }
        if ($participant['available']) { $lines[] = ['text' => $battler->name, 'color' => null]; }
      }
      if ($skin !== null) {
        $feedbackParticipants[] = ['id' => $identity, 'bounds' => $bounds, 'persistent' => $lines, 'popups' => $popups];
        continue;
      }
      foreach ($popups as $popup) {
        foreach (GraphicalBattleFeedback::getVisibleLines($popup['lines']) as $line) {
          $color = SgrColorParser::parse($line['color']->value . 'x')['foreground'];
          $lines[] = ['text' => $line['text'], 'color' => $color];
        }
      }
      if ($lines !== []) { $text[] = $this->labels('feedback-' . $identity, $lines, $bounds, $ui); }
    }
    $effects = null;
    $conditionAreas = [];
    foreach ($conditionBattlers as $battler) {
      $condition = $this->conditionEffects->createPlayback($battler, $field?->getPoseElapsedSeconds() ?? 0,
        $includeOptionalArtwork);
      if ($condition === null) { continue; }
      $ambient = GraphicalBattleEffects::compose($condition, $this->layout, $combatantBounds, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides);
      array_push($images, ...$ambient->images);
      array_push($text, ...$ambient->textLayers);
      array_push($composites, ...$ambient->composites);
      array_push($conditionAreas, ...$ambient->getOverlayProtection());
    }
    if ($playback !== null && $includeOptionalArtwork) {
      $effects = GraphicalBattleEffects::compose($playback, $this->layout, $combatantBounds, $this->assetRoot,
        $reducedMotion, $combatantImages, $imageFlips, $compositing, $groundAnchors, $partySides);
      array_push($images, ...$effects->images);
      array_push($text, ...$effects->textLayers);
      array_push($composites, ...$effects->composites);
    }
    if ($composition !== null) {
      array_push($images, ...$composition->images);
      array_push($text, ...$composition->textLayers);
      array_push($composites, ...$composition->composites);
    }
    $badges = GraphicalBattleConditions::compose($this->layout, $conditionBattlers, $combatantBounds, $this->assetRoot, $this->headAttachments);
    array_push($images, ...$badges->images);
    array_push($text, ...$badges->textLayers);
    array_push($conditionAreas, ...$badges->getOverlayProtection());
    if ($skin !== null) {
      array_push($text, ...GraphicalBattleFeedback::compose($this->layout, $feedbackParticipants, $ui, $now,
        [...$hudBounds, ...$cursorBounds, ...$badges->getOverlayProtection()]));
    }
    // Protect participants, effects and HUD content, not the decorative arena behind them.
    $areas = [...$protectedCombatants, ...$cursorBounds, ...$hudBounds, ...$conditionAreas,
      ...array_map(static fn(CanvasTextLayer $layer) => $layer->clipRect ?? $layer->paintBounds, [...$text, ...$ui]),
      ...array_map(static fn(CanvasIndicator $indicator) => $indicator->bounds, $indicators),
      ...($effects?->getOverlayProtection() ?? [])];
    $canvas = new PresentationCanvas($this->layout->width, $this->layout->height,
      [...$images, ...($stageCanvas?->images ?? [])], $indicators, CanvasTextBatch::compact([...$text, ...$ui]),
      composites: [...$composites, ...($stageCanvas?->composites ?? [])],
      protectedAreas: [...$areas, ...($stageCanvas?->getOverlayProtection() ?? [])]);
    CanvasImagePreflight::inspect($canvas->images, $this->assetRoot, $canvas->composites);
    return $canvas;
  }

  /**
   * Standing participants from back to front, so a nearer battler is drawn
   * over one behind it; equal depths keep party before troop, then member order.
   *
   * @return array<int, array> Identity => participant.
   */
  private function getDrawOrder(array $frontline, array $enemies): array
  {
    $standing = array_filter($this->participants, static fn(array $participant): bool =>
      in_array($participant['battler'], $participant['party'] ? $frontline : $enemies, true));
    $depths = array_map(fn(array $participant): float => $this->getSlot($participant['battler'])->y, $standing);
    uksort($standing, static fn(int $one, int $two): int => $depths[$one] <=> $depths[$two]);
    return $standing;
  }

  /** @param list<array{text: string, color: ?PresentationColor}> $lines @param list<CanvasTextLayer> $ui */
  private function labels(string $id, array $lines, CanvasRectangle $bounds, array $ui): CanvasTextLayer
  {
    $cellWidth = $this->layout->uiGrid->cellWidth;
    $cellHeight = $this->layout->uiGrid->cellHeight;
    $runs = [];
    $columns = 1;
    foreach ($lines as $row => $line) {
      $text = mb_substr(TerminalText::stripAnsi($line['text']), 0, min(128, intdiv($this->layout->width, $cellWidth)), 'UTF-8');
      $columns = max($columns, mb_strlen($text, 'UTF-8'));
      $runs[] = new PresentationTextRun($row, 0, $text, $line['color'], PresentationColor::rgb(15, 23, 30));
    }
    $grid = new RendererGridConfig($columns, count($runs), $cellWidth, $cellHeight);
    $placement = BattleFeedbackPlacement::place($bounds, $columns * $cellWidth, count($runs) * $cellHeight,
      $this->layout->width, $this->layout->height, $ui);
    $placement->assertWithin($this->layout->width, $this->layout->height);
    return new CanvasTextLayer($id, 300, $placement->x, $placement->y, $grid, $runs);
  }
}
