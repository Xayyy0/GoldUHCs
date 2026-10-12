<?php
namespace practicecore\listener;

use practicecore\task\ClosureTask;
use practicecore\model\DuelMatch;
use practicecore\entity\PracticePlayer;
use practicecore\PracticeCore;
use practicecore\model\Session;
use practicecore\utils\Utils;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\Player;

class EventListener implements Listener{
	private $plugin;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	private function session(Player $p){
		return $this->plugin->sessions->get($p);
	}

	private function isBuilder(Player $p){
		return $p->hasPermission("practice.admin") and $p->isCreative();
	}

	public function onCreation(PlayerCreationEvent $e){
		$e->setPlayerClass(PracticePlayer::class);
	}

	public function onJoin(PlayerJoinEvent $e){
		$p = $e->getPlayer();
		$cfg = $this->plugin->getConfig();
		$lang = $this->plugin->lang;
		$s = $this->plugin->sessions->open($p);
		$e->setJoinMessage("");
		$this->plugin->sendToLobby($p);
		$this->plugin->clearWeather($p);

		if($cfg->get("join-message-enabled", true)){
			$this->plugin->getServer()->broadcastMessage($lang->get("join.format", ["player" => $p->getName()]));
		}
		if($cfg->get("welcome-enabled", true) and !$s->welcomed and !$p->hasPlayedBefore()){
			foreach($lang->lines("welcome", ["player" => $p->getName(), "online" => count($this->plugin->getServer()->getOnlinePlayers())]) as $line){
				$p->sendMessage($line);
			}
		}
		$s->welcomed = true;
	}

	public function onLevelChange(EntityLevelChangeEvent $e){
		$p = $e->getEntity();
		if($p instanceof Player){
			$this->plugin->boards->hideAll($p);
		}
	}

	public function onQuit(PlayerQuitEvent $e){
		$p = $e->getPlayer();
		$this->plugin->boards->forget($p);
		$cfg = $this->plugin->getConfig();
		$e->setQuitMessage("");
		$s = $this->session($p);
		if($s !== null){
			switch($s->state){
				case Session::DUEL:
					$this->plugin->duels->forfeit($p);
					break;
				case Session::DUEL_QUEUE:
					$this->plugin->duels->leaveQueue($p);
					break;
				case Session::FFA:

					$this->plugin->ffa->combatLog($p);
					break;
			}
			$this->plugin->meetup->onQuit($p);
			$this->plugin->sessions->close($p);
		}
		if($cfg->get("quit-message-enabled", true)){
			$this->plugin->getServer()->broadcastMessage($this->plugin->lang->get("quit.format", ["player" => $p->getName()]));
		}
	}

	public function onDamage(EntityDamageEvent $e){
		$entity = $e->getEntity();

		if(!($entity instanceof Player)){
			return;
		}
		$s = $this->session($entity);
		if($s === null){
			return;
		}

		if($e->getCause() === EntityDamageEvent::CAUSE_FALL){
			$e->setCancelled(true);
			return;
		}

		if(!$s->isFighting()){
			$e->setCancelled(true);
			if($e->getCause() === EntityDamageEvent::CAUSE_VOID){
				$this->plugin->sendToLobby($entity);
			}
			return;
		}

		if($s->state === Session::DUEL and ($s->match === null or $s->match->state !== DuelMatch::ACTIVE)){
			$e->setCancelled(true);
			return;
		}
		if($s->state === Session::MEETUP and !$this->plugin->meetup->isPvpEnabled()){
			$e->setCancelled(true);
			return;
		}

		$damager = null;
		if($e instanceof EntityDamageByEntityEvent){
			$damager = $e->getDamager();
			if(!($damager instanceof Player)){
				$e->setCancelled(true);
				return;
			}
			$ds = $this->session($damager);
			if($ds === null or !$this->sameFight($s, $ds)){
				$e->setCancelled(true);
				return;
			}
			if($s->state === Session::MEETUP and $this->plugin->teams->sameTeam($entity->getName(), $damager->getName())){
				$e->setCancelled(true);
				return;
			}

			if($s->state === Session::FFA and $this->plugin->ffa->tagEnabled()){
				$victimTag = $this->plugin->ffa->activeTag($s);
				$damagerTag = $this->plugin->ffa->activeTag($ds);
				if($victimTag !== null and strtolower($victimTag) !== strtolower($damager->getName())){
					$e->setCancelled(true);
					$this->tagNotice($ds, "combat.target-busy", ["player" => $entity->getName()]);
					return;
				}
				if($damagerTag !== null and strtolower($damagerTag) !== strtolower($entity->getName())){
					$e->setCancelled(true);
					$this->tagNotice($ds, "combat.you-busy", ["player" => $damagerTag]);
					return;
				}
				if($victimTag === null){
					$this->plugin->lang->send($entity, "combat.tagged", ["player" => $damager->getName()]);
					$this->plugin->lang->send($damager, "combat.tagged", ["player" => $entity->getName()]);
				}
				$this->plugin->ffa->tag($s, $ds);
			}

			$s->lastDamager = $damager->getName();
			$s->lastDamageTime = time();

			if($entity instanceof PracticePlayer and $e->getFinalDamage() < $entity->getHealth()){
				$entity->setKbProfile($this->plugin->kb->get($this->kbKey($s)));
			}
		}

		if($e->getFinalDamage() >= $entity->getHealth()){
			$e->setCancelled(true);
			$killer = $damager;
			if($killer === null and $s->lastDamager !== null and (time() - $s->lastDamageTime) <= 10){
				$killer = $this->plugin->getServer()->getPlayerExact($s->lastDamager);
				$ks = $killer !== null ? $this->session($killer) : null;
				if($ks === null or !$this->sameFight($s, $ks)){
					$killer = null;
				}
			}
			$this->handleDeath($entity, $s, $killer);
		}
	}

