<?php
/*
 * Mainly wrapper for PEG parser that throws an error if not the whole string is parsed
 */
use WouterJ\Peg\Definition;
use WouterJ\Peg\Grammar;

class BetaParser {
	private $parser;
	private $rules;
	private $optionals;
	
	private $source;
	protected $va_beta = true;
	
	private $accents;
	private $vowels;
	private $default_accent = false;
	
	private $db;
	private $ipa_table;
	private $orig_table;
	private $rules_table;
	
	public function __construct (&$db, $rules_table, $orig_table, $source, $ipa_table = false, $init = true){
		$this->source = $source;
		$this->rules_table = $rules_table;
		$this->ipa_table = $ipa_table;
		$this->orig_table = $orig_table;
		$this->db = &$db;
		
		if ($init)
			$this->init();
	}
	
	protected function init ($rules = NULL, $optionals = NULL){
		$this->include_peg();
		
		if (!$rules){
			list ($this->rules, $this->optionals) = $this->rules_beta($db);
		}
		else {
			$this->rules = $rules;
			$this->optionals = $optionals;
		}
		
		$this->reset_parser();
		
		if ($this->ipa_table){
			$this->accents = $this->db->get_results("SELECT Beta, IPA FROM " . $this->ipa_table . " WHERE Kind = 'Accent' AND Source = '$this->source'", ARRAY_N);
			$this->vowels = $this->db->get_col("SELECT DISTINCT SUBSTR(Beta, 1, 1) FROM " . $this->ipa_table . " WHERE Kind = 'Vowel' AND Source = '$this->source'");
		}
	}
	
	public function default_accent_for_ipa ($bool){
		$this->default_accent = $bool;
	}
	
	public function split_chars ($input, $option = NULL){
		try {
			$change_rules = $option && isset($this->optionals[$option]);
			if($change_rules){
				$this->set_parser_for_option($option);
			}
			
			$res = $this->parser->parse($input);
			
			if($change_rules){
				$this->reset_parser();
			}
			
			if($res['str'] !== $input){
				return ['String not valid after: ' . htmlentities($res['str']), false];
			}
		}
		catch (Exception $e){
			return [$e->getMessage(), false];
		}

		return [$res['arr'], true];
	}
	
	private function set_parser_for_option ($option){
		$rules = $this->rules;
		
		foreach ($this->optionals[$option] as $id => $extra_rule){
			$rules[$id] = $extra_rule;
		}
		
		$this->reset_parser($rules);
	}
	
	private function get_rules_for_option ($rules, $option){

		foreach ($this->optionals[$option] as $id => $extra_rule){
			$rules[$id] = $extra_rule;
		}
		
		return $rules;
	}
	
	private function reset_parser ($rules = NULL){
		if(!$rules)
			$rules = $this->rules;
		
		$definitions = [];
		$first = NULL;
		foreach ($rules as $id => $rule){
			if(!$first)
				$first = $id;
			$definitions[] = new Definition($id, $rule[0], $this->php_callback($rule[1], $id, $this->va_beta));
		}
		
		$this->parser = new Grammar($first, $definitions);
	}
	
	public function convert_space_to_ipa ($input){
		if (!$this->ipa_table)
			return false;
		
		$space_ipa = $this->db->get_var($this->db->prepare('SELECT IPA FROM ' . $this->ipa_table . ' WHERE Source = %s AND Kind = "Blank" AND Beta = %s',
				$this->source, $input));
		
		if($space_ipa)
			return $space_ipa;
		
		return false;
	}
	
	public function convert_space_to_original ($input){
		$space_org = $this->db->get_var($this->db->prepare('SELECT Original FROM ' . $this->orig_table . ' WHERE Beta = %s', $input));
		
		if($space_org)
			return $space_org;
			
		return false;
	}
	
	public function beta_from_unicode_character ($unicode){
		
		if (ctype_alpha($unicode))
			return $unicode;
		
		$upper = false;
		
		if (ctype_upper($unicode)){
			$unicode = strtolower($unicode);
			$upper = true;
		}
		
		$original = $this->db->get_var($this->db->prepare('SELECT Beta FROM ' . $this->orig_table . ' WHERE Original COLLATE utf8mb4_bin = %s', $unicode));
		
		if ($upper){
			$original = strtoupper($original);
		}
		
		return $original;
	}
	
