<?php
class TableMapping {
	private $new_name;
	
	private $field_mappings;
	private $enum_mappings;
	
	public function __construct ($new_name, $field_mappings = NULL, $enum_mappings = NULL){
		$this->new_name = $new_name;
		
		$this->field_mappings = $field_mappings?: [];
		$this->enum_mappings = $enum_mappings?: [];
	}
	
	public function get_table_name (){
		return $this->new_name;
	}
	
	public function get_field_name ($field){
		if (isset($this->field_mappings[$field])){
			return $this->field_mappings[$field];
		}
		else {
			return $field;
		}
	}
	
	public function get_enum_value ($field, $val){
		$field_name = $this->get_field_name($field);

		if (!isset($this->enum_mappings[$field])){
			return $val;
		}
		
		if (isset($this->enum_mappings[$field][$val])){
			return $this->enum_mappings[$field][$val];
		}
		else {
			return $val;
		}
	}
}