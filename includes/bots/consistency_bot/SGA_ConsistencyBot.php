<?php
/**
 * @file
 * @ingroup ConsistencyBot
 * 
 * @defgroup ConsistencyBot
 * @ingroup SemanticGardeningBots
 * 
 * Created on 13.03.2007
 *
 * @author Kai K�hn
 */
if ( !defined( 'MEDIAWIKI' ) ) die;

global $sgagIP;
require_once("SGA_GraphCycleDetector.php");
require_once("SGA_PropertyCoVarianceDetector.php");
require_once("SGA_AnnotationLevelConsistency.php");
require_once("SGA_InverseEqualityConsistency.php");
require_once( $sgagIP . '/includes/SGA_GardeningBot.php');
require_once( $sgagIP . '/includes/SGA_GardeningIssues.php');
require_once("$sgagIP/includes/SGA_ParameterObjects.php");


class ConsistencyBot extends GardeningBot {


	function ConsistencyBot() {
		parent::GardeningBot("smw_consistencybot");
		$this->store = ConsitencyBotStorage::getConsistencyStorage();
	}

	public function getHelpText() {
		return wfMsg('smw_gard_consistency_docu');
	}

	public function getLabel() {
		return wfMsg($this->id);
	}



	/**
	 * Returns an array mapping parameter IDs to parameter objects
	 */
	public function createParameters() {
		$param1 = new GardeningParamTitle('MA_CATEGORY_RESTRICTION', wfMsg('smw_gard_restricttocategory'), SMW_GARD_PARAM_OPTIONAL);
		$param1->setAutoCompletion(true);
		$param1->setConstraints("namespace: ".NS_CATEGORY);
		return array($param1);
	}

	/**
	 * Do consistency checks and return a log as wiki markup.
	 * Do not use echo when it is not running asynchronously.
	 */
	public function run($paramArray, $isAsync, $delay) {

		if (!$isAsync) {
			echo 'ConsistencyChecks should not be run synchronously! Abort bot.';
			return;
		}
		echo $this->getBotID()." started!\n";

		$this->setNumberOfTasks(9); // 8 single tasks
		if(array_key_exists('MA_CATEGORY_RESTRICTION', $paramArray)){
			$categoryRestriction = urldecode($paramArray['MA_CATEGORY_RESTRICTION']);
			$categories = explode(";", $categoryRestriction);
		} else {
			$categories = array();
		}
		$categoryTitles = array();
		foreach($categories as $c) {
			$t = Title::newFromText($c, NS_CATEGORY);
			if (!is_null($t)) $categoryTitles[] = $t;
		}

		// get inheritance graphs
		$categoryGraph = $this->store->getCategoryInheritanceGraph();
		$propertyGraph = $this->store->getPropertyInheritanceGraph();
        
		
		// Replace redirect annotations
		//if (array_key_exists('CONSISTENCY_BOT_REPLACE_REDIRECTS', $paramArray)) {
		smwfGetSemanticStore()->replaceRedirectAnnotations(true);
		//}

		// Schema level checks
		// first, check if there are cycles in the inheritance graphs
		echo "\n=== Checking for cycles in inheritance graphs ===";
		$this->checkInheritanceCycles($categoryGraph, $propertyGraph);
		echo "\n...done!\n\n";


		echo "\n=== Checking property co-variance === \n";
		$this->checkPropertyCovariance($delay, $categoryGraph, $propertyGraph);
		echo "\n...done!\n\n";

		echo "\n=== Checking inverse and equality relations ===";
		$this->checkInverseEqualityRelations($delay);
		echo "\n...done!\n\n";

		// Annotation level checks
		echo "\n=== Checking annotation level ===\n";
		$this->checkAnnotationLevel($delay, $categoryGraph, $propertyGraph, $categoryTitles);
		echo "\n...done!\n\n";

		// propagate issues
		echo "\n=== Propagating issues ===";
		SGAGardeningIssuesAccess::getGardeningIssuesAccess()->generatePropagationIssuesForCategories($this->id, SMW_GARDISSUE_CONSISTENCY_PROPAGATION);
		echo "\n...done!\n\n";
			
		return NULL;

	}

	private function checkInheritanceCycles(& $categoryGraph, & $propertyGraph) {

		$gcd = new GraphCycleDetector($this);
		$gcd->getAllCategoryCycles($categoryGraph);
		$gcd->getAllPropertyCycles($propertyGraph);

	}


	private function checkPropertyCovariance($delay, & $categoryGraph, & $propertyGraph) {

		$pcd = new PropertyCoVarianceDetector($this, $delay, $categoryGraph, $propertyGraph, true);
		$pcd->checkPropertyGraphForCovariance();


	}

	private function checkAnnotationLevel($delay, & $categoryGraph, & $propertyGraph, $categories) {

		$alc = new AnnotationLevelConsistency($this, $delay, $categoryGraph, $propertyGraph, true);
		$alc->checkAllPropertyAnnotations($categories);
		$alc->checkAllAnnotationCardinalities($categories);
		
		//todo: fix this
		$alc->checkAllUnits();

	}



	private function checkInverseEqualityRelations($delay) {

		$ier = new InverseEqualityConsistency($this, $delay);
		$cir = $ier->checkInverseRelations();
		$cer = $ier->checkEqualToRelations();

	}



}


// instantiate it once.
new ConsistencyBot();

require_once("SGA_ConsistencyIssue.php");

class ConsistencyBotFilter extends GardeningIssueFilter {


