<?php
include_once("value_processor.php");

// TODO  enhance these to allow for map to determine input/output columns
abstract class Data_Output {
    
    protected $inputs_handled_count;
    protected $last_val;
    protected $output_column_name;
    protected $value_processor;
    protected $main_output_table;
    protected $main_table_primary_key_column;
    protected $ignore_in_duplicate_check;

    function __construct($ignore_in_duplicate_check){
        $this->ignore_in_duplicate_check = $ignore_in_duplicate_check;
    }

    public function set_main_output_table($table) {
        $this->main_output_table = $table;
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
        if ( $this->inputs_handled_count < $this->number_of_inputs())
            return TRUE;
        else
            return FALSE;
    }

    function add_values_to_array(&$val_list) {
        $val_list[] = $this->last_val;
    }

    function add_values_to_assoc_array(&$val_list) {
        $val_list[$this->get_output_col_name()] = $this->get_last_val();
    }


    public function set_last_val($val) {
        $this->last_val = $val[$this->output_column_name];
    }

    function duplicate_check_sql_repeated($intermediate_table = "") {
        $sql = "";
        if ($intermediate_table != "") 
            $sql = " " . $intermediate_table . ".";
        $sql .= $this->output_column_name . " = '" . $this->get_last_val() . "' ";
        return $sql;
    }

    // optional paramerter allows this functionality to be re-used for reapeated columns, which
    // need to make an intermediate table to check for a records that matches all of the repetions
    function duplicate_check_sql($intermediate_table = "") {
        $sql = "";
        if ($intermediate_table != "") 
            $sql = " " . $intermediate_table . ".";
        $sql .= $this->output_column_name . " = '" . $this->get_last_val() . "' ";
        return $sql;
    }

    // this is going to mostly function the same as the can_take_more_input method
    // it will be overridden for Data_Output instances where multiple outputs are
    // allowed, but not a fixed amount
    function expecting_more_input() {
        return $this->can_take_more_input();
    }
}

class Single_Column_Output extends Data_Output {

    function __construct($value_processor, $output_column_name, $ignore_in_duplicate_check ){
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_name = $output_column_name;
        $this->value_processor = $value_processor;  
        // TODO - pass down table name from above
        $value_processor->init("dummy_table_name_fixme");
    }

    function get_last_val() {
        return $this->last_val;
    }

    function get_output_col_name(){ 
        return $this->output_column_name; 
    }
 
    function convert_to_output_format($value) {
        try {
            $this->last_val = $this->value_processor->process_value($value); 

            // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history 
            throw $ex;
            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        } 
    }
}

/*
 * Output for a list within a record. Generated either by looking at a series of columns (or series of split
 * combined columns) or a single slit column by passing in the respective data outputs as input to this repeated 
 * column output.
 * 
 * For validation the passed data_outputs will be called repeatedly for a set number of 'repetition cycles', thus 
 * if you are passing this repeated column N data outputs, it will pass values to each of them and expect that the 
 * end of input will fall on the completion of the last data output, or a subsequent use of this data output. 
 *
 * Example, repeated column passed a splitter that produces two columns will expect the number of columns provided 
 * to it to be odd
 */
class Repeated_Column_Output extends Data_Output {

    private $data_outputs;
    private $data_output_count;
    // number of total expected repetitions
    private $repetition_count;
    // number of repetitions that have been completed
    private $current_repetition_count;
    // keeps track of progress inside of a single repetition
    private $current_data_output_index;
    // 2d array, inner arrays are associative one for each repetition
    private $last_vals;
    private $relation_column;
    private $output_table;

