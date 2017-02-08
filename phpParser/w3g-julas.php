<?php
/******************************************************************************
Warcraft III Replay Parser 2.4
(c) 2003-2010 Juliusz 'Julas' Gonera
http://w3rep.sourceforge.net/
e-mail: julas@toya.net.pl
-------------------------------------------------------------------------------
Based on:
- w3g_format.txt 1.15 and w3g_actions.txt 1.00 by Blue and Nagger
- other PHP scripts by Michael Kufner, Soar Chin and Andre Cerqueira
  (AKA SnakeByte)
- my own researches
-------------------------------------------------------------------------------
For more informtion about w3g file format, please read the docs and visit
the developer forum on: http://shadowflare.samods.org/cgi-bin/yabb/YaBB.cgi
-------------------------------------------------------------------------------
Please place a note with a link to http://w3rep.sourceforge.net/ on your site
if you use this script.
******************************************************************************/

require('w3g-julas-convert.php');

// to know when there is a need to load next block
define('MAX_DATABLOCK', 1500);
// for preventing duplicated actions
define('ACTION_DELAY', 1000);
// to know how long it may take after buying a Tome of Retraining to retrain a hero
define('RETRAINING_TIME', 15000);

class replay {
	var $fp, $data, $leave_unknown, $continue_game, $referees, $time, $pause, $leaves, $errors, $header, $game,  $players, $teams, $chat, $filename, $parse_actions, $parse_chat;
	var $max_datablock = MAX_DATABLOCK;
	
	function replay($filename, $parse_actions=true, $parse_chat=true) {
		$this->parse_actions = $parse_actions;
		$this->parse_chat = $parse_chat;
		$this->filename = $filename;
        $this->arm = array("detected_events"=>array());
		$this->game['player_count'] = 0;
		if (!$this->fp = fopen($filename, 'rb')) {
			exit($this->filename.': Can\'t read replay file');
		}
		flock($this->fp, 1);
	
		$this->parseheader();
		$this->parsedata();
		$this->cleanup();
	
		flock($this->fp, 3);
		fclose($this->fp);
		unset($this->fp);
		unset($this->data);
		unset($this->players);
		unset($this->referees);
		unset($this->time);
		unset($this->pause);
		unset($this->leaves);
		unset($this->max_datablock);
		unset($this->ability_delay);
		unset($this->leave_unknown);
		unset($this->continue_game);
	}
 function hex2str($hex) {
        $str = '';
        for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
        return $str;
    }    	
	// 2.0 [Header]
	function parseheader() {
		$data = fread($this->fp, 48);
		$this->header = @unpack('a28intro/Vheader_size/Vc_size/Vheader_v/Vu_size/Vblocks', $data);
	
	
		if ($this->header['header_v'] == 0) {
			$data = fread($this->fp, 16);
			$this->header = array_merge($this->header, unpack('vminor_v/vmajor_v/vbuild_v/vflags/Vlength/Vchecksum', $data));
			$this->header['ident'] = 'WAR3';
		} elseif ($this->header['header_v']==1) {
			$data = fread($this->fp, 20);
			$this->header = array_merge($this->header, unpack('a4ident/Vmajor_v/vbuild_v/vflags/Vlength/Vchecksum', $data));
			$this->header['minor_v'] = 0;
			$this->header['ident'] = strrev($this->header['ident']);
		}
	}
	
	function parsedata() {
		fseek($this->fp, $this->header['header_size']);
		$blocks_count = $this->header['blocks'];
		for ($i=0; $i<$blocks_count; $i++) {
			// 3.0 [Data block header]
			$block_header = @unpack('vc_size/vu_size/Vchecksum', fread($this->fp, 8));
			$temp = fread($this->fp, $block_header['c_size']);
			$temp = substr($temp, 2, -4);
			// the first bit must be always set, but already set in replays with modified chatlog (why?)
			$temp{0} = chr(ord($temp{0}) | 1);
			if ($temp = gzinflate($temp)) {
				$this->data .= $temp;
			} else {
				exit($this->filename.': Incomplete replay file');
			}
	
			// 4.0 [Decompressed data]
			if ($i == 0) {
				$this->data = substr($this->data, 4);
				$this->loadplayer();
				$this->loadgame();
			} elseif ($blocks_count - $i < 2) {
				$this->max_datablock = 0;
			}
	
			if ($this->parse_chat || $this->parse_actions) {
				$this->parseblocks();
			} else {
				break;
			}
		}
	}
	
	// 4.1 [PlayerRecord]
	function loadplayer() {
		$temp = unpack('Crecord_id/Cplayer_id', $this->data);
		$this->data = substr($this->data, 2);
		$player_id = $temp['player_id'];
		$this->players[$player_id]['player_id'] = $player_id;
		$this->players[$player_id]['initiator'] = convert_bool(!$temp['record_id']);
	
		$this->players[$player_id]['name'] = '';
		for ($i=0; $this->data{$i}!="\x00"; $i++) {
			$this->players[$player_id]['name'] .= $this->data{$i};
		}
		// if it's FFA we need to give players some names
		if (!$this->players[$player_id]['name']) {
			$this->players[$player_id]['name'] = 'Player '.$player_id;
		}
		$this->data = substr($this->data, $i+1);
	
	
		if (ord($this->data{0}) == 1) { // custom game
			$this->data = substr($this->data, 2);
		} elseif (ord($this->data{0}) == 8) { // ladder game
			$this->data = substr($this->data, 1);
			$temp = unpack('Vruntime/Vrace', $this->data);
			$this->data = substr($this->data, 8);
			$this->players[$player_id]['exe_runtime'] = $temp['runtime'];
			$this->players[$player_id]['race'] = convert_race($temp['race']);
		}
		if ($this->parse_actions) {
			$this->players[$player_id]['actions'] = 0;
		}
		if (!$this->header['build_v']) { // calculating team for tournament replays from battle.net website
			$this->players[$player_id]['team'] = ($player_id-1)%2;
		}
		$this->game['player_count']++;
	}
	