    public function __construct() {
        parent::__construct(SMW_CONSISTENCY_BOT_BASE);
        $this->gi_issue_classes = array(wfMsg('smw_gardissue_class_all'),
        wfMsg('smw_gardissue_class_covariance'),
        wfMsg('smw_gardissue_class_undefined'),
        wfMsg('smw_gardissue_class_missdouble'),
        wfMsg('smw_gardissue_class_wrongvalue'),
        wfMsg('smw_gardissue_class_incomp'),
        wfMsg('smw_gardissue_class_cycles'));
    }

    public function getUserFilterControls($specialAttPage, $request) {
        $matchString = $request != NULL && $request->getVal('matchString') != NULL ? $request->getVal('matchString') : "";
        return ' Contains:<input name="matchString" type="text" class="wickEnabled" value="'.$matchString.'"/>';
    }

    public function linkUserParameters(& $wgRequest) {
        return array('matchString' => $wgRequest->getVal('matchString'), 'pageTitle' => $wgRequest->getVal('pageTitle'));
    }

    public function getData($options, $request) {
        $matchString = $request->getVal('matchString');
        $pageTitle = $request->getVal('pageTitle');

        if ($pageTitle != NULL) {
            // show only issue of *ONE* title
            return $this->getGardeningIssueContainerForTitle($options, $request, Title::newFromText(urldecode($pageTitle)));
        }
        if ($matchString != NULL && $matchString != '') {
            // show all issues of title which match
            $options->addStringCondition($matchString, SMWStringCondition::STRCOND_MID);
            return $this->getGardeningIssueContainer($options, $request);
        } else {
            // default
            return $this->getGardeningIssueContainer($options, $request);
        }
    }

    private function getGardeningIssueContainer($options, $request) {

        $gi_class = $request->getVal('class') == 0 ? NULL : $request->getVal('class') + $this->base - 1;


        $gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();

        $gic = array();

        // get issues of the given class. If no class is specified, ignore propagation issues.
        $titles = $gi_store->getDistinctTitles('smw_consistencybot', NULL, $gi_class != NULL ? $gi_class : -GardeningIssue::getClass(SMW_GARDISSUE_CONSISTENCY_PROPAGATION), SMW_GARDENINGLOG_SORTFORTITLE, $options);
        foreach($titles as $t) {
            $gis = $gi_store->getGardeningIssues('smw_consistencybot', NULL, $gi_class, $t, SMW_GARDENINGLOG_SORTFORTITLE, NULL);
            $gic[] = new GardeningIssueContainer($t, $gis);
        }

        return $gic;
    }

    /**
     * Returns array of ONE GardeningIssueContainer for a specific title
     */
    private function getGardeningIssueContainerForTitle($options, $request, $title) {
        $gi_class = $request->getVal('class') == 0 ? NULL : $request->getVal('class') + $this->base - 1;


        $gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();

        $gic = array();
        $gis = $gi_store->getGardeningIssues('smw_consistencybot', NULL, $gi_class, $title, SMW_GARDENINGLOG_SORTFORTITLE, NULL);
        $gic[] = new GardeningIssueContainer($title, $gis);


        return $gic;
    }
}

abstract class ConsitencyBotStorage {

	private static $store = NULL;
	/*
	 * Note:
	 *
	 *   Most of the following methods require a reference to a complete inheritance graph in memory.
	 *   They are intended to be used thousands of times in a row, since it is a complex
	 *   task to load and prepare a complete inheritance graph for pathfinding at maximum speed.
	 *   So if you just need for instance a domain of _one_ super property, do this manually.
	 *
	 */


	/**
	 * Returns the domain and ranges of the first super property which has defined some.
	 *
	 * @param & $inheritance graph Reference to array of GraphEdge objects.
	 * @param Title $property
	 */
	public abstract function getDomainsAndRangesOfSuperProperty(& $inheritanceGraph, $property);

	/**
	 * Determines minimum cardinality of an attribute,
	 * which may be inherited.
	 *
	 * @param & $inheritance graph Reference to array of GraphEdge objects.
	 * @param Title $property
	 */
	public abstract function getMinCardinalityOfSuperProperty(& $inheritanceGraph, $property);

	/**
	 * Determines minimum cardinality of an attribute,
	 * which may be inherited.
	 *
	 * @param & $inheritance graph Reference to array of GraphEdge objects.
	 * @param Title $property
	 */
	public abstract function getMaxCardinalityOfSuperProperty(& $inheritanceGraph, $property);

	/**
	 * Returns type of superproperty
	 *
	 * @param & $inheritance graph Reference to array of GraphEdge objects.
	 * @param Title $property
	 */
	public abstract function getTypeOfSuperProperty(& $inheritanceGraph, $property);

	/**
	 * Returns categories of super property
	 *
	 * @param & $inheritance graph Reference to array of GraphEdge objects.
	 * @param Title $property
	 */
	public abstract function getCategoriesOfSuperProperty(& $inheritanceGraph, $property);

	/**
	 * Returns a sorted array of (category,supercategory) page_id tuples
	 * representing an category inheritance graph.
	 *
	 * @return array of GraphEdge objects;
	 */
	public abstract function getCategoryInheritanceGraph();

	/**
	 * Returns a sorted array of (attribute,superattribute) page_id tuples
	 * representing an attribute inheritance graph.
	 *
	 *  @return array of GraphEdge objects;
	 */
	public abstract function getPropertyInheritanceGraph();

	public abstract function getInverseRelations();

	public abstract function getEqualToRelations();

	/**
	 * Returns number of property instantiations for each instance, which has
	 * at least one instantiation of $property or one of its subproperties.
	 *
	 * @param SMWProperty $property
	 * @return array of tuples (Title instance, Integer frequency)
	 */
	public abstract function getNumberOfPropertyInstantiations($property);

