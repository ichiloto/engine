<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Scenes\Game\States\GameSceneState;

/**
 * ShopState class. This state allows players to interact with in-game shops.
 *
 * Key Features:
 * - Item Listings: Display items available for purchase, along with their prices and descriptions.
 * - Currency Transactions: Deduct currency for purchases and add items to the inventory. Enable selling items for currency.
 * - Inventory Updates: Reflect changes immediately in the player's inventory.
 *
 * Interactions with Other States:
 * - Returns to FieldState after the transaction is complete.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class ShopState extends GameSceneState
{

}