<?php
namespace practicecore\task;

use pocketmine\plugin\Plugin;
use pocketmine\scheduler\PluginTask;

class ClosureTask extends PluginTask{
	private $fn;

	public function __construct(Plugin $owner, callable $fn){
		parent::__construct($owner);
		$this->fn = $fn;
	}

	public function onRun($currentTick){
		call_user_func($this->fn, $currentTick);
	}
}
