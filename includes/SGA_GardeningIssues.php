<?php
/**
 * @file
 * @ingroup SemanticGardening
 * 
 * Created on 18.10.2007
 *
 * @author Kai K�hn
 * 
 * Provide access to Gardening issue table. Gardening issues can be categorized
 * by bot, type and class. A GardeningIssue is can be about one or two titles and an optional 
 * numeric or string value.  
 * 
 * 
 */
 

/* Ajax functions */
global $wgAjaxExportList;




define('SMW_GARDENINGLOG_SORTFORTITLE', 0);
define('SMW_GARDENINGLOG_SORTFORVALUE', 1);

/**
 * Abstract class to access GardeningIssue store.
 */
abstract class SGAGardeningIssuesAccess {

	static $gi_interface;
	/**
	 * Setups GardeningIssues table(s).
	 */
	public abstract function setup($verbose);


	/**
	 * Drops GardeningIssues table(s).
	 */
	public abstract function drop($verbose);
	/**
	 * Clear all Gardening issues
	 *
	 * @param $bot_id if not NULL, clear only GardeningIssues of this bot. Otherwise all.
	 * @param $t1 Clear only Gardening issues of this title.
	 */
	public abstract function clearGardeningIssues($bot_id = NULL, $gi_type = NULL, $gi_class = NULL,Title $t1 = NULL, Title $t2 = NULL);

	/**
	 * Detects if a GardeningIssue about an article does already exist.
	 *
	 * @param $bot_id
	 * @param $gi_type
	 * @param $gi_class
	 * @param $title1
	 * @param $title2
	 * @param $value
	 *
	 * @return True, if at least one Gardening Issue of the article exists, otherwise false.
	 */
	public abstract function existsGardeningIssue($bot_id = NULL, $gi_type = NULL, $gi_class = NULL, $title1 = NULL, $title2 = NULL, $value = NULL);

	/**
	 * Get Gardening issues. Every parameter (except $bot_id) may be NULL!
	 *
	 * @param $bot_id Bot-ID
	 * @param $gi_type type of issue. (Can be an array!)
	 * @param $gi_class type of class of issue. (Can be an array!)
	 * @param $titles Title1 issue is about. (Can be an array)
	 * @param $sortfor column to sort for. Default by title.
	 * 				One of the constants: SMW_GARDENINGLOG_SORTFORTITLE, SMW_GARDENINGLOG_SORTFORVALUE
	 * @param $options instance of SMWRequestOptions
	 *
	 * @return array of GardeningIssue objects
	 */
	public abstract function getGardeningIssues($bot_id = NULL, $gi_type = NULL, $gi_class = NULL, $titles = NULL,  $sortfor = NULL, $options = NULL);


	/**
	 * Get Gardening issues for a pair of titles. Every parameter (except $bot_id) may be NULL!
	 *
	 * @param $bot_id Bot-ID
	 * @param $gi_type type of issue. (Can be an array!)
	 * @param $gi_class type of class of issue.  (Can be an array!)
	 * @param $titles Pair (2-tuple) of Title objects the issue is about. (Must be an array of tuples)
	 * @param $sortfor column to sort for. Default by title.
	 * 				One of the constants: SMW_GARDENINGLOG_SORTFORTITLE, SMW_GARDENINGLOG_SORTFORVALUE
	 * @param $options instance of SMWRequestOptions
	 *
	 * @return array of GardeningIssue objects
	 */
	public abstract function getGardeningIssuesForPairs($bot_id = NULL, $gi_type = NULL, $gi_class = NULL, $titles = NULL,  $sortfor = NULL, $options = NULL);

	/**
	 * Get array of distinct titles having at least one Gardening issue.
	 * Every parameter may be NULL!
	 *
	 * @param $bot_id Bot-ID
	 * @param $gi_type type of issue. (Can be an array!)
	 * @param $gi_class type of class of issue.
	 * @param $sortfor column to sort for. Default by title.
	 * 				One of the constants: SMW_GARDENINGLOG_SORTFORTITLE, SMW_GARDENINGLOG_SORTFORVALUE
	 * @param $options instance of SMWRequestOptions
	 *
	 * @return array of titles
	 */
	public abstract function getDistinctTitles($bot_id = NULL, $gi_type = NULL, $gi_class = NULL, $sortfor = NULL, $options = NULL);

	/**
	 * Get array of distinct title pairs having at least one Gardening issue.
	 * Every parameter may be NULL!
	 *
	 * @param $bot_id Bot-ID
	 * @param $gi_type type of issue. (Can be an array!)
	 * @param $gi_class type of class of issue.
	 * @param $sortfor column to sort for. Default by title.
	 * 				One of the constants: SMW_GARDENINGLOG_SORTFORTITLE, SMW_GARDENINGLOG_SORTFORVALUE
	 * @param $options instance of SMWRequestOptions
	 *
	 * @return array of tuples (t1, t2)
	 */
	public abstract function getDistinctTitlePairs($bot_id = NULL, $gi_type = NULL, $gi_class = NULL, $sortfor = NULL, $options = NULL);
		
