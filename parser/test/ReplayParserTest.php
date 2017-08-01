<?php
declare(strict_types=1);
error_reporting(E_ERROR );
require ( __DIR__ . "/../../vendor/autoload.php" );
require(__DIR__ . "/../ReplayParser.php");


use PHPUnit\Framework\TestCase;


class ReplayParserTest extends TestCase
{

  public function testCaseProvider()
      {
        $files = [];
        foreach(array_diff(scandir(__DIR__ . "/replays"),array("..",".")) as $key => $file){
          $files[] = [__DIR__ . "/replays/".$file];
        }
          // parse your data file however you want
          $this->assertTrue(true);
          return  $files;
      }


      /**
      * @dataProvider testCaseProvider
      */
  public function testReturnedObject($filepath)
  {
      $parser = new ReplayParser();
      $data = json_decode(json_encode($parser->parseReplayFile($filepath)));

      $this->assertObjectHasAttribute ('map', $data);
      $this->assertObjectHasAttribute ('randomSeed', $data);
      $this->assertObjectHasAttribute ('mapLong', $data);
      $this->assertObjectHasAttribute ('gameName', $data);
      $this->assertObjectHasAttribute ('host', $data);
      $this->assertObjectHasAttribute ('filesize', $data);
      $this->assertObjectHasAttribute ('gameLength', $data);
      $this->assertObjectHasAttribute ('version', $data);
      $this->assertObjectHasAttribute ('nwgOffset', $data);
      $this->assertObjectHasAttribute ('teams', $data);
      $this->assertObjectHasAttribute ('observers', $data);
      $this->assertObjectHasAttribute ('chat',$data);
      $this->assertObjectHasAttribute ('w3gplus',$data);
  }

}