    /*
     * takes a list of data outputs to be used repeatedly. Second parameter is optional if the
     * number of repetitions is known. It default to 1000, anything close to this is stretching the
     * usefulness of a relational database and this software for loading data.
     *
     * The relation column is used to hook up each of the child records that represents this repeated column
     * to the parent record in both the duplicate check and insertion.
     */
    function __construct($data_outputs, $repetition_count  = 1000, $relation_column,
                         $output_table, $ignore_in_duplicate_check){
        parent::__construct($ignore_in_duplicate_check);
        $this->data_outputs = $data_outputs;
        $this->data_output_count = count($this->data_outputs);
        $this->repetition_count = $repetition_count;
        $this->relation_column = $relation_column;
        $this->output_table = $output_table;
        $this->last_vals = array();
        for ($i = 0; $i < $this->repetition_count; $i++){
            $this->last_vals[$i] = array();     
        }
    }

    function number_of_repetitions() { 
        return $this->repetition_count;
    }
    
    public function reset_for_new_row() {
        $this->current_data_output_index = 0;
        $this->current_repetition_count = 0;
        $this->data_outputs[0]->reset_for_new_row();
    }   
    
    protected function get_current_index() {
        return $this->current_repetion_count;
    }

    function convert_to_output_format($value) {
        try {
            $this->data_outputs[$this->current_data_output_index]->convert_to_output_format($value);

            if ( ! $this->data_outputs[$this->current_data_output_index]->can_take_more_input()) {
                $this->data_outputs[$this->current_data_output_index]->
                    add_values_to_assoc_array($this->last_vals[$this->current_repetition_count]);
                $this->current_repetition_count++;
                if ($this->current_repetition_count > $this->repetition_count) {
                    return; 
                }
                // not actually moving to new row yet, just re-using this functionality from the non-repeated case
                $this->data_outputs[$this->current_data_output_index]->reset_for_new_row();
                if ( $this->current_data_output_index >= $this->data_output_count) { 
                    $this->current_data_output_index = 0;
                }
                $this->data_outputs[$this->current_data_output_index]->reset_for_new_row();
            }

        } catch (Exception $ex ) {
            // store error in upload history, add new column to store message about error passed back
            throw new Exception("Exception processing value: " . $ex->getMessage());
        }
    }

    function get_last_val(){
        throw new Exception("Unsupported operation");
    }

    function duplicate_check_sql($unused_intermediate_table = NULL) {
        $pk_col = $this->main_table_primary_key_column;
        $sql = "";
        for ($i = 0; $i < $this->current_repetition_count; $i++) {
            $temp_table = $this->output_table . $i;
            $sql .= " INNER JOIN " . $this->output_table . " AS " . $temp_table . " ON " . $temp_table . 
                "." . $pk_col . " = " . $this->main_output_table. "." . $pk_col . " ";
            $column_checks = array();
            foreach ($this->data_outputs as $data_output) { 
                $data_output->set_last_val($this->last_vals[$i]);
                $column_checks[] = $data_output->duplicate_check_sql_repeated($temp_table);
            }
            $sql .= " AND " . implode(" AND ", $column_checks);
        }
        return $sql;
    }

    function generate_insert_sql($last_insert_id) {
        $sql_statements = array();
        $last_vals;
        for ($i = 0; $i < $this->current_repetition_count; $i++) { 
            $last_vals = $this->last_vals[$i];
            $last_vals[$this->main_table_primary_key_column] = $last_insert_id;
            $sql_statements[] = Record_Processor::insert_sql_based_on_assoc_array(
                $last_vals, $this->output_table);
        }
        return $sql_statements;
    }

    function can_take_more_input() {
        if ($this->current_repetition_count < $this->repetition_count) 
            return TRUE;
        else
            return FALSE;
    }

    function add_values_to_array(&$val_list) {
        foreach ($this->last_vals as $val) {
            foreach ($val as $sub_val){
                $val_list[] = $sub_val;
            }
        }
    }

    function add_values_to_assoc_array(&$val_list) {
        $val_list[$this->get_output_col_name()] = $this->get_last_val();
    }

    // this is going to mostly function the same as the can_take_more_input method
    // it will be overridden for Data_Output instances where multiple outputs are
    // allowed, but not a fixed amount
    function expecting_more_input() {
        return $this->can_take_more_input();
    }
}

