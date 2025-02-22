<?php
// We need access to gameRequest here, but it's not global.
// Impl copied from main.php

require_once("util.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."updateThreadsDB.php");


function xtoys($toy,$intensity) {
    if (isset($GLOBALS["XTOYS_WEBHOOK"]) && !empty($GLOBALS["XTOYS_WEBHOOK"])) {
        $url = "https://webhook.xtoys.app/".$GLOBALS["XTOYS_WEBHOOK"]."?action=".$toy."&time=600&intensity=".$intensity;
        file_get_contents($url);
    }
}

function ProcessIntegrations() {
    if (isset($GLOBALS["gameRequest"])) {
        minai_log("info", "Processing request: " . json_encode($GLOBALS["gameRequest"]));
    }
    // Handle allowing third party mods to register things with the context system
    $MUST_DIE=false;
    if (isset($GLOBALS["XTOYS_WEBHOOK"]) && !empty($GLOBALS["XTOYS_WEBHOOK"])) {
        if (isset($GLOBALS["gameRequest"])) {
            if ($GLOBALS["gameRequest"][0]=="udng_vibrate") {
                $vars=explode("@",$GLOBALS["gameRequest"][3]);
                xtoys($vars[0],intval($vars[1]));
                $MUST_DIE=true;
            }
        }
    }
    if (isset($GLOBALS["use_defeat"]) && $GLOBALS["use_defeat"] && IsModEnabled("SexlabDefeat")) {
        $GLOBALS["events_to_ignore"][] = "combatend";
        $GLOBALS["events_to_ignore"][] = "combatendmighty";
    }
    if (isset($GLOBALS["gameRequest"]) && isset($GLOBALS["events_to_ignore"]) && in_array($GLOBALS["gameRequest"][0], $GLOBALS["events_to_ignore"])) {
        minai_log("info", "Event {$GLOBALS["gameRequest"][0]} in ignore list, blocking.");
        $MUST_DIE=true;
    }
    if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] == "minai_init") {
        // This is sent once by the SKSE plugin when the game is loaded. Do our initialization here.
        minai_log("info", "Initializing");
        DropThreadsTableIfExists();
        InitiateDBTables();
        importXPersonalities();
        importScenesDescriptions();
        $MUST_DIE=true;

    }
    if (isset($GLOBALS["gameRequest"]) && strtolower($GLOBALS["gameRequest"][0]) == "storecontext") {
        $db = $GLOBALS['db'];
        $vars=explode("@",$GLOBALS["gameRequest"][3]);
        $modName = $vars[0];
        $eventKey = $vars[1];
        $eventValue = $vars[2];
        $npcName = $vars[3];
        $ttl = intval($vars[4]);
        minai_log("info", "Storing custom context: {$modName}, {$eventKey}, {$eventValue}, {$ttl}");
        $db->delete("custom_context", "modName='".$db->escape($modName)."' AND eventKey='".$db->escape($eventKey)."'");
        $db->insert(
            'custom_context',
            array(
                'modName' => $db->escape($modName),
                'eventKey' => $db->escape($eventKey),
                'eventValue' => $db->escape($eventValue),
                'expiresAt' => time() + $ttl,
                'npcName' => $db->escape($npcName),
                'ttl' => $ttl // already converted to int, no need to escape
            )
        );
        $MUST_DIE=true;
    }
    if (isset($GLOBALS["gameRequest"]) && strtolower($GLOBALS["gameRequest"][0]) == "registeraction") {
        $db = $GLOBALS['db'];
        $vars=explode("@",$GLOBALS["gameRequest"][3]);
        $actionName = $vars[0];
        $actionPrompt = $vars[1];
        $enabled = $vars[2];
        $ttl = intval($vars[3]);
        $targetDescription = $vars[4];
        $targetEnum = $vars[5];
        $npcName = $vars[6];
        minai_log("info", "Registering custom action: {$actionName}, {$actionPrompt}, {$enabled}, {$ttl}");
        $db->delete("custom_actions", "actionName='".$db->escape($actionName)."'");
        $db->insert(
            'custom_actions',
            array(
                'actionName' => $db->escape($actionName),
                'actionPrompt' => $db->escape($actionPrompt),
                'enabled' => $enabled,
                'expiresAt' => time() + $ttl,
                'ttl' => $ttl, // already converted to int, no need to escape
                'targetDescription' => $db->escape($targetDescription),
                'targetEnum' => $db->escape($targetEnum),
                'npcName' => $db->escape($npcName)
            )
        );
        $MUST_DIE=true;
    }
    if (isset($GLOBALS["gameRequest"]) && strtolower($GLOBALS["gameRequest"][0]) == "updatethreadsdb") {
        updateThreadsDB();
        $MUST_DIE=true;
    }
    if (isset($GLOBALS["gameRequest"]) && strtolower($GLOBALS["gameRequest"][0]) =="npc_talk") {
        $vars=explode("@",$GLOBALS["gameRequest"][3]);
        $tmp = explode(":", $vars[0]);
        $speaker = $tmp[sizeof($tmp)-1];
        $target = $vars[1];
        $message = $vars[2];
        minai_log("info", "Processing NPC request ({$speaker} => {$target}: {$message})");
        $GLOBALS["PROMPTS"]["npc_talk"]= [
            "cue"=>[
                "write dialogue for {$GLOBALS["HERIKA_NAME"]}.{$GLOBALS["TEMPLATE_DIALOG"]}  "
            ], 
            "player_request"=>[
                "{$speaker}: {$message} (Talking to {$target})"
            ]
        ];
    }
    if (isset($GLOBALS["gameRequest"]) && in_array(strtolower($GLOBALS["gameRequest"][0]), ["radiant", "radiantsearchinghostile", "radiantsearchingfriend", "radiantcombathostile", "radiantcombatfriend", "minai_force_rechat"])) {
        if (strtolower($GLOBALS["gameRequest"][0]) == "minai_force_rechat" || time() > GetLastInput() + $GLOBALS["input_delay_for_radiance"]) {
            // Block rechat/radiant during sex scenes
            if (IsSexActive()) {
                minai_log("info", "Blocking rechat/radiant during sex scene");
                $MUST_DIE = true;
            }
            else if ($GLOBALS["HERIKA_NAME"] == "The Narrator") {
                // Fail safe
                minai_log("info", "WARNING - Radiant dialogue started with narrator");
                $MUST_DIE = true;
            }
            else {
                // $GLOBALS["HERIKA_NAME"] is npc1
                $GLOBALS["HERIKA_TARGET"] = explode(":", $GLOBALS["gameRequest"][3])[3];
                if ($GLOBALS["HERIKA_TARGET"] == $GLOBALS["HERIKA_NAME"])
                    $GLOBALS["HERIKA_TARGET"] = $GLOBALS["PLAYER_NAME"];
                minai_log("info", "Starting {$GLOBALS["gameRequest"][0]} dialogue between {$GLOBALS["HERIKA_NAME"]} and {$GLOBALS["HERIKA_TARGET"]}");
                StoreRadiantActors($GLOBALS["HERIKA_TARGET"], $GLOBALS["HERIKA_NAME"]);
                $GLOBALS["target"] = $GLOBALS["HERIKA_TARGET"];
            }
        }
        else {
            // Avoid race condition where we send input, the server starts to process the request, and then
            // a radiant request comes in 
            minai_log("info", "Not starting radiance: Input was too recent");
            $MUST_DIE=true;
        }
    }
    if (in_array($GLOBALS["gameRequest"][0],["inputtext","inputtext_s","ginputtext","ginputtext_s","rechat","bored", "radiant", "minai_force_rechat"])) {
        if (!in_array($GLOBALS["gameRequest"][0], ["radiant", "rechat", "minai_force_rechat"]))
            ClearRadiantActors();
        // minai_log("info", "Setting lastInput time.");
        $db = $GLOBALS['db'];
        $id = "_minai_RADIANT//lastInput";
        $db->delete("conf_opts", "id='{$id}'");
        $db->insert(
            'conf_opts',
            array(
                'id' => $id,
                'value' => time()
            )
        );
    }

    // Handle singing events
    /* if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] == "minai_sing") {
        // Set up singing context
        $GLOBALS["ORIGINAL_HERIKA_NAME"] = $GLOBALS["HERIKA_NAME"];
        // Intended for use with the "Self Narrator" functionality
        $GLOBALS["HERIKA_NAME"] = "The Narrator";
        SetNarratorProfile();
        $GLOBALS["HERIKA_NAME"] = $GLOBALS["PLAYER_NAME"];
        $GLOBALS["PROMPTS"]["minai_sing"] = [
            "cue" => [
                "write a musical response as {$GLOBALS["PLAYER_NAME"]}. Be creative and match the mood of the scene."
            ],
            "player_request"=>[    
                "{$GLOBALS["PLAYER_NAME"]} begins singing a song: {$GLOBALS["gamerequest"][3]}.",
            ]
        ];
        
        // Add singing-specific personality traits
        $GLOBALS["HERIKA_PERS"] .= "\nWhen singing, you should be musical and poetic. Format your responses as song lyrics or poetry.\n";
        
        // Force response to be musical
        $GLOBALS["TEMPLATE_DIALOG"] = "Respond with song lyrics or a musical performance.";
        }*/

    // Handle narrator talk events
    if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] == "minai_narrator_talk") {
        SetEnabled($GLOBALS["PLAYER_NAME"], "isTalkingToNarrator", false);
        $GLOBALS["ORIGINAL_HERIKA_NAME"] = $GLOBALS["HERIKA_NAME"];
        $GLOBALS["HERIKA_NAME"] = "The Narrator";
        SetNarratorProfile();
        
        SetNarratorPrompts(isset($GLOBALS["self_narrator"]) && $GLOBALS["self_narrator"]);
    }

    if (isset($GLOBALS["gameRequest"]) && strpos($GLOBALS["gameRequest"][0], "minai_tntr_") === 0) {
        if (ShouldBlockTNTREvent($GLOBALS["gameRequest"][0])) {
            minai_log("info", "Blocking TNTR event: {$GLOBALS["gameRequest"][0]}");
            $MUST_DIE=true;
        }
    }

    if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] == "storetattoodesc") {
        minai_log("info", "Processing storetattoodesc event");
        $MUST_DIE=true;
    }

    // Add handling for minai_combatenddefeat
    if (isset($GLOBALS["gameRequest"]) && $GLOBALS["gameRequest"][0] == "minai_combatenddefeat") {
        // Store the defeat timestamp
        $db = $GLOBALS['db'];
        $id = "_minai_PLAYER//lastDefeat";
        $db->delete("conf_opts", "id='{$id}'");
        $db->insert(
            'conf_opts',
            array(
                'id' => $id,
                'value' => time()
            )
        );
        minai_log("info", "Player was defeated in combat, blocking Attack command for 300 seconds");
        $MUST_DIE=true;
    }

    if ($MUST_DIE) {
        minai_log("info", "Done procesing custom request");
        die('X-CUSTOM-CLOSE');
    }
}

