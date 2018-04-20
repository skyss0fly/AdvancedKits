<?php

namespace AdvancedKits;

use pocketmine\command\ConsoleCommandSender;
use pocketmine\entity\Effect;
use pocketmine\entity\EffectInstance;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\Player;

class Kit{

    private $ak;
    private $data;
    private $name;
    private $cost = 0;
    private $coolDown;
    private $coolDowns = [];

    /** @var Item[] */
    private $items = [];
    /** @var Item[] */
    private $armor = [];
    /** @var EffectInstance[] */
    private $effects = [];

    public function __construct(Main $ak, array $data, string $name){
        $this->ak = $ak;
        $this->data = $data;
        $this->name = $name;
        $this->coolDown = $this->getCoolDownMinutes();

        if(isset($this->data['money']) && $this->data['money'] !== 0){
            $this->cost = (int) $this->data['money'];
        }
        if(file_exists($this->ak->getDataFolder().'cooldowns/'.strtolower($this->name).'.sl')){
            $this->coolDowns = unserialize(file_get_contents($this->ak->getDataFolder().'cooldowns/'.strtolower($this->name).'.sl'));
        }

        foreach($this->data['items'] as $itemString){
            $item = $this->loadItem($itemString);
            if($item !== null){
                $this->items[] = $item;
            }
        }
        isset($this->data['helmet']) && ($this->armor['helmet'] = $this->loadItem($this->data['helmet']));
        isset($this->data['chestplate']) && ($this->armor['chestplate'] = $this->loadItem($this->data['chestplate']));
        isset($this->data['leggings']) && ($this->armor['leggings'] = $this->loadItem($this->data['leggings']));
        isset($this->data['boots']) && ($this->armor['boots'] = $this->loadItem($this->data['boots']));

        if(isset($this->data['effects']) && is_array($this->data['effects'])){
            foreach($this->data['effects'] as $effectString){
                $effect = $this->loadEffect($effectString);
                if($effect !== null){
                    $this->effects[] = $effect;
                }
            }
        }
    }

    public function getName() : string{
        return $this->name;
    }

    public function handleRequest(Player $player) : bool{
        if($this->testPermission($player)){
            if(!isset($this->coolDowns[$player->getLowerCaseName()])){
                if(!($this->ak->getConfig()->get('one-kit-per-life') && isset($this->ak->hasKit[strtolower($player->getName())]))){
                    if($this->cost){
                        if($this->ak->economy->grantKit($player, $this->cost)){
                            $this->addTo($player);
                            $player->sendMessage($this->ak->langManager->getTranslation('sel-kit', $this->name));
                            return true;
                        }
                        $player->sendMessage($this->ak->langManager->getTranslation('cant-afford', $this->name));
                    }else{
                        $this->addTo($player);
                        $player->sendMessage($this->ak->langManager->getTranslation('sel-kit', $this->name));
                        return true;
                    }
                }else{
                    $player->sendMessage($this->ak->langManager->getTranslation('one-per-life'));
                }
            }else{
                $player->sendMessage($this->ak->langManager->getTranslation('cooldown1', $this->name));
                $player->sendMessage($this->ak->langManager->getTranslation('cooldown2', $this->getCoolDownLeft($player)));
            }
        }else{
            $player->sendMessage($this->ak->langManager->getTranslation('no-perm', $this->name));
        }
        return false;
    }

    public function addTo(Player $player) : void{
        foreach($this->items as $item){
            $player->getInventory()->addItem($item);
        }

        isset($this->armor['helmet']) && $player->getArmorInventory()->setHelmet($this->armor['helmet']);
        isset($this->armor['chestplate']) && $player->getArmorInventory()->setChestplate($this->armor['chestplate']);
        isset($this->armor['leggings']) && $player->getArmorInventory()->setLeggings($this->armor['leggings']);
        isset($this->armor['boots']) && $player->getArmorInventory()->setBoots($this->armor['boots']);

        foreach($this->effects as $effect){
            $player->addEffect(clone $effect);
        }

        if(isset($this->data['commands']) && is_array($this->data['commands'])){
            foreach($this->data['commands'] as $cmd){
                $this->ak->getServer()->dispatchCommand(new ConsoleCommandSender(), str_replace('{player}', $player->getName(), $cmd));
            }
        }
        if($this->coolDown){
            $this->coolDowns[$player->getLowerCaseName()] = $this->coolDown;
        }
        $this->ak->hasKit[$player->getLowerCaseName()] = $this->name;
    }


