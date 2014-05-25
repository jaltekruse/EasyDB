<?php

class Sheet_Processor { 

    private $record_processor;
    // This processor will validate data that does not come from the 'cells' of your
    // primary dataset. It can represent metata like shet filename, uploader, author
    // of the record or the date of upload
    private $external_vals_processor;

    // for delimited test such as CSV
    private $row_separator;
    private $column_separtor;
    
    private $skip_blank_lines;
    private $record_id_column_header;

    function __construct($record_processor, $external_vals_processor) {
        $this->record_processor = $record_processor;
        $this->external_vals_processor;
        $this->row_separator = "\n";
        $this->column_separator = "\t";
        $this->skip_blank_lines = TRUE;
        $this->record_id_column_header = "easydb_record_id";
    }

    function check_for_full_sheet_resubmission($data, $external_columns) {
        
    }

    /*
     * Function to handle input in the form of delimited text with some other fields
     * outside of the main 'sheet'.
     *
     */
    function validate_and_insert_records($data, $external_columns) {
        // TODO - validate and generate sheet external data insert statement fragments
        
        $lines = explode($data, $this->row_separator);
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
            if ($row[0] == $this->record_id_column_header) {
                $handling_resubmitted_records = TRUE;
                // remove the column to allow the user defined header search to avoid
                // having to work around it
                array_unshift($row);
            }

            // The first column that is not blank is considered either the headers or the first
            // column of input data to be read with the default settings
            // This method should be overridden by users to allow them to set read rules based
            // on the column headers
            if ( ! $this->check_for_column_headers($row) ) {
                $lines_to_skip--;
            }
        }

        for ($i = $lines_to_skip; $i < $line_count; $i++) {
            $line = $lines[$i];
			// ignore blank lines
            if (trim($line) == "") continue;

            $row = explode("\t", $line);

        }
                
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
}

?>
