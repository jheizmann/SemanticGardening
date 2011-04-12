<?php
/**
 * @file
 * @ingroup ImportOntologyBot
 * 
 * @defgroup ImportOntologyBot
 * @ingroup SemanticGardeningBots
 * 
 * @author Kai K�hn
 * Created on 03.07.2007
 *
 * Author: kai
 */
if ( !defined( 'MEDIAWIKI' ) ) die;

global $sgagIP;
require_once("$sgagIP/includes/SGA_GardeningBot.php");
require_once("$sgagIP/includes/SGA_ParameterObjects.php");

define('XML_SCHEMA_NS', 'http://www.w3.org/2001/XMLSchema#');

class ImportOntologyBot extends GardeningBot {

	private static $OWL_VALUES_FROM;

	// global log which contains wiki-markup
	private $globalLog;

	// RDF model
	private $model;

	// set of wiki statements (semantic markup)
	private $wikiStatements = array();

	// use labels or localnames
	private $useLabels = true;

	// use ontology ID as a marker for the imported ontology
	private $ontologyID;

	function ImportOntologyBot() {
		parent::GardeningBot("smw_importontologybot");
		$this->globalLog = "== The following wiki pages were created during import: ==\n\n";
			
			
	}

	public function getHelpText() {
		return wfMsg('smw_gard_import_docu');
	}

	public function getLabel() {
		return wfMsg($this->id);
	}



	/**
	 * Returns an array mapping parameter IDs to parameter objects
	 */
	public function createParameters() {
		$param1 = new GardeningParamFileList('GARD_IO_FILENAME', "", SMW_GARD_PARAM_REQUIRED, "owl");
		$param2 = new GardeningParamString('GARD_IO_ONTOLOGY_ID', wfMsg('smw_gard_ontology_id'), SMW_GARD_PARAM_OPTIONAL, "http://myontology");
		return array($param1, $param2);
	}

	/**
	 * Import ontology
	 * Do not use echo when it is not running asynchronously.
	 */
	public function run($paramArray, $isAsync, $delay) {

		$this->globalLog = "";
		// do not allow to start synchronously.
		if (!$isAsync) {
			return 'Import ontology bot should not be started synchronously!';
		}
		$fileName = urldecode($paramArray['GARD_IO_FILENAME']);
		$this->useLabels = false; //array_key_exists('GARD_IO_USE_LABELS', $paramArray);
		$this->ontologyID = urldecode($paramArray['GARD_IO_ONTOLOGY_ID']);

		$fileTitle = Title::newFromText($fileName);
		$fileLocation = wfFindFile($fileTitle)->getPath();
		//$fileLocation = wfImageDir($fileName)."/".$fileName; old MW 1.10
			
		// initialize RAP
		$this->initializeRAP();
			
		// Load model
		echo "Load model: ".$fileLocation."...";
		$this->model = ModelFactory::getDefaultModel();
		$this->model->load($fileLocation);
		echo "done!.\n";

		echo "Number of triples: ".$this->model->size()."\n";

		// Translate RDF model to wiki markup
		echo "\nTranslate model...\n";
		$this->translateModel();
		echo "\nModel translated!\n";

		// Import model (and merge markup if necessary)
		echo "\nImport model...";
		$this->importModel();
		//$this->printModel();
		//$this->printAllStatements();
		echo "done!\n";

		// this is just for debugging
		#$this->model->writeAsHtmlTable();

		$this->globalLog .= "\nModel was successfully translated and imported!\n";
		print $this->globalLog;
		
	    //sync with tsc
        global $smwgDefaultStore;
        if($smwgDefaultStore == 'SMWTripleStore' || $smwgDefaultStore == 'SMWTripleStoreQuad'){
            define('SMWH_FORCE_TS_UPDATE', 'TRUE');
            smwfGetStore()->initialize(true);
        }
        
		return $this->globalLog;
	}

	/**
	 * Print generated Wiki text (for debugging reasons)
	 */
	private function printModel() {
		echo "\n----------------------\nPrint wikiStatements\n\n";
		$this->mergeStatementArray($this->wikiStatements);
		foreach($this->wikiStatements as $s) {
			if ($s == NULL) continue;
			echo "Pagename: ".$s['PAGENAME']." Namespace: ".$s['NS']."\n";

			foreach($s['WIKI'] as $stat) {
				echo $stat."\n";

			}

			echo "------------------------------------------------------\n";
		}

	}

	/**
	 * Prints all statements of the model. For debugging reasons.
	 */
	private function printAllStatements() {
		$it  = $this->model->findAsIterator(NULL, NULL, NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			print_r($statement);
		}
	}