	public function convert_to_original ($input, $option = NULL){
		if(!$this->va_beta){
			return ['string' => false, 'output' => [['error', 'Not VA beta code']]];
		}
		
		if(is_string($input)){
			list($chars, $valid) = $this->split_chars($input, $option);
		}
		else {
			$chars = $input;
			$valid = true;
		}

		if ($chars === false || !$valid)
			return ['string' => false, 'output' => [['error', 'Record not valid: ' . $input . ' -> ' . $chars]]];

		$missing = [];
		
		$result = '';
		$has_span = false;
		$output = [];
		
		foreach ($chars as $char){
			if(strlen($char) == 3 && $char[0] == '\\' && $char[1] == '\\'){ //Escaped characters:
				$result .= $char[2];
			}
			else {
				$caseChanged = false;
				
				if($option == 'UPPERCASE' && ctype_upper($char[0])){
					$char = strtolower($char[0]) . substr($char, 1);
					$caseChanged = true;
				}
				
				$original = $this->db->get_var($this->db->prepare('SELECT Original FROM ' . $this->orig_table . ' WHERE Beta COLLATE utf8_bin = %s', $char));
				if($original){
					if($caseChanged){
						$original = mb_strtoupper(mb_substr($original, 0, 1)) . mb_substr($original, 1);
					}
					
					if(strpos($original, "<span style='position: absolute;") !== false){
						$result .= "<span style='position: relative'>" . $original . '</span>';
						$has_span = true;
					}
					else {
						$result .= $original;
					}
				}
				else if (!in_array($char, $missing)){
					$missing[] = $char;
				}
			}
		}
		
		if (count($missing) > 0){
			$result = false;
			$this->add_missing_char_errors($missing, $output, 'original');
		}
		else {
			if ($has_span){
				$result = "<span style='position : relative'>" . $result . '</span>'; //Add this to make the absolute spans work
			}
		}
		
		return ['string' => $result, 'output' => $output];
	}
	
	public function convert_to_ipa ($input, $option = NULL){
		if (!$this->ipa_table)
			return false;
		
		if(is_string($input)){
			list($chars, $valid) = $this->split_chars($input, $option);
		}
		else {
			$chars = $input;
			$valid = true;
		}
		
		if ($chars === false || !$valid)
			return false;
			
		$missing = [];
		$vowels_str = implode('', $this->vowels);
		
		$result = '';
		$accent_explicit = false;
		$indexLastVowel = false;
		$numVowels = 0;
		$output = [];
		
		foreach ($chars as $char){
			
			//Look for vowels with accents
			foreach ($this->accents as $accent) {
				$acc_quoted = preg_quote($accent[0], '/');
				$char = preg_replace_callback('/([' . $vowels_str . '][^' . $acc_quoted . 'a-zA-Z]*)' . $acc_quoted . '/',
					function ($matches) use (&$result, $accent, &$accent_explicit){
						$result .= $accent[1]; //Add ipa accent before vowel
						$accent_explicit = true; //Explicit accent found => no accent has to be added
						return $matches[1];
					}, $char
				);
			}
			
			$ipa = $this->db->get_var($this->db->prepare('SELECT IPA FROM ' . $this->ipa_table . ' WHERE Beta = %s COLLATE utf8mb4_bin AND Source = %s', $char, $this->source));
			if($ipa){
				$result .= $ipa;
				
				if(in_array($char[0], $this->vowels)){
					$indexLastVowel = mb_strlen($result) - mb_strlen($ipa);
					$numVowels++;
				}
			}
			else if (!in_array($char, $missing) && mb_strpos($char, '\\\\') === false){
				$missing[] = $char;
			}
		}
		
		if (count($missing) > 0){
			$result = false;
			$this->add_missing_char_errors($missing, $output, $this->source . '-IPA');
		}
		else {
			if($this->default_accent && !$accent_explicit && $indexLastVowel !== false && $numVowels > 1){
				//Accent on last syllable, if not set explicitly
				$result = mb_substr($result, 0, $indexLastVowel) . $this->accents[0][1] . mb_substr($result, $indexLastVowel);
				$output[] = ['note', 'Accent added'];
			}
		}
		
		return ['string' => $result, 'output' => $output];
	}
	
