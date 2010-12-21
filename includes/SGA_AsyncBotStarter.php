<?php
/**
 * @file
 * @ingroup SemanticGardening
 *
 * Created on 12.03.2007
 * 
 * @author Kai K�hn
 * 
 */


//get Parameter
$wgRequestTime = microtime(true);

/** */
# Abort if called from a web server
if ( isset( $_SERVER ) && array_key_exists( 'REQUEST_METHOD', $_SERVER ) ) {
	print "This script must be run from the command line\n";
	exit();
}


if( version_compare( PHP_VERSION, '5.0.0' ) < 0 ) {
	print "Sorry! This version of MediaWiki requires PHP 5; you are running " .
	PHP_VERSION . ".\n\n" .
		"If you are sure you already have PHP 5 installed, it may be " .
		"installed\n" .
		"in a different path from PHP 4. Check with your system administrator.\n";
	die( -1 );
}


# Process command line arguments
# Parse arguments

echo "Parse arguments...";

$params = array();
for( $arg = reset( $argv ); $arg !== false; $arg = next( $argv ) ) {
	//-b => BotID
	if ($arg == '-b') {
		$botID = next($argv);
		continue;
	} // -t => TaskID
	if ($arg == '-t') {
		$taskid = next($argv);
		continue;
	} // -u => UserID
	if ($arg == '-u') {
		$userId = next($argv);
		continue;
	}
	if ($arg == '-s') {
		$servername = next($argv);
		continue;
	}
	$params[] = $arg;
}

if (!isset($botID)) {
	print "No bot set! Use option -b. Abort.\n";
    exit();
}
// include commandLine script which provides some basic
// methodes for maintenance scripts
$mediaWikiLocation = dirname(__FILE__) . '/../../..';
require_once "$mediaWikiLocation/maintenance/commandLine.inc";

// set servername, because it is not set properly in async mode (always localhost)
global $wgServer, $wgScriptPath, $wgScript;
$wgServer = $servername;

require_once( $sgagIP . '/includes/SGA_GardeningBot.php');
require_once( $sgagIP . '/includes/SGA_GardeningIssues.php');
require_once("$sgagIP/includes/SGA_ParameterObjects.php");
// include bots
sgagImportBots("$sgagIP/includes/bots");


require_once("SGA_GardeningLog.php");


// run bot

array_shift($params); // get rid of path

global $registeredBots, $wgUser;
$bot = $registeredBots[$botID];

if ($bot != null) {
	echo ("Starting bot: $botID\n");
	// run bot
	global $sgagGardeningBotDelay, $wgContLang;
	try {
		$bot->setTaskID($taskid);
		// initialize term signal socket
		$bot->initializeTermSignal($taskid);

		SGAGardeningIssuesAccess::getGardeningIssuesAccess()->clearGardeningIssues($botID);
		// Transformation of parameters:
		// 	1. Concat to a string
		// 	2. Replace {{percantage}} by %
		// 	3. decode URL
		//  4. convert string of the form (key=value,)* to a hash array
		$log = $bot->run(GardeningBot::convertParamStringToArray(urldecode(str_replace("{{percentage}}", "%", implode($params,"")))), true, isset($sgagGardeningBotDelay) ? $sgagGardeningBotDelay : 0);
		
		//sync with tsc
		global $smwgDefaultStore;
		if($smwgDefaultStore == 'SMWTripleStore' || $smwgDefaultStore == 'SMWTripleStoreQuad'){
			define('SMWH_FORCE_TS_UPDATE', 'TRUE');
			smwfGetStore()->initialize(true);
		}
		
		global $smwgAbortBotPortRange;
        if (isset($smwgAbortBotPortRange)) @socket_close($bot->getTermSignalSocket());
			
		if ($bot->isAborted()) {
			print "\n - Bot was aborted by user! - \n";
			die();
		}
		
		//allow bots to return the title of the associated log page
		$logPageTitle = null;
		if(is_array($log)){
			$logPageTitle = $log[1];
			$log = $log[0];
		}
		
		echo "\n".$log."\n";
	    if ($log != NULL && $log != '') {
            // create link to GardeningLog
            $glp = Title::newFromText(wfMsg('gardeninglog'), NS_SPECIAL);
            $log .= "\n\n".wfMsg('smw_gardeninglog_link', "[".$glp->getFullURL("bot=$botID")." ".$glp->getText()."]");
            
        }
            
        // mark task as finished
        $title = SGAGardeningLog::getGardeningLogAccess()->markGardeningTaskAsFinished($taskid, $log, $logPageTitle);
        if ($title != NULL) echo "Log saved at: ".$title->getLocalURL()."\n";
            
    } catch(Exception $e) {
        $glp = Title::newFromText(wfMsg('gardeninglog'), NS_SPECIAL);
        $log = "\n\nSomething bad happened during execution of '".$botID."': ".$e->getMessage();
        $log .= "\n[[".$glp->getPrefixedText()."]]";
        echo $log;

        $title = SGAGardeningLog::getGardeningLogAccess()->markGardeningTaskAsFinished($taskid, $log);
        if ($title != NULL) echo "\nLog saved at: ".$title->getLocalURL();
    }
}


