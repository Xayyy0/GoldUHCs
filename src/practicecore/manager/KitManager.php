<?php
namespace practicecore\manager;

use practicecore\utils\Lang;
use practicecore\PracticeCore;
use practicecore\utils\Utils;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\utils\Config;

class KitManager{
	private static $ENCH = [
		"protection" => 0, "fire_protection" => 1, "feather_falling" => 2, "blast_protection" => 3,
		"projectile_protection" => 4, "thorns" => 5, "respiration" => 6, "sharpness" => 9,
		"knockback" => 12, "fire_aspect" => 13, "efficiency" => 15, "unbreaking" => 17,
		"power" => 19, "punch" => 20, "flame" => 21, "infinity" => 22
	];

	private $cfg;

	private $plugin;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$plugin->saveResource("kits.yml");
		$this->cfg = new Config($plugin->getDataFolder() . "kits.yml", Config::YAML);
	}

	public function reload(){
		$this->cfg->reload();
	}

	public function exists($kit){
		$kits = $this->cfg->get("kits", []);
		return isset($kits[$kit]);
	}

	private function build($spec){
		if(!is_array($spec)){
			$spec = ["item" => $spec];
		}
		$item = Item::fromString((string) $spec["item"]);
		$item->setCount(isset($spec["count"]) ? (int) $spec["count"] : 1);
		if(isset($spec["name"])){
			$item->setCustomName(Lang::color($spec["name"]));
		}
		if(isset($spec["enchants"]) and is_array($spec["enchants"])){
			foreach($spec["enchants"] as $name => $level){
				$id = is_numeric($name) ? (int) $name : (isset(self::$ENCH[strtolower($name)]) ? self::$ENCH[strtolower($name)] : null);
				if($id === null){
					continue;
				}
				$e = Enchantment::getEnchantment($id);
				if($e === null){
					continue;
				}
				$e->setLevel((int) $level);
				$item->addEnchantment($e);
			}
		}
		return $item;
	}

	public function apply(Player $p, $kitName){
		$kits = $this->cfg->get("kits", []);
		Utils::clearInventory($p);
		if(!isset($kits[$kitName])){
			$this->plugin->getLogger()->warning("Kit '" . $kitName . "' does not exist in kits.yml");
			return false;
		}
		$kit = $kits[$kitName];
		$inv = $p->getInventory();
		if(isset($kit["armor"]) and is_array($kit["armor"])){
			$a = $kit["armor"];
			if(isset($a["helmet"])) $inv->setHelmet($this->build($a["helmet"]));
			if(isset($a["chestplate"])) $inv->setChestplate($this->build($a["chestplate"]));
			if(isset($a["leggings"])) $inv->setLeggings($this->build($a["leggings"]));
			if(isset($a["boots"])) $inv->setBoots($this->build($a["boots"]));
		}
		if(isset($kit["items"]) and is_array($kit["items"])){
			foreach($kit["items"] as $spec){
				$item = $this->build($spec);
				if(is_array($spec) and isset($spec["slot"])){
					$inv->setItem((int) $spec["slot"], $item);
				}else{
					$inv->addItem($item);
				}
			}
		}
		$inv->setHeldItemIndex(0);
		$inv->sendContents($p);
		$inv->sendArmorContents($p);
		return true;
	}
}
