<?php
namespace practicecore\manager;

use pocketmine\Player;
use pocketmine\utils\Config;
use practicecore\model\Session;
use practicecore\PracticeCore;

class SessionManager{
	public static $SETTINGS = ["auto-sprint", "auto-respawn", "lightning-kill", "cps-counter", "scoreboard"];

	private $plugin;

	private $sessions = [];

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		@mkdir($plugin->getDataFolder() . "players/");
	}

	public function dir(){
		return $this->plugin->getDataFolder() . "players/";
	}

	private function file($name){
		return $this->dir() . strtolower($name) . ".yml";
	}

	public function get(Player $p){
		return isset($this->sessions[strtolower($p->getName())]) ? $this->sessions[strtolower($p->getName())] : null;
	}

	public function getByName($name){
		$n = strtolower($name);
		return isset($this->sessions[$n]) ? $this->sessions[$n] : null;
	}

	public function read($name, $create = false){
		$file = $this->file($name);
		$exists = is_file($file);
		if(!$exists and !$create){
			return null;
		}
		$s = new Session($name);
		$cfg = $this->plugin->getConfig();
		$s->settings = $cfg->get("settings-defaults", []);
		foreach(self::$SETTINGS as $k){
			if(!isset($s->settings[$k])){
				$s->settings[$k] = ($k === "scoreboard");
			}
		}
		$s->elo = (int) $cfg->getNested("elo.start", 800);
		if($exists){
			$c = new Config($file, Config::YAML);
			foreach(self::$SETTINGS as $k){
				if($c->exists($k)){
					$s->settings[$k] = (bool) $c->get($k);
				}
			}
			$s->welcomed = (bool) $c->get("welcomed", false);
			$s->elo = (int) $c->get("elo", $s->elo);
			$s->kills = (int) $c->get("kills", 0);
			$s->deaths = (int) $c->get("deaths", 0);
			$s->rankedWins = (int) $c->get("ranked-wins", 0);
			$s->rankedLosses = (int) $c->get("ranked-losses", 0);
			$s->unrankedWins = (int) $c->get("unranked-wins", 0);
			$s->unrankedLosses = (int) $c->get("unranked-losses", 0);
			$s->meetupPlayed = (int) $c->get("meetup-played", 0);
			$s->meetupWins = (int) $c->get("meetup-wins", 0);
		}
		return $s;
	}

	public function open(Player $p){
		$s = $this->read($p->getName(), true);
		$s->name = $p->getName();
		$this->sessions[strtolower($p->getName())] = $s;
		$this->plugin->stats->touch($s);
		return $s;
	}

	public function save(Session $s){
		$c = new Config($this->file($s->name), Config::YAML);
		$c->set("name", $s->name);
		foreach(self::$SETTINGS as $k){
			$c->set($k, (bool) $s->settings[$k]);
		}
		$c->set("welcomed", $s->welcomed);
		$c->set("elo", $s->elo);
		$c->set("kills", $s->kills);
		$c->set("deaths", $s->deaths);
		$c->set("ranked-wins", $s->rankedWins);
		$c->set("ranked-losses", $s->rankedLosses);
		$c->set("unranked-wins", $s->unrankedWins);
		$c->set("unranked-losses", $s->unrankedLosses);
		$c->set("meetup-played", $s->meetupPlayed);
		$c->set("meetup-wins", $s->meetupWins);
		$c->save();
		$this->plugin->stats->touch($s);
	}

	public function close(Player $p){
		$s = $this->get($p);
		if($s !== null){
			$this->save($s);
			unset($this->sessions[strtolower($p->getName())]);
		}
	}

	private $saveCursor = 0;

	public function saveBatch($count){
		$keys = array_keys($this->sessions);
		$n = count($keys);
		if($n === 0){
			return;
		}
		for($i = 0; $i < $count and $i < $n; $i++){
			$k = $keys[$this->saveCursor % $n];
			$this->saveCursor++;
			$this->save($this->sessions[$k]);
		}
	}

	public function saveAll(){
		foreach($this->sessions as $s){
			$this->save($s);
		}
	}

	public function all(){
		return $this->sessions;
	}
}
