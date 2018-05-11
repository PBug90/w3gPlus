# w3gPlus

** The parser was moved to a separate repository:
[w3gphp](https://github.com/anXieTyPB/w3gphp)
**

## What?

A new standard for Warcraft 3 Replays. Allows tracking more standard-game-metadata via Hostbots and with replay files. Parsers can then utilize this meta data from replay files and display valuable, reliable information about the game.


![resource sample graph](sample_resource_graph.png?raw=true "Example image showing the gold resources being tracked over time in a Human (red) vs Undead (blue) game.")

## Why?
Replay parsers can only evaluate player input actions, and since even redundant "spam" actions get tracked they naturally have a hard time figuring out what really happened in a game. Also, the game lacks features for tracking interesting additional statistics like resources over time. This standard allows tracking that kind of data in a reliable and persistent way.

## What is being tracked?

* every minute: player gold mined
* every minute: player gold mined considering upkeep
* every minute: player lumber harvested
* whenever a hero learns an ability: the player, hero type-id and ability learned
* whenever a hero levels up: the player, hero type-id and time elapsed in seconds
* whenever a building finishes upgrading: the player, unit type-id and time elapsed in seconds
* whenever an upgrades finishes being researched: the player, upgrade type-id, upgrade level and time elapsed in seconds
* whenever a player finishes training a unit and that unit enters the actual game: the player and the unit id

## Requirements for map implementation

Requires a vJASS-compatible editor.

# Credits

* Strilanc for creating the Map Meta Data Standard (W3MMD) and Library in vJass
* various Ghost++ authors for hostbot-implementation of W3MMD
* Juliusz 'Julas' Gonera for a parser implementation in php (http://w3rep.sourceforge.net/)
