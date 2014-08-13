<?php
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/data_output.php");

class Record_Processor {

    // defines the sequence of outputs to be used, current interface uses numerical positions
    // of the columns in the input data set. Will be adding interface later to allow matching
    // column names to different value processors (which are nested in data outputs)
    private $data_outputs;

    // Map from ouput name to numerical index of output in the data_outputs array.
    // This allows for referring to outputs by name, without relying on the technically undefined
    // ordering or elements within an associative array. Thus we can loop through the outputs for
    // processing data that is comming in a specific order (without moving the data into an associative
    // array beforehand) but after processing the data outputs can be referred to by their names
    // for doing cross column valiation without leaving another place in the code to break when the column
    // orderings are changed
    private $data_output_names;

    // to allow for efficient processing into sql queries, additional references to the data
    // outputs are stored on creation of this Record_Processor
    private $repeated_outputs;
    private $non_repeated_outputs;

    // the system allows for metadata stored outside of the sheet to be added to each record
    // this data only needs to be validated once, so here the columns and data to be added to the
    // insert statements are stored for concatination into the sql queries
    private $sheet_external_fields_and_data;
    
    private $data_outputs_count;
    private $output_table;
    private $primary_key_column;
    private $user_config;
    private $insert_db;

    // The last row of raw data is stored here to allow it to be inserted in the upload history if one of the
    // validators fails. This does mean that the values will be re-processed upon an upload of the ammended
    // record, but it removes the complexity of having to store which fields have already been validated
    // (it might save a little processing if we stored the numeric foreign keys of code values for example, but
    // notating that some column represent raw data and some present processed data would likely be more
    // difficult and possibly even take more processing time in the end)
    private $last_input_row;
    
    // In a few rare cases we need to change the raw input from a validator to allow for correct processing
    // of error correcting uploads in the future. The current use case for this feature is the value repeating
    // validator. This is desiged to handle cases where values are left out of a sheet, and the most recent non-
    // blank value is expected to cascade down the dataset. In this case to handle subsequent uploads of error
    // corrections on columns that originally contain some of these blanks, we will fill in the blanks with the
    // appropriate values copied down from above into the raw input array for this row to be inserted into the
    // upload history.
    private $index_in_input_row;

    // boolean that is set if an error occurs
    private $last_input_row_errored;

    // the character to add to the end of a column if it is the one in error, defaults to '*'
    private $error_char;

    // a record processor in typical execution assumes the creation of a primary record and possibly a set of child
    // records that link to it with a foreign key relationship. This allows disabling the primary record insertion,
    // to enable a single record to be processed into a series of child rows.
    // This can be accomplished by simply re-formatting the data for each 
    private $no_primary_record_insertion;

    // A list of columns that should be added to each child record from the primary columns. These are specified to
    // allow for some data to be redundantly stored in the parents and children (not reccomended) or to allow processing
    // of a single 'record' that describes a series of entries in a many to many table.
    //
    // Example:
    // --------
    // Employees Table:         employee_id, name, gender, department
    // Tasks Table:             task_id, task_name, start_date, description
    // Task Assignments Table:  employee_id, task_id
    //
    // This allows for muliple emplyees to be assigned to one task, if a single task were related to a single employee,
    // the employee_id could simply be added as an attribute of each task.
    //
    // This field allows for the following format to be processed into the task assigments table, this assumes tasks and
    // employees have already been created
    //
    // employee_id_1, task_id_1, task_id_2, task_id_3, task_id_4, ...
    // employee_id_2, task_id_3, task_id_2, task_id_7, task_id_8, ...
    //
    // In this case the employee_id needs to be added to each child record, but does not appear next to each of the child
    // task_id values. This breaks the model for a Repeated_Column_Output, necessitating an extension of record assembly
    // here.
    //
    // Format : associative array relating the Repeated_Column_Output names to a list of columns to push into the repective child table for
    // that repeater
    // { employee_tasks : [ 'employee_id' ] }
    private $primary_columns_to_push_into_children;
    
    public function get_outputs() {
        return $this->data_outputs;
    }

    public function get_last_input_row_errored() {
        return $this->last_input_row_errored;
    }