class Column_Splitter_Output extends Data_Output {

    private $output_column_names;
    private $value_processors;
    private $value_processor_count;
    private $last_vals;
    private $delimiter;

    public function get_last_val(){
        throw new Exception("Operation unsupported for column splitter.");
    }

    function add_values_to_array(&$val_list) {
        foreach ($this->last_vals as $value ) { 
            $val_list[] = $value;
        }
    }

    function add_values_to_assoc_array(&$val_list) {
        $i = 0;
        foreach ($this->last_vals as $value ) { 
            $val_list[$this->output_column_names[$i]] = $value;
            $i++;
        }
    }

    function __construct($value_processors, $output_column_names, $delimiter, $ignore_in_duplicate_check){
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_names = $output_column_names;
        $this->value_processors = $value_processors;
        $this->delimiter = $delimiter;
        foreach($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme");
        }
        $this->value_processor_count = count($this->value_processors);
    }
    
    function number_of_inputs() { 
        return $this->value_processor_count;
    }

    function split_value($value) {
        return explode($this->delimiter, $value);
    }

    public function set_last_val($val) {
        $this->last_vals = $val;
    }

    function duplicate_check_sql_repeated($intermediate_table = "") {
        $sql_wheres = array();
        for($i = 0; $i < $this->value_processor_count; $i++) {
            if ($intermediate_table != "") 
                $sql = " " . $intermediate_table . ".";
            else
                $sql = "";
            if ( is_null($this->last_vals[$this->value_processors[$i]->get_column()]) )
                $value = "NULL";
            else
                $value = "'" . $this->last_vals[$this->value_processors[$i]->get_column()] . "'"; 
            $sql .= $this->value_processors[$i]->get_column() . " = " . $value . " ";
            $sql_wheres[] = $sql;
        }
        return implode(" AND ", $sql_wheres);
    }

    function duplicate_check_sql($intermediate_table = "") {
        $sql_wheres = array();
        for($i = 0; $i < $this->value_processor_count; $i++) {
            if ($intermediate_table != "") 
                $sql = " " . $intermediate_table . ".";
            else
                $sql = "";
            if ( is_null($this->last_vals[$i]) )
                $value = "NULL";
            else
                $value = "'" . $this->last_vals[$i] . "'"; 
            $sql .= $this->value_processors[$i]->get_column() . " = " . $value . " ";
            $sql_wheres[] = $sql;
        }
        return implode(" AND ", $sql_wheres);
    }

    function convert_to_output_format($value) {
        try {
            $curr_vals = $this->split_value($value);
            if (count($curr_vals) == $this->value_processor_count){
                foreach ( $curr_vals as $split_val ) {
                    $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($split_val); 

                    // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
                    $this->finished_handling_an_input();
                }
            } else {
                foreach ( $curr_vals as $split_val ) {
                    $this->last_vals[$this->get_current_index()] = $split_val; 

                    // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
                    $this->finished_handling_an_input();
                }
            }
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history 
            throw $ex;
            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }

}

class Column_Combiner_Output extends Data_Output {

    private $value_processors;
    private $value_processor_count;
    private $last_vals;


    function get_last_val() {
        return implode(" ", $this->last_vals);
    }


    function add_values_to_assoc_array(&$val_list) {
        $val_list[$this->output_column_name] = $this->get_last_val();
    }

    function add_values_to_array(&$val_list) {
        $val_list[] = $this->get_last_val();
    }

    function __construct($value_processors, $output_column_name, $ignore_in_duplicate_check){
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_name = $output_column_name;
        $this->value_processors = $value_processors;  
        foreach($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme");
        }
        $this->value_processor_count = count($this->value_processors);
    }

    function number_of_inputs() { 
        return $this->value_processor_count;
    }

    function convert_to_output_format($value) {
        try {
            $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($value); 

            // TODO - finally blocks are only in PHP 5.5+, this is a hackt get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
           // TODO - make this report an error to the user and store it in the upload history 

            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }
}

?>
