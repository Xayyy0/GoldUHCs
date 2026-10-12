<?php
namespace practicecore\manager;

use pocketmine\command\CommandSender;
use practicecore\model\Session;
use practicecore\PracticeCore;

class StatsManager{
	private $plugin;

	private $index = [];
	private $sortedCache = [];

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	public function buildIndex(){
		$this->sortedCache = [];
		$files = glob($this->plugin->sessions->dir() . "*.yml");
		if(!is_array($files) or !function_exists("yaml_parse_file")){
			return;
		}
		foreach($files as $f){
			$d = @yaml_parse_file($f);
			if(!is_array($d) or !isset($d["elo"])){
				continue;
			}
			$key = strtolower(basename($f, ".yml"));
			$this->index[$key] = [
				"name" => isset($d["name"]) ? $d["name"] : $key,
				"elo" => (int) $d["elo"],
				"kills" => isset($d["kills"]) ? (int) $d["kills"] : 0,
				"deaths" => isset($d["deaths"]) ? (int) $d["deaths"] : 0,
				"meetupWins" => isset($d["meetup-wins"]) ? (int) $d["meetup-wins"] : 0
			];
		}
	}

	public function touch(Session $s){
		$this->sortedCache = [];
		$this->index[strtolower($s->name)] = ["name" => $s->name, "elo" => $s->elo, "kills" => $s->kills, "deaths" => $s->deaths, "meetupWins" => $s->meetupWins];
	}

	public function rankLabel($elo){
		$ranks = $this->plugin->getConfig()->get("ranks", []);
		$best = null;
		$bestMin = -1;
		foreach($ranks as $r){
			$min = isset($r["min"]) ? (int) $r["min"] : 0;
			if($min <= $elo and $min >= $bestMin){
				$bestMin = $min;
				$best = $r;
			}
		}
		if($best === null){
			return "&7Unranked";
		}
		return (isset($best["color"]) ? $best["color"] : "&f") . $best["name"];
	}

	public function addKill(Session $killer){
		$killer->kills++;
		$this->touch($killer);
	}

	public function addDeath(Session $victim){
		$victim->deaths++;
		$this->touch($victim);
	}

	public function applyDuel(Session $winner, Session $loser, $ranked, $countKD = true){
		if($countKD){
			$this->addKill($winner);
			$this->addDeath($loser);
		}
		if(!$ranked){
			$winner->unrankedWins++;
			$loser->unrankedLosses++;
			return [0, 0];
		}
		$winner->rankedWins++;
		$loser->rankedLosses++;
		$cfg = $this->plugin->getConfig();
		$k = (int) $cfg->getNested("elo.k-factor", 32);
		$min = (int) $cfg->getNested("elo.min", 0);
		$expected = 1 / (1 + pow(10, ($loser->elo - $winner->elo) / 400));
		$gain = max(1, (int) round($k * (1 - $expected)));
		$loss = max(0, min($gain, $loser->elo - $min));
		$winner->elo += $gain;
		$loser->elo -= $loss;
		$this->touch($winner);
		$this->touch($loser);
		return [$gain, $loss];
	}

	public function sendStats(CommandSender $to, Session $s){
		$lang = $this->plugin->lang;
		$to->sendMessage($lang->get("stats.header", ["player" => $s->name]));
		$to->sendMessage($lang->get("stats.elo", ["elo" => $s->elo, "rank" => $lang->color($this->rankLabel($s->elo))]));
		$kd = $s->deaths > 0 ? number_format($s->kills / $s->deaths, 2) : number_format($s->kills, 2);
		$to->sendMessage($lang->get("stats.kills", ["kills" => $s->kills, "deaths" => $s->deaths, "kd" => $kd]));
		$to->sendMessage($lang->get("stats.ranked", ["wins" => $s->rankedWins, "losses" => $s->rankedLosses]));
		$to->sendMessage($lang->get("stats.unranked", ["wins" => $s->unrankedWins, "losses" => $s->unrankedLosses]));
		$to->sendMessage($lang->get("stats.meetup", ["played" => $s->meetupPlayed, "wins" => $s->meetupWins]));
		$to->sendMessage($lang->get("stats.footer"));
	}

	private function sorted($type){
		if(isset($this->sortedCache[$type])){
			return $this->sortedCache[$type];
		}
		$rows = array_values($this->index);
		usort($rows, function($a, $b) use ($type){
			if($a[$type] === $b[$type]){
				return strcmp(strtolower($a["name"]), strtolower($b["name"]));
			}
			return $b[$type] <=> $a[$type];
		});
		$this->sortedCache[$type] = $rows;
		return $rows;
	}

	public function top($type, $n){
		$out = [];
		foreach($this->sorted($type) as $r){
			if($r[$type] <= 0 or count($out) >= $n){
				break;
			}
			$out[] = $r;
		}
		return $out;
	}

	public function position($type, $name){
		$rows = $this->sorted($type);
		foreach($rows as $i => $r){
			if(strtolower($r["name"]) === strtolower($name)){
				return $i + 1;
			}
		}
		return count($rows) + 1;
	}

	public function sendLeaderboards(CommandSender $to, $name = null){
		$lang = $this->plugin->lang;
		$n = max(1, (int) $this->plugin->getConfig()->getNested("leaderboard.size", 10));
		$to->sendMessage($lang->get("top.header"));
		foreach(["elo", "kills"] as $type){
			$to->sendMessage($lang->get("top.title-" . $type, ["n" => $n]));
			$rows = array_slice($this->sorted($type), 0, $n);
			if(count($rows) === 0){
				$to->sendMessage($lang->get("top.empty"));
				continue;
			}
			foreach($rows as $i => $r){
				if($type === "elo"){
					$to->sendMessage($lang->get("top.line-elo", [
						"pos" => $i + 1, "player" => $r["name"], "value" => $r["elo"],
						"rank" => $lang->color($this->rankLabel($r["elo"]))
					]));
				}else{
					$to->sendMessage($lang->get("top.line-kills", ["pos" => $i + 1, "player" => $r["name"], "value" => $r["kills"]]));
				}
			}
		}
		if($name !== null){
			$to->sendMessage($lang->get("top.you", [
				"elopos" => $this->position("elo", $name),
				"killspos" => $this->position("kills", $name)
			]));
		}
		$to->sendMessage($lang->get("top.footer"));
	}
}
