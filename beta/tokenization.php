<?php 
class Tokenizer {
	//TODO angelegt_von, angelegt_am
	private $levels;
	private $replacement_rules = [];
	private $escape_rules = [];
	private $copy_rules = [];
	
	private $postProcessFunctions = [];
	private $preProcessFunctions = [];
	
	private $ignore_areas_escape = [];
	private $ignore_areas_copy = [];
	
	private $data = [];
	
	const DEBUG = false;
	
	public function __construct ($levels){
		foreach ($levels as &$level){
			if(!is_array($level)){
				$level = [$level];
			}
			else { //Longest separators first, in case they are starting with the same characters
				usort($level, function ($val1, $val2){
					count($val1) < count($val2);
				});
			}
		}
		
		$this->levels = $levels;
	}
	
	public function tokenize ($record, $extraData = []){
		
		$record = $this->preprocess($record, $extraData);
		
		$tokens = [['token' => $record, 'offset' => 0, 'indexes' => []]];
		foreach ($this->levels as $num => $level){
			$newTokens = [];
			foreach ($tokens as $token){
				$newParts = $this->customExplode($level, $token['token'], $token['offset'], $num == count($this->levels) - 1);
				foreach ($newParts as $index => $newPart){
					$tokenNew = trim($newPart[0]);
					if (!$tokenNew){
						$this->error('Record contains empty token: ' . $record);
					}
					
					$offset = $token['offset'] + $newPart[1] + strpos($newPart[0], $tokenNew);
					
					$newData = [
							'token' => $tokenNew, 
							'offset' => $offset, 
							'indexes' => array_merge($token['indexes'], [$index]), 
							'delimiter' => ($newPart[2]? $newPart[2]: (isset($token['delimiter'])? $token['delimiter']: NULL))
					];
					if(isset($newPart[3])){
						$newData['cfields'] = $newPart[3];
					}
					
					$newTokens[] = $newData;
				}
			}
			$tokens = $newTokens;
		}
		
		list($tokens, $global) = $this->postProcess($tokens, $extraData);
		
		return ['global' => $global, 'tokens' => $tokens];
	}
	
	private function preprocess ($record, $extraData){
		
		if (self::DEBUG){
			echo 'Record "' . htmlentities($record) . '"<br />';
		}
		
		foreach ($this->preProcessFunctions as $preFun){
			$record = $preFun($record, $extraData);
		}
		
		if (self::DEBUG){
			echo 'After preprocess functions: "' . htmlentities($record) . '"<br />';
		}
		
		$this->ignore_areas_copy = [];
		$this->ignore_areas_escape = [];
		
		$record = trim($record);
		$record = str_replace("\xC2\xA0", ' ', $record); //Non breaking spaces
		$record = preg_replace('/\s+/', ' ', $record);
		
		foreach ($this->replacement_rules as $replaceArray){
			$conditionFun = $replaceArray['condition'];
			if($conditionFun === false || $conditionFun($record, $extraData)){
				if($replaceArray['isRegex']){
					$record = preg_replace($replaceArray['search'], $replaceArray['replace'], $record);
				}
				else {
					$record = str_replace($replaceArray['search'], $replaceArray['replace'], $record);
				}
			}
		}
		
		if (self::DEBUG){
			echo 'After replacements: "' . htmlentities($record) . '"<br>';
		}
		
		//Use merged intervals for escaping
		$matches = [];
		foreach ($this->escape_rules as $escape_rule){
			$conditionFun = $escape_rule['condition'];
			if($conditionFun === false || $conditionFun($record, $extraData)){
				preg_match_all($escape_rule['search'], $record, $matches, PREG_OFFSET_CAPTURE);
				foreach ($matches[0] as $match){
					$this->ignore_areas_escape = va_add_interval($this->ignore_areas_escape, [$match[1], $match[1] + strlen($match[0])]);
				}
			}
		}
		
		//Use just list of (ordered) intervals for copying (if intervals overlap an error is thrown)
		foreach ($this->copy_rules as $copyData){
			$conditionFun = $copyData['condition'];
			if($conditionFun === false || $conditionFun($record, $extraData)){
				preg_match_all($copyData['search'], $record, $matches, PREG_OFFSET_CAPTURE);
				foreach ($matches[0] as $match){
					$this->ignore_areas_copy[] =  [$match[1], $match[1] + strlen($match[0]), $copyData['field'], $copyData['separator'], $copyData['edit']];
				}
			}
		}
		
		usort($this->ignore_areas_copy, function ($e1, $e2){
			return $e1[0] > $e2[0];
		});
		
		for ($i = 1; $i < count($this->ignore_areas_copy); $i++){
			$lastInterval = $this->ignore_areas_copy[$i - 1];
			$currentInterval = $this->ignore_areas_copy[$i];
			
			if($lastInterval[1] > $currentInterval[0]){
				throw new Exception('Overlapping intervals for copy rules!');
			}
		}
		
		if (self::DEBUG){
			echo 'Copy areas: ' . va_add_marking_spans($record, $this->ignore_areas_copy) . '<br>';
			echo 'Escape areas: ' . va_add_marking_spans($record, $this->ignore_areas_escape) . '<br>';
		}
		
		return $record;
	}
	
