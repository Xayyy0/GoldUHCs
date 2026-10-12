<?php
namespace practicecore\task;

use practicecore\PracticeCore;

use pocketmine\plugin\Plugin;
use pocketmine\scheduler\PluginTask;

class SecondTask extends PluginTask{
	private $counter = 0;

	public function __construct(PracticeCore $owner){
		parent::__construct($owner);
	}

	public function onRun($currentTick){
		$p = $this->getOwner();
		$p->enforceWorld($this->counter % 10 === 0);
		$p->duels->tick();
		$p->meetup->tick();
		$p->boards->tick();
		$this->counter++;
		if($this->counter % 3 === 0){
			$p->menus->refreshLive();
		}
		$auto = (int) $p->getConfig()->get("autosave-seconds", 300);
		if($auto > 0){
			$n = count($p->sessions->all());
			$p->sessions->saveBatch(max(1, (int) ceil($n / $auto)));
		}
	}
}