	/**
	 * Translates the whole OWL model and populate the wiki statement arrays for
	 * the different namespaces ($ns_MAIN, $ns_ATTRIBTE, $ns_RELATION, $ns_CATEGORY)
	 */
	private function translateModel() {
		$triplesNum = $this->model->size();
		$oneCent = intval($triplesNum / 100) == 0 ? 1 : intval($triplesNum / 100);
		$this->setNumberOfTasks(5);
		$this->wikiStatements = array();
		// translate categories
		$this->addSubTask(1);
		print "\nTranslate categories...";
		$it  = $this->model->findAsIterator(NULL, RDF::TYPE(), OWL::OWL_CLASS());
		while ($it->hasNext()) {
			if ($triplesNum % $oneCent == 0) echo ".";

			$statement = $it->next();

			$subject = $statement->getSubject();
			$s = $this->createCategoryStatements($subject);
			$this->wikiStatements = array_merge($this->wikiStatements, $s);
			$triplesNum--;
		}
		$this->worked(1);
		print "done!\n";

		// translate relations
		$this->addSubTask(1);
		print "\nTranslate properties...";
		$it  = $this->model->findAsIterator(NULL, RDF::TYPE(), OWL::OBJECT_PROPERTY());
		while ($it->hasNext()) {
			if ($triplesNum % $oneCent == 0) echo ".";

			$statement = $it->next();

			$subject = $statement->getSubject();
			$s = $this->createRelationStatements($subject);
			$this->wikiStatements = array_merge($this->wikiStatements, $s);
			$triplesNum--;
		}
		$this->worked(1);

		// translate attributes
		$this->addSubTask(1);
		$it  = $this->model->findAsIterator(NULL, RDF::TYPE(), OWL::DATATYPE_PROPERTY());
		while ($it->hasNext()) {
			if ($triplesNum % $oneCent == 0) echo ".";

			$statement = $it->next();
			$subject = $statement->getSubject();
			$s = $this->createAttributeStatements($subject);

			$this->wikiStatements = array_merge($this->wikiStatements, $s);
			$triplesNum--;
		}
		$this->worked(1);
		print "done!\n";

		print "\nTranslate instances...";
		// translate instances, instantiated properties
		$this->addSubTask(1);
		$s = array();
		$it  = $this->model->findAsIterator(NULL, RDF::TYPE(), NULL);
		while ($it->hasNext()) {
			if ($triplesNum % $oneCent == 0) echo ".";

			$statement = $it->next();

			$subject = $statement->getSubject();
			$object = $statement->getObject();
			$objOWLClass = ($this->model->find($object, RDF::TYPE(), OWL::OWL_CLASS()));
			if (!$objOWLClass->isEmpty()) {
				$s = $this->createArticleStatements($subject);
			}
			$this->wikiStatements = array_merge($this->wikiStatements, $s);
			$triplesNum--;

		}
		$this->worked(1);
		print "done!\n";

	}

	/**
	 * Creates Wiki pages from the transformed model.
	 */
	private function importModel() {
		global $wgContLang, $wgUser;

		// merge statements
		ImportOntologyBot::mergeStatementArray($this->wikiStatements);
		
		$this->addSubTask(count($this->wikiStatements));

		// get all new pages as prefixed titles
		$newPages = array();
		foreach($this->wikiStatements as $s) {
			$newPages[] = $s['NS'] != 0 ? $wgContLang->getNsText($s['NS']).":".$s['PAGENAME'] : $s['PAGENAME'];
		}

		// delete removed pages (ie. those which are belonging to ontologyID
		// but which are not in the new imported file)
		$pagesToDelete = WikiImportTools::getPagesToDelete($newPages, $this->ontologyID);
		print "\nPages to delete...";

		foreach($pagesToDelete as $p) {
			$t = Title::newFromText( $p );
			$a = new Article($t);
			$reason = "ontology removed: ".$this->ontologyID;
			$id = $t->getArticleID( GAID_FOR_UPDATE );
			if( wfRunHooks('ArticleDelete', array(&$a, &$wgUser, &$reason, &$error)) ) {
				if( $a->doDeleteArticle( $reason ) ) {
					print "\n\tDeleted page: ".$p;
					wfRunHooks('ArticleDeleteComplete', array(&$a, &$wgUser, $reason, $id));
				}
			}

		}

		// import new them

		foreach($this->wikiStatements as $s) {
			$this->worked(1);
			if ($s == NULL) continue;
			print "\nImporting: ".$s['PAGENAME'];
			$this->globalLog .= "\n*Importing: ".ImportOntologyBot::getNamespaceText($s['NS']).":".$s['PAGENAME'];
			$title = Title::makeTitle( $s['NS'] , $s['PAGENAME'] );
			$wikiMarkup = array_unique($s['WIKI']);
			$this->globalLog .= WikiImportTools::insertOrUpdateArticle($title, $wikiMarkup, $this->ontologyID);

		}
	}



