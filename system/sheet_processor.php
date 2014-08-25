<?php
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/record_processor.php");
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/db_util/mysql_util.php");

// TODO - remove all references to MBED specific tables and features
class Sheet_Processor { 

    protected $record_processor;
    // This processor will validate data that does not come from the 'cells' of your
    // primary dataset. It can represent metata like shet filename, uploader, author
    // of the record or the date of upload
    protected $external_vals_processor;

    // for delimited text such as CSV
    private $row_separator;
    private $column_separtor;
    
    private $skip_blank_lines;
    private $record_id_column_header;
    private $disable_duplicate_check;

    private $record_table;
    private $upload_attempt_table;
    private $current_time;
    private $db;
    // These values are used to set a maximum percentage or records allowed to be duplicate or errors
    // and still allow them to be added to the upload history. Sheets that exceed these thresholds will
    // have to be reviewed and modified by uploaders before they re-uploaded
    private $resubmit_dup_count_threshold;
    private $max_error_threshold;
    private $use_upload_history;

    function __construct($db, $record_processor, $external_vals_processor, $use_upload_history = TRUE) {
        $this->record_processor = $record_processor;
        $this->external_vals_processor = $external_vals_processor;
        $this->db = $db;
        $this->row_separator = "\n";
        $this->column_separator = "\t";
        $this->skip_blank_lines = TRUE;
        $this->disable_duplicate_check = FALSE;
        $this->record_id_column_header = "record_id";
        $this->record_table = 'records';
        $this->upload_attempt_table = 'upload_attempts';
        $this->resubmit_dup_count_threshold = 0.5;
        $this->max_error_threshold = 0.5;
        $this->use_upload_history = $use_upload_history;
    }

    function check_for_full_sheet_resubmission($data, $external_columns) {
        
    }

    function disable_duplicate_check(){
        $this->disable_duplicate_check = TRUE;
    }

    function enable_duplicate_check() {
        $this->disable_duplicate_check = FALSE;
    }

    /*
     * This method allows for users to detect if the current row representats the headers for the sheet
     * and handle them accordingly. For some users the sheets may take several formats from changing requirements
     * over the time of their data tracking. This allows them to set specific read rules based on their headers.
     *
     * This method should return true or false if the headers are detected, this prompts the reading of regular
     * data.
     */
    function check_for_column_headers($row) {
        return false;
    }

    function get_last_insert_time() {
        return $this->current_time;
    }

