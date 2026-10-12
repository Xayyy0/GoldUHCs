# GoldUHCs (PocketMine API 2.x)

Install: copy the `GoldUHCs` folder (or the .zip contents) into `plugins/`.

## Lobby (same layout as the reference video)
Hotbar: slot 0 **Unranked**, 1 **Ranked**, 3 **FFA**, 5 **Top Leaderboards**, 7 **My Stats**, 8 **Settings**.
* Unranked / Ranked / FFA / Settings replace the hotbar with a submenu made of items (plus a *Back* item).
* My Stats and Top Leaderboards print in chat (also `/stats [player]` and `/top`).
* Side panel on the right: Online / Elo / Rank / Kills. It is built from frame templates in
  `config.yml` (`hud.scoreboard.<state>`) with `$center`, `{n}` and `$placeholders`; several frames = animation.
  Tune its position in game with `/practice hud center <n>` (more right) and `/practice hud lines <n>` (higher).
* Elo starts at 800, changes only in Ranked duels; ranks are configured in `config.yml`.

## Quick setup
```
/practice setlobby
/practice setprelobby
/practice arena ffa create Sand nodebuff      (stand on the first spawn)
/practice arena ffa addspawn Sand
/practice arena duel create Duel1
/practice arena duel setpos Duel1 1           (then stand on the other spot)
/practice arena duel setpos Duel1 2
/practice lb create kills                    (floating leaderboard where you stand; also: deaths, meetupwins)
/practice lb remove                          (removes the closest leaderboard)
/meetup map set Forest 100                    (center = your position, radius 100)
/meetup schedule 10m Forest duo               (solo | duo | trio)
```
Knockback: own formula, 3 values per mode: `/practice kb uhc 0.42 0.45 10` = xkb (horizontal), ykb (vertical) and speed (hit immunity in ticks).
Kits: `kits.yml`. Texts: `messages.yml`. Items, panel, Elo, ranks, modes: `config.yml`.

## Structure
```
plugin.yml
resources/            config.yml, kits.yml, messages.yml
src/practicecore/
  PracticeCore.php    main class
  command/            CommandHandler
  entity/             PracticePlayer
  listener/           EventListener
  manager/            Session, Stats, Menu, Hud, Kit, Knockback, Arena, Leaderboard, FFA, Duel, Team, Meetup managers
  model/              Session, DuelMatch, Team
  task/               ClosureTask, SecondTask, HudTask
  utils/              Lang, Utils
```