    public function get_last_input_row() {
        return $this->last_input_row;
    }

    /**
      * Takes an associative array for parameters.
      *
      * Fields: 
      * -------
      * data_outputs - list of Data_Output objects
      * TODO - this will simply work by using the positions of the columns and match them with the
      * inputs (taking into account data_outputs that use up several columns). This should be changed
      * to allow the columns to be moved around and passed an associative array (data will also need to 
      * be send associated with column names, and processors will need to know the names of the columns to process) 
      * extra_fields - to be appended after the columns provided in the main records
      *     This can be used to store information about the datasheet, uploader, observer, or anything that can
      *     be applied to the whole sheet to save the time of copying it into each row manually
      */
    function __construct($parameters){
        assert( isset($parameters['data_outputs'])); // "Must supply data outputs for Record_Processor."
        assert( isset($parameters['output_table'])); //"Must supply output table for Record_Processor.");
        assert( isset($parameters['primary_key_column']));//"Must supply primary key column for Record_Processor.");
        assert( isset($parameters['user_config']), "Must supply user config for Record_Processor.");
        // TODO - make this configurable
        $this->error_char = "*";
        $this->user_config = $parameters['user_config'];
        $this->output_table = $parameters['output_table'];
        $this->data_outputs = $parameters['data_outputs'];
        if (isset($parameters['data_output_names']))
            $this->data_output_names = $parameters['data_output_names'];
        if (isset($parameters['no_primary_record_insertion']))
            $this->no_primary_record_insertion = $parameters['no_primary_record_insertion']; 
        if (isset($parameters['primary_columns_to_push_into_children']))
            $this->primary_columns_to_push_into_children = $parameters['primary_columns_to_push_into_children']; 
        $this->data_outputs_count = count($this->data_outputs);
        $this->primary_key_column = $parameters['primary_key_column']; 
        $this->repeated_outputs = array();
        $this->non_repeated_outputs = array();
        $this->insert_db = $this->user_config->get_database_connection('read_write');
        MySQL_Utilities::set_db($this->insert_db);

        // TODO - absolutely ugly hack, need to figure out a better way to organize this
        // solution is to map the columns at the top of the sheet to data outputs, this will be a
        // little interesting for repeated columns, but for a basic implementation that says column label X
        // indicates the start of this repeated column should be alright
        $reversed_data_output_name_map = array();
        if ( ! is_null($this->data_output_names)) {
            foreach ($this->data_output_names as $name => $index) {
                $reversed_data_output_name_map[$index] = $name;
            }
        }
        //print_r($reversed_data_output_name_map);
        foreach ($this->data_outputs as $index => $data_output) {
            $data_output->set_parent_record_reader($this);
            $data_output->set_main_output_table($this->output_table);
            $data_output->set_main_table_pk_column($this->primary_key_column);
            if ($data_output instanceof Repeated_Column_Output) {
                if ($reversed_data_output_name_map) {
                    $this->repeated_outputs[$reversed_data_output_name_map[$index]] = $data_output; 
                }
                else
                    $this->repeated_outputs[] = $data_output; 
            }
            else {
                if ($reversed_data_output_name_map)
                    $this->non_repeated_outputs[$reversed_data_output_name_map[$index]] = $data_output; 
                else
                    $this->non_repeated_outputs[] = $data_output;
            }
        }
        $this->index_in_input_row = NULL;
    }

    function set_sheet_external_fields_and_data($sheet_external_fields_and_data) {
        $this->sheet_external_fields_and_data = $sheet_external_fields_and_data;
    }

    function set_repeated_data_output_repetition_count($output_name, $repetitions) {
        // TODO - make this safer, if this is called on a non-repeated column I believe it will throw
        // a fatal error
        $this->data_outputs[$this->data_output_names[$output_name]]->set_number_of_repetitions($repetitions);
    }

    private function start_row($row) {
        $this->last_input_row = $row;
    }

