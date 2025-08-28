<!-- 

Copyright 2019 Andras Molnar

Permission is hereby granted, free of charge, to any person obtaining a copy of this software 
and associated documentation files (the "Software"), to deal in the Software without 
restriction, including without limitation the rights to use, copy, modify, merge, publish, 
distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the 
Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or 
substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING 
BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND 
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, 
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, 
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

The licensee undertakes to mention the name SMARTRIQS, the name of the licensor (Andras Molnar) 
and to cite the following article in all publications in which results of experiments conducted 
with the Software are published: 

Molnar, A. (2019). 
“SMARTRIQS: A Simple Method Allowing Real-Time Respondent Interaction in Qualtrics Surveys". 
Journal of Behavioral and Experimental Finance, 22, 161-169. doi: 10.1016/j.jbef.2019.03.005


-->

<?php
header("Access-Control-Allow-Origin: *"); 		
$status = "";		// Status variable. Used for communcation between Qualtrics and the server
$found = 0;			// Dummy variable: 0 if participantID is not in study database, 1 if participantID is already in database
$errorCount = 0;    // Error count. If everything works fine, this remains 0

// Add functions
include "functions.php";

// Get values from query string
if (empty($_GET["timeZone"]))				{$timeZone = 0;} 				else {$timeZone = 				$_GET["timeZone"];}
$currentTime = getTime($timeZone);
if (empty($_GET["researcherID"])) 			{errorMessage("001");} 			else {$researcherID = 			$_GET["researcherID"];}
if (empty($_GET["studyID"])) 				{errorMessage("002");} 			else {$studyID = 				$_GET["studyID"];}
if (empty($_GET["participantID"])) 			{errorMessage("003");} 			else {$participantID = 			$_GET["participantID"];}
if (empty($_GET["groupSize"])) 				{errorMessage("004");} 			else {$groupSize = 				$_GET["groupSize"];}
if (empty($_GET["numStages"]))      		{errorMessage("005");}			else {$numStages = 				$_GET["numStages"];}
if (empty($_GET["roles"]))        	 		{errorMessage("006");} 			else {$roles = 					$_GET["roles"]; $rolesArray = explode(",", $roles);}	// Since we imported a string, we must use the 'explode' function to convert it to an array
if (empty($_GET["participantRole"]))		{errorMessage("007");}			else {$participantRole = 		$_GET["participantRole"];}
if (empty($_GET["timeOut"]))      			{$timeOut = "no";}				else {$timeOut = 				$_GET["timeOut"];}
if (empty($_GET["timeOutLog"]))    			{$timeOutLog = "";}				else {$timeOutLog = 			$_GET["timeOutLog"];}
if (empty($_GET["dropInactivePlayers"]))	{$dropInactivePlayers = 10;}	else {$dropInactivePlayers =	$_GET["dropInactivePlayers"];}
if (empty($_GET["conditions"]))    			{$conditionsString = "";}		else {$conditions = 			$_GET["conditions"]; $conditionsArray = explode(",", $conditions);} // Same as for roles
if (empty($_GET["participantCondition"]))	{$participantCondition = "";}	else {$participantCondition = 	$_GET["participantCondition"];}
if (empty($_GET["currentRound"])) { $currentRound = 1; } else { $currentRound = (int) $_GET["currentRound"]; }

// Check whether the imported values are valid	
if (file_exists($researcherID) == FALSE)	{errorMessage("101");};
if (in_array($groupSize, array(2,3,4,5,6,7,8,9)) == FALSE)	{errorMessage("104");}
if (count($rolesArray) != $groupSize)		{errorMessage("201");}
if (in_array($participantRole, $rolesArray) == FALSE and $participantRole != "random")	{errorMessage("106");}
if (filter_var($numStages, FILTER_VALIDATE_INT) == FALSE or $numStages < 1)	{errorMessage("105");}
if (count($conditionsArray) > 1 and in_array($participantCondition, $conditionsArray) == FALSE and $participantCondition != "random")	{errorMessage("108");}