	private function add_missing_char_errors ($missing, &$output, $spec){
		$output = array_merge($output, array_map(function ($miss) use ($spec){
			return ['missing', 'Missing character in ' . $spec . ' codepage: ' . $miss];
		}, $missing));
	}
	
	private $currentDiacritics = [];
	
	//Only works if the top-level rule is an array type (sequence, repeat, etc.)
	private function php_callback ($returnType, $id, $beta){
		
		$cb = function ($input) use ($returnType, $id, $beta, &$cb){
			//echo 'INPUT: '. json_encode($input) . '<br>';
			if($beta && $id == 'Basiszeichen'){ //TODO not perfect o:{x}: is allowed
				$this->currentDiacritics = [];
			}
	
			$str = '';
			$arr = [];
			foreach ($input as $element){
				if (is_string($element)){ //String => pass through
					$str .= $element;
					$arr[] = $str;
					
					if($beta && strpos($id, 'Diakritikum') === 0){ //TODO comment this in description of tokens parsing
						if(in_array($element, $this->currentDiacritics)){
							throw new Exception('Diacritic used twice!');	
						}
						$this->currentDiacritics[] = $element;
					}
				}
				else if (count($element) == 0){
					//Skip
				}
				else if (isset($element['str']) && isset($element['arr'])) { //Just result array => pass through
					$str .= $element['str'];
					if ($element['arr']){
						$arr = array_merge($arr, $element['arr']);
					}
					else if ($returnType == 'array' && $element['str']) {
						$arr[] = $element['str'];
					}
				}
				else {
					$recres = $cb($element);
					$str .= $recres['str'];
					if($recres['arr']){
						$arr = array_merge($arr, $recres['arr']);
					}
				}
			}
			
			if($returnType == 'string'){
				$arr = [];
			}
			//echo 'OUTPUT: '. json_encode(['str' => $str, 'arr' => $arr]) . '<br>';
			return ['str' => $str, 'arr' => $arr];
		};
		
		return $cb;
	}
	
