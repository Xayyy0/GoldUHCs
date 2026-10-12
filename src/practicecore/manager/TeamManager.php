<?php
namespace practicecore\manager;

use practicecore\PracticeCore;
use practicecore\model\Team;

use pocketmine\Player;

class TeamManager{
	private $plugin;

	private $teams = [];

	private $playerTeam = [];

	private $invites = [];
	private $nextId = 1;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	public function clear(){
		$this->teams = [];
		$this->playerTeam = [];
		$this->invites = [];
		$this->nextId = 1;
	}

	public function getTeam($name){
		$k = strtolower($name);
		return isset($this->playerTeam[$k]) ? $this->teams[$this->playerTeam[$k]] : null;
	}

	public function sameTeam($a, $b){
		$ta = $this->getTeam($a);
		return $ta !== null and $ta->has($b);
	}

	public function all(){
		return $this->teams;
	}

	public function createTeam($leaderName){
		$t = new Team($this->nextId++, $leaderName);
		$this->teams[$t->id] = $t;
		$this->playerTeam[strtolower($leaderName)] = $t->id;
		return $t;
	}

	private function addMember(Team $t, $name){
		$t->members[strtolower($name)] = $name;
		$this->playerTeam[strtolower($name)] = $t->id;
	}

	public function invite(Player $from, Player $to){
		$lang = $this->plugin->lang;
		$meetup = $this->plugin->meetup;
		$size = $meetup->getTeamSize();
		if(!$meetup->isOpenForTeams() or $size < 2){
			$lang->send($from, "team.not-available");
			return;
		}
		if($from === $to){
			$lang->send($from, "team.self");
			return;
		}
		$team = $this->getTeam($from->getName());
		if($team !== null and $team->leader !== $from->getName()){
			$lang->send($from, "team.not-leader");
			return;
		}
		if($team !== null and $team->size() >= $size){
			$lang->send($from, "team.full");
			return;
		}
		if($this->getTeam($to->getName()) !== null){
			$lang->send($from, "team.target-in-team", ["player" => $to->getName()]);
			return;
		}
		$expire = (int) $this->plugin->getConfig()->getNested("meetup.invite-expire", 60);
		$this->invites[strtolower($to->getName())][strtolower($from->getName())] = time() + $expire;
		$lang->send($from, "team.invite-sent", ["player" => $to->getName()]);
		$lang->send($to, "team.invite-received", ["player" => $from->getName(), "seconds" => $expire]);
	}

	public function accept(Player $p, $fromName = null){
		$lang = $this->plugin->lang;
		$k = strtolower($p->getName());
		$meetup = $this->plugin->meetup;
		if(!$meetup->isOpenForTeams()){
			$lang->send($p, "team.not-available");
			return;
		}
		$list = isset($this->invites[$k]) ? $this->invites[$k] : [];
		foreach($list as $f => $exp){
			if($exp < time()){
				unset($list[$f]);
			}
		}
		if($fromName === null){
			$keys = array_keys($list);
			$from = count($keys) > 0 ? $keys[count($keys) - 1] : null;
		}else{
			$from = isset($list[strtolower($fromName)]) ? strtolower($fromName) : null;
		}
		if($from === null){
			$lang->send($p, "team.no-invite");
			return;
		}
		unset($this->invites[$k]);
		$leaderPlayer = $this->plugin->getServer()->getPlayerExact($from);
		if($leaderPlayer === null){
			$lang->send($p, "team.leader-offline");
			return;
		}
		if($this->getTeam($p->getName()) !== null){
			$lang->send($p, "team.already-in-team");
			return;
		}
		$team = $this->getTeam($leaderPlayer->getName());
		if($team === null){
			$team = $this->createTeam($leaderPlayer->getName());
		}
		if($team->size() >= $meetup->getTeamSize()){
			$lang->send($p, "team.full");
			return;
		}
		$this->addMember($team, $p->getName());
		if(!$meetup->isRegistered($p)){
			$meetup->join($p);
		}
		if(!$meetup->isRegistered($leaderPlayer)){
			$meetup->join($leaderPlayer);
		}
		$this->message($team, "team.joined", ["player" => $p->getName()]);
	}

	public function deny(Player $p, $fromName = null){
		$k = strtolower($p->getName());
		if(!isset($this->invites[$k])){
			$this->plugin->lang->send($p, "team.no-invite");
			return;
		}
		if($fromName === null){
			unset($this->invites[$k]);
		}else{
			unset($this->invites[$k][strtolower($fromName)]);
		}
		$this->plugin->lang->send($p, "team.denied");
	}

	public function leave($name, $notify = true){
		$t = $this->getTeam($name);
		if($t === null){
			return false;
		}
		unset($t->members[strtolower($name)]);
		unset($this->playerTeam[strtolower($name)]);
		if($t->size() === 0){
			unset($this->teams[$t->id]);
			return true;
		}
		if($t->leader === $name or strtolower($t->leader) === strtolower($name)){
			$vals = array_values($t->members);
			$t->leader = $vals[0];
		}
		if($notify){
			$this->message($t, "team.left", ["player" => $name]);
		}
		if($t->size() === 1 and !$this->plugin->meetup->isRunning()){
		}
		return true;
	}

	public function message(Team $t, $key, array $r = []){
		foreach($t->members as $display){
			$p = $this->plugin->getServer()->getPlayerExact($display);
			if($p !== null){
				$this->plugin->lang->send($p, $key, $r);
			}
		}
	}

	public function autoFill(array $names, $size){
		$free = [];
		foreach($names as $n){
			if($this->getTeam($n) === null){
				$free[] = $n;
			}
		}
		shuffle($free);
		$current = null;
		foreach($free as $n){
			if($current === null or $current->size() >= $size){
				$current = $this->createTeam($n);
			}else{
				$this->addMember($current, $n);
			}
		}
	}

	public function filter(array $keepLower){
		foreach($this->teams as $id => $t){
			foreach($t->members as $k => $display){
				if(!isset($keepLower[$k])){
					unset($t->members[$k]);
					unset($this->playerTeam[$k]);
				}
			}
			if($t->size() === 0){
				unset($this->teams[$id]);
			}elseif(!isset($t->members[strtolower($t->leader)])){
				$vals = array_values($t->members);
				$t->leader = $vals[0];
			}
		}
	}
}