	public function addPreProcessFunction ($fun){
		$this->preProcessFunctions[] = $fun;
	}
	
	public function addPostProcessFunction ($fun){
		$this->postProcessFunctions[] = $fun;
	}
	
	private function postProcess ($tokens, $extraData){
			
		foreach ($tokens as &$token){
			foreach ($this->escape_rules as $escape_rule){
				if($escape_rule['replace'] !== false){
					$token['token'] = preg_replace($escape_rule['search'], $escape_rule['replace'], $token['token']);
				}
			}
		}
		
		$global = ['warnings' => []];
		
		foreach ($this->postProcessFunctions as $fun){
			list($tokens, $global) = $fun($this, $tokens, $global, $extraData);
		}
		return [$tokens, $global];
	}
	
	private function customExplode ($delimiters, $str, $offset, $lastLevel){
		$res = [];
		$offsetIncrease = 0;
				
		$chrArray = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
		
		$currEscapeInterval = NULL;
		if(count($this->ignore_areas_escape) == 0){
			$currEscapeIntervalIndex = false;
		}
		else {
			$currEscapeIntervalIndex = 0;
			$currEscapeInterval = $this->ignore_areas_escape[0];
		}
		
		$currCopyInterval = NULL;
		if(count($this->ignore_areas_copy) == 0){
			$currCopyIntervalIndex = false;
		}
		else {
			$currCopyIntervalIndex = 0;
			$currCopyInterval = $this->ignore_areas_copy[0];
		}
		
		$currentToken = '';
		$currentOffset = 0;
		$currentCField = NULL;
		$currentCFieldContent = '';
		$cFields = [];
		$skipChar = 0;
		
		if(self::DEBUG){
			echo '<b>Start splitting: "' . htmlentities($str) . '" for delimiters [' . htmlentities(implode(',', va_surround($delimiters, '"'))) . ']</b><br />';
			echo 'Start offset: ' . $offset . '<br /><br />';
		}
		
		foreach ($chrArray as $index => $char){
			if(self::DEBUG){
				echo 'Character "' . $char . '"<br />';
			}
			
			if($skipChar > 0){
				$skipChar--;
				if(self::DEBUG){
					echo 'Skip<br />';
				}
				continue;
			}
			
			$recordIndex = $index + $offset;
			if(self::DEBUG){
				echo 'Record Index:  "' . $recordIndex . '"<br />';
			}
			
			if(!mb_check_encoding($char, 'ASCII')){
				$offset++;
				$offsetIncrease++;
				if (self::DEBUG){
					echo 'Non ASCII char => increase offset <br />';
				}
			}
			
			if($currEscapeIntervalIndex !== false && $recordIndex >= $currEscapeInterval[1]){
				if(count($this->ignore_areas_escape) > $currEscapeIntervalIndex + 1){
					$currEscapeInterval = $this->ignore_areas_escape[++$currEscapeIntervalIndex];
				}
				else {
					$currEscapeIntervalIndex = false;
				}
			}
			
			if($currCopyIntervalIndex !== false && $recordIndex >= $currCopyInterval[1]){
				if($currentCFieldContent){
					$this->appendToCField($currentCFieldContent, $currentCField, $currCopyInterval, $cFields);
				}
				
				$currentCField = NULL;
				$currentCFieldContent = '';
				
				if(count($this->ignore_areas_copy) > $currCopyIntervalIndex + 1){
					$currCopyInterval = $this->ignore_areas_copy[++$currCopyIntervalIndex];
				}
				else {
					$currCopyIntervalIndex = false;
				}
			}

			//Escaping has priority over copying
			if($currEscapeIntervalIndex !== false && $recordIndex >= $currEscapeInterval[0] && $recordIndex < $currEscapeInterval[1]){
				$currentToken .= $char;
				if (self::DEBUG){
					echo 'Char in escape interval<br />';
				}
				continue;
			}
			//Copying
			else if($currCopyIntervalIndex !== false && $recordIndex >= $currCopyInterval[0] && $recordIndex < $currCopyInterval[1]){
				if($lastLevel){
					if($currentCField === NULL){
						$currentCField = $currCopyInterval[2];
					}
	
					$currentCFieldContent .= $char;
					if (self::DEBUG){
						echo 'Char in copy interval<br />';
					}
				}
				else {
					$currentToken .= $char;
				}
				continue;
			}
			
			//Look for delimiters
			$delimiterFound = NULL;
			foreach ($delimiters as $delimiter){
				$isDelimiter = true;
				foreach (str_split($delimiter) as $num_del_char => $del_char){
					if(count($chrArray) <= $index + $num_del_char || $chrArray[$index + $num_del_char] != $del_char){
						$isDelimiter = false;
						break;
					}
				}
				if($isDelimiter){
					$delimiterFound = $delimiter;
					break;
				}
			}
			
			if ($delimiterFound !== NULL){
				$res[] = [$currentToken, $currentOffset, $delimiterFound, $cFields];
				$currentToken = '';
				$currentOffset = $index + $offsetIncrease + 1;
				$cFields = [];
				
				if(strlen($delimiterFound) > 1){
					$skipChar = strlen($delimiterFound) - 1;
				}
			}
			else {
				$currentToken .= $char;
			}
		}
		
		if($currentCFieldContent){
			$this->appendToCField($currentCFieldContent, $currentCField, $currCopyInterval, $cFields);
		}
		
		$res[] = [$currentToken, $currentOffset, NULL, $cFields];
		
		return $res;
	}
	
