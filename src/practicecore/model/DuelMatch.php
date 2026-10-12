<?php
namespace practicecore\model;

class DuelMatch{
	const COUNTDOWN = 0;
	const ACTIVE = 1;
	const ENDED = 2;

	public $id;
	public $arena;
	public $mode;
	public $p1;
	public $p2;
	public $ranked;
	public $state = self::COUNTDOWN;
	public $countdown = 5;

	public function __construct($id, $arena, $mode, $p1, $p2, $ranked = false){
		$this->id = $id;
		$this->arena = $arena;
		$this->mode = $mode;
		$this->p1 = $p1;
		$this->p2 = $p2;
		$this->ranked = (bool) $ranked;
	}

	public function opponentOf($name){
		return strtolower($name) === strtolower($this->p1) ? $this->p2 : $this->p1;
	}
}