    // TODO - enable modify_current_value for this method
    function process_row_assoc($row) {
        $this->start_row($row);
        $last_input_row_errored = FALSE;
        $curr_data_output = NULL;
        foreach ($row as $field => $value) {
            if ( ! ( isset($this->data_output_names[$field]) &&
                isset($this->data_outputs[$this->data_output_names[$field]] ) ) ) {
                // the input contained a field not matching a data output
                // for now ignore it, possibly design a system for warnings
                continue;
            }
            $curr_data_output = $this->data_outputs[$this->data_output_names[$field]];
            $curr_data_output->reset_for_new_row();
            if ( ! is_array($value) ) {
                // TODO - decide what to do here
                // this check is here to handle cases where a user mistakenly passes data associated
                // with a data output designed not to take anything, might want to just ignore it instead
                // of throwing the error
                if ($curr_data_output->can_take_more_input())
                    $curr_data_output->convert_to_output_format($value);
                else {
                    $last_input_row_errored = TRUE;
                    $this->place_error_char($i);
                    throw new Exception("Input not expected for '" . $field . "', but some was provided."); 
                }
            }
            else {
                foreach ($value as $parts) {
                    if ($curr_data_output->can_take_more_input()) {
                        $curr_data_output->convert_to_output_format($value);
                    } else {
                        $last_input_row_errored = TRUE;
                        $this->place_error_char($i);
                        throw new Exception("Too much input provided for '" . $field . "'.");
                    }
                }
            }
        }
    }

    /*
     * This should only be called from within the loops in process_row or process_row_assoc
     *
     * TODO modify this to work with associative arry processing
     */
    public function modify_current_value($value) {
        //debug_print_backtrace();
        assert($this->index_in_input_row !== NULL); // "can only modify current value while processing a row");
        $this->last_input_row[$this->index_in_input_row] = $value;
    }

    function process_row($row) {
        $this->start_row($row);
        $this->data_outputs[0]->reset_for_new_row();
        $too_much_input = FALSE;
        $index = 0;
        $row_len = count($row);
        for ($this->index_in_input_row = 0; $this->index_in_input_row < $row_len; $this->index_in_input_row++){
            $value = $row[$this->index_in_input_row];
            //echo 'val in record processor:' . $value . '<br>';
            try {
                if ( ! $this->data_outputs[$index]->can_take_more_input()) {
                    $index++;
                    if ( $index >= $this->data_outputs_count) { 
                        $too_much_input = $value; break;
                    }
                    $this->data_outputs[$index]->reset_for_new_row();
                }

                $this->data_outputs[$index]->convert_to_output_format($value);
    
            } catch (Exception $ex ) {
                // store error in upload history, add new column to store message about error passed back
                $last_input_row_errored = TRUE;
                $this->place_error_char($this->index_in_input_row);
                throw $ex;
            }
        }
        // TODO - re-enable this when it can handle extra input that is just spaces without saying there is an error
        if ($too_much_input !== FALSE) {
            //$last_input_row_errored = TRUE;
            //$this->place_error_char($this->index_in_input_row);
            return;
            // TODO - re-enable this
            //throw new Exception("Unexpected extra input at the end of row, starting at '" . $too_much_input . "'");
        }
        // check to make sure we recieved all the input we expected
        if ($this->data_outputs[$index]->expecting_more_input()){
            $last_input_row_errored = TRUE;
            $this->place_error_char($this->index_in_input_row);
            throw new Exception("Not all expected values were provided.");
        }
        $this->index_in_input_row = NULL;
    }

    private function place_error_char($index) {
        $this->last_input_row[$index] = rtrim($this->last_input_row[$index]) . $this->error_char;
    }

    /*
     * this is used by the instances of record processor that are handling sheet external data
     * they do not generate entire sql statements, but instead column and values lists as strings
     * to be injected into other records.
     *
     * TODO - think about separating the public interfaces for sheet processors and external
     *        data processors, possibly have them inherit from a this common subclass with
     *        common functionality declared private or protected. For each subclass different
     *        methods that need to be public can be wrapped in public methods.
     */
    function generate_columns_and_data_lists() {
        return $this->generate_columns_and_data_lists_from_assoc($this->output_to_assoc_array());
    }

