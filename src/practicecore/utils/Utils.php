<?php
namespace practicecore\utils;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;
use pocketmine\Server;

class Utils{
	const ITEM_TAG = "pcItem";

	public static function playerPos(Player $p){
		return [
			"world" => $p->getLevel()->getFolderName(),
			"x" => round($p->x, 2), "y" => round($p->y, 2), "z" => round($p->z, 2),
			"yaw" => round($p->yaw, 1), "pitch" => round($p->pitch, 1)
		];
	}

	public static function level($name){
		$server = Server::getInstance();
		$level = $server->getLevelByName($name);
		if($level === null){
			$server->loadLevel($name);
			$level = $server->getLevelByName($name);
		}
		return $level;
	}

	public static function toPosition($a){
		if(!is_array($a) or !isset($a["world"])){
			return null;
		}
		$level = self::level($a["world"]);
		if($level === null){
			return null;
		}
		return new Position($a["x"], $a["y"], $a["z"], $level);
	}

	public static function teleport(Player $p, $a){
		$pos = self::toPosition($a);
		if($pos === null){
			return false;
		}
		$p->teleport($pos, isset($a["yaw"]) ? $a["yaw"] : null, isset($a["pitch"]) ? $a["pitch"] : null);
		return true;
	}

	public static function clearInventory(Player $p){
		$inv = $p->getInventory();
		$inv->clearAll();
		$air = Item::get(Item::AIR);
		$inv->setHelmet($air);
		$inv->setChestplate($air);
		$inv->setLeggings($air);
		$inv->setBoots($air);
		for($i = 0; $i < 9; $i++){
			$inv->setHotbarSlotIndex($i, $i);
		}
		$inv->sendContents($p);
		$inv->sendArmorContents($p);
	}

	public static function resetPlayer(Player $p, $gamemode = 0){
		$p->setGamemode($gamemode);
		$p->removeAllEffects();
		$p->extinguish();
		$p->setHealth($p->getMaxHealth());
		$p->setFood(20);
		$p->setSaturation(20);
		$p->resetFallDistance();
		self::clearInventory($p);
	}

	public static function marked($id, $meta, $name, $tag){
		$item = Item::get($id, $meta, 1);
		$item->setCustomName(Lang::color($name));
		$nbt = $item->getNamedTag();
		if($nbt === null){
			$nbt = new CompoundTag("", []);
		}
		$nbt->{self::ITEM_TAG} = new StringTag(self::ITEM_TAG, $tag);
		$item->setNamedTag($nbt);
		return $item;
	}

	public static function itemTag(Item $item){
		$t = $item->getNamedTagEntry(self::ITEM_TAG);
		return $t === null ? null : $t->getValue();
	}

	public static function lightning(Player $viewer, Vector3 $pos){
		$pk = new AddEntityPacket();
		$pk->eid = Entity::$entityCount++;
		$pk->type = 93;
		$pk->x = $pos->x;
		$pk->y = $pos->y;
		$pk->z = $pos->z;
		$pk->speedX = 0;
		$pk->speedY = 0;
		$pk->speedZ = 0;
		$pk->yaw = 0;
		$pk->pitch = 0;
		$pk->modifiers = 0;
		$pk->metadata = [];
		$viewer->dataPacket($pk);
	}

	public static function posOf(Entity $e){
		return new Vector3($e->x, $e->y, $e->z);
	}

	public static function stripColors($text){
		$text = (string) $text;
		if(strpos($text, "\xc2\xa7") === false){
			return $text;
		}
		return preg_replace('/\xc2\xa7./', '', $text);
	}

	public static function parseTime($s){
		$s = strtolower(trim($s));
		if(!preg_match('/^(\d+)([smh]?)$/', $s, $m)){
			return null;
		}
		$n = (int) $m[1];
		switch($m[2]){
			case "s": return $n;
			case "h": return $n * 3600;
			default: return $n * 60;
		}
	}

	public static function formatTime($sec){
		$sec = max(0, (int) $sec);
		if($sec >= 3600){
			return floor($sec / 3600) . "h " . floor(($sec % 3600) / 60) . "m";
		}
		if($sec >= 60){
			return floor($sec / 60) . "m " . ($sec % 60) . "s";
		}
		return $sec . "s";
	}
}
