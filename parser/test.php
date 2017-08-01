<?php
include "ReplayParser.php";
error_reporting(E_ERROR);

$parser =new ReplayParser();
$cwd = getcwd();
$directory = $cwd.'/parser/replays/';
$scanned_directory = array_diff(scandir($directory), array('..', '.'));
foreach ($scanned_directory as $file){
  echo $directory.$file."\n";
  $result = json_encode($parser->parseReplayFile($directory.$file));
  $valid = json_decode ($result);
}
