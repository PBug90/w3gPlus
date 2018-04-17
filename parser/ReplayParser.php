<?php

function prettyPrint($what)
{
    echo "<pre>";
    print_r($what);
    echo "</pre>";
}

function prettyJson($what)
{
    echo "<pre>";
    echo json_encode($what);
    echo "</pre>";
}

require_once('w3g-julas.php');

class ReplayParser
{
    private $colorMap = array();
    private $playerIdToName = array();
    private $actionStrings = array("Right click", "Select / deselect","Select group hotkey","Assign group hotkey","Use ability","Basic commands","Build / train","Enter build submenu",
                                  "Enter hero's abilities submenu","Select subgroup","Give item / drop item","Remove unit from queue","ESC pressed");

    private function normalizeChatMessages($chat)
    {
        $chatMessages = array();
        foreach ($chat as $msg) {
            $chatMessages[]= array("color"=>$this->colorMap[$msg["player_name"]],"playerId"=>$msg["player_id"],"playerName"=>$msg["player_name"],"message"=>htmlspecialchars($msg['text'], ENT_COMPAT, 'UTF-8'),"time"=>$msg["time"]?$msg["time"] / 1000 :0,"channel"=>strtolower($msg["mode"]));
        }
        return $chatMessages;
    }

    private function convertTime($time)
    {
        return intval($time / 1000);
    }


    private function normalizeMap_legacy($MapName)
    {
        //remove all directory entries in map path
        $list = explode("\\", $MapName);
        $realMapName = $list[count($list)-1];
        $shortenMap = strtolower($realMapName);
        //remove file extension
        $extensionCandidates = explode(".", $shortenMap);
        //file extension is stored in last element of $extensionCandidates array, so we will replace it with "" and afterwards remove
        //the trailing dot that remains by using rtrim
        $shortenMap = rtrim(str_replace($extensionCandidates[count($extensionCandidates)-1], "", $shortenMap), ".");

        //remove the w3a versioning if there is any.
        $versioning = array("__v5","__v4","__v3","__v2","__v1");
        foreach ($versioning as $version) {
            $shortenMap = str_replace($version, "", $shortenMap);
        }
        //remove all digits, only letters shall remain
        $shortenMap = preg_replace("/[^a-zA-Z]/", "", $shortenMap);

        //remove the remaining warena if the replay was played on a w3arena map that includes w3arena_mapname.w3x
        $shortenMap = str_replace("warena", "", $shortenMap);
        return $shortenMap;
    }

    public function normalizeMap($MapName)
    {
        //remove all directory entries in map path
        $list = explode("\\", $MapName);
        $realMapName = $list[count($list)-1];
        $shortenMap = strtolower($realMapName);
        //remove file extension
        $extensionCandidates = explode(".", $shortenMap);
        //file extension is stored in last element of $extensionCandidates array, so we will replace it with "" and afterwards remove
        //the trailing dot that remains by using rtrim
        $shortenMap = rtrim(str_replace($extensionCandidates[count($extensionCandidates)-1], "", $shortenMap), ".");
        $found = array();
        preg_match_all("/[a-zA-Z]+/", $shortenMap, $found);
        $longest = "";
        foreach ($found[0] as $str) {
            if (strlen($str) > strlen($longest)) {
                $longest = $str;
            }
        }
        return $longest;
    }

    private function normalizePlayerUpgrades($player)
    {
        $upgrades = array();
        $order = array();
        $summary = array();
        if ($player["upgrades"]) {
            if ($player["upgrades"]["order"]) {
                foreach ($player["upgrades"]["order"] as $time=>$upg) {
                    $order[] = array("name"=>$upg,"nameShort"=>strtolower(str_replace(" ", "", $upg)),"time"=>$this::convertTime($time));
                }
                unset($player["upgrades"]["order"]);
            }
            foreach ($player["upgrades"] as $upg => $lvl) {
                $summary[] = array("name"=>$upg,"nameShort"=>strtolower(str_replace(" ", "", $upg)),"level"=>$lvl);
            }
        }
        $upgrades["order"] = $order;
        $upgrades["summary"] = $summary;
        return $upgrades;
    }

