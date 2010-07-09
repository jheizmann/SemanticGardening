<?php
/**
 * @file
 * @ingroup MissingAnnotationsBot
 * 
 * @defgroup MissingAnnotationsBot
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




class MissingAnnotationsBot extends GardeningBot {

	private $store;

	function MissingAnnotationsBot() {
		parent::GardeningBot("smw_missingannotationsbot");
		$this->store = $this->getMissingAnnotationsStore();
	}

	public function getHelpText() {
		return wfMsg('smw_gard_missingannot_docu');
	}

	public function getLabel() {
		return wfMsg($this->id);
	}

	

	/**
	 * Returns an array of parameter objects
	 */
	public function createParameters() {
		$param1 = new GardeningParamString('MA_PART_OF_NAME', wfMsg('smw_gard_missingannot_titlecontaining'), SMW_GARD_PARAM_OPTIONAL);
		$param2 = new GardeningParamTitle('MA_CATEGORY_RESTRICTION', wfMsg('smw_gard_restricttocategory'), SMW_GARD_PARAM_OPTIONAL);
		$param2->setAutoCompletion(true);
		$param2->setConstraints("namespace: ".NS_CATEGORY);
		return array($param1, $param2);
	}

	/**
	 * Do consistency checks and return a log as wiki markup.
	 * Do not use echo when it is not running asynchronously.
	 */
	public function run($paramArray, $isAsync, $delay) {
		$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
		if (!$isAsync) {
			echo 'Missing annotations bot should not be run synchronously! Abort bot.'; // do not externalize
			return;
		}
		echo $this->getBotID()." started!\n";
		$term = $paramArray['MA_PART_OF_NAME'];
		$categoryRestriction = urldecode($paramArray['MA_CATEGORY_RESTRICTION']);
		$notAnnotatedPages = array();
			
		echo "Checking for pages without annotations...\n";
		if ($categoryRestriction == '') {
			$notAnnotatedPages = $this->store->getPagesWithoutAnnotations($term == '' ? NULL : $term, NULL);
		} else {
			$categories = explode(";", $categoryRestriction);

			$notAnnotatedPages = array_merge($notAnnotatedPages, $this->store->getPagesWithoutAnnotations($term == '' ? NULL : $term, $categories));

		}

		foreach($notAnnotatedPages as $page) {
			$gi_store->addGardeningIssueAboutArticle('smw_missingannotationsbot', SMW_GARDISSUE_NOTANNOTATED_PAGE, $page);
			echo $page->getText()."\n";
		}

		echo "done!\n\n";
		return '';
			
	}

	private function getMissingAnnotationsStore() {
		
		if ($this->store == NULL) {
			global $smwgBaseStore;
			switch ($smwgBaseStore) {
				
				case ('SMWHaloStore2'): default:

					$this->store = new MissingAnnotationStorageSQL2();
					break;
				
			}
		}
		return $this->store;
	}

}

new MissingAnnotationsBot();

define('SMW_NOTANNOTATED_BOT_BASE', 500);
define('SMW_GARDISSUE_NOTANNOTATED_PAGE', SMW_NOTANNOTATED_BOT_BASE * 100 + 1);

class MissingAnnotationsBotIssue extends GardeningIssue {

	public function __construct($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified) {
		parent::__construct($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified);
	}

	protected function getTextualRepresenation(& $skin,  $text1, $text2, $local = false) {
		$text1 = $local ? wfMsg('smw_gard_issue_local') : $text1;
		switch($this->gi_type) {
			case SMW_GARDISSUE_NOTANNOTATED_PAGE:
				return wfMsg('smw_gardissue_notannotated_page', $text1);
			default: return NULL;
		}
	}
}

class MissingAnnotationsBotFilter extends GardeningIssueFilter {


	public function __construct() {
		parent::__construct(SMW_NOTANNOTATED_BOT_BASE);
		$this->gi_issue_classes = array(wfMsg('smw_gardissue_class_all'));
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
		$gis = $gi_store->getGardeningIssues('smw_missingannotationsbot', NULL, $gi_class, $title, SMW_GARDENINGLOG_SORTFORTITLE, NULL);
		$gic[] = new GardeningIssueContainer($title, $gis);


		return $gic;
	}
}

abstract class MissingAnnotationStorage {
	public abstract function getPagesWithoutAnnotations($term = NULL, $categories = NULL);
}