    /*
     * Function to handle input in the form of delimited text with some other fields
     * outside of the main 'sheet'.
     *
     */
    function validate_and_insert_records($data, $external_columns) {
        // this time is used as the upload time for all records processed in this input dataset
        // this allows for gathering data that was uploaded at the same time, which would be less
        // precise if the queries for each insertion used a call to the sql NOW() function
        $result = $this->db->query("SELECT NOW() as time");
        $this->current_time = "";
        if ( $result){
            $row = $result->fetch_assoc();
            $this->current_time = $row['time'];
        }
        else{
            throw new Exception("Error finding current time to store with uploads.");
        }
        $external_columns['upload_date'] = $this->current_time;
        // TODO - validate and generate sheet external data insert statement fragments
        // external columns are assumed to be in an associative array, as they are not part of a sheet
        // we will not want them to have to come in a specific order
        if ( ! is_null($this->external_vals_processor) ) {
            $this->external_vals_processor->process_row_assoc($external_columns);
            $this->record_processor->set_sheet_external_fields_and_data(
                $this->external_vals_processor->generate_columns_and_data_lists(), $this->external_vals_processor->output_to_assoc_array());
        }
        // upload history also uses external columns, but does not need the upload_date in the array
        unset($external_columns['upload_date']);
        $lines = explode( $this->row_separator, $data);
        $handling_resubmitted_records = FALSE;
        // keep track of the number of lines that should be skipped at the beginning of the sheet
        // including blanks and the header
        $lines_to_skip = 0;
        // read the input line by line
        foreach ($lines as $line) {
            $lines_to_skip++;
            if (trim($line) == "" && $this->skip_blank_lines)
                continue;
            $row = explode($this->column_separator, $line);

            // check if the column header for reading re-sumbitted data was reached, the column
            // name chosen for the header of the records id column should not appear in the dataset!
            // especially in the first column as this will corrupt the first line of input
            if (trim($row[0]) == $this->record_id_column_header) {
                $handling_resubmitted_records = TRUE;
                // remove the column to allow the user defined header search to avoid
                // having to work around it
                array_shift($row);
            }

            // The first column that is not blank is considered either the headers or the first
            // column of input data to be read with the default settings
            // This method should be overridden by users to allow them to set read rules based
            // on the column headers
            if ( ! $this->check_for_column_headers($row) ) {
                $lines_to_skip--;
                break;
            }
        }

        // TODO - add a test for this
        // process the sheet once to see what percentage of the records return as duplicates, if it is beyond
        // the threshold set the sheet is assumed to be incorrectly re-uploaded by a user and is rejected before anything
        // is added to the database
        $dup_count = 0;
        $error_count = 0;
        if ( ! $this->disable_duplicate_check && ! $handling_resubmitted_records) {
            $this->add_sheet_processing_metadata($external_columns);
            $line_count = count($lines);
            for ($i = $lines_to_skip; $i < $line_count; $i++) {
                $record_id = '';
                $line = $lines[$i];
                // ignore blank lines
                if (trim($line) == "") continue;

                $row = explode("\t", $line);
                try {
                    $this->record_processor->process_row($row);
                } catch (Exception $ex) {
                    $error_count++;
                    continue;
                }
                $dup_check = $this->record_processor->generate_duplicate_check();
                $result = $this->db->query($dup_check);
                if ( ! $result ) $dev_err .=  "ERROR WITH DUP CHECK!! : " . $this->db->error;
                else {
                    if ($result->num_rows > 0) {
                        $dup_count++;
                        continue;
                    }
                }
            }
            if ( (float) $dup_count / ($line_count - $error_count) > $this->resubmit_dup_count_threshold ) {
                throw new Exception("High percentage of duplicates found, assuming errant sheet re-upload."
                    . " Nothing new was added to the upload history or final dataset.");
            }
            if ( (float) $error_count / ($line_count ) > $this->max_error_threshold ) {
                throw new Exception("High percentage of errors found, check the datasheet and any additional information submitted while uploading for accuracy. "
                    . " Nothing new was added to the upload history or final dataset.");
            }
        }

        // this method is used to add columns that are needed to process the records if they are re-submitted,
        // but are not stored as part of the records themselves. Currenty this is just used to add the repetition
        // counts for repeated columns. This call is placed here so the sheet processors can use the 
        // check_for_column_headers function to detect the sheet headers and adjust the column counts an necessary.
        // TODO - figure out if the way this works could be cleaner
        // TODO - this the information must be stored in the subclass between the two method calls for now, this may
        // change in the future
        $this->add_sheet_processing_metadata($external_columns);
        $line_count = count($lines);
        for ($i = $lines_to_skip; $i < $line_count; $i++) {
            $record_id = '';
            $line = $lines[$i];
			// ignore blank lines
            if (trim($line) == "") continue;

            $row = explode("\t", $line);
            if ($handling_resubmitted_records) {
                $record_id = trim(array_shift($row));
            }

			// save this line in the history of uploads, all uploaded raw data is saved as well as application output
			$record_id = $this->save_upload_attempt_in_history($row, '', '', '0', $record_id, $external_columns);	
			if ($record_id == false){
				continue; // there was an issue with the record_id
            }

            try {
                $this->record_processor->process_row($row);
                //echo 'result of processing: ';
                //print_r($this->record_processor->output_to_array());
                //echo '<br>';
            } catch (Exception $ex) {
                // TODO - figure out what to do with errors if no upload history being used
                // TODO - delete me
                $to_save = $this->record_processor->get_last_input_row();
                // add back the record id
                array_unshift($to_save, $record_id);
	            $this->save_upload_attempt_in_history($to_save, 'error', $ex->getMessage(), '1', $record_id, $external_columns);
                continue;
            }
            if ( ! $this->disable_duplicate_check ) {
                $dup_check = $this->record_processor->generate_duplicate_check();
                $result = $this->db->query($dup_check);
                if ( ! $result ) $dev_err .=  "ERROR WITH DUP CHECK!! : " . $this->db->error;
                else {
                    if ($result->num_rows > 0) {
                        $to_save = $this->record_processor->get_last_input_row();
                        // add back the record_id
                        array_unshift($to_save, $record_id);
                        $this->save_upload_attempt_in_history($to_save, 'duplicate', '', '1', $record_id, $external_columns);
                        continue;
                    }
                }
            }
            $this->record_processor->process_row($row);
            // TODO - check if this record ID is already in the dataset, if it is delete the child records, issue an update
            // statement instead of an insert and re-add the children
            $check_previous_sucessful_upload_query = "select observation_id from scan_observations where record_id = " . MySQL_Utilities::quoted_val_or_null($record_id);
            $result = $this->db->query($check_previous_sucessful_upload_query);
            if ( ! $result) {
                // TODO - handle this
                // error connecting to the database;
            } else {
                if ( $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $observation_id = $row['observation_id'];
                    $delete_query = 'delete from neighbors where observation_id = ' . MySQL_Utilities::quoted_val_or_null($observation_id);
                    $result = $this->db->query($delete_query);
                    if ( ! $result )
                        echo 'Failed to delete neighbors for old record. ' . $this->db->error;
                    $delete_query = 'delete from scan_observations where observation_id = ' . MySQL_Utilities::quoted_val_or_null($observation_id);
                    $result = $this->db->query($delete_query);
                    if ( ! $result )
                        echo 'Failed to delete previous version of record. ' . $this->db->error;
                } 
            }
            $new_observation_id = $this->record_processor->insert();
            if ($this->use_upload_history) {
                $result = $this->db->query("update scan_observations set record_id = '" . $record_id . "' where observation_id = '" . $new_observation_id . "'");	
                if ( $result ){ } // success
                else{
                    echo "Error linking uploaded record to upload history: " .  $this->db->error . "<br>";
                }
            }
            // need to allow changes in some of the columns to impact the actual row data that will be saved
            // after processing, such as with columns that are supposed to fill down the sheet, but we will not
            // necessarily be processing the record above it the provided the values when we are going to correct
            // a record in error at a later time (as the preivous records may have been without errors)
            $this->save_upload_attempt_in_history($this->record_processor->get_last_input_row(), 'successful add', '', '1', $record_id, $external_columns);
        } 
    }

