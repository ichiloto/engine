<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\WorldObject;
use Ichiloto\Engine\Scenes\Game\GameScene;
use InvalidArgumentException;
use RuntimeException;

/** Explicit speaker bindings and turn-taking for field dialogue. */
final class CinematicConversation
{
    public const array SUBJECT_KINDS = ['player', 'npc', 'staged_actor'];
    private ?string $previousSpeaker = null;
    private string $mapId;
    private ?string $activeGroup = null;

    public function __construct(private readonly GameScene $scene, private readonly array $definition)
    {
        self::validate($definition);
        $this->mapId = $scene->currentMapId;
        $this->activeGroup = $definition['initialGroup'] ?? null;
    }

    public static function validate(array $definition): void
    {
        if (array_diff(array_keys($definition), ['speakers', 'addressees', 'facing', 'groups', 'initialGroup']) !== []) {
            throw new InvalidArgumentException('Conversation accepts speakers, addressees, facing, groups and initialGroup bindings.');
        }
        $speakers = $definition['speakers'] ?? null;
        if (!is_array($speakers) || $speakers === [] || array_is_list($speakers)) {
            throw new InvalidArgumentException('Conversation speakers must bind display names to field subjects.');
        }
        $subjects = [];
        foreach ($speakers as $name => $reference) {
            if (!is_string($name) || trim($name) === '' || trim($name) !== $name || !is_array($reference)
                || array_diff(array_keys($reference), ['kind', 'id']) !== []
                || (array_key_exists('id', $reference) && !is_string($reference['id']))
                || !in_array($reference['kind'] ?? null, self::SUBJECT_KINDS, true)) {
                throw new InvalidArgumentException('Choose a player, NPC or staged actor for each named conversation speaker.');
            }
            CinematicSubjectResolver::validateReference($reference);
            $identity = $reference['kind'] . ':' . ($reference['id'] ?? '');
            if (isset($subjects[$identity])) { throw new InvalidArgumentException('A conversation subject has one speaker binding.'); }
            $subjects[$identity] = true;
        }
        foreach (['addressees', 'facing'] as $field) {
            if (!is_array($definition[$field] ?? [])) { throw new InvalidArgumentException("Conversation $field must be keyed by speaker."); }
            foreach ($definition[$field] ?? [] as $name => $value) {
                if (!isset($speakers[$name])) { throw new InvalidArgumentException("Conversation $field names an unbound speaker."); }
                if ($field === 'addressees') { self::validateAddressee($value, $speakers, $name); }
                elseif (!is_array($value) || array_diff(array_keys($value), CinematicFacing::FIELDS) !== []
                    || !CinematicFacing::isDeclared($value)) {
                    throw new InvalidArgumentException('A conversation facing override declares facing or faceToward.');
                } else { CinematicFacing::validate($value); }
            }
        }
        if (array_key_exists('groups', $definition)) {
            $groups = $definition['groups'];
            if (!is_array($groups) || $groups === [] || array_is_list($groups)) {
                throw new InvalidArgumentException('Conversation groups must be keyed by stable group ids.');
            }
            foreach ($groups as $id => $group) {
                if (!is_string($id) || trim($id) === '' || !is_array($group)
                    || array_diff(array_keys($group), ['speakers', 'addressees', 'facing']) !== []
                    || !is_array($group['speakers'] ?? null) || !array_is_list($group['speakers'])
                    || array_any($group['speakers'], static fn(mixed $key): bool => !is_string($key) || !isset($speakers[$key]))
                    || count(array_unique($group['speakers'])) !== count($group['speakers'])) {
                    throw new InvalidArgumentException('A conversation group selects distinct bound speaker keys.');
                }
                $subset = array_intersect_key($speakers, array_flip($group['speakers']));
                if ($subset === []) {
                    if (($group['addressees'] ?? []) !== [] || ($group['facing'] ?? []) !== []) {
                        throw new InvalidArgumentException('An empty conversation group has no addressee or facing overrides.');
                    }
                } else {
                    self::validate(['speakers' => $subset, 'addressees' => $group['addressees'] ?? [], 'facing' => $group['facing'] ?? []]);
                }
            }
            if (!is_string($definition['initialGroup'] ?? null) || !isset($groups[$definition['initialGroup']])) {
                throw new InvalidArgumentException('Grouped conversations select an authored initialGroup.');
            }
        } elseif (array_key_exists('initialGroup', $definition)) {
            throw new InvalidArgumentException('initialGroup requires declared conversation groups.');
        }
    }

    /** Text and dialogue pages share these optional stable speaker selections and direction overrides. */
    public static function validateLine(array $line): void
    {
        foreach (['speaker', 'addressee'] as $field) {
            if (array_key_exists($field, $line) && (!is_string($line[$field]) || trim($line[$field]) === '')) {
                throw new InvalidArgumentException("Dialogue $field must select a bound conversation speaker.");
            }
        }
        CinematicFacing::validate($line);
    }

    private static function validateAddressee(mixed $value, array $speakers, string $speaker): void
    {
        if (!is_string($value) || !isset($speakers[$value]) || $value === $speaker) {
            throw new InvalidArgumentException('A conversation addressee selects another bound speaker.');
        }
    }