// MATCHING 
if ($errorCount == 0) {	
	$playerIndexArray = getPlayerIndexes($groupSize, $numStages);	// Get player indexes
	$datafile = $researcherID . "/" . $studyID . "_rawdata.csv"; 	// Get datafile
	
	if (file_exists($datafile) == FALSE) {	// Check if the study database already exists. If not, then create it (and add header).
		addHeader($datafile, $groupSize, $numStages, $rolesArray);	
	} 
	else {									// Otherwise -- if study database already exists --, import it and check if participantID is already in the database.
		$dataTable = importData($datafile); 
		checkHeader($dataTable, $groupSize, $numStages, $rolesArray);
		
		$allGroups = []; 		// An array that lists all group IDs in the study.
		$openGroups = [];		// An array that lists all group IDs in the study, which have at least one open role.
		$suitableGroups = [];	// An array that lists all group IDs in the study, which have at least one open role, and are suitable for the participant. 
		$selectedGroup = 0;		// The ID of a suitable group to which the participant will be added. 0 if there are no suitable groups.
		
		// Look up participant in data table
		for ($i = 0; $i < count($dataTable); $i++) {
            $thisGroup = $dataTable[$i];
       		
			for ($j = 0; $j < $groupSize; $j++){
				if ($thisGroup[$playerIndexArray[$j]] == $participantID) {
					$found = 1;	
					$groupData = $thisGroup;
				}
       		}
			// Check whether the current group is listed among all groups, whether it is open, and whether it is suitable for the participant
			checkGroupAvailability($thisGroup, $playerIndexArray, $participantCondition, $participantRole, $rolesArray);
        }
        // Check if there is any suitable group. If yes, select the first suitable group
		if (count($suitableGroups) > 0) {$selectedGroup = $suitableGroups[0];}
	}
}	

// If there are still no errors, take an action: (1) retrieve participant data, (2) join group, or (3) crate new group.
if ($errorCount == 0){	
    if ($found == 1) {	// If participantID is found in database...
    	
		getParticipantData($groupData, $participantID, $playerIndexArray, $rolesArray);	// Retrieve participant information from database
		
		// Check who else is in this group. (and drop inactive players if necessary)
		checkGroupmembers($groupData, $groupSize, $currentTime, $playerIndexArray, $participantIndex, $rolesArray, $dropInactivePlayers);
	
		// If there are no more open spots, then the group is ready, change status to 'matched'
		if ($openSpots == 0) { $status = "matched";}	
    	
		else {	// Otherwise, wait.
    		$status = "waiting";
    		if ($timeOut == "yes") {
				fillGroupWithBots($groupData, $currentTime);
				}	// If the botMatch is set to 1 (either by default, or because the participant waited too long), fill open spots with bots.
    	}
   	}

	else {	// If participantID is NOT found in database, then do one of the follolwing:
		$status = "new";
		if ($selectedGroup > 0) {joinGroup($selectedGroup, $datafile);}	// If there is a suitable group, join that group
		else {createNewGroup(max(1,count($allGroups)));}				// Else, if there is no suitable group, create a new group
	
		$participantIndex = $playerIndexArray[array_search($participantRole, $rolesArray)];	// Determine the participant's index based on their role...
		$groupData[$participantIndex] = $participantID;										// ... and assign participantID to that index.
	}
	
	$oldTime = $groupData[$participantIndex + 1];					// Retrieve old timestamp

	// If still waiting and the record is less than 10 seconds old, do nothing...
	if ($status == "waiting" and ($currentTime - $oldTime < 10)){	}
	// ... otherwise, save new record
	else {
		$groupData[$participantIndex + 1] = $currentTime;	// Update the participant's 'last active' timestamp.
		addData($updateData, $groupData, $datafile);		// Add new/updated group data to the datafile. 
	}	
} 