	protected function rules_beta (){

		$base_chars = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Base'");
		$spaces = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Blank'");
		$special_chars = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Special'");
		$diacriticsAbove = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Diacritic' AND (Position = 'above' OR Position = 'inherited') AND Beta != '\\\\' ORDER BY LENGTH(Beta) DESC, Beta ASC");
		$diacriticsBelow = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Diacritic' AND (Position = 'below' OR Position = 'inherited') ORDER BY LENGTH(Beta) DESC, Beta ASC");
		$diacriticsAfter = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Diacritic' AND Position = 'after' ORDER BY LENGTH(Beta) DESC, Beta ASC");
		$diacriticsDirect = $this->db->get_col("SELECT Beta FROM " . $this->rules_table . " WHERE Kind = 'Diacritic' AND Position = 'direct' ORDER BY LENGTH(Beta) DESC, Beta ASC");
		
		$grammarData = [];

		$grammarData['Belegliste'] = [
				['sequence', [
						['identifier', 'Beleg'],
						['repeat',
								['sequence', [
										['repeat', ['literal', " "], 0, 1],
										['identifier', 'Trennzeichen'],
										['repeat', ['literal', " "], 0, 1],
										['identifier', 'Beleg']
								]
								], 0, INF]
				]
				], 'array'];
		
		$grammarData['Beleg'] = [
				['sequence', [
						['identifier', 'Token'],
						['repeat', [
								'sequence', [
										['identifier', 'Leerzeichen'],
										['identifier', 'Token']
								]
						], 0, INF]
				]
				], 'array'];
		
		$grammarData['Token'] = [
				['repeat', ['choice', [
					['identifier', 'Zeichen'],
					['identifier', 'Zeichengruppe'],
					['identifier', 'Sonderzeichen'],
					['identifier', 'Maskiert']
				]], 1, INF],
				'array'];
		
		$grammarData['Zeichen'] = [['sequence', [['identifier', 'Basiszeichen'], ['sequence', [
			['repeat', ['identifier', 'DiakritikumDirekt'], 0, INF],
			['repeat', ['identifier', 'DiakritikumUnten'], 0, INF],
			['repeat', ['identifier', 'DiakritikumOben'], 0, INF],
			['repeat', ['identifier', 'DiakritikumNach'], 0, INF]
		]]]], 'string'];
		
		$grammarData['Zeichengruppe'] = [['sequence', [
				['literal', '['],
				['repeat', ['identifier', 'Zeichen'], 1, INF],
				['literal', ']'],
				['choice', [['identifier', 'DiakritikumDirekt'], ['identifier', 'DiakritikumUnten'], ['identifier', 'DiakritikumOben'], ['identifier', 'DiakritikumNach']]]
			]], 'string'];
		
		$grammarData['Maskiert'] = [['sequence', [
				['literal', '\\\\'],
				['characterClass', '^a-zA-Z()']
		]], 'string'];
		
		$grammarData['Basiszeichen'] = [['choice',
				array_merge([
					['sequence', [['literal', 'aa'], ['not', ['choice', 
						[['identifier', 'DiakritikumDirekt'], 
						['identifier', 'DiakritikumUnten'], 
						['identifier', 'DiakritikumOben'], 
						['identifier', 'DiakritikumNach']]]]]], 
					['sequence', [['literal', 'ee'], ['not', ['choice',
						[['identifier', 'DiakritikumDirekt'],
							['identifier', 'DiakritikumUnten'],
							['identifier', 'DiakritikumOben'],
							['identifier', 'DiakritikumNach']]]]]], 
					['sequence', [['literal', 'ii'], ['not', ['choice',
						[['identifier', 'DiakritikumDirekt'],
							['identifier', 'DiakritikumUnten'],
							['identifier', 'DiakritikumOben'],
							['identifier', 'DiakritikumNach']]]]]], 
					['sequence', [['literal', 'oo'], ['not', ['choice',
						[['identifier', 'DiakritikumDirekt'],
							['identifier', 'DiakritikumUnten'],
							['identifier', 'DiakritikumOben'],
							['identifier', 'DiakritikumNach']]]]]], 
					['sequence', [['literal', 'uu'], ['not', ['choice',
						[['identifier', 'DiakritikumDirekt'],
							['identifier', 'DiakritikumUnten'],
							['identifier', 'DiakritikumOben'],
							['identifier', 'DiakritikumNach']]]]]]], 
						array_map(function ($s) {return ['literal', $s];}, $base_chars), [['characterClass', 'a-z']])
						], 'string'];
		
		$grammarData['DiakritikumDirekt'] = [['choice', array_map(function ($diacritic){
			return $this->beta_to_grammar($diacritic, 'Direkt');
		}, $diacriticsDirect)], 'string']; //TODO in Transkriptionsregeln-Text
		
		$grammarData['DiakritikumUnten'] = [['choice', array_map(function ($diacritic){
			return $this->beta_to_grammar($diacritic, 'Unten');
		}, $diacriticsBelow)], 'string'];
		
		$grammarData['DiakritikumOben'] = [['choice',
			array_merge(array_map(function ($diacritic){
				return $this->beta_to_grammar($diacritic, 'Oben');
			}, $diacriticsAbove), [['sequence', [['literal', '\\'], ['not', ['literal', '\\']]]]])
		], 'string'];
		
		$grammarData['DiakritikumNach'] = [['choice', array_map(function ($diacritic){
			return $this->beta_to_grammar($diacritic, 'Nach');
		}, $diacriticsAfter)], 'string']; //TODO in Transkriptionsregeln-Text
		
		$grammarData['Leerzeichen'] = [['choice', array_map(function ($s) {return ['literal', $s];}, $spaces)], 'string'];
		
		$grammarData['Sonderzeichen'] = [['choice', array_map(function ($s) {return ['literal', $s];}, $special_chars)], 'string'];
		
		$grammarData['Trennzeichen'] = [['choice',
				[['literal', ','], ['literal', ';']]
		], 'string'];
		
		$grammarData['Ziffer'] = [['characterClass', '0-9'], 'string'];

		$optionals = [];
		$optionals['UPPERCASE'] = [];
		$optionals['UPPERCASE']['Token'] = [['choice', [
			['sequence', [['repeat', ['identifier', 'Grossbuchstabe'], 0, 1], $grammarData['Token'][0]]],
			['identifier', 'Grossbuchstabe']
		]], 'array']; //TODO document
		$optionals['UPPERCASE']['Grossbuchstabe'] = [['sequence', [
			['characterClass', 'A-Z'],
			['repeat', ['identifier', 'DiakritikumDirekt'], 0, INF],
			['repeat', ['identifier', 'DiakritikumUnten'], 0, INF],
			['repeat', ['identifier', 'DiakritikumOben'], 0, INF],
			['repeat', ['identifier', 'DiakritikumNach'], 0, INF]
		]], 'string'];
		$spaces = $grammarData['Leerzeichen'][0];
		$spaces[1][] = ['literal', '\\\\-'];
		$optionals['UPPERCASE']['Leerzeichen'] = [$spaces, 'string'];
		$optionals['UPPERCASE']['Maskiert'] = [['sequence', [
			['literal', '\\\\'],
			['characterClass', '^a-zA-Z\\-']
		]], 'string'];
		
		$optionals['COMMENTS'] = [];
		$optionals['COMMENTS']['Beleg'] = [
			['sequence', [
				['identifier', 'Token'],
				['repeat', [
					'sequence', [
						['identifier', 'Leerzeichen'],
						['identifier', 'Token']
					]
				], 0, INF],
				['repeat', ['identifier', 'Kommentar'], 0, INF]
			]
			], 'array'];
		
		$optionals['COMMENTS']['Kommentar'] = [
			['sequence', [
				['repeat', ['literal', ' '], 0, 1],
				['literal', '<'],
				['repeat', ['characterClass', '^>'], 1, INF],
				['literal', '>'],
				['repeat', ['literal', ' '], 0, 1]
			]
		], 'string'];
		
		return [$grammarData, $optionals];
	}
	
