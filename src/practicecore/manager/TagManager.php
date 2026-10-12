<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\utils\Lang;

use pocketmine\Player;
use pocketmine\utils\Config;

class TagManager{
	private $plugin;

	private $cfg;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$this->cfg = new Config($plugin->getDataFolder() . "champions.yml", Config::YAML, ["players" => []]);
	}

	public function enabled(){
		return (bool) $this->plugin->getConfig()->getNested("meetup.winner-tag.enabled", true);
	}

	public function rankOf($name){
		$all = $this->cfg->get("players", []);
		$k = strtolower($name);
		return (is_array($all) and isset($all[$k])) ? (int) $all[$k] : 0;
	}

	public function setPodium(array $first, array $second){
		$old = $this->cfg->get("players", []);
		$new = [];
		foreach($second as $n){
			$new[strtolower($n)] = 2;
		}
		foreach($first as $n){
			$new[strtolower($n)] = 1;
		}
		$this->cfg->set("players", $new);
		$this->cfg->save();
		$names = array_unique(array_merge(is_array($old) ? array_keys($old) : [], array_keys($new)));
		foreach($names as $n){
			$p = $this->plugin->getServer()->getPlayerExact((string) $n);
			if($p !== null){
				$this->apply($p);
			}
		}
	}

	public function apply(Player $p){
		$name = $p->getName();
		$rank = $this->enabled() ? $this->rankOf($name) : 0;
		if($rank === 1 or $rank === 2){
			$cfg = $this->plugin->getConfig();
			$format = $rank === 1
				? (string) $cfg->getNested("meetup.winner-tag.first", "&6#1 &f{player}")
				: (string) $cfg->getNested("meetup.winner-tag.second", "&bBest &f{player}");
			$full = Lang::color(str_replace("{player}", $name, $format));
			$p->setNameTag($full);
			$p->setDisplayName($full);
		}else{
			$p->setNameTag($name);
			$p->setDisplayName($name);
		}
	}
}
