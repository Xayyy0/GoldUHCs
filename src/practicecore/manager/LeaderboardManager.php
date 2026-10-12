<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\utils\Utils;

use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\utils\Config;

class LeaderboardManager{
	public static $TYPES = [
		"kills" => ["kills", "lb.title-kills"],
		"deaths" => ["deaths", "lb.title-deaths"],
		"meetupwins" => ["meetupWins", "lb.title-meetupwins"]
	];

	private $plugin;

	private $cfg;

	private $particles = [];

	private $shown = [];
	private $lastRefresh = 0;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$this->cfg = new Config($plugin->getDataFolder() . "leaderboards.yml", Config::YAML, []);
	}

	public function all(){
		return $this->cfg->getAll();
	}

	public static function normalizeType($t){
		$t = str_replace(["-", "_", " "], "", strtolower($t));
		if($t === "wins" or $t === "meetup" or $t === "meetupwin"){
			$t = "meetupwins";
		}
		return isset(self::$TYPES[$t]) ? $t : null;
	}

	public function create($type, Player $p){
		$id = substr(md5(uniqid("", true)), 0, 6);
		$this->cfg->set($id, ["type" => $type, "pos" => Utils::playerPos($p)]);
		$this->cfg->save();
		$this->refresh(true);
		return $id;
	}

	public function removeNearest(Player $p){
		$best = null;
		$bestDist = 36;
		foreach($this->cfg->getAll() as $id => $d){
			if($d["pos"]["world"] !== $p->getLevel()->getFolderName()){
				continue;
			}
			$dx = $d["pos"]["x"] - $p->x;
			$dy = $d["pos"]["y"] - $p->y;
			$dz = $d["pos"]["z"] - $p->z;
			$dist = $dx * $dx + $dy * $dy + $dz * $dz;
			if($dist <= $bestDist){
				$bestDist = $dist;
				$best = $id;
			}
		}
		if($best === null){
			return false;
		}
		$this->hideEverywhere($best);
		unset($this->particles[$best]);
		$this->cfg->remove($best);
		$this->cfg->save();
		return true;
	}

	private function text($type){
		$lang = $this->plugin->lang;
		$n = max(1, (int) $this->plugin->getConfig()->getNested("holograms.lines", 10));
		$col = self::$TYPES[$type][0];
		$rows = $this->plugin->stats->top($col, $n);
		$lines = [];
		foreach($rows as $i => $r){
			$lines[] = $lang->get("lb.line", ["pos" => $i + 1, "player" => $r["name"], "value" => $r[$col]]);
		}
		if(count($lines) === 0){
			$lines[] = $lang->get("lb.empty");
		}
		return [$lang->get(self::$TYPES[$type][1], ["n" => $n]), implode("\n", $lines)];
	}

	private function refresh($force = false){
		$every = max(5, (int) $this->plugin->getConfig()->getNested("holograms.refresh-seconds", 20));
		if(!$force and time() - $this->lastRefresh < $every){
			return false;
		}
		$this->lastRefresh = time();
		foreach($this->cfg->getAll() as $id => $d){
			$level = Utils::level($d["pos"]["world"]);
			if($level === null){
				continue;
			}
			list($title, $body) = $this->text($d["type"]);
			if(!isset($this->particles[$id])){
				$this->particles[$id] = new FloatingTextParticle(new Vector3($d["pos"]["x"], $d["pos"]["y"] + 1.8, $d["pos"]["z"]), $body, $title);
			}else{
				$this->particles[$id]->setTitle($title);
				$this->particles[$id]->setText($body);
			}
		}
		return true;
	}

	public function tick(){
		$refreshed = $this->refresh(false);
		foreach($this->plugin->getServer()->getOnlinePlayers() as $p){
			if(!$p->spawned or $p->closed){
				continue;
			}
			$key = strtolower($p->getName());
			foreach($this->cfg->getAll() as $id => $d){
				if(!isset($this->particles[$id])){
					continue;
				}
				$here = $p->getLevel()->getFolderName() === $d["pos"]["world"];
				$on = isset($this->shown[$key][$id]);
				if($here and (!$on or $refreshed)){
					$this->send($p, $id, false);
					$this->shown[$key][$id] = true;
				}elseif(!$here and $on){
					$this->send($p, $id, true);
					unset($this->shown[$key][$id]);
				}
			}
		}
	}

	private function send(Player $p, $id, $hide){
		$d = $this->cfg->get($id);
		$level = $d !== false && $d !== null ? Utils::level($d["pos"]["world"]) : null;
		if($level === null or !isset($this->particles[$id])){
			return;
		}
		$particle = $this->particles[$id];
		$particle->setInvisible($hide);
		$level->addParticle($particle, [$p]);
		$particle->setInvisible(false);
	}

	public function hideAll(Player $p){
		$key = strtolower($p->getName());
		if(isset($this->shown[$key])){
			foreach(array_keys($this->shown[$key]) as $id){
				$this->send($p, $id, true);
			}
		}
		unset($this->shown[$key]);
	}

	public function forget(Player $p){
		unset($this->shown[strtolower($p->getName())]);
	}

	private function hideEverywhere($id){
		foreach($this->plugin->getServer()->getOnlinePlayers() as $p){
			$key = strtolower($p->getName());
			if(isset($this->shown[$key][$id])){
				$this->send($p, $id, true);
				unset($this->shown[$key][$id]);
			}
		}
	}
}
