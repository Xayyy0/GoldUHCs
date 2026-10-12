<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\model\Session;
use practicecore\utils\Utils;

use pocketmine\command\CommandSender;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\utils\Config;

class MeetupManager{
	const IDLE = 0;
	const SCHEDULED = 1;
	const PRELOBBY = 2;
	const COUNTDOWN = 3;
	const ACTIVE = 4;
	const ENDING = 5;

	private $plugin;

	private $maps;

	private $state = self::IDLE;
	private $mapName = null;
	private $teamSize = 1;
	private $startAt = 0;
	private $pvpAt = 0;
	private $endAt = 0;
	private $lastAnnounce = 0;

	private $registered = [];

	private $alive = [];

	private $kills = [];

	private $eliminatedTeams = [];

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$this->maps = new Config($plugin->getDataFolder() . "meetup_maps.yml", Config::YAML, []);
	}

	public function setMap($name, Player $p, $radius){
		$this->maps->set(strtolower($name), [
			"name" => $name,
			"center" => Utils::playerPos($p),
			"radius" => (int) $radius
		]);
		$this->maps->save();
	}

	public function deleteMap($name){
		if(!$this->maps->exists(strtolower($name))){
			return false;
		}
		$this->maps->remove(strtolower($name));
		$this->maps->save();
		return true;
	}

	public function getMap($name){
		$m = $this->maps->get(strtolower($name), null);
		return is_array($m) ? $m : null;
	}

	public function mapNames(){
		$out = [];
		foreach($this->maps->getAll() as $m){
			$out[] = $m["name"];
		}
		return $out;
	}

	public function getState(){
		return $this->state;
	}

	public function getTeamSize(){
		return $this->teamSize;
	}

	public function isOpenForTeams(){
		return $this->state === self::SCHEDULED or $this->state === self::PRELOBBY;
	}

	public function isRunning(){
		return $this->state >= self::COUNTDOWN;
	}

	public function isPvpEnabled(){
		return $this->state === self::ACTIVE;
	}

	public function isRegistered(Player $p){
		return isset($this->registered[strtolower($p->getName())]);
	}

	public function registeredCount(){
		return count($this->registered);
	}

	public function aliveCount(){
		return count($this->alive);
	}

	public function killsOf($name){
		$k = strtolower($name);
		return isset($this->kills[$k]) ? $this->kills[$k] : 0;
	}

	public function getMapName(){
		return $this->mapName === null ? "-" : $this->mapName;
	}

	public function modeName(){
		return $this->teamSize === 1 ? "Solo" : ($this->teamSize === 2 ? "Duo" : "Trio");
	}

	public function statusLines(){
		$lang = $this->plugin->lang;
		if($this->state === self::IDLE){
			return [$lang->get("meetup.status-none")];
		}
		$names = ["", "Scheduled", "Pre-lobby", "PvP protection", "Active", "Ending"];
		$lines = [
			$lang->get("meetup.status-header"),
			$lang->get("meetup.status-state", ["state" => $names[$this->state]]),
			$lang->get("meetup.status-map", ["map" => $this->mapName]),
			$lang->get("meetup.status-mode", ["mode" => $this->modeName()]),
			$lang->get("meetup.status-players", ["count" => count($this->registered)])
		];
		if($this->state === self::SCHEDULED or $this->state === self::PRELOBBY){
			$lines[] = $lang->get("meetup.status-starts", ["time" => Utils::formatTime($this->startAt - time())]);
		}
		if($this->state === self::ACTIVE or $this->state === self::COUNTDOWN){
			$lines[] = $lang->get("meetup.status-alive", ["count" => count($this->alive)]);
		}
		return $lines;
	}

	public function schedule($seconds, $mapName, $teamSize){
		if($this->state !== self::IDLE){
			return "meetup.already-running";
		}
		$map = $this->getMap($mapName);
		if($map === null){
			return "meetup.map-not-found";
		}
		if(Utils::toPosition($map["center"]) === null){
			return "meetup.map-world-missing";
		}
		$this->state = self::SCHEDULED;
		$this->mapName = $map["name"];
		$this->teamSize = max(1, min(3, (int) $teamSize));
		$this->startAt = time() + (int) $seconds;
		$this->lastAnnounce = (int) $seconds;
		$this->registered = [];
		$this->alive = [];
		$this->kills = [];
		$this->eliminatedTeams = [];
		$this->plugin->teams->clear();

		$this->plugin->lang->broadcast("meetup.scheduled", [
			"map" => $this->mapName,
			"mode" => $this->modeName(),
			"time" => Utils::formatTime($seconds)
		]);
		$this->plugin->lang->broadcast("meetup.how-to-join");
		$this->plugin->menus->refreshAll();
		return null;
	}

	public function cancel(){
		if($this->state === self::IDLE){
			return false;
		}
		$this->plugin->lang->broadcast("meetup.cancelled");
		$this->reset();
		return true;
	}

	private function reset(){
		foreach($this->registered as $display){
			$p = $this->plugin->getServer()->getPlayerExact($display);
			if($p !== null){
				$s = $this->plugin->sessions->get($p);
				if($s !== null and ($s->state === Session::MEETUP_WAIT or $s->state === Session::MEETUP or $s->state === Session::MEETUP_DEAD)){
					$this->plugin->sendToLobby($p);
				}
			}
		}
		$this->registered = [];
		$this->alive = [];
		$this->kills = [];
		$this->eliminatedTeams = [];
		$this->plugin->teams->clear();
		$this->state = self::IDLE;
		$this->mapName = null;
		$this->plugin->menus->refreshAll();
	}

	public function join(Player $p){
		$lang = $this->plugin->lang;
		if($this->state === self::IDLE){
			$lang->send($p, "meetup.none-scheduled");
			return false;
		}
		if($this->isRunning()){
			$lang->send($p, "meetup.already-started");
			return false;
		}
		if($this->isRegistered($p)){
			$lang->send($p, "meetup.already-joined");
			return false;
		}
		$this->registered[strtolower($p->getName())] = $p->getName();
		$lang->send($p, "meetup.joined", ["count" => count($this->registered)]);
		if($this->state === self::PRELOBBY){
			$this->toPrelobby($p);
		}else{
			$s = $this->plugin->sessions->get($p);
			if($s !== null){
				$this->plugin->menus->render($p, $s, true);
			}
		}
		return true;
	}

	public function leave(Player $p){
		$lang = $this->plugin->lang;
		if(!$this->isRegistered($p) or $this->isRunning()){
			$lang->send($p, "meetup.not-joined");
			return false;
		}
		unset($this->registered[strtolower($p->getName())]);
		$this->plugin->teams->leave($p->getName());
		$s = $this->plugin->sessions->get($p);
		if($s !== null and $s->state === Session::MEETUP_WAIT){
			$this->plugin->sendToLobby($p);
		}elseif($s !== null){
			$this->plugin->menus->render($p, $s, true);
		}
		$lang->send($p, "meetup.left");
		return true;
	}

	public function onQuit(Player $p){
		$k = strtolower($p->getName());
		if(!isset($this->registered[$k])){
			return;
		}
		if($this->state === self::SCHEDULED or $this->state === self::PRELOBBY){
			unset($this->registered[$k]);
			$this->plugin->teams->leave($p->getName());
		}elseif(isset($this->alive[$k])){
			$killer = null;
			$s = $this->plugin->sessions->get($p);
			$window = (int) $this->plugin->getConfig()->getNested("combat-tag.seconds", 15);
			if($s !== null and $s->lastDamager !== null and (time() - $s->lastDamageTime) <= $window){
				$kp = $this->plugin->getServer()->getPlayerExact($s->lastDamager);
				if($kp !== null and isset($this->alive[strtolower($kp->getName())])){
					$killer = $kp;
				}
			}
			$this->eliminate($p, $killer, true);
		}
	}

	private function onlineParticipants(){
		$out = [];
		foreach($this->registered as $display){
			$p = $this->plugin->getServer()->getPlayerExact($display);
			if($p !== null){
				$out[] = $p;
			}
		}
		return $out;
	}

	public function tick(){
		$now = time();
		$cfg = $this->plugin->getConfig();
		switch($this->state){
			case self::SCHEDULED:
				$rem = $this->startAt - $now;
				if($rem <= (int) $cfg->getNested("meetup.prelobby-seconds", 60)){
					$this->enterPrelobby();
					break;
				}
				foreach($cfg->getNested("meetup.announce-at", []) as $t){
					if($rem <= $t and $this->lastAnnounce > $t){
						$this->lastAnnounce = $t;
						$this->plugin->lang->broadcast("meetup.reminder", [
							"time" => Utils::formatTime($rem), "map" => $this->mapName, "mode" => $this->modeName()
						]);
						break;
					}
				}
				break;
			case self::PRELOBBY:
				$rem = $this->startAt - $now;
				if($rem <= 0){
					$this->begin();
					break;
				}
				if(in_array($rem, [60, 45, 30, 15, 10, 5, 4, 3, 2, 1], true)){
					foreach($this->onlineParticipants() as $p){
						$this->plugin->hud->flash($p, $this->plugin->lang->get("meetup.teleport-in", ["seconds" => $rem]), 1.2);
						if($rem >= 10 or $rem <= 3){
							$this->plugin->lang->send($p, "meetup.teleport-in", ["seconds" => $rem]);
						}
					}
				}
				break;
			case self::COUNTDOWN:
				$rem = $this->pvpAt - $now;
				if($rem <= 0){
					$this->state = self::ACTIVE;
					foreach($this->onlineParticipants() as $p){
						$this->plugin->hud->flash($p, $this->plugin->lang->get("meetup.pvp-on"), 2);
						$this->plugin->lang->send($p, "meetup.pvp-on");
					}
				}else{
					foreach($this->onlineParticipants() as $p){
						$this->plugin->hud->flash($p, $this->plugin->lang->get("meetup.pvp-in", ["seconds" => $rem]), 1.2);
					}
				}
				break;
			case self::ENDING:
				if($this->endAt <= $now){
					$this->reset();
				}
				break;
		}
	}

	private function enterPrelobby(){
		$this->state = self::PRELOBBY;
		if($this->plugin->getConfig()->getNested("meetup.pull-everyone", false)){
			foreach($this->plugin->getServer()->getOnlinePlayers() as $p){
				$this->registered[strtolower($p->getName())] = $p->getName();
			}
		}
		$this->plugin->lang->broadcast("meetup.prelobby-start", ["seconds" => max(0, $this->startAt - time())]);
		foreach($this->onlineParticipants() as $p){
			$this->toPrelobby($p);
		}
		$this->plugin->menus->refreshAll();
	}

	private function toPrelobby(Player $p){
		$s = $this->plugin->sessions->get($p);
		if($s === null){
			return;
		}
		switch($s->state){
			case Session::DUEL:
				$this->plugin->duels->abort($p);
				break;
			case Session::DUEL_QUEUE:
				$this->plugin->duels->leaveQueue($p);
				break;
			case Session::FFA:
			case Session::FFA_DEAD:
				$this->plugin->ffa->leave($p);
				break;
		}
		$s->state = Session::MEETUP_WAIT;
		$s->match = null;
		$s->queueMode = null;
		Utils::resetPlayer($p, 0);
		$pre = $this->plugin->getConfig()->get("meetup-prelobby", null);
		if($pre === null){
			$pre = $this->plugin->getConfig()->get("lobby", null);
		}
		if($pre !== null){
			Utils::teleport($p, $pre);
		}
		$item = $this->plugin->getConfig()->get("items", [])["meetup-leave"];
		$p->getInventory()->setItem((int) $item["slot"], Utils::marked($item["id"], $item["meta"], $item["name"], "meetup_leave"));
		$p->getInventory()->sendContents($p);
		$this->plugin->lang->send($p, "meetup.in-prelobby");
	}

	private function begin(){
		$cfg = $this->plugin->getConfig();
		$lang = $this->plugin->lang;
		$map = $this->getMap($this->mapName);
		$center = $map !== null ? Utils::toPosition($map["center"]) : null;
		$players = [];
		foreach($this->onlineParticipants() as $p){
			$s = $this->plugin->sessions->get($p);
			if($s !== null and $s->state === Session::MEETUP_WAIT){
				$players[] = $p;
			}
		}
		if($center === null or count($players) < (int) $cfg->getNested("meetup.min-players", 2)){
			$lang->broadcast("meetup.not-enough");
			$this->reset();
			return;
		}

		$teams = $this->plugin->teams;
		$names = [];
		$keep = [];
		foreach($players as $p){
			$names[] = $p->getName();
			$keep[strtolower($p->getName())] = true;
		}
		if($this->teamSize === 1){
			$teams->clear();
		}else{
			$teams->filter($keep);
		}
		$teams->autoFill($names, $this->teamSize);

		$radius = (int) $map["radius"];
		$spread = (float) $cfg->getNested("meetup.spread", 0.85);
		$level = $center->getLevel();
		$kit = $cfg->getNested("meetup.kit", "meetup");
		foreach($teams->all() as $team){
			$angle = mt_rand(0, 359) * M_PI / 180;
			$dist = sqrt(mt_rand(0, 1000) / 1000) * $radius * $spread;
			$tx = (int) floor($center->x + cos($angle) * $dist);
			$tz = (int) floor($center->z + sin($angle) * $dist);
			foreach($team->members as $k => $display){
				$p = $this->plugin->getServer()->getPlayerExact($display);
				if($p === null){
					continue;
				}
				$x = $tx + mt_rand(-2, 2);
				$z = $tz + mt_rand(-2, 2);
				$level->loadChunk($x >> 4, $z >> 4, true);
				$y = $level->getHighestBlockAt($x, $z) + 1;
				if($y < 2){
					$y = (int) $center->y;
				}
				Utils::resetPlayer($p, 0);
				$p->teleport(new Position($x + 0.5, $y, $z + 0.5, $level));
				$this->plugin->kits->apply($p, $kit);
				$s = $this->plugin->sessions->get($p);
				$s->state = Session::MEETUP;
				$s->meetupPlayed++;
				$this->alive[$k] = true;
				$this->kills[$k] = 0;
				$lang->send($p, "meetup.teleported", ["map" => $this->mapName]);
				if($team->size() > 1){
					$mates = [];
					foreach($team->members as $d){
						if(strtolower($d) !== $k){
							$mates[] = $d;
						}
					}
					$lang->send($p, "meetup.your-team", ["players" => implode(", ", $mates)]);
				}
			}
		}
		$this->state = self::COUNTDOWN;
		$this->pvpAt = time() + (int) $cfg->getNested("meetup.pvp-delay", 10);
		$this->eliminatedTeams = [];
		$this->plugin->menus->refreshAll();
	}

	public function eliminate(Player $p, $killer, $quit = false){
		$k = strtolower($p->getName());
		if(!isset($this->alive[$k])){
			return;
		}
		unset($this->alive[$k]);
		$lang = $this->plugin->lang;
		$s = $this->plugin->sessions->get($p);
		$pos = Utils::posOf($p);

		if($killer instanceof Player and isset($this->kills[strtolower($killer->getName())])){
			$this->kills[strtolower($killer->getName())]++;
		}

		if($s !== null and $killer instanceof Player){
			$this->plugin->stats->addDeath($s);
		}
		if($killer instanceof Player){
			$ks0 = $this->plugin->sessions->get($killer);
			if($ks0 !== null){
				$this->plugin->stats->addKill($ks0);
			}
		}

		foreach($this->onlineParticipants() as $pl){
			if($killer instanceof Player){
				$lang->send($pl, "meetup.eliminated-by", ["player" => $p->getName(), "killer" => $killer->getName(), "left" => count($this->alive)]);
			}else{
				$lang->send($pl, "meetup.eliminated", ["player" => $p->getName(), "left" => count($this->alive)]);
			}
		}

		if(!$quit){
			if($s !== null){
				$s->state = Session::MEETUP_DEAD;
			}
			Utils::lightning($p, $pos);
			if($killer instanceof Player){
				$ks = $this->plugin->sessions->get($killer);
				if($ks !== null and $ks->settings["lightning-kill"]){
					Utils::lightning($killer, $pos);
				}
			}
			Utils::resetPlayer($p, 3);
			$item = $this->plugin->getConfig()->get("items", [])["to-lobby"];
			$p->getInventory()->setItem((int) $item["slot"], Utils::marked($item["id"], $item["meta"], $item["name"], "to_lobby"));
			$p->getInventory()->sendContents($p);
		}

		$team = $this->plugin->teams->getTeam($p->getName());
		if($team !== null){
			$teamAlive = false;
			foreach($team->members as $mk => $d){
				if(isset($this->alive[$mk])){
					$teamAlive = true;
					break;
				}
			}
			if(!$teamAlive){
				$this->eliminatedTeams[] = $team->id;
			}
		}
		$aliveTeams = [];
		foreach($this->alive as $ak => $_){
			$t = $this->plugin->teams->getTeam($ak);
			$aliveTeams[$t !== null ? $t->id : "solo-" . $ak] = true;
		}
		if(count($aliveTeams) <= 1){
			$ids = array_keys($aliveTeams);
			$this->finish(count($ids) === 1 ? $ids[0] : null);
		}
	}

	public function outsideBorder(Player $p, Vector3 $to){
		if($this->state !== self::COUNTDOWN and $this->state !== self::ACTIVE){
			return false;
		}
		$map = $this->getMap($this->mapName);
		if($map === null){
			return false;
		}
		if($p->getLevel()->getFolderName() !== $map["center"]["world"]){
			return false;
		}
		$r = (int) $map["radius"] + (int) $this->plugin->getConfig()->getNested("meetup.border-buffer", 3);
		$dx = $to->x - $map["center"]["x"];
		$dz = $to->z - $map["center"]["z"];
		return ($dx * $dx + $dz * $dz) > $r * $r;
	}

	private function finish($winnerTeamId){
		$this->state = self::ENDING;
		$this->endAt = time() + (int) $this->plugin->getConfig()->getNested("meetup.return-delay", 15);
		$lang = $this->plugin->lang;
		$teams = $this->plugin->teams->all();

		$rows = [];
		$rank = 1;
		if($winnerTeamId !== null and isset($teams[$winnerTeamId])){
			foreach($teams[$winnerTeamId]->members as $d){
				$rows[] = [1, $d];
			}
		}
		$order = array_reverse($this->eliminatedTeams);
		foreach($order as $tid){
			if($tid === $winnerTeamId or !isset($teams[$tid])){
				continue;
			}
			$rank++;
			foreach($teams[$tid]->members as $d){
				$rows[] = [$rank, $d];
			}
		}

		$out = $lang->lines("meetup.results-header", ["map" => $this->mapName, "mode" => $this->modeName()]);
		$shown = 0;
		foreach($rows as $row){
			if($shown >= 10){
				break;
			}
			$kills = isset($this->kills[strtolower($row[1])]) ? $this->kills[strtolower($row[1])] : 0;
			$key = $row[0] === 1 ? "meetup.result-winner" : "meetup.result-line";
			$out[] = $lang->get($key, ["rank" => $row[0], "player" => $row[1], "kills" => $kills]);
			$shown++;
		}
		foreach($lang->lines("meetup.results-footer") as $l){
			$out[] = $l;
		}
		if($this->plugin->getConfig()->getNested("meetup.broadcast-results-globally", true)){
			foreach($out as $line){
				$this->plugin->getServer()->broadcastMessage($line);
			}
		}else{
			foreach($this->onlineParticipants() as $p){
				foreach($out as $line){
					$p->sendMessage($line);
				}
			}
		}

		if($winnerTeamId !== null and isset($teams[$winnerTeamId])){
			foreach($teams[$winnerTeamId]->members as $d){
				$wsess = $this->plugin->sessions->getByName($d);
				if($wsess !== null){
					$wsess->meetupWins++;
					$this->plugin->stats->touch($wsess);
				}
			}
		}

		$secondNames = [];
		foreach($order as $tid){
			if($tid !== $winnerTeamId and isset($teams[$tid])){
				$secondNames = $teams[$tid]->members;
				break;
			}
		}
		$winnerNames = ($winnerTeamId !== null and isset($teams[$winnerTeamId])) ? $teams[$winnerTeamId]->members : [];
		if(count($winnerNames) > 0){
			$this->plugin->tags->setPodium($winnerNames, $secondNames);
		}
		foreach($this->alive as $k => $_){
			$p = $this->plugin->getServer()->getPlayerExact(isset($this->registered[$k]) ? $this->registered[$k] : $k);
			if($p !== null){
				$p->setHealth($p->getMaxHealth());
			}
		}
	}
}
