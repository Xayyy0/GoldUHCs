<?php
namespace practicecore\manager;

use pocketmine\Player;
use practicecore\model\DuelMatch;
use practicecore\model\Session;
use practicecore\PracticeCore;
use practicecore\task\ClosureTask;
use practicecore\utils\Utils;

class DuelManager{
	private $plugin;

	private $queues = [];

	private $matches = [];
	private $nextId = 1;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	public function queuedCount(){
		$n = 0;
		foreach($this->queues as $q){
			$n += count($q);
		}
		return $n;
	}

	public function playingCount(){
		$n = 0;
		foreach($this->matches as $m){
			if($m->state !== DuelMatch::ENDED){
				$n += 2;
			}
		}
		return $n;
	}

	public function queue(Player $p, $mode, $ranked){
		$lang = $this->plugin->lang;
		$s = $this->plugin->sessions->get($p);
		if($s === null){
			return;
		}
		if($s->state !== Session::LOBBY){
			$lang->send($p, "error.busy");
			return;
		}
		$modes = $this->plugin->getConfig()->get("modes", []);
		$mode = strtolower($mode);
		if(!isset($modes[$mode])){
			$lang->send($p, "error.unknown-mode", ["modes" => implode(", ", array_keys($modes))]);
			return;
		}
		$key = ($ranked ? "ranked" : "unranked") . ":" . $mode;
		$s->state = Session::DUEL_QUEUE;
		$s->queueMode = $mode;
		$s->queueRanked = (bool) $ranked;
		$s->queueSince = time();
		$this->queues[$key][] = ["name" => strtolower($p->getName()), "since" => time()];
		$lang->send($p, "duel.queued", ["type" => $ranked ? "Ranked" : "Unranked", "mode" => $modes[$mode]["display"]]);
		$this->plugin->menus->render($p, $s, true);
		$this->tryMatch($key);
	}

	public function leaveQueue(Player $p){
		$s = $this->plugin->sessions->get($p);
		if($s === null){
			return;
		}
		if($s->queueMode !== null){
			$key = ($s->queueRanked ? "ranked" : "unranked") . ":" . $s->queueMode;
			if(isset($this->queues[$key])){
				$name = strtolower($p->getName());
				$this->queues[$key] = array_values(array_filter($this->queues[$key], function($e) use ($name){
					return $e["name"] !== $name;
				}));
			}
			$s->queueMode = null;
		}
		if($s->state === Session::DUEL_QUEUE){
			$s->state = Session::LOBBY;
		}
	}

	private function freeArena(){
		$busy = [];
		foreach($this->matches as $m){
			$busy[strtolower($m->arena)] = true;
		}
		foreach($this->plugin->arenas->allDuel() as $k => $a){
			if(!isset($busy[$k]) and $a["p1"] !== null and $a["p2"] !== null){
				return $a;
			}
		}
		return null;
	}

	private function bestRankedPair(array $q){
		$cfg = $this->plugin->getConfig();
		$range = (int) $cfg->getNested("elo.initial-range", 150);
		$per = (int) $cfg->getNested("elo.range-per-second", 10);
		$now = time();
		$best = null;
		$n = count($q);
		for($i = 0; $i < $n; $i++){
			for($j = $i + 1; $j < $n; $j++){
				$sa = $this->plugin->sessions->getByName($q[$i]["name"]);
				$sb = $this->plugin->sessions->getByName($q[$j]["name"]);
				if($sa === null or $sb === null){
					continue;
				}
				$diff = abs($sa->elo - $sb->elo);
				$wait = min($now - $q[$i]["since"], $now - $q[$j]["since"]);
				if($diff <= $range + $per * $wait and ($best === null or $diff < $best[2])){
					$best = [$i, $j, $diff];
				}
			}
		}
		return $best === null ? null : [$best[0], $best[1]];
	}

