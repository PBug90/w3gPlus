# Changes

This library should be fully compatible with existing versions, only enhancing its functionality. It adds another property called "json_parsed_full" to the PHP class object that allows retrieval of W3GPlus-Information as well as well structured output that is JSON-compatible and can be easily used with modern front end Web-Applications like AngularJS.

#### Additional Replay Object property
Extended parser saves the W3GPlus raw-Metadata as an array in the arm (Advanced Replay Meta) property of a Replay object.
Access that object like so:
```php
var_dump($replay->arm);
```


#### Normalized JSON data structures
Can be accessed by using the json_parsed_full property of the Replay object. Currently still work in progress and not 100% reliable yet.
```php
$this->json_parsed_full["teams"] = $teams;
$this->json_parsed_full["game"]  = $this->game;
$this->json_parsed_full["teams_simple"]  =$teams_simple;
$this->json_parsed_full["julas"] = $this->players;
$this->json_parsed_full["w3gplus"]  =$this->arm;
```
Example usage in a simplified PHP backend script using an uploaded replay file as source.

```php
if (isset($_FILES['custom_replay'])) {
    $replay = new replay($_FILES["custom_replay"]['tmp_name']);    
    print (json_encode($replay->json_parsed_full));
    die;
}
```