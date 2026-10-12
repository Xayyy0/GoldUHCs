<?php
namespace practicecore\manager;

use pocketmine\Player;
use practicecore\model\Session;
use practicecore\PracticeCore;
use practicecore\utils\Lang;
use practicecore\utils\Utils;

class HudManager{
	private $plugin;
	private $counter = 0;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	public function flash(Player $p, $text, $seconds = 1.5){
		$s = $this->plugin->sessions->get($p);
		if($s !== null){
			$s->flash = $text;
			$s->flashUntil = microtime(true) + $seconds;
		}
		$p->sendPopup($text);
	}

	public function tick(){
		$this->counter++;
		$cfg = $this->plugin->getConfig();
		$panelOn = (bool) $cfg->getNested("hud.enabled", true);
		$every = max(1, (int) round(((int) $cfg->getNested("hud.update-ticks", 10)) / 5));
		$now = microtime(true);
		foreach($this->plugin->sessions->all() as $s){
			$p = $this->plugin->getServer()->getPlayerExact($s->name);
			if($p === null){
				continue;
			}

			if($s->state === Session::FFA_DEAD and !$s->deadHandled){
				$this->plugin->ffa->afterDeath($p);
			}

			if($s->flash !== null and $s->flashUntil > $now){
				$p->sendPopup($s->flash);
			}

			if(!$panelOn or empty($s->settings["scoreboard"])){
				if($s->hudLastTip !== ""){
					$s->hudLastTip = "";
					$p->sendTip("");
				}
			}elseif($this->counter % $every === 0){
				$tip = $this->build($p, $s);

				if($tip !== null and ($tip !== $s->hudLastTip or $now - $s->hudLastSent >= 4.0)){
					$s->hudLastTip = $tip;
					$s->hudLastSent = $now;
					$p->sendTip($tip);
				}
			}
		}
	}

	private function keyFor(Session $s){
		switch($s->state){
			case Session::LOBBY: return "lobby";
			case Session::DUEL_QUEUE: return "queue";
			case Session::FFA:
			case Session::FFA_DEAD: return "ffa";
			case Session::DUEL: return "duel";
			case Session::MEETUP_WAIT: return "meetup-wait";
			case Session::MEETUP:
			case Session::MEETUP_DEAD: return "meetup";
		}
		return "lobby";
	}

	private function modeDisplay($mode){
		$modes = $this->plugin->getConfig()->get("modes", []);
		return isset($modes[$mode]["display"]) ? $modes[$mode]["display"] : (string) $mode;
	}

	private function vars(Player $p, Session $s, $t){
		$has = function($k) use ($t){
			return strpos($t, '$' . $k) !== false;
		};
		$meetup = $this->plugin->meetup;
		$v = ["name" => $p->getName(), "nick" => $p->getName()];
		if($has("online")) $v["online"] = count($this->plugin->getServer()->getOnlinePlayers());
		if($has("maxOnline")) $v["maxOnline"] = $this->plugin->getServer()->getMaxPlayers();
		if($has("elo")) $v["elo"] = $s->elo;
		if($has("rank")) $v["rank"] = $this->plugin->stats->rankLabel($s->elo);
		if($has("kills")) $v["kills"] = $s->kills;
		if($has("deaths")) $v["deaths"] = $s->deaths;
		if($has("cps")) $v["cps"] = $s->cps();
		if($has("arena")) $v["arena"] = $s->ffaArena !== null ? $this->plugin->arenas->ffaLabel($s->ffaArena) : "";
		if($has("players")) $v["players"] = $s->ffaArena !== null ? $this->plugin->ffa->count($s->ffaArena) : 0;
		if($has("queue-type")) $v["queue-type"] = $s->queueRanked ? "Ranked" : "Unranked";
		if($has("queue-time")) $v["queue-time"] = $s->queueSince > 0 ? max(0, time() - $s->queueSince) : 0;
		if($has("map")) $v["map"] = $meetup->getMapName();
		if($has("team-mode")) $v["team-mode"] = $meetup->modeName();
		if($has("alive")) $v["alive"] = $meetup->aliveCount();
		if($has("event-kills")) $v["event-kills"] = $meetup->killsOf($s->name);
		if($has("pvp")) $v["pvp"] = $this->plugin->lang->get($meetup->isPvpEnabled() ? "hud.pvp-on" : "hud.pvp-off");
		if($has("registered")) $v["registered"] = $meetup->registeredCount();
		if($has("date")) $v["date"] = date("d/m/y");
		if($has("coins")) $v["coins"] = $this->coins($p);
		if($has("mode") or $has("opponent") or $has("ranked")){
			$v["mode"] = $s->queueMode !== null ? $this->modeDisplay($s->queueMode) : "-";
			$v["opponent"] = "-";
			$v["ranked"] = "-";
			if($s->match !== null){
				$v["opponent"] = $s->match->opponentOf($s->name);
				$v["ranked"] = $s->match->ranked ? "Ranked" : "Unranked";
				$v["mode"] = $this->modeDisplay($s->match->mode);
			}
		}
		return $v;
	}

	private function coins(Player $p){
		$api = $this->plugin->getServer()->getPluginManager()->getPlugin("EconomyAPI");
		if($api !== null and method_exists($api, "myMoney")){
			return number_format($api->myMoney($p), 0, ".", ",");
		}
		return "N/A";
	}

	private function build(Player $p, Session $s){
		$cfg = $this->plugin->getConfig();
		$frames = $cfg->getNested("hud.scoreboard." . $this->keyFor($s), null);
		if(!is_array($frames) or count($frames) === 0){
			return null;
		}
		$frames = array_values($frames);

		$frameSeconds = max(1, (int) $cfg->getNested("hud.frame-seconds", 2));
		$template = (string) $frames[((int) floor(microtime(true) / $frameSeconds)) % count($frames)];

		$pairs = ["{n}" => "\n"];
		foreach($this->vars($p, $s, $template) as $k => $val){
			$pairs['$' . $k] = (string) $val;
		}
		$pairs['$server'] = (string) $cfg->getNested("hud.server-name", "&6Gold&eUHCs");
		$pairs['$center'] = str_repeat(" ", max(0, (int) $cfg->getNested("hud.center", 61)));

		$text = Lang::color(strtr($template, $pairs));

		if($cfg->getNested("hud.align", true)){
			$text = $this->align($text);
		}
		return $text . str_repeat("\n", max(0, (int) $cfg->getNested("hud.bottom-lines", 0)));
	}

	private function align($text){
		$lines = explode("\n", $text);
		$max = 0;
		$lengths = [];
		foreach($lines as $i => $l){
			$plain = Utils::stripColors($l);
			$len = strlen($plain);
			if(trim($plain) !== ""){
				$lengths[$i] = $len;
				if($len > $max){
					$max = $len;
				}
			}
		}
		foreach($lines as $i => $l){
			if(isset($lengths[$i])){
				$lines[$i] = $l . str_repeat(" ", $max - $lengths[$i]);
			}
		}
		return implode("\n", $lines);
	}
}