// ---- Build & persist (a,b) per base round per pair (no numbers for mediators) ----
function ensurePairNumbers($researcherID, $studyID, $groupID, $groupStatus, $scheduleAll) {
    if ($groupStatus !== "matched" || !$scheduleAll) { return null; }

    $file = $researcherID . "/" . $studyID . "_groupvars.json";
    $map  = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($map)) { $map = []; }
    if (!isset($map[$groupID])) { $map[$groupID] = []; }

    if (isset($map[$groupID]['pair_numbers']) && is_array($map[$groupID]['pair_numbers'])) {
        return $map[$groupID]['pair_numbers'];
    }

    $pairsByRound = $scheduleAll['pairs_by_round'];
    $set = [10,30,50,70,90];
    $pairNumbers = []; // [round][role] = ['a'=>..., 'b'=>...]

    foreach ($pairsByRound as $roundKey => $pairsMap) {
        $perRound = [];
        foreach ($pairsMap as $role => $partner) {
            if (strcmp($role, $partner) >= 0) { continue; }  // only once per unordered pair
            do {
                $a = $set[array_rand($set)];
                $b = $set[array_rand($set)];
            } while ($a === $b || ($a + $b > 100));
            $perRound[$role]    = ['a'=>$a,'b'=>$b];
            $perRound[$partner] = ['a'=>$a,'b'=>$b];
        }
        $pairNumbers[$roundKey] = $perRound;
    }

    $map[$groupID]['pair_numbers'] = $pairNumbers;
    file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $pairNumbers;
}


// ---- Build & persist 5-round schedule among NON-mediators, plus per-round mediator assignment ----
// Returns a map with:
//   schedule_type: 'pairs_with_mediators'
//   pairs_by_round: ["1"=>[role=>partner, ...], ..., "5"=>[...]]
//   mediator_for_role_by_round: ["1"=>[role=>mediatorRole, ...], ...]
function ensureGroupSchedule($researcherID, $studyID, $groupID, $rolesArray, $groupStatus) {
    $file = $researcherID . "/" . $studyID . "_groupvars.json";
    $map  = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($map)) { $map = []; }
    if (!isset($map[$groupID])) { $map[$groupID] = []; }

    // If schedule already exists, return it
    if (isset($map[$groupID]['pairs_by_round']) && is_array($map[$groupID]['pairs_by_round'])) {
        return $map[$groupID];
    }
    if ($groupStatus !== "matched") { return null; }

    // Work on pair roles only (exclude mediators from pairing)
    [$pairRoles, $mediators] = splitByMediators($rolesArray);
    $n = count($pairRoles);
    if ($n % 2 !== 0) { return null; }      // need even number for circle method (here n=6)
    if ($n < 2)       { return null; }
    $rounds = $n - 1;                       // 5 base rounds for n=6

    // === Circle method on pairRoles (keep your original idea) ===
    $roles = $pairRoles;
    shuffle($roles);                         // randomize initial order
    $fixed = $roles[0];
    $rest  = array_slice($roles, 1);
    $pairsByRound = [];

    for ($r = 1; $r <= $rounds; $r++) {
        $arr = array_merge([$fixed], $rest);
        $pairsMap = [];
        for ($i = 0; $i < $n/2; $i++) {
            $a = $arr[$i];
            $b = $arr[$n - 1 - $i];
            $pairsMap[$a] = $b;
            $pairsMap[$b] = $a;
        }
        $pairsByRound[(string)$r] = $pairsMap;
        $last = array_pop($rest);
        array_unshift($rest, $last);
    }

    // === Random mediator assignment: one mediator per pair per round ===
    // Build mediator map per round so that both members of a pair map to the same mediator.
    $medForRoleByRound = [];
    $mCount = max(1, count($mediators));       // robust to fewer mediators (will cycle)
    foreach ($pairsByRound as $roundKey => $pairsMap) {
        // Collect unique pairs [(a,b), ...] with a < b
        $uniquePairs = [];
        $seen = [];
        foreach ($pairsMap as $a => $b) {
            if (isset($seen[$a]) || isset($seen[$b])) { continue; }
            $pair = (strcmp($a,$b) < 0) ? [$a,$b] : [$b,$a];
            $uniquePairs[] = $pair;
            $seen[$a] = true; $seen[$b] = true;
        }

        // Random permutation of mediators for this round
        $meds = $mediators;
        if ($mCount > 1) { shuffle($meds); }

        // Assign mediators to pairs (cycle if mediators < pairs)
        $medMap = [];
        for ($i = 0; $i < count($uniquePairs); $i++) {
            $med = $meds[$i % $mCount];
            [$ra, $rb] = $uniquePairs[$i];
            $medMap[$ra] = $med;
            $medMap[$rb] = $med;
        }
        $medForRoleByRound[$roundKey] = $medMap;
    }

    $map[$groupID]['schedule_type'] = 'pairs_with_mediators';
    $map[$groupID]['pairs_by_round'] = $pairsByRound;
    $map[$groupID]['mediator_for_role_by_round'] = $medForRoleByRound;

    file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $map[$groupID];
}