	/**
	 * Turns the triples of an individual into wiki source
	 */
	private function createArticleStatements($entity) {
		$statements = array();
		global $wgLanguageCode;
		$sLabel = $this->getLabelForEntity($entity, $wgLanguageCode);
		$st = Title::newFromText( $sLabel , NS_MAIN );
		if ($st == NULL) return $statements; // Could not create a title, next please

		// instantiated relations and attributes
		$it  = $this->model->findAsIterator($entity, NULL, NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$property = $statement->getPredicate();
			$object = $statement->getObject();
			$propertyIsRelation = ($this->model->find($property, RDF::TYPE(), OWL::OBJECT_PROPERTY()));
			$propertyIsAttribute = ($this->model->find($property, RDF::TYPE(), OWL::DATATYPE_PROPERTY()));
			if (!$propertyIsRelation->isEmpty()) {
				$this->createRelationAnnotation($st, $property, $object, $statements);
			}
			if (!$propertyIsAttribute->isEmpty()) {
				$this->createAttributeAnnotation($st, $property, $object, $statements);
			}

		}

		// categories links from instances
		$it  = $this->model->findAsIterator($entity, RDF::TYPE(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();

			$concept = $statement->getObject();
			$label = $this->getLabelForEntity($concept, $wgLanguageCode);
			$t = Title::newFromText( $label , NS_CATEGORY );
			if ($this->isInCategory($st, $t)) continue;
			if ($t == NULL) continue; // Could not create a title, next please

			$s = array();
			$s['NS'] = NS_MAIN;
			$s['ID'] = $st->getDBkey();
			$s['PAGENAME'] = $st->getDBkey();
			$s['WIKI'] = array();
			$s['WIKI'][] = "[[" . $t->getPrefixedText() . "]]" . "\n";
			$statements[] = $s;
		}

		return $statements;
	}

	/**
	 * Turns an OBJECT_PROPERTY statement into wiki source
	 *
	 * @param $st subject title
	 * @param $property statement
	 * @param $object statement
	 * @param $statement reference to set of statements for this subject.
	 */
	private function createRelationAnnotation($st, $property, $object, & $statements) {
		global $wgLanguageCode;
		$pLabel = $this->getLabelForEntity($property, $wgLanguageCode);
		$pt = Title::newFromText( $pLabel , SMW_NS_PROPERTY );
		if ($pt == NULL) return; // Could not create a title, next please
			
		$oLabel = $this->getLabelForEntity($object, $wgLanguageCode);
		$ot = Title::newFromText( $oLabel , NS_MAIN );
		if ($ot == NULL) return; // Could not create a title, next please

		$s = array();
		$s['NS'] = NS_MAIN;
		$s['WIKI'] = array();
		$s['WIKI'][] = "[[" . $pt->getText() . "::" . $ot->getPrefixedText() . "]]";
		$s['PAGENAME'] = $st->getDBkey();
		$s['ID'] = $st->getDBkey();
		$statements[] = $s;
			
	}

	/**
	 * Turns an DATATYPE_PROPERTY statement into wiki source
	 *
	 * @param $st subject title
	 * @param $property statement
	 * @param $object statement
	 * @param $statement reference to set of statements for this subject.
	 */
	private function createAttributeAnnotation($st, $property, $object, & $statements) {
		global $wgLanguageCode;
		$pLabel = $this->getLabelForEntity($property, $wgLanguageCode);
		$pt = Title::newFromText( $pLabel , SMW_NS_PROPERTY );
		if ($pt == NULL) return; // Could not create a title, next please
			
		$oLabel = $object->getLabel();
		
		// special case for dateTime/date property value
		if (preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $oLabel, $matches) > 0) {
			$oLabel = $matches[0];
		} else if (preg_match('/\d{4}-\d{2}-\d{2}/', $oLabel, $matches) > 0) {
            $oLabel = $matches[0];
        }
		
		// TODO check if already within wiki
		// TODO use datatype handler
		$s = array();
		$s['NS'] = NS_MAIN;
		$s['WIKI'] = array();
		$s['WIKI'][] = "[[" . $pt->getText() . "::" . $oLabel . "]]";
		$s['PAGENAME'] = $st->getDBkey();
		$s['ID'] = $st->getDBkey();
		$statements[] = $s;
	}

