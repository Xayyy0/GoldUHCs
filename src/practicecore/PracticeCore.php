<?php
namespace practicecore;

use practicecore\manager\ArenaManager;
use practicecore\task\ClosureTask;
use practicecore\command\CommandHandler;
use practicecore\task\HudTask;
use practicecore\manager\DuelManager;
use practicecore\listener\EventListener;
use practicecore\manager\FFAManager;
use practicecore\manager\KitManager;
use practicecore\manager\KnockbackManager;
use practicecore\utils\Lang;
use practicecore\manager\HudManager;
use practicecore\manager\MeetupManager;
use practicecore\manager\MenuManager;
use practicecore\manager\LeaderboardManager;
use practicecore\task\SecondTask;
use practicecore\task\SprintTask;
use practicecore\model\Session;
use practicecore\manager\SessionManager;
use practicecore\manager\StatsManager;
use practicecore\manager\TeamManager;
use practicecore\manager\TagManager;
use practicecore\utils\Utils;

use pocketmine\command\Command;
use pocketmine\network\protocol\LevelEventPacket;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class PracticeCore extends PluginBase{
	private static $instance;

	public $lang;

	public $sessions;

	public $kits;

	public $kb;

	public $arenas;

	public $boards;

	public $ffa;

	public $duels;

	public $teams;

	public $meetup;

	public $stats;

	public $menus;

	public $tags;

	public $hud;

	private $commands;

	public static function getInstance(){
		return self::$instance;
	}

	public function onEnable(){
		@mkdir($this->getDataFolder());
		$this->saveDefaultConfig();
		self::$instance = $this;
		$this->migrateGappleToUhc();

		$this->lang = new Lang($this);
		$this->sessions = new SessionManager($this);
		$this->stats = new StatsManager($this);
		$this->stats->buildIndex();
		$this->kits = new KitManager($this);
		$this->kb = new KnockbackManager($this);
		$this->arenas = new ArenaManager($this);
		$this->ffa = new FFAManager($this);
		$this->duels = new DuelManager($this);
		$this->teams = new TeamManager($this);
		$this->meetup = new MeetupManager($this);
		$this->tags = new TagManager($this);
		$this->boards = new LeaderboardManager($this);
		$this->menus = new MenuManager($this);
		$this->hud = new HudManager($this);
		$this->commands = new CommandHandler($this);

		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new SecondTask($this), 20);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new HudTask($this), 5);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new SprintTask($this), 2);

		foreach($this->getServer()->getOnlinePlayers() as $p){
			$this->sessions->open($p);
			$this->sendToLobby($p);
		}
		$this->enforceWorld(true);
		$this->getLogger()->info("GoldUHCs enabled.");
	}

	private function migrateGappleToUhc(){
		$rename = function(array $a){
			$out = [];
			foreach($a as $k => $v){
				$out[$k === "gapple" ? "uhc" : $k] = $v;
			}
			return $out;
		};

		$cfg = $this->getConfig();
		$changed = false;
		$modes = $cfg->get("modes", []);
		if(isset($modes["gapple"]) and !isset($modes["uhc"])){
			$modes["gapple"]["display"] = "UHC";
			if(isset($modes["gapple"]["kit"]) and $modes["gapple"]["kit"] === "gapple"){
				$modes["gapple"]["kit"] = "uhc";
			}
			$cfg->set("modes", $rename($modes));
			$changed = true;
		}
		$kb = $cfg->get("knockback", []);
		if(isset($kb["gapple"]) and !isset($kb["uhc"])){
			$cfg->set("knockback", $rename($kb));
			$changed = true;
		}
		if($changed){
			$cfg->save();
		}

		$this->saveResource("kits.yml");
		$kitsFile = new Config($this->getDataFolder() . "kits.yml", Config::YAML);
		$kits = $kitsFile->get("kits", []);
		if(isset($kits["gapple"]) and !isset($kits["uhc"])){
			$kitsFile->set("kits", $rename($kits));
			$kitsFile->save();
		}

		$arenasFile = new Config($this->getDataFolder() . "arenas.yml", Config::YAML, ["ffa" => [], "duel" => []]);
		$ffa = $arenasFile->get("ffa", []);
		$arenasChanged = false;
		foreach($ffa as $k => $arena){
			if(isset($arena["mode"]) and $arena["mode"] === "gapple"){
				$ffa[$k]["mode"] = "uhc";
				$arenasChanged = true;
			}
		}
		if($arenasChanged){
			$arenasFile->set("ffa", $ffa);
			$arenasFile->save();
		}
	}

	public function onDisable(){
		if($this->sessions !== null){
			$this->sessions->saveAll();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args){
		return $this->commands->handle($sender, $command, $args);
	}

	public function registerClick(Player $p){
		$s = $this->sessions->get($p);
		if($s === null){
			return;
		}
		$s->click();
		$now = microtime(true);

		if(!empty($s->settings["cps-counter"])
			and $now - $s->lastCpsPopup >= 0.2
			and $s->state !== Session::FFA_DEAD and $s->state !== Session::MEETUP_DEAD
			and !($s->flash !== null and $s->flashUntil > $now)
		){
			$s->lastCpsPopup = $now;
			$p->sendPopup($this->lang->get("cps.tip", ["cps" => $s->cps()]));
		}
	}

	private $dayLevels = [];

	public function enforceWorld($weather = false){
		$cfg = $this->getConfig();
		if($cfg->getNested("world.always-day", true)){
			$time = (int) $cfg->getNested("world.time", 6000);
			foreach($this->getServer()->getLevels() as $level){
				$id = $level->getId();
				if(!isset($this->dayLevels[$id]) or $level->getTime() !== $time){
					$level->setTime($time);
					$level->stopTime();
					$this->dayLevels[$id] = true;
				}
			}
		}
		if($weather and $cfg->getNested("world.no-rain", true)){
			foreach($this->getServer()->getOnlinePlayers() as $p){
				$this->clearWeather($p);
			}
		}
	}

	public function clearWeather(Player $p){
		foreach([3003, 3004] as $event){
			$pk = new LevelEventPacket();
			$pk->evid = $event;
			$pk->x = 0;
			$pk->y = 0;
			$pk->z = 0;
			$pk->data = 0;
			$p->dataPacket($pk);
		}
	}

	public function sendToLobby(Player $p){
		$s = $this->sessions->get($p);
		if($s === null){
			return;
		}
		$s->state = Session::LOBBY;
		$s->ffaArena = null;
		$s->match = null;
		$s->queueMode = null;
		$s->lastDamager = null;
		$s->tagWith = null;
		$s->tagUntil = 0;
		$s->menu = "main";
		$s->menuPage = 0;
		Utils::resetPlayer($p, 0);
		$lobby = $this->getConfig()->get("lobby", null);
		if($lobby !== null){
			Utils::teleport($p, $lobby);
		}else{
			$p->teleport($p->getServer()->getDefaultLevel()->getSafeSpawn());
		}
		$this->tags->apply($p);
		$this->menus->render($p, $s, true);
	}

	public function sendSettings(Player $p){
		$s = $this->sessions->get($p);
		if($s === null){
			return;
		}
		$p->sendMessage($this->lang->get("settings.header"));
		$i = 1;
		foreach(SessionManager::$SETTINGS as $key){
			$p->sendMessage($this->lang->get("settings.line", [
				"id" => $i++,
				"name" => $this->lang->get("settings.name." . $key),
				"state" => $this->lang->get($s->settings[$key] ? "settings.on" : "settings.off")
			]));
		}
		$p->sendMessage($this->lang->get("settings.usage"));
	}

	public function toggleSetting(Player $p, $key){
		$s = $this->sessions->get($p);
		if($s === null){
			return;
		}
		$s->settings[$key] = !$s->settings[$key];
		$this->lang->send($p, "settings.changed", [
			"name" => $this->lang->get("settings.name." . $key),
			"state" => $this->lang->get($s->settings[$key] ? "settings.on" : "settings.off")
		]);
		if($key === "auto-sprint" and !$s->settings[$key] and $p->isSprinting()){
			$p->setSprinting(false);
		}
		$this->sessions->save($s);
	}
}
