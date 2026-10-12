<?php
namespace practicecore\model;

class Session{
	const LOBBY = 0;
	const FFA = 1;
	const FFA_DEAD = 2;
	const DUEL_QUEUE = 3;
	const DUEL = 4;
	const MEETUP_WAIT = 5;
	const MEETUP = 6;
	const MEETUP_DEAD = 7;

	public $name;
	public $state = self::LOBBY;
	public $settings = [];
	public $welcomed = false;

	public $dirty = false;

	public $elo = 800;
	public $kills = 0;
	public $deaths = 0;
	public $rankedWins = 0;
	public $rankedLosses = 0;
	public $unrankedWins = 0;
	public $unrankedLosses = 0;
	public $meetupPlayed = 0;
	public $meetupWins = 0;

	public $ffaArena = null;
	public $queueMode = null;
	public $queueRanked = false;
	public $queueSince = 0;

	public $match = null;

	public $menu = "main";
	public $menuPage = 0;
	public $menuHash = "";

	public $lastDamager = null;

	public $tagWith = null;
	public $tagUntil = 0;
	public $lastTagMsg = 0;
	public $lastDamageTime = 0;

	public $clicks = [];
	public $lastItemUse = 0;
	public $lastBorderMsg = 0;

	public $flash = null;
	public $flashUntil = 0;

	public $hudFrame = 0;

	public $hudLastTip = "";

	public $deadHandled = true;
	public $hudLastSent = 0;

	public $lastCpsPopup = 0;

	public function __construct($name){
		$this->name = $name;
	}

	public function click(){
		$now = microtime(true);
		$n = count($this->clicks);
		if($n > 0 and $now - $this->clicks[$n - 1] < 0.025){
			return;
		}
		$this->clicks[] = $now;
	}

	public $lastForward = 0;

	public function cps(){
		$limit = microtime(true) - 1;
		$keep = [];
		foreach($this->clicks as $t){
			if($t >= $limit){
				$keep[] = $t;
			}
		}
		$this->clicks = $keep;
		return count($keep);
	}

	public function isFighting(){
		return $this->state === self::FFA or $this->state === self::DUEL or $this->state === self::MEETUP;
	}
}