class MissingAnnotationStorageSQL extends MissingAnnotationStorage {
	/**
	 * Returns not annotated pages matching the $term (substring matching) or
	 * which are members of the subcategories of $category.
	 */
	public function getPagesWithoutAnnotations($term = NULL, $categories = NULL) {
		$db =& wfGetDB( DB_SLAVE );
		$smw_attributes = $db->tableName('smw_attributes');
		$smw_relations = $db->tableName('smw_relations');
		$smw_nary = $db->tableName('smw_nary');
		$mw_page = $db->tableName('page');
		$categorylinks = $db->tableName('categorylinks');
		$smw_longstrings = 	$db->tableName('smw_longstrings');
			
		$result = array();
		if ($categories == NULL) {
			if ($term == NULL) {
				$sql = 'SELECT DISTINCT page_title FROM '.$mw_page.' p LEFT JOIN '.$smw_attributes.' a ON a.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_relations.' r ON r.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_nary.' na ON na.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_longstrings.' ls ON ls.subject_id=p.page_id ' .
					
						'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND a.subject_id IS NULL AND r.subject_id IS NULL AND na.subject_id IS NULL AND ls.subject_id IS NULL'; 
			} else {
				$sql = 'SELECT DISTINCT page_title FROM '.$mw_page.' p LEFT JOIN '.$smw_attributes.' a ON a.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_relations.' r ON r.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_nary.' na ON na.subject_id=p.page_id ' .
																	 'LEFT JOIN '.$smw_longstrings.' ls ON ls.subject_id=p.page_id ' .
					
						'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND a.subject_id IS NULL AND r.subject_id IS NULL AND na.subject_id IS NULL AND ls.subject_id IS NULL AND page_title LIKE \'%'.mysql_real_escape_string($term).'%\'';
			}
			$res = $db->query($sql);

			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {

					$result[] = Title::newFromText($row->page_title, NS_MAIN);

				}
			}

			$db->freeResult($res);
		} else {
			global $smwgDefaultCollation;
			if (!isset($smwgDefaultCollation)) {
				$collation = '';
			} else {
				$collation = 'COLLATE '.$smwgDefaultCollation;
			}
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories ( category VARCHAR(255) '.$collation.')
		            TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories_sub (category VARCHAR(255) '.$collation.' NOT NULL)
		            TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories_super (category VARCHAR(255) '.$collation.' NOT NULL)
		            TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );

			foreach($categories as $category) {
				$categoryTitle = Title::newFromText($category, NS_CATEGORY);
				$db->query('INSERT INTO smw_ob_categories_super VALUES ('.$db->addQuotes($categoryTitle->getDBkey()).')');
				$db->query('INSERT INTO smw_ob_categories VALUES ('.$db->addQuotes($categoryTitle->getDBkey()).')');
			}
			$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
			// maximum iteration length is maximum category tree depth.
			do  {
				$maxDepth--;

				// get next subcategory level
				$db->query('INSERT INTO smw_ob_categories_sub (SELECT DISTINCT page_title AS category FROM '.$categorylinks.' JOIN '.$mw_page.' ON page_id = cl_from WHERE page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_ob_categories_super))');

				// insert direct instances of current subcategory level
				$db->query('INSERT INTO smw_ob_categories (SELECT * FROM smw_ob_categories_sub)');

				// copy subcatgegories to supercategories of next iteration
				$db->query('TRUNCATE TABLE smw_ob_categories_super');
				$db->query('INSERT INTO smw_ob_categories_super (SELECT * FROM smw_ob_categories_sub)');

				// check if there was least one more subcategory. If not, all instances were found.
				$res = $db->query('SELECT COUNT(category) AS numOfSubCats FROM smw_ob_categories_super');
				$numOfSubCats = $db->fetchObject($res)->numOfSubCats;
				$db->freeResult($res);

				$db->query('TRUNCATE TABLE smw_ob_categories_sub');

			} while ($numOfSubCats > 0 && $maxDepth > 0);