// Split roles into "pair roles" (everyone except mediators) and "mediators"
function splitByMediators($rolesArray) {
    $pairRoles = [];
    $mediators = [];
    foreach ($rolesArray as $r) {
        if (preg_match('/^mediator\d+$/i', $r)) { $mediators[] = $r; }
        else { $pairRoles[] = $r; }
    }
    return [$pairRoles, $mediators];
}




createOutputFields($status, $groupID, $participantCondition, $participantRole, $openSpots, $bots, $errorCount, $timeOutLog);


// >>> Emit ALL rounds' info once matched (5 base + 5 reverse like before)
$scheduleAll = ensureGroupSchedule($researcherID, $studyID, $groupID, $rolesArray, $status);

if ($status === "matched" && $scheduleAll) {
    $pairsByRound = $scheduleAll['pairs_by_round'];
    $medForRole   = $scheduleAll['mediator_for_role_by_round'];
    $pairNums     = ensurePairNumbers($researcherID, $studyID, $groupID, $status, $scheduleAll);

    $baseRounds  = count($pairsByRound);      // 5
    $totalRounds = $baseRounds * 2;           // 10
    echo "<totalRounds>" . $totalRounds . "</totalRounds>";

    // Precompute lookups
    [$pairRoles, $mediators] = splitByMediators($rolesArray);

    for ($r = 1; $r <= $totalRounds; $r++) {
        $base = ($r <= $baseRounds) ? $r : ($r - $baseRounds);
        $key  = (string)$base;

        // Participant is a NON-mediator (paired)
        if (!preg_match('/^mediator\d+$/i', $participantRole)) {
            if (!isset($pairsByRound[$key][$participantRole])) { continue; }

            $partnerRole = $pairsByRound[$key][$participantRole];
            $partnerColIndex = $playerIndexArray[array_search($partnerRole, $rolesArray)];
            $partnerID = $groupData[$partnerColIndex];

            echo "<partnerRole_r{$r}>{$partnerRole}</partnerRole_r{$r}>";
            echo "<partnerID_r{$r}>{$partnerID}</partnerID_r{$r}>";

            // Mediator assigned to this pair/round
            $mediatorRole = isset($medForRole[$key][$participantRole]) ? $medForRole[$key][$participantRole] : "";
            $mediatorID = "";
            if ($mediatorRole !== "" && ($idx = array_search($mediatorRole, $rolesArray)) !== false) {
                $mediatorID = $groupData[$playerIndexArray[$idx]];
            }
            echo "<mediatorRole_r{$r}>{$mediatorRole}</mediatorRole_r{$r}>";
            echo "<mediatorID_r{$r}>{$mediatorID}</mediatorID_r{$r}>";

            // Numbers & outside option (flip on rematch)
            if (isset($pairNums[$key][$participantRole])) {
                $a = $pairNums[$key][$participantRole]['a'];
                $b = $pairNums[$key][$participantRole]['b'];
                echo "<numA_r{$r}>{$a}</numA_r{$r}><numB_r{$r}>{$b}</numB_r{$r}>";

                $earlierGetsA = ($r <= $baseRounds);
                $iAmEarlier   = (strcmp($participantRole, $partnerRole) < 0);
                $myOutside = $earlierGetsA ? ($iAmEarlier ? $a : $b) : ($iAmEarlier ? $b : $a);
                echo "<outside_option_R{$r}>{$myOutside}</outside_option_R{$r}>";
            } else {
                echo "<numA_r{$r}></numA_r{$r}><numB_r{$r}></numB_r{$r}><outside_option_R{$r}></outside_option_R{$r}>";
            }
        }
        // Participant is a MEDIATOR
        else {
            // Find the two roles this mediator hosts this base round
            $rolesThisMed = [];
            if (isset($medForRole[$key])) {
                foreach ($medForRole[$key] as $role => $medR) {
                    if ($medR === $participantRole) { $rolesThisMed[] = $role; }
                }
            }
            // Expect exactly 2 (one pair). Be robust if more: take first two.
            if (count($rolesThisMed) > 2) { $rolesThisMed = array_slice($rolesThisMed, 0, 2); }

            $role1 = isset($rolesThisMed[0]) ? $rolesThisMed[0] : "";
            $role2 = isset($rolesThisMed[1]) ? $rolesThisMed[1] : "";

            $id1 = $id2 = "";
            if ($role1 !== "" && ($idx = array_search($role1, $rolesArray)) !== false) {
                $id1 = $groupData[$playerIndexArray[$idx]];
            }
            if ($role2 !== "" && ($idx = array_search($role2, $rolesArray)) !== false) {
                $id2 = $groupData[$playerIndexArray[$idx]];
            }

            // Leave single-partner fields empty to avoid breaking old piping
            echo "<partnerRole_r{$r}></partnerRole_r{$r}><partnerID_r{$r}></partnerID_r{$r}>";

            // Trio info (generic, not gendered)
            echo "<pairRole1_r{$r}>{$role1}</pairRole1_r{$r}><pairID1_r{$r}>{$id1}</pairID1_r{$r}>";
            echo "<pairRole2_r{$r}>{$role2}</pairRole2_r{$r}><pairID2_r{$r}>{$id2}</pairID2_r{$r}>";

            // Numbers for that pair (use role1 as the key if present)
            if ($role1 !== "" && isset($pairNums[$key][$role1])) {
                $a = $pairNums[$key][$role1]['a'];
                $b = $pairNums[$key][$role1]['b'];
                echo "<numA_r{$r}>{$a}</numA_r{$r}><numB_r{$r}>{$b}</numB_r{$r}>";
            } else {
                echo "<numA_r{$r}></numA_r{$r}><numB_r{$r}></numB_r{$r}>";
            }
            // Mediator has no outside option
            echo "<outside_option_R{$r}>0</outside_option_R{$r}>";
            // Echo the mediator identity explicitly too
            $meID = $groupData[$playerIndexArray[array_search($participantRole, $rolesArray)]];
            echo "<mediatorRole_r{$r}>{$participantRole}</mediatorRole_r{$r}><mediatorID_r{$r}>{$meID}</mediatorID_r{$r}>";
        }
    }
}




