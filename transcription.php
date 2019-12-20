<?php

/**
 * Plugin Name: Transcription tool
 * Plugin URI: keine
 * Description: 
 * Version: 0.0
 * Author: fz
 * Author URI: keine
 * Text Domain: tt
 * License: CC BY-SA 4.0
 * Text Domain: tt
 */

class TranscriptionTool {
	
	private static $db;
	private static $document_path;
	
	private static $required_tables = ['transcription_rules', 'codepage_original', 'attestations', 'stimuli', 'informants', 'c_attestation_concept', 'locks'];
	private static $mappings;
	
	private static $concepts;
	private static $sources;
	
	private static $special_val_buttons;
	private static $informant_filters;
	
	private static $ajax_params = ['action' => 'tt'];
	
	private static $cap_read = 'va_transcription_tool_read';
	private static $cap_write = 'va_transcription_tool_write';
	private static $cap_edit = 'va_transcription_edit_all';
	
	private static $initialized = false;
	
	static function init_plugin (){
		register_activation_hook(__FILE__, [__CLASS__, 'install']);
		
		add_action('wp_ajax_tt', [__CLASS__, 'ajax_handler']);
		
		add_action('plugins_loaded', [__CLASS__, 'text_domain']);
		
		include_once('table_mapping.php');
		include_once('beta/beta_parser.php');
		include_once('beta/tokenization.php');
	}
	
	static function install (){
		//Register capabilities
		global $wp_roles;
		$administrator = $wp_roles->role_objects['administrator'];
		
		$administrator->add_cap(self::$cap_read);
		$administrator->add_cap(self::$cap_write);
		$administrator->add_cap(self::$cap_edit);
	}
	