    function generate_columns_and_data_lists_from_assoc($output_array) {
        $lists = array();
        // I know array_keys and array_valus exist, but I could not find a definite answer if they are
        // both guarenteed to return values in the same order
        //echo "asdferjjkdsfher231243===============<br>";
        //print_r($output_array);
        foreach ( $output_array as $field => $value) {
            $lists['fields'][] = $field;
            $lists['data'][] = MySQL_Utilities::quoted_val_or_null($value);
        }
        $lists['fields'] = "`" . implode('`, `', $lists['fields']) . "`";
        $lists['data'] = implode(', ', $lists['data']);
        return $lists;
    }

    //=============================================
    // TODO - PREPARED STATEMENTS
    // ============================================
    function generate_duplicate_check() {
        $sql = "select * from " . $this->output_table . ' ';
        $repeated_output_checks = array();
        $standard_column_checks = array();
        foreach ($this->data_outputs as $data_output) {
            if ($data_output instanceof Repeated_Column_Output) {
                $repeated_output_checks[] = $data_output->duplicate_check_sql(NULL);
            } else {
                // passing table name to get rid of 'column ambiguity' errors
                if ( ! $data_output->ignore_in_duplicate_check()) {
                    $standard_column_checks[] = $data_output->duplicate_check_sql($this->output_table);
                }
            }
        }
        $sql .= implode(' ', $repeated_output_checks);
        $sql .= ' WHERE ' . implode(' AND ', $standard_column_checks);
        return $sql;
    }

    function insert() {
        $db = $this->insert_db;
        $main_reocrd_id = -1;
        if ( ! $this->no_primary_record_insertion ) {
            $insert_sql = $this->insert_main_record_sql();
            $result = $db->query($insert_sql);
            $main_record_id = $db->insert_id;
            if (! $result) {
                // TODO - throw error
                echo $insert_sql . '<br>';
                echo "Error with main record insert:" . $db->error . "<br>\n";
            }
        }
        $child_insert_sql = $this->insert_child_record_sql($main_record_id);
        foreach ($child_insert_sql as $sql){
            $result = $db->query($sql);
            if (! $result) {
                // TODO - throw error with the parent ID so we can repair or re-upload it
                // TODO - allow parents and children to be inserted as part of a single transaction
                // would elmintate the need to clean up parents incorrectly missing children
                //print_r($this->last_input_row);
                echo "error with child record insertion:" . $db->error . "<br>\n";
            }
        }
        return $main_record_id;
    }

    function insert_main_record_sql(){
        $sql_statements = array();
        $values = array();
        foreach ($this->non_repeated_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($values);
        }
        return MySQL_Utilities::insert_sql_based_on_assoc_array($values, $this->output_table, 
            $this->sheet_external_fields_and_data);
    }

    /*
     * Get the SQL required to insert the current record. The return of the function is
     * an array of statements to execute, one for each of the child rows to insert.
     */
    function insert_child_record_sql($last_insert_id){
        //echo "========= insert child in record processor";
        $sql_statements = array();
        // TODO - this is redundant with part of the method above, may want to move it
        $values = array();
        foreach ($this->non_repeated_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($values);
        }
        //echo "!!!!!!!!!!!!";
        //print_r($this->primary_columns_to_push_into_children);
        foreach ($this->repeated_outputs as $key => $data_output) {
            $push_down_cols = array();
            // TODO change this next line to push down the primary key if needed
            
            if ( ! $this->no_primary_record_insertion ) {
                $push_down_cols[$this->primary_key_column] = $last_insert_id;
            }
            if ( ! is_null($this->primary_columns_to_push_into_children)) {
                foreach ($this->primary_columns_to_push_into_children[$key] as $column_to_push_down) {
                    $push_down_cols[$column_to_push_down] = $values[$column_to_push_down];
                }
            }

            $extra_fields = $this->generate_columns_and_data_lists_from_assoc($push_down_cols);
            $sql_statements = array_merge($sql_statements, $data_output->generate_insert_sql($extra_fields));
        }
        //echo "%%%%%%%%";
        return $sql_statements;
    }

    function output_to_array() {
        $output = array();
        foreach ($this->data_outputs as $data_output) {
            $data_output->add_values_to_array($output);
        }
        return $output;
    }

    function output_to_assoc_array(){
        $output = array();
        foreach ($this->data_outputs as $data_output) {
            $data_output->add_values_to_assoc_array($output);
        }
        return $output;
    }
}

?>
