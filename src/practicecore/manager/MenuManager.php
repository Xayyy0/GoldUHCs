<?php
namespace practicecore\manager;

use pocketmine\Player;
use practicecore\model\Session;
use practicecore\PracticeCore;
use practicecore\utils\Utils;

class MenuManager{
	const PER_PAGE = 7;

	private $plugin;

	public function __construct(PracticeCore $plugin){
		$this->plugin = $plugin;
	}

	private function cfgItem($key){
		$items = $this->plugin->getConfig()->get("items", []);
		return isset($items[$key]) ? $items[$key] : ["id" => 1, "meta" => 0, "slot" => 0, "name" => $key];
	}

	private function entry($key, $tag, $slot = null, $name = null){
		$i = $this->cfgItem($key);
		return [
			"slot" => $slot !== null ? $slot : (int) $i["slot"],
			"id" => (int) $i["id"],
			"meta" => (int) $i["meta"],
			"name" => $name !== null ? $name : $i["name"],
			"tag" => $tag
		];
	}

	private function paged(array &$entries, array $items, $page){
		$pages = max(1, (int) ceil(count($items) / self::PER_PAGE));
		$page = (($page % $pages) + $pages) % $pages;
		$slice = array_slice($items, $page * self::PER_PAGE, self::PER_PAGE);
		foreach($slice as $i => $it){
			$entries[] = ["slot" => $i, "id" => $it[0], "meta" => $it[1], "name" => $it[2], "tag" => $it[3]];
		}
		if($pages > 1){
			$base = $this->cfgItem("next-page");
			$entries[] = $this->entry("next-page", "menu:page:" . ($page + 1), null,
				str_replace(["{page}", "{pages}"], [$page + 1, $pages], $base["name"]));
		}
	}

	private function entries(Player $p, Session $s){
		$lang = $this->plugin->lang;
		$cfg = $this->plugin->getConfig();
		$e = [];

		if($s->state === Session::DUEL_QUEUE){
			$e[] = $this->entry("leave-queue", "queue_leave");
			return $e;
		}

		$menu = $s->menu;

		if($menu === "duel-type"){
			$e[] = $this->entry("unranked", "menu:open:modes:unranked", 3);
			$e[] = $this->entry("ranked", "menu:open:modes:ranked", 5);
			$e[] = $this->entry("back", "menu:back");
			return $e;
		}

		if(strpos($menu, "modes:") === 0){
			$ranked = ($menu === "modes:ranked");
			$items = [];
			foreach($cfg->get("modes", []) as $key => $m){
				$icon = isset($m["icon"]) ? $m["icon"] : ["id" => 267, "meta" => 0];
				$items[] = [
					(int) $icon["id"], (int) $icon["meta"],
					$lang->get("menu.mode-item", ["color" => $ranked ? "&6" : "&b", "mode" => $m["display"]]),
					"queue:" . ($ranked ? "ranked" : "unranked") . ":" . $key
				];
			}
			$this->paged($e, $items, $s->menuPage);
			$e[] = $this->entry("back", "menu:back");
			return $e;
		}

		if($menu === "ffa"){
			$modes = $cfg->get("modes", []);
			$items = [];
			foreach($this->plugin->arenas->allFfa() as $a){
				$m = isset($modes[$a["mode"]]) ? $modes[$a["mode"]] : ["display" => $a["mode"]];
				$icon = isset($m["icon"]) ? $m["icon"] : ["id" => 322, "meta" => 0];
				$items[] = [
					(int) $icon["id"], (int) $icon["meta"],
					$lang->get("menu.ffa-item", ["arena" => $this->plugin->arenas->ffaLabel($a["name"]), "n" => $this->plugin->ffa->count($a["name"])]),
					"ffa:" . $a["name"]
				];
			}
			$this->paged($e, $items, $s->menuPage);
			$e[] = $this->entry("back", "menu:back");
			return $e;
		}

		if($menu === "settings"){
			$slots = [0, 2, 4, 6, 7];
			foreach(SessionManager::$SETTINGS as $i => $key){
				$on = (bool) $s->settings[$key];
				$base = $this->cfgItem($on ? "toggle-on" : "toggle-off");
				$e[] = [
					"slot" => $slots[$i],
					"id" => (int) $base["id"],
					"meta" => (int) $base["meta"],
					"name" => $lang->get("menu.toggle", [
						"name" => $lang->get("settings.name." . $key),
						"state" => $lang->get($on ? "settings.on" : "settings.off")
					]),
					"tag" => "setting:" . $key
				];
			}
			$e[] = $this->entry("back", "menu:back");
			return $e;
		}

		$e[] = $this->entry("unranked", "menu:open:modes:unranked");
		$e[] = $this->entry("ranked", "menu:open:modes:ranked");
		$e[] = $this->entry("ffa", "menu:open:ffa");
		$e[] = $this->entry("leaderboards", "top");
		$e[] = $this->entry("stats", "stats");
		$e[] = $this->entry("settings", "menu:open:settings");
		$meetup = $this->plugin->meetup;
		if($meetup->isOpenForTeams()){
			$e[] = $this->entry("meetup", "meetup_toggle", null,
				$lang->get($meetup->isRegistered($p) ? "menu.meetup-leave" : "menu.meetup-join"));
		}
		return $e;
	}