// Function that adds the header to the data table
function addHeader($datafile, $groupSize, $numStages, $rolesArray) {
	global $headerArray;
	$headerArray = ["Group ID","Condition","Group status"];
    for ($i=0; $i < $groupSize; $i++) {
        array_push($headerArray,$rolesArray[$i]);
        array_push($headerArray,"Last active");
        for ($j=1; $j <= $numStages; $j++){
    	    array_push($headerArray, $rolesArray[$i] . "#" . $j);
        }
    } 
	addData($addHeader, $headerArray, $datafile);
}

// Function that manages whether a group will be listed in the array of 1) all groups, 2) open groups, 3) suitable groups.
function checkGroupAvailability($thisGroup, $playerIndexArray, $participantCondition, $participantRole, $rolesArray){
	global $allGroups, $openGroups, $suitableGroups;	

	if (in_array($thisGroup[0], $allGroups) == FALSE) {  array_push($allGroups, $thisGroup[0]);}
	
	// Check if group is open			
    if ($thisGroup[2] == "open" ) {
        if (in_array($thisGroup[0], $openGroups) == FALSE){ array_push($openGroups, $thisGroup[0]);}
        
		// Check if this group is suitable for the participant. First, check if the group condition matches the participant's, or if the participant can be assigned to any condition (participantCondition=random)
        if ($thisGroup[1] == $participantCondition or $participantCondition == "random"){  
			
			// Check if the participant's desired role is available within this group (only if not random)
            if ($participantRole == "random" or $thisGroup[$playerIndexArray[array_search($participantRole, $rolesArray)]] == "[open]"){
                
				// If the desired role is available, then check if the group is already in the suitable group array. Add if it is not there
				if (in_array($thisGroup[0], $suitableGroups) == FALSE){ 
					array_push($suitableGroups, $thisGroup[0]);
				}
            }
            else {
				// If the desired role is NOT available, then check if the group is already in the suitable group array. Remove if it is there
				if (in_array($thisGroup[0], $suitableGroups) == TRUE) {	
					unset($suitableGroups[array_search($thisGroup[0],$suitableGroups,TRUE)]); 
					$suitableGroups = array_values($suitableGroups);
				}
			}
		}
	}
	// If the group is not open, then check if it is already in the open/suitable group arrays. Remove if it is there
	else {
		if (in_array($thisGroup[0], $openGroups) == TRUE) {
			unset($openGroups[array_search($thisGroup[0],$openGroups,TRUE)]);
			$openGroups = array_values($openGroups);
		}
		
		if (in_array($thisGroup[0], $suitableGroups) == TRUE){
			unset($suitableGroups[array_search($thisGroup[0],$suitableGroups,TRUE)]);
			$suitableGroups = array_values($suitableGroups);
		}
	}
}


