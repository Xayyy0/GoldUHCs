<?php
namespace practicecore\model;

class Team{
	public $id;
	public $leader;

	public $members = [];

	public function __construct($id, $leaderName){
		$this->id = $id;
		$this->leader = $leaderName;
		$this->members[strtolower($leaderName)] = $leaderName;
	}

	public function size(){
		return count($this->members);
	}

	public function has($name){
		return isset($this->members[strtolower($name)]);
	}
}