	/**
	 * Add Gardening issue about articles.
	 *
	 * @param $gi_type type of issue.
	 * @param $t1 Title issue is about.
	 * @param $t2 Title
	 * @param $value optional value. Depends on $gi_type
	 */
	public abstract function addGardeningIssueAboutArticles($bot_id, $gi_type, Title $t1, Title $t2, $value = NULL);

	/**
	 * Add Gardening issue about an article.
	 *
	 * @param $gi_type type of issue.
	 * @param $t1 Title issue is about.
	 */
	public abstract function addGardeningIssueAboutArticle($bot_id, $gi_type, Title $t1);

	/**
	 * Add Gardening issue about values.
	 *
	 * @param $gi_type type of issue.
	 * @param $t1 Title issue is about.
	 * @param $value Depends on $gi_type
	 */
	public abstract function addGardeningIssueAboutValue($bot_id, $gi_type, Title $t1, $value);

	/**
	 * Set modified flag for GardeningIssues of $t
	 *
	 * @param Title $t
	 */
	public abstract function setGardeningIssueToModified(Title $t);

	/**
	 * Generates propagated GardeningIssues to indicate problems up to the root level. Propagates
	 * all category and instance issues.
	 *
	 * @param $bot_id ID of bot whose issues should be propagated
	 * @param $propagationType gi_type of propagation issue.
	 */
	public abstract function generatePropagationIssuesForCategories($botID, $propagationType);

	public static function getGardeningIssuesAccess() {
		global $sgagIP;
		if (SGAGardeningIssuesAccess::$gi_interface == NULL) {

			require_once($sgagIP . '/includes/storage/SGA_GardeningIssuesSQL.php');
			require_once($sgagIP . '/includes/storage/SGA_GardeningIssuesSQL2.php');
			SGAGardeningIssuesAccess::$gi_interface = new SGAGardeningIssuesAccessSQL2();
			 
		}
		return SGAGardeningIssuesAccess::$gi_interface;
	}
}

/**
 * Simple record class to store a Gardening issue.
 *
 * @author kai
 */
abstract class GardeningIssue {

	protected $bot_id;
	protected $gi_type;
	protected $t1;
	protected $t2;
	protected $value;
	protected $isModified;


	protected function __construct($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified) {
		$this->bot_id = $bot_id;
		$this->gi_type = $gi_type;
		if ($t1_ns != -1 && $t1 != NULL && $t1 != '') {
			$this->t1 = Title::newFromText($t1, $t1_ns);
		} else {
			$this->t1 = NULL;
		}
		if ($t2_ns != -1 && $t2 != NULL && $t2 != '') {
			$this->t2 = Title::newFromText($t2, $t2_ns);
		} else {
			$this->t2 = NULL;
		}
		$this->value = $value;
		$this->isModified = $isModified;
	}

	/**
	 * Creates an issue depending of the $bot_id
	 *
	 * @return instance of subclass of GardeningIssue.
	 */
	public static function createIssue($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified) {
		global $registeredBots;
		$botclass = get_class($registeredBots[$bot_id]);
		if ($botclass == '' && is_string($registeredBots[$bot_id])) {
			// if botclass not registered
			$botclass = $registeredBots[$bot_id];
		}
		$issueClassName = $botclass."Issue";
		return new $issueClassName($bot_id, $gi_type, $t1_ns, $t1, $t2_ns, $t2, $value, $isModified);
	}


	public function getBotID() {
		return $this->bot_id;
	}

	public function getType() {
		return $this->gi_type;
	}

	public function getTitle1() {
		return $this->t1;
	}

	public function getTitle2() {
		return $this->t2;
	}

	public function getValue() {
		return $this->value;
	}

	public function isModified() {
		return $this->isModified;
	}

	/**
	 * Converts a semicolon separated list of Title strings
	 * to a comma separated list of displayable links.
	 *
	 * @param & $skin Current skin object.
	 * @param $value List of semicolon separated titles.
	 *
	 * @return comma separated list of displayable links (HTML string)
	 */
	protected function explodeTitlesToLinkObjs(& $skin, $value) {
		if ($value == NULL || $value == '') return "__undefined__";
		$titleNames = explode(';', $value);
		$result = "";
		foreach($titleNames as $tn) {
			$title = Title::newFromText($tn);
			if ($title != NULL) $result .= $skin->makeLinkObj($title).", ";
		}
		return substr($result, 0, strlen($result)-2);
	}


	public function getRepresentation(& $skin = NULL, $local = false) {
		// convert title1 to string and replace if it does not exist
		if ($this->t1 != NULL) {
			$text1 = "'".$this->t1->getText()."'";
		} else {
			$text1 = "__empty_title__"; // this is an error, if it is visible to the user.
		}
		// convert title2 to string and replace if it does not exist
		if ($this->t2 != NULL) {
			$text2 =  "'".$this->t2->getText()."'";
		} else {
			$text2 = "__empty_title__"; // this is an error, if it is visible to the user.
		}
		return ucfirst($this->getTextualRepresenation($skin, $text1, $text2, $local));
	}