	private function tagNotice(Session $ds, $key, array $vars){
		if($ds->lastTagMsg === time()){
			return;
		}
		$ds->lastTagMsg = time();
		$p = $this->plugin->getServer()->getPlayerExact($ds->name);
		if($p !== null){
			$this->plugin->lang->send($p, $key, $vars);
		}
	}

	private function sameFight(Session $a, Session $b){
		if($a->state !== $b->state){
			return false;
		}
		switch($a->state){
			case Session::FFA:
				return strtolower((string) $a->ffaArena) === strtolower((string) $b->ffaArena);
			case Session::DUEL:
				return $a->match !== null and $b->match !== null and $a->match->id === $b->match->id;
			case Session::MEETUP:
				return true;
		}
		return false;
	}

	private function kbKey(Session $s){
		switch($s->state){
			case Session::FFA:
				$arena = $this->plugin->arenas->getFfa((string) $s->ffaArena);
				return $arena !== null ? $arena["mode"] : null;
			case Session::DUEL:
				return $s->match !== null ? $s->match->mode : null;
			case Session::MEETUP:
				return "meetup";
		}
		return null;
	}

	private function handleDeath(Player $victim, Session $s, $killer){
		switch($s->state){
			case Session::FFA:
				$this->plugin->ffa->handleDeath($victim, $killer);
				break;
			case Session::DUEL:
				$this->plugin->duels->handleDeath($victim, $killer !== null);
				break;
			case Session::MEETUP:
				$this->plugin->meetup->eliminate($victim, $killer);
				break;
		}
	}

	public function onDeath(PlayerDeathEvent $e){
		$e->setDeathMessage("");
		$e->setDrops([]);
		$p = $e->getEntity();
		$s = $this->session($p);
		if($s !== null){
			if($s->state === Session::DUEL and $s->match !== null and $s->match->state !== DuelMatch::ENDED){
				$this->plugin->duels->handleDeath($p, false);
			}

			if($s->state === Session::FFA){
				$killer = null;
				if($s->lastDamager !== null and (time() - $s->lastDamageTime) <= 10){
					$killer = $this->plugin->getServer()->getPlayerExact($s->lastDamager);
				}
				$this->plugin->ffa->handleDeath($p, $killer);
				return;
			}
			if($s->state === Session::FFA_DEAD){
				return;
			}
			$s->state = Session::LOBBY;
			$s->match = null;
		}
	}

	public function onRespawn(PlayerRespawnEvent $e){
		$p = $e->getPlayer();
		$plugin = $this->plugin;
		$s = $this->session($p);

		if($s !== null and $s->state === Session::FFA_DEAD and $s->ffaArena !== null){
			$spawn = Utils::toPosition($plugin->arenas->randomFfaSpawn($s->ffaArena));
			if($spawn !== null){
				$e->setRespawnPosition($spawn);
			}
			$plugin->getServer()->getScheduler()->scheduleDelayedTask(new ClosureTask($plugin, function() use ($plugin, $p){
				$plugin->ffa->afterDeath($p);
			}), 2);
			return;
		}

		$lobby = Utils::toPosition($this->plugin->getConfig()->get("lobby", null));
		if($lobby !== null){
			$e->setRespawnPosition($lobby);
		}
		$plugin->getServer()->getScheduler()->scheduleDelayedTask(new ClosureTask($plugin, function() use ($plugin, $p){
			if($p->isOnline()){
				$plugin->sendToLobby($p);
			}
		}), 5);
	}

