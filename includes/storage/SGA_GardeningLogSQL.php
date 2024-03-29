<?php
/*
 * Copyright (C) Vulcan Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program.If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * @file
 * @ingroup SemanticGardeningStorage
 * 
 * Created on 18.10.2007
 *
 * Implementation of Gardening Log interface in SQL.
 *
 * @author Kai K�hn
 */
if ( !defined( 'MEDIAWIKI' ) ) die;

global $sgagIP;
require_once($sgagIP . '/includes/SGA_DBHelper.php');


class SGAGardeningLogSQL extends SGAGardeningLog {


	/**
	 * Initializes the gardening component
	 */
	public function setup($verbose) {
		global $wgDBname, $smwgDefaultCollation;
		$db =& wfGetDB( DB_MASTER );

		// create gardening table
		$smw_gardening = $db->tableName('smw_gardening');
		$fname = 'SMW::initGardeningLog';
			
		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}

		// create relation table
		SGADBHelper::setupTable($smw_gardening, array(
				  'id'				=>	'INT(8) UNSIGNED NOT NULL auto_increment PRIMARY KEY' ,
				  'user'      		=>  'VARCHAR(255) '.$collation.' NOT NULL' ,
				  'gardeningbot'	=>	'VARCHAR(255) '.$collation.' NOT NULL' ,
				  'starttime'  		=> 	'DATETIME NOT NULL',
				  'endtime'     	=> 	'DATETIME',
				  'timestamp_start'	=>	'VARCHAR(14) '.$collation.' NOT NULL',
				  'timestamp_end' 	=>	'VARCHAR(14) '.$collation.'',
				  'useremail'   	=>  'VARCHAR(255) '.$collation.'',
				  'log'				=>	'VARCHAR(255) '.$collation.'',
		          'comment'         =>  'MEDIUMBLOB',
				  'progress'		=>	'DOUBLE'), $db, $verbose);


	}

	function drop($verbose) {
		global $wgDBtype;
		SGADBHelper::reportProgress("Deleting all database content and tables generated by SemanticGardening ...\n\n",$verbose);
		$db =& wfGetDB( DB_MASTER );
		$tables = array('smw_gardening');
		foreach ($tables as $table) {
			$name = $db->tableName($table);
			$db->query('DROP TABLE' . ($wgDBtype=='postgres'?'':' IF EXISTS'). $name, 'SGAGardeningLogSQL::drop');
			SGADBHelper::reportProgress(" ... dropped table $name.\n", $verbose);
		}
		$this->removePredefinedPages($verbose);
		SGADBHelper::reportProgress("All data removed successfully.\n",$verbose);
		return true;
	}

	private function removePredefinedPages($verbose) {
		// create GardeningLog category
		SGADBHelper::reportProgress("Removing GardeningLog category ...\n",$verbose);
		$gardeningLogCategoryTitle = Title::newFromText(wfMsg('smw_gardening_log_cat'), NS_CATEGORY);
		$gardeningLogCategory = new Article($gardeningLogCategoryTitle);
		if ($gardeningLogCategory->exists()) {
			$gardeningLogCategory->doDeleteArticle("De-installation of SemanticGardening extension");
		}
		SGADBHelper::reportProgress("   ... GardeningLog category removed.\n",$verbose);

	}

	public function createPredefinedPages($verbose) {
		// create GardeningLog category
		SGADBHelper::reportProgress("Setting up GardeningLog category ...\n",$verbose);
		$gardeningLogCategoryTitle = Title::newFromText(wfMsg('smw_gardening_log_cat'), NS_CATEGORY);
		$gardeningLogCategory = new Article($gardeningLogCategoryTitle);
		if (!$gardeningLogCategory->exists()) {
			$gardeningLogCategory->insertNewArticle(wfMsg('smw_gardening_log_exp'), wfMsg('smw_gardening_log_exp'), false, false);
		}
		SGADBHelper::reportProgress("   ... GardeningLog category created.\n",$verbose);

	}
	/**
	 * Returns the complete gardening log as a 2-dimensional array.
	 */
	public function getGardeningLogAsTable() {
		$this->cleanupGardeningLog();
		$fname = 'SMW::getGardeningLog';
		$db =& wfGetDB( DB_SLAVE );

		$res = $db->select( $db->tableName('smw_gardening'),
		array('user','gardeningbot', 'starttime','endtime','log', 'progress', 'id', 'comment'), array(),
		$fname, array('ORDER BY' => 'id DESC') );

		$result = array();
		if($db->numRows( $res ) > 0)
		{
			$row = $db->fetchObject($res);
			while($row)
			{
				$result[]=array($row->user,$row->gardeningbot,$row->starttime,$row->endtime,$row->log, $row->progress, $row->id, $row->comment);
				$row = $db->fetchObject($res);
			}
		}
		$db->freeResult($res);
		return count($result) === 0 ? wfMsg('smw_no_gard_log') : $result;
	}

	/**
	 * Adds a gardening task. One must specify the $botID.
	 * Returns a task id which identifies the task.
	 *
	 * @param string $botID botID

	 * @return taskID
	 */
	public function addGardeningTask($botID) {
		global $wgUser;

		$fname = 'SMW::addGardeningTask';
		$db =& wfGetDB( DB_MASTER );
		$date = getDate();

		$db->insert( $db->tableName('smw_gardening'),
		array('user' => $wgUser->getName(),
		                   'gardeningbot' => $botID,
		                   'starttime' => $this->getDBDate($date),
		                   'endtime' => null,
		                   'timestamp_start' => $db->timestamp(),
		                   'timestamp_end' => null,
		                   'log' => null,
		                   'progress' => 0,
		                 
		                   'useremail' => $wgUser->getEmail()), 
		$fname );
		return $db->insertId();
	}
	
	public function updateComment($taskID, $comment) {
		
        $db =& wfGetDB( DB_MASTER );
            
        $db->update( $db->tableName('smw_gardening'),
        array('comment' => $comment),
        array( 'id' => $taskID) );
        
	}

	/**
	 * Removes a gardening task
	 *
	 * @param $id taskID
	 */
	public function removeGardeningTask($id) {
		$fname = 'SMW::removeGardeningTask';
		$db =& wfGetDB( DB_MASTER );
		$db->delete( $db->tableName('smw_gardening'),
		array('id' => $id),
		$fname );
	}

	/**
	 * Marks a Gardening task as finished.
	 *
	 * @param $taskID taskID
	 * @param $logContent content of log as wiki markup
	 */
	public function markGardeningTaskAsFinished($taskID, $logContent, $logPageTitle = null) {

		$fname = 'SMW::markGardeningTaskAsFinished';
		$db =& wfGetDB( DB_MASTER );
		$date = getDate();

		// get botID
		$res = $db->select( $db->tableName('smw_gardening'),
		array('gardeningbot'),
		array('id' => $taskID),
		$fname,array());
		if($db->numRows( $res ) == 0) {
			throw new Exception("There is no task with the id: $taskID");
		}
		$row = $db->fetchObject($res);
		$botID = $row->gardeningbot;

		$title = NULL;
		if ($logContent != NULL && $logContent != '') {
			$title = $this->createGardeningLogFile($botID, $date, $logContent, $logPageTitle);
		}
		$gardeningLogPage = Title::newFromText(wfMsg('gardeninglog'), NS_SPECIAL);
		$db->update( $db->tableName('smw_gardening'),
		array('endtime' => $this->getDBDate($date),
		             	   'timestamp_end' => $db->timestamp(),
		             	   'log' => $title != NULL ? $title->getPrefixedDBkey() : $gardeningLogPage->getPrefixedDBkey()."?bot=".$botID,
		             	   'progress' => 1),
		array( 'id' => $taskID),
		$fname );
		return $title;
	}

	public function updateProgress($taskID, $value) {
		$fname = 'SMW::updateProgress';
		$db =& wfGetDB( DB_MASTER );
		$db->update( $db->tableName('smw_gardening'),
		array('progress' => $value),
		array( 'id' => $taskID),
		$fname );
	}

	/**
	 * Returns last finished Gardening task of the given type
	 *
	 * @param botID type of Gardening task
	 */
	public function getLastFinishedGardeningTask($botID = NULL) {

		$fname = 'SMW::getLastFinishedGardeningTask';
		$db =& wfGetDB( DB_SLAVE );

		$res = $db->select( $db->tableName('smw_gardening'),
		array('MAX(timestamp_end)'),
		$botID != NULL ? array('gardeningbot='.$db->addQuotes($botID)) : array(),
		$fname,array());
		if($db->numRows( $res ) > 0)
		{
			$row = $db->fetchObject($res);
			if ($row) {
				$c_dummy = 'MAX(timestamp_end)';
				return $row->$c_dummy;
			}
		}
		$db->freeResult($res);
		return NULL; // minimum
	}
	
	/**
	 * Checks if a Gardening bot of the given type is running
	 *
	 * @param $botID Bot-ID
	 * 
	 * @return boolean
	 */
	public function isGardeningBotRunning($botID = NULL) {

		$fname = 'SGA::isGardeningBotRunning';
		$db =& wfGetDB( DB_SLAVE );

		$res = $db->select( $db->tableName('smw_gardening'),
		array('*'),
		array('gardeningbot' => $botID, 'endtime' => NULL),
		$fname,array());
		$result = $db->numRows( $res ) > 0;
		$db->freeResult($res);
		return $result; 
	}

	/**
	 * Initializes Gardening table.
	 */
	public function cleanupGardeningLog() {
		$dbr =& wfGetDB( DB_MASTER );
		$tblName = $dbr->tableName('smw_gardening');

		// Remove very old (2 days) and still running tasks. They are probably crashed.
		// If not, they are still available via GardeningLog category.
        if (version_compare(PHP_VERSION, '5.3.0') >= 0)
            date_default_timezone_set('UTC');
		$twoDaysAgo  = mktime(0, 0, 0, date("m"), date("d")-2,   date("Y"));
		$date = getDate($twoDaysAgo);
		$dbr->query('DELETE FROM '.$tblName.' WHERE endtime IS NULL AND starttime < '.$dbr->addQuotes($this->getDBDate($date)));

		// Remove logs which are older than 1 month. (running and finished)
		$oneMonthAgo  = mktime(0, 0, 0, date("m")-1, date("d"),   date("Y"));
		$date = getDate($oneMonthAgo);

		// select log pages older than one month
		$res = $dbr->query('SELECT log FROM '.$tblName.' WHERE starttime < '.$dbr->addQuotes($this->getDBDate($date)));
		if($dbr->numRows( $res ) > 0)
		{

			while($row = $dbr->fetchObject($res))
			{
				// get name of log page and remove the article
				$logTitle = Title::newFromDBkey($row->log);
				if (is_null($logTitle) || !$logTitle->exists()) continue;
				$logArticle = new Article($logTitle);
				if ($logArticle->exists()) {
					$logArticle->doDeleteArticle("automatic deletion");
				}
			}
		}
		$dbr->freeResult($res);

		// remove log entries
		$dbr->query('DELETE FROM '.$tblName.' WHERE starttime < '.$dbr->addQuotes($this->getDBDate($date)));
	}

	/**
	 * Creates a log article.
	 * Returns: Title of log article.
	 */
	private  function createGardeningLogFile($botID, $date, $logContent, $logPageTitle = null) {
		if($logPageTitle == null){
			$timeInTitle = $date["year"]."_".$date["mon"]."_".$date["mday"]."_".$date["hours"]."_".$date["minutes"]."_".$date["seconds"];
			$title = Title::newFromText($botID."_at_".$timeInTitle, SGA_NS_LOG);
			$article = new Article($title);
			$article->insertNewArticle($logContent, "Logging of $botID at ".$this->getDBDate($date), false, false);
		} else {
			$title = Title::newFromText($logPageTitle, SGA_NS_LOG);
		}

		return $title;
	}

	private  function getDBDate($date) {
		return $date["year"]."-".$date["mon"]."-".$date["mday"]." ".$date["hours"].":".$date["minutes"].":".$date["seconds"];
	}



}