	/**
	 * Returns textual representation of Gardening issue.
	 *
	 * @param & $skin reference to skin object to create links.
	 * @param $text1 textual representation of title1 or constant if title1 is invalid
	 * @param $text2 textual representation of title2 or constant if title2 is invalid
	 */
	protected abstract function getTextualRepresenation(& $skin, $text1, $text2, $local = false);

	/**
	 * Returns class of a given $type.
	 */
	public static function getClass($type) {
		return intval($type / 100);
	}
}

/**
 * Holds a set of Gardening issues and associate it with an
 * article or a pair of articles.
 */
class GardeningIssueContainer {

	// article or array of 2 articles
	private $bound;

	// array of Gardening issues
	private $gi;

	public function GardeningIssueContainer($bound, array & $gi) {
		$this->bound = $bound;
		$this->gi = $gi;
	}

	public function getBound() {
		return $this->bound;
	}

	public function getGardeningIssues() {
		return $this->gi;
	}
}
/**
 * Abstract class which defines an interface for a GardeningIssue Filter.
 */
abstract class GardeningIssueFilter {

	protected $base;
	protected $gi_issue_classes;


	protected function __construct($base) {
		$this->base = $base;
	}
	/**
	 * Returns array of strings representing the gardening issue classes.
	 * The index is used to access the issue class.
	 *
	 * @return array of strings
	 */
	public function getIssueClasses() {
		return $this->gi_issue_classes;
	}

	/**
	 * Returns filtering FORM.
	 *
	 * @param $specialAttPage special page title object
	 * @param $request Request object for accessing URL parameters
	 *
	 * @return HTML string of form.
	 */
	public function getFilterControls($specialAttPage, $request) {
		global $registeredBots;
		$html = "<form action=\"".$specialAttPage->getFullURL()."\">";
		$html .= '<input type="hidden" name="title" value="' . $specialAttPage->getPrefixedText() . '"/>';
		$html .= "<select name=\"bot\" onchange=\"gardeningLogPage.selectBot(event)\">";

		$sent_bot_id = $request->getVal('bot');
		foreach($registeredBots as $bot_id => $bot) {
			if ($sent_bot_id == $bot_id) {
				$html .= "<option value=\"".$bot->getBotID()."\" selected=\"selected\">".$bot->getLabel()."</option>";
			} else {
				$html .= "<option value=\"".$bot->getBotID()."\">".$bot->getLabel()."</option>";
			}

		}
		$html .= 	"</select>";
			
		// type of Gardening issue
		$type = $request->getVal('class');
		$html .= "<span id=\"issueClasses\"><select name=\"class\">";
		$i = 0;
		foreach($this->getIssueClasses() as $class) {
			if ($i == $type) {
				$html .= "<option value=\"$i\" selected=\"selected\">$class</option>";
			} else {
				$html .= "<option value=\"$i\">$class</option>";
			}
			$i++;
		}
		$html .= 	"</select>";
			
		$html .= $this->getUserFilterControls($specialAttPage, $request);
		$html .= "</span>";
		$html .= "<input type=\"submit\" value=\" Go \">";
		$html .= "</form>";
		return $html;
	}

	/**
	 * Returns associative array of additional parameters to link.
	 *
	 * @param array(parameter => value)
	 */
	public function linkUserParameters(& $wgRequest) {
		return array();
	}


	/**
	 * Returns user-defined filtering elements.
	 *
	 * @param $specialAttPage special page title object. May be NULL (ajax)
	 * @param $request Request object for accessing URL parameters. May be NULL (ajax)
	 *
	 * @return HTML string of form elements
	 */
	public abstract function getUserFilterControls($specialAttPage, $request);

	/**
	 * Returns GardeningIssue objects.
	 *
	 * @param $options SMWRequestOptions object.
	 * @param $request Request object for accessing URL parameters.
	 *
	 * @return array of GardeningIssue objects.
	 */
	public function getData($options, $request) {
		$bot = $request->getVal('bot');
		if ($bot == NULL) $bot = 'smw_consistencybot'; // set ConsistencyBot as default

		$gi_class = $request->getVal('class') == 0 ? NULL : $request->getVal('class') + $this->base - 1;


		$gi_store = SGAGardeningIssuesAccess::getGardeningIssuesAccess();

		$gic = array();

		$titles = $gi_store->getDistinctTitles($bot, NULL, $gi_class, SMW_GARDENINGLOG_SORTFORTITLE, $options);
		foreach($titles as $t) {
			$gis = $gi_store->getGardeningIssues($bot, NULL, $gi_class, $t, SMW_GARDENINGLOG_SORTFORTITLE, NULL);
			$gic[] = new GardeningIssueContainer($t, $gis);
		}

		return $gic;
	}


}