    protected function add_sheet_processing_metadata(&$external_columns){
    }

    // TODO - deal with invalid record numbers passed in
    // TODO - refactor sql query generation to use library functions defined elsewhere
    function save_upload_attempt_in_history($input, $status, $notes, $is_webapp_output, $record_id, $record_fields){
        if ( ! $this->use_upload_history) {
            return true;
        }
        $uploader_id = $record_fields['uploader_id'];
        if (is_null($record_id) || ! ctype_digit(trim($record_id))){
            unset($record_fields['uploader_id']);
            $record_insert_query = MySQL_Utilities::insert_sql_based_on_assoc_array($record_fields, 'records');
            $result = $this->db->query($record_insert_query);	
            if ($result){
                $record_id = $this->db->insert_id;
            }
            else{
                echo "error adding record to upload history:" . $this->db->error;
            }
        }
        $sql = "select record_id from records where record_id = '" . $record_id . "'";
        $result = $this->db->query($sql);
        if ( $result && $result->num_rows == 1){
            ;// successful add of record, or recall of correct previous id
        }
        else{
            // TODO - figure out how to report these to the users
            //$dev_err .= "Could not find record with the given ID:" . $this->db->error . "<br>\n";
            // TODO - make this a class level variable?
            //$invalid_record_numbers .= $record_id . ", ";
            return false; // do not do the next query, it will fail due to a bad foreign key
        }
        $fields_and_data = array('upload_date' => $this->current_time , 'uploader_id' => $uploader_id , 
            'record_id' => $record_id, 'is_webapp_output' => $is_webapp_output, 'status' => $status, 'upload_notes' => $notes );
        for($i = 1; $i <= count($input); $i++){
            $fields_and_data[ "col_" . $i ] = $input[$i - 1];
        }
        $prepared_fields_and_data = MySQL_Utilities::generate_columns_and_data_lists_from_assoc($fields_and_data);
        $sql = 'INSERT INTO upload_attempts (' . $prepared_fields_and_data['fields'] . ') values (' . $prepared_fields_and_data['data'] . ')'; 
        $result = $this->db->query($sql);
        if (! $result){
            echo "Error adding upload attempt: " . $this->db->error . " - " . $sql;
        }
        return $record_id;
    }
}

?>
