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
 * @ingroup ConsistencyBot
 *
 * Created on 23.05.2007
 *
 * @author Kai K�hn
 */
if ( !defined( 'MEDIAWIKI' ) ) die;

global $sgagIP;
require_once("SGA_GraphEdge.php");
require_once("$sgagIP/includes/SGA_GraphHelper.php");

class AnnotationLevelConsistency {

	private $bot;
	private $delay;
	private $limit;

	// Category/Property Graph.
	// They are cached for the whole consistency checks.
	private $categoryGraph;
	private $propertyGraph;

	// GardeningIssue store
	private $gi_store;

	// Consistency store
	private $cc_store;

	private $verbose = false;

	// Important: Attribute values (primitives) are always syntactically
	// correct when they are in the database. So only relations
	// will be checked.

	public function AnnotationLevelConsistency(& $bot, $delay, & $categoryGraph, & $propertyGraph, $verbose = false) {
		$this->bot = $bot;
		$this->delay = $delay;
		$this->cc_store = ConsitencyBotStorage::getConsistencyStorage();

		$this->categoryGraph = $categoryGraph;
		$this->propertyGraph = $propertyGraph;
		$this->gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
		$this->verbose = $verbose;
		$this->limit = 100;
	}
	/**
	 * Checks if property annotations uses schema consistent values
	 */
	public function checkAllPropertyAnnotations($restrictToCategories = NULL) {

		$requestoptions = new SMWRequestOptions();
		$requestoptions->limit = $this->limit;
		$requestoptions->offset = 0;
		$totalWork = smwfGetSemanticStore()->getNumber(SMW_NS_PROPERTY);
		if ($this->verbose) $this->bot->addSubTask($totalWork);
		do {
			$properties = smwfGetSemanticStore()->getPages(array(SMW_NS_PROPERTY), $requestoptions);


			foreach($properties as $p) {

				if ($this->verbose && $this->bot->isAborted()) break;
				usleep($this->delay);

				if ($this->verbose) {
					$this->bot->worked(1);
					$workDone =$this->bot->getCurrentWorkDone();
					if ($workDone % 10 == 1 || $workDone == $totalWork) {
						GardeningBot::printProgress($workDone/$totalWork);
					}
				}

				if (SMWHaloPredefinedPages::$HAS_DOMAIN_AND_RANGE->equals($p)
				|| SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY->equals($p)
				|| SMWHaloPredefinedPages::$HAS_MAX_CARDINALITY->equals($p)
				|| SMWHaloPredefinedPages::$IS_INVERSE_OF->equals($p)) {
					// ignore builtin halo properties
					continue;
				}

				// get annotation subjects for the property.
				$subjects = array();
				$p_DV = SMWPropertyValue::makeUserProperty($p->getDBkey());
				if (!$p_DV->isUserDefined()) continue;
				if (is_null($restrictToCategories) || empty($restrictToCategories)) {
					$allPropertySubjects = smwfGetStore()->getAllPropertySubjects($p_DV->getDataItem());
					foreach ($allPropertySubjects as $dv) {
						$subjects[] = $dv->getTitle();
					};
				} else {
					$subjects = $this->cc_store->getInstancesUsingProperty($p_DV, $restrictToCategories);

				}

				$this->checkPropertyAnnotations($subjects, $p);

			}
			$requestoptions->offset += $this->limit;
		} while (count($properties) == $this->limit);
	}