	private function appendToCField ($text, $field, $copyInterval, &$fields){
		if(is_callable($copyInterval[4])){
			$savedContent = $copyInterval[4]($text, $this);
		}
		else {
			$savedContent = $text;
		}
		
		if (array_key_exists($field, $fields)){
			$fields[$field] .= $copyInterval[3] . trim($savedContent);
		}
		else {
			$fields[$field] = trim($savedContent);
		}
	}
	
	public function addReplacementString ($search, $replace, $conditionFun = false){
		$this->replacement_rules[] = ['search' => $search, 'replace' => $replace, 'isRegex' => false, 'condition' => $conditionFun];
	}
	
	public function addReplacementRegex ($search, $replace, $conditionFun = false){
		$this->replacement_rules[] = ['search' => $search, 'replace' => $replace, 'isRegex' => true, 'condition' => $conditionFun];
	}
	
	public function addEscapeRegex ($regexp, $repl = false, $conditionFun = false){
		$this->escape_rules[] = ['search' => $regexp, 'replace' => $repl, 'condition' => $conditionFun];
	}
	
	public function addCopyRegex ($regexp, $fieldName, $seperator, $editFun, $conditionFun = false){
		$this->copy_rules[] = ['search' => $regexp, 'field' => $fieldName, 'separator' => $seperator, 'edit' => $editFun, 'condition' => $conditionFun];
	}
	
	public function addData ($name, $data){
		$this->data[$name] = $data;
	}
	
	public function getData ($name){
		return $this->data[$name];
	}
	
	public function error ($msg){
		throw new TokenizerException($msg);
	}
}

class TokenizerException extends Exception {
	public function __construct($msg){
		parent::__construct($msg);
	}
	
	public function __toString (){
		return $this->getMessage();
	}
}
?>