	/**
	 * Returns number of property instantiations of $property or one of its
	 * subproperties for the given instances.
	 *
	 * @param SMWProperty $property
	 * @param array of Title $instances
	 *
	 * @return array of tuples (Title instance, Integer frequency)
	 */
	public abstract function getMissingPropertyInstantiations($property, $instances);

	/**
	 * Returns all instances which use $property and are member of at least one of $categories.
	 *
	 * @param SMWProperty $property
	 * @param array of Title $categories
	 *
	 * @return array of Title
	 */
	public abstract function getInstancesUsingProperty($property, $categories);

	/**
	 * Transform an array of IDs to Title objects.
	 *
	 * @return array of Title
	 */
	public abstract function translateToTitle(& $cycle);

	public static function getConsistencyStorage() {
			
		if (self::$store == NULL) {
			self::$store = new ConsistencyBotStorageSQL2();
		}
		return self::$store;
	}


}

abstract class ConsistencyBotStorageSQL extends ConsitencyBotStorage {
	public function getDomainsAndRangesOfSuperProperty(& $inheritanceGraph, $p) {
		$visitedNodes = array();
		return $this->_getDomainsAndRangesOfSuperProperty($inheritanceGraph, $p, $visitedNodes);

	}

	private function _getDomainsAndRangesOfSuperProperty(& $inheritanceGraph, $p, & $visitedNodes) {
		$results = array();

		$propertyID = $p->getArticleID();
		array_push($visitedNodes, $propertyID);
		$superProperties = GraphHelper::searchInSortedGraph($inheritanceGraph, $propertyID);
		if ($superProperties == null) return $results;
		foreach($superProperties as $sp) {
			$spTitle = Title::newFromID($sp->to);
			$domainRangeCategories = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($spTitle),
				SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_DOMAIN_AND_RANGE->getText()));
			
