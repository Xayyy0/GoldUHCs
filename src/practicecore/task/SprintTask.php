<?php
namespace practicecore\task;

use pocketmine\scheduler\PluginTask;
use practicecore\PracticeCore;

class SprintTask extends PluginTask{
	public function __construct(PracticeCore $owner){
		parent::__construct($owner);
	}

	public function onRun($currentTick){
		$plugin = $this->getOwner();
		$now = microtime(true);
		foreach($plugin->sessions->all() as $s){
			if(empty($s->settings["auto-sprint"]) or $now - $s->lastForward <= 0.15){
				continue;
			}
			$p = $plugin->getServer()->getPlayerExact($s->name);
			if($p !== null and $p->isSprinting()){
				$p->setSprinting(false);
			}
		}
	}
}
