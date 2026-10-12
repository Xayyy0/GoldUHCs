<?php
namespace practicecore\manager;

use practicecore\PracticeCore;

class KnockbackManager{
	private $plugin;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	private function raw($key){
		$all = $this->plugin->getConfig()->get("knockback", []);
		if($key !== null and isset($all[$key]) and is_array($all[$key])){
			return $all[$key];
		}
		if(isset($all["default"]) and is_array($all["default"])){
			return $all["default"];
		}
		return [];
	}

	public function get($key){
		$p = $this->raw($key);

		$x = isset($p["xkb"]) ? $p["xkb"] : (isset($p["horizontal"]) ? $p["horizontal"] : 0.4);
		$y = isset($p["ykb"]) ? $p["ykb"] : (isset($p["vertical"]) ? $p["vertical"] : 0.4);
		$sp = isset($p["speed"]) ? $p["speed"] : (isset($p["attack-delay"]) ? $p["attack-delay"] : 10);
		return [(float) $x, (float) $y, max(0, (int) $sp)];
	}

	public function set($key, $x, $y, $speed = null){
		$cfg = $this->plugin->getConfig();
		$all = $cfg->get("knockback", []);
		$cur = $this->get($key);
		$old = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
		$old["xkb"] = (float) $x;
		$old["ykb"] = (float) $y;
		$old["speed"] = $speed !== null ? (int) $speed : $cur[2];
		unset($old["horizontal"], $old["vertical"], $old["attack-delay"]);
		unset($old["air-ykb"], $old["sprint-bonus"]);
		$all[$key] = $old;
		$cfg->set("knockback", $all);
		$cfg->save();
	}

	public function keys(){
		return array_keys($this->plugin->getConfig()->get("knockback", []));
	}
}