	public function show(Player $p, $menu = "main", $page = 0){
		$s = $this->plugin->sessions->get($p);
		if($s === null){
			return;
		}
		$s->menu = $menu;
		$s->menuPage = (int) $page;
		$this->render($p, $s, true);
	}

	public function render(Player $p, Session $s, $force = false){
		if($s->state !== Session::LOBBY and $s->state !== Session::DUEL_QUEUE){
			return;
		}
		$entries = $this->entries($p, $s);
		$hash = md5(serialize($entries));
		if(!$force and $hash === $s->menuHash){
			return;
		}
		$s->menuHash = $hash;
		Utils::clearInventory($p);
		$inv = $p->getInventory();
		foreach($entries as $en){
			$inv->setItem($en["slot"], Utils::marked($en["id"], $en["meta"], $en["name"], $en["tag"]));
		}
		$inv->sendContents($p);
	}

	public function refreshAll(){
		foreach($this->plugin->sessions->all() as $s){
			if($s->state === Session::LOBBY and $s->menu === "main"){
				$p = $this->plugin->getServer()->getPlayerExact($s->name);
				if($p !== null){
					$this->render($p, $s);
				}
			}
		}
	}

	public function refreshLive(){
		foreach($this->plugin->sessions->all() as $s){
			if($s->state === Session::LOBBY and $s->menu === "ffa"){
				$p = $this->plugin->getServer()->getPlayerExact($s->name);
				if($p !== null){
					$this->render($p, $s);
				}
			}
		}
	}

	public function handle(Player $p, Session $s, $tag){
		$parts = explode(":", $tag, 3);
		$lang = $this->plugin->lang;
		switch($parts[0]){
			case "menu":
				if($s->state !== Session::LOBBY){
					return;
				}
				if($parts[1] === "open" and isset($parts[2])){
					$this->show($p, $parts[2]);
				}elseif($parts[1] === "back"){
					$this->show($p, "main");
				}elseif($parts[1] === "page" and isset($parts[2])){
					$this->show($p, $s->menu, (int) $parts[2]);
				}
				return;

			case "queue":
				if($s->state === Session::LOBBY and isset($parts[2])){
					$this->plugin->duels->queue($p, $parts[2], $parts[1] === "ranked");
				}
				return;

			case "queue_leave":
				if($s->state === Session::DUEL_QUEUE){
					$this->plugin->duels->leaveQueue($p);
					$lang->send($p, "duel.left-queue");
					$this->show($p, "main");
				}
				return;

			case "ffa":
				if($s->state === Session::LOBBY and isset($parts[1])){
					$this->plugin->ffa->join($p, $parts[1] . (isset($parts[2]) ? ":" . $parts[2] : ""));
				}
				return;

			case "setting":
				if(isset($parts[1])){
					$this->plugin->toggleSetting($p, $parts[1]);
					if($s->state === Session::LOBBY){
						$this->render($p, $s, true);
					}
				}
				return;

			case "stats":
				$this->plugin->stats->sendStats($p, $s);
				return;

			case "top":
				$this->plugin->stats->sendLeaderboards($p, $p->getName());
				return;

			case "meetup_toggle":
				if($this->plugin->meetup->isRegistered($p)){
					$this->plugin->meetup->leave($p);
				}else{
					$this->plugin->meetup->join($p);
				}
				return;

			case "meetup_leave":
				$this->plugin->meetup->leave($p);
				return;

			case "ffa_respawn":
				$this->plugin->ffa->respawn($p);
				return;

			case "to_lobby":
				$this->plugin->sendToLobby($p);
				return;
		}
	}
}