	/**
	 * Turns the triples of a class into wiki source.
	 */
	private function createCategoryStatements($entity) {
		$statements = array();
			
		$it  = $this->model->findAsIterator($entity, RDFS::SUB_CLASS_OF(), NULL);
		global $wgLanguageCode;
		$slabel = $this->getLabelForEntity($entity, $wgLanguageCode);

		if ($entity instanceof BlankNode) return $statements;
		$st = Title::newFromText( $slabel , NS_CATEGORY );
		if ($st == NULL) return $statements; // Could not create a title, next please
		$s1 = array();
		$s1['NS'] = NS_CATEGORY;
		$s1['WIKI'] = array();
		$s1['PAGENAME'] = $st->getDBkey();
		$s1['ID'] = $entity->getLocalName();
		while ($it->hasNext()) {
			$statement = $it->next();

			$superClass = $statement->getObject();

			$superClassLabel = $this->getLabelForEntity($superClass, $wgLanguageCode);
			$superClassTitle = Title::newFromText( $superClassLabel , NS_CATEGORY );
			if ($superClassTitle == NULL) continue; // Could not create a title, next please
			if ($this->isInCategory($st, $superClassTitle)) continue;

			$s1['WIKI'][] = !($superClass instanceof BlankNode) && !($superClassTitle->getText() == 'Thing') ? "[[" . $superClassTitle->getPrefixedText() . "]]"  : "";

			// create relations or attribute which has $entity as domain (with range, cardinality)
			if ($superClass instanceof BlankNode) {
				$this->createPropertiesFromCategory($st, $superClass, $statements);
			}
		}
		$statements[] = $s1;

		return $statements; //ImportOntologyBot::mergeStatementsForNS($statements);

	}

