<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\model\Session;
use practicecore\utils\Utils;

use practicecore\task\ClosureTask;

use pocketmine\entity\Effect;
use pocketmine\Player;

class FFAManager{
	private $plugin;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	public function count($arena){
		$n = 0;
		foreach($this->plugin->sessions->all() as $s){
			if(($s->state === Session::FFA or $s->state === Session::FFA_DEAD) and strtolower((string) $s->ffaArena) === strtolower($arena)){
				$n++;
			}
		}
		return $n;
	}

	public function playersIn($arena){
		$out = [];
		foreach($this->plugin->sessions->all() as $s){
			if($s->state === Session::FFA and strtolower((string) $s->ffaArena) === strtolower($arena)){
				$p = $this->plugin->getServer()->getPlayerExact($s->name);
				if($p !== null){
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	public function join(Player $p, $arenaName){
		$lang = $this->plugin->lang;
		$s = $this->plugin->sessions->get($p);
		if($s === null){
			return false;
		}
		if($s->state !== Session::LOBBY and $s->state !== Session::FFA_DEAD){
			$lang->send($p, "error.busy");
			return false;
		}
		$arena = $this->plugin->arenas->getFfa($arenaName);
		if($arena === null){
			$lang->send($p, "ffa.no-arena", ["arena" => $arenaName]);
			return false;
		}
		$spawn = $this->plugin->arenas->randomFfaSpawn($arenaName);
		$modes = $this->plugin->getConfig()->get("modes", []);
		$kit = isset($modes[$arena["mode"]]["kit"]) ? $modes[$arena["mode"]]["kit"] : $arena["mode"];
		Utils::resetPlayer($p, 0);
		if($spawn === null or !Utils::teleport($p, $spawn)){
			$lang->send($p, "ffa.spawn-error");
			$this->plugin->sendToLobby($p);
			return false;
		}
		$this->plugin->kits->apply($p, $kit);
		$s->state = Session::FFA;
		$s->ffaArena = $arena["name"];
		$s->lastDamager = null;
		$this->clearTag($s);
		$lang->send($p, "ffa.joined", ["arena" => $this->plugin->arenas->ffaLabel($arena["name"])]);
		return true;
	}

	public function respawn(Player $p){
		$s = $this->plugin->sessions->get($p);
		if($s === null or $s->state !== Session::FFA_DEAD or $s->ffaArena === null){
			return;
		}
		$this->join($p, $s->ffaArena);
	}

	public function leave(Player $p){
		$s = $this->plugin->sessions->get($p);
		if($s !== null){
			$s->ffaArena = null;
			$s->lastDamager = null;
			$this->clearTag($s);
		}
	}

	public function refillKit(Player $killer){
		if(!$this->plugin->getConfig()->getNested("ffa.refill-kit-on-kill", true) or !$killer->isAlive()){
			return;
		}
		$ks = $this->plugin->sessions->get($killer);
		if($ks === null or $ks->state !== Session::FFA or $ks->ffaArena === null){
			return;
		}
		$arena = $this->plugin->arenas->getFfa($ks->ffaArena);
		if($arena === null){
			return;
		}
		$modes = $this->plugin->getConfig()->get("modes", []);
		$kit = isset($modes[$arena["mode"]]["kit"]) ? $modes[$arena["mode"]]["kit"] : $arena["mode"];
		$this->plugin->kits->apply($killer, $kit);
		$killer->getInventory()->sendContents($killer);
		$killer->getInventory()->sendArmorContents($killer);
	}

	public function tagEnabled(){
		return (bool) $this->plugin->getConfig()->getNested("combat-tag.enabled", true);
	}

	public function clearTag(Session $s){
		$s->tagWith = null;
		$s->tagUntil = 0;
	}

	public function activeTag(Session $s){
		if($s->state !== Session::FFA or $s->tagWith === null){
			return null;
		}
		if($s->tagUntil <= time()){
			$this->clearTag($s);
			return null;
		}
		$ps = $this->plugin->sessions->getByName($s->tagWith);
		$pp = $this->plugin->getServer()->getPlayerExact($s->tagWith);
		if($ps === null or $pp === null or $ps->state !== Session::FFA
			or strtolower((string) $ps->ffaArena) !== strtolower((string) $s->ffaArena)){
			$this->clearTag($s);
			return null;
		}
		return $pp->getName();
	}

	public function tag(Session $a, Session $b){
		$secs = max(1, (int) $this->plugin->getConfig()->getNested("combat-tag.seconds", 15));
		$until = time() + $secs;
		$a->tagWith = $b->name;
		$a->tagUntil = $until;
		$b->tagWith = $a->name;
		$b->tagUntil = $until;
	}

	public function combatLog(Player $quitter){
		$s = $this->plugin->sessions->get($quitter);
		if($s === null or $s->state !== Session::FFA or !$this->tagEnabled()){
			return;
		}
		$partnerName = $this->activeTag($s);
		if($partnerName === null){
			return;
		}
		$killer = $this->plugin->getServer()->getPlayerExact($partnerName);
		$ks = $this->plugin->sessions->getByName($partnerName);
		if($killer === null or $ks === null){
			return;
		}
		$lang = $this->plugin->lang;
		$arena = $s->ffaArena;
		$pos = Utils::posOf($quitter);
		$this->clearTag($s);
		$this->clearTag($ks);
		$this->plugin->stats->addDeath($s);
		$this->plugin->stats->addKill($ks);
		try{
			if(!empty($ks->settings["lightning-kill"])){
				Utils::lightning($killer, $pos);
			}
			if($this->plugin->getConfig()->getNested("ffa.heal-on-kill", true) and $killer->isAlive()){
				$killer->setHealth($killer->getMaxHealth());
				$killer->setFood(20);
			}
			$this->refillKit($killer);
			$lang->send($killer, "ffa.you-killed", ["victim" => $quitter->getName()]);
			if($this->plugin->getConfig()->getNested("ffa.broadcast-deaths", true)){
				foreach($this->playersIn($arena) as $pl){
					if($pl !== $quitter){
						$lang->send($pl, "ffa.combat-log", ["victim" => $quitter->getName(), "killer" => $killer->getName()]);
					}
				}
			}
		}catch(\Exception $ex){
			$this->plugin->getLogger()->warning("Combat log effects failed: " . $ex->getMessage());
		}
	}

	public function handleDeath(Player $victim, $killer){
		$lang = $this->plugin->lang;
		$s = $this->plugin->sessions->get($victim);
		if($s === null){
			return;
		}
		$arena = $s->ffaArena;
		$pos = Utils::posOf($victim);
		$s->state = Session::FFA_DEAD;
		$s->deadHandled = false;

		if($killer instanceof Player){
			$this->plugin->stats->addDeath($s);
		}
		$this->clearTag($s);
		if($killer instanceof Player){
			$kts = $this->plugin->sessions->get($killer);
			if($kts !== null and strtolower((string) $kts->tagWith) === strtolower($victim->getName())){
				$this->clearTag($kts);
			}
		}

		$plugin = $this->plugin;
		$plugin->getServer()->getScheduler()->scheduleDelayedTask(new ClosureTask($plugin, function() use ($plugin, $victim){
			$plugin->ffa->afterDeath($victim);
		}), 1);

		try{
			Utils::lightning($victim, $pos);

			if($killer instanceof Player){
				$ks = $this->plugin->sessions->get($killer);
				if($ks !== null){
					$this->plugin->stats->addKill($ks);
					if(!empty($ks->settings["lightning-kill"])){
						Utils::lightning($killer, $pos);
					}
				}
				if($this->plugin->getConfig()->getNested("ffa.heal-on-kill", true) and $killer->isAlive()){
					$killer->setHealth($killer->getMaxHealth());
					$killer->setFood(20);
				}
				$this->refillKit($killer);
				$lang->send($killer, "ffa.you-killed", ["victim" => $victim->getName()]);
			}

			if($this->plugin->getConfig()->getNested("ffa.broadcast-deaths", true)){
				$msg = $killer instanceof Player
					? ["ffa.death-killed", ["victim" => $victim->getName(), "killer" => $killer->getName()]]
					: ["ffa.death", ["victim" => $victim->getName()]];
				foreach($this->playersIn($arena) as $pl){
					$lang->send($pl, $msg[0], $msg[1]);
				}
				$lang->send($victim, $msg[0], $msg[1]);
			}
		}catch(\Exception $ex){
			$this->plugin->getLogger()->warning("FFA death effects failed: " . $ex->getMessage());
		}catch(\Throwable $ex){
			$this->plugin->getLogger()->warning("FFA death effects failed: " . $ex->getMessage());
		}
	}

	public function afterDeath(Player $p){
		if(!$p->isOnline() or !$p->isAlive()){
			return;
		}
		$s = $this->plugin->sessions->get($p);
		if($s === null or $s->state !== Session::FFA_DEAD or $s->deadHandled){
			return;
		}
		$s->deadHandled = true;
		$arena = $s->ffaArena;
		try{
			if(!empty($s->settings["auto-respawn"]) and $arena !== null){
				if($this->join($p, $arena)){
					return;
				}

				if($s->state === Session::FFA_DEAD){
					$this->enterSpectator($p);
				}
				return;
			}
			$this->enterSpectator($p);
		}catch(\Exception $ex){
			$this->fail($p, $ex);
		}catch(\Throwable $ex){
			$this->fail($p, $ex);
		}
	}

	private function fail(Player $p, $ex){
		$this->plugin->getLogger()->error("FFA respawn failed for " . $p->getName() . ": " . $ex->getMessage()
			. " (" . basename($ex->getFile()) . ":" . $ex->getLine() . ")");

		try{
			$this->plugin->sendToLobby($p);
		}catch(\Exception $e2){
		}
	}

	public function enterSpectator(Player $p){
		$cfg = $this->plugin->getConfig();

		Utils::resetPlayer($p, 2);
		$invisible = Effect::getEffect(Effect::INVISIBILITY);
		if($invisible !== null){
			$p->addEffect($invisible->setDuration(20 * 3600)->setAmplifier(0)->setVisible(false));
		}
		$items = $cfg->get("items", []);
		$r = $items["ffa-respawn"];
		$l = $items["to-lobby"];
		$p->getInventory()->setItem((int) $r["slot"], Utils::marked($r["id"], $r["meta"], $r["name"], "ffa_respawn"));
		$p->getInventory()->setItem((int) $l["slot"], Utils::marked($l["id"], $l["meta"], $l["name"], "to_lobby"));
		$p->getInventory()->sendContents($p);
		$this->plugin->lang->send($p, "ffa.spectator");
	}
}