	function loadgame() {
		// 4.2 [GameName]
		$this->game['name'] = '';
		for ($i=0; $this->data{$i}!=chr(0); $i++) {
			$this->game['name'] .= $this->data{$i};
		}
		$this->data = substr($this->data, $i+2); // 0-byte ending the string + 1 unknown byte
	
		// 4.3 [Encoded String]
		$temp = '';
	
		for ($i=0; $this->data{$i} != chr(0); $i++) {
			if ($i%8 == 0) {
				$mask = ord($this->data{$i});
			} else {
				$temp .= chr(ord($this->data{$i}) - !($mask & (1 << $i%8)));
			}
		}
		$this->data = substr($this->data, $i+1);
	
		// 4.4 [GameSettings]
		$this->game['speed'] = convert_speed(ord($temp{0}));
		
		if (ord($temp{1}) & 1) {
			$this->game['visibility'] = convert_visibility(0);
		} else if (ord($temp{1}) & 2) {
			$this->game['visibility'] = convert_visibility(1);
		} else if (ord($temp{1}) & 4) {
			$this->game['visibility'] = convert_visibility(2);
		} else if (ord($temp{1}) & 8) {
			$this->game['visibility'] = convert_visibility(3);
		}
		$this->game['observers'] = convert_observers(((ord($temp{1}) & 16) == true) + 2*((ord($temp{1}) & 32) == true));
		$this->game['teams_together'] = convert_bool(ord($temp{1}) & 64);
		
		$this->game['lock_teams'] = convert_bool(ord($temp{2}));
		
		$this->game['full_shared_unit_control'] = convert_bool(ord($temp{3}) & 1);
		$this->game['random_hero'] = convert_bool(ord($temp{3}) & 2);
		$this->game['random_races'] = convert_bool(ord($temp{3}) & 4);
		if (ord($temp{3}) & 64) {
			$this->game['observers'] = convert_observers(4);
		}
	
		$temp = substr($temp, 13); // 5 unknown bytes + checksum
		
		// 4.5 [Map&CreatorName]
		$temp = explode(chr(0), $temp);
		$this->game['creator'] = $temp[1];
		$this->game['map'] = $temp[0];
	
		// 4.6 [PlayerCount]
		$temp = unpack('Vslots', $this->data);
		$this->data = substr($this->data, 4);
		$this->game['slots'] = $temp['slots'];
	
		// 4.7 [GameType]
		$this->game['type'] = convert_game_type(ord($this->data[0]));
		$this->game['private'] = convert_bool(ord($this->data[1]));
	
		$this->data = substr($this->data, 8); // 2 bytes are unknown and 4.8 [LanguageID] is useless
	
		// 4.9 [PlayerList]
		while (ord($this->data{0}) == 0x16) {
			$this->loadplayer();
			$this->data = substr($this->data, 4);
		}
	
		// 4.10 [GameStartRecord]
		$temp = unpack('Crecord_id/vrecord_length/Cslot_records', $this->data);
		$this->data = substr($this->data, 4);
		$this->game = array_merge($this->game, $temp);
		$slot_records = $temp['slot_records'];
	
		// 4.11 [SlotRecord]
		for ($i=0; $i<$slot_records; $i++) {
			if ($this->header['major_v'] >= 7) {
				$temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace/Cai_strength/Chandicap', $this->data);
				$this->data = substr($this->data, 9);
			} elseif ($this->header['major_v'] >= 3) {
				$temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace/Cai_strength', $this->data);
				$this->data = substr($this->data, 8);
			} else {
				$temp = unpack('Cplayer_id/x1/Cslot_status/Ccomputer/Cteam/Ccolor/Crace', $this->data);
				$this->data = substr($this->data, 7);
			}
			
			if ($temp['slot_status'] == 2) { // do not add empty slots
				$temp['color'] = convert_color($temp['color']);
				$temp['race'] = convert_race($temp['race']);
				$temp['ai_strength'] = convert_ai($temp['ai_strength']);
				
				// player ID is always 0 for computer players
				if ($temp['computer'] == 1)
					$this->players[] = $temp;
				else
					$this->players[$temp['player_id']] = array_merge($this->players[$temp['player_id']], $temp);
				
				// Tome of Retraining
				$this->players[$temp['player_id']]['retraining_time'] = 0;
			}
		}
	
		// 4.12 [RandomSeed]
		$temp = unpack('Vrandom_seed/Cselect_mode/Cstart_spots', $this->data);
		$this->data = substr($this->data, 6);
		$this->game['random_seed'] = $temp['random_seed'];
		$this->game['select_mode'] = convert_select_mode($temp['select_mode']);
		if ($temp['start_spots'] != 0xCC) { // tournament replays from battle.net website don't have this info
			$this->game['start_spots'] = $temp['start_spots'];
		}
	}
	