	private function include_peg (){
		$path = VA_PLUGIN_PATH . '/lib/peg_php/';
		
		include_once($path . '/Grammar.php');
		include_once($path . 'Definition.php');
		include_once($path . 'Parser.php');
		include_once($path . 'Exception.php');
		include_once($path . 'Result.php');
		include_once($path . 'Util.php');
		include_once($path . 'Exception/OperatorException.php');
		include_once($path . 'Exception/DefinitionException.php');
	}

	public function grammar_string ($lenBorder = 100){
		
		$res = '';
		$middle = intval($lenBorder / 2);
		
		foreach ($this->rules as $rule){
			$ruleStr = $this->operator_to_string($rule[1]);
			
			if($ruleStr[0] == '(' && substr($ruleStr, -1, 1) == ')'){
				$ruleStr = substr($ruleStr, 1, strlen($ruleStr) - 2);
			}
			
			$resStrings = [$ruleStr];
			$lastStr = $resStrings[count($resStrings) - 1];
			
			while (strlen($lastStr) > $lenBorder && strpos($lastStr, '/') !== false){
				$index = 0;
				array_pop($resStrings);
				
				while (true){
					if($lastStr[$middle + $index] == '/'){
						break;
					}
					
					if($lastStr[$middle - $index] == '/'){
						$index = -1 * $index;
						break;
					}
					
					$index++;
				}
				
				$resStrings[] = substr($lastStr, 0, $middle + $index);
				$resStrings[] = substr($lastStr, $middle + $index);
				
				$lastStr = $resStrings[count($resStrings) - 1];
			}
			
			$res .= $rule[0] . ' <- ' . implode("\n", $resStrings) . "\n";
		}
		return $res;
	}