	private function createPropertiesFromCategory($st, $superClass, & $statements) {
		global $smwgContLang,$smwgHaloContLang, $wgContLang, $wgLanguageCode;
		$ssp = $smwgHaloContLang->getSpecialSchemaPropertyArray();
		$sp = $smwgContLang->getPropertyLabels();
			
		$it2 = $this->model->findAsIterator($superClass, OWL::ON_PROPERTY(), NULL);
		if ($it2->hasNext()) {
			$property = $it2->next()->getObject();
			$propertyName = $this->getLabelForEntity($property, $wgLanguageCode);
			$propertyTitle = Title::newFromText( $propertyName);
		}

		if (!isset($property)) return; // can not do anything, if property name can not be read.

		$s2 = array();
		$s2['PAGENAME'] = $propertyTitle->getDBkey();
		$s2['ID'] = $property->getLocalName();
		$s2['WIKI'] = array();
		$s2['NS'] = SMW_NS_PROPERTY;

		$it3 = $this->model->findAsIterator($superClass, OWL::ALL_VALUES_FROM(), NULL);
		if ($it3->hasNext()) {
			$range = $it3->next()->getObject();
		}

		if (!isset($range)) {
			$it6 = $this->model->findAsIterator($superClass, ImportOntologyBot::$OWL_VALUES_FROM, NULL);
			if ($it6->hasNext()) {
				$range = $it6->next()->getObject();
			}
		}

		if (!isset($range)) {
			$it6 = $this->model->findAsIterator($superClass, RDFS::RANGE(), NULL);
			if ($it6->hasNext()) {
				$range = $it6->next()->getObject();
			}
		}

		if (isset($range)) {


			$rangeCategoryName = $this->getLabelForEntity($range, $wgLanguageCode);
			$rangeCategoryTitle = Title::newFromText( $rangeCategoryName , (ImportOntologyBot::isXMLSchemaType($range->getURI())) ? SMW_NS_TYPE : NS_CATEGORY );


			if ((ImportOntologyBot::isXMLSchemaType($range->getURI()))) {
				$label = ImportOntologyBot::mapXSDTypesToWikiTypes($range->getLocalName());
				$s2['WIKI'][] = "[[".$sp["_TYPE"]."::". $wgContLang->getNsText(SMW_NS_TYPE) .":".$label."]]\n";
				$s2['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::".$st->getPrefixedText()."]]\n";
			} else {
				$s2['WIKI'][] = "[[".$sp["_TYPE"]."::Type:Page]]\n";
				$s2['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::".$st->getPrefixedText()."; ".$rangeCategoryTitle->getPrefixedText()."]]\n";
			}

		}

		$it4 = $this->model->findAsIterator($superClass, OWL::MIN_CARDINALITY(), NULL);
		if ($it4->hasNext()) {
			$minCardinality = $it4->next()->getObject()->getLabel();
		}

		$it5 = $this->model->findAsIterator($superClass, OWL::MAX_CARDINALITY(), NULL);
		if ($it5->hasNext()) {
			$maxCardinality = $it5->next()->getObject()->getLabel();
		}

		if (isset($minCardinality)) {
			$s2['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_MIN_CARD]."::$minCardinality]]\n";
		}
		if (isset($maxCardinality)) {
			$s2['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_MAX_CARD]."::$maxCardinality]]\n";
		}
		$statements[] = $s2;
	}

	/**
	 * Turns an OBJECT_PROPERTY statements into relation pages
	 * Reads also schema data which is exported by wiki. (may be obsolete)
	 */
	private function createRelationStatements($entity) {
		$statements = array();
		global $smwgContLang, $smwgHaloContLang, $wgContLang, $wgLanguageCode;
		$ssp = $smwgHaloContLang->getSpecialSchemaPropertyArray();
		$sp = $smwgContLang->getPropertyLabels();
		$sc = $smwgHaloContLang->getSpecialCategoryArray();
		$smwNSArray = $smwgContLang->getNamespaces();
			
		$slabel = $this->getLabelForEntity($entity, $wgLanguageCode);
		$st = Title::newFromText( $slabel , SMW_NS_PROPERTY );
		if ($st == NULL) {
			return $statements; // Could not create a title, next please
		}

		$s = array();
		$s['NS'] = SMW_NS_PROPERTY;
		$s['ID'] = $entity->getLocalName();
		$s['PAGENAME'] = $st->getDBkey();
		$s['WIKI'] = array();


		// read domain (if available)
		$domainLabels = array();
		$it  = $this->model->findAsIterator($entity, RDFS::DOMAIN(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();
			$union = $this->model->findFirstMatchingStatement($object, OWL::UNION_OF(), NULL);
			if (!is_null($union)) {
				$classes = $this->getRDFList($union->getObject());
				foreach($classes as $c) {
					$label = $this->getLabelForEntity($c, $wgLanguageCode);
					$domainLabels[] = $label;
				}
			} else {
				$label = $this->getLabelForEntity($object, $wgLanguageCode);
				$domainLabels[] = $label;
			}

		}

		// read range (if available)
		$rangeLabels = array();
		$it  = $this->model->findAsIterator($entity, RDFS::RANGE(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();
			if (!is_null($union)) {
				$classes = $this->getRDFList($union->getObject());
				foreach($classes as $c) {
					$label = $this->getLabelForEntity($c, $wgLanguageCode);
					$rangeLabels[] = $label;
				}
			} else {
				$label = $this->getLabelForEntity($object, $wgLanguageCode);
				$rangeLabels[] = $label;
			}
		}

		if (empty($domainLabels)) {
			foreach($rangeLabels as $label) {
				$s['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT].":: ;" . $wgContLang->getNsText(NS_CATEGORY) . ":" . $label . "]]" . "\n";
			}
		} else if (empty($rangeLabels)) {
			foreach($domainLabels as $label) {
				$s['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::" . $wgContLang->getNsText(NS_CATEGORY) . ":" . $label . "]]" . "\n";
			}
		} else {
			foreach($domainLabels as $domLabel) {
				foreach($rangeLabels as $ranLabel) {
					$s['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::" . $wgContLang->getNsText(NS_CATEGORY) . ":" . $domLabel . "; " . $wgContLang->getNsText(NS_CATEGORY) . ":" . $ranLabel . "]]" . "\n";
				}
			}
		}

		// read inverse statement (if available)
		$it  = $this->model->findAsIterator($entity, OWL::INVERSE_OF(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();
			$label = $this->getLabelForEntity($object, $wgLanguageCode);
			$s['WIKI'][] = "[[".$ssp[SMW_SSP_IS_INVERSE_OF]."::" . $smwNSArray[SMW_NS_PROPERTY] . ":" . $label . "]]" . "\n";
		}

		// read symetry (if available)
		$symProp  = $this->model->findFirstMatchingStatement($entity, RDF::TYPE(), OWL::SYMMETRIC_PROPERTY());
		if ($symProp != null) {
			$s['WIKI'][] = "[[".$wgContLang->getNsText(NS_CATEGORY).":".$sc[SMW_SC_SYMMETRICAL_RELATIONS]."]]" . "\n";
		}

		// read transitivity (if available)
		$transProp  = $this->model->findFirstMatchingStatement($entity, RDF::TYPE(), OWL::TRANSITIVE_PROPERTY());
		if ($transProp != null) {
			$s['WIKI'][] = "[[".$wgContLang->getNsText(NS_CATEGORY).":".$sc[SMW_SC_TRANSITIVE_RELATIONS]."]]" . "\n";
		}

		// read subproperties
		$it  = $this->model->findAsIterator($entity, RDFS::SUB_PROPERTY_OF(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();
			$label = $this->getLabelForEntity($object, $wgLanguageCode);

			$s['WIKI'][] = "[[".$sp['_SUBP']."::" . $smwNSArray[SMW_NS_PROPERTY] . ":" . $label . "]]" . "\n";

		}


		$statements[] = $s;
		return $statements;
	}


	/**
	 *  Turns an DATATYPE_PROPERTY statements into attribute pages
	 */
	private function createAttributeStatements($entity) {
		$statements = array();
		global $smwgContLang, $smwgHaloContLang, $wgContLang, $wgLanguageCode;
		$ssp = $smwgHaloContLang->getSpecialSchemaPropertyArray();
		$sp = $smwgContLang->getPropertyLabels();
		$smwNSArray = $smwgContLang->getNamespaces();
		$slabel = $this->getLabelForEntity($entity, $wgLanguageCode);
		$st = Title::newFromText( $slabel , SMW_NS_PROPERTY );
		if ($st == NULL) return $statements; // Could not create a title, next please

		$s = array();
		$s['NS'] = SMW_NS_PROPERTY;
		$s['PAGENAME'] = $st->getDBkey();
		$s['ID'] = $entity->getLocalName();
		$s['WIKI'] = array();


		// read domain (if available)
		$it  = $this->model->findAsIterator($entity, RDFS::DOMAIN(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();
			$union = $this->model->findFirstMatchingStatement($object, OWL::UNION_OF(), NULL);
			if (!is_null($union)) {
				$classes = $this->getRDFList($union->getObject());
				foreach($classes as $c) {
					$label = $this->getLabelForEntity($c, $wgLanguageCode);
					$s['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::" . $wgContLang->getNsText(NS_CATEGORY) . ":" . $label . "]]" . "\n";
				}
			} else {
				$label = $this->getLabelForEntity($object, $wgLanguageCode);
				$s['WIKI'][] = "[[".$ssp[SMW_SSP_HAS_DOMAIN_AND_RANGE_HINT]."::" . $wgContLang->getNsText(NS_CATEGORY) . ":" . $label . "]]" . "\n";
			}


		}

		// read range (if available)
		$it  = $this->model->findAsIterator($entity, RDFS::RANGE(), NULL);
		while ($it->hasNext()) {
			$statement = $it->next();
			$object = $statement->getObject();


			$label = $this->getLabelForEntity($object, $wgLanguageCode);
			$label = ImportOntologyBot::mapXSDTypesToWikiTypes($label);
				
			$s['WIKI'][] = "[[".$sp["_TYPE"]."::" . $wgContLang->getNsText(SMW_NS_TYPE) . ":" . $label . "]]" . "\n";
		}
		
		// read subproperties
        $it  = $this->model->findAsIterator($entity, RDFS::SUB_PROPERTY_OF(), NULL);
        while ($it->hasNext()) {
            $statement = $it->next();
            $object = $statement->getObject();
            $label = $this->getLabelForEntity($object, $wgLanguageCode);

            $s['WIKI'][] = "[[".$sp['_SUBP']."::" . $smwNSArray[SMW_NS_PROPERTY] . ":" . $label . "]]" . "\n";

        }
		

		$statements[] = $s;
		return $statements;
	}

	/**
	 * Returns nodes from an RDFList.
	 *
	 * @param Resource $startNode
	 * @return array of Resource
	 */
	private function getRDFList($startNode) {
		$e  = $this->model->findFirstMatchingStatement($startNode, RDF::FIRST(), NULL);
		$result = array();
		while (!is_null($e)) {

			$result[] = $e->getObject();
			$tail = $this->model->findFirstMatchingStatement($e->getSubject(), RDF::REST(), NULL);
			if (is_null($tail)) break;
			$e = $this->model->findFirstMatchingStatement($tail->getObject(), RDF::FIRST(), NULL);

		}
		return $result;
	}
	/**
	 * Returns a label for a given Resource $entity
	 *
	 * $entity Resource
	 * $lang language code (default is english)
	 */
	private function getLabelForEntity($entity, $lang = "en") {

		$label = $entity->getLocalName(); // use local name as default, if no labels exist at all
		if (!$this->useLabels) return urldecode($label);
		$it = $this->model->findAsIterator($entity, RDFS::LABEL(), NULL);
		$takeFirst = true;
		while ($it->hasNext()) {
			$labelstatement = $it->next();
			if ($takeFirst) { // make sure that at least first label is taken
				$takeFirst = false;
				$label = $labelstatement->getLabelObject();
			}

			if ($labelstatement != NULL && $labelstatement->getObject()->getLanguage() == $lang) {

				$label = $labelstatement->getLabelObject();
			}
		}
		return $label;
	}

	/**
	 * Checks if an article is in a category. Returns true if yes, and false else.
	 * Works also to check if a category is a subcategory of the second.
	 */
	private function isInCategory($article, $category) {
		if (!$article->exists()) return FALSE; // this was the easy part :)

		$categories = $article->getParentCategories();
		if ('' == $categories) return FALSE;

		$catkeys = array_keys($categories);

		foreach($catkeys as $cat) {
			if ($category->getPrefixedDBKey() == $cat) {
				return TRUE;
			}
		}

		return FALSE;
	}

	private static function isXMLSchemaType($type) {
		return $type == XML_SCHEMA_NS.'int' || $type == XML_SCHEMA_NS.'integer' || $type == XML_SCHEMA_NS.'string' || $type == XML_SCHEMA_NS.'float'
		|| $type == XML_SCHEMA_NS.'boolean' || $type == XML_SCHEMA_NS.'number'
		|| $type == XML_SCHEMA_NS.'date' || $type == XML_SCHEMA_NS.'time' || $type == XML_SCHEMA_NS.'datetime';
	}



	/**
	 * Merges statements concerning the same wiki page.
	 *
	 * @param & $statements Array of hash arrays containing the following keys: PAGENAME, NS, WIKI
	 */
	private static function mergeStatementArray( & $statements) {

		if (count($statements) == 0) return;

		// sort array for ID
		for($i = 0, $n = count($statements); $i < $n; $i++) {
			for($j = 0; $j < $n-1; $j++) {
				if (strcmp($statements[$j]['ID'], $statements[$j+1]['ID']) > 0) {
					$help = $statements[$j];
					$statements[$j] = $statements[$j+1];
					$statements[$j+1] = $help;
				}
			}
		}

		// sort for NS
		for($i = 0, $n = count($statements); $i < $n; $i++) {
			for($j = 0; $j < $n-1; $j++) {
				if ($statements[$j]['NS'] < $statements[$j+1]['NS']) {
					$help = $statements[$j];
					$statements[$j] = $statements[$j+1];
					$statements[$j+1] = $help;
				}
			}
		}

		// merge wiki statements and set all merged entries to NULL
		$current = & $statements[0];
		for($i = 1, $n = count($statements); $i < $n; $i++) {
			if (($statements[$i]['ID'] == $current['ID']) && ($statements[$i]['NS'] == $current['NS'])) {

				$diff = array_diff($statements[$i]['WIKI'], $current['WIKI']);

				$current['WIKI'] = array_merge($current['WIKI'], $diff);
				$statements[$i] = NULL;
			} else {
				$current = & $statements[$i];
			}

		}
	}

	private static function mapXSDTypesToWikiTypes($xsdType) {
		switch($xsdType) {
			case 'string': return 'String';
			case 'number': return 'Number';
			case 'int': return 'Number';
			case 'integer': return 'Number';
			case 'float': return 'Number';
			case 'double': return 'Number';
			case 'datetime': return 'Date';
			case 'date': return 'Date';
			case 'boolean': return 'Boolean';
			default: return 'String';
		}
	}

	private static function getNamespaceText($ns) {
		global $smwgContLang, $wgLang;
		$nsArray = $smwgContLang->getNamespaces();
		if ($ns == NS_TEMPLATE || $ns == NS_CATEGORY) {
			$ns = $wgLang->getNsText($ns);
		} else {
			$ns = $ns != NS_MAIN ? $nsArray[$ns] : "";
		}
		return $ns;
	}

	public function initializeRAP() {
		// initialize RAP
		echo "\nTry to include RAP RDF-API...";
		$smwgRAPPath = dirname(__FILE__) . "/../../../SemanticMediaWiki/libs/rdfapi-php";
		$Rdfapi_includes= $smwgRAPPath . '/api/';
		define("RDFAPI_INCLUDE_DIR", $Rdfapi_includes); // not sure if the constant is needed within RAP
		include_once(RDFAPI_INCLUDE_DIR . "RdfAPI.php");
		include_once( RDFAPI_INCLUDE_DIR . 'vocabulary/RDF_C.php');
		include_once( RDFAPI_INCLUDE_DIR . 'vocabulary/OWL_C.php');
		include_once( RDFAPI_INCLUDE_DIR . 'vocabulary/RDFS_C.php');
		echo "done!.\n";

		// user defined OWL::valuesFrom property (generated by Prot�g�)
		ImportOntologyBot::$OWL_VALUES_FROM = new Resource(OWL_NS . 'valuesFrom');
	}

	/**
	 * For automatic testing
	 *
	 * @param string $fileLocation
	 */
	public function testOntologyImport($fileLocation) {
			

		// Load model
		echo "Load model: ".$fileLocation."...";
		$this->model = ModelFactory::getDefaultModel();
		$this->model->load($fileLocation);
		echo "done!.\n";

		echo "Number of triples: ".$this->model->size()."\n";

		// Translate RDF model to wiki markup
		echo "\nTranslate model...\n";
		$this->translateModel();
		echo "\nModel translated!\n";
	}

	/**
	 * for automatic testing
	 *
	 * @return array
	 */
	public function getWikiStatements() {
		return $this->wikiStatements;
	}
}

class WikiImportTools {
	/**
	 * Get all pages which can be deleted, ie. which are not in the set of
	 * new pages.
	 *
	 * @param array of string $newPages Prefixed titles
	 * @return array of string
	 */
	public static function getPagesToDelete($newPages, $ontologyID) {
		$oldPages = array();
		$query = SMWQueryProcessor::createQuery("[[OntologyID::$ontologyID]]", array());
		$res = smwfGetStore()->getQueryResult($query);
		$next = $res->getNext();
		while($next !== false) {

			$title = $next[0]->getNextObject()->getTitle();
			if (!is_null($title)) {
				$oldPages[] = $title->getPrefixedText();
			}
			$next = $res->getNext();
		}
		return array_diff($oldPages, $newPages);

	}



	/**
	 * Inserts an article or updates it if it already exists
	 *
	 * @param $title Title of article
	 * @param $text Text to add
	 */
	public static function insertOrUpdateArticle($title, $wikiMarkup, $ontologyID) {
		if (NULL == $title) return "";
		$globalLog = "";
		if ($title->exists()) {
			print " (merging)";
			$globalLog = " (merging)";
			$article = new Article($title);
			$oldtext = $article->getContent();
			// article exists, so paste only diff of semantic markup
			$text = WikiTextTools::removeAnnotations($oldtext);
			$text .= "\n".implode("\n", $wikiMarkup);
			$text .= "\n[[OntologyID::".$ontologyID."]]";
			$article->updateArticle( $text, wfMsg( 'smw_oi_importedfromontology' ), FALSE, FALSE );
		} else {
			// articles does not exist, so simply paste all semantic markup
			$text = implode("\n", $wikiMarkup);
			$text .= "\n[[OntologyID::".$ontologyID."]]";
			$newArticle = new Article($title);
			$newArticle->insertNewArticle( $text, wfMsg( 'smw_oi_importedfromontology' ), FALSE, FALSE, FALSE, FALSE );

		}
		return $globalLog;
	}


}

class WikiTextTools {
	/**
	 * Removes all annotations from $text
	 *
	 * @param string $text
	 * @return string
	 */
	public static function removeAnnotations($text) {

		// Parse links to extract properties and categories
		$semanticLinkPattern = '/(\n*\[\[(([^:][^]]*)::)+([^\|\]]*)(\|([^]]*))?\]\])/i';
		$categoryPattern = '/(\n*\[\[\s*category\s*:\s*([^\|\]]*)(\|([^]]*))?\]\])/i';
		$textWithoutAnnotations = preg_replace($semanticLinkPattern, "", $text);
		$textWithoutAnnotations = preg_replace($categoryPattern, "", $textWithoutAnnotations);
		return $textWithoutAnnotations;
	}

	/**
	 * Calculates diff of semantic markup and an existing wiki text.
	 */
	public static function diffSemanticMarkup($oldWikiText, $newSemanticMarkup) {
		$annotations = array();
		// Parse links to extract relations
		$semanticLinkPattern = '(\[\[(([^:][^]]*)::)+([^\|\]]*)(\|([^]]*))?\]\])';
		$num = preg_match_all($semanticLinkPattern, $oldWikiText, $relMatches);
		$oldSemanticMarkup = array();
		if ($num > 0) {
			for($i = 0, $n = count($relMatches[0]); $i < $n; $i++) {

				$oldSemanticMarkup[] = $relMatches[0][$i];
			}
		}


		return array_diff($newSemanticMarkup, $oldSemanticMarkup);
	}
}
/*
 * Note: This bot filter has no real functionality. It is just a dummy to
 * prevent error messages in the GardeningLog. There are no gardening issues
 * about importing. Instead there's a textual log.
 *
 */
define('SMW_IMPORTONTOLOGY_BOT_BASE', 700);

class ImportOntologyBotFilter extends GardeningIssueFilter {



	public function __construct() {
		parent::__construct(SMW_IMPORTONTOLOGY_BOT_BASE);
		$this->gi_issue_classes = array(wfMsg('smw_gardissue_class_all'));

	}

	public function getUserFilterControls($specialAttPage, $request) {
		return '';
	}

	public function linkUserParameters(& $wgRequest) {

	}

	public function getData($options, $request) {
		parent::getData($options, $request, 0);
	}
}

// create one instance for registration at Gardening framework
// new ImportOntologyBot();

// For importing an ontology please do not use the ImportBot any longer.
// Instead use the deployment framework: smwadmin -i <ontology-file>
// This will read the ontology and create appropriate wiki pages.  