	// 5.0 [ReplayData]
	function parseblocks() {
		$data_left = strlen($this->data);
		$block_id = 0;
		while ($data_left > $this->max_datablock) {
			$prev = $block_id;
			$block_id = ord($this->data{0});
	
			switch ($block_id) {
				// TimeSlot block
				case 0x1E:
				case 0x1F:
					$temp = unpack('x1/vlength/vtime_inc', $this->data);
					if (!$this->pause) {
						$this->time += $temp['time_inc'];
					}
					if ($temp['length'] > 2 && $this->parse_actions) {
						$this->parseactions(substr($this->data, 5, $temp['length']-2), $temp['length']-2);
					}
					$this->data = substr($this->data, $temp['length']+3);
					$data_left -= $temp['length']+3;
					break;
				// Player chat message (patch version >= 1.07)
				case 0x20:
					// before 1.03 0x20 was used instead 0x22
					if ($this->header['major_v'] > 2) {
						$temp = unpack('x1/Cplayer_id/vlength/Cflags/vmode', $this->data);
						if ($temp['flags'] == 0x20) {
							$temp['mode'] = convert_chat_mode($temp['mode']);
							$temp['text'] = substr($this->data, 9, $temp['length']-6);
						} elseif ($temp['flags'] == 0x10) {
							// those are strange messages, they aren't visible when
							// watching the replay but they are present; they have no mode
							$temp['text'] = substr($this->data, 7, $temp['length']-3);
							unset($temp['mode']);
						}
						$this->data = substr($this->data, $temp['length']+4);
						$data_left -= $temp['length']+4;
						$temp['time'] = $this->time;
						$temp['player_name'] = $this->players[$temp['player_id']]['name'];
						$this->chat[] = $temp;
						break;
					}
				// unknown (Random number/seed for next frame)
				case 0x22:
					$temp = ord($this->data{1});
					$this->data = substr($this->data, $temp+2);
					$data_left -= $temp+2;
					break;
				// unknown (startblocks)
				case 0x1A:
				case 0x1B:
				case 0x1C:
					$this->data = substr($this->data, 5);
					$data_left -= 5;
					break;
				// unknown (very rare, appears in front of a 'LeaveGame' action)
				case 0x23:
					$this->data = substr($this->data, 11);
					$data_left -= 11;
					break;
				// Forced game end countdown (map is revealed)
				case 0x2F:
					$this->data = substr($this->data, 9);
					$data_left -= 9;
					break;
				// LeaveGame
				case 0x17:
				case 0x54:
					$this->leaves++;
					
					$temp = unpack('x1/Vreason/Cplayer_id/Vresult/Vunknown', $this->data);
					$this->players[$temp['player_id']]['time'] = $this->time;
					$this->players[$temp['player_id']]['leave_reason'] = $temp['reason'];
					$this->players[$temp['player_id']]['leave_result'] = $temp['result'];
					$this->data = substr($this->data, 14);
					$data_left -= 14;
					if ($this->leave_unknown) {
						$this->leave_unknown = $temp['unknown'] - $this->leave_unknown;
					}
					if ($this->leaves == $this->game['player_count']) {
						$this->game['saver_id'] = $temp['player_id'];
						$this->game['saver_name'] = $this->players[$temp['player_id']]['name'];
					}
					if ($temp['reason'] == 0x01) {
						switch ($temp['result']) {
							case 0x08: $this->game['loser_team'] = $this->players[$temp['player_id']]['team']; break;
							case 0x09: $this->game['winner_team'] = $this->players[$temp['player_id']]['team']; break;
							case 0x0A: $this->game['loser_team'] = 'tie'; $this->game['winner_team'] = 'tie'; break;
						}
					} elseif ($temp['reason'] == 0x0C && $this->game['saver_id']) {
						switch ($temp['result']) {
							case 0x07:
								if ($this->leave_unknown > 0 && $this->continue_game) {
									$this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
								} else {
									$this->game['loser_team'] = $this->players[$this->game['saver_id']]['team'];
								}
							break;
							case 0x08: $this->game['loser_team'] = $this->players[$this->game['saver_id']]['team']; break;
							case 0x09: $this->game['winner_team'] = $this->players[$this->game['saver_id']]['team']; break;
							case 0x0B: // this isn't correct according to w3g_format but generally works...
								if ($this->leave_unknown > 0) {
									$this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
								}
							break;
						}
					} elseif ($temp['reason'] == 0x0C) {
						switch ($temp['result']) {
							case 0x07: $this->game['loser_team'] = 99; break; // saver
							case 0x08: $this->game['winner_team'] = $this->players[$temp['player_id']]['team']; break;
							case 0x09: $this->game['winner_team'] = 99; break; // saver
							case 0x0A: $this->game['loser_team'] = 'tie'; $this->game['winner_team'] = 'tie'; break;
						}
					}
					$this->leave_unknown = $temp['unknown'];
					break;
				case 0:
					$data_left = 0;
					break;
				default:
					exit('Unhandled replay command block at '.convert_time($this->time).': 0x'.sprintf('%02X', $block_id).' (prev: 0x'.sprintf('%02X', $prev).', time: '.$this->time.') in '.$this->filename);
			}
		}
	}
	