// Function that fills a group with bots.
function fillGroupWithBots($groupData, $currentTime){
	global $groupData;
	for ($i=3; $i < count($groupData); $i++) {
    	if ($groupData[$i] == "[open]") {
			$groupData[$i] = "BOT " . rand(10000,99999);
			$groupData[$i+1] = $currentTime;
    	}
    }
    $groupData[2] = "matched";
}


// Function that retrieves participant data from group data
function getParticipantData($groupData, $participantID, $playerIndexArray, $rolesArray){
	global $groupID, $participantCondition, $participantIndex, $participantRole;
	$groupID = $groupData[0];				
    $participantCondition = $groupData[1];			
	$participantIndex = array_search($participantID, $groupData);		
	$participantRole = $rolesArray[array_search($participantIndex, $playerIndexArray)];	
}


// Function that checks the status of players in the group, and drop inactive players if necessary.
function checkGroupmembers($groupData, $groupSize, $currentTime, $playerIndexArray, $participantIndex, $rolesArray, $dropInactivePlayers){
	global $players, $bots, $timeOutLog, $openSpots, $groupData;
	
	$players = [];
	$bots = [];
	
	// An array that lists player IDs in the participant's group.
	$openSpots = 0;
	for ($i=0; $i < $groupSize; $i++){
		array_push($players,$groupData[$playerIndexArray[$i]]);
		if ($groupData[$playerIndexArray[$i]] == "[open]"){	$openSpots++;} # If a spot is open, add +1 to openSpots.
		else{
			// Check if this player is BOT. if yes, then add text to timeoutlog.
			if (substr($groupData[$playerIndexArray[$i]],0,4) == "BOT "){
				array_push($bots,$groupData[$playerIndexArray[$i]]);
				$timeOutLog = $timeOutLog . " *** Warning: There was no participant available for role " . $rolesArray[$i] . ". Bot added: " . $groupData[$playerIndexArray[$i]] . ".";
			}
			// If not bot, check activity
			else{
				// If not open, check whether the player has been active. If inactive, drop from group, unless: 1) the target player is the participant, 2) is a BOT, or 3) the group is still open.
				if ($currentTime - $groupData[$playerIndexArray[$i]+1] > ($dropInactivePlayers + 20) and $participantIndex != $playerIndexArray[$i] and strpos($groupData[$playerIndexArray[$i]],"BOT") != 1 and $groupData[2] == "open"){	
					$groupData[$playerIndexArray[$i]] = "[open]";
					$groupData[$playerIndexArray[$i]+1] = "[.....]";
				}
			}
		}			
	}
}


// Function that adds the new player to a suitable group.
function joinGroup($group, $datafile){
	global $groupSize, $playerIndexArray, $participantIndex, $participantRole, $rolesArray, $groupData, $currentTime, $dropInactivePlayers;
	 global $researcherID, $studyID;

	// Retrieve most recent record of selected group:
	$handle_openData = fopen($datafile, "r"); $row = 0;
	while (($thisGroup = fgetcsv($handle_openData, 0, ",")) !== FALSE) {
		$row++;
		if ($thisGroup[0] == $group) {$groupData = $thisGroup;}
	}
	fclose($handle_openData);
	


	// Check status and last activity of group members. If there are inactive players, drop them
	checkGroupmembers($groupData, $groupSize, $currentTime, $playerIndexArray, $participantIndex, $rolesArray, $dropInactivePlayers);
	
	$availableSpots = [];
	for ($i=0; $i < $groupSize; $i++){
		if ($groupData[$playerIndexArray[$i]] == "[open]") {array_push($availableSpots,$i);}
	}
	
	// If role is random, pick one among the available ones
	if ($participantRole == "random"){$participantRole = $rolesArray[$availableSpots[array_rand($availableSpots,1)]];}
	
	// If this was the last available spot, the group is ready
	if (count($availableSpots) == 1) {$groupData[2] = "matched";}
}