	static function text_domain (){
		load_plugin_textdomain( 'tt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
	
	static function add_special_val_button ($text, $dbval, $help = NULL){
		self::$special_val_buttons[] = [$text, $dbval, $help];
	}
	
	static function add_informant_filter ($col, $val, $type, $text, $selected){
		self::$informant_filters[] = [$col, $val, $type, $text, $selected];
	}
	
	static function init ($document_path, &$db, $sources, $concepts, $mappings = NULL){
		if (!$db){
			global $wpdb;
			self::$db = &$wpdb;
		}
		else {
			self::$db = &$db;
		}

		self::$document_path = $document_path;
		self::$sources = $sources;
		self::$concepts = $concepts;

		foreach (self::$required_tables as $table){
			if (isset($mappings[$table])){
				self::$mappings[$table] = $mappings[$table];
			}
			else {
				self::$mappings[$table] = new TableMapping($table);
			}
		}
		
		self::$initialized = true;
	}
	
	private static function set_parameter (&$data, $name, $default = NULL){
		if (!isset($data[$name])){
			if ($default){
				return $default;
			}
			else {
				throw new Exception('Missing paramter: ' . $name);
			}
		}
		
		return $data[$name];
	}

	static function create_menu ($sub_menu = NULL){
		if ($sub_menu){
			add_submenu_page($sub_menu, __('Transcription tool', 'tt'), __('Transcription tool', 'tt'), self::$cap_read, 'transcription', [__CLASS__, 'echo_content']);
		}
		else {
			add_menu_page(__('Transcription tool', 'tt'), __('Transcription tool', 'tt'), self::$cap_read, 'transcription', [__CLASS__, 'echo_content']);
		}
	}
	
	static function add_ajax_param ($param, $val){
		self::$ajax_params[$param] = $val;
	}


	static function echo_content () {
		
		if (isset($_REQUEST['onlytable']) && $_REQUEST['onlytable']){
			wp_enqueue_style('tt_style', plugins_url('', __FILE__) . '/transcription.css');
			echo '<div id="iframeCodepageDiv" style="width: 100%; height: 100%;">';
			self::print_rule_table(true);
			echo '</div>';
			return;
		}
		
		if (!self::$initialized){
			echo 'Not initialized!';
			return;
		}
	
		$helpProblem = __('This button skips the current informant and marks it as problematic. These problem cases can be transcribed later using the problem mode in the select box on the right', 'tt');
		
		$helpScans = __('If the stimulius is marked green the corresponding scan exists, if it is marked red the scan is missing.', 'tt');
		
		$folder = plugins_url('', __FILE__) . '/';
		
		wp_enqueue_script('tt_script', $folder . 'transcription.js?v=1', [], false, true);
		wp_enqueue_style('tt_style', $folder . 'transcription.css');
		
		wp_enqueue_script('tt_qtip', $folder . 'lib/qtip/jquery.qtip.min.js', ['jquery']);
		wp_enqueue_style('tt_qtip_style', $folder . 'lib/qtip/jquery.qtip.min.css');
		
		wp_enqueue_script('tt_select2', $folder . 'lib/select2/js/select2.min.js', ['jquery']);
		wp_enqueue_style('tt_select2_style', $folder . 'lib/select2/css/select2.min.css');
		
		wp_enqueue_script('tt_grammar', $folder . '/lib/peg-0.10.0.min.js');
		
		//Concepts for js
		wp_localize_script('tt_script', 'Concepts', self::$concepts);
		
		//Translations
		wp_localize_script('tt_script', 'Translations', [
			'NOT_VALID' => __('Not valid', 'tt'),
			'SEARCH_MAP' => __('Search map', 'tt'),
			'NO_INPUT' => __('No input in line %!', 'tt'),
			'NO_CONCEPTS' => __('No concepts for attestation in line %!', 'tt'),
			'INVALID_RECORD' => __('Attestation in line % is not valid!', 'tt'),
			'INSERT' => __('Insert', 'tt'),
			'UPDATE' => __('Update', 'tt'),
			'LOCKED' => __('This stimulis is currently transcribed by another user!', 'tt')
		]);
		
		//Ajax paramters
		wp_localize_script('tt_script', 'AjaxData', self::$ajax_params);

		//Original codepage for js:
		$chars = self::$db->get_results(self::create_query('SELECT c.#Beta#, c.#Original# FROM #codepage_original# c'), ARRAY_N);
		
		$char_assoc = [];
		
		foreach ($chars as $char){
			$char_assoc[$char[0]] = $char[1];
		}
		wp_localize_script('tt_script', 'Codepage', $char_assoc);
		wp_localize_script('tt_script', 'url', home_url(self::$document_path));
		
		$special_vals = ['problem'];
		foreach (self::$special_val_buttons as $button_data){
			$special_vals[] = $button_data[1];
		}
		wp_localize_script('tt_script', 'SpecialValues', $special_vals);
		
		$rulesBase = 'rules_base.html';
		
		$lang = substr(get_user_locale(), 0, 2);
		$transl_content = apply_filters('tt_get_rules_text', false, $lang);
		
		if ($transl_content === false){
			$rulesFile = 'rules_en.html';
			
			if (file_exists(dirname(__FILE__) . '/rules_' . $lang . '.html')){
				$rulesFile = 'rules_' . $lang . '.html';
			}
			
			$transl_content = file_get_contents(dirname(__FILE__) . '/' . $rulesFile);
		}
		
		wp_localize_script('tt_script', 'Base_File', $folder . $rulesBase);
		wp_localize_script('tt_script', 'Rules', $transl_content);
		
		$url_data = [];
		
		if (isset($_REQUEST['stimulus'])){
			$url_data['stimulus'] = $_REQUEST['stimulus'];
			$url_data['atlas'] = self::$db->get_var(self::create_query('SELECT s.#Source# FROM #stimuli# s WHERE s.#Id_Stimulus# = %d', [$_REQUEST['stimulus']]));
		}
		else if  (isset($_REQUEST['atlas'])){
			$url_data['atlas'] = $_REQUEST['atlas'];
		}
		
		if (isset($_REQUEST['informant'])){
			$url_data['informant'] = $_REQUEST['informant'];
		}
		
		wp_localize_script('tt_script', 'URLData', $url_data);
		
		?>

		<div id="iframeScanDiv">
			<iframe src="<?php echo $folder . $rulesBase?>" id="iframeScan"></iframe>
		</div>
		<div id="iframeCodepageDiv">
			
			<?php self::print_rule_table(true); ?>
			
			<br />
			<br />
			<br />
			<br />
			
			<?php 
			$base = file_get_contents(dirname(__FILE__) . '/' . $rulesBase);
			echo str_replace('###CONTENT###', $transl_content, $base);
			?>
		</div>
		<div id="enterTranscription">
		<?php
		?>
			<select id="atlasSelection">
				<option value="-1"><?php _e('Choose atlas', 'tt');?></option>
				<?php
				foreach(self::$sources as $source){
					echo "<option value='$source'>$source</option>";
				}
				?>
			</select>
			
			<a href="" target="_BLANK" id="atlas_info"><span class="dashicons dashicons-paperclip"></span></a>
			
			<div id="mapSelectionDiv">
				<select id="mapSelection">
				</select>
				<?php echo self::info_symbol($helpScans);?>
			</div>
			
			<div id="informant_info" class="hidden_c" style="display: inline;">
				<span class="informant_fields"></span> - <?php _e('Informant no.', 'tt');?>
				<span class="informant_fields"></span>
			</div>
		
			<div style="float:right; display:inline;">
				<input id="region" placeholder="<?php _e('Informant number(s)', 'tt');?>" style="background-color : #ffffff; display : inline;" />
				<?php if (self::$informant_filters){ ?>
				<input type="button" id="informant_filters" value="<?php _e('Filters', 'tt');?>">
				<?php } ?>
				<img  style="vertical-align: middle;" src="<?php echo VA_PLUGIN_URL . '/images/Help.png';?>" id="helpIconInformants" class="helpIcon" />
			</div>
			
			<?php 
			if (self::$informant_filters){
				echo '<div id="informant_filter_screen" style="display: none;">';
				foreach (self::$informant_filters as $filter){
					echo '<input type="checkbox" data-col="' . htmlspecialchars($filter[0]) . 
					'" data-val="' . htmlspecialchars($filter[1]) . '" data-type="' . htmlspecialchars($filter[2]) 
					. '" data-selected="' . ($filter[3]? '1': '0') . '" /> ' . $filter[4];	
				}
				echo '</div>';
			}
			?>
			
			<div style="float:right; display:inline;">
				<select id="mode" style="background-color:#ffffff;">
					<option value="first"><?php _e('Initial recording', 'tt');?></option>
					<option value="correct"><?php _e('Correction', 'tt');?></option>
					<option value="problems"><?php _e('Problems', 'tt');?> </option>
				</select>
				<img  style="vertical-align: middle;" src="<?php echo VA_PLUGIN_URL . '/images/Help.png';?>" id="helpIconMode" class="helpIcon" />
			</div>
				
			<div class="hidden_coll" id="error"></div>
				
			<div class="informant_details hidden_c" id="input_fields">
				<h3 style="display: inline"><?php _e('Transcription', 'tt');?></h3>
				<a href="#" id="addRow" style="display: inline">(<?php _e('+ Add row', 'tt'); ?>)</a>
				
				<table id="inputTable"></table>
			
				<br />
				
				<input type="button" style="margin-right: 40px;" value="<?php _e('Insert', 'tt');?>" id="insertAttestation"<?php if (!current_user_can(self::$cap_write)) echo ' disabled'; ?> />
				
				<?php 
				foreach (self::$special_val_buttons as $button_data){
					self::echo_extra_button($button_data[0], $button_data[1], $button_data[2]);
				}
				
				self::echo_extra_button(__('Problem', 'tt'), 'problem', $helpProblem);
				?>
			
				<input type="button" id="addConcept" value="<?php _e('Create new concept', 'tt');?>" style="float: right"<?php if (!current_user_can(self::$cap_write)) echo ' disabled'; ?> />
			</div>

			<div id="helpInformants" class="entry-content" style="display: none">
				<?php _e('To select survey points you have the following possibilities', 'tt');?>:
				<ul style="list-style : disc; padding-left : 1em;">
					<li><?php _e('Specify exactly one informant (Example: 252)', 'tt');?></li>
					<li>
						<?php _e('Use wildcards: % means an arbitrary number of characters, _ means exactly one character', 'tt');?>
						<ul style="list-style : circle; padding-left : 2em;">
							<li>8%  -> <?php _e('all points starting with an 8', 'tt'); ?> (81,801,899)</li>
							<li>8_  -> <?php _e('all points with two digits starting with an 8', 'tt'); ?> (80,81,...)</li>
							<li>8__ -> <?php _e('all points with three digits starting with an 8', 'tt'); ?> (800,801,...)</li>
							<li>87_ ->  <?php _e('all points with three digits in which the first one is an 8 and the second one is a 7', 'tt'); ?> (870,871,...)</li>
							<li>% -> alle Punkte</li>
						</ul>
					</li>
				</ul>
				<?php _e('ATTENTION: The usage of wildcards for choosing the informant number *only* makes sense for the intial recording. In correction mode you should enter a concrete informant number.', 'tt');?>
			</div>
			<div id="helpMode" style="display: none">
				<?php _e('There are the following modes', 'tt');?>:
				<ul style="list-style : disc; padding-left : 1em;">
					<li><b style="font-weight: 800;"><?php _e('Initial recording', 'tt');?></b>: <?php _e('Record new data', 'tt');?></li>
					<li><b style="font-weight: 800;"><?php _e('Correction', 'tt');?></b>: <?php _e('Correct existing data or add further attestations for an already processed informant', 'tt');?></li>
					<li><b style="font-weight: 800;"><?php _e('Problems', 'tt');?></b>: <?php _e('Edit existing problems', 'tt');?></li>
				</ul>
			</div>
			
			<div id="helpConcepts" style="display: none">
				<?php _e('Selection of the concept(s) that are assigned to this attestation. In most cases the concepts only depend on the respective stimulus, but on some maps (e.g. AIS#1191_1) also on the informant. The pre-selected concept is the one that was assigned the most. Non fitting concepts can be removed, missing concepts can be added through this box.', 'tt');?>
			</div>
		</div>
	<?php
		va_echo_new_concept_fields('newConceptDialog');
	}
	
	private static function echo_extra_button ($text, $dbvalue, $help){
		echo '<input type="button" value="' . htmlspecialchars($text) . '" class="tt_extra_button" data-dbval="' . htmlspecialchars($dbvalue) . '"' . (current_user_can(self::$cap_write)? '': ' disabled') . '/>';
		if ($help)
			echo self::info_symbol($help);
	}
	
	public static function print_rule_table ($colored = true){
		//error_log(json_encode(self::$mappings));
		$select_sql = 'SELECT t.#Beta#A#, t.#Beta_Example#A#, t.#Position#A#, t.#Description#A#, t.#Comment#A#, t.#Depiction#A# FROM #transcription_rules# t ';
		
		$tmapping = self::$mappings['transcription_rules'];
		$base_chars = self::$db->get_results(self::create_query($select_sql . "WHERE t.#Kind# = '" . $tmapping->get_enum_value('Kind', 'Base')  .  "' ORDER BY t.#Sort_Order# ASC, t.#Beta# ASC"), ARRAY_A);
		$diacritics = self::$db->get_results(self::create_query($select_sql . "WHERE t.#Kind# = '" . $tmapping->get_enum_value('Kind', 'Diacritic')  .  "' ORDER BY t.#Sort_Order# ASC, t.#Beta# ASC"), ARRAY_A);
		$special_chars = self::$db->get_results(self::create_query($select_sql . "WHERE t.#Kind# = '" . $tmapping->get_enum_value('Kind', 'Special')  .  "' ORDER BY t.#Sort_Order# ASC, t.#Beta# ASC"), ARRAY_A);
		$spaces = self::$db->get_results(self::create_query($select_sql . "WHERE t.#Kind# = '" . $tmapping->get_enum_value('Kind', 'Blank')  .  "' ORDER BY t.#Sort_Order# ASC, t.#Beta# ASC"), ARRAY_A);
		
		?>
		<h1 style="text-align: center;"><?php _e('Base characters', 'tt');?></h1>
			
			<table class="ruleTable">
				<thead>
					<tr>
						<th><?php _e('Character', 'tt');?></th>
						<th><?php _e('Description', 'tt');?></th>
						<th><?php _e('Beta code', 'tt');?></th>
						<th><?php _e('Comment', 'tt');?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					foreach ($base_chars as $char){
						$img = self::rule_image($char);
						
						echo '<tr><td class="imageTranscriptionRule"><div>' . $img . '</div></td><td>' 
							. $char['Description'] . '</td><td class="betaTranscriptionRule">' 
							. htmlentities($char['Beta']) . '</td><td>' 
							. $char['Comment'] . '</td></tr>';
					}
					?>
				</tbody>
			</table>
			
			<h1 style="margin-top: 50px; text-align: center; width: 100%;"><?php _e('Diacritics', 'tt');?></h1>
			
			<table class="ruleTable">
				<thead>
					<tr>
						<th><?php _e('Character', 'tt');?></th>
						<th><?php _e('Description', 'tt');?></th>
						<th><?php _e('Beta code', 'tt');?></th>
						<th><?php _e('Comment', 'tt');?></th>
						<th><?php _e('Example', 'tt');?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					foreach ($diacritics as $char){
						$img = self::rule_image($char);
						
						if($char['Beta'] == '{<b><d*>}'){
							$char['Beta'] = '{<b>}';	
						}
						
						echo '<tr style="background: ' 
							. ($colored? self::type_to_bg($char['Position']) : 'white')
							. '"><td class="imageTranscriptionRule"><div>' 
							. $img . '</div></td><td>' . $char['Description'] . '</td><td class="betaTranscriptionRule">' 
							. htmlentities($char['Beta']) . '</td><td>'
							. $char['Comment'] . '</td><td>' 
							. $char['Beta_Example'] . '</td></tr>';
					}
					?>
				</tbody>
			</table>
			
			<h1 style="margin-top: 50px; text-align: center;"><?php _e('Special characters', 'tt');?></h1>
			<div style="margin-left: 5px;"><?php _e('In principle, these characters are equivalent to base characters, except that they cannot be combined with diacritics.', 'tt');?></div>
			
			<table style="margin-top: 10px;" class="ruleTable">
				<thead>
					<tr>
						<th><?php _e('Character', 'tt');?></th>
						<th><?php _e('Description', 'tt');?></th>
						<th><?php _e('Beta code', 'tt');?></th>
						<th><?php _e('Example', 'tt');?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					foreach ($special_chars as $char){
						$img = self::rule_image($char);
						
						echo '<tr><td class="imageTranscriptionRule"><div>' . $img . '</div></td><td>' . $char['Description'] . '</td><td class="betaTranscriptionRule">' . $char['Beta'] . '</td><td>' . $char['Beta_Example'] . '</td></tr>';
					}
					?>
				</tbody>
			</table>
			
			<h1 style="margin-bottom: 5px; margin-top: 50px; text-align: center; width: 100%;"><?php _e('Special Blanks', 'tt');?><br /><span style="font-size: 45%">(<?php _e('Regular blanks are represented by the character ␣ in this table', 'tt');?>)</span></h1>
			<table style="margin-top: 20px;" class="ruleTable">
				<thead>
					<tr>
						<th><?php _e('Character', 'tt');?></th>
						<th><?php _e('Description', 'tt');?></th>
						<th><?php _e('Beta code', 'tt');?></th>
						<th><?php _e('Example', 'tt');?></th>
					</tr>
				</thead>
				<tbody>
					<?php 
					foreach ($spaces as $char){
						if ($char['Beta'] == ' ')
							continue;
						
						$img = self::rule_image($char);
						
						echo '<tr><td class="imageTranscriptionRule"><div>' . $img . '</div></td><td>' . $char['Description'] . '</td><td class="betaTranscriptionRule">' . str_replace(' ', '␣', $char['Beta']) . '</td><td>' . str_replace(' ', '␣', $char['Beta_Example']) . '</td></tr>';
					}
					?>
				</tbody>
			</table>
		<?php 
	}
	
	private static function create_query ($sql, $params = NULL, $no_aliases = false){
		$tables = [];
		
		//Replace table names
		$sql = preg_replace_callback('/(?<= )#([A-Za-z_]+)# ([a-z][1-9]?)/', function ($matches) use (&$tables, $no_aliases){
			if (!isset(TranscriptionTool::$mappings[$matches[1]])){
				throw new Exception('Mapping not found for table: ' . $matches[1]);	
			}
			$mapping = TranscriptionTool::$mappings[$matches[1]];
			$tables[$matches[2]] = $mapping;
			return $mapping->get_table_name() . ($no_aliases? '':  ' ' . $matches[2]);
		}, $sql);
		
		//Replace column names
		$sql = preg_replace_callback('/([a-z][1-9]?)\.#([A-Za-z_]+)#(A#)?/', function ($matches) use (&$tables, $no_aliases){
			if (!isset($tables[$matches[1]])){
				throw new Exception('Unknown table alias : ' . $matches[1]);
			}
			$mapping = $tables[$matches[1]];
			return  ($no_aliases? '' : $matches[1] . '.') . $mapping->get_field_name($matches[2]) . ($matches[3]? (' AS ' . $matches[2]): '');
		}, $sql);
		
		if ($params)
			$sql = self::$db->prepare($sql, $params);

		return $sql;
	}
	
	private static function info_symbol ($info_text){
		return '<img  src="' . plugins_url('', __FILE__) . '/images/Help.png" style="vertical-align: middle;" title="' . $info_text . '" class="infoSymbol" />';
	}

	private static function type_to_bg ($type){
		switch ($type){
			case 'direct':
				return 'LightYellow';
			case 'below':
				return 'DarkSeaGreen';
			case 'above':
				return 'LightBlue';
			case 'after':
				return 'SandyBrown ';
		}
	}

	private static function rule_image ($char){
		if ($char['Depiction']){
			return '<span style="position: relative; vertical-align: middle;">' . $char['Depiction'] . '</span>';
		}
		else {
			$name = $char['Beta'];
			$name = str_replace('<', '', $name);
			$name = str_replace('>', '', $name);
			$name = str_replace('\\', 'bs', $name);
			
			return '<img src="' . plugins_url('/images/', __FILE__) . $name . '.png" />';
		}
	}
	
	static function ajax_handler (){
		if (!self::$initialized){
			echo 'Not initialized!';
			die;
		}
		
		if(current_user_can(self::$cap_read)){
			
			switch ($_REQUEST['query']){
				case 'update_informant':
					echo self::update_informant($_POST['id_stimulus'], $_POST['mode'], $_POST['region'], $_POST['filters']);
				break;
				
				case 'update_grammar':
					$parser = new BetaParser(
						self::$db, 
						self::$mappings['transcription_rules']->get_table_name(), 
						self::$mappings['codepage_original']->get_table_name(), 
						$_POST['atlas']);
					
					$info_file = get_home_path() . self::$document_path . $_POST['atlas'] . '/' . $_POST['atlas'] . '_INFO.pdf';
					$info = file_exists($info_file)? 1: 0;
					
					echo json_encode([$parser->build_js_grammar_string(['COMMENTS']), ($parser->build_js_grammar_string(['UPPERCASE', 'COMMENTS'])), $info]);
					break;
				
				case 'get_map_list':
					$search = '%' . self::$db->esc_like($_REQUEST['search']) . '%';
					
					$sql = self::create_query('SELECT s.#Id_Stimulus#A#, s.#Source#A#, s.#Map_Number#A#, s.#Sub_Number#A#, left(s.#Stimulus#, 50) as Stimulus
							FROM #stimuli# s
							WHERE s.#Source# = %s
							AND (LPAD(s.#Map_Number#, 4, "0") LIKE %s OR left(s.#Stimulus#, 50) LIKE %s)
							ORDER BY special_cast(s.#Map_Number#)', [$_REQUEST['atlas'], $search, $search]);
					
					$scans = self::list_scan_dir($_REQUEST['atlas']);
					
					$result = self::$db->get_results($sql, ARRAY_A);
					$options = [];
					foreach($result as $row) {
						if(isset($scans[$row['Map_Number']])) {
							$scan = $scans[$row['Map_Number']];
							$backgroundcolor="#80FF80";
						}
						else {
							$scan = NULL;
							$backgroundcolor="#fe7266";
						}
						$nameMap = $row['Source'] . '#' . str_pad($row['Map_Number'], 4, '0', STR_PAD_LEFT) . '_' . $row['Sub_Number'] . ' (' . $row['Stimulus'] . ')';
						$options[] = ['id' => $row['Id_Stimulus'] .  ($scan? '|' . $scan : ''), 'text' => $nameMap, 'color' => $backgroundcolor];
					}
					echo json_encode(['results' => $options]);
				break;
				
				case 'get_new_row':
					if(!current_user_can(self::$cap_write))
						break;
				
					echo self::get_table_row($_POST['index']);
				break;
				
				case 'update_transcription':
					self::update_transcription();
				break;
				
				case 'add_lock':
					if ($_REQUEST['context'] == 'Transcription' && !current_user_can(self::$cap_write)){
						echo 'success'; //No locks in readonly mode	
						die;
					}
					
					ob_start();
					self::remove_locks();
					$lmapping = self::$mappings['locks'];
					if(self::$db->insert($lmapping->get_table_name(), [
						$lmapping->get_field_name('Context') => $_REQUEST['context'], 
						$lmapping->get_field_name('Locked_By') => wp_get_current_user()->user_login, 
						$lmapping->get_field_name('Value') => $_REQUEST['value']])){
						
						$res = 'success';
					}
					else {
						$res = 'locked';
					}
					ob_end_clean();
					echo $res;
					break;
					
				case 'remove_lock':
					self::remove_locks();
					break;
			}
		}
		
		die;
	}
	
	private static function remove_locks (){
		self::$db->query(self::create_query("
			DELETE FROM #locks# l
			WHERE (l.#Context# = %s AND l.#Locked_By# = %s) or hour(timediff(l.#Time#, now())) > 0",
			[$_REQUEST['context'], wp_get_current_user()->user_login], true)
		);
	}
	
	private static function update_transcription (){
		if (!current_user_can(self::$cap_write)){
			echo 'No permission to write data!';
			die;
		}
		
		$user_name = wp_get_current_user()->user_login;
		
		if($_REQUEST['mode'] == 'first'){
			
			//Check for existing data if there should be none
			$existing = self::$db->get_var(self::create_query('
							SELECT a.#Id_Attestation#
							FROM #attestations# a
							WHERE a.#Id_Stimulus# = %d AND a.#Id_Informant# = %d', [$_REQUEST['id_stimulus'], $_REQUEST['id_informant']]));
			
			if ($existing){
				error_log(json_encode($_REQUEST)); //TODO
				echo 'There is existing data!';
				die;
			}
		}
		else if (!current_user_can(self::$cap_edit)) {
			
			//Check if user is allowed to change this attestation
			$updated_ids = [];
			
			foreach($_REQUEST['data'] as $row){
				if ($row['id_attestation']){ //There is an old attestation
					$updated_ids[] = $row['id_attestation'];
					
					self::check_not_changed($row, $user_name, 'USER', 'Not allowed to change others attestations');
				}
			}
			
			$existing = self::$db->get_results(self::create_query('
				SELECT a.#Id_Attestation#A#, a.#Transcribed_By#A#
				FROM #attestations# a
				WHERE a.#Id_Stimulus# = %d AND a.#Id_Informant# = %d', [$_REQUEST['id_stimulus'], $_REQUEST['id_informant']]), ARRAY_A);
			
			foreach ($existing as $ex){
				if (!in_array($ex['Id_Attestation'], $updated_ids) && $ex['Transcribed_By'] != $user_name){
					//This attestation would be deleted at the end and is from a different user => update not allowed
					echo 'Not allowed to change others attestations (attestation removed)';
					die;
				}
			}
		}
		
		//Store a list of all new/updated attestations. All old attestations that are not in this list will be removed
		$ids_attestations = [];
		
		$attestation_mapping = self::$mappings['attestations'];
		$conn_mapping = self::$mappings['c_attestation_concept'];
		
		foreach($_REQUEST['data'] as $row){
			
			if ($row['id_attestation']){ //Update of an existing attestation
				
				$tokenized = self::$db->get_var(self::create_query('SELECT a.#Tokenized# FROM #attestations# a WHERE a.#Id_Attestation# = %d', [$row['id_attestation']]));
				
				if ($tokenized){
					self::check_not_changed($row, $user_name, 'TOK', 'Updating tokenized attestation not allowed');
				}
				
				if ($_REQUEST['mode'] == 'first'){
					echo 'Invalid request: Id set for initial recording!';
					die;
				}
				
				$field_updates = [
					$attestation_mapping->get_field_name('Attestation') => stripslashes($row['attestation']),
					$attestation_mapping->get_field_name('Classification') => $row['classification']
				];
				
				$old_val = self::$db->get_var(self::create_query('SELECT a.#Attestation# FROM #attestations# a WHERE a.#Id_Attestation# = %d', [$row['id_attestation']]));
				
				if ($old_val === '<problem>'){
					$field_updates[$attestation_mapping->get_field_name('Transcribed_By')] = $user_name;
				}
				
				$updated = self::$db->update($attestation_mapping->get_table_name(), $field_updates,[
					$attestation_mapping->get_field_name('Id_Attestation') => $row['id_attestation'],
				]);
				
				if ($updated === false){
					echo 'SQL error: "' . self::$db->last_error . '"';
					die;
				}
				
				$id_attestation = $row['id_attestation'];
				
				//Delete exisiting concept assignments
				self::$db->delete ($conn_mapping->get_table_name(), [$conn_mapping->get_field_name('Id_Attestation') => $id_attestation]);
			}
			else  { //New entry
				
				$inserted = self::$db->insert($attestation_mapping->get_table_name(), [
				$attestation_mapping->get_field_name('Id_Stimulus') => $_REQUEST['id_stimulus'],
				$attestation_mapping->get_field_name('Id_Informant') => $_REQUEST['id_informant'],
				$attestation_mapping->get_field_name('Attestation') => stripslashes($row['attestation']),
				$attestation_mapping->get_field_name('Transcribed_By') => $user_name,
				$attestation_mapping->get_field_name('Created') => current_time('mysql'),
				$attestation_mapping->get_field_name('Classification') => $row['classification'],
				$attestation_mapping->get_field_name('Tokenized') => false
				], ['%d', '%d', '%s', '%s', '%s', '%s', '%d']);
				
				if ($inserted === false){
					echo 'SQL error: "' . self::$db->last_error . '"';
					die;
				}
				
				$id_attestation = self::$db->insert_id;
			}
			
			$ids_attestations[] = $id_attestation;
			
			//Add concepts
			if ($row['concepts'] && !self::is_special_val($row['attestation'])){
				foreach ($row['concepts'] as $concept_id){
					
					//Connect concepts
					$inserted = self::$db->insert($conn_mapping->get_table_name(), [
					$conn_mapping->get_field_name('Id_Attestation') => $id_attestation,
					$conn_mapping->get_field_name('Id_Concept') => $concept_id
					], ['%d', '%d']);
					
					if ($inserted === false){
						echo 'SQL error: "' . self::$db->last_error . '"';
						die;
					}
				}
			}
		}
		
		//Get all attestations for this stimulus and informant
		$all_ids = self::$db->get_col(self::create_query('
						SELECT a.#Id_Attestation#
						FROM #attestations# a
						WHERE a.#Id_Stimulus# = %d AND a.#Id_Informant# = %d', [$_REQUEST['id_stimulus'], $_REQUEST['id_informant']]));
		
		//Delete all old attestations
		foreach ($all_ids as $aid){
			if (!in_array($aid, $ids_attestations)){
				self::$db->delete ($conn_mapping->get_table_name(), [$conn_mapping->get_field_name('Id_Attestation') => $aid]);
				self::$db->delete($attestation_mapping->get_table_name(), [$attestation_mapping->get_field_name('Id_Attestation')=> $aid], ['%d']);
			}
		}
		
		echo self::update_informant($_REQUEST['id_stimulus'], $_REQUEST['mode'], $_REQUEST['region'], $_REQUEST['filters']);
	}
	
	private static function is_special_val ($str){
		$str = mb_substr($str, 1, -1); //Strip < and >
		
		if ($str === 'problem')
			return true;
		
		foreach (self::$special_val_buttons as $button_data){
			if ($button_data[1] === $str){
				return true;	
			}
		}
		
		return false;
	}
	
	private static function check_not_changed ($row, $user_name, $context, $msg){
		$old_attestation = self::$db->get_row(self::create_query(
				'SELECT a.#Attestation#A#, a.#Classification#A#, a.#Transcribed_By#A#, a.#Tokenized#A# FROM #attestations# a WHERE a.#Id_Attestation# = %d', [$row['id_attestation']]), ARRAY_A);
		
		
		if ($context == 'TOK'){
			$condition = $old_attestation['Tokenized'];
		}
		else {
			$condition = $old_attestation['Transcribed_By'] != $user_name && $old_attestation['Attestation'] != '<problem>';
		}
		
		if ($condition){
			if ($old_attestation['Attestation'] != $row['attestation'] || $old_attestation['Classification'] != $row['classification']){
				echo $msg . ' (attestation changed)';
				die;
			}
			
			$old_concepts = self::$db->get_col(self::create_query(
					'SELECT c.#Id_Concept# FROM #c_attestation_concept# c WHERE c.#Id_Attestation# = %d', [$row['id_attestation']]));
			
			if (count($old_concepts) != count($row['concepts'])){
				echo $msg . ' (different number of concepts)';
				die;
			}
			
			foreach ($old_concepts as $old_id){
				if (!in_array($old_id, $row['concepts'])){
					echo $msg . ' (different concepts)';
					die;
				}
			}
		}
	}
	
	private static function list_scan_dir($atlas) {

		$atlas = remove_accents($atlas);
		$scan_dir = get_home_path() . self::$document_path . $atlas . '/';
		
		$listing = [];
		
		if ($handle = opendir($scan_dir)) {
			while (false !== ($file = readdir($handle))) {

				if ($file != "." && $file != ".." && mb_strpos($file, $atlas . '#') === 0) {
					$pos_hash = mb_strpos($file, '#');
					$pos_dot = mb_strpos($file, '.pdf');
					$map = mb_substr($file, $pos_hash + 1, $pos_dot - $pos_hash - 1); 

					if(mb_strpos($map, '-') !== false){
						$numbers = explode('-', $map);
						if(ctype_digit($numbers[0]) && ctype_digit($numbers[1])){
							$start = (int) $numbers[0];
							$end = (int) $numbers[1];
							for ($i = $start; $i <= $end; $i++){
								$listing[$i] = $file;
							}
						}
						else {
							$listing[$map] = $file;
						}
					}
					else {
						$listing[$map] = $file;
					}
				}
				
			}
			closedir($handle);
		}

		return $listing;
	}
	
	private static function update_informant ($id_stimulus, $mode, $region, $filters){

		if($mode == 'first'){
			$modeWhere = 'a.#Id_Attestation# is null';
		}
		else if ($mode == 'correct'){
			$modeWhere = 'a.#Id_Attestation# is not null';
		}
		else {
			$modeWhere = "a.#Attestation# = '<problem>'";
		}
		
		$ifilter = '';
		if ($filters){
			foreach ($filters as $filter){
				if ($filter[2] == '%s'){
					$ifilter .= ' AND ' . stripslashes($filter[0]) . ' = "' . esc_sql(stripslashes($filter[1])) . '"' . "\n";
				}
				else {
					$ifilter .= ' AND i.#' . stripslashes($filter[0]) . '# = ' . esc_sql(stripslashes($filter[1])) . "\n";
				}
			}
		}
		
		$sql = self::create_query("
		SELECT 
			s.#Id_Stimulus#A#,
			s.#Source#A#, 
			s.#Map_Number#A#, 
			s.#Sub_Number#A#, 
			s.#Stimulus#A#, 
			i.#Id_Informant#A#,
			i.#Informant_Number#A#, 
			i.#Place_Name#A#,
			a.#Id_Attestation#A#, 
			a.#Attestation#A#, 
			a.#Classification#A#, 
			a.#Transcribed_By#A#,
			a.#Tokenized#A#
		FROM #stimuli# s 
			join #informants# i ON s.#Source# = i.#Source#
			left join #attestations# a ON s.#Id_Stimulus# = a.#Id_Stimulus# AND i.#Id_Informant# = a.#Id_Informant#
		WHERE 
			s.#Id_Stimulus# = %d
			and $modeWhere
			and i.#Informant_Number# like %s
			" . $ifilter . "
		ORDER BY i.#Position# ASC, a.#Id_Attestation# ASC"
		, [$id_stimulus, $region]);

		$attestations = self::$db->get_results($sql, ARRAY_A);
		
		//Use only attestations with the first selected informant id
		$first_id = $attestations[0]['Id_Informant'];
		foreach($attestations as $index => $row){
			if($row['Id_Informant'] != $first_id){
				$break_index = $index;
				break;
			}
		}
		if($break_index)
			$results = array_slice($attestations, 0, $break_index);
		else
			$results = $attestations;

		 if($results[0]["Id_Stimulus"] && $results[0]["Id_Informant"]) {
			foreach ($results as $index => $row){
				if($mode == 'first'){
					//Most frequent concept:
					$sql_concept = self::create_query("
						SELECT c.#Id_Concept#
						FROM #attestations# a JOIN #c_attestation_concept# c ON c.#Id_Attestation# = a.#Id_Attestation#
						WHERE a.#Id_Stimulus# = " . $row["Id_Stimulus"] . " 
						GROUP BY c.#Id_Concept#
						ORDER BY count(*) DESC 
						LIMIT 1");
				}
				else {
					$sql_concept = "
						SELECT c.#Id_Concept# 
						FROM #c_attestation_concept# c JOIN #attestations# a ON c.#Id_Attestation# = a.#Id_Attestation# 
						WHERE a.#Id_Attestation# = '" . $row["Id_Attestation"] . "'";
				}
				$conceptIds = self::$db->get_col(self::create_query($sql_concept));
				$results[$index]['Concept_Ids'] = $conceptIds;
				
				$cannot_be_edited = 
					!current_user_can(self::$cap_edit) && 
					$mode == 'correct' && 
					wp_get_current_user()->user_login !== $row['Transcribed_By'] && 
					$row['Transcribed_By'] != '' && $row['Attestation'] !== '<problem>';
				
				$results[$index]['readonly'] = $cannot_be_edited || $row['Tokenized'];
				ob_start();
				self::get_table_row($index, $row['Id_Attestation'], $mode == 'correct'? ($row['Tokenized'] ? 'TOKENIZED' : $row['Transcribed_By']) : '', $results[$index]['readonly']);
				$results[$index]['html'] = ob_get_clean();
			}
			
			return json_encode($results);
		}
		
		$informant_exists = self::$db->get_var(self::create_query('
			SELECT i.#Id_Informant# 
			FROM #informants# i JOIN #stimuli# s ON s.#Source# = i.#Source# 
			WHERE i.#Informant_Number# like %s AND s.#Id_Stimulus# = %d', [$region, $id_stimulus]));
		
		if($informant_exists){
			
			$not_filtered = self::$db->get_var(self::create_query('
					SELECT i.#Id_Informant#
					FROM #informants# i JOIN #stimuli# s ON s.#Source# = i.#Source#
					WHERE i.#Informant_Number# like %s AND s.#Id_Stimulus# = %d' . $ifilter, [$region, $id_stimulus]));
			
			if($mode == 'first'){
				if ($not_filtered){
					if($region == '%'){
						return self::error_string(__('Everything transcribed!', 'tt'));
					}
					else {
						return self::error_string(__('Already transcribed!', 'tt'));
					}
				}
				else {
					return self::error_string(__('Informant(s) not included by the current filter options!', 'tt'));
				}
			}
			else if($mode == 'correct'){
				if ($not_filtered){
					return self::error_string(__('There is no transcription!', 'tt'));
				}
				else {
					return self::error_string(__('Informant(s) not included by the current filter options!', 'tt'));
				}
			}
			else{
				if ($not_filtered){
					return self::error_string(__('No more problems!', 'tt'));
				}
				else {
					return self::error_string(__('Informant(s) not included by the current filter options!', 'tt'));
				}
			}
		}	
		else {
			return self::error_string(__('Informant number(s) not valid!', 'tt'));
		}
	}
	
	private static function error_string ($str){
		echo '<br><br><div style="color: red; font-size: 100%; font-style: bold;">' . $str . '</div><br>';
	}
	
	private static function get_table_row ($index, $id_attestation = 0, $author = '', $readonly = false){

		?>
	<tr id="inputRow<?php echo $index; ?>" data-index="<?php echo $index; ?>" data-id="<?php echo $id_attestation;?>">
		<td>
			<span class="spanNumber">
				<?php echo $index + 1;?>.) 
			</span>
		</td>
		
		<td>
			<input class="inputStatement" type="text" style="width: calc(60% - 8px)" />
			<span class="previewStatement" style="width: calc(40% - 8px); vertical-align: middle; line-height : 2; display:inline-block; text-overflow : ellipsis; overflow-x:hidden !important;"></span>
		</td>
		
		<td>
			<select class="classification">
				<option value="<?php echo self::$mappings['attestations']->get_enum_value('Classification', 'A'); ?>"><?php _e('attestation', 'tt');?></option>
				<option value="<?php echo self::$mappings['attestations']->get_enum_value('Classification', 'P'); ?>"><?php _e('phon. type', 'tt');?></option>
				<option value="<?php echo self::$mappings['attestations']->get_enum_value('Classification', 'M'); ?>"><?php _e('morph. type', 'tt');?></option>
			</select>
		</td>
		
		<td>
			<select class="conceptList" data-placeholder="<?php _e('Choose Concept(s)', 'tt'); ?>" multiple style="width: 95%"></select>
			<img  style="vertical-align: middle;" src="<?php echo VA_PLUGIN_URL . '/images/Help.png';?>" id="helpIconConcepts" class="helpIcon" />
		</td>
		
		<td>
			<span class="authorSpan">
			<?php
				if($author){
					if ($author == 'TOKENIZED'){
						echo '<b>' . _e('Tokenized', 'tt') . '</b>';
					}
					else {
						echo '<b>' . _e('Transcribed&nbsp;by', 'tt') . ':&nbsp;</b>' . $author;
					}
				}
				?>
			</span>
		</td>
		
		<td>
			<span class="deleteSpan">
				<?php 
				if($index > 0 && !$readonly){
					echo '<a class="remover" href="#">(' . __('Remove&nbsp;row', 'tt') . ')</a>'; 
				}
				?>
			</span>
		</td>
	</tr><?php

	}
}

TranscriptionTool::init_plugin();
?>