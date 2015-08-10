<?php

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

    // TODO - fix this!! this is used currently to store when the fist blank appears
    // so we know where to stop generating child records rather than trying to insert nulls
    private $blank_at_pos;

    private $columns_that_cannot_be_null;

    /*
     * takes a list of data outputs to be used repeatedly.
     *
     * The relation column is used to hook up each of the child records that represents this repeated column
     * to the parent record in both the duplicate check and insertion.
     *
     * TODO - implement columns_that_cannot_be_null
     */
    function __construct($data_outputs, $repetition_count, $relation_column,
                         $output_table, $ignore_in_duplicate_check, $columns_that_cannot_be_null = array() ){
        parent::__construct($ignore_in_duplicate_check);
        $this->data_outputs = $data_outputs;
        $this->data_output_count = count($this->data_outputs);
        $this->repetition_count = $repetition_count;
        $this->relation_column = $relation_column;
        $this->output_table = $output_table;
        $this->last_vals = array();
        $this->columns_that_cannot_be_null = $columns_that_cannot_be_null;
        for ($i = 0; $i < $this->repetition_count; $i++){
            $this->last_vals[$i] = array();
        }
    }

    function set_parent_record_reader($reader) {
        $this->parent_record_reader = $reader;
        foreach ($this->data_outputs as $data_output) {
            $data_output->set_parent_record_reader($this->parent_record_reader);
        }
    }

    public function set_number_of_repetitions($count) {
        $this->repetition_count = $count;
    }

    function number_of_repetitions() {
        return $this->repetition_count;
    }

    public function reset_for_new_row() {
        $this->current_data_output_index = 0;
        $this->current_repetition_count = 0;
        $this->blank_at_pos = -1;
        $this->data_outputs[0]->reset_for_new_row();
    }

    protected function get_current_index() {
        return $this->current_repetion_count;
    }

    function convert_to_output_format($value) {
        // TODO allow blanks in reptitions
        if ($this->blank_at_pos == -1 && ($value == "" || is_null($value))){
            $this->blank_at_pos = $this->current_repetition_count;
        }
        try {
            $this->data_outputs[$this->current_data_output_index]->convert_to_output_format($value);

            if ( ! $this->data_outputs[$this->current_data_output_index]->can_take_more_input()) {
                // TODO - allow users to specify which columns can be null, right now no nulls are allowed in
                // repetitions
                //print_r($this->last_vals[$this->current_repetition_count]);
                if (in_array(NULL, $this->last_vals[$this->current_repetition_count], true)) {
                    if ($this->blank_at_pos == -1){
                        $this->blank_at_pos = $this->current_repetition_count;
                    }
                }
                $this->data_outputs[$this->current_data_output_index]->
                add_values_to_assoc_array($this->last_vals[$this->current_repetition_count]);
                //echo 'in repeated output, curr_output index(1):' . $this->current_data_output_index . ' rep count ' . $this->repetition_count . '<br>';
                $this->current_data_output_index++;
                if ($this->current_data_output_index == $this->data_output_count) {
                    $this->current_data_output_index = 0;
                    $this->current_repetition_count++;
                }
                if ($this->current_repetition_count > $this->repetition_count) {
                    return;
                }

                /*
                foreach ($this->data_outputs as $output) {
                    echo get_class($output) . ',';
                }
                 */
                //echo "index in output list: " . $this->current_data_output_index . '<br>';
                // not actually moving to new row yet, just re-using this functionality from the non-repeated case
                $this->data_outputs[$this->current_data_output_index]->reset_for_new_row();
                //echo 'in repeated output, curr_output index:' . $this->current_data_output_index . '<br>';
                if ( $this->current_data_output_index >= $this->data_output_count) {
                    $this->current_data_output_index = 0;
                }
                $this->data_outputs[$this->current_data_output_index]->reset_for_new_row();
            }

        } catch (Exception $ex ) {
            // TODO - not sure if there are other things to do here
            throw $ex;
        }
    }

    function get_last_val(){
        $last_vals = array();
        for ($i = 0; $i < $this->current_repetition_count &&
        ($i < $this->blank_at_pos || $this->columns_that_cannot_be_null);  $i++) {
            $last_val = $this->last_vals[$i];
            foreach ($last_val as $key => $value) {
                if ( is_null($value) && array_search($key, $this->columns_that_cannot_be_null) !== FALSE){
                    continue 2;
                }
            }
            $last_vals[] = $this->last_vals[$i];
        }
        //print_r($last_vals);
        return $last_vals;
    }

    // TODO !! - these do not appear to be handling NULL appropriately!
    // need to use IS NULL syntax, NULL does not work with '='
    // TODO - modify this to correctly check for duplicates where there are values that appear more
    // than once. right now a releated list of ("J", "J", "AD") will incorrectly report as a duplicate for ("J", "AD")
    function duplicate_check_sql($unused_intermediate_table = NULL) {
        $pk_col = $this->main_table_primary_key_column;
        $sql = "";
        for ($i = 0; $i < $this->current_repetition_count && $i < $this->blank_at_pos; $i++) {
            $temp_table = $this->output_table . $i;
            $sql .= " INNER JOIN " . $this->output_table . " AS " . $temp_table . " ON " . $temp_table .
                "." . $pk_col . " = " . $this->main_output_table. "." . $pk_col . " ";
            $column_checks = array();
            foreach ($this->data_outputs as $data_output) {
                $data_output->set_last_val($this->last_vals[$i]);
                $column_checks[] = $data_output->duplicate_check_sql_repeated($temp_table);
                // TODO - this is a bit of a hack, think about how to bring togethet standard and repeated
                // duplicate check generation
                $data_output->set_last_val(array());
            }
            $sql .= " AND " . implode(" AND ", $column_checks);
        }
        return $sql;
    }

    function generate_insert_sql($extra_fields_and_data = NULL) {
        //echo "gen sql statements in repeated data output, blank at:" . $this->blank_at_pos;
        $sql_statements = array();
        $last_vals;
        for ($i = 0; $i < $this->current_repetition_count; $i++) {
            if ( $this->blank_at_pos != -1 && $i > $this->blank_at_pos) {
                break;
            }
            //print_r($this->last_vals);

            $last_vals = $this->last_vals[$i];

            if ($this->columns_that_cannot_be_null) {
                foreach ($last_vals as $key => $value) {
                    if ( is_null($value) && array_search($key, $this->columns_that_cannot_be_null) !== FALSE){
                        // TODO - should an exception be thrown here?
                        continue 2;
                    }
                }
            }
            // going to handle this at a level up as it can be added to the new more general extra fields and data parameter
            $sql_statements[] = MySQL_Utilities::insert_sql_based_on_assoc_array(
                $last_vals, $this->output_table, $extra_fields_and_data);
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
        if ( $this->disabled ) return;
        foreach ($this->last_vals as $val) {
            foreach ($val as $sub_val){
                $val_list[] = $sub_val;
            }
        }
    }

    function add_values_to_assoc_array(&$val_list) {
        if ( $this->disabled ) return;
        $val_list[$this->output_table] = $this->get_last_val();
    }

    // this is going to mostly function the same as the can_take_more_input method
    // it will be overridden for Data_Output instances where multiple outputs are
    // allowed, but not a fixed amount
    function expecting_more_input() {
        return FALSE;
    }
}
?>