// Function that creates a new group
function createNewGroup($groupNumber){
	global $participantCondition, $conditionsArray, $groupData, $allGroups, $groupSize, $numStages, $participantRole, $rolesArray, $openSpots;
   global $researcherID, $studyID;
	// If condition is random, then pick one condition randomly from the condition array:
	if ($participantCondition == "random") {$participantCondition = $conditionsArray[array_rand($conditionsArray,1)];}

	// Set the first three elements of the group data: 1) groupID, 2) group condition, and 3) group status:	
	$groupData = [$groupNumber, $participantCondition, "open"];
	
	// Fill the rest the group data with blanks:
	for ($i=0; $i < $groupSize; $i++) {
		array_push($groupData, "[open]");
		for ($j=1; $j <= $numStages + 1 ; $j++) {array_push($groupData, "[.....]");}
	}
	
	// Add the participant's data to group data:
	if ($participantRole == "random") {$participantRole = $rolesArray[array_rand($rolesArray,1)];};	# If role is random, then pick one role from the role array.

	$openSpots = $groupSize - 1;


}



// Function that creates output fields for Qualtrics.
function createOutputFields($status, $groupID, $participantCondition, $participantRole, $openSpots, $bots, $errorCount, $log){
	echo "<status>" 				. $status 					. "</status>";
	echo "<groupID>" 				. $groupID 					. "</groupID>"; 
	echo "<participantCondition>" 	. $participantCondition 	. "</participantCondition>";
	echo "<participantRole>"		. $participantRole			. "</participantRole>";	
	echo "<openSpots>"				. $openSpots				. "</openSpots>";	
	echo "<bots>"					. implode(",",$bots)		. "</bots>";		
	echo "<errorCount>"				. $errorCount				. "</errorCount>";
	echo "<timeOutLog>" 			. $log 						. "</timeOutLog>";
}



/*
RANDOMIZATION — SIMPLE CHEATSHEET (with tiny examples)
------------------------------------------------------

• When a group becomes "matched", we save TWO stable things to:
    <researcherID>/<studyID>_groupvars.json
1) pairs_by_round  → who partners with whom each round
2) pair_numbers    → the two numbers (a,b) each pair will use

• Partner schedule (simple rule)
- Works for EVEN group sizes. BaseRounds = groupSize - 1.
- Example: groupSize = 6 → BaseRounds = 5 → TotalRounds = 10.
- Rounds 1..5: everyone meets each other once (no repeats).
- Rounds 6..10: same partners again (a straight rematch).

• Pair numbers (a,b) per pair per base round
- Draw a,b from {10,30,50,70,90}.
- Must satisfy: a ≠ b and a + b ≤ 100.
- Example valid: (30,50) → sum 80.  Example invalid: (70,90) → sum 160.
- We REUSE the SAME (a,b) in the rematch (round r+BaseRounds).

• Outside option (who gets a vs b)
- Determine “earlier” role using string order (strcmp): e.g., "female1" < "male3".
- Rounds 1..BaseRounds: earlier role gets a, later role gets b.
- Rounds BaseRounds+1..TotalRounds: REVERSED (earlier gets b, later gets a).
- Example:
      Pair: female1 vs male3, numbers (a,b) = (30,70)
        Round 1: female1→30 (earlier), male3→70 (later)
        Round 6: female1→70, male3→30 (flipped)
    Another:
      Pair: male1 vs male2, numbers (a,b) = (50,70)
        Round 2: male1→50, male2→70
        Round 7: male1→70, male2→50

• What we output back to Qualtrics (per player)
- totalRounds
- partnerRole_r#, partnerID_r#
- numA_r#, numB_r#      (the (a,b) for that pair/round)
- outside_option_R#     (this player’s number that round)  
*/

?>
