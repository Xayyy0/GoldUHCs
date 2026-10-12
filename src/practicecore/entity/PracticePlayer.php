<?php
namespace practicecore\entity;

use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\Player;
use practicecore\PracticeCore;

class PracticePlayer extends Player{
	private $kbProfile = null;

	private $hitFreeAt = 0.0;

	public function setKbProfile(array $profile){
		$this->kbProfile = $profile;
	}

	public function attack($damage, EntityDamageEvent $source){
		$now = microtime(true);
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			$plugin = PracticeCore::getInstance();
			if($damager instanceof Player and $plugin !== null){
				$plugin->registerClick($damager);
			}
		}

		$this->kbProfile = null;

		if($now >= $this->hitFreeAt - 0.025){
			$this->attackTime = 0;
		}

		parent::attack($damage, $source);

		if(!$source->isCancelled() and $this->kbProfile !== null){
			$speed = max(0, (int) $this->kbProfile[2]);
			$this->hitFreeAt = $now + $speed * 0.05;

			$this->attackTime = $speed > 0 ? 100000 : 0;
		}
		$this->kbProfile = null;
	}

	public function knockBack(Entity $attacker, $damage, $x, $z, $base = 0.4){
		if($this->kbProfile === null){
			parent::knockBack($attacker, $damage, $x, $z, $base);
			return;
		}

		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return;
		}

		$h = $this->kbProfile[0];
		$v = $this->kbProfile[1];
		$mult = $h / $f;

		$mx = $this->motionX / 2 + ($x * $mult);
		$my = $this->motionY / 2 + $v;
		if($my > $v){
			$my = $v;
		}
		$mz = $this->motionZ / 2 + ($z * $mult);

		$this->setMotion(new Vector3($mx, $my, $mz));
	}
}