    public function prepareLine(string $name, array $line = []): void
    {
        self::validateLine($line);
        $group = $this->activeGroup === null ? $this->definition : $this->definition['groups'][$this->activeGroup];
        $bindings = $this->activeGroup === null ? $this->definition['speakers']
            : array_intersect_key($this->definition['speakers'], array_flip($group['speakers']));
        $name = $line['speaker'] ?? $name;
        if (!isset($bindings[$name])) {
            if (isset($line['speaker']) || isset($line['addressee']) || CinematicFacing::isDeclared($line)) {
                throw new RuntimeException('A dialogue facing override requires a bound conversation speaker.');
            }
            return; // Narration and deliberately off-field speakers leave field headings alone.
        }
        if ($this->mapId !== $this->scene->currentMapId) {
            $this->previousSpeaker = null;
            $this->mapId = $this->scene->currentMapId;
        }
        $others = array_values(array_diff(array_keys($bindings), [$name]));
        $addressee = $line['addressee'] ?? $group['addressees'][$name]
            ?? ($this->previousSpeaker !== $name ? $this->previousSpeaker : null)
            ?? (count($others) === 1 ? $others[0] : null);
        if ($addressee !== null) { self::validateAddressee($addressee, $bindings, $name); }

        // Resolve all participants and headings before any transform changes.
        $subjects = $seen = $headings = [];
        foreach ($bindings as $speaker => $reference) {
            $subject = $this->resolveSubject($reference);
            $real = $subject instanceof StagedActor ? ($subject->subject?->subject ?? $subject) : $subject;
            if (isset($seen[spl_object_id($real)])) {
                throw new RuntimeException('Conversation bindings refer to the same real subject twice.');
            }
            $seen[spl_object_id($real)] = true;
            $subjects[$speaker] = $subject;
        }
        foreach ($subjects as $speaker => $subject) {
            $override = $speaker === $name && CinematicFacing::isDeclared($line)
                ? $line : ($group['facing'][$speaker] ?? []);
            if (CinematicFacing::isDeclared($override)) {
                if ($this->isMovingSubject($subject)) {
                    throw new RuntimeException('A dialogue facing override must wait for its subject movement to finish.');
                }
                $headings[$speaker] = CinematicFacing::resolve($this->scene, $override, $subject->position);
                continue;
            }
            if ($this->hasFixedFacing($subject)) { continue; }
            $target = $speaker === $name ? $addressee : $name;
            if ($target !== null) {
                $headings[$speaker] = CinematicFacing::getHeadingToward($subject->position, $subjects[$target]->position);
            }
        }
        foreach ($headings as $speaker => $heading) {
            if ($heading !== null) { $this->turnSubject($subjects[$speaker], $heading); }
        }
        $this->previousSpeaker = $name;
        $this->scene->requestFieldPresentationReconciliation();
    }

    /** Select one scene phase's participants; declaration-wide bindings remain available for later phases. */
    public function selectGroup(string $id): void
    {
        if (!isset($this->definition['groups'][$id])) { throw new RuntimeException("Conversation group '$id' is not declared."); }
        $this->activeGroup = $id;
        $this->previousSpeaker = null;
    }

    private function resolveSubject(array $reference): Player|Npc|StagedActor
    {
        $subject = match ($reference['kind']) {
            'player' => $this->scene->player,
            'npc' => $this->scene->npcManager?->findById($reference['id']),
            'staged_actor' => $this->scene->cinematicStage?->find($reference['id']),
        };
        if ($subject === null || ($subject instanceof Npc && !($this->scene->npcManager?->canStageNpc($subject) ?? false))
            || ($subject instanceof StagedActor && (!$subject->isVisible || $subject->subject?->subject instanceof WorldObject))) {
            throw new RuntimeException('A bound conversation participant is unavailable on the current map.');
        }
        return $subject;
    }

    private function hasFixedFacing(Player|Npc|StagedActor $subject): bool
    {
        if ($this->isMovingSubject($subject)) { return true; }
        $real = $subject instanceof StagedActor ? $subject->subject?->subject : $subject;
        if ($real instanceof Npc && $real->directionFix) { return true; }
        foreach ($this->scene->cinematicStage?->all() ?? [] as $actor) {
            if (($actor === $subject || ($real !== null && $actor->subject?->subject === $real)) && $actor->hasAuthoredFacing) { return true; }
        }
        return false;
    }

    private function isMovingSubject(Player|Npc|StagedActor $subject): bool
    {
        $real = $subject instanceof StagedActor ? ($subject->subject?->subject ?? $subject) : $subject;
        return $this->scene->eventInterpreter?->activeSession()?->isMovingSubject($real) ?? false;
    }

    private function turnSubject(Player|Npc|StagedActor $subject, MovementHeading $heading): void
    {
        if ($subject instanceof StagedActor) {
            if ($subject->subject === null) { $subject->face($heading->getDirection()); return; }
            $subject = $subject->subject->subject;
        }
        if (!$subject instanceof Player && !$subject instanceof Npc) { return; }
        $this->scene->cinematicStage?->captureSubject($subject);
        $subject instanceof Player
            ? $subject->setFacingSprite($subject->getSpriteForHeading($heading), $heading, engageEvents: false)
            : $subject->face($heading->getDirection());
        $subject->stopGraphicalAnimation();
        $this->scene->cinematicStage?->subjectStopped($subject);
    }
}