function GetThirdpartyContext() {
    $db = $GLOBALS['db'];
    $ret = "";
    $currentTime = time();
    
	$npcName = $GLOBALS["db"]->escape($GLOBALS["HERIKA_NAME"]);
	$npcName = $GLOBALS["db"]->escape($npcName); // we need to escape twice to catch names with ' in then, like most Khajit names. Probably because the names are escaped before inserting.
	$npcNameLower = strtolower($npcName); // added the same name but in lowercase to be safe, since sometimes Skyrim returns NPC names in all lowercase and those get put into the DB.
	
	$inArray = array("everyone", $npcName, $npcNameLower);
	
	// Add the player name if its not an NPC to NPC conversation
	if (!IsRadiant()) {
		array_push($inArray, $GLOBALS["PLAYER_NAME"]);
	}
	
    $rows = $db->fetchAll(
		"SELECT * FROM custom_context WHERE expiresAt > {$currentTime} AND npcname IN ('" . implode("', '", $inArray) . "')"
	);
    foreach ($rows as $row) {
        minai_log("info", "Inserting third-party context: {$row["eventvalue"]}");
        $ret .= $row["eventvalue"] . "\n";
    }
    return $ret;
}


function RegisterThirdPartyActions() {
    $db = $GLOBALS['db'];
    $currentTime = time();
    // $db->delete("custom_context", "expiresAt < {$currentTime}");
    $rows = $db->fetchAll(
      "SELECT * FROM custom_actions WHERE expiresAt > {$currentTime}"
    );
    foreach ($rows as $row) {
        if ($row["enabled"] == 1 && ((strtolower(strtolower($GLOBALS["HERIKA_NAME"])) == strtolower($row['npcname'])
            || (!IsRadiant() && strtolower($GLOBALS["PLAYER_NAME"])) == strtolower($row['npcname'])) 
            || strtolower($row['npcname']) == "everyone")) {
            $actionName = $row["actionname"];
            $cmdName = "ExtCmd{$actionName}";
            $actionPrompt = $row["actionprompt"];
            $targetDesc = $row["targetdescription"];
            $targetEnum = explode(",", $row["targetenum"]);
            minai_log("info", "Inserting third-party action: {$actionName} ({$actionPrompt})");
            $GLOBALS["F_NAMES"][$cmdName]=$actionName;
            $GLOBALS["F_TRANSLATIONS"][$cmdName]=$actionPrompt;
            $GLOBALS["FUNCTIONS"][] = [
                "name" => $GLOBALS["F_NAMES"][$cmdName],
                "description" => $GLOBALS["F_TRANSLATIONS"][$cmdName],
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "target" => [
                            "type" => "string",
                            "description" => $targetDesc,
                            "enum" => $targetEnum
                        ]
                    ],
                    "required" => ["target"],
                ],
            ];
            $GLOBALS["FUNCRET"][$cmdName]=$GLOBALS["GenericFuncRet"];
            RegisterAction($cmdName);
        }
    }
}

function ShouldBlockTNTREvent($eventName) {
    // Extract source and event from full event name (e.g. "minai_tntr_mimic_triggervoreinstant")
    $parts = explode('_', strtolower($eventName));
    if (count($parts) < 4) return false;
    
    $source = $parts[2];
    $event = $parts[3];
    
    if ($source == "mimic") {
        $blockedEvents = [
            "transvorestage02loop",
            "triggerdie", 
            "triggerattack",
            "triggermimicshake"
        ];
        return in_array($event, $blockedEvents);
    }
    
    if ($source == "deathworm") {
        $blockedEvents = [
            "trigger01"  // Block initial ground trembling event
        ];
        return in_array($event, $blockedEvents);
    }
    
    return false;
}

