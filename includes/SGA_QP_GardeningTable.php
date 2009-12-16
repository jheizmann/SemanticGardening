<?php
/**
 * Print query results in tables.
 * @author Markus Krötzsch
 * @author Ontoprise
 */

/**
 * New implementation of SMW's printer for result tables, extended with
 * certain gardening features supplied by the Halo extension.
 *
 * @note AUTOLOADED
 */
class SGAGardeningTableResultPrinter extends SMWResultPrinter {
	
// supported parameters
    protected $mParameters;
   
    public function __construct($format, $inline) {
        parent::__construct($format, $inline);
      
        $order = new SMWQPParameter('order', 'Order', array('ascending','descending'), NULL, "Sort order");
        $link = new SMWQPParameter('link', 'Link', array('all','subject', 'none'), NULL, "Show everything as link, only subjects or nothing at all.");
        $limit = new SMWQPParameter('limit', 'Limit', '<number>', NULL, "Instance display limit");
        $headers = new SMWQPParameter('headers', 'Headers', array('show', 'hide'), NULL, "Show headers or not.");
        $intro = new SMWQPParameter('intro', 'Intro', '<string>', NULL, "Intro text");
        $mainlabel = new SMWQPParameter('mainlabel', 'Mainlabel', '<string>', NULL, "Name of main column");
        $default = new SMWQPParameter('default', 'Default text', '<string>', NULL, "Displayed when there are no results at all.");
         
        $this->mParameters = array($order, $link, $limit, $headers, $intro, $mainlabel, $default);
    }
    
    function getSupportedParameters() {
        return $this->mParameters;
    }
    
	
	protected function getResultText($res, $outputmode) {
		global $smwgIQRunningNumber;
		SMWOutputs::requireHeadItem(SMW_HEADER_SORTTABLE);

		
		global $sgagIP;
		
		// import ConsistencyIssue class and make sure ConsistencyIssue class can be instantiated
		require_once( $sgagIP . "/includes/SGA_GardeningIssues.php");
		require_once( $sgagIP . "/includes/bots/consistency_bot/SGA_ConsistencyIssue.php");
	    global $registeredBots;
	    $registeredBots['smw_consistencybot'] = 'ConsistencyBot';
	    
        wfGAInitUserMessages();
		$cols = array(); //Names of columns
		$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();

		// print header
		if ('broadtable' == $this->mFormat)
		$widthpara = ' width="100%"';
		else $widthpara = '';
		$result = "<table class=\"smwtable\"$widthpara id=\"querytable" . $smwgIQRunningNumber . "\">\n";
		if ($this->mShowHeaders) { // building headers
			$result .= "\t<tr>\n";
			foreach ($res->getPrintRequests() as $pr) {
				$title = $pr->getTitle();
				if($title instanceof Title)
				array_push($cols, $title);
				else
				array_push($cols, "");
				$result .= "\t\t<th>" . $pr->getText($outputmode, $this->mLinker) . "</th>\n";
			}
			$result .= "\t</tr>\n";
		} else {
			foreach ($res->getPrintRequests() as $pr) {
				$title = $pr->getTitle();
				if($title instanceof Title)
				array_push($cols, $title);
				else
				array_push($cols, "");
			}
		}

		// print all result rows
		while ( $row = $res->getNext() ) {
			$result .= "\t<tr>\n";
			$firstcol = true;
			$gIssues = null;
			$act_column = 0;
			foreach ($row as $field) {
				$result .= "\t\t<td>";
				$first = true;
				$tt = '';
				if($gIssues != null && $outputmode == SMW_OUTPUT_HTML){
					for($j = 0; $j<sizeof($gIssues); $j++){ //check if there's a GI for this property / article combination
						if($act_column > 0 && $cols[$act_column] instanceof Title && ($gIssues[$j]->getTitle2()->getArticleID() == $cols[$act_column]->getArticleID())){
							if($gIssues[$j]->getType() == SMW_GARDISSUE_TOO_LOW_CARD)
							$tt = '<a title="' . wfMsg("qbedit") . ' ' . $gIssues[$j]->getTitle1()->getText() . '" class="gardeningissue" href="' . $gIssues[$j]->getTitle1()->getEditURL() . '" target="_new">' . wfMsg('smw_iqgi_missing') . '</a>';
							else if($gIssues[$j]->getType() == SMW_GARDISSUE_WRONG_UNIT)
							$tt = '&nbsp;<a title="' . wfMsg("qbedit") . ' ' . $gIssues[$j]->getTitle1()->getText() . '" class="gardeningissue_notify" href="' . $gIssues[$j]->getTitle1()->getEditURL() . '" target="_new">(' . wfMsg('smw_iqgi_wrongunit') . ')</a>';
							else
							$tt = smwfEncodeMessages(array($gIssues[$j]->getRepresentation()));
						}
					}
				}
				while ( ($object = $field->getNextObject()) !== false ) {
					if ($firstcol && $object instanceof SMWWikiPageValue) { //save gardening issues for the article of the current row
						$gIssues = $gi_store->getGardeningIssues("smw_consistencybot", NULL, SMW_CONSISTENCY_BOT_BASE + 3, $object->getTitle(), NULL, NULL);
					}
					if ($object->getTypeID() == '_wpg') { // use shorter "LongText" for wikipage
						$text = $object->getLongText($outputmode,$this->getLinker($firstcol));
					} else {
						$text = $object->getShortText($outputmode,$this->getLinker($firstcol));
					}
					if ($first) {
						if ($object->isNumeric()) { // use numeric sortkey
							$result .= '<span class="smwsortkey">' . $object->getNumericValue() . '</span>';
						}
						$first = false;
					} else {
						$result .= '<br />';
					}
					if ($object instanceof SMWWikiPageValue){
						$text .= $this->addTooltip($object->getTitle());
					}
					$result .= $text;
				}
				$result .= $tt;
				$result .= "</td>\n";
				$firstcol = false;
				$act_column ++;
			}
			$result .= "\t</tr>\n";
		}

		// print further results footer
		if ( $this->linkFurtherResults($res) ) {
			$link = $res->getQueryLink();
			if ($this->getSearchLabel($outputmode)) {
				$link->setCaption($this->getSearchLabel($outputmode));
			}
			$result .= "\t<tr class=\"smwfooter\"><td class=\"sortbottom\" colspan=\"" . $res->getColumnCount() . '"> ' . $link->getText($outputmode,$this->mLinker) . "</td></tr>\n";
		}
		$result .= "</table>\n"; // print footer
		$this->isHTML = ($outputmode == SMW_OUTPUT_HTML); // yes, our code can be viewed as HTML if requested, no more parsing needed
		return $result;
	}

	protected function addTooltip($title){
		$tt = '';
		
		if($title instanceof Title){
			$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();
			$gIssues = $gi_store->getGardeningIssues("smw_consistencybot", NULL, NULL, $title, NULL, NULL);
			$messages = array();
			for($j = 0; $j<sizeof($gIssues); $j++){
				if($gIssues[$j]->getRepresentation() != wfMsg('smw_gard_issue_contains_further_problems'))
				array_push($messages, '<ul><li>' . $gIssues[$j]->getRepresentation() . '</li></ul>');
			}
			if(count($messages)>0){
				$messages = array_unique($messages);
				$tt = smwfEncodeMessages($messages);
			}
		}
		return $tt;
	}
}