			if ($term == NULL) {
				$sql = 'SELECT DISTINCT page_title FROM '.$categorylinks.' c, '.$mw_page.' p LEFT JOIN '.$smw_attributes.' a ON a.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_relations.' r ON r.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_nary.' na ON na.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_longstrings.' ls ON ls.subject_id=p.page_id ' .

					'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND a.subject_id IS NULL AND r.subject_id IS NULL AND na.subject_id IS NULL AND ls.subject_id IS NULL AND p.page_id = c.cl_from AND cl_to IN (SELECT * FROM smw_ob_categories)';
					
			} else {
				$sql = 'SELECT DISTINCT page_title FROM '.$categorylinks.' c, '.$mw_page.' p LEFT JOIN '.$smw_attributes.' a ON a.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_relations.' r ON r.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_nary.' na ON na.subject_id=p.page_id ' .
																 'LEFT JOIN '.$smw_longstrings.' ls ON ls.subject_id=p.page_id ' .
					
					'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND a.subject_id IS NULL AND r.subject_id IS NULL AND na.subject_id IS NULL AND ls.subject_id IS NULL AND p.page_id = c.cl_from AND cl_to IN (SELECT * FROM smw_ob_categories) AND page_title LIKE \'%'.mysql_real_escape_string($term).'%\'';

			}
			$res = $db->query($sql);

			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {
					$result[] = Title::newFromText($row->page_title, NS_MAIN);
				}
			}

			$db->freeResult($res);
			$db->query('DROP TEMPORARY TABLE smw_ob_categories');
			$db->query('DROP TEMPORARY TABLE smw_ob_categories_super');
			$db->query('DROP TEMPORARY TABLE smw_ob_categories_sub');
		}
		return $result;
	}

}