    private function normalizePlayerUnits($player)
    {
        $units = array();
        $order = array();
        $summary = array();
        if ($player["units"]) {
            if ($player["units"]["order"]) {
                foreach ($player["units"]["order"] as $time=>$unit) {
                    $unit = explode(" ", $unit, 2);
                    $count = $unit[0];
                    $unit = $unit[1];
                    $order[] = array("name"=>$unit,"nameShort"=>strtolower(str_replace(" ", "", $unit)),"time"=>$this::convertTime($time),"count"=>$count);
                }
                unset($player["units"]["order"]);
            }
            foreach ($player["units"] as $unit => $lvl) {
                $summary[] = array("name"=>$unit,"nameShort"=>strtolower(str_replace(" ", "", $unit)),"count"=>$lvl);
            }
        }
        $units["order"] = $order;
        $units["summary"] = $summary;
        return $units;
    }

    private function normalizePlayerBuildings($player)
    {
        $buildings = array();
        $order = array();
        $summary = array();
        if ($player["buildings"]) {
            if ($player["buildings"]["order"]) {
                foreach ($player["buildings"]["order"] as $time=>$building) {
                    $order[] = array("name"=>$building,"nameShort"=>strtolower(str_replace(" ", "", $building)),"time"=>$this::convertTime($time));
                }
                unset($player["buildings"]["order"]);
            }
            foreach ($player["buildings"] as $building => $lvl) {
                $summary[] = array("name"=>$building,"nameShort"=>strtolower(str_replace(" ", "", $building)),"count"=>$lvl);
            }
        }
        $buildings["order"] = $order;
        $buildings["summary"] = $summary;
        return $buildings;
    }

    private function normalizePlayerItems($player)
    {
        $items = array();
        $order = array();
        $summary = array();
        if ($player["items"]) {
            if ($player["items"]["order"]) {
                foreach ($player["items"]["order"] as $time=>$building) {
                    $order[] = array("name"=>$building,"nameShort"=>strtolower(str_replace(" ", "", $building)),"time"=>$this::convertTime($time));
                }
                unset($player["items"]["order"]);
            }
            foreach ($player["items"] as $building => $lvl) {
                $summary[] = array("name"=>$building,"nameShort"=>strtolower(str_replace(" ", "", $building)),"count"=>$lvl);
            }
        }
        $items["order"] = $order;
        $items["summary"] = $summary;
        return $items;
    }

    private function normalizePlayerHeroes($player)
    {
        $heroes = array();
        $alreadySeen = array();
        $ordered = $player["heroes"]["order"];

        foreach ($ordered as $time => $hero) {
            if (in_array($hero, $alreadySeen)) {
                continue;
            }
            $newHero = array();
            $newHero["name"] = $hero;
            $newHero["time"] = $this::convertTime($time);
            $newHero["nameShort"] = strtolower(str_replace(" ", "", $hero));
            $newHero["level"] = $player["heroes"][$hero]["level"];
            $newHero["revivals"] = $player["heroes"][$hero]["revivals"];
            $newHero["abilities"] = array();

            foreach ($player["heroes"][$hero]["abilities"][0] as $ability => $level) {
                $newHero["abilities"]["summary"][] = array("name"=>$ability,"nameShort"=>strtolower(str_replace(" ", "", $ability)),"level"=>$level);
            }
            foreach ($player["heroes"][$hero]["abilities"]["order"] as $time => $ability) {
                $newHero["abilities"]["order"][] = array("name"=>$ability,"nameShort"=>strtolower(str_replace(" ", "", $ability)),"time"=>$this::convertTime($time));
            }
            $alreadySeen[] = $hero;
            $heroes[] = $newHero;
        }

        return $heroes;
    }

    private function normalizePlayerActions($player)
    {
        $actions = array();

        foreach (array_diff($this->actionStrings, array_keys($player["actions_details"])) as $key) {
            $player["actions_details"][$key] = 0;
        }
        ksort($player["actions_details"]);
        foreach ($player["actions_details"] as $action => $count) {
            $actions[] = array("action"=>$action,"count"=>$count);
        }
        return $actions;
    }

    private function normalizePlayerTimedAPM($player, $timeSampleInterval)
    {
        $periodic_actions = array();
        foreach ($player["actions_timed"] as $key => $val) {
            $periodic_actions["data"][] = $val;
        }
        $periodic_actions["sampleInterval"] = $timeSampleInterval / 1000;
        return $periodic_actions;
    }