	private function tryMatch($key){
		$parts = explode(":", $key, 2);
		$ranked = ($parts[0] === "ranked");
		$mode = $parts[1];
		$server = $this->plugin->getServer();
		while(isset($this->queues[$key]) and count($this->queues[$key]) >= 2){
			$this->queues[$key] = array_values(array_filter($this->queues[$key], function($e) use ($server){
				return $server->getPlayerExact($e["name"]) !== null;
			}));
			if(count($this->queues[$key]) < 2){
				return;
			}
			$pair = $ranked ? $this->bestRankedPair($this->queues[$key]) : [0, 1];
			if($pair === null){
				return;
			}
			$arena = $this->freeArena();
			if($arena === null){
				return;
			}
			$a = $this->queues[$key][$pair[0]];
			$b = $this->queues[$key][$pair[1]];
			unset($this->queues[$key][$pair[1]], $this->queues[$key][$pair[0]]);
			$this->queues[$key] = array_values($this->queues[$key]);
			$pa = $server->getPlayerExact($a["name"]);
			$pb = $server->getPlayerExact($b["name"]);
			if($pa !== null and $pb !== null){
				$this->start($arena, $mode, $pa, $pb, $ranked);
			}
		}
	}

	private function start(array $arena, $mode, Player $pa, Player $pb, $ranked){
		$id = $this->nextId++;
		$match = new DuelMatch($id, $arena["name"], $mode, $pa->getName(), $pb->getName(), $ranked);
		$match->countdown = (int) $this->plugin->getConfig()->getNested("duel.countdown", 5);
		$this->matches[$id] = $match;
		$modes = $this->plugin->getConfig()->get("modes", []);
		$kit = $modes[$mode]["kit"];
		$lang = $this->plugin->lang;
		$type = $ranked ? "Ranked" : "Unranked";
		$sessions = $this->plugin->sessions;
		foreach([[$pa, $arena["p1"], $pb], [$pb, $arena["p2"], $pa]] as $row){
			$p = $row[0];
			$s = $sessions->get($p);
			$os = $sessions->get($row[2]);
			$s->state = Session::DUEL;
			$s->queueMode = null;
			$s->match = $match;
			Utils::resetPlayer($p, 0);
			Utils::teleport($p, $row[1]);
			$this->plugin->kits->apply($p, $kit);
			$lang->send($p, "duel.found", [
				"opponent" => $row[2]->getName(), "type" => $type, "mode" => $modes[$mode]["display"], "arena" => $arena["name"]
			]);
			if($ranked and $os !== null){
				$lang->send($p, "duel.found-elo", ["elo" => $s->elo, "opponent-elo" => $os->elo]);
			}
		}
	}

	public function tick(){
		foreach(array_keys($this->queues) as $key){
			$this->tryMatch($key);
		}
		$lang = $this->plugin->lang;
		foreach($this->matches as $m){
			if($m->state !== DuelMatch::COUNTDOWN){
				continue;
			}
			$players = $this->players($m);
			if($m->countdown <= 0){
				$m->state = DuelMatch::ACTIVE;
				foreach($players as $p){
					$this->plugin->hud->flash($p, $lang->get("duel.fight"));
					$lang->send($p, "duel.fight");
				}
				continue;
			}
			foreach($players as $p){
				$this->plugin->hud->flash($p, $lang->get("duel.countdown", ["seconds" => $m->countdown]));
			}
			$m->countdown--;
		}
	}

	private function players(DuelMatch $m){
		$out = [];
		foreach([$m->p1, $m->p2] as $n){
			$p = $this->plugin->getServer()->getPlayerExact($n);
			if($p !== null){
				$out[] = $p;
			}
		}
		return $out;
	}

	public function handleDeath(Player $victim, $byPlayer = true){
		$s = $this->plugin->sessions->get($victim);
		if($s === null or $s->match === null){
			return;
		}
		$m = $s->match;
		$this->end($m, $m->opponentOf($victim->getName()), $victim->getName(), false, !$byPlayer);
	}