	private function operator_to_string ($operator){
		switch ($operator[0]){
			case 'sequence':
				$res = [];
				foreach ($operator[1] as $sub){
					$res[] = $this->operator_to_string($sub);
				}
				return '(' . implode(' ', $res) . ')';
				
			case 'literal':
				return "'" . addslashes($operator[1]) . "'";
				
			case 'identifier':
				return $operator[1];
				
			case 'repeat':
				$min = $operator[2];
				$max = isset($operator[3]) ? $operator[3] : INF;
				
				if ($min == 0 && $max == INF){
					return $this->operator_to_string($operator[1]) . '*';
				}
				else if ($min == 1 && $max == INF){
					return $this->operator_to_string($operator[1]) . '+';
				}
				else if ($min == 0 && $max == 1){
					return $this->operator_to_string($operator[1]) . '?';
				}
				else {
					throw new Exception ('Borders of repeat not valid not found: [' . $min . ', ' . $max . ']');
				}
				
			case 'choice':
				$res = [];
				foreach ($operator[1] as $sub){
					$res[] = $this->operator_to_string($sub);
				}
				
				return '(' . ($res? implode(" / ", $res): '& {return false;} "§"') /*Impossible rule for empty options list*/  . ')';
				
			case 'not':
				return '!' . $this->operator_to_string($operator[1]);
				
			case 'characterClass':
				return '[' . $operator[1] . ']';
				
			default:
				echo json_encode($operator);
				throw new Exception('Operator not found: ' .$operator[0]);
		}
	}

	private function beta_shortcodes ($shortcode, $dtype){
		switch ($shortcode){
			case 'z':
				return 'Zeichen';
			case 'b':
				return 'Basiszeichen';
			case 'd':
				return 'Diakritikum' . $dtype;
			case 'x':
				return 'Sonderzeichen';
			case 's':
				return 'Leerzeichen';
			case 'n':
				return 'Ziffer';
		}
	}
	
	private function beta_to_grammar ($rule, $dtype){
		$count = preg_match_all('/<([bdxsnz])(\*)?>/', $rule, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		if ($count == 0){
			if (strlen($rule) == '1' && !is_numeric($rule)){
				return ['sequence', [['literal', $rule], ['not', ['identifier', 'Ziffer']]]];
			}
			else {
				return ['literal', $rule];
			}
		}
		
		$res = [];
		$currIndex = 0;
		foreach ($matches as $match){
			$pre = substr($rule, $currIndex, $match[0][1] - $currIndex);
			if ($pre !== ''){
				$res[] = ['literal', $pre];
			}
			
			if(isset($match[2])){
				$res[] = ['repeat', ['identifier', $this->beta_shortcodes($match[1][0], $dtype)], 0];
			}
			else {
				$res[] = ['identifier', $this->beta_shortcodes($match[1][0], $dtype)];
			}
			$currIndex = $match[0][1] + strlen($match[0][0]);
		}
		
		$post = substr($rule, $currIndex);
		if ($post !== ''){
			$res[] = ['literal', $post];
		}
		return ['sequence', $res];
	}
	
	public function build_js_grammar_string ($options = NULL){

		if ($options){
			$rules = $this->rules;
			foreach ($options as $option){
				if(isset($this->optionals[$option])){
					$rules = $this->get_rules_for_option($rules, $option);
				}
				else {
					throw new Exception('Invalid parser option');
				}
			}
		}
		else {
			$rules = $this->rules;
		}
		
		$res = "{\n";
		$res .= "var diacriticsUsed = {};\n";
		$res .= "var flatten = arr => arr.reduce((a, b) => a.concat(Array.isArray(b)? flatten(b): b), []).filter(x => x != null);\n";
		$res .= "}\n";
		
		$first = true;
		foreach ($rules as $key => $rule){
			if($first){
				$res .= "start = all: " . $key . " {return flatten(all);} \n";
				$first = false;
			}
			$res .= $key . ' = all:' . ($rule[0][0] == 'literal'? 'l:' : '') .$this->operator_to_string($rule[0]) . ' ' . $this->js_return_clause ($key, $rule[1]) . "\n";
		}
		
		return $res;
	}
	
	private function js_return_clause ($key, $returnType){
		if($returnType == 'string'){
			$pre = '';
			
			if($key == 'Basiszeichen' || $key == 'Grossbuchstabe'){
				$pre = 'diacriticsUsed = {};';
			}
			else if (strpos($key, 'Diakritikum') === 0){
				$pre = 'if (diacriticsUsed[all]) error("Diacritic used twice"); diacriticsUsed[all] = true;';
			}
			
			return '{' . $pre . 'return text();}';
		}
		else {
			return '';
		}
	}

}
?>