<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;

require_once __DIR__ . '/../../Support/Battle/QueuedCommandFixture.php';

/** Read-only assets produced by writeQueuedAttackEffects(root, 8) plus Arena/Idle/Attack/Damage PNGs. */
return static function (string $root, bool $reducedMotion, ?array $subjects = null): array {
    $subjects ??= ['traditional', 'atb'];
    if ($subjects === [] || count(array_unique($subjects)) !== count($subjects)
        || array_diff($subjects, ['traditional', 'atb']) !== []) {
        throw new InvalidArgumentException('Command preview subjects are traditional and/or atb.');
    }
    $root = realpath($root);
    if ($root === false || basename($root) !== 'assets' || !is_file($root . '/Data/animations.php')) {
        throw new InvalidArgumentException('Queued command preview requires an isolated project assets directory.');
    }
    $priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
    $priorCatalog = [];
    foreach (['battleAnimations', 'battleSummons', 'missingAnimationIds'] as $name) {
        $priorCatalog[$name] = new ReflectionProperty(BattleCommandCatalog::class, $name)->getValue();
    }
    $settings = new PlaySettings(['audio' => ['music' => false, 'sfx' => false, 'voice' => false, 'master_volume' => 0],
        'accessibility' => ['reducedMotion' => $reducedMotion], 'reducedMotion' => $reducedMotion]);
    $withProject = static function (callable $operation) use ($root, $settings) {
        $cwd = getcwd();
        $configs = [];
        foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
            $configs[$class] = ConfigStore::has($class) ? ConfigStore::get($class) : null;
            ConfigStore::put($class, $settings);
        }
        try { chdir(dirname($root)); return $operation(); }
        finally {
            chdir($cwd);
            foreach ($configs as $class => $config) {
                $config === null ? ConfigStore::remove($class) : ConfigStore::put($class, $config);
            }
        }
    };
    // No backend is constructed: even a mistaken sound request cannot spawn a player.
    $audio = new class extends AudioManager {
        public array $requests = [];
        public function __construct() {}
        public function playSystemSound(SystemSound $sound): void { $this->requests[] = $sound->value; }
        public function playSoundEffect(string $path): void { $this->requests[] = $path; }
    };
    $random = new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return $maximum; }
    };
    $fixture = $presentation = $playback = $action = $turn = null;
    $subject = $command = 0;
    $completed = $views = $posesSeen = $damageSeen = $positions = $crops = [];
    $last = 0.0;
    $disposed = false;
    $start = static function () use (&$fixture, &$presentation, &$playback, &$action, &$turn,
        &$subject, &$command, $subjects, $root, $audio, $random): void {
        if ($command === 0) {
            $fixture = createTargetExecutionFixture($subjects[$subject] === 'atb', true, $audio,
                [new BattlerSlot(450, 500, 192, 192), new BattlerSlot(550, 650, 192, 192)]);
            [$engine, $context, , , $ally] = $fixture;
            $ally->stats->currentHp = 100;
            new ReflectionProperty($context, 'effectTimelines')->setValue($context, new EffectTimelineLibrary($root));
            BattleCommandCatalog::beginBattle();
            $poses = new BattlePoseSet([
                'idle' => new BattlerPose('Idle.png', pivotY: .90625),
                'attack' => new BattlerPose('Attack.png', pivotY: .90625),
                'damage' => new BattlerPose('Damage.png', pivotY: .90625),
            ], displayWidth: 192);
            $art = BattlerArtwork::getFromPng($root, 'Idle.png', .5, .90625);
            $arena = new BattleArenaDefinition('Preview scene',
                new CanvasImage('arena', 'Arena.png', new CanvasRectangle(0, 0, 1350, 720)));
            $catalog = new BattlePresentationCatalog(['arena.preview' => $arena],
                ['Caster' => $art, 'Recipient' => $art], ['Enemy A' => $art, 'Enemy B' => $art],
                ui: new BattleCanvasLayout(1350, 720, partySlots:
                    [new BattlerSlot(950, 500, 192, 192), new BattlerSlot(1100, 650, 192, 192)]),
                defaultArena: 'arena.preview', actorPoses: ['Caster' => $poses, 'Recipient' => $poses],
                enemyPoses: ['Enemy A' => $poses, 'Enemy B' => $poses]);
            $presentation = GraphicalBattlePresentation::prepare($engine->battleConfig, $catalog, $root);
        }
        [, , $screen, $hero, , $enemies] = $fixture;
        $action = new SkillBattleAction(new BasicSkill($command === 0 ? 'Two strokes' : 'Impact', '', '', 4, 0,
            invocation: new SkillInvocation(repeat: $command === 0 ? 2 : 1),
            effects: [new HPDamageSkillEffect('10', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE)],
            animationId: $command === 0 ? 3 : null), random: $random);
        $turn = queueTargetExecution($fixture, $action, [$command === 0 ? $enemies[0] : $hero],
            $command === 0 ? $hero : $enemies[0]);
        $playback = $screen->fieldWindow->getCommandPlayback();
        if ($playback === null) { throw new RuntimeException('Queued command did not enter production playback.'); }
        $screen->observed = [];
    };
    $frame = static function (float $seconds) use ($withProject, $start, $subjects, $reducedMotion,
        &$fixture, &$presentation, &$playback, &$action, &$turn, &$subject, &$command, &$completed,
        &$views, &$posesSeen, &$damageSeen, &$positions, &$crops, &$last, &$disposed) {
        if ($disposed || !is_finite($seconds) || $seconds < $last) { throw new RuntimeException('Invalid command preview clock/lifecycle.'); }
        return $withProject(static function () use ($seconds, $start, $subjects, $reducedMotion,
            &$fixture, &$presentation, &$playback, &$action, &$turn, &$subject, &$command, &$completed,
            &$views, &$posesSeen, &$damageSeen, &$positions, &$crops, &$last) {
            $delta = $seconds - $last;
            $last = $seconds;
            if ($subject < count($subjects) && ($fixture === null || $playback === null)) { $start(); $delta = 0; }
            [$engine, $context, $screen, $hero, , $enemies] = $fixture;
            $key = $subject < count($subjects) ? $subjects[$subject] . ':' . $command : 'complete';
            if ($playback !== null) {
                new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $delta);
                $engine->state->update($context);
            }
            $screen->fieldWindow->advancePoseTime($delta);
            $label = 'Silent queued combat | ' . $key . ' | ' . ($playback?->phase ?? 'complete')
                . ' | Party HP ' . $hero->stats->currentHp . ' | Enemy HP ' . $enemies[0]->stats->currentHp;
            $canvas = $presentation->frame($screen->fieldWindow, [new CanvasTextLayer('preview-phase', 250, 20, 20,
                new RendererGridConfig(130, 1, 10, 20), [new PresentationTextRun(0, 0, $label)])]);
            if ($playback !== null) {
                foreach ($canvas->images as $image) {
                    if ($image->id === 'combatant-' . spl_object_id($playback->actor)) {
                        $positions[$key]['first'] ??= $image->destination;
                        $positions[$key]['final'] = $image->destination;
                        $positions[$key]['moved'] = ($positions[$key]['moved'] ?? false)
                            || $image->destination->x !== $positions[$key]['first']->x;
                        $posesSeen[$key][$image->asset] = true;
                    }
                    if ($image->id === 'combatant-' . spl_object_id($playback->targets[0]) && $image->asset === 'Damage.png') {
                        $damageSeen[$key] = true;
                    }
                    if (str_starts_with($image->id, 'command-effect-')) {
                        $views[$key][$image->id] = [$image->asset, $image->flipX, $image->sourceRect?->x];
                        $crops[$key][$image->asset][$image->sourceRect?->x ?? 0] = true;
                    }
                }
                if ($playback->isCompleted) {
                    $expectedHp = $command === 0 ? 80 : 70;
                    $recipient = $command === 0 ? $enemies[0] : $hero;
                    if (!$turn->isCompleted || $recipient->stats->currentHp !== $expectedHp
                        || $playback->actor->stats->currentMp !== 46
                        || $action->lastResult?->hitCount() !== ($command === 0 ? 2 : 1)
                        || $screen->fieldWindow->getCommandPlayback() !== null || $screen->fieldWindow->getFeedback() !== []
                        || $screen->announcement !== null || $playback->presentationFailure !== null) {
                        throw new RuntimeException('Queued combat outcome or owned cleanup did not verify.');
                    }
                    $completed[$key] = ['phases' => array_values(array_unique(array_column($screen->observed, 0))),
                        'hits' => $action->lastResult->hitCount(), 'hp' => $recipient->stats->currentHp,
                        'mp' => $playback->actor->stats->currentMp];
                    $playback = null;
                    $command++;
                    if ($command === 2) { $engine->stop(); $subject++; $command = 0; }
                }
            }
            return $canvas;
        });
    };
    $dispose = static function () use (&$disposed, &$fixture, $priorDelta, $priorCatalog): void {
        if ($disposed) { return; }
        $disposed = true;
        try { ($fixture[0] ?? null)?->stop(); }
        finally {
            new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $priorDelta);
            foreach ($priorCatalog as $name => $value) {
                new ReflectionProperty(BattleCommandCatalog::class, $name)->setValue(null, $value);
            }
        }
    };
    return ['subjects' => $subjects, 'frame' => $frame, 'dispose' => $dispose,
        'requiredCapabilities' => [RendererSessionConfig::CANVAS_IMAGE_FLIP],
        'verify' => static function () use ($subjects, $reducedMotion, &$completed, &$views, &$posesSeen,
            &$damageSeen, &$positions, &$crops): array {
            if (count($completed) !== count($subjects) * 2) { throw new RuntimeException('Queued command preview ended before every battle engine finished.'); }
            foreach ($completed as $key => $result) {
                $double = str_ends_with($key, ':0');
                if ($result['phases'] !== ['advance', 'announce', 'source', 'target', 'reaction', 'return', 'finish']
                    || $positions[$key]['first'] != $positions[$key]['final'] || $positions[$key]['moved'] === $reducedMotion
                    || !isset($posesSeen[$key]['Attack.png'], $damageSeen[$key])
                    || count($views[$key] ?? []) !== ($double && !$reducedMotion ? 3 : 2)) {
                    throw new RuntimeException('Queued poses, effects, phase order or formation return did not verify.');
                }
                $target = array_values(array_filter($views[$key], static fn(array $view): bool => $view[0] !== 'windup.png'));
                if (array_unique(array_column($target, 0)) !== [$double ? 'double.png' : 'impact.png']
                    || count($crops[$key]['windup.png'] ?? []) !== ($reducedMotion ? 1 : 8)
                    || count($crops[$key][$double ? 'double.png' : 'impact.png'] ?? []) !== ($reducedMotion ? 1 : 8)
                    || array_column($target, 1) !== ($double ? ($reducedMotion ? [true] : [true, false]) : [false])) {
                    throw new RuntimeException('Directional double strokes or neutral enemy impact did not verify.');
                }
            }
            return ['commands' => $completed, 'sourceAndTargetCrops' => $reducedMotion ? 1 : 8,
                'returnedToFormation' => true, 'damageReactionsObserved' => true, 'isolatedSilentAudio' => true,
                'normalSettingsAndSavesUntouched' => true];
        }];
};