			if (count($domainRangeCategories) > 0) {
				return $domainRangeCategories;
			} else {
				if (!in_array($sp->to, $visitedNodes)) {
					$results = array_merge($results, $this->_getDomainsAndRangesOfSuperProperty($inheritanceGraph, $spTitle, $visitedNodes));
				}
			}

		}
		array_pop($visitedNodes);
		return $results;
	}


	public function getMinCardinalityOfSuperProperty(& $inheritanceGraph, $a) {
		$visitedNodes = array();
		$minCards = $this->_getMinCardinalityOfSuperProperty($inheritanceGraph, $a, $visitedNodes);
		return max($minCards); // return highest min cardinality
	}

	private function _getMinCardinalityOfSuperProperty(& $inheritanceGraph, $a, & $visitedNodes) {
		$results = array(CARDINALITY_MIN);

		$attributeID = $a->getArticleID();
		array_push($visitedNodes, $attributeID);
		$superAttributes = GraphHelper::searchInSortedGraph($inheritanceGraph, $attributeID);
		if ($superAttributes == null) return $results;
		foreach($superAttributes as $sa) {
			$saTitle = Title::newFromID($sa->to);
			$minCards = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($saTitle),
				SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY->getText()));
			
			if (count($minCards) > 0) {
				return array(intval(GardeningBot::getXSDValue($minCards[0])));
			} else {
				if (!in_array($sa->to, $visitedNodes)) {
					$results = array_merge($results, $this->_getMinCardinalityOfSuperProperty($inheritanceGraph, $saTitle, $visitedNodes));
				}
			}

		}
		array_pop($visitedNodes);
		return $results;
	}


	public function getMaxCardinalityOfSuperProperty(& $inheritanceGraph, $a) {
		$visitedNodes = array();
		$maxCards = $this->_getMaxCardinalityOfSuperProperty($inheritanceGraph, $a, $visitedNodes);
		return min($maxCards); // return smallest max cardinality
	}

	private function _getMaxCardinalityOfSuperProperty(& $inheritanceGraph, $a, & $visitedNodes) {
		$results = array(CARDINALITY_UNLIMITED);

		$attributeID = $a->getArticleID();
		array_push($visitedNodes, $attributeID);
		$superAttributes = GraphHelper::searchInSortedGraph($inheritanceGraph, $attributeID);
		if ($superAttributes == null) return $results;
		foreach($superAttributes as $sa) {
			$saTitle = Title::newFromID($sa->to);
			$maxCards = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($saTitle),
				SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_MAX_CARDINALITY->getText()));
			
			if (count($maxCards) > 0) {
				return array(intval(GardeningBot::getXSDValue($maxCards[0])));
			} else {
				if (!in_array($sa->to, $visitedNodes)) {
					$results = array_merge($results, $this->_getMaxCardinalityOfSuperProperty($inheritanceGraph, $saTitle, $visitedNodes));
				}
			}

		}
		array_pop($visitedNodes);
		return $results;
	}



	public function getTypeOfSuperProperty(& $inheritanceGraph, $a) {
		$visitedNodes = array();
		return $this->_getTypeOfSuperProperty($inheritanceGraph, $a, $visitedNodes);

	}

	private function _getTypeOfSuperProperty(& $inheritanceGraph, $a, & $visitedNodes) {
		$results = array();
		$attributeID = $a->getArticleID();
		array_push($visitedNodes, $attributeID);
		$superAttributes = GraphHelper::searchInSortedGraph($inheritanceGraph, $attributeID);
		if ($superAttributes == null) return $results;
		$hasTypeDV = SMWPropertyValue::makeProperty("_TYPE");
		foreach($superAttributes as $sa) {
			$saTitle = Title::newFromID($sa->to);
			$types = smwfGetStore()->getPropertyValues(
				SMWDIWikiPage::newFromTitle($saTitle), $hasTypeDV->getDataItem());
			if (count($types) > 0) {
				return $types;
			} else {
				if (!in_array($sa->to, $visitedNodes)) {
					$results = array_merge($results, $this->_getTypeOfSuperProperty($inheritanceGraph, $saTitle, $visitedNodes));
				}
			}

		}
		array_pop($visitedNodes);
		return $results;
	}


	public function getCategoriesOfSuperProperty(& $inheritanceGraph, $a) {
		$visitedNodes = array();
		return $this->_getCategoriesOfSuperProperty($inheritanceGraph, $a, $visitedNodes);
	}

	private function _getCategoriesOfSuperProperty(& $inheritanceGraph, $a, & $visitedNodes) {
		$results = array();
		$attributeID = $a->getArticleID();
		array_push($visitedNodes, $attributeID);
		$superAttributes = GraphHelper::searchInSortedGraph($inheritanceGraph, $attributeID);
		if ($superAttributes == null) return $results;
		foreach($superAttributes as $sa) {
			$saTitle = Title::newFromID($sa->to);
			$categories = smwfGetSemanticStore()->getCategoriesForInstance($saTitle);
			if (count($categories) > 0) {
				return $categories;
			} else {
				if (!in_array($sa->to, $visitedNodes)) {
					$results = array_merge($results, $this->_getCategoriesOfSuperProperty($inheritanceGraph, $saTitle, $visitedNodes));
				}
			}

		}
		array_pop($visitedNodes);
		return $results;
	}


	public function getCategoryInheritanceGraph() {
		$result = "";
		$db =& wfGetDB( DB_SLAVE );
		$sql = 'page_namespace=' . NS_CATEGORY .
               ' AND cl_to = page_title';
		$sql_options = array();
		$sql_options['ORDER BY'] = 'cl_from';
		$res = $db->select(  array($db->tableName('page'), $db->tableName('categorylinks')),
		array('cl_from','page_id', 'page_title'),
		$sql, 'SMW::getCategoryInheritanceGraph', $sql_options);
		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = new GraphEdge($row->cl_from, $row->page_id);
			}
		}
		$db->freeResult($res);
		return $result;
	}


	public function getPropertyInheritanceGraph() {
		global $smwgContLang;
		$namespaces = $smwgContLang->getNamespaces();
		$result = "";
		$db =& wfGetDB( DB_SLAVE );
		$smw_subprops = $db->tableName('smw_subprops');
		$res = $db->query('SELECT p1.page_id AS sub, p2.page_id AS sup FROM '.$smw_subprops.', page p1, page p2 WHERE p1.page_namespace = '.SMW_NS_PROPERTY.
                            ' AND p2.page_namespace = '.SMW_NS_PROPERTY.' AND p1.page_title = subject_title AND p2.page_title = object_title ORDER BY p1.page_id');
		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = new GraphEdge($row->sub, $row->sup);
			}
		}
		$db->freeResult($res);
		return $result;
	}

	public function getInverseRelations($requestoptions = NULL) {
		$db =& wfGetDB( DB_SLAVE );
		$sql = 'relation_title = '.$db->addQuotes(smwfGetSemanticStore()->inverseOf->getDBkey());

		$res = $db->select(  array($db->tableName('smw_relations')),
		array('subject_title', 'object_title'),
		$sql, 'SMW::getInverseRelations', SGADBHelper::getSQLOptions($requestoptions));


		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = array(Title::newFromText($row->subject_title, SMW_NS_PROPERTY),  Title::newFromText($row->object_title, SMW_NS_PROPERTY));
			}
		}

		$db->freeResult($res);

		return $result;
	}

	public function getEqualToRelations($requestoptions = NULL) {
		//TODO: read partitions of redirects
		$db =& wfGetDB( DB_SLAVE );
		$sql = 'rd_from = page_id';

		$res = $db->select(  array($db->tableName('redirect'), $db->tableName('page')),
		array('rd_namespace','rd_title', 'page_namespace', 'page_title'),
		$sql, 'SMW::getEqualToRelations', SGADBHelper::getSQLOptions($requestoptions));


		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = array(Title::newFromText($row->rd_title, $row->rd_namespace), Title::newFromText($row->page_title, $row->page_namespace));
			}
		}

		$db->freeResult($res);

		return $result;
	}

	public function getNumberOfPropertyInstantiations($property) {

		global $smwgDefaultCollation;
		$db =& wfGetDB( DB_SLAVE );
		$smw_attributes = $db->tableName('smw_attributes');
		$smw_relations = $db->tableName('smw_relations');
		$smw_nary = $db->tableName('smw_nary');
		$smw_subprops = $db->tableName('smw_subprops');

		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_propertyinst (instance VARCHAR(255) '.$collation.', namespace INTEGER, property VARCHAR(255) '.$collation.', num INTEGER(8))
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_sub (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_super (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		// initialize with direct property instantiations
			
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_title AS instance, subject_namespace AS namespace, attribute_title AS property, COUNT(subject_title) AS num FROM '.$smw_attributes.' WHERE attribute_title = '.$db->addQuotes($property->getDBkey()).' GROUP BY instance) ');
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_title AS instance, subject_namespace AS namespace, relation_title AS property, COUNT(subject_title) AS num FROM '.$smw_relations.' WHERE relation_title = '.$db->addQuotes($property->getDBkey()).' GROUP BY instance) ');
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_title AS instance, subject_namespace AS namespace, attribute_title AS property, COUNT(subject_title) AS num FROM '.$smw_nary.' WHERE attribute_title = '.$db->addQuotes($property->getDBkey()).' GROUP BY instance)');


		$db->query('INSERT INTO smw_cc_properties_super VALUES ('.$db->addQuotes($property->getDBkey()).')');

		$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
		// maximum iteration length is maximum property tree depth.
		do  {
			$maxDepth--;

			// get next subproperty level
			$db->query('INSERT INTO smw_cc_properties_sub (SELECT DISTINCT subject_title AS property FROM '.$smw_subprops.' WHERE object_title IN (SELECT * FROM smw_cc_properties_super) AND subject_title NOT IN (SELECT property FROM smw_cc_propertyinst))');

			// insert number of instantiated properties of current property level level
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_title AS instance, subject_namespace AS namespace, attribute_title AS property, COUNT(subject_title) AS num FROM '.$smw_attributes.' WHERE attribute_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY instance) ');
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                    '(SELECT subject_title AS instance, subject_namespace AS namespace, relation_title AS property, COUNT(subject_title) AS num FROM '.$smw_relations.' WHERE relation_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY instance)');
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                    '(SELECT subject_title AS instance, subject_namespace AS namespace, attribute_title AS property, COUNT(subject_title) AS num FROM '.$smw_nary.' WHERE attribute_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY instance) ');


			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_cc_properties_super');
			$db->query('INSERT INTO smw_cc_properties_super (SELECT * FROM smw_cc_properties_sub)');

			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(property) AS numOfSubProps FROM smw_cc_properties_super');
			$numOfSubProps = $db->fetchObject($res)->numOfSubProps;
			$db->freeResult($res);

			$db->query('DELETE FROM smw_cc_properties_sub');

		} while ($numOfSubProps > 0 && $maxDepth > 0);

		$res = $db->query('SELECT instance, namespace, SUM(num) AS numOfInstProps FROM smw_cc_propertyinst GROUP BY instance');

		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {

				$result[] = array(Title::newFromText($row->instance, $row->namespace), $row->numOfInstProps);
			}
		}

		$db->freeResult($res);

		$db->query('DROP TEMPORARY TABLE smw_cc_properties_super');
		$db->query('DROP TEMPORARY TABLE smw_cc_properties_sub');
		$db->query('DROP TEMPORARY TABLE smw_cc_propertyinst');

		return $result;
	}

	public function getMissingPropertyInstantiations($property, $instances) {
		global $smwgDefaultCollation;
		$db =& wfGetDB( DB_SLAVE );
		$smw_attributes = $db->tableName('smw_attributes');
		$smw_relations = $db->tableName('smw_relations');
		$smw_nary = $db->tableName('smw_nary');
		$smw_subprops = $db->tableName('smw_subprops');

		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_propertyinst (id INTEGER(8))
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_allinst (id INTEGER(8), namespace INTEGER, instance VARCHAR(255) '.$collation.')
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_sub (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_super (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query('INSERT INTO smw_cc_properties_super VALUES ('.$db->addQuotes($property->getDBkey()).')');

		// initialize with direct property instantiations
		foreach($instances as $i) {
			if ($i == NULL) continue;
			// insert ID of instances
			list($instance, $category) = $i;
			$db->query('INSERT INTO smw_cc_allinst VALUES ('.$instance->getArticleID().', '.$instance->getNamespace().' , '.$db->addQuotes($instance->getDBkey()).')');
		}

		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_attributes.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND attribute_title IN (SELECT * FROM smw_cc_properties_super) GROUP BY subject_id)');
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_relations.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND relation_title IN (SELECT * FROM smw_cc_properties_super) GROUP BY subject_id) ');
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_nary.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND attribute_title IN (SELECT * FROM smw_cc_properties_super) GROUP BY subject_id) ');


		$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
		// maximum iteration length is maximum property tree depth.
		do  {
			$maxDepth--;

			// get next subproperty level
			$db->query('INSERT INTO smw_cc_properties_sub (SELECT DISTINCT subject_title AS property FROM '.$smw_subprops.' WHERE object_title IN (SELECT * FROM smw_cc_properties_super)  AND subject_title NOT IN (SELECT property FROM smw_cc_propertyinst))');


			// insert number of instantiated properties of current property level level
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_attributes.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND attribute_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY subject_id)');
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_relations.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND relation_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY subject_id) ');
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT subject_id AS id FROM '.$smw_nary.' WHERE subject_id IN (SELECT id FROM smw_cc_allinst) AND attribute_title IN (SELECT * FROM smw_cc_properties_sub) GROUP BY subject_id) ');


			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_cc_properties_super');
			$db->query('INSERT INTO smw_cc_properties_super (SELECT * FROM smw_cc_properties_sub)');

			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(property) AS numOfSubProps FROM smw_cc_properties_super');
			$numOfSubProps = $db->fetchObject($res)->numOfSubProps;
			$db->freeResult($res);

			$db->query('DELETE FROM smw_cc_properties_sub');

		} while ($numOfSubProps > 0 && $maxDepth > 0);



		$res = $db->query('SELECT DISTINCT allinst1.instance, allinst1.namespace FROM smw_cc_allinst allinst1 LEFT JOIN smw_cc_propertyinst allinst2 ON allinst1.id = allinst2.id WHERE allinst2.id IS NULL');

		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {

				$result[] = Title::newFromText($row->instance, $row->namespace);
			}
		}

		$db->freeResult($res);

		$db->query('DROP TEMPORARY TABLE smw_cc_properties_super');
		$db->query('DROP TEMPORARY TABLE smw_cc_properties_sub');
		$db->query('DROP TEMPORARY TABLE smw_cc_allinst');
		$db->query('DROP TEMPORARY TABLE smw_cc_propertyinst');

		return $result;
	}

	public function translateToTitle(& $cycle) {

		$db =& wfGetDB( DB_SLAVE );
		$sql = "";
		for ($i = 0, $n = count($cycle); $i < $n; $i++) {
			if ($i < $n-1) {
				$sql .= 'page_id ='.$cycle[$i].' OR ';
			} else {
				$sql .= 'page_id ='.$cycle[$i];
			}
		}

		$res = $db->select(  array($db->tableName('page')),
		array('page_title','page_namespace', 'page_id'),
		$sql, 'SMW::translate', NULL);
		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[$row->page_id] = Title::newFromText($row->page_title, $row->page_namespace);
			}
		}
		$db->freeResult($res);

		$titles = array();
		foreach($cycle as $id) {
			$titles[] = $result[$id];
		}
		return $titles;
	}
}

