<?php
namespace practicecore\task;

use pocketmine\scheduler\PluginTask;
use practicecore\PracticeCore;

class HudTask extends PluginTask{
	public function __construct(PracticeCore $owner){
		parent::__construct($owner);
	}

	public function onRun($currentTick){
		$plugin = $this->getOwner();
		$plugin->hud->tick();
	}
}
