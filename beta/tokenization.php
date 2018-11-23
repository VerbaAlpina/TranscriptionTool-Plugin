<?php 
class Tokenizer {
	//TODO angelegt_von, angelegt_am
	private $levels;
	private $replacement_rules = [];
	private $escape_rules = [];
	private $copy_rules = [];
	
	private $postProcessFunctions = [];
	
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
		
		$this->ignore_areas_copy = [];
		$this->ignore_areas_escape = [];
		
		$record = trim($record);
		$record = preg_replace('/ +/', ' ', $record);
		
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
			$savedContent = $copyInterval[4]($text);
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

function va_add_original_and_ipa ($tokenizer, $tokens, $global, $extraData){
	$parser = $tokenizer->getData('beta_parser');
	
	$isConcept = function ($token, $concept){
		return isset($token['Konzepte']) && count($token['Konzepte']) == 1 && $token['Konzepte'][0] == $concept;
	};
	
	foreach ($tokens as &$token){
		if ($token['Token'] && $parser && !$isConcept($token, 779)){ //TODO add more generic support for special concepts
			$chars = $parser->split_chars($token['Token']);
			if($chars == false){
				$tokenizer->error('Record not valid: ' . $token['Token']);
			}
			
			$res = $parser->convert_to_ipa($chars);
			if($res['string']){
				$token['IPA'] = $res['string'];
			}
			else {
				$token['IPA'] = '';
				foreach ($res['output'] as $missing){
					if (!in_array($missing[1], $global['warnings'])){
						$global['warnings'][] = $missing[1];
					}
				}
			}

			$res = $parser->convert_to_original($chars);
			if($res['string']){
				$token['Original'] = $res['string'];
			}
			else {
				$token['Original'] = '';

				if($res['output'][0][0] != 'error'){ //Ignore errors, cause some sources cannot or doesn't need to be translated
					foreach ($res['output'] as $missing){
						if (!in_array($missing[1], $global['warnings'])){
							$global['warnings'][] = $missing[1];
						}
					}
				}
			}
		}
		else {
			$token['IPA'] = '';
			$token['Original'] = '';
		}
	}
	
	return [$tokens, $global];
}

function va_tokenize_handle_groups_and_concepts ($tokenizer, $tokens, $global, $extraData){

	if(empty($extraData['concepts'])){
		throw new TokenizerException('No concepts given!');
	}
	
	$global['groups'] = [];
	
	$articles = $tokenizer->getData('articles');
	$schars = $tokenizer->getData('schars');
	
	//Split into groups
	$groups = [];
	$current_index = 0;
	foreach ($tokens as $token){
		if($token['Ebene_3'] == 1){
			$current_index++;
			$groups[$current_index] = [$token];
		}
		else {
			$groups[$current_index][] = $token;
		}
	}
	
	$result = [];
	
	$isConcept = function ($token, $concept){
		return isset($token['Konzepte']) && count($token['Konzepte']) == 1 && $token['Konzepte'][0] == $concept;
	};
	
	//Handle groups
	foreach ($groups as &$group){
		$len = count($group);
		
		if($len == 1){ //One Token
			$group[0]['Id_Tokengruppe'] = NULL;
			if (in_array($token['Token'], $schars)){
				$group[0]['Konzepte'] = [779];
			}
			else {
				$group[0]['Konzepte'] = $extraData['concepts'];
			}
		}
		else {
			$group_gender_from_article = '';
			//Mark articles and special characters
			foreach ($group as $index => $token){
				if(array_key_exists($token['Token'], $articles) && ($articles[$token['Token']][1] == '' || strpos($extraData['lang'], $articles[$token['Token']][1]) !== false)){
					$group[$index]['Konzepte'] = [699];
					$group[$index]['Genus'] = $articles[$token['Token']][0];
					
					if($index == 0 || ($index == 1 && $isConcept($group[$index-1], 779))){
						$group_gender_from_article = $articles[$token['Token']][0];
					}
				}
				else if (in_array($token['Token'], $schars)){
					$group[$index]['Konzepte'] = [779];
				}
			}
			
			//Article + token => no group
			if($len == 2 && $isConcept($group[0], 699)){
				$group[0]['Id_Tokengruppe'] = NULL;
				$group[1]['Id_Tokengruppe'] = NULL;
				$group[1]['Konzepte'] = $extraData['concepts'];
				
				if($group[1]['Genus'] == ''){
					$group[1]['Genus'] = $group_gender_from_article;
				}
			}
			//special char + token => no group
			else if ($len == 2 && $isConcept($group[0], 779)){
				$group[0]['Id_Tokengruppe'] = NULL;
				$group[1]['Id_Tokengruppe'] = NULL;
				$group[1]['Konzepte'] = $extraData['concepts'];
			}
			//special char + token + special char => no group
			else if ($len == 3 && $isConcept($group[0], 779) && $isConcept($group[2], 779)){
				$group[0]['Id_Tokengruppe'] = NULL;
				$group[1]['Id_Tokengruppe'] = NULL;
				$group[2]['Id_Tokengruppe'] = NULL;
				$group[1]['Konzepte'] = $extraData['concepts'];
			
				//Move notes to "real" token
				$group[1]['Bemerkung'] = $group[2]['Bemerkung'];
				$group[2]['Bemerkung'] = '';
			}
			//Article + token + special char => no group
			else if ($len == 3 && $isConcept($group[0], 699) && $isConcept($group[2], 779)){
				$group[0]['Id_Tokengruppe'] = NULL;
				$group[1]['Id_Tokengruppe'] = NULL;
				$group[1]['Konzepte'] = $extraData['concepts'];
				$group[2]['Id_Tokengruppe'] = NULL;
				
				if($group[1]['Genus'] == ''){
					$group[1]['Genus'] = $group_gender_from_article;
				}
				
				//Move notes to "real" token
				$group[1]['Bemerkung'] = $group[2]['Bemerkung'];
				$group[2]['Bemerkung'] = '';
			}
			//Special char + article + token => no group
			else if ($len == 3 && $isConcept($group[1], 699) && $isConcept($group[0], 779)){
				$group[0]['Id_Tokengruppe'] = NULL;
				$group[1]['Id_Tokengruppe'] = NULL;
				$group[2]['Konzepte'] = $extraData['concepts'];
				$group[2]['Id_Tokengruppe'] = NULL;
				
				if($group[2]['Genus'] == ''){
					$group[2]['Genus'] = $group_gender_from_article;
				}
			}
			//Group
			else {
				$indexGroup = count($global['groups']);
				$group_gender = '';
				$group_notes = $group[$len - 1]['Bemerkung'];
				$group[$len - 1]['Bemerkung'] = '';
				
				if($group[$len - 1]['Genus'] == ''){
					$group_gender = $group_gender_from_article;
				}
				else {
					$group_gender = $group[$len - 1]['Genus'];
				}
				
				$global['groups'][] = ['Genus' => $group_gender, 'Bemerkung' => $group_notes, 'Konzepte' => $extraData['concepts'], 'MTyp' => NULL, 'PTyp' => NULL];
				
				foreach ($group as $index => $token){
					$group[$index]['Id_Tokengruppe'] = 'NEW' . $indexGroup;
					if(!array_key_exists('Konzepte', $group[$index])){
						$group[$index]['Konzepte'] = [];
					}
					if(!$isConcept($group[$index], 699)){
						$group[$index]['Genus'] = '';
					}
					$group[$index]['Bemerkung'] = '';
				}
			}
		}
		
		foreach ($group as $token){
			$result[] = $token;
		}
	}
	
	return [$result, $global];
}

function va_tokenize_to_db_cols ($tokenizer, $tokens, $global, $extraData){
	$result = [];
	
	foreach ($tokens as $token){
		$newToken = [];
		
		$newToken['Token'] = $token['token'];
		
		//Set spaces for token groups
		if($token['delimiter'] == ';' || $token['delimiter'] == ',' || $token['delimiter'] === NULL){
			$newToken['Trennzeichen'] = NULL;
			$newToken['Trennzeichen_Original'] = NULL;
			$newToken['Trennzeichen_IPA'] = NULL;
		}
		else {
			$newToken['Trennzeichen'] = $token['delimiter'];
			
			$parser = $tokenizer->getData('beta_parser');
			
			if($parser){
				$space_ipa = $parser->convert_space_to_ipa($token['delimiter']);
				if($space_ipa !== false){
					$newToken['Trennzeichen_IPA'] = $space_ipa;
				}
				else {
					$newToken['Trennzeichen_IPA'] = '';
				}
				
				$space_org = $parser->convert_space_to_original($token['delimiter']);
				if($space_org !== false){
					$newToken['Trennzeichen_Original'] = $space_org;
				}
				else {
					$newToken['Trennzeichen_Original'] = NULL;
				}
			}
			else {
				$newToken['Trennzeichen_IPA'] = NULL;
				$newToken['Trennzeichen_Original'] = NULL;
			}
		}
		
		//Set token indexes
		foreach ($token['indexes'] as $index => $num){
			$newToken['Ebene_' . ($index + 1)] = $num + 1;
		}

		//Set notes
		$notesList = [];
		if($extraData['notes'] && $newToken['Trennzeichen'] === NULL){
			$notesList[] = $extraData['notes'];
		}
		if(isset($token['cfields']['notes'])){
			$notesList[] = $token['cfields']['notes'];
		}
		$newToken['Bemerkung'] = implode(' ', $notesList);
		
		$result[] = $newToken;
	}
	return [$result, $global];
}

function va_tokenize_handle_source_types ($tokenizer, $tokens, $global, $extraData){
	global $va_xxx;
	
	$global['mtypes'] = [];
	$global['ptypes'] = [];
	
	$currentGroupTypesBeta = [];
	$currentGroupTypesOrg = [];
	$indexGroup = 0;
	
	if($extraData['class'] != 'B'){
		$parser = $tokenizer->getData('beta_parser');
		
		foreach ($tokens as $index => &$token){
			$gender = $token['Genus'];
			$parsed = $parser->convert_to_original($token['Token'], 'UPPERCASE');
			if(!$parsed['string']){
				if($parsed['output'][0][0] == 'error'){
					$tokenizer->error($parsed['output'][0][1]);
				}
				else {
					foreach ($parsed['output'] as $warning){
						if(!in_array($warning[1], $global['warnings'])){
							$global['warnings'][] = $warning[1];
						}
					}
				}
			}
			else {
				$containsHtml = $parsed['string'] != strip_tags($parsed['string']);
			}

			if($extraData['class'] == 'M'){
				$type_id = $va_xxx->get_var($va_xxx->prepare('SELECT Id_morph_Typ FROM morph_Typen WHERE Beta = %s AND Genus = %s AND Quelle = %s', $token['Token'], $gender, $tokenizer->getData('source')));
				if($type_id){
					$token['MTyp'] = intval($type_id);
				}
				else {
					$token['MTyp'] = 'NEW' . count($global['mtypes']);
					$global['mtypes'][] = ['Beta' => $token['Token'], 'Orth' => ($parsed['string']?: ''), 'Genus' => $gender, 'Quelle' => $tokenizer->getData('source')];
				}
				$token['PTyp'] = NULL;
			}
			else {
				$type_id = $va_xxx->get_var($va_xxx->prepare('SELECT Id_phon_Typ FROM phon_Typen WHERE Beta = %s AND Quelle = %s', $token['Token'], $tokenizer->getData('source')));
				if($type_id){
					$token['PTyp'] = intval($type_id);
				}
				else {
					$token['PTyp'] = 'NEW' . count($global['ptypes']);
					$global['ptypes'][] = ['Beta' => $token['Token'], 'Original' => ($parsed['string']?: ''), 'Quelle' => $tokenizer->getData('source')];
				}
				$token['MTyp'] = NULL;
			}
			
			$currentGroupTypesBeta[] = $token['Token'] . $token['Trennzeichen'];
			if ($token['Trennzeichen_Original'] !== '' && $parsed['string']){
				if ($currentGroupTypesOrg !== NULL){
					$currentGroupTypesOrg[] = $parsed['string'] . $token['Trennzeichen_Original'];
				}
			}
			else {
				$currentGroupTypesOrg = NULL;
			}
			
			//Last token of group
			if($index == count($tokens) - 1 || $tokens[$index + 1]['Ebene_3'] == 1){
				if($token['Id_Tokengruppe'] !== NULL){
					$betaGroup = implode('', $currentGroupTypesBeta);
					$group = &$global['groups'][$indexGroup];
					$groupGender = $group['Genus'];
					
					if($extraData['class'] == 'M'){
						$gtype_id = $va_xxx->get_var($va_xxx->prepare('SELECT Id_morph_Typ FROM morph_Typen WHERE Beta = %s AND Genus = %s AND Quelle = %s', $betaGroup, $groupGender, $tokenizer->getData('source')));
						if ($gtype_id){
							$group['MTyp'] = $gtype_id;
						}
						else {
							$group['MTyp'] = 'NEW' . count($global['mtypes']);
							$global['mtypes'][] = [
								'Beta' => $betaGroup, 
								'Orth' => ($currentGroupTypesOrg? implode('', $currentGroupTypesOrg): ''),
								'Genus' => $groupGender, 
								'Quelle' => $tokenizer->getData('source')];
						}
					}
					else {
						$gtype_id = $va_xxx->get_var($va_xxx->prepare('SELECT Id_phon_Typ FROM phon_Typen WHERE Beta = %s AND Quelle = %s', $betaGroup,$tokenizer->getData('source')));
						if ($gtype_id){
							$group['PTyp'] = intval($gtype_id);
						}
						else {
							$group['PTyp'] = 'NEW' . count($global['ptypes']);
							$global['ptypes'][] = [
								'Beta' => $betaGroup, 
								'Original' => ($currentGroupTypesOrg? implode('', $currentGroupTypesOrg): ''),
								'Quelle' => $tokenizer->getData('source')];
						}
					}
					
					$addGroup = $tokenizer->getData('source') . '-Typ "' . ($currentGroupTypesOrg? implode('', $currentGroupTypesOrg): $betaGroup) . '"';
					$group['Bemerkung'] = ($group['Bemerkung']? $group['Bemerkung'] . ' ' . $addGroup: $addGroup);
					$indexGroup++;
				}
				$currentGroupTypesBeta = [];
				$currentGroupTypesOrg = [];
			}
			
			$add = $tokenizer->getData('source') . '-Typ "' . (($parsed['string'] && !$containsHtml) ? $parsed['string'] : $token['Token']) . '"';
			$token['Token'] = '';
			$token['Bemerkung'] = ($token['Bemerkung']? $token['Bemerkung'] . ' ' . $add: $add);
		}
	}
	else {
		foreach ($tokens as &$token){
			$token['MTyp'] = NULL;
			$token['PTyp'] = NULL;
		}
	}

	return [$tokens, $global];
}

function va_tokenize_split_double_genders ($tokenizer, $tokens, $global, $extraData){
	$result = [];
	
	//Duplicate tokens with multiple gender information
	$currentGroup = [];
	
	foreach ($tokens as $index => $token){
		//Last token in group
		if($index == count($tokens) - 1 || $tokens[$index + 1]['Ebene_3'] == 1){

			if(isset($token['Bemerkung'])){
				$genderRegex = '/(?<=^|[ .,;])[MFNmfn](?=$|[ .,;])/';
				$notes = $token['Bemerkung'];
				preg_match_all($genderRegex, $notes, $matches, PREG_OFFSET_CAPTURE);
				
				if(count($matches[0]) > 0){
					$genderStrs = [];
					$offset = 0;
					foreach ($matches[0] as $match){
						$start = $match[1] - $offset;
						$len = ($start == strlen($notes) - 1 || $notes[$start + 1] != '.'? 1: 2);
						$genderStr = substr($notes, $start, $len);
						if(!in_array(strtolower($genderStr), array_map(function ($arr) {return strtolower($arr[0]);}, $genderStrs))){
							$genderStrs[] = [$genderStr, $start];
							$notes = substr($notes, 0, $start) . substr($notes, $start + $len);
							$offset += $len;
						}
					}

					
					foreach ($genderStrs as $genderStr){
						foreach ($currentGroup as $gtoken){
							$result[] = $gtoken;
						}
						$newToken = $token;
						$newToken['Bemerkung'] = trim(substr($notes, 0, $genderStr[1]) . $genderStr[0] . substr($notes, $genderStr[1]));
						$newToken['Genus'] = strtolower($genderStr[0][0]);
						$result[] = $newToken;
					}
					$currentGroup = [];
					continue;
				}
			}
			
			$token['Genus'] = '';
			foreach ($currentGroup as $gtoken){
				$result[] = $gtoken;
			}
			$result[] = $token;
			$currentGroup = [];
		}
		else {
			$token['Genus'] = '';
			$currentGroup[] = $token;
		}
	}
	return [$result, $global];
}

class TokenizerException extends Exception {
	private $msg;
	
	public function __construct($msg){
		$this->msg = $msg;
	}
	
	public function __toString (){
		return $this->msg;
	}
}
?>