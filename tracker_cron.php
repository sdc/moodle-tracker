<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Edit the grade options for an individual grade item
 *
 * @package   core_grades
 * @copyright 2007 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


// Make this work from a CLI.
define('CLI_SCRIPT', true);

require_once 'config.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/lib.php';

// Define the wanted column names (will appear in this order in the Gradebook, initially).
$column_names = array(
    'TAG'   => 'Taregt Achievable Grade.',
    'L3VA'  => 'Level 3 Value Added (the new LAT).',
    'MAG'   => 'Minimum Achievable Grade.',
);

// Category details for the above columns to go into.
$cat_name = 'Targets';

// Just this course for now.
$courseid = 3;
// In the future, ALL courses which exist in the DB except 1 and a (manual) blacklist?

// Get the course.
if ( !$course = $DB->get_record( 'course', array( 'id' => $courseid ) ) ) {
    //print_error('nocourseid');
    echo 'No course with ID ' . $courseid . ' could be found in the database.';
} else {

    /**
     * Category checking or creation.
     */
    if ( $DB->get_record( 'grade_categories', array( 'courseid' => $courseid, 'fullname' => $cat_name ) ) ) {
        // Category exists, so skip creation.
        echo 'Manual category ' . $cat_name . ' already exists for course ' . $courseid . ', so skipping.'."\n";
    } else {
        // Create a category for this course.
        $grade_category = new grade_category( array( 'courseid' => $courseid ), false);
        //$grade_category->apply_default_settings();
        //$grade_category->apply_forced_settings();

        // Set the category name (no description).
        $grade_category->fullname = $cat_name;

        // Set the grading type to 'none' (0).
        //$grade_category->gradetype = 0;
        $grade_category->grade_item_gradetype = 0;

        // Save all that...
        $grade_category->insert();
        echo 'Manual category ' . $cat_name . ' created for course '.$courseid.".\n";
    }

    // We've either checked a category exists or created one, so this *should* always work.
    $cat_id = $DB->get_record( 'grade_categories', array( 'courseid' => $courseid, 'fullname' => $cat_name ) );
    $cat_id = $cat_id->id;

    // One thing we need to do is set 'gradetype' to 0 on that newly created categoty.
    //$DB->set_field_select('event', 'eventtype', 'due', "eventtype = '' AND courseid != 0 AND groupid = 0 AND (modulename = 'assignment' OR modulename = 'assign')");
    $DB->set_field_select();


    /**
     * Column checking or creation.
     */
    // Step through each column name.
    foreach ( $column_names as $col_name => $col_desc ) {

        // Need to check for previously-created columns and skip creation if they already exist.
        if ( $DB->get_record('grade_items', array( 'courseid' => $courseid, 'itemname' => $col_name ) ) ) {
            echo 'Manual column ' . $col_name . ' already exists for course ' . $courseid . ', so skipping.'."\n";
        } else { 

            // Create a new item object. 
            $grade_item = new grade_item( array( 'courseid' => $courseid, 'itemtype' => 'manual' ), false );

            // The item's name.
            $grade_item->itemname = $col_name;
            // Description of the item.
            $grade_item->iteminfo = $col_desc;

            // Set the immediate parent category.
            $grade_item->categoryid = $cat_id;

            // Nothing is hidden.
            $grade_item->hidden = 0;
            // Nothing is locked by default.
            $grade_item->locked = 0;

            // Per-column specifics.
            if ( $col_name == 'TAG' ) {
                //
            }
            if ( $col_name == 'L3VA' ) {
                // Lock the L3VA col as it's calculated elsewhere.
                $grade_item->locked = 1;
                $grade_item->decimals = 0;
            }
            if ( $col_name == 'MAG' ) {
                //
            }

            // Not sure if want.
            //$grade_item->itemtype = 'manual';

            // Save it all, probably.
            $grade_item->insert();
            echo 'Manual column ' . $col_name . ' created for course '.$courseid.".\n";

        } // END skip processing if manual column(s) already found in course.

    } // END while working through each rquired column.

} // END find course in the database.