class ConsistencyBotStorageSQL2 extends ConsistencyBotStorageSQL {
	public function getPropertyInheritanceGraph() {
		global $smwgContLang;
		$namespaces = $smwgContLang->getNamespaces();
		$result = "";
		$db =& wfGetDB( DB_SLAVE );
		$smw_subs2 = $db->tableName('smw_subp2');
		$smw_ids = $db->tableName('smw_ids');
		$page = $db->tableName('page');
		$res = $db->query('SELECT p1.page_id AS sub, p2.page_id AS sup '.
         ' FROM '.$smw_subs2.' '.
         ' JOIN '.$smw_ids.' subprop ON subprop.smw_id = s_id '.
         ' JOIN '.$page.' p1 ON p1.page_title = subprop.smw_title AND p1.page_namespace =  '.SMW_NS_PROPERTY.
         ' JOIN '.$smw_ids.' superprop ON superprop.smw_id = o_id '.
         ' JOIN '.$page.' p2 ON p2.page_title = superprop.smw_title AND p2.page_namespace = '.SMW_NS_PROPERTY.
         ' ORDER BY sub');
			
		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {
				$result[] = new GraphEdge($row->sub, $row->sup);
			}
		}
		
		$db->freeResult($res);
		return $result;
	}

	public function getInverseRelations($requestoptions = NULL) {
		$db =& wfGetDB( DB_SLAVE );
			
		$smw_ids = $db->tableName('smw_ids');
		$smw_rels2 = $db->tableName('smw_rels2');

		$res = $db->query('SELECT i.smw_title AS subject_title, i2.smw_title AS object_title FROM '.$smw_rels2.
        ' JOIN '.$smw_ids.' i ON s_id = i.smw_id' 
        .' JOIN '.$smw_ids.' i2 ON o_id = i2.smw_id'
        .' JOIN '.$smw_ids.' i3 ON p_id = i3.smw_id'
        .' WHERE i3.smw_title = '.$db->addQuotes(
        	SMWHaloPredefinedPages::$IS_INVERSE_OF->getDBkey()).' AND i3.smw_namespace = '.SMW_NS_PROPERTY.' '.SGADBHelper::getSQLOptionsAsString($requestoptions));


        $result = array();
        if($db->numRows( $res ) > 0) {
        	while($row = $db->fetchObject($res)) {
        		$result[] = array(Title::newFromText($row->subject_title, SMW_NS_PROPERTY),  Title::newFromText($row->object_title, SMW_NS_PROPERTY));
        	}
        }

        $db->freeResult($res);

        return $result;
	}


	public function getNumberOfPropertyInstantiations($property) {

		global $smwgDefaultCollation;
		$db =& wfGetDB( DB_SLAVE );

		$smw_ids = $db->tableName('smw_ids');
		$smw_rels2 = $db->tableName('smw_rels2');
		$smw_atts2 = $db->tableName('smw_atts2');
		$smw_subs2 = $db->tableName('smw_subp2');

		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_propertyinst (instance VARCHAR(255) '.$collation.', namespace INTEGER, property VARCHAR(255) '.$collation.', num INTEGER(8))
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_sub (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_super (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		// initialize with direct property instantiations
			
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_title AS instance, i.smw_namespace AS namespace, i2.smw_title AS property, COUNT(i.smw_title) AS num FROM '.$smw_atts2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i2.smw_title = '.$db->addQuotes($property->getDBkey()).' AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY instance) ');

		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_title AS instance, i.smw_namespace AS namespace, i2.smw_title AS property, COUNT(i.smw_title) AS num FROM '.$smw_rels2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i2.smw_title = '.$db->addQuotes($property->getDBkey()).' AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY instance) ');
			

		$db->query('INSERT INTO smw_cc_properties_super VALUES ('.$db->addQuotes($property->getDBkey()).')');

		$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
		// maximum iteration length is maximum property tree depth.
		do  {
			$maxDepth--;

			// get next subproperty level
			$db->query('INSERT INTO smw_cc_properties_sub (SELECT DISTINCT i.smw_title AS property FROM '.$smw_subs2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON o_id = i2.smw_id WHERE i2.smw_title IN (SELECT * FROM smw_cc_properties_super) AND i.smw_title NOT IN (SELECT property FROM smw_cc_propertyinst))');

			// insert number of instantiated properties of current property level level
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_title AS instance, i.smw_namespace AS namespace, i2.smw_title AS property, COUNT(i.smw_title) AS num FROM '.$smw_atts2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i2.smw_title IN (SELECT * FROM smw_cc_properties_sub) AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY instance) ');

			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_title AS instance, i.smw_namespace AS namespace, i2.smw_title AS property, COUNT(i.smw_title) AS num FROM '.$smw_rels2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i2.smw_title IN (SELECT * FROM smw_cc_properties_sub) AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY instance) ');


			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_cc_properties_super');
			$db->query('INSERT INTO smw_cc_properties_super (SELECT * FROM smw_cc_properties_sub)');

			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(property) AS numOfSubProps FROM smw_cc_properties_super');
			$numOfSubProps = $db->fetchObject($res)->numOfSubProps;
			$db->freeResult($res);

			$db->query('DELETE FROM smw_cc_properties_sub');

		} while ($numOfSubProps > 0 && $maxDepth > 0);

		$res = $db->query('SELECT instance, namespace, SUM(num) AS numOfInstProps FROM smw_cc_propertyinst GROUP BY instance');

		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {

				$result[] = array(Title::newFromText($row->instance, $row->namespace), $row->numOfInstProps);
			}
		}

		$db->freeResult($res);

		$db->query('DROP TEMPORARY TABLE smw_cc_properties_super');
		$db->query('DROP TEMPORARY TABLE smw_cc_properties_sub');
		$db->query('DROP TEMPORARY TABLE smw_cc_propertyinst');

		return $result;
	}

	public function getMissingPropertyInstantiations($property, $instances) {
		global $smwgDefaultCollation;
		$db =& wfGetDB( DB_SLAVE );

		$smw_ids = $db->tableName('smw_ids');
		$smw_rels2 = $db->tableName('smw_rels2');
		$smw_atts2 = $db->tableName('smw_atts2');
		$smw_subs2 = $db->tableName('smw_subp2');

		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_propertyinst (id INTEGER(8))
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_allinst (id INTEGER(8), namespace INTEGER, instance VARCHAR(255) '.$collation.')
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_sub (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );
		$db->query( 'CREATE TEMPORARY TABLE smw_cc_properties_super (property VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::getNumberOfPropertyInstantiations' );

		$db->query('INSERT INTO smw_cc_properties_super VALUES ('.$db->addQuotes($property->getDBkey()).')');

		// initialize with direct property instantiations
		foreach($instances as $i) {
			if ($i == NULL) continue;
			// insert ID of instances
			list($instance, $category) = $i;
			$id = $db->selectRow($smw_ids, array('smw_id'), array('smw_title' => $instance->getDBkey(), 'smw_namespace' => $instance->getNamespace()) );
			$db->query('INSERT INTO smw_cc_allinst VALUES ('.$id->smw_id.', '.$instance->getNamespace().' , '.$db->addQuotes($instance->getDBkey()).')');
		}

		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_id AS id FROM '.$smw_atts2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i.smw_id IN (SELECT id FROM smw_cc_allinst) AND i2.smw_title IN (SELECT * FROM smw_cc_properties_super) GROUP BY id)');
		$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT i.smw_id AS id FROM '.$smw_rels2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE i.smw_id IN (SELECT id FROM smw_cc_allinst) AND i2.smw_title IN (SELECT * FROM smw_cc_properties_super) GROUP BY id)');
			
			
		$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
		// maximum iteration length is maximum property tree depth.
		do  {
			$maxDepth--;

			// get next subproperty level
			$db->query('INSERT INTO smw_cc_properties_sub (SELECT DISTINCT i.smw_title AS property FROM '.$smw_subs2.' JOIN '.$smw_ids.' i ON s_id = i.smw_id JOIN '.$smw_ids.' i2 ON o_id = i2.smw_id WHERE i2.smw_title IN (SELECT * FROM smw_cc_properties_super) AND i.smw_id NOT IN (SELECT id FROM smw_cc_propertyinst))');

			// insert number of instantiated properties of current property level level
			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT s_id AS id FROM '.$smw_atts2.' JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE s_id IN (SELECT id FROM smw_cc_allinst) AND i2.smw_title IN (SELECT * FROM smw_cc_properties_sub) AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY s_id) ');

			$db->query('INSERT INTO smw_cc_propertyinst ' .
                '(SELECT s_id AS id FROM '.$smw_rels2.' JOIN '.$smw_ids.' i2 ON p_id = i2.smw_id WHERE s_id IN (SELECT id FROM smw_cc_allinst) AND i2.smw_title IN (SELECT * FROM smw_cc_properties_sub) AND i2.smw_namespace = '.SMW_NS_PROPERTY.' GROUP BY s_id) ');


			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_cc_properties_super');
			$db->query('INSERT INTO smw_cc_properties_super (SELECT * FROM smw_cc_properties_sub)');

			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(property) AS numOfSubProps FROM smw_cc_properties_super');
			$numOfSubProps = $db->fetchObject($res)->numOfSubProps;
			$db->freeResult($res);

			$db->query('DELETE FROM smw_cc_properties_sub');

		} while ($numOfSubProps > 0 && $maxDepth > 0);



		$res = $db->query('SELECT DISTINCT allinst1.instance, allinst1.namespace FROM smw_cc_allinst allinst1 LEFT JOIN smw_cc_propertyinst allinst2 ON allinst1.id = allinst2.id WHERE allinst2.id IS NULL');

		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {

				$result[] = Title::newFromText($row->instance, $row->namespace);
			}
		}

		$db->freeResult($res);

		$db->query('DROP TEMPORARY TABLE smw_cc_properties_super');
		$db->query('DROP TEMPORARY TABLE smw_cc_properties_sub');
		$db->query('DROP TEMPORARY TABLE smw_cc_allinst');
		$db->query('DROP TEMPORARY TABLE smw_cc_propertyinst');

		return $result;
	}

	public function getInstancesUsingProperty($property, $categories) {
		global $smwgDefaultCollation;
		$db =& wfGetDB( DB_SLAVE );

		$propertyID = smwfGetStore()->getSMWPropertyID($property);

		$page = $db->tableName('page');
		$categorylinks = $db->tableName('categorylinks');
		$smwids = $db->tableName('smw_ids');
		$smwatts2 = $db->tableName('smw_atts2');
		$smwrels2 = $db->tableName('smw_rels2');

		if (!isset($smwgDefaultCollation)) {
			$collation = '';
		} else {
			$collation = 'COLLATE '.$smwgDefaultCollation;
		}
		// create virtual tables
		$db->query( 'CREATE TEMPORARY TABLE smw_ob_instances (instance VARCHAR(255), namespace INT(11))
                    ENGINE=MEMORY', 'SMW::createVirtualTableWithInstances' );

		$db->query( 'CREATE TEMPORARY TABLE smw_ob_instances_sub (category VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::createVirtualTableWithInstances' );
		$db->query( 'CREATE TEMPORARY TABLE smw_ob_instances_super (category VARCHAR(255) '.$collation.' NOT NULL)
                    ENGINE=MEMORY', 'SMW::createVirtualTableWithInstances' );

		// initialize with direct instances
		if ($onlyMain) {
			$articleNamespaces = "page_namespace = ".NS_MAIN;
		} else {
			$articleNamespaces = "page_namespace != ".NS_CATEGORY;
		}

		foreach($categories as $categoryTitle) {
			$db->query('INSERT INTO smw_ob_instances (SELECT page_title AS instance,page_namespace AS namespace FROM '.$page.' ' .
                        'JOIN '.$categorylinks.' ON page_id = cl_from JOIN '.$smwids.' ON page_title = smw_title AND page_namespace = smw_namespace JOIN '.$smwrels2.' ON smw_id = s_id ' .
                        'WHERE p_id='.$propertyID.' AND page_is_redirect = 0 AND '.$articleNamespaces.' AND cl_to = '.$db->addQuotes($categoryTitle->getDBkey()).')');
			$db->query('INSERT INTO smw_ob_instances (SELECT page_title AS instance,page_namespace AS namespace FROM '.$page.' ' .
                        'JOIN '.$categorylinks.' ON page_id = cl_from JOIN '.$smwids.' ON page_title = smw_title AND page_namespace = smw_namespace JOIN '.$smwatts2.' ON smw_id = s_id ' .
                        'WHERE p_id='.$propertyID.' AND page_is_redirect = 0 AND '.$articleNamespaces.' AND cl_to = '.$db->addQuotes($categoryTitle->getDBkey()).')');
			$db->query('INSERT INTO smw_ob_instances_super VALUES ('.$db->addQuotes($categoryTitle->getDBkey()).')');
		}

		$maxDepth = SMW_MAX_CATEGORY_GRAPH_DEPTH;
		// maximum iteration length is maximum category tree depth.
		do  {
			$maxDepth--;

			// get next subcategory level
			$db->query('INSERT INTO smw_ob_instances_sub (SELECT DISTINCT page_title AS category FROM '.$categorylinks.' JOIN '.$page.' ON page_id = cl_from WHERE page_namespace = '.NS_CATEGORY.' AND cl_to IN (SELECT * FROM smw_ob_instances_super))');

			// insert direct instances of current subcategory level
			$db->query('INSERT INTO smw_ob_instances (SELECT page_title AS instance, page_namespace AS namespace FROM '.$page.' ' .
                        'JOIN '.$categorylinks.' ON page_id = cl_from JOIN '.$smwids.' ON page_title = smw_title AND page_namespace = smw_namespace JOIN '.$smwrels2.' ON smw_id = s_id ' .
                        'WHERE p_id='.$propertyID.' AND page_is_redirect = 0 AND '.$articleNamespaces.' AND cl_to IN (SELECT * FROM smw_ob_instances_sub))');
			$db->query('INSERT INTO smw_ob_instances (SELECT page_title AS instance, page_namespace AS namespace FROM '.$page.' ' .
                        'JOIN '.$categorylinks.' ON page_id = cl_from JOIN '.$smwids.' ON page_title = smw_title AND page_namespace = smw_namespace JOIN '.$smwatts2.' ON smw_id = s_id ' .
                        'WHERE p_id='.$propertyID.' AND page_is_redirect = 0 AND '.$articleNamespaces.' AND cl_to IN (SELECT * FROM smw_ob_instances_sub))');

			// copy subcatgegories to supercategories of next iteration
			$db->query('DELETE FROM smw_ob_instances_super');
			$db->query('INSERT INTO smw_ob_instances_super (SELECT * FROM smw_ob_instances_sub)');

			// check if there was least one more subcategory. If not, all instances were found.
			$res = $db->query('SELECT COUNT(category) AS numOfSubCats FROM smw_ob_instances_super');
			$numOfSubCats = $db->fetchObject($res)->numOfSubCats;
			$db->freeResult($res);

			$db->query('DELETE FROM smw_ob_instances_sub');

		} while ($numOfSubCats > 0 && $maxDepth > 0);

		$res = $db->query('SELECT DISTINCT instance, namespace FROM smw_ob_instances');

		$result = array();
		if($db->numRows( $res ) > 0) {
			while($row = $db->fetchObject($res)) {

				$result[] = Title::newFromText($row->instance, $row->namespace);
			}
		}

		$db->freeResult($res);
        $db->query('DROP TEMPORARY TABLE smw_ob_instances');
		$db->query('DROP TEMPORARY TABLE smw_ob_instances_super');
		$db->query('DROP TEMPORARY TABLE smw_ob_instances_sub');
		
		return $result;
	}
}