	// ACTIONS, the best part...
	function parseactions($actionblock, $data_length) {
		$block_length = 0;
		$action = 0;
			
		while ($data_length) {
			if ($block_length) {
				$actionblock = substr($actionblock, $block_length);
			}
			$temp = unpack('Cplayer_id/vlength', $actionblock);
			$player_id = $temp['player_id'];
			$block_length = $temp['length']+3;
			$data_length -= $block_length;
			
			$was_deselect = false;
			$was_subupdate = false;
			$was_subgroup = false;
			$c = 0;
			$n = 3;
			while ($n < $block_length) {
				$prev = $action;
				$action = ord($actionblock{$n});
				
				switch ($action) {
					// Unit/building ability (no additional parameters)
					// here we detect the races, heroes, units, items, buildings,
					// upgrades
                    case 0x6B:
                        //echo "Detected a meta action";
                        $n++;
                        
                       // echo(sprintf('Hex von n: %02X<br>',ord($actionblock[$n])));
                        $pos = strpos($actionblock,"\0",$n);
                        $value = trim(substr($actionblock, $n,$pos-$n));
                       
                        
                        //echo "n increased by ".($pos-$n+1)."<br>";
                        $n=$n+($pos-$n)+1;
                        //echo(sprintf('Hex von n: %02X<br>',ord($actionblock[$n])));
                        //echo bin2hex($value)."<br>";
                        $value = str_replace("\0", "", $value);
                        //echo "value: ".$value."<br>";
                        //check if we find our custom data type
                        if (trim($value) === "MMD.Dat"){
                          //check for sequence number
                            
                            
                            $pos = strpos($actionblock,"\0",$n);
                            $value = substr($actionblock, $n,$pos-$n);
                            $n= $n+($pos-$n)+1;   
                          //  echo "n increased by ".($pos-$n+1)."<br>";
                            //echo(sprintf('Hex von n: %02X<br>',ord($actionblock[$n])));
                            $value = explode(':',$value);
                            
                          //check for actual content
                            $pos = strpos($actionblock,"\0",$n);
                            $value = substr($actionblock, $n,$pos-$n);
                          //  echo "n increased by ".($pos-$n+1)."<br>";
                            $n=$n+$pos-$n+1;
                            $value = str_replace("\0", "", $value);
                            
                           // echo(sprintf('Hex von n: %02X<br>',ord($actionblock[$n])));
                       
                            
                            //echo $value."<br>";
                            $value = explode(" ",$value,10);
                            
                            if (($value[0]) === "Event"){
                                //We had an event, lets check it
                                $pid= $value[2];
                                
                                switch ($value[1]){
                                    case "unit_created":
                                      
                                       // if (!in_array($value[2],$this->arm["player"][$pid]))
                                        //    $this->arm["player"][$pid][$value[2]] = 0;
                                        $wc3unitcode= $this->hex2str(dechex ($value[3]));
                                        $this->arm["player"][$pid]["units_created"][$wc3unitcode]++;
                                        break;
                                    case "hero_skill":
                                       
                                        $wc3herocode= $this->hex2str(dechex ($value[3]));
                                        $wc3abilcode= $this->hex2str(dechex ($value[4]));
                                        $this->arm["player"][$pid]["hero_skills"][$wc3herocode][$wc3abilcode]++;
                                        break;
                                    case "hero_level":
                                        $wc3herocode= $this->hex2str(dechex ($value[3]));
                                        $this->arm["player"][$pid]["hero_level"][] = array("hero_id"=>$wc3herocode,"time"=>$value[4],"name"=>substr(convert_itemid($wc3herocode), 2),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($wc3herocode),2))); 
                                        break;                                
                                    case "gold_mined_total":
                                        //echo $value."<br>";
                                        //echo "gold_mined_total pid:".$pid." exec: ".$value[4]." val: ".$value[3]."<br>";
                                        $this->arm["player"][$pid]["gold_mined_total"][] =array("exec"=>$value[4],"val"=>$value[3]);
                                        break;
                                    case "gold_mined_upkeep":
                                        //echo $value."<br>";
                                        //echo "gold_mined_upkeep pid:".$pid." exec: ".$value[4]." val: ".$value[3]."<br>";
                                        $this->arm["player"][$pid]["gold_mined_upkeep"][] =array("exec"=>$value[4],"val"=>$value[3]);
                                        break;                                    
                                    case "upgrade_finished":
                                        $wc3unitcode= $this->hex2str(dechex ($value[3]));
                                        $this->arm["player"][$pid]["upgrade"][] =array("game_seconds"=>$value[4],"upgrade_id"=>$wc3unitcode,"name"=>substr(convert_itemid($wc3unitcode), 2),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($wc3unitcode),2)));
                                        break;
                                    case "research_finished":
                                        $split = explode(":",$value[4]);
                                        $wc3unitcode= $this->hex2str(dechex ($value[3]));
                                        $this->arm["player"][$pid]["research"][] =array("level"=>$split[0],"game_seconds"=>$split[1],"research_id"=>$wc3unitcode,"name"=>substr(convert_itemid($wc3unitcode), 2),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($wc3unitcode),2)));
                                        break;                                        
                                    
                                }
                                
                            }
                            
                            elseif ($value[0] === "init" && $value[1] === "pid"){
                                $this->arm["player"][$value[2]]["name"] = $value[3];
                            }
                            elseif ($value[0] === "DefEvent"){
                                $this->arm["detected_events"][] = $value[1];
                            }
                                
                                
                                                        
                        }
                        $n+=4;
                       // echo(sprintf('Hex von n: %02X<br>',ord($actionblock[$n])));

                        break;
                  
							 
			            
                        
                                                
					case 0x10:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 13) {
							$n++; // ability flag is one byte longer
						}
						
						$itemid = strrev(substr($actionblock, $n+2, 4));
						$value = convert_itemid($itemid);
						
						if (!$value) {
							$this->players[$player_id]['actions_details'][convert_action('ability')]++;
							
							// handling Destroyers
							if (ord($actionblock{$n+2}) == 0x33 && ord($actionblock{$n+3}) == 0x02) {
								$name = substr(convert_itemid('ubsp'), 2);
								$this->players[$player_id]['units']['order'][$this->time] = $this->players[$player_id]['units_multiplier'].' '.$name;
								$this->players[$player_id]['units'][$name]++;
								
								$name = substr(convert_itemid('uobs'), 2);
								$this->players[$player_id]['units'][$name]--;
							}
						} else {
							$this->players[$player_id]['actions_details'][convert_action('buildtrain')]++;
							
							if (!$this->players[$player_id]['race_detected']) {
								if ($race_detected = convert_race($itemid)) {
									$this->players[$player_id]['race_detected'] = $race_detected;
								}
							}
							
							$name = substr($value, 2);
							switch ($value{0}) {
								case 'u':
									// preventing duplicated units
									if (($this->time - $this->players[$player_id]['units_time'] > ACTION_DELAY || $itemid != $this->players[$player_id]['last_itemid'])
									// at the beginning of the game workers are queued very fast, so
									// it's better to omit action delay protection
									|| (($itemid == 'hpea' || $itemid == 'ewsp' || $itemid == 'opeo' || $itemid == 'uaco') && $this->time - $this->players[$player_id]['units_time'] > 0)) {
										$this->players[$player_id]['units_time'] = $this->time;
										$this->players[$player_id]['units']['order'][$this->time] = $this->players[$player_id]['units_multiplier'].' '.$name;
										$this->players[$player_id]['units'][$name] += $this->players[$player_id]['units_multiplier'];
									}
									break;
								case 'b':
									$this->players[$player_id]['buildings']['order'][$this->time] = $name;
									$this->players[$player_id]['buildings'][$name]++;
									break;
								case 'h':
									$this->players[$player_id]['heroes']['order'][$this->time] = $name;
									$this->players[$player_id]['heroes'][$name]['revivals']++;
									break;
								case 'a':
									list($hero, $ability) = explode(':', $name);
									$retraining_time = $this->players[$player_id]['retraining_time'];
									if (!$this->players[$player_id]['heroes'][$hero]['retraining_time']) {
										$this->players[$player_id]['heroes'][$hero]['retraining_time'] = 0;
									}
									
									// preventing too high levels (avoiding duplicated actions)
									// the second condition is mainly for games with random heroes
									// the third is for handling Tome of Retraining usage
									if (($this->time - $this->players[$player_id]['heroes'][$hero]['ability_time'] > ACTION_DELAY
									|| !$this->players[$player_id]['heroes'][$hero]['ability_time']
									|| $this->time - $retraining_time < RETRAINING_TIME)
									&& $this->players[$player_id]['heroes'][$hero]['abilities'][$retraining_time][$ability] < 3) {
										if ($this->time - $retraining_time > RETRAINING_TIME) {
											$this->players[$player_id]['heroes'][$hero]['ability_time'] = $this->time;
											$this->players[$player_id]['heroes'][$hero]['level']++;
											$this->players[$player_id]['heroes'][$hero]['abilities'][$this->players[$player_id]['heroes'][$hero]['retraining_time']][$ability]++;
										} else {
											$this->players[$player_id]['heroes'][$hero]['retraining_time'] = $retraining_time;
											$this->players[$player_id]['heroes'][$hero]['abilities']['order'][$retraining_time] = 'Retraining';
											$this->players[$player_id]['heroes'][$hero]['abilities'][$retraining_time][$ability]++;
										}
										$this->players[$player_id]['heroes'][$hero]['abilities']['order'][$this->time] = $ability;
									}
									break;
								case 'i':
									$this->players[$player_id]['items']['order'][$this->time] = $name;
									$this->players[$player_id]['items'][$name]++;
									
									if ($itemid == 'tret') {
										$this->players[$player_id]['retraining_time'] = $this->time;
									}
									break;
								case 'p':
									// preventing duplicated upgrades
									if ($this->time - $this->players[$player_id]['upgrades_time'] > ACTION_DELAY || $itemid != $this->players[$player_id]['last_itemid']) {
										$this->players[$player_id]['upgrades_time'] = $this->time;
										$this->players[$player_id]['upgrades']['order'][$this->time] = $name;
										$this->players[$player_id]['upgrades'][$name]++;
									}
									break;
								default:
									$this->errors[$this->time] = 'Unknown ItemID at '.convert_time($this->time).': '.$value;
									break;
							}
							$this->players[$player_id]['last_itemid'] = $itemid;
						}
	
						if ($this->header['major_v'] >= 7) {
							$n+=14;
						} else {
							$n+=6;
						}
						break;
	
					// Unit/building ability (with target position)
					case 0x11:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 13) {
							$n++; // ability flag
						}
						if (ord($actionblock{$n+2}) <= 0x19 && ord($actionblock{$n+3}) == 0x00) { // basic commands
							$this->players[$player_id]['actions_details'][convert_action('basic')]++;
						} else {
							$this->players[$player_id]['actions_details'][convert_action('ability')]++;
						}
						$value = strrev(substr($actionblock, $n+2, 4));
						if ($value = convert_buildingid($value)) {
							$this->players[$player_id]['buildings']['order'][$this->time] = $value;
							$this->players[$player_id]['buildings'][$value]++;
						}
						if ($this->header['major_v'] >= 7) {
							$n+=22;
						} else {
							$n+=14;
						}
						break;
	
					// Unit/building ability (with target position and target object ID)
					case 0x12:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 13) {
							$n++; // ability flag
						}
						if (ord($actionblock{$n+2}) == 0x03 && ord($actionblock{$n+3}) == 0x00) { // rightclick
							$this->players[$player_id]['actions_details'][convert_action('rightclick')]++;
						} elseif (ord($actionblock{$n+2}) <= 0x19 && ord($actionblock{$n+3}) == 0x00) { // basic commands
							$this->players[$player_id]['actions_details'][convert_action('basic')]++;
						} else {
							$this->players[$player_id]['actions_details'][convert_action('ability')]++;
						}
						if ($this->header['major_v'] >= 7) {
							$n+=30;
						} else {
							$n+=22;
						}
						break;
	
					// Give item to Unit / Drop item on ground
					case 0x13:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 13) {
							$n++; // ability flag
						}
						$this->players[$player_id]['actions_details'][convert_action('item')]++;
						if ($this->header['major_v'] >= 7) {
							$n+=38;
						} else {
							$n+=30;
						}
						break;
	
					// Unit/building ability (with two target positions and two item IDs)
					case 0x14:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 13) {
							$n++; // ability flag
						}
						if (ord($actionblock{$n+2}) == 0x03 && ord($actionblock{$n+3}) == 0x00) { // rightclick
							$this->players[$player_id]['actions_details'][convert_action('rightclick')]++;
						} elseif (ord($actionblock{$n+2}) <= 0x19 && ord($actionblock{$n+3}) == 0x00) { // basic commands
							$this->players[$player_id]['actions_details'][convert_action('basic')]++;
						} else {
							$this->players[$player_id]['actions_details'][convert_action('ability')]++;
						}
						if ($this->header['major_v'] >= 7) {
							$n+=43;
						} else {
							$n+=35;
						}
						break;
	
					// Change Selection (Unit, Building, Area)
					case 0x16:
						$temp = unpack('Cmode/vnum', substr($actionblock, $n+1, 3));
						if ($temp['mode'] == 0x02 || !$was_deselect) {
							$this->players[$player_id]['actions']++;
							$this->players[$player_id]['actions_details'][convert_action('select')]++;
						}
						$was_deselect = ($temp['mode'] == 0x02);
						
						$this->players[$player_id]['units_multiplier'] = $temp['num'];
						$n+=4 + ($temp['num'] * 8);
						break;
	
					// Assign Group Hotkey
					case 0x17:
						$this->players[$player_id]['actions']++;
						$this->players[$player_id]['actions_details'][convert_action('assignhotkey')]++;
						$temp = unpack('Cgroup/vnum', substr($actionblock, $n+1, 3));
						$this->players[$player_id]['hotkeys'][$temp['group']]['assigned']++;
						$this->players[$player_id]['hotkeys'][$temp['group']]['last_totalitems'] = $temp['num'];
	
						$n+=4 + ($temp['num'] * 8);
						break;
	
					// Select Group Hotkey
					case 0x18:
						$this->players[$player_id]['actions']++;
						$this->players[$player_id]['actions_details'][convert_action('selecthotkey')]++;
						$this->players[$player_id]['hotkeys'][ord($actionblock{$n+1})]['used']++;
	
						$this->players[$player_id]['units_multiplier'] = $this->players[$player_id]['hotkeys'][ord($actionblock{$n+1})]['last_totalitems'];
						$n+=3;
						break;
	
					// Select Subgroup
					case 0x19:
						// OR is for torunament reps which don't have build_v
						if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) {
							if ($was_subgroup) { // can't think of anything better (check action 0x1A)
								$this->players[$player_id]['actions']++;
								$this->players[$player_id]['actions_details'][convert_action('subgroup')]++;
								
								// I don't have any better idea what to do when somebody binds buildings
								// of more than one type to a single key and uses them to train units
								// TODO: this is rarely executed, maybe it should go after if ($was_subgroup) {}?
								$this->players[$player_id]['units_multiplier'] = 1;
							}
							$n+=13;
						} else {
							if (ord($actionblock{$n+1}) != 0 && ord($actionblock{$n+1}) != 0xFF && !$was_subupdate) {
								$this->players[$player_id]['actions']++;
								$this->players[$player_id]['actions_details'][convert_action('subgroup')]++;
							}
							$was_subupdate = (ord($actionblock{$n+1}) == 0xFF);
							$n+=2;
						}
						break;
	
					// some subaction holder?
					// version < 14b: Only in scenarios, maybe a trigger-related command
					case 0x1A:
						// OR is for torunament reps which don't have build_v
						if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) {
							$n+=1;
							$was_subgroup = ($prev == 0x19 || $prev == 0); //0 is for new blocks which start with 0x19
						} else {
							$n+=10;
						}
						break;
	
					// Only in scenarios, maybe a trigger-related command
					// version < 14b: Select Ground Item
					case 0x1B:
						// OR is for torunament reps which don't have build_v
						if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) {
							$n+=10;
						} else {
							$this->players[$player_id]['actions']++;
							$n+=10;
						}
						break;
						
					// Select Ground Item
					// version < 14b: Cancel hero revival (new in 1.13)
					case 0x1C:
						// OR is for torunament reps which don't have build_v
						if ($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) {
							$this->players[$player_id]['actions']++;
							$n+=10;
						} else {
							$this->players[$player_id]['actions']++;
							$n+=9;
						}
						break;
						
					// Cancel hero revival
					// Remove unit from building queue
					case 0x1D:
					case 0x1E:
						// OR is for torunament reps which don't have build_v
						if (($this->header['build_v'] >= 6040 || $this->header['major_v'] > 14) && $action != 0x1E) {
							$this->players[$player_id]['actions']++;
							$n+=9;
						} else {
							$this->players[$player_id]['actions']++;
							$this->players[$player_id]['actions_details'][convert_action('removeunit')]++;
							$value = convert_itemid(strrev(substr($actionblock, $n+2, 4)));
							$name = substr($value, 2);
							switch ($value{0}) {
								case 'u':
									// preventing duplicated units cancellations
									if ($this->time - $this->players[$player_id]['runits_time'] > ACTION_DELAY || $value != $this->players[$player_id]['runits_value']) {
										$this->players[$player_id]['runits_time'] = $this->time;
										$this->players[$player_id]['runits_value'] = $value;
										$this->players[$player_id]['units']['order'][$this->time] = '-1 '.$name;
										$this->players[$player_id]['units'][$name]--;
									}
									break;
								case 'b':
									$this->players[$player_id]['buildings'][$name]--;
									break;
								case 'h':
									$this->players[$player_id]['heroes'][$name]['revivals']--;
									break;
								case 'p':
									// preventing duplicated upgrades cancellations
									if ($this->time - $this->players[$player_id]['rupgrades_time'] > ACTION_DELAY || $value != $this->players[$player_id]['rupgrades_value']) {
										$this->players[$player_id]['rupgrades_time'] = $this->time;
										$this->players[$player_id]['rupgrades_value'] = $value;
										$this->players[$player_id]['upgrades'][$name]--;
									}
									break;
							}
							$n+=6;
						}
						break;
	
					// Found in replays with patch version 1.04 and 1.05.
					case 0x21:
						$n+=9;
						break;
	
					// Change ally options
					case 0x50:
						$n+=6;
						break;
	
					// Transfer resources
					case 0x51:
						$n+=10;
						break;
	
					// Map trigger chat command (?)
					case 0x60:
						$n+=9;
						while ($actionblock{$n} != "\x00") {
							$n++;
						}
						++$n;
						break;
	
					// ESC pressed
					case 0x61:
						$this->players[$player_id]['actions']++;
						$this->players[$player_id]['actions_details'][convert_action('esc')]++;
						++$n;
						break;
	
					// Scenario Trigger
					case 0x62:
						if ($this->header['major_v'] >= 7) {
							$n+=13;
						} else {
							$n+=9;
						}
						break;
	
					// Enter select hero skill submenu for WarCraft III patch version <= 1.06
					case 0x65:
						$this->players[$player_id]['actions']++;
						$this->players[$player_id]['actions_details'][convert_action('heromenu')]++;
						++$n;
						break;
	
					// Enter select hero skill submenu
					// Enter select building submenu for WarCraft III patch version <= 1.06
					case 0x66:
						$this->players[$player_id]['actions']++;
						if ($this->header['major_v'] >= 7) {
							$this->players[$player_id]['actions_details'][convert_action('heromenu')]++;
						} else {
							$this->players[$player_id]['actions_details'][convert_action('buildmenu')]++;
						}
						$n+=1;
						break;
	
					// Enter select building submenu
					// Minimap signal (ping) for WarCraft III patch version <= 1.06
					case 0x67:
						if ($this->header['major_v'] >= 7) {
							$this->players[$player_id]['actions']++;
							$this->players[$player_id]['actions_details'][convert_action('buildmenu')]++;
							$n+=1;
						} else {
							$n+=13;
						}
						break;
	
					// Minimap signal (ping)
					// Continue Game (BlockB) for WarCraft III patch version <= 1.06
					case 0x68:
						if ($this->header['major_v'] >= 7) {
							$n+=13;
						} else {
							$n+=17;
						}
						break;
	
					// Continue Game (BlockB)
					// Continue Game (BlockA) for WarCraft III patch version <= 1.06
					case 0x69:
					// Continue Game (BlockA)
					case 0x6A:
						$this->continue_game = 1;
						$n+=17;
						break;
	
					// Pause game
					case 0x01:
						$this->pause = true;
						$temp = '';
						$temp['time'] = $this->time;
						$temp['text'] = convert_chat_mode(0xFE, $this->players[$player_id]['name']);
						$this->chat[] = $temp;
						$n+=1;
						break;
	
					// Resume game
					case 0x02:
						$temp = '';
						$this->pause = false;
						$temp['time'] = $this->time;
						$temp['text'] = convert_chat_mode(0xFF, $this->players[$player_id]['name']);
						$this->chat[] = $temp;
						$n+=1;
						break;
	
					// Increase game speed in single player game (Num+)
					case 0x04:
					// Decrease game speed in single player game (Num-)
					case 0x05:
						$n+=1;
						break;
	
					// Set game speed in single player game (options menu)
					case 0x03:
						$n+=2;
						break;
	
					// Save game
					case 0x06:
						$i=1;
						while ($actionblock{$n} != "\x00") {
							$n++;
						}
						$n+=1;
						break;
	
					// Save game finished
					case 0x07:
						$n+=5;
						break;
	
					// Only in scenarios, maybe a trigger-related command
					case 0x75:
						$n+=2;
						break;
	
					default:
						$temp = '';
						echo "error!";
						for ($i=3; $i<$n; $i++) { // first 3 bytes are player ID and length
							$temp .= sprintf('%02X', ord($actionblock{$i})).' ';
						}
							 
						$temp .= '['.sprintf('%02X', ord($actionblock{$n})).'] ';
						
						for ($i=1; $n+$i<strlen($actionblock); $i++) {
							$temp .= sprintf('%02X', ord($actionblock{$n+$i})).' ';
						}
						
						$this->errors[] = 'Unknown action at '.convert_time($this->time).': 0x'.sprintf('%02X', $action).', prev: 0x'.sprintf('%02X', $prev).', dump: '.$temp;
						
						// skip to the next CommandBlock
						// continue 3, not 2 because of http://php.net/manual/en/control-structures.continue.php#68193
						// ('Current functionality treats switch structures as looping in regards to continue.')
						continue 3;
				}
				
			}
			$was_deselect = ($action == 0x16);
			$was_subupdate = ($action == 0x19);
		}
	}
	
	function cleanup() {
        /*
        
        case 'rightclick': $value = 'Right click'; break;
		case 'select': $value = 'Select / deselect'; break;
		case 'selecthotkey': $value = 'Select group hotkey'; break;
		case 'assignhotkey': $value = 'Assign group hotkey'; break;
		case 'ability': $value = 'Use ability'; break;
		case 'basic': $value = 'Basic commands'; break;
		case 'buildtrain': $value = 'Build / train'; break;
		case 'buildmenu': $value = 'Enter build submenu'; break;
		case 'heromenu': $value = 'Enter hero\'s abilities submenu'; break;
		case 'subgroup': $value = 'Select subgroup'; break;
		case 'item': $value = 'Give item / drop item'; break;
		case 'removeunit': $value = 'Remove unit from queue'; break;
		case 'esc': $value = 'ESC pressed'; break;
        
        */
        $allActionStrings = array("Right click", "Select / deselect","Select group hotkey","Assign group hotkey","Use ability","Basic commands","Build / train","Enter build submenu",
                                  "Enter hero\'s abilities submenu","Select subgroup","Give item / drop item","Remove unit from queue","Esc pressed");
        
        
        
		// players time cleanup
		foreach ($this->players as $player) {
			if (!$player['time']) {
				$this->players[$player['player_id']]['time'] = $this->header['length'];
			}
		}
	
		// counting apm
		if ($this->parse_actions) {
			foreach ($this->players as $player_id=>$info) {
				// whole team 12 are observers/referees
				if ($this->players[$player_id]['team'] != 12 && $this->players[$player_id]['computer'] == 0) {
					$this->players[$player_id]['apm'] = $this->players[$player_id]['actions'] / $this->players[$player_id]['time'] * 60000;
				}
                
                //normalize player action details so that all players have all types of actions in their data even if they never did one or many of them in the actual game
                foreach (array_diff(array_keys($info["actions_details"]),$allActionStrings) as $key){
                    $this->players[$player_id]['actions_details'][$key] = 0;
                }
                asort($this->players[$player_id]["actions_details"]);
			}
		}
	
		// splitting teams
		foreach ($this->players as $player_id=>$info) {
			if (isset($info['team'])) { // to eliminate zombie-observers caused by Waaagh!TV
				$this->teams[$info['team']][$player_id] = $info;
			}
		}
	
		// winner/loser cleanup
		if ($this->game['winner_team'] == 99) { // saver
			$this->game['winner_team'] = $this->players[$this->game['saver_id']]['team'];
		} elseif ($this->game['loser_team'] == 99) {
			$this->game['loser_team'] = $this->players[$this->game['saver_id']]['team'];
		}
	
		$winner = strlen($this->game['winner_team']);
		$loser = strlen($this->game['loser_team']);
		if (!$winner && $loser) {
			foreach ($this->teams as $team_id=>$info) {
				if ($team_id != $this->game['loser_team'] && $team_id != 12) {
					$this->game['winner_team'] = $team_id;
					break;
				}
			}
		} elseif (!$loser && $winner) {
			foreach ($this->teams as $team_id=>$info) {
				if ($team_id != $this->game['winner_team'] && $team_id != 12) {
					$this->game['loser_team'] = $team_id;
					break;
				}
			}
		}
        $this->generateJSONDataStructure();
	}
    
    function generateJSONDataStructure(){
          $teams = array();
        $teams_simple = array();
		// players time cleanup
		foreach ($this->players as $id=>$player) {
			if (!$player['time']) {
				$this->players[$player['player_id']]['time'] = $this->header['length'];
                
			}
           // var_dump($player["actions_details"]);
            
            $newUnits = array();
            $newUnitOrder =array();
            
            foreach ($player["units"]["order"] as $timestamp => $unitstring){
                $split = explode(" ",$unitstring,2);
                $newUnitOrder[]=array("time"=>$timestamp,"amount"=>$split[0],"name"=>$unitstring);
            }
            
            unset ($player["units"]["order"]);
            foreach ($player["units"] as $unitname => $count){
                $newUnits[]=array("name"=>$unitname,"count"=>$count);
            }        
            
            $player["units"] = $newUnits;
            $player["units_order"] = $newUnitOrder;
            
            
            $newActions = array();
            foreach ($player["actions_details"] as $action => $count){
                $newActions[]=array("name"=>$action,"count"=>$count);
            }            
            $player["actions_details"] = $newActions;
            
            
            $newBuildings = array();
            $newBuildingOrder =array();
            
            foreach ($player["buildings"]["order"] as $timestamp => $buildingstring){
                $newBuildingOrder[]=array("time"=>$timestamp,"name"=>$buildingstring);
            }
            
            unset ($player["buildings"]["order"]);
            foreach ($player["buildings"] as $buildingname => $count){
                $newBuildings[]=array("name"=>$buildingname,"count"=>$count);
            }                    
            $player["buildings"] = $newBuildings;
            $player["buildings_order"] = $newBuildingOrder;            
            
            
            $newUpgrades = array();
            $newUpgradeOrder =array();
            
            foreach ($player["upgrades"]["order"] as $timestamp => $buildingstring){
                $newUpgradeOrder[]=array("time"=>$timestamp,"name"=>$buildingstring);
            }
            
            unset ($player["upgrades"]["order"]);
            foreach ($player["upgrades"] as $buildingname => $count){
                $newUpgrades[]=array("name"=>$buildingname,"count"=>$count);
            }                    
            $player["upgrades"] = $newUpgrades;
            $player["upgrades_order"] = $newUpgradeOrder;                
            
            
            $newItems = array();
            $newItemOrder =array();
            
            foreach ($player["items"]["order"] as $timestamp => $buildingstring){
                $newItemOrder[]=array("time"=>$timestamp,"name"=>$buildingstring);
            }
            
            unset ($player["items"]["order"]);
            foreach ($player["items"] as $buildingname => $count){
                $newItems[]=array("name"=>$buildingname,"count"=>$count);
            }                    
            $player["items"] = $newItems;
            $player["items_order"] = $newItemOrder;      
            
            
            
            $newHeroes= array();
            $newHeroOrder =array();
            
            foreach ($player["heroes"]["order"] as $timestamp => $buildingstring){
                $newHeroOrder[]=array("time"=>$timestamp,"name"=>$buildingstring);
            }
            
            unset ($player["heroes"]["order"]);
            foreach ($player["heroes"] as $heroname => $properties){
                if (strlen($heroname)<=2){
                    unset ($this->players[$id]["heroes"][$heroname]);
                    continue;
                }
                $newOrder = array();
                $newAbilitys = array();
                foreach ($properties["abilities"]["order"] as $time => $abilname){
                    $newOrder[] = array("time"=>$time,"name"=>$abilname);
                }
                
                $properties["abilitys_order"] = $newOrder;
                unset ($properties["abilities"]["order"]);
                foreach ($properties["abilities"][0] as $abilname => $abillevel){
                    $newAbilitys[] = array("name"=>$abilname,"level" => $abillevel);
                }
                $properties["abilities"] = $newAbilitys;
                $newHeroes[] = array_merge(array("name"=>$heroname),$properties);
                
            }
            
            $player["heroes"] = $newHeroes;
            $player["heroes_order"] = $newHeroOrder;                          
            
            unset ($player["hotkeys"]);
            
            $teams[$player["team"]]["players"][] = $player;
            $teams[$player["team"]]["team_id"] = $player["team"];
            
            $player_simple = array();
            foreach ($player as $name => $val){
                if (!is_array($val)){
                    $player_simple[$name] = $val;
                }
            }
            unset($player_simple["slot_status"]);
            unset($player_simple["actions"]);
            unset($player_simple["computer"]);
            unset($player_simple["ai_strength"]);
            unset($player_simple["handicap"]);
            unset($player_simple["retraining_time"]);
            unset($player_simple["units_multiplier"]);
            unset($player_simple["units_time"]);
            unset($player_simple["last_itemid"]);
            unset($player_simple["upgrades_time"]);
            unset($player_simple["time"]);
            unset($player_simple["leave_reason"]);
            unset($player_simple["leave_result"]);
            $teams_simple[$player["team"]]["players"][] = $player_simple;
		}
        //var_dump($this->arm["player"]);
        foreach ($this->arm["player"] as $i => $player){
            $rawX = array();
            $rawY = array();    
            $name = $this->arm["player"][$i]["name"];
      
            foreach ($this->players as $player){
                if ($player["name"] == $name){
                    $this->arm["player"][$i]["replay_player_id"] = $player["player_id"];
                    break;
                }
                    
            }
            
            foreach ($this->arm["player"][$i]["gold_mined_total"] as $key => $value){
                $rawX[] = intval($value["exec"]);
                $rawY[]= intval($value["val"]);
            }
            $this->arm["player"][$i]["gold_mined_total_raw_x"] = $rawX;
            $this->arm["player"][$i]["gold_mined_total_raw_y"] = $rawY;
            $rawX = array();
            $rawY = array();                
            foreach ($this->arm["player"][$i]["gold_mined_upkeep"] as $key => $value){
                $rawX[] = intval($value["exec"]);
                $rawY[]= intval($value["val"]);
            }
            $this->arm["player"][$i]["gold_mined_upkeep_raw_x"] = $rawX;
            $this->arm["player"][$i]["gold_mined_upkeep_raw_y"] = $rawY; 
            
            
            $temp = $this->arm["player"][$i]["hero_skills"];
            $new = array();
            foreach ($temp as $key => $val){
                $abils = array();
                foreach ($val as $ability => $level){
                    list($hero, $abil_name) = explode(':', substr(convert_itemid($ability),2));
                    $abils[] = array("ability_id"=>$ability,"level"=>$level,"name"=>$abil_name,"name_trim"=>str_replace(' ', '',  $abil_name));
                }
                $new[]=array("unit_id"=>$key,"abilities"=>$abils,"name"=>str_replace(' ', '',  substr(convert_itemid($key),2)),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($key),2)));
            }
            $this->arm["player"][$i]["hero_skills"] = $new;
            
            
            $temp = $this->arm["player"][$i]["units_created"];
            $new = array();
            foreach ($temp as $key => $val){
                $new[]= array("unit_id"=>$key,"count"=>$val,"name"=>substr(convert_itemid($key),2),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($key),2)));
            }
            $this->arm["player"][$i]["units_created"] = $new;
            
            $temp = $this->arm["player"][$i]["research_finished"];
            $new = array();
            foreach ($temp as $key => $val){
                $new[]= array("upgrade_id"=>$key,"count"=>$val,"name"=>substr(convert_itemid($key),2),"name_trim"=>str_replace(' ', '',  substr(convert_itemid($key),2)));
            }
            $this->arm["player"][$i]["units_created"] = $new;    
            
            
            $temp = $this->arm["player"][$i]["hero_level"];
            $new = array();
            $herolevelstore = [];
            foreach ($temp as $key => $val){
                $herolevelstore[$val.hero_id]++;
                $new[]= array_merge(array("level"=>$herolevelstore[$val.hero_id]),$val);
                
            }
            $this->arm["player"][$i]["hero_level"] = $new;                      
        }
        
            $this->json_parsed_full["teams"] = $teams;
            $this->json_parsed_full["game"]  = $this->game;
            $this->json_parsed_full["teams_simple"]  =$teams_simple;
            $this->json_parsed_full["julas"] = $this->players;
            $this->json_parsed_full["w3gplus"]  =$this->arm;
             
        	}        
}
	

?>
