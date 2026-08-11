<?php

use Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows\CharacterDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\CharacterPanel;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;

class CharacterRoleMainMenuPanelProxy extends CharacterPanel
{
  public function render(?int $x = null, ?int $y = null): void
  {
    // Skip terminal output during isolated presentation tests.
  }
}

class CharacterRoleStatusViewProxy extends StatusViewState
{
  public function profileContentFor(Character $character): array
  {
    $this->character = $character;

    return $this->buildProfileSummaryContent();
  }
}

class CharacterRoleEquipmentPanelProxy extends CharacterDetailPanel
{
  public function contentFor(Character $character): array
  {
    $this->character = $character;

    return $this->buildCharacterContent();
  }
}

function makeCharacterWithRole(string $role): Character
{
  $character = new Character('Kaelion', 0, new Stats());
  $character->role = new CharacterRole($character, $role);

  return $character;
}

it('shows the assigned role throughout character information views', function () {
  $character = makeCharacterWithRole('Vanguard');

  $mainMenuPanel = new CharacterRoleMainMenuPanelProxy(new Rect(0, 0, 46, 7));
  $mainMenuPanel->setDetails(
    $character->name,
    $character->level,
    '100 / 100',
    '10 / 10',
    $character->role->name
  );

  $statusView = (new ReflectionClass(CharacterRoleStatusViewProxy::class))
    ->newInstanceWithoutConstructor();
  $equipmentPanel = (new ReflectionClass(CharacterRoleEquipmentPanelProxy::class))
    ->newInstanceWithoutConstructor();

  expect(implode("\n", $mainMenuPanel->getContent()))->toContain('Role: Vanguard')
    ->and(implode("\n", $statusView->profileContentFor($character)))->toContain('Role:    Vanguard')
    ->and(implode("\n", $equipmentPanel->contentFor($character)))->toContain('Role: Vanguard');
});