    private function loadItem(string $itemString) : ?Item{
        $array = explode(':', $itemString);
        if(count($array) < 2){
            $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Item '.$itemString.' could not be loaded because name and damage are not specified');
            return null;
        }

        $name = array_shift($array);
        $damage = array_shift($array);
        try{
            $item = Item::fromString($name.':'.$damage);
        }catch(\InvalidArgumentException $exception){
            $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Item '.$itemString.' could not be loaded');
            $this->ak->getLogger()->logException($exception);
            return null;
        }

        if(!empty($array)){
            $item->setCount((int) array_shift($array));
        }

        if(!empty($array)){
            $name = array_shift($array);
            if(strtolower($name) !== 'default'){
                $item->setCustomName($name);
            }
        }

        if(!empty($array)){
            $enchantmentsArrays = array_chunk($array, 2);
            foreach ($enchantmentsArrays as $enchantmentsData){
                if(count($enchantmentsData) !== 2){
                    $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Enchantments must be specified in the format name:level. Item: '.$itemString.' could not be loaded');
                    continue;
                }
                $enchantment = Enchantment::getEnchantmentByName($enchantmentsData[0]);
                if($enchantment === null){
                    $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Enchantment '.$enchantmentsData[0].' in item '.$itemString.' could not be loaded because the enchantment does not exist');
                    continue;
                }
                if(!is_numeric($array[1])){
                    $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Enchantment '.$enchantmentsData[0].' in item '.$itemString.' could not be loaded because the level is not a number');
                    continue;
                }
                $enchantmentInstance = new EnchantmentInstance($enchantment, (int) $enchantmentsData[1]);
                $item->addEnchantment($enchantmentInstance);
            }
        }
        return $item;
    }

    private function loadEffect(string $effectString) : ?EffectInstance{
        $array = explode(':', $effectString);
        if(count($array) < 2){
            $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Effect '.$effectString.' could not be loaded because name and level are not specified');
            return null;
        }
        $name = array_shift($array);
        $duration = array_shift($array);
        if(!is_numeric($duration)){
            $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Effect '.$effectString.' could not be loaded because the duration is not a number');
            return null;
        }

        if(!empty($array)){
            $amplifier = array_shift($array);
             if(!is_numeric($amplifier)){
                 $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Effect '.$effectString.' could not be loaded because the amplifier is not a number');
                 return null;
             }
        }else{
            $amplifier = 0;
        }

        $e = Effect::getEffectByName($name);
        if($e === null){
            $this->ak->getLogger()->warning('Bad configuration in kit '.$this->name.'. Effect '.$effectString.' could not be loaded because the effect does not exist');
            return null;
        }
        return new EffectInstance($e, (int) $duration * 20, (int) $amplifier);
    }

    private function getCoolDownMinutes() : int{
        $min = 0;
        if(isset($this->data['cooldown']['minutes'])){
            $min += (int) $this->data['cooldown']['minutes'];
        }
        if(isset($this->data['cooldown']['hours'])){
            $min += (int) $this->data['cooldown']['hours'] * 60;
        }
        return $min;
    }

    private function getCoolDownLeft(Player $player) : string{
        if(($minutes = $this->coolDowns[$player->getLowerCaseName()]) < 60){
            return $this->ak->langManager->getTranslation('cooldown-format1', $minutes);
        }
        if(($modulo = $minutes % 60) !== 0){
            return $this->ak->langManager->getTranslation('cooldown-format2', floor($minutes / 60), $modulo);
        }
        return $this->ak->langManager->getTranslation('cooldown-format3', $minutes / 60);
    }

    public function processCoolDown() : void{
        foreach($this->coolDowns as $player => $min){
            --$this->coolDowns[$player];
            if($this->coolDowns[$player] <= 0){
                unset($this->coolDowns[$player]);
            }
        }
    }

    private function testPermission(Player $player) : bool{
        return $this->ak->permManager ? $player->hasPermission('advancedkits.'.strtolower($this->name)) : (
            (isset($this->data['users']) ? in_array($player->getLowerCaseName(), $this->data['users'], true) : true)
            &&
            (isset($this->data['worlds']) ? in_array(strtolower($player->getLevel()->getName()), $this->data['worlds'], true) : true)
        );
    }

    public function save() : void{
        if(!empty($this->coolDowns)){
            file_put_contents($this->ak->getDataFolder().'cooldowns/'.strtolower($this->name).'.sl', serialize($this->coolDowns));
        }
    }

}
