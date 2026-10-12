<?php
namespace practicecore\utils;

use practicecore\PracticeCore;

use pocketmine\command\CommandSender;
use pocketmine\Server;
use pocketmine\utils\Config;

class Lang{
	private $cfg;

	public function __construct(PracticeCore $plugin){
		$plugin->saveResource("messages.yml");
		$this->cfg = new Config($plugin->getDataFolder() . "messages.yml", Config::YAML);
		$this->addMissing($plugin);
	}

	private function addMissing(PracticeCore $plugin){
		$res = $plugin->getResource("messages.yml");
		if($res === null){
			return;
		}
		$tmp = $plugin->getDataFolder() . "messages.default.tmp";
		file_put_contents($tmp, stream_get_contents($res));
		fclose($res);
		$defaults = (new Config($tmp, Config::YAML))->getAll();
		@unlink($tmp);
		$changed = false;
		foreach($defaults as $k => $v){
			if(!$this->cfg->exists($k)){
				$this->cfg->set($k, $v);
				$changed = true;
			}
		}
		if($changed){
			$this->cfg->save();
		}
	}

	public static function color($text){
		return str_replace("&", "\xc2\xa7", (string) $text);
	}

	private static function fill($text, array $r){
		foreach($r as $k => $v){
			$text = str_replace("{" . $k . "}", (string) $v, $text);
		}
		return self::color($text);
	}

	public function get($key, array $r = []){
		$m = $this->cfg->get($key, null);
		if($m === null or is_array($m)){
			return $key;
		}
		return self::fill($m, $r);
	}

	public function lines($key, array $r = []){
		$m = $this->cfg->get($key, []);
		$out = [];
		if(!is_array($m)){
			$m = [$m];
		}
		foreach($m as $line){
			$out[] = self::fill($line, $r);
		}
		return $out;
	}

	public function send(CommandSender $to, $key, array $r = []){
		$to->sendMessage($this->get("prefix") . $this->get($key, $r));
	}

	public function broadcast($key, array $r = []){
		Server::getInstance()->broadcastMessage($this->get("prefix") . $this->get($key, $r));
	}

	public function sendMany(array $players, $key, array $r = []){
		$msg = $this->get("prefix") . $this->get($key, $r);
		foreach($players as $p){
			$p->sendMessage($msg);
		}
	}
}