class MissingAnnotationStorageSQL2 extends MissingAnnotationStorageSQL {
	/**
	 * Returns not annotated pages matching the $term (substring matching) or
	 * which are members of the subcategories of $category.
	 */
	public function getPagesWithoutAnnotations($term = NULL, $categories = NULL) {
		$db =& wfGetDB( DB_SLAVE );

		$mw_page = $db->tableName('page');
		$categorylinks = $db->tableName('categorylinks');
		$smw_ids = $db->tableName('smw_ids');
		$smw_atts2 = $db->tableName('smw_atts2');
		$smw_rels2 = $db->tableName('smw_rels2');

		$result = array();
		$excludeAtts = $this->excludePreProperties("a.p_id");
		$excludeRels = $this->excludePreProperties("r.p_id");
		
		if ($categories == NULL) {
			if ($term == NULL) {

				$sql = 'SELECT DISTINCT p.page_title FROM '.$mw_page.' p JOIN '.$smw_ids.' i ON p.page_title = i.smw_title AND p.page_namespace = i.smw_namespace  LEFT JOIN '.$smw_atts2.' a ON i.smw_id=a.s_id AND '.$excludeAtts.' LEFT JOIN '.$smw_rels2.' r ON i.smw_id=r.s_id AND '.$excludeRels.' ' .

                        'WHERE i.smw_namespace = '.NS_MAIN.' AND (a.s_id IS NULL AND r.s_id IS NULL) AND p.page_is_redirect = 0';

			} else {
				$sql = 'SELECT DISTINCT p.page_title FROM '.$mw_page.' p JOIN '.$smw_ids.' i ON p.page_title = i.smw_title AND p.page_namespace = i.smw_namespace  LEFT JOIN '.$smw_atts2.' a ON i.smw_id=a.s_id AND '.$excludeAtts.' LEFT JOIN '.$smw_rels2.' r ON i.smw_id=r.s_id AND '.$excludeRels.' ' .

                        'WHERE i.smw_namespace = '.NS_MAIN.' AND (a.s_id IS NULL AND r.s_id IS NULL) AND i.smw_title LIKE \'%'.mysql_real_escape_string($term).'%\' AND p.page_is_redirect = 0';

			}
			$res = $db->query($sql);

			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {

					$result[] = Title::newFromText($row->page_title, NS_MAIN);

				}
			}

			$db->freeResult($res);
		} else {
			global $smwgDefaultCollation;
			if (!isset($smwgDefaultCollation)) {
				$collation = '';
			} else {
				$collation = 'COLLATE '.$smwgDefaultCollation;
			}
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories ( category VARCHAR(255) '.$collation.')
                    TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories_sub (category VARCHAR(255) '.$collation.' NOT NULL)
                    TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );
			$db->query( 'CREATE TEMPORARY TABLE smw_ob_categories_super (category VARCHAR(255) '.$collation.' NOT NULL)
                    TYPE=MEMORY', 'SMW::getPagesWithoutAnnotations' );

			foreach($categories as $category) {
				$categoryTitle = Title::newFromText($category, NS_CATEGORY);
				if (is_null($categoryTitle)) continue;
				$db->query('INSERT INTO smw_ob_categories_super VALUES ('.$db->addQuotes($categoryTitle->getDBkey()).')');
				$db->query('INSERT INTO smw_ob_categories VALUES ('.$db->addQuotes($categoryTitle->getDBkey()).')');
			}
			$maxDepth = 10;
			// maximum iteration length is maximum category tree depth.
			do  {
				$maxDepth--;

				// get next subcategory level
				$db->query('INSERT INTO smw_ob_categories_sub (SELECT DISTINCT page_title AS category FROM '.$categorylinks.' JOIN '.$mw_page.' ON page_id = cl_from WHERE page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_ob_categories_super))');

				// insert direct instances of current subcategory level
				$db->query('INSERT INTO smw_ob_categories (SELECT * FROM smw_ob_categories_sub)');

				// copy subcatgegories to supercategories of next iteration
				$db->query('TRUNCATE TABLE smw_ob_categories_super');
				$db->query('INSERT INTO smw_ob_categories_super (SELECT * FROM smw_ob_categories_sub)');

				// check if there was least one more subcategory. If not, all instances were found.
				$res = $db->query('SELECT COUNT(category) AS numOfSubCats FROM smw_ob_categories_super');
				$numOfSubCats = $db->fetchObject($res)->numOfSubCats;
				$db->freeResult($res);

				$db->query('TRUNCATE TABLE smw_ob_categories_sub');

			} while ($numOfSubCats > 0 && $maxDepth > 0);


			if ($term == NULL) {
				$sql = 'SELECT DISTINCT page_title FROM '.$categorylinks.' c, '.$mw_page.' p JOIN '.$smw_ids.' i ON i.smw_title=p.page_title AND i.smw_namespace=p.page_namespace ' .
                        'LEFT JOIN '.$smw_atts2.' a ON i.smw_id=a.s_id AND '.$excludeAtts.' LEFT JOIN '.$smw_rels2.' r ON i.smw_id=r.s_id AND '.$excludeRels.' '.
                        'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND (a.s_id IS NULL AND r.s_id IS NULL) AND p.page_id = c.cl_from AND p.page_is_redirect = 0 AND cl_to IN (SELECT * FROM smw_ob_categories)';

                 
			} else {
				$sql = 'SELECT DISTINCT page_title FROM '.$categorylinks.' c, '.$mw_page.' p JOIN '.$smw_ids.' i ON i.smw_title=p.page_title AND i.smw_namespace=p.page_namespace ' .
                        'LEFT JOIN '.$smw_atts2.' a ON i.smw_id=a.s_id AND '.$excludeAtts.' LEFT JOIN '.$smw_rels2.' r ON i.smw_id=r.s_id AND '.$excludeRels.' '.
                        'WHERE p.page_is_redirect = 0 AND p.page_namespace = '.NS_MAIN.' AND (a.s_id IS NULL AND r.s_id IS NULL) AND p.page_id = c.cl_from AND p.page_is_redirect = 0 AND cl_to IN (SELECT * FROM smw_ob_categories) AND p.page_title LIKE \'%'.mysql_real_escape_string($term).'%\'';
                
			}
			$res = $db->query($sql);

			if($db->numRows( $res ) > 0) {
				while($row = $db->fetchObject($res)) {
					$result[] = Title::newFromText($row->page_title, NS_MAIN);
				}
			}

			$db->freeResult($res);
			$db->query('DROP TEMPORARY TABLE smw_ob_categories');
			$db->query('DROP TEMPORARY TABLE smw_ob_categories_super');
			$db->query('DROP TEMPORARY TABLE smw_ob_categories_sub');
		}
		return $result;
	}
    
	/**
	 * Returns a conjunction of predfined property IDs using $column as variable.
	 *
	 * @param string $column
	 * @return string
	 */
	private function excludePreProperties($column) {
		$db =& wfGetDB( DB_SLAVE );
		$smw_ids = $db->tableName('smw_ids');
        $res = $db->select($smw_ids, 'smw_id', 'smw_namespace = '.SMW_NS_PROPERTY.' AND smw_iw = ":smw-preprop"');
        $preprops = "";
        if($db->numRows( $res ) > 0) {
            while($row = $db->fetchObject($res)) {

                $preprops .= "$column != $row->smw_id AND ";

            }
             $preprops .= "TRUE ";
        }

        $db->freeResult($res);
        
        return $preprops;
	}
}