	public function onMove(PlayerMoveEvent $e){
		$p = $e->getPlayer();
		$s = $this->session($p);
		if($s === null){
			return;
		}
		$from = $e->getFrom();
		$to = $e->getTo();
		$moved = (abs($from->x - $to->x) > 0.0001 or abs($from->z - $to->z) > 0.0001);

		if($moved and $this->plugin->duels->isFrozen($s)){
			$e->setCancelled(true);
			return;
		}
		if($moved and $s->state === Session::MEETUP and $this->plugin->meetup->outsideBorder($p, $to)){
			$e->setCancelled(true);
			if(time() !== $s->lastBorderMsg){
				$s->lastBorderMsg = time();
				$this->plugin->lang->send($p, "meetup.border");
			}
			return;
		}

		if($moved and !empty($s->settings["auto-sprint"]) and $s->state !== Session::FFA_DEAD and $s->state !== Session::MEETUP_DEAD){
			$dx = $to->x - $from->x;
			$dz = $to->z - $from->z;
			$distSq = $dx * $dx + $dz * $dz;
			$now = microtime(true);

			if($p->isSprinting() and ($now - $s->lastForward) < 0.05 and $distSq > 0.01){
				$s->lastForward = $now;
			}elseif($distSq > 0.000001){
				$len = sqrt($distSq);
				$rad = $to->yaw * 0.017453292519943;
				$forward = ((($dx * -sin($rad)) + ($dz * cos($rad))) / $len > 0.35);
				if($forward){
					$s->lastForward = $now;
					if(!$p->isSprinting()){
						$p->setSprinting(true);
					}
				}elseif($p->isSprinting()){
					$p->setSprinting(false);
				}
			}elseif($p->isSprinting()){
				$p->setSprinting(false);
			}
		}
	}

	public function onInteract(PlayerInteractEvent $e){
		$p = $e->getPlayer();
		$tag = Utils::itemTag($e->getItem());
		if($tag === null){
			return;
		}
		$e->setCancelled(true);
		$s = $this->session($p);
		if($s === null){
			return;
		}
		$now = microtime(true);
		if($now - $s->lastItemUse < 0.5){
			return;
		}
		$s->lastItemUse = $now;
		$this->plugin->menus->handle($p, $s, $tag);
	}

	public function onDrop(PlayerDropItemEvent $e){
		if(!$this->isBuilder($e->getPlayer())){
			$e->setCancelled(true);
		}
	}

	public function onTransaction(InventoryTransactionEvent $e){
		$p = null;
		if(method_exists($e, "getQueue")){
			$q = $e->getQueue();
			if($q !== null and method_exists($q, "getPlayer")){
				$p = $q->getPlayer();
			}
		}elseif(method_exists($e, "getTransaction")){
			$t = $e->getTransaction();
			if(method_exists($t, "getSource")){
				$p = $t->getSource();
			}
		}
		if(!($p instanceof Player) or $this->isBuilder($p)){
			return;
		}
		$s = $this->session($p);
		if($s === null){
			return;
		}
		$locked = [Session::LOBBY, Session::FFA_DEAD, Session::DUEL_QUEUE, Session::MEETUP_WAIT, Session::MEETUP_DEAD];
		if(in_array($s->state, $locked, true)){
			$e->setCancelled(true);
		}
	}

	public function onExhaust(PlayerExhaustEvent $e){
		$e->setCancelled(true);
	}

	private function canBuild(Player $p){
		if($this->isBuilder($p)){
			return true;
		}
		$s = $this->session($p);
		return $s !== null and $s->state === Session::MEETUP and $this->plugin->getConfig()->getNested("meetup.allow-build", true);
	}

	public function onBreak(BlockBreakEvent $e){
		if(!$this->canBuild($e->getPlayer())){
			$e->setCancelled(true);
		}
	}

	public function onPlace(BlockPlaceEvent $e){
		if(!$this->canBuild($e->getPlayer())){
			$e->setCancelled(true);
		}
	}
}