	public function forfeit(Player $quitter){
		$s = $this->plugin->sessions->get($quitter);
		if($s === null or $s->match === null){
			return;
		}
		$m = $s->match;
		$this->end($m, $m->opponentOf($quitter->getName()), $quitter->getName(), true);
	}

	public function abort(Player $leaver){
		$s = $this->plugin->sessions->get($leaver);
		if($s === null or $s->match === null){
			return;
		}
		$m = $s->match;
		$s->match = null;
		if($m->state === DuelMatch::ENDED){
			return;
		}
		$m->state = DuelMatch::ENDED;
		$other = $this->plugin->getServer()->getPlayerExact($m->opponentOf($leaver->getName()));
		if($other !== null){
			$this->plugin->lang->send($other, "duel.aborted", ["player" => $leaver->getName()]);
			$this->plugin->sendToLobby($other);
		}
		$this->removeMatch($m->id);
	}

	private function end(DuelMatch $m, $winnerName, $loserName, $quit = false, $suicide = false){
		if($m->state === DuelMatch::ENDED){
			return;
		}
		$m->state = DuelMatch::ENDED;
		$lang = $this->plugin->lang;
		$server = $this->plugin->getServer();
		$winner = $server->getPlayerExact($winnerName);
		$loser = $server->getPlayerExact($loserName);
		$ws = $this->plugin->sessions->getByName($winnerName);
		$ls = $this->plugin->sessions->getByName($loserName);

		$delay = max(1, (int) $this->plugin->getConfig()->getNested("duel.end-delay", 3)) * 20;
		$plugin = $this->plugin;
		$id = $m->id;
		$plugin->getServer()->getScheduler()->scheduleDelayedTask(new ClosureTask($plugin, function() use ($plugin, $winnerName, $loserName, $id){
			foreach([$winnerName, $loserName] as $n){
				$p = $plugin->getServer()->getPlayerExact($n);
				if($p !== null){
					$s = $plugin->sessions->get($p);
					if($s !== null and $s->match !== null and $s->match->id === $id){
						$plugin->sendToLobby($p);
					}
				}
			}
			$plugin->duels->removeMatch($id);
		}), $delay);

		$gain = 0;
		$loss = 0;
		if($ws !== null and $ls !== null){
			list($gain, $loss) = $this->plugin->stats->applyDuel($ws, $ls, $m->ranked, !$suicide);
		}

		try{
			if($winner !== null){
				$winner->setHealth($winner->getMaxHealth());
				$lang->send($winner, "duel.won", ["loser" => $loserName]);
				if($m->ranked and $ws !== null){
					$lang->send($winner, "duel.elo-gain", ["delta" => $gain, "elo" => $ws->elo]);
				}
				if($loser !== null and $ws !== null and !empty($ws->settings["lightning-kill"])){
					Utils::lightning($winner, Utils::posOf($loser));
				}
			}
			if($loser !== null and !$quit){
				Utils::lightning($loser, Utils::posOf($loser));
				Utils::resetPlayer($loser, 3);
				$lang->send($loser, "duel.lost", ["winner" => $winnerName]);
				if($m->ranked and $ls !== null){
					$lang->send($loser, "duel.elo-loss", ["delta" => $loss, "elo" => $ls->elo]);
				}
			}
		}catch(\Exception $ex){
			$this->plugin->getLogger()->warning("Duel end effects failed: " . $ex->getMessage());
		}catch(\Throwable $ex){
			$this->plugin->getLogger()->warning("Duel end effects failed: " . $ex->getMessage());
		}
	}

	public function removeMatch($id){
		unset($this->matches[$id]);
	}

	public function isFrozen(Session $s){
		return $s->state === Session::DUEL and $s->match !== null and $s->match->state === DuelMatch::COUNTDOWN;
	}
}