	public function checkPropertyAnnotations(& $subjects, $property) {
		// get domain and range categories of property

		$domainRangeAnnotations = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($property), 
			SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_DOMAIN_AND_RANGE->getText()));


		if (empty($domainRangeAnnotations)) {
			// if there are no range categories defined, try to find a super relation with defined range categories
			$domainRangeAnnotations = $this->cc_store->getDomainsAndRangesOfSuperProperty($this->propertyGraph, $property);
		}
		
		$this->checkForMissingParams($subjects, $property);			

		if (empty($domainRangeAnnotations)) {
			// if it's still empty, there's no domain or range defined at all. In this case, simply skip it in order not to pollute the consistency log.
			// but check for missing params of n-ary relations before.

			
			return;
		}
		
		// iterate over all property subjects
		foreach($subjects as $subject) {

			if ($subject == null) {
				continue;
			}

			$categoriesOfSubject = smwfGetSemanticStore()->getCategoriesForInstance($subject);

			list($domain_cov_results, $domainCorrect) = $this->checkDomain($categoriesOfSubject, $domainRangeAnnotations);
			if (!$domainCorrect) {
				$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_WRONG_DOMAIN_VALUE, $subject, $property );

			}

			// get property value for a given instance
			$propertyDV = SMWPropertyValue::makeUserProperty($property->getDBkey());
			$relationTargets = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($subject), 
				$propertyDV->getDataItem());

			foreach($relationTargets as $target) {

				// decide which type and do consistency checks
				if ($target instanceof SMWDIWikiPage) {  // binary relation
					$rd_target = smwfGetSemanticStore()->getRedirectTarget($target->getTitle());
					if (!$rd_target->exists()) continue;
					$categoriesOfObject = smwfGetSemanticStore()->getCategoriesForInstance($rd_target);
					if ($domainCorrect) {
						$rangeCorrect = $this->checkRange($domain_cov_results, $categoriesOfObject, $domainRangeAnnotations);
					} else {
						$rangeCorrect = $this->checkRange(NULL, $categoriesOfObject, $domainRangeAnnotations);
					}
					if (!$rangeCorrect) {
						$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_WRONG_TARGET_VALUE, $subject, $property, $rd_target != NULL ? $rd_target->getDBkey() : NULL);
					}

				} else if ($target instanceof SMWDIContainer) { // n-ary relation

					continue; //nothing to check here. Properties, that are used to hold fields within records are evaluated themselves
					
				} else {
					// Normally, one would check attribute values here, but they are always correctly validated during SAVE.
					// Otherwise the annotation would not appear in the database. *Exception*: wrong units


					break; // always break the loop, because an attribute annotation is representative for all others.
				}


			}
		}
	}

	/**
	 * Checks weather subject and object matches a domain/range pair.
	 *
	 * @param subject Title
	 * @param object Title
	 * @param $domainRange SMWNaryValue
	 */


	/**
	 * Checks if number of property appearances in articles are schema-consistent.
	 */
	public function checkAllAnnotationCardinalities($restrictToCategories = NULL) {


		// get all properties
		print "\n";
		$properties = smwfGetSemanticStore()->getPages(array(SMW_NS_PROPERTY));
		$totalWork = count($properties);
		if ($this->verbose) $this->bot->addSubTask($totalWork);
		foreach($properties as $a) {
			if ($this->verbose && $this->bot->isAborted()) break;
			usleep($this->delay);

			if ($this->verbose) {
				$this->bot->worked(1);
				$workDone = $this->bot->getCurrentWorkDone();
				if ($workDone % 10 == 1 || $workDone == $totalWork) GardeningBot::printProgress($workDone/$totalWork);
			}

			// ignore builtin properties
			if (SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY ->equals($a)
			|| SMWHaloPredefinedPages::$HAS_MAX_CARDINALITY->equals($a)
			|| SMWHaloPredefinedPages::$HAS_DOMAIN_AND_RANGE->equals($a)
			|| SMWHaloPredefinedPages::$IS_INVERSE_OF->equals($a)) {
				continue;
			}

			// check cardinalities for all instantiations of $a and its subproperties
			$this->checkAnnotationCardinalities($a, $restrictToCategories);


		}

	}

	public function checkAnnotationCardinalities($a, $restrictToCategories = array()) {
		// get minimum cardinality

		$minCardArray = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($a), 
			SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY->getText()));

		if (empty($minCardArray)) {
			// if it does not exist, get minimum cardinality from superproperty
			$minCards = CARDINALITY_MIN;
		} else {
			// assume there's only one defined. If not it will be found in co-variance checker anyway
			$minCards = intval(GardeningBot::getXSDValue($minCardArray[0]));
		}

		// get maximum cardinality

		$maxCardsArray = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($a), 
			SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_MAX_CARDINALITY->getText()));

		if (empty($maxCardsArray)) {
			// if it does not exist, get maximum cardinality from superproperty
			$maxCards = CARDINALITY_UNLIMITED;
		} else {
			// assume there's only one defined. If not it will be found in co-variance checker anyway
			$maxCards = intval(GardeningBot::getXSDValue($maxCardsArray[0]));
		}

		if ($minCards == CARDINALITY_MIN && $maxCards == CARDINALITY_UNLIMITED) {
			// default case: no check needed, so skip it.
			return;
		}

		// get all instances which have instantiated properties (including subproperties) of $a
		// and the number of these annotations for each for instance
		$result = $this->cc_store->getNumberOfPropertyInstantiations($a);

		// compare actual number of appearances to minCard and maxCard and log errors if necessary
		foreach($result as $r) {
			list($subject, $numOfInstProps) = $r;

			// less than allowed?
			if ($numOfInstProps < $minCards) {
				if (!$this->gi_store->existsGardeningIssue($this->bot->getBotID(), SMW_GARDISSUE_TOO_LOW_CARD, NULL, $subject, $a)) {

					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_TOO_LOW_CARD, $subject, $a, $minCards - $numOfInstProps);
				}
			}

			// too many than allowed?
			if ($numOfInstProps > $maxCards) {
				if (!$this->gi_store->existsGardeningIssue($this->bot->getBotID(), SMW_GARDISSUE_TOO_HIGH_CARD, NULL, $subject, $a)) {

					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_TOO_HIGH_CARD, $subject, $a, $numOfInstProps - $maxCards);
				}
			}
		}

		// special case: If minCard > CARDINALITY_MIN (=0), it may happen that an instance does not have a single property instantiation although it should.
		// Then it will not be found with 'getNumberOfPropertyInstantiations' method. Only the schema information about the domain category can tell if
		// an instance has too less annotations than allowed.

		if ($minCards == CARDINALITY_MIN) {
			// check if minCard > 0 is inherited

			$minCards = $this->cc_store->getMinCardinalityOfSuperProperty($this->propertyGraph, $a);
			if ($minCards == CARDINALITY_MIN) return; // do nothing for default cardinality
		}

		// get domains

		$domainRangeAnnotations = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($a), 
			SMWDIProperty::newFromUserLabel(SMWHaloPredefinedPages::$HAS_DOMAIN_AND_RANGE->getText()));

		if (empty($domainRangeAnnotations)) {
			// if there are no domain categories defined, this check can not be applied.
			return;
		}

		foreach($domainRangeAnnotations as $domRan) {
			$domain = $domRan->getSemanticData()->getPropertyValues(SMWDIProperty::newFromUserLabel('Has range'));
			if (count($domain) == 0) continue; // ignore annotations with missing domain
			$domainCategory = $domain[0]->getTitle();
			if (count($restrictToCategories) > 0) {
				// check if domain categories appear in the categories to restrict.
				if (count(array_filter($restrictToCategories,
				create_function('$e', '$e->getText()=="'.$domainCategory->getText().'";'))) == 0) continue;
			}
			$instances = smwfGetSemanticStore()->getInstances($domainCategory);


			$results = $this->cc_store->getMissingPropertyInstantiations($a, $instances);
			
			foreach($results as $title) {


				if (!$this->gi_store->existsGardeningIssue($this->bot->getBotID(), SMW_GARDISSUE_MISSING_ANNOTATIONS, NULL, $title, $a)) {

					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_MISSING_ANNOTATIONS, $title, $a, $minCards);
				}

			}


		}
	}

	public function checkAnnotationCardinalitiesForInstance($instance, array & $domainProperties) {

		$properties = smwfGetStore()->getProperties($instance);

		foreach($properties as $a) {
			// get minimum cardinality
			$aTitle = Title::newFromDBkey(GardeningBot::getXSDValue($a), SMW_NS_PROPERTY);
			$minCardArray = smwfGetStore()->getPropertyValues($aTitle, SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY);

			if (empty($minCardArray)) {
				// if it does not exist, get minimum cardinality from superproperty
				$minCards = CARDINALITY_MIN;
			} else {
				// assume there's only one defined. If not it will be found in co-variance checker anyway
				$minCards = intval(GardeningBot::getXSDValue($minCardArray[0]));
			}

			// get maximum cardinality
			$maxCardsArray = smwfGetStore()->getPropertyValues($aTitle, smwfGetSemanticStore()->maxCardProp);

			if (empty($maxCardsArray)) {
				// if it does not exist, get maximum cardinality from superproperty
				$maxCards = CARDINALITY_UNLIMITED;

			} else {
				// assume there's only one defined. If not it will be found in co-variance checker anyway
				$maxCards = intval(GardeningBot::getXSDValue($maxCardsArray[0]));
			}

			if ($minCards == CARDINALITY_MIN && $maxCards == CARDINALITY_UNLIMITED) {
				// default case: no check needed, so skip it.
				continue;
			}

			// get all instances which have instantiated properties (including subproperties) of $a
			// and the number of these annotations for each for instance
			$result = smwfGetStore()->getPropertyValues($instance, $a);

			// compare actual number of appearances to minCard and maxCard and log errors if necessary

			$subject = $instance;
			$numOfInstProps = count($result);

			// less than allowed?
			if ($numOfInstProps < $minCards) {
				if (!$this->gi_store->existsGardeningIssue($this->bot->getBotID(), SMW_GARDISSUE_TOO_LOW_CARD, NULL, $subject, $aTitle)) {

					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_TOO_LOW_CARD, $subject, $aTitle, $minCards - $numOfInstProps);
				}
			}

			// too many than allowed?
			if ($numOfInstProps > $maxCards) {
				if (!$this->gi_store->existsGardeningIssue($this->bot->getBotID(), SMW_GARDISSUE_TOO_HIGH_CARD, NULL, $subject, $aTitle)) {

					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_TOO_HIGH_CARD, $subject, $aTitle, $numOfInstProps - $maxCards);
				}
			}

		}
		// special case: If minCard > CARDINALITY_MIN (=0), it may happen that an instance does not have a single property instantiation although it should.

		foreach($domainProperties as $domainProperty) {

			// get minimum cardinality
			$minCardArray = smwfGetStore()->getPropertyValues($domainProperty, SMWHaloPredefinedPages::$HAS_MIN_CARDINALITY);

			if (empty($minCardArray)) {
				// if it does not exist, get minimum cardinality from superproperty
				$minCards = CARDINALITY_MIN;
			} else {
				// assume there's only one defined. If not it will be found in co-variance checker anyway
				$minCards = intval(GardeningBot::getXSDValue($minCardArray[0]));
			}

			if ($minCards == CARDINALITY_MIN) {
				// default case: no check needed, so skip it.
				continue;
			}
			$domainPropertyDV = SMWPropertyValue::makeUserProperty($domainProperty->getDBkey());
			$num = count(smwfGetStore()->getPropertyValues($instance, $domainPropertyDV));

			if ($num == 0) {

				$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_MISSING_ANNOTATIONS, $instance, $domainProperty, $minCards);
			}
		}





	}
	/**
	 * Checks if all annotations with units have proper units (such defined by 'corresponds to' relations).
	 */
	public function checkAllUnits() {
		// check attribute annotation cardinalities
		print "\n";
		$types = smwfGetSemanticStore()->getPages(array(SMW_NS_TYPE));
		$totalWork = count($types);
		if ($this->verbose) $this->bot->addSubTask($totalWork);
		foreach($types as $type) {
			if ($this->verbose && $this->bot->isAborted()) break;
			usleep($this->delay);

			if ($this->verbose) {
				$this->bot->worked(1);
				$workDone = $this->bot->getCurrentWorkDone();
				if ($workDone % 5 == 1 || $workDone == $totalWork) GardeningBot::printProgress($workDone/$totalWork);
			}

			$this->checkUnits($type);
		}
	}

	public function checkUnits($type) {
		// get all *used* units for a given datatype
		$units = smwfGetSemanticStore()->a($type);

		// get all *defined* units for a given datatype
		$conversionFactorDV = SMWPropertyValue::makeProperty("_CONV");
		$conversionFactorSIDV = SMWPropertyValue::makeProperty("___cfsi");
		$conversion_factors = smwfGetStore()->getPropertyValues($type, $conversionFactorDV);
		$si_conversion_factors = smwfGetStore()->getPropertyValues($type, $conversionFactorSIDV);

		// match used units against defined a log if there's a mismatch
		foreach($units as $unit) {

			$correct_unit = false;
			if ($unit == NULL) continue;

			// check if a unit matches
			foreach($conversion_factors as $c) {
				$valuetrimmed = trim(GardeningBot::getXSDValue($c));
				// remove linear factory, then split the units separted by comma
				$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
				$units = explode(",", $unitString);

				foreach($units as $u) {
					$correct_unit |= $unit == trim($u);
				}
			}

			// check if a SI unit matches
			foreach($si_conversion_factors as $c) {
				$valuetrimmed = trim(GardeningBot::getXSDValue($c));
				// remove linear factory, then split the units separted by comma
				$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
				$units = explode(",", $unitString);
				foreach($units as $u) {
					$correct_unit |= $unit == trim($u);
				}
			}

			if (!$correct_unit) {

				$annotations = smwfGetSemanticStore()->getAnnotationsWithUnit($type, $unit);

				foreach($annotations as $a) {
					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_WRONG_UNIT, $a[0], $a[1], $unit);
				}
			}
		}
	}

	public function checkUnitForInstance($instance) {

		$hasTypeDV = SMWPropertyValue::makeProperty("_TYPE");
		$conversionFactorDV = SMWPropertyValue::makeProperty("_CONV");
		$conversionFactorSIDV = SMWPropertyValue::makeProperty("___cfsi");
		$properties = smwfGetStore()->getProperties($instance);
		foreach($properties as $p) {
			$pTitle = Title::newFromDBkey(GardeningBot::getXSDValue($p), SMW_NS_PROPERTY);
			$values = smwfGetStore()->getPropertyValues($instance, $p);
			foreach($values as $v) {
				if ($v->getUnit() != '') {
					$type = smwfGetStore()->getPropertyValues($pTitle, $hasTypeDV);
					$firstType = reset($type);
					if (count($type) == 0 || $firstType->isBuiltIn()) continue;
					$typeTitle = Title::newFromText(GardeningBot::getXSDValue($firstType), SMW_NS_TYPE);
					$conversion_factors = smwfGetStore()->getPropertyValues($typeTitle, $conversionFactorDV);
					$si_conversion_factors = smwfGetStore()->getPropertyValues($typeTitle, $conversionFactorSIDV);
					$correct_unit = false;

					// check if a unit matches
					foreach($conversion_factors as $c) {
						$valuetrimmed = trim(GardeningBot::getXSDValue($c));
						// remove linear factory, then split the units separted by comma
						$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
						$units = explode(",", $unitString);
							
						foreach($units as $u) {
							$correct_unit |= $v->getUnit() == trim($u);
						}
					}

					// check if a SI unit matches
					foreach($si_conversion_factors as $c) {
						$valuetrimmed = trim(GardeningBot::getXSDValue($c));
						// remove linear factory, then split the units separted by comma
						$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
						$units = explode(",", $unitString);
						foreach($units as $u) {
							$correct_unit |= $v->getUnit() == trim($u);
						}
					}
					if (!$correct_unit) {
						$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_WRONG_UNIT, $instance, $pTitle, $v->getUnit());
					}
				}
			}
		}

	}

	public function checkUnitsForProperty($property) {
		$hasTypeDV = SMWPropertyValue::makeProperty("_TYPE");
		$conversionFactorDV = SMWPropertyValue::makeProperty("_CONV");
		$conversionFactorSIDV = SMWPropertyValue::makeProperty("___cfsi");
		$type = smwfGetStore()->getPropertyValues($property, $hasTypeDV);
		$firstType = reset($type);
		if (count($type) == 0 || $firstType->isBuiltIn()) return;
		$typeTitle = Title::newFromText(GardeningBot::getXSDValue($firstType), SMW_NS_TYPE);
		$subjects = smwfGetStore()->getAllPropertySubjects(SMWPropertyValue::makeUserProperty($property->getDBkey()));
		foreach($subjects as $s) {
			$propertyDV = SMWPropertyValue::makeUserProperty($property->getDBkey());
			$values = smwfGetStore()->getPropertyValues($s->getTitle(), $propertyDV);
			foreach($values as $v) {
				if ($v->getUnit() != '') {
					$conversion_factors = smwfGetStore()->getPropertyValues($typeTitle, $conversionFactorDV);
					$si_conversion_factors = smwfGetStore()->getPropertyValues($typeTitle, $conversionFactorSIDV);
					$correct_unit = false;

					// check if a unit matches
					foreach($conversion_factors as $c) {
						$valuetrimmed = trim(GardeningBot::getXSDValue($c));
						// remove linear factory, then split the units separted by comma
						$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
						$units = explode(",", $unitString);
							
						foreach($units as $u) {
							$correct_unit |= $v->getUnit() == trim($u);
						}
					}

					// check if a SI unit matches
					foreach($si_conversion_factors as $c) {
						$valuetrimmed = trim(GardeningBot::getXSDValue($c));
						// remove linear factory, then split the units separted by comma
						$unitString = trim(substr($valuetrimmed, stripos($valuetrimmed, " ")));
						$units = explode(",", $unitString);
						foreach($units as $u) {
							$correct_unit |= $v->getUnit() == trim($u);
						}
					}
					if (!$correct_unit) {
						$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARDISSUE_WRONG_UNIT, $s->getTitle(), $property, $v->getUnit());
					}
				}
			}
		}
	}

	/**
	 * Checks for missing parameter of annotations of n-ary properties
	 *
	 * @param array & $subjects which contains annotations of the given property
	 * @param $property n-ary property
	 */
	private function checkForMissingParams(array & $subjects, $property) {

		$hasTypeDV = SMWPropertyValue::makeProperty("_LIST");
		$type = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($property), 
			$hasTypeDV->getDataItem());
		
		if (count($type) == 0) return;
		
		$types = array();
		foreach(explode(';', $type[0]->getSortKey()) as $k => $t){
			if(array_key_exists($t, $types)){
				$types[$t]['count'] += 1;
			} else {
				$types[$t]['prop'] = SMWPropertyValue::makeProperty($t)->getDataItem(); 
				$types[$t]['count'] = 1;
			} 
		}		
		$propertyDataItem = SMWDIProperty::newFromUserLabel($property->getText());
		
		foreach($subjects as $subject) {
			$values = smwfGetStore()->getPropertyValues(SMWDIWikiPage::newFromTitle($subject), 
				$propertyDataItem);

			foreach($values as $v) {
				if ($v instanceof SMWDIContainer) { 

					foreach($types as $k => $t){
						$recordVals = $v->getSemanticData()->getPropertyValues($t['prop']);
						if(count($recordVals) != $t['count']){
							$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARD_ISSUE_MISSING_PARAM, $subject, $property, 1);
							break;
						} 
					}
				}
			}
		}
	}

	private function checkRange($domain_cov_results, $categoriesOfObject, $domainRange) {


		$result = false;

		if ($domain_cov_results === NULL) {
			// when domain is not correct
			for($domRanVal = reset($domainRange); $domRanVal !== false;$domRanVal = next($domainRange) ) {


				$rangeCorrect = false;
				
				$range = $domRanVal->getSemanticData()->getPropertyValues(
					SMWDIProperty::newFromUserLabel('Has range'));
				$rangeCat  = (count($range) > 0) ? $range[0]->getTitle() : NULL;


				if ($rangeCat == NULL) {
					$rangeCorrect = true;
				}
				if ($rangeCat != NULL) {
					// check range

					foreach($categoriesOfObject as $coo) {
						$rangeCorrect |= (GraphHelper::checkForPath($this->categoryGraph, $coo->getArticleID(), $rangeCat->getArticleID()));
						if ($rangeCorrect) break;
					}
				}

				$result |= $rangeCorrect;

			}
		} else {
			// if at least one domain is correct and $domain_cov_results is an array of boolean which indicates which domain are correct and which not.
			for($domRanVal = reset($domainRange), $dvr = reset($domain_cov_results); $domRanVal !== false && $dvr !== false;$dvr = next($domain_cov_results), $domRanVal = next($domainRange) ) {

				if ($domain_cov_results != NULL && !$dvr) {
					continue;
				}

				$rangeCorrect = false;
				
				$range = $domRanVal->getSemanticData()->getPropertyValues(
					SMWDIProperty::newFromUserLabel('Has range'));
				$rangeCat  = count($range) > 0 ? $range[0]->getTitle() : NULL;


				if ($rangeCat == NULL) {
					$rangeCorrect = true;
				}
				if ($rangeCat != NULL) {
					// check range

					foreach($categoriesOfObject as $coo) {
						$rangeCorrect |= (GraphHelper::checkForPath($this->categoryGraph, $coo->getArticleID(), $rangeCat->getArticleID()));
						if ($rangeCorrect) break;
					}
				}

				$result |= $rangeCorrect;

			}
		}
		return $result;
	}


	/**
	 * Checks weather subject matches a domain/range pair.
	 */
	private function checkDomain($categoriesOfSubject, $domainRange) {

		$results = array();
		$oneDomainCorrect = false;
		foreach($domainRange as $domRanVal) {
			$domainCorrect = false;

			$domain = $domRanVal->getSemanticData()->getPropertyValues(
				SMWDIProperty::newFromUserLabel('Has domain'));;
			$domainCat = count($domain) > 0 ? $domain[0]->getTitle() : NULL;

			if ($domainCat == NULL) {
				$domainCorrect = true;
			} else {
				//check domain

				foreach($categoriesOfSubject as $coi) {
					$domainCorrect |= (GraphHelper::checkForPath($this->categoryGraph, $coi->getArticleID(), $domainCat->getArticleID()));
					if ($domainCorrect) break;
				}
			}
			$results[] = $domainCorrect;
			$oneDomainCorrect |= $domainCorrect;
		}
		return array($results, $oneDomainCorrect);
	}

}

