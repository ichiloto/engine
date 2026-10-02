<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

return static function (string $root, bool $reducedMotion): array {
    ConfigStore::put(PlaySettings::class, new PlaySettings(['reducedMotion' => $reducedMotion]));
    if (Accessibility::prefersReducedMotion() !== $reducedMotion) { throw new RuntimeException('Preview motion policy not applied.'); }
    $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100));
    $recipient = new Character('Recipient', 1, new Stats(currentHp: 100, totalHp: 100));
    $party = new Party();
    $party->addMember($hero);
    $party->addMember($recipient);
    $arena = new BattleArenaDefinition('Preview scene',
        new CanvasImage('arena', 'Arena.png', new CanvasRectangle(0, 0, 1350, 720)));
    $poses = new BattlePoseSet([
        'idle' => new BattlerPose('Idle.png', pivotY: .90625),
        'attack' => new BattlerPose('Attack.png', pivotY: .90625),
        'damage' => new BattlerPose('Damage.png', pivotY: .90625),
    ], displayWidth: 192);
    $base = BattlerArtwork::getFromPng($root, 'Idle.png', .5, .90625);
    $catalog = new BattlePresentationCatalog(['arena.preview' => $arena], ['Hero' => $base, 'Recipient' => $base], [],
        ui: new BattleCanvasLayout(1350, 720, partySlots: [new BattlerSlot(950, 500, 192, 192), new BattlerSlot(450, 500, 192, 192)]),
        defaultArena: 'arena.preview',
        actorPoses: ['Hero' => $poses, 'Recipient' => $poses]);
    $presentation = GraphicalBattlePresentation::prepare(new BattleConfig($party, new Troop('Preview')), $catalog, $root);
    $field = new class extends BattleFieldWindow {
        public function __construct() {}
        public function getSelectedBattlers(): array { return []; }
        public function getFocusedBattlers(): array { return []; }
        public function getQueuedBattlers(): array { return []; }
    };
    $library = new EffectTimelineLibrary($root);
    $effect = static fn(string $id, string $anchor, string $depth) => $library->compile($id, [
        'fps' => 8, 'lengthFrames' => 8, 'restFrame' => 0,
        'tracks' => [['id' => $id, 'type' => 'image', 'asset' => 'Effect.png', 'anchor' => $anchor,
            'depth' => $depth, 'sheet' => ['columns' => 8, 'rows' => 1], 'cells' => ['width' => 2, 'height' => 2],
            'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame], range(0, 7))]],
        ...($anchor === 'target' ? ['cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 4]],
            'effectTiming' => ['mode' => 'cue', 'cueId' => 'hit']] : []),
    ], true);
    $phases = [];
    $resolutions = 0;
    $playback = null;
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.6, .8, 0, 0, .6, .8, .3),
        $effect('source', 'caster', 'behind'), $effect('target', 'target', 'front')), $hero, [$recipient], BattlePoseRole::ATTACK,
        static function () use (&$resolutions, &$playback, $recipient): void {
            $resolutions++;
            $recipient->stats->currentHp -= 25;
            $playback->setReaction($recipient, BattlePoseRole::DAMAGE);
        }, static function (array $cue) use (&$phases): void {
            if ($cue['type'] === 'commandPhase') { $phases[] = $cue['payload']['phase']; }
        });
    $field->setCommandPlayback($playback);
    $playback->begin();
    $last = 0.0;
    $crops = $effects = [];
    $moved = false;
    $first = null;
    $final = null;
    $frame = static function (float $seconds) use ($presentation, $field, $playback, $hero, $recipient,
        &$last, &$crops, &$effects, &$moved, &$first, &$final): \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas {
        $playback->update(max(0, $seconds - $last));
        $field->advancePoseTime(max(0, $seconds - $last));
        $last = $seconds;
        $label = 'Silent G2 fixture | ' . $playback->phase . ' | Recipient HP ' . $recipient->stats->currentHp;
        $canvas = $presentation->frame($field, [new CanvasTextLayer('preview-phase', 250, 20, 20,
            new RendererGridConfig(125, 1, 10, 20), [new PresentationTextRun(0, 0, $label)])]);
        foreach ($canvas->images as $image) {
            if ($image->id === 'combatant-' . spl_object_id($hero)) {
                $first ??= $image->destination;
                $final = $image->destination;
                $moved = $moved || $final->x !== $first->x;
            }
            if (str_starts_with($image->id, 'command-effect-')) {
                $effects[$image->id] = true;
                $crops[$image->sourceRect?->x ?? 0] = true;
            }
        }
        return $canvas;
    };
    return ['frame' => $frame, 'verify' => static function () use ($playback, $recipient, $reducedMotion,
        &$resolutions, &$phases, &$crops, &$effects, &$moved, &$first, &$final): array {
        if (!$playback->isCompleted || $resolutions !== 1 || $recipient->stats->currentHp !== 75
            || $phases !== ['advance', 'announce', 'source', 'target', 'reaction', 'return', 'finish']
            || $first != $final || $moved === $reducedMotion || count($effects) !== 2
            || count($crops) !== ($reducedMotion ? 1 : 8) || $playback->presentationFailure !== null) {
            throw new RuntimeException('Battle preview sequence, movement, effects or exactly-once outcome did not verify.');
        }
        return ['phases' => $phases, 'resolutions' => $resolutions, 'recipientHp' => $recipient->stats->currentHp,
            'sourceAndTargetEffects' => count($effects), 'sourceCrops' => count($crops),
            'returnedToFormation' => true, 'motionObserved' => $moved];
    }];
};