    private function normalizeTeams($teams, $apmInterval)
    {
        $newTeams = array();
        $observers = array();
        $teamCount = array();

        foreach ($teams as $team) {
            //prettyPrint($team);
            $newTeam = array();
            foreach ($team as $player) {
                $newPlayer = array();
                $this->playerIdToName[$player["player_id"]] = $player["name"];
                if (($player["team"] == 12 && $this["version"] <29) || $player["team"] == 24) {
                    $observers[] = $player["name"];
                    continue;
                }

                $newPlayer["name"] = $player["name"];
                $newPlayer["apm"] = ceil($player["apm"]);
                $newPlayer["apm_timed"] = $this::normalizePlayerTimedAPM($player, $apmInterval);
                $newPlayer["race"] = strtolower($player["race"]);
                $newPlayer["color"] = $player["color"];
                $newPlayer["actions"] = $this::normalizePlayerActions($player);
                $newPlayer["heroes"] = $this::normalizePlayerHeroes($player);
                $newPlayer["raceDetected"] = strtolower($player["race_detected"]);
                $newPlayer["units"] = $this::normalizePlayerUnits($player);
                $newPlayer["upgrades"] = $this::normalizePlayerUpgrades($player);
                $newPlayer["buildings"] = $this::normalizePlayerBuildings($player);
                $newPlayer["items"] = $this::normalizePlayerItems($player);


                $this->colorMap[$player["name"]] = $player["color"];
                array_push($teamCount, $player["team"]);

                $newTeam[] = $newPlayer;
            }
            if (count($newTeam)>0) {
                $newTeams[] = $newTeam;
            }
        }


        $teamCount = array_filter($teamCount, 'is_numeric');
        $distinguishedPlayerCount = count($teamCount);
        $distinguishedTeamCount = count(array_unique($teamCount));

        $gametype="other";
        if ($distinguishedTeamCount == 2 && $distinguishedPlayerCount == 2) {
            $gametype="1on1";
        } elseif ($distinguishedTeamCount == 2 && $distinguishedPlayerCount == 4) {
            $gametype="2on2";
        } elseif ($distinguishedTeamCount == 2 && $distinguishedPlayerCount == 6) {
            $gametype="3on3";
        } elseif ($distinguishedTeamCount == 2 && $distinguishedPlayerCount == 8) {
            $gametype="4on4";
        } elseif ($distinguishedTeamCount == $distinguishedPlayerCount) {
            $gametype="ffa";
        }

        return array($newTeams,$observers,$gametype);
    }







    public function parseReplayFile($filepath)
    {
        $replay_parsed = new replay($filepath, true, true);
        $return = array();
        $return["map"] = $this::normalizeMap($replay_parsed->game['map']);
        $return["randomSeed"] = $replay_parsed->game["random_seed"];
        $return["mapLong"] = $replay_parsed->game["map"];
        $return["gameName"] = $replay_parsed->game["name"];
        $return["host"] = $replay_parsed->game["creator"];
        $return["filesize"] = filesize($filepath);
        $return["gameLength"] =  $this::convertTime($replay_parsed->header["length"]);
        $return["version"] = intval(sprintf('%02d', $replay_parsed->header['major_v']));
        $this["version"] = $return["version"];
        $return["nwgOffset"] = intval($replay_parsed->read_offset);

        $teamresult = $this::normalizeTeams($replay_parsed->teams, $replay_parsed->apmTimeSampleInterval);
        $return["teams"]=$teamresult[0];
        $return["observers"]=$teamresult[1];
        $return["gameType"] = $teamresult[2];

        $return["chat"] = $this::normalizeChatMessages($replay_parsed->chat);
        $return["w3gplus"] =$replay_parsed->arm;

        if (!isset($replay_parsed->game["saver_id"])) {
            $maxDuration = 0;
            foreach ($replay_parsed->teams as $id => $team) {
                foreach ($team as $player) {
                    if ($maxDuration<$player["time"]) {
                        $maxDuration = $player["time"];
                        $replay_parsed->game["saver_id"] = $player["player_id"];
                    }
                }
            }
        }
        $return["saver"] = $this->playerIdToName[$replay_parsed->game["saver_id"]];
        return $return;
    }
}
$parser = new ReplayParser();
$parser->parseReplayFile(__DIR__ . "/test/replays/1.w3g");
