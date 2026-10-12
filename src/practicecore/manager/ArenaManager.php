<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\utils\Utils;

use pocketmine\Player;
use pocketmine\utils\Config;

class ArenaManager{
	private $plugin;

	private $cfg;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$this->cfg = new Config($plugin->getDataFolder() . "arenas.yml", Config::YAML, ["ffa" => [], "duel" => []]);
	}

	private function section($type){
		return $this->cfg->get($type, []);
	}

	private function put($type, array $data){
		$this->cfg->set($type, $data);
		$this->cfg->save();
	}

	public function getFfa($name){
		$all = $this->section("ffa");
		$k = strtolower($name);
		return isset($all[$k]) ? $all[$k] : null;
	}

	public function ffaLabel($name){
		$arena = $this->getFfa($name);
		if($arena === null){
			return (string) $name;
		}
		$modes = $this->plugin->getConfig()->get("modes", []);
		$display = isset($modes[$arena["mode"]]["display"]) ? $modes[$arena["mode"]]["display"] : $arena["mode"];
		$n = 0;
		foreach($this->allFfa() as $k => $a){
			if($a["mode"] === $arena["mode"]){
				$n++;
			}
			if(strtolower($a["name"]) === strtolower($arena["name"])){
				break;
			}
		}
		return $n > 1 ? $display . " " . $n : $display;
	}

	public function allFfa(){
		return $this->section("ffa");
	}

	public function createFfa($name, $mode, Player $p){
		$all = $this->section("ffa");
		$k = strtolower($name);
		if(isset($all[$k])){
			return false;
		}
		$all[$k] = ["name" => $name, "mode" => $mode, "spawns" => [Utils::playerPos($p)]];
		$this->put("ffa", $all);
		return true;
	}

	public function addFfaSpawn($name, Player $p){
		$all = $this->section("ffa");
		$k = strtolower($name);
		if(!isset($all[$k])){
			return false;
		}
		$all[$k]["spawns"][] = Utils::playerPos($p);
		$this->put("ffa", $all);
		return true;
	}

	public function randomFfaSpawn($name){
		$a = $this->getFfa($name);
		if($a === null or empty($a["spawns"])){
			return null;
		}
		return $a["spawns"][array_rand($a["spawns"])];
	}

	public function getDuel($name){
		$all = $this->section("duel");
		$k = strtolower($name);
		return isset($all[$k]) ? $all[$k] : null;
	}

	public function allDuel(){
		return $this->section("duel");
	}

	public function createDuel($name){
		$all = $this->section("duel");
		$k = strtolower($name);
		if(isset($all[$k])){
			return false;
		}
		$all[$k] = ["name" => $name, "p1" => null, "p2" => null];
		$this->put("duel", $all);
		return true;
	}

	public function setDuelPos($name, $n, Player $p){
		$all = $this->section("duel");
		$k = strtolower($name);
		if(!isset($all[$k])){
			return false;
		}
		$all[$k]["p" . $n] = Utils::playerPos($p);
		$this->put("duel", $all);
		return true;
	}

	public function delete($type, $name){
		$all = $this->section($type);
		$k = strtolower($name);
		if(!isset($all[$k])){
			return false;
		}
		unset($all[$k]);
		$this->put($type, $all);
		return true;
	}
}
