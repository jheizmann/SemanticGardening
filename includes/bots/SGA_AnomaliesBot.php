<?php
/**
 * @file
 * @ingroup AnomaliesBot
 * 
 * @defgroup AnomaliesBot
 * @ingroup SemanticGardeningBots
 * 
 * @author Kai K�hn
 * 
 * Created on 18.06.2007
 *
 * Author: kai
 */
if ( !defined( 'MEDIAWIKI' ) ) die;

 global $sgagIP;
 require_once("$sgagIP/includes/SGA_GardeningBot.php");
 require_once("$sgagIP/includes/SGA_ParameterObjects.php");
 require_once("$sgagIP/includes/SGA_GardeningIssues.php");
 
 // maximum number of subcategories not regarded as anomaly
 define('MAX_SUBCATEGORY_NUM', 8);
 // minimum number of subcategories not regarded as anomaly
 define('MIN_SUBCATEGORY_NUM', 2);
 
 /**
  * Bot is used to find ontology anomalies
  */
 class AnomaliesBot extends GardeningBot {
 	
 	// global log which contains wiki-markup
 	private $globalLog;
 	private $store;
 	
 	private $formerSuperCategories = array();
 	
 	function AnomaliesBot() {
 		parent::GardeningBot("smw_anomaliesbot");
 		$this->globalLog = NULL;
 		$this->store = $this->getAnomalyStore();
 	}
 	
 	public function getHelpText() {
 		return wfMsg('smw_gard_anomaly_docu');
 	}
 	
 	public function getLabel() {
 		return wfMsg($this->id);
 	}
 	
 	
 	
 	/**
 	 * Returns an array of parameter objects
 	 */
 	public function createParameters() {
 		global $wgUser;
 		$params = array();
 		$params[] = new GardeningParamBoolean('CATEGORY_NUMBER_ANOMALY', wfMsg('smw_gard_anomaly_checknumbersubcat'), SMW_GARD_PARAM_OPTIONAL, true);
 		$params[] = new GardeningParamBoolean('CATEGORY_LEAF_ANOMALY', wfMsg('smw_gard_anomaly_checkcatleaves'), SMW_GARD_PARAM_OPTIONAL, true);
 		$resParam = new GardeningParamTitle('CATEGORY_RESTRICTION', wfMsg('smw_gard_anomaly_restrictcat'), SMW_GARD_PARAM_OPTIONAL);
 		$resParam->setAutoCompletion(true);
 		$resParam->setConstraints("namespace: ".NS_CATEGORY);
 		$params[] = $resParam;
 		// for GARDENERS and SYSOPS, deletion of leaf categories is possible.
 		$userGroups = $wgUser->getGroups();
 		if (in_array('gardener', $userGroups) || in_array('sysop', $userGroups)) { // why do the constants SMW_GARD_SYSOP, SMW_GARD_GARDENERS not work here?
            // Deactivated for security reasons 			
 			//$params[] = new GardeningParamBoolean('CATEGORY_LEAF_DELETE', wfMsg('smw_gard_anomaly_deletecatleaves'), SMW_GARD_PARAM_OPTIONAL, false);
 		}
 		return $params;
 	}
 	
 	/**
 	 * Do bot work and return a log as wiki markup.
 	 * Do not use echo when it is not running asynchronously.
 	 */
 	public function run($paramArray, $isAsync, $delay) {
 		global $wgLang;
 		$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
 		if (!$isAsync) {
 			echo 'Missing annotations bot should not be run synchronously! Abort bot.'; // do not externalize
 			return;
 		}
 		echo $this->getBotID()." started!\n";
 		
       	$categories = explode(";", $paramArray['CATEGORY_RESTRICTION']);
 		$categoryTitles = array();
 				
 		foreach($categories as $c) {
 			$categoryTitle = Title::newFromText($c, NS_CATEGORY);
 			if (!is_null($categoryTitle)) $categoryTitles[] = $categoryTitle; 
 		}
 				
 		if (array_key_exists('CATEGORY_LEAF_ANOMALY', $paramArray)) {  
 			echo "Checking for category leafs...\n";
 			if (count($categoryTitles) == 0) {
       			$categoryLeaves = $this->store->getCategoryLeafs();
       	
       			
       			foreach($categoryLeaves as $cl) {
       				$gi_store->addGardeningIssueAboutArticle($this->id, SMW_GARDISSUE_CATEGORY_LEAF, $cl);
       			
       				echo $cl->getPrefixedText()."\n";
       			} 
 			} else {
 				
 					
 				$categoryLeaves = $this->store->getCategoryLeafs($categoryTitles);
 				
 				foreach($categoryLeaves as $cl) {
 					// maybe it exists already
 					if (!$gi_store->existsGardeningIssue($this->id, SMW_GARDISSUE_CATEGORY_LEAF, NULL, $cl)) {
 						$gi_store->addGardeningIssueAboutArticle($this->id, SMW_GARDISSUE_CATEGORY_LEAF, $cl);
	       				echo $cl->getPrefixedText()."\n";
 					}
       			} 
 			       			
 			}		
       		echo "done!\n";
 	   		
 		}
       	
       	if (array_key_exists('CATEGORY_NUMBER_ANOMALY', $paramArray)) {  	
       		echo "\nChecking for number anomalies...\n";
       		if (count($categoryTitles) == 0) {
       			
        		$subCatAnomalies = $this->store->getCategoryAnomalies();
       			      			
       			foreach($subCatAnomalies as $a) {
       				list($title, $subCatNum) = $a;
       				$gi_store->addGardeningIssueAboutValue($this->id, SMW_GARDISSUE_SUBCATEGORY_ANOMALY, $title, $subCatNum);
       				
       				echo $title->getPrefixedText()." has $subCatNum ".($subCatNum == 1 ? "subcategory" : "subcategories").".\n";
       			} 
       		} else {
       			
       				
 				$subCatAnomalies = $this->store->getCategoryAnomalies($categoryTitles);
 				
 				foreach($subCatAnomalies as $a) {
       				list($title, $subCatNum) = $a;
       				if (!$gi_store->existsGardeningIssue($this->id, SMW_GARDISSUE_SUBCATEGORY_ANOMALY, NULL, $title)) {
	       				$gi_store->addGardeningIssueAboutValue($this->id, SMW_GARDISSUE_SUBCATEGORY_ANOMALY, $title, $subCatNum);
	       				echo $title->getPrefixedText()." has $subCatNum ".($subCatNum == 1 ? "subcategory" : "subcategories").".\n";
       				}
       			} 
 				
       		}
       		echo "done!\n";
       	}
       	
       	if (array_key_exists('CATEGORY_LEAF_DELETE', $paramArray)) {
       		$this->globalLog = "== ".wfMsg('smw_gard_anomalylog')."! ==\n\n";
       		echo "\nRemoving category leaves...\n";
       		if ($paramArray['CATEGORY_RESTRICTION'] == '') {
       			$deletedCategories = array();
       		
        		$this->store->removeCategoryLeaves(NULL, $deletedCategories);
        		
       			foreach($deletedCategories as $dc) {
       				list($cat, $superCats) = $dc;
       				$leafOf = count($superCats) > 0 ? "(".wfMsg('smw_gard_was_leaf_of')." [[:".$superCats[0]->getPrefixedText()."]])" : "";
       				$this->globalLog .= "\n*[[:".$cat->getPrefixedText()."]] " . $leafOf;
 	          	}
       		} else {
       			
       			foreach($categoryTitles as $c) {
       				echo('start:'.$c->getText());
       				$deletedCategories = array();
       				       			
       				$this->store->removeCategoryLeaves(array($c), $deletedCategories);
       				foreach($deletedCategories as $dc) {
       					list($cat, $superCats) = $dc;
       					$leafOf = count($superCats) > 0 ? "(".wfMsg('smw_gard_was_leaf_of')." [[:".$superCats[0]->getPrefixedText()."]])" : "";
       					$this->globalLog .= "\n*[[:".$cat->getPrefixedText()."]] " . $leafOf;
 	     			}
       			}
       		}
       		echo "done!\n";
       	}
 		return $this->globalLog;
 		
 	}
 	
 	private function getAnomalyStore() {
 		
		if ($this->store == NULL) {
			global $smwgBaseStore;
			switch ($smwgBaseStore) {
				
				case ('SMWHaloStore2'): default:
					
					$this->store = new AnomalyStorageSQL();
				break;
			}
		}
		return $this->store;
 	}
 		
 	
 }
 
 new AnomaliesBot();
 define('SMW_ANOMALY_BOT_BASE', 600);
 define('SMW_GARDISSUE_CATEGORY_LEAF', SMW_ANOMALY_BOT_BASE * 100 + 1);
 define('SMW_GARDISSUE_SUBCATEGORY_ANOMALY', (SMW_ANOMALY_BOT_BASE+1) * 100 + 1);

 
 class AnomaliesBotIssue extends GardeningIssue {
 	
 	public function __construct($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified) {
 		parent::__construct($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified);
 	}
 	
 	protected function getTextualRepresenation(& $skin, $text1, $text2, $local = false) {
 		$text1 = $local ? wfMsg('smw_gard_issue_local') : $text1;
		switch($this->gi_type) {
			case SMW_GARDISSUE_CATEGORY_LEAF:
				return wfMsg('smw_gardissue_category_leaf', $text1);
			case SMW_GARDISSUE_SUBCATEGORY_ANOMALY:
				return wfMsg('smw_gardissue_subcategory_anomaly', $text1, $this->value);
			default: return NULL;
			
		}
 	}
 }
 
 class AnomaliesBotFilter extends GardeningIssueFilter {
 	 	
 	
 	public function __construct() {
 		parent::__construct(SMW_ANOMALY_BOT_BASE);
 		$this->gi_issue_classes = array(wfMsg('smw_gardissue_class_all'), wfMsg('smw_gardissue_class_category_leaves'), wfMsg('smw_gardissue_class_number_anomalies'));
 	}
 	
 	public function getUserFilterControls($specialAttPage, $request) {
		return '';
	}
	
	public function linkUserParameters(& $wgRequest) {
		return array('pageTitle' => $wgRequest->getVal('pageTitle'));
	}
	
	public function getData($options, $request) {
		$pageTitle = $request->getVal('pageTitle');
		if ($pageTitle != NULL) {
			// show only issue of *ONE* title
			return $this->getGardeningIssueContainerForTitle($options, $request, Title::newFromText(urldecode($pageTitle)));
		} else return parent::getData($options, $request);
	}
	
	private function getGardeningIssueContainerForTitle($options, $request, $title) {
		$gi_class = $request->getVal('class') == 0 ? NULL : $request->getVal('class') + $this->base - 1;
		
		
		$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
		
		$gic = array();
		$gis = $gi_store->getGardeningIssues('smw_anomaliesbot', NULL, $gi_class, $title, SMW_GARDENINGLOG_SORTFORTITLE, NULL);
		$gic[] = new GardeningIssueContainer($title, $gis);
		
		
		return $gic;
	}
 }
 
 abstract class AnomalyStorage {
 	public abstract function getCategoryLeafs($category = NULL);
 	public abstract function getCategoryAnomalies($category = NULL);
 	public abstract function removeCategoryLeaves($category = NULL, array & $deletedCategories);
 }
 
 class AnomalyStorageSQL extends AnomalyStorage {
 	/**
 	 * Returns all categories which have neither instances nor subcategories.
 	 * 
 	 * @param $category as strings (dbkey)
 	 */
 	public function getCategoryLeafs($categories = NULL) {
 		$db =& wfGetDB( DB_SLAVE );
 		$mw_page = $db->tableName('page');
	 	$categorylinks = $db->tableName('categorylinks');
 		$result = array();
		if ($categories == NULL) { 
			$sql = 'SELECT page_title FROM '.$mw_page.' p LEFT JOIN '.$categorylinks.' c ON p.page_title = c.cl_to WHERE cl_from IS NULL AND page_namespace = '.NS_CATEGORY;
	               
			$res = $db->query($sql);
		
			$result = array();
			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {
				
					$result[] = Title::newFromText($row->page_title, NS_CATEGORY);
				
				}
			}
			$db->freeResult($res);
		} else {
			
				
				$result = $this->getCategoryLeafsBelow($categories, $db);
		
				
			
		}
		
		return $result;
 	}
 	
 	/**
 	 * Returns all categories which have less than MIN_SUBCATEGORY_NUM and more than MAX_SUBCATEGORY_NUM subcategories.
 	 */
 	public function getCategoryAnomalies($categories = NULL) {
 		$db =& wfGetDB( DB_SLAVE );
 		$mw_page = $db->tableName('page');
	 	$categorylinks = $db->tableName('categorylinks');
		$result = array();

		if ($categories == NULL) {  		
			$sql = 'SELECT COUNT(cl_from) AS subCatNum, cl_to FROM '.$mw_page.' p, '.$categorylinks.' c '.
			         'WHERE cl_from = page_id AND page_namespace = '.NS_CATEGORY.' GROUP BY cl_to '.
			         'HAVING (COUNT(cl_from) < '.MIN_SUBCATEGORY_NUM.' OR COUNT(cl_from) > '.MAX_SUBCATEGORY_NUM.') ';
		               
			$res = $db->query($sql);
		
			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {
				
					$result[] = array(Title::newFromText($row->cl_to, NS_CATEGORY), $row->subCatNum);
					
				}
			}
		
			$db->freeResult($res);
		} else {
					
				$result = $this->getCategoryAnomaliesBelow($categories, $db);
			
		}
		return $result;
 	}
 	
 	/**
 	 * Removes all category leaves
 	 */
 	public function removeCategoryLeaves($categories = NULL, array &$deletedCategories) {
 		$db =& wfGetDB( DB_SLAVE );
 		$mw_page = $db->tableName('page');
	 	$categorylinks = $db->tableName('categorylinks');
 		if ($categories == NULL) {  		
 				
			$sql = 'SELECT DISTINCT page_title FROM '.$mw_page.' p LEFT JOIN '.$categorylinks.' c ON p.page_title = c.cl_to '.
			         'WHERE cl_from IS NULL AND page_namespace = '.NS_CATEGORY. ' ORDER BY page_title';
		               
			$res = $db->query($sql);
			
			
			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {
					$categoryTitle = Title::newFromText($row->page_title, NS_CATEGORY);
					$superCatgeories = smwfGetSemanticStore()->getDirectSuperCategories($categoryTitle);
					$deletedCategories[] = array($categoryTitle, $superCatgeories);
					$categoryArticle = new Article($categoryTitle);
					$categoryArticle->doDeleteArticle(wfMsg('smw_gard_category_leaf_deleted', $row->page_title));
				}
			}
		
		$db->freeResult($res);
 		} else {
 			$result = $this->getCategoryLeafsBelow($categories, $db);
			
 			if(count($result) > 0){
	 			foreach($categories as $c){
	 				$this->formerSuperCategories[$c->getText()] = true;
	 			}
 			}
 			
 			print_r($this->formerSuperCategories);
 			
 			foreach($result as $c) {
				if(array_key_exists($c->getText(), $this->formerSuperCategories)) continue;
				
				echo("\n".$c->getText());
				
				$superCatgeories = smwfGetSemanticStore()->getDirectSuperCategories($c);
				$deletedCategories[] = array($c, $superCatgeories);
				$categoryArticle = new Article($c);
				$categoryArticle->doDeleteArticle(wfMsg('smw_gard_category_leaf_deleted', $c->getText()));
			}
		}
			
 		
		
 	}
 	
 	private function getCategoryLeafsBelow($categoryTitles, & $db) {
		global $smwgDefaultCollation;
		
		$page = $db->tableName('page');
		$categorylinks = $db->tableName('categorylinks');
	
		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_leaves (category VARCHAR(255) '.$collation.')
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_sub (category VARCHAR(255) '.$collation.' NOT NULL)
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_super (category VARCHAR(255) '.$collation.' NOT NULL)
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		
		// initialize with direct instances
		
		foreach($categoryTitles as $c) {
			$db->query('INSERT INTO smw_gard_ab_super VALUES ('.$db->addQuotes($c->getDBkey()).')');
		}		           
		
		$db->query('INSERT INTO smw_gard_ab_leaves (SELECT DISTINCT page_title FROM '.$page.' p ' .
				'LEFT JOIN '.$categorylinks.' c ON p.page_title = c.cl_to ' .
				'WHERE cl_from IS NULL AND page_title IN (SELECT * FROM smw_gard_ab_super) AND page_namespace = '.NS_CATEGORY.')');
	
		
		
		$maxDepth = 10;
		// maximum iteration length is maximum category tree depth.
		do  {
			$maxDepth--;
			
			// get next subcategory level
			$db->query('INSERT INTO smw_gard_ab_sub (SELECT DISTINCT page_title AS category FROM '.$categorylinks.' JOIN '.$page.' ON page_id = cl_from WHERE page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_gard_ab_super))');
			
			// get category leaves
			$db->query('INSERT INTO smw_gard_ab_leaves (SELECT DISTINCT page_title FROM '.$page.' p ' .
				'LEFT JOIN '.$categorylinks.' c ON p.page_title = c.cl_to ' .
				'WHERE cl_from IS NULL AND page_title IN (SELECT * FROM smw_gard_ab_sub) AND page_namespace = '.NS_CATEGORY.')');
			
			
			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_gard_ab_super');
			$db->query('INSERT INTO smw_gard_ab_super (SELECT * FROM smw_gard_ab_sub)');
			
			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(category) AS numOfSubCats FROM smw_gard_ab_super');
			$numOfSubCats = $db->fetchObject($res)->numOfSubCats;
			$db->freeResult($res);
			
			$db->query('DELETE FROM smw_gard_ab_sub');
			
			
		} while ($numOfSubCats > 0 && $maxDepth > 0);
		
		$result = array();
		$res = $db->query('SELECT DISTINCT category FROM smw_gard_ab_leaves');
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = Title::newFromText($row->category, NS_CATEGORY);
			}
		}
		$db->freeResult($res);
		
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_super');
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_sub');
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_leaves');
		return $result;
	}
	
	private function getCategoryAnomaliesBelow($categoryTitles, & $db) {
		global $smwgDefaultCollation;
		
		$page = $db->tableName('page');
		$categorylinks = $db->tableName('categorylinks');
	
		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_anomalies (category VARCHAR(255) '.$collation.', subCatNum INTEGER)
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_sub (category VARCHAR(255) '.$collation.' NOT NULL)
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		$db->query( 'CREATE TEMPORARY TABLE smw_gard_ab_super (category VARCHAR(255) '.$collation.' NOT NULL)
		            ENGINE=MEMORY', 'SMW::getCategoryLeafsBelow' );
		
		// initialize with direct instances
		
		foreach($categoryTitles as $c) {
			$db->query('INSERT INTO smw_gard_ab_super VALUES ('.$db->addQuotes($c->getDBkey()).')');
		}		           
		
		$db->query('INSERT INTO smw_gard_ab_anomalies (SELECT cl_to, COUNT(cl_from) AS subCatNum FROM '.$page.' p, '.$categorylinks.' c ' .
				'WHERE cl_from = page_id AND page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_gard_ab_super) ' .
				'GROUP BY cl_to HAVING (COUNT(cl_from) < '.MIN_SUBCATEGORY_NUM.' OR COUNT(cl_from) > '.MAX_SUBCATEGORY_NUM.'))');
	
		
		
		$maxDepth = 10;
		// maximum iteration length is maximum category tree depth.
		do  {
			$maxDepth--;
			
			// get next subcategory level
			$db->query('INSERT INTO smw_gard_ab_sub (SELECT DISTINCT page_title AS category FROM '.$categorylinks.' JOIN '.$page.' ON page_id = cl_from WHERE page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_gard_ab_super))');
			
			// get category leaves
			$db->query('INSERT INTO smw_gard_ab_anomalies (SELECT cl_to, COUNT(cl_from) AS subCatNum FROM '.$page.' p, '.$categorylinks.' c ' .
				'WHERE cl_from = page_id AND page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_gard_ab_sub) ' .
				'GROUP BY cl_to HAVING (COUNT(cl_from) < '.MIN_SUBCATEGORY_NUM.' OR COUNT(cl_from) > '.MAX_SUBCATEGORY_NUM.'))');
			
			
			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_gard_ab_super');
			$db->query('INSERT INTO smw_gard_ab_super (SELECT * FROM smw_gard_ab_sub)');
			
			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(category) AS numOfSubCats FROM smw_gard_ab_super');
			$numOfSubCats = $db->fetchObject($res)->numOfSubCats;
			$db->freeResult($res);
			
			$db->query('DELETE FROM smw_gard_ab_sub');
			
			
		} while ($numOfSubCats > 0 && $maxDepth > 0);
		
		$result = array();
		$res = $db->query('SELECT DISTINCT category, subCatNum FROM smw_gard_ab_anomalies');
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = array(Title::newFromText($row->category, NS_CATEGORY), $row->subCatNum);
			}
		}
		$db->freeResult($res);
		
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_super');
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_sub');
		$db->query('DROP TEMPORARY TABLE smw_gard_ab_anomalies');
		return $result;
	}
 }
