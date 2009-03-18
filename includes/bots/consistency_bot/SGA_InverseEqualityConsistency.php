<?php
/*
 * Created on 29.05.2007
 *
 * Author: kai
 */
 
 class InverseEqualityConsistency {
 	
 	
 	private $bot;
 	private $delay;
 	private $gi_store;
 	private $cc_store;
 	
 	public function InverseEqualityConsistency(& $bot, $delay) {
 		$this->bot = $bot;
 		$this->delay = $delay;
 		$this->gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
 		$this->cc_store = ConsitencyBotStorage::getConsistencyStorage();
 	}
 	
 	
 	public function checkInverseRelations() {
 		 		
 		print "\n";
 		$inverseRelations = $this->cc_store->getInverseRelations();
 		$totalWork = count($inverseRelations);
 		$this->bot->addSubTask($totalWork);
 		
 		foreach($inverseRelations as $r) {
 			if ($this->delay > 0) {
 				if ($this->bot->isAborted()) break;
 				usleep($this->delay);
 			}
 			
 			$this->bot->worked(1);
 			$workDone = $this->bot->getCurrentWorkDone();
 			if ($workDone % 10 == 1 || $workDone == $totalWork) GardeningBot::printProgress($workDone/$totalWork);
 			
 			
 			list($s, $t) = $r;
 			$domainAndRangeOfSource = smwfGetStore()->getPropertyValues($s, smwfGetSemanticStore()->domainRangeHintProp);
 			$domainAndRangeOfTarget = smwfGetStore()->getPropertyValues($t, smwfGetSemanticStore()->domainRangeHintProp);
 			
 			if (count($domainAndRangeOfSource) == 0) {
 				continue;
 			}
 			if (count($domainAndRangeOfTarget) == 0) {
 				continue;
 			}
 		 	
 		 	$dv_source = $domainAndRangeOfSource[0]->getDVs();
 		 	$dv_target = $domainAndRangeOfTarget[0]->getDVs();
 		 	
 		 	if (count($dv_source) > 0 && count($dv_target) > 1 && $dv_source[0] != NULL && $dv_target[1] != NULL) {
 		 		if (!$dv_source[0]->getTitle()->equals($dv_target[1]->getTitle())) {
 			
 					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARD_ISSUE_DOMAIN_NOT_RANGE, $s, $t);
 				
 				} 
 		 	}
 		 	
 		 	if (count($dv_source) > 1 && count($dv_target) > 0 && $dv_source[1] != NULL && $dv_target[0] != NULL) {
 		 		 if (!$dv_source[1]->getTitle()->equals($dv_target[0]->getTitle())) {
 				
 					$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARD_ISSUE_DOMAIN_NOT_RANGE, $t, $s);
 					
 				}
 		 	}  
 		 	
 			
 			
 			
 		}
 		
 	}
 	
 	public function checkEqualToRelations() {
 		$equalToRelations = $this->cc_store->getEqualToRelations();
 		$hasTypeDV = SMWPropertyValue::makeProperty("_TYPE");
 		$this->bot->addSubTask(count($equalToRelations));
 		foreach($equalToRelations as $r) {
 			$this->bot->worked(1);
 			list($s, $t) = $r;
 			if ($s->getNamespace() != $t->getNamespace()) {
 				// equality of incompatible entities
 				$this->gi_store->addGardeningIssueAboutArticles($this->bot->getBotID(), SMW_GARD_ISSUE_INCOMPATIBLE_ENTITY, $s, $t);
 				
 				continue;
 			} 
 			
 			
 		}
 		return '';
 	}
 	
 	
 	
 	
 }
?>
