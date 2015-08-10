<?php
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/value_processor.php");

// TODO  enhance these to allow for map to determine input/output columns
abstract class Data_Output {
    
    protected $inputs_handled_count;
    protected $last_val;
    protected $output_column_name;
    protected $value_processor;
    protected $main_output_table;
    protected $main_table_primary_key_column;
    protected $ignore_in_duplicate_check;
    // this allows for information known about the incoming data to change processing
    // without requiring a re-construction of the Record_Processor that holds this Data_Output
    protected $disabled;
    protected $parent_record_reader;

    function __construct($ignore_in_duplicate_check){
        $this->ignore_in_duplicate_check = $ignore_in_duplicate_check;
        $this->disabled = FALSE;
    }

    function set_parent_record_reader($reader) {
        $this->parent_record_reader = $reader;
    }

    public function modify_current_value($value) {
        $this->parent_record_reader->modify_current_value($value);
    }

    public function set_main_output_table($table) {
        $this->main_output_table = $table;
    }

    public function disable() {
        $this->disabled = TRUE;
    }

    public function enable() {
        $this->disabled = FALSE;
    }

    public function ignore_in_duplicate_check(){
        return $this->ignore_in_duplicate_check;
    }

    public function set_main_table_pk_column($column) {
        $this->main_table_primary_key_column = $column;
    }
 
    function number_of_inputs() { 
        return 1;
    }
    
    public function reset_for_new_row() {
        $this->inputs_handled_count = 0;
    }   
    
    protected function get_current_index() {
        return $this->inputs_handled_count;
    }
    
    abstract function convert_to_output_format($value); 
    abstract function get_last_val();

    // only used for data outputs with a single output column
    function get_output_col_name(){ 
        throw new Exception("This type of data output does not have a single output column name.");
    }
    
    function finished_handling_an_input(){
        $this->inputs_handled_count++;
    }
    
    function can_take_more_input() {
        if ( $this->disabled) return FALSE;
        if ( $this->inputs_handled_count < $this->number_of_inputs())
            return TRUE;
        else
            return FALSE;
    }

    function add_values_to_array(&$val_list) {
        if ( $this->disabled ) return;
        $val_list[] = $this->last_val;
    }

    function add_values_to_assoc_array(&$val_list) {
        if ( $this->disabled ) return;
        $val_list[$this->get_output_col_name()] = $this->get_last_val();
    }

    public function set_last_val($val) {
        $this->last_val = $val[$this->output_column_name];
    }

    // TODO !! - these do not appear to be handling NULL appropriately!
    // need to use IS NULL syntax, NULL does not work with '='
    function duplicate_check_sql_repeated($intermediate_table = "") {
        return $this->gen_dup_check($intermediate_table);
    }

    // optional paramerter allows this functionality to be re-used for reapeated columns, which
    // need to make an intermediate table to check for a records that matches all of the repetions
    function duplicate_check_sql($intermediate_table = "") {
        return $this->gen_dup_check($intermediate_table);
    }

    private function gen_dup_check($intermediate_table) {
        $sql = "";
        if ($intermediate_table != "") 
            $sql = " " . $intermediate_table . ".";
        $sql .= $this->output_column_name;
        $sql .= MySQL_Utilities::quoted_val_or_null_with_comparison_op($this->get_last_val());
        return $sql;
    }

    // this is going to mostly function the same as the can_take_more_input method
    // it will be overridden for Data_Output instances where multiple outputs are
    // allowed, but not a fixed amount
    function expecting_more_input() {
        return $this->can_take_more_input();
    }
}
?>
