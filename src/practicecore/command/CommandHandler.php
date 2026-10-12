<?php
namespace practicecore\command;

use practicecore\manager\LeaderboardManager;
use practicecore\utils\Lang;
use practicecore\PracticeCore;
use practicecore\model\Session;
use practicecore\model\DuelMatch;
use practicecore\manager\SessionManager;
use practicecore\utils\Utils;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;

class CommandHandler{
	private $plugin;

	private $lang;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
		$this->lang = $plugin->lang;
	}

	public function handle(CommandSender $sender, Command $cmd, array $args){
		switch(strtolower($cmd->getName())){
			case "practice": return $this->practice($sender, $args);
			case "meetup": return $this->meetup($sender, $args);
			case "team": return $this->team($sender, $args);
			case "settings": return $this->settings($sender, $args);
			case "duel": return $this->duel($sender, $args);
			case "lobby": return $this->lobby($sender);
			case "stats": return $this->stats($sender, $args);
			case "top": return $this->top($sender);
		}
		return false;
	}

	private function playerOnly(CommandSender $s){
		if(!($s instanceof Player)){
			$this->lang->send($s, "error.player-only");
			return false;
		}
		return true;
	}

	private function admin(CommandSender $s){
		if(!$s->hasPermission("practice.admin")){
			$this->lang->send($s, "error.no-permission");
			return false;
		}
		return true;
	}

	private function help(CommandSender $s, $key){
		foreach($this->lang->lines($key) as $l){
			$s->sendMessage($l);
		}
	}

	private function practice(CommandSender $s, array $a){
		if(!$this->admin($s)){
			return true;
		}
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		switch($sub){
			case "setlobby":
				if(!$this->playerOnly($s)) return true;
				$this->plugin->getConfig()->set("lobby", Utils::playerPos($s));
				$this->plugin->getConfig()->save();
				$this->lang->send($s, "admin.lobby-set");
				return true;
			case "setprelobby":
				if(!$this->playerOnly($s)) return true;
				$this->plugin->getConfig()->set("meetup-prelobby", Utils::playerPos($s));
				$this->plugin->getConfig()->save();
				$this->lang->send($s, "admin.prelobby-set");
				return true;
			case "arena":
				return $this->arena($s, array_slice($a, 1));
			case "lb":
			case "leaderboard":
				return $this->board($s, array_slice($a, 1));
			case "kb":
				return $this->kbCommand($s, array_slice($a, 1));
			case "hud":
				return $this->hud($s, array_slice($a, 1));
			case "reload":
				$this->plugin->getConfig()->reload();
				$this->plugin->kits->reload();
				$this->lang->send($s, "admin.reloaded");
				return true;
		}
		$this->help($s, "help.practice");
		return true;
	}

	private function arena(CommandSender $s, array $a){
		if(!$this->playerOnly($s)) return true;
		$arenas = $this->plugin->arenas;
		$type = isset($a[0]) ? strtolower($a[0]) : "";
		if($type === "list"){
			$s->sendMessage($this->lang->get("arena.list-ffa"));
			foreach($arenas->allFfa() as $f){
				$s->sendMessage($this->lang->get("arena.list-line", ["name" => $f["name"], "info" => $f["mode"] . ", " . count($f["spawns"]) . " spawn(s)"]));
			}
			$s->sendMessage($this->lang->get("arena.list-duel"));
			foreach($arenas->allDuel() as $d){
				$ok = ($d["p1"] !== null and $d["p2"] !== null) ? "ready" : "positions missing";
				$s->sendMessage($this->lang->get("arena.list-line", ["name" => $d["name"], "info" => $ok]));
			}
			return true;
		}
		if($type === "ffa"){
			$act = isset($a[1]) ? strtolower($a[1]) : "";
			if($act === "create" and isset($a[3])){
				$modes = $this->plugin->getConfig()->get("modes", []);
				$mode = strtolower($a[3]);
				if(!isset($modes[$mode])){
					$this->lang->send($s, "error.unknown-mode", ["modes" => implode(", ", array_keys($modes))]);
					return true;
				}
				$this->lang->send($s, $arenas->createFfa($a[2], $mode, $s) ? "arena.ffa-created" : "arena.exists", ["arena" => $a[2]]);
				return true;
			}
			if($act === "addspawn" and isset($a[2])){
				$this->lang->send($s, $arenas->addFfaSpawn($a[2], $s) ? "arena.spawn-added" : "arena.not-found", ["arena" => $a[2]]);
				return true;
			}
		}
		if($type === "duel"){
			$act = isset($a[1]) ? strtolower($a[1]) : "";
			if($act === "create" and isset($a[2])){
				$this->lang->send($s, $arenas->createDuel($a[2]) ? "arena.duel-created" : "arena.exists", ["arena" => $a[2]]);
				return true;
			}
			if($act === "setpos" and isset($a[3]) and in_array($a[3], ["1", "2"], true)){
				$this->lang->send($s, $arenas->setDuelPos($a[2], $a[3], $s) ? "arena.pos-set" : "arena.not-found", ["arena" => $a[2], "n" => $a[3]]);
				return true;
			}
		}
		if($type === "delete" and isset($a[2]) and in_array(strtolower($a[1]), ["ffa", "duel"], true)){
			$this->lang->send($s, $arenas->delete(strtolower($a[1]), $a[2]) ? "arena.deleted" : "arena.not-found", ["arena" => $a[2]]);
			return true;
		}
		$this->help($s, "help.arena");
		return true;
	}

	private function board(CommandSender $s, array $a){
		if(!$this->playerOnly($s)) return true;
		$boards = $this->plugin->boards;
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		if($sub === "create" and isset($a[1])){
			$type = LeaderboardManager::normalizeType($a[1]);
			if($type === null){
				$this->lang->send($s, "lb.unknown-type");
				return true;
			}
			$boards->create($type, $s);
			$this->lang->send($s, "lb.created");
			return true;
		}
		if($sub === "remove"){
			$this->lang->send($s, $boards->removeNearest($s) ? "lb.removed" : "lb.none-near");
			return true;
		}
		if($sub === "list"){
			foreach($boards->all() as $id => $d){
				$s->sendMessage($this->lang->get("lb.list-line", ["id" => $id, "type" => $d["type"], "world" => $d["pos"]["world"]]));
			}
			return true;
		}
		$this->help($s, "help.lb");
		return true;
	}

	private function kbCommand(CommandSender $s, array $a){
		$kb = $this->plugin->kb;
		if(count($a) === 0){
			foreach($kb->keys() as $k){
				$v = $kb->get($k);
				$s->sendMessage($this->lang->get("kb.line", ["key" => $k, "h" => $v[0], "v" => $v[1], "delay" => $v[2]]));
			}
			$this->help($s, "help.kb");
			return true;
		}
		if(count($a) < 3 or !is_numeric($a[1]) or !is_numeric($a[2])){
			$this->help($s, "help.kb");
			return true;
		}
		$key = strtolower($a[0]);
		$modes = $this->plugin->getConfig()->get("modes", []);
		if($key !== "meetup" and $key !== "default" and !isset($modes[$key])){
			$this->lang->send($s, "error.unknown-mode", ["modes" => implode(", ", array_merge(array_keys($modes), ["meetup", "default"]))]);
			return true;
		}
		$kb->set($key, $a[1], $a[2], isset($a[3]) && is_numeric($a[3]) ? $a[3] : null);
		$this->lang->send($s, "kb.set", ["key" => $key, "h" => $a[1], "v" => $a[2]]);
		return true;
	}

	private function meetup(CommandSender $s, array $a){
		$m = $this->plugin->meetup;
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		switch($sub){
			case "schedule":
				if(!$this->admin($s)) return true;
				if(count($a) < 4){
					$this->help($s, "help.meetup");
					return true;
				}
				$secs = Utils::parseTime($a[1]);
				$sizes = ["solo" => 1, "duo" => 2, "trio" => 3];
				if($secs === null or $secs < 1){
					$this->lang->send($s, "meetup.bad-time");
					return true;
				}
				if(!isset($sizes[strtolower($a[3])])){
					$this->lang->send($s, "meetup.bad-mode");
					return true;
				}
				$err = $m->schedule($secs, $a[2], $sizes[strtolower($a[3])]);
				if($err !== null){
					$this->lang->send($s, $err, ["maps" => implode(", ", $m->mapNames())]);
				}
				return true;
			case "cancel":
				if(!$this->admin($s)) return true;
				if(!$m->cancel()){
					$this->lang->send($s, "meetup.none-scheduled");
				}
				return true;
			case "join":
				if($this->playerOnly($s)) $m->join($s);
				return true;
			case "leave":
				if($this->playerOnly($s)) $m->leave($s);
				return true;
			case "status":
				foreach($m->statusLines() as $l){
					$s->sendMessage($l);
				}
				return true;
			case "map":
				if(!$this->admin($s)) return true;
				$act = isset($a[1]) ? strtolower($a[1]) : "";
				if($act === "set" and isset($a[2]) and $this->playerOnly($s)){
					$radius = isset($a[3]) && is_numeric($a[3]) ? (int) $a[3] : 100;
					$m->setMap($a[2], $s, $radius);
					$this->lang->send($s, "meetup.map-set", ["map" => $a[2], "radius" => $radius]);
					return true;
				}
				if($act === "delete" and isset($a[2])){
					$this->lang->send($s, $m->deleteMap($a[2]) ? "meetup.map-deleted" : "meetup.map-not-found", ["map" => $a[2], "maps" => implode(", ", $m->mapNames())]);
					return true;
				}
				if($act === "list"){
					$this->lang->send($s, "meetup.map-list", ["maps" => implode(", ", $m->mapNames())]);
					return true;
				}
				break;
		}
		$this->help($s, "help.meetup");
		return true;
	}

	private function team(CommandSender $s, array $a){
		if(!$this->playerOnly($s)) return true;
		$t = $this->plugin->teams;
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		switch($sub){
			case "invite":
				if(!isset($a[1])){
					break;
				}
				$target = $this->plugin->getServer()->getPlayer($a[1]);
				if($target === null){
					$this->lang->send($s, "error.player-not-found", ["player" => $a[1]]);
					return true;
				}
				$t->invite($s, $target);
				return true;
			case "accept":
				$t->accept($s, isset($a[1]) ? $a[1] : null);
				return true;
			case "deny":
				$t->deny($s, isset($a[1]) ? $a[1] : null);
				return true;
			case "leave":
				if($this->plugin->meetup->isRunning()){
					$this->lang->send($s, "team.cannot-leave-running");
					return true;
				}
				$this->lang->send($s, $t->leave($s->getName()) ? "team.you-left" : "team.not-in-team");
				return true;
			case "list":
				$team = $t->getTeam($s->getName());
				if($team === null){
					$this->lang->send($s, "team.not-in-team");
					return true;
				}
				$this->lang->send($s, "team.list", ["players" => implode(", ", $team->members), "leader" => $team->leader]);
				return true;
		}
		$this->help($s, "help.team");
		return true;
	}

	private function settings(CommandSender $s, array $a){
		if(!$this->playerOnly($s)) return true;
		if(!isset($a[0])){
			$this->plugin->sendSettings($s);
			return true;
		}
		$key = strtolower($a[0]);
		if(is_numeric($key)){
			$i = (int) $key - 1;
			$key = isset(SessionManager::$SETTINGS[$i]) ? SessionManager::$SETTINGS[$i] : "";
		}
		$aliases = ["autosprint" => "auto-sprint", "sprint" => "auto-sprint", "autorespawn" => "auto-respawn", "respawn" => "auto-respawn",
			"lightning" => "lightning-kill", "lightningkill" => "lightning-kill", "cps" => "cps-counter",
			"scoreboard" => "scoreboard", "board" => "scoreboard", "sb" => "scoreboard", "hud" => "scoreboard"];
		if(isset($aliases[$key])){
			$key = $aliases[$key];
		}
		if(!in_array($key, SessionManager::$SETTINGS, true)){
			$this->plugin->sendSettings($s);
			return true;
		}
		$this->plugin->toggleSetting($s, $key);
		return true;
	}

	private function duel(CommandSender $s, array $a){
		if(!$this->playerOnly($s)) return true;
		$d = $this->plugin->duels;
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		$modes = $this->plugin->getConfig()->get("modes", []);
		switch($sub){
			case "queue":
			case "join":
				if(!isset($a[1])){
					break;
				}
				$d->queue($s, $a[1], isset($a[2]) && strtolower($a[2]) === "ranked");
				return true;
			case "leave":
				$ses = $this->plugin->sessions->get($s);
				if($ses !== null and $ses->state === Session::DUEL_QUEUE){
					$d->leaveQueue($s);
					$this->lang->send($s, "duel.left-queue");
					$this->plugin->menus->show($s, "main");
				}else{
					$this->lang->send($s, "duel.not-queued");
				}
				return true;
			case "modes":
				$this->lang->send($s, "duel.modes", ["modes" => implode(", ", array_keys($modes))]);
				return true;
		}
		$this->help($s, "help.duel");
		return true;
	}

	private function stats(CommandSender $s, array $a){
		if(isset($a[0])){
			$target = $this->plugin->sessions->getByName($a[0]);
			if($target === null){
				$target = $this->plugin->sessions->read($a[0], false);
			}
			if($target === null){
				$this->lang->send($s, "stats.not-found", ["player" => $a[0]]);
				return true;
			}
			$this->plugin->stats->sendStats($s, $target);
			return true;
		}
		if(!$this->playerOnly($s)) return true;
		$this->plugin->stats->sendStats($s, $this->plugin->sessions->get($s));
		return true;
	}

	private function top(CommandSender $s){
		$this->plugin->stats->sendLeaderboards($s, $s instanceof Player ? $s->getName() : null);
		return true;
	}

	private function hud(CommandSender $s, array $a){
		$cfg = $this->plugin->getConfig();
		$hud = $cfg->get("hud", []);
		$sub = isset($a[0]) ? strtolower($a[0]) : "";
		if(($sub === "center" or $sub === "indent" or $sub === "lines") and isset($a[1]) and is_numeric($a[1])){
			$key = $sub === "lines" ? "bottom-lines" : "center";
			$hud[$key] = max(0, (int) $a[1]);
			$cfg->set("hud", $hud);
			$cfg->save();
			$this->lang->send($s, "hud.set", ["key" => $key, "value" => $hud[$key]]);
			return true;
		}
		if($sub === "on" or $sub === "off"){
			$hud["enabled"] = ($sub === "on");
			$cfg->set("hud", $hud);
			$cfg->save();
			$this->lang->send($s, "hud.set", ["key" => "enabled", "value" => $sub]);
			return true;
		}
		$this->help($s, "help.hud");
		return true;
	}

	private function lobby(CommandSender $s){
		if(!$this->playerOnly($s)) return true;
		$ses = $this->plugin->sessions->get($s);
		if($ses === null){
			return true;
		}
		$duelOver = ($ses->state === Session::DUEL && $ses->match !== null && $ses->match->state === DuelMatch::ENDED);
		if(($ses->state === Session::DUEL and !$duelOver) or $ses->state === Session::MEETUP){
			$this->lang->send($s, "error.cannot-leave-fight");
			return true;
		}
		if($ses->state === Session::DUEL_QUEUE){
			$this->plugin->duels->leaveQueue($s);
		}
		if($ses->state === Session::MEETUP_WAIT){
			$this->plugin->meetup->leave($s);
			return true;
		}
		$this->plugin->sendToLobby($s);
		return true;
	}
}
