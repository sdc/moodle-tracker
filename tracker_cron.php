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
 * A script, to be run via cron, to pull L3VA scores from Leap and generate the MAG, for each student on specifically-tagged courses.
 *
 * @package   ext_cron
 * @copyright 2014 Paul Vaughan
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


// Make this work from a CLI.
define('CLI_SCRIPT', true);

// Sample Leap Tracker API URL.
define('LEAP_TRACKER_API', 'http://172.21.4.85/api.php?hash=%s&id=%s' );
// Another sample Leap Tracker API URL.
//define('LEAP_TRACKER_API', 'http://172.21.11.5:3000/people/%s.json?token=%s' );

require_once 'config.php';
require_once $CFG->dirroot.'/grade/lib.php';

// A little function to make the output look nice.
function tlog($msg, $type = 'ok') {

    $out = ( $type == 'ok' ) ? $type = '[ ok ]' : $type = '['.$type.']';
    echo $out . ' ' . $msg . "\n";

}

// Process the L3VA score into a MAG.
function make_mag($l3va) {

    if ( $l3va == '' || $l3va <= 0 || !$l3va ) {
        return false;
    } else {
        return ( $l3va + 5 );
    }
}

// Process the L3VA score into a TAG.
function make_tag($l3va) {

    if ( $l3va == '' || $l3va <= 0 || !$l3va ) {
        return false;
    } else {
        return ( $l3va + 10 );
    }
}

// Define the wanted column names (will appear in this order in the Gradebook, initially).
$column_names = array(
    'TAG'   => 'Taregt Achievable Grade.',
    'L3VA'  => 'Level 3 Value Added (the new LAT).',
    'MAG'   => 'Minimum Achievable Grade.',
);

// Category details for the above columns to go into.
$cat_name = 'Targets';

// All courses which are appropriately tagged.
$courses = $DB->get_records_select(
    'course',
    "idnumber LIKE '%|leapcore_%|%'",
    null,
    "id ASC",
    'id, shortname'
);

tlog('Started at ' . date( 'c', time() ) . '.', ' go ');
tlog('', '----');


/**
 * Sets up each course tagged with leapcore_ with a category and columns within it.
 */
foreach ($courses as $course) {

    tlog('Processing course ' . $course->shortname . ' (' . $course->id . ').', 'info');

    /**
     * Category checking or creation.
     */
    if ( $DB->get_record( 'grade_categories', array( 'courseid' => $course->id, 'fullname' => $cat_name ) ) ) {
        // Category exists, so skip creation.
        tlog('Category \'' . $cat_name . '\' already exists for course ' . $course->id . '.', 'skip');

    } else {
        // Create a category for this course.
        $grade_category = new grade_category();

        // Course id.
        $grade_category->courseid = $course->id;
        // Set the category name (no description).
        $grade_category->fullname = $cat_name;

        // Save all that...
        if ( !$gc = $grade_category->insert() ) {
            tlog('Category \'' . $cat_name . '\' could not be inserted for course '.$course->id.'.', 'EROR');
            break;
        } else {
            tlog('Category \'' . $cat_name . '\' (' . $gc . ') created for course '.$course->id.'.');
        }
    }

    // We've either checked a category exists or created one, so this *should* always work.
    $cat_id = $DB->get_record( 'grade_categories', array(
        'courseid' => $course->id,
        'fullname' => $cat_name,
    ) );
    $cat_id = $cat_id->id;

    // One thing we need to do (aesthetic reasons) is set 'gradetype' to 0 on that newly created category.
    $DB->set_field_select('grade_items', 'gradetype', 0, "courseid = " . $course->id . " AND itemtype = 'category' AND iteminstance = " . $cat_id);


    /**
     * Column checking or creation.
     */
    // Step through each column name.
    foreach ( $column_names as $col_name => $col_desc ) {

        // Need to check for previously-created columns and skip creation if they already exist.
        if ( $DB->get_record('grade_items', array( 'courseid' => $course->id, 'itemname' => $col_name, 'itemtype' => 'manual' ) ) ) {
            // Column exists, so skip creation.
            tlog(' Column \'' . $col_name . '\' already exists for course ' . $course->id . '.', 'skip');

        } else {
            // Create a new item object.
            $grade_item = new grade_item();

            // Course id.
            $grade_item->courseid = $course->id;
            // Set the category name (no description).
            $grade_item->itemtype = 'manual';
            // The item's name.
            $grade_item->itemname = $col_name;
            // Description of the item.
            $grade_item->iteminfo = $col_desc;
            // Set the immediate parent category.
            $grade_item->categoryid = $cat_id;

            // Don't want it hidden or locked.
            $grade_item->hidden = 0;
            $grade_item->locked = 0;

            // Per-column specifics.
            if ( $col_name == 'TAG' ) {
                $grade_item->sortorder  = 1;
            }
            if ( $col_name == 'L3VA' ) {
                // Lock the L3VA col as it's calculated elsewhere.
                $grade_item->sortorder  = 2;
                $grade_item->locked     = 1;
                $grade_item->decimals   = 0;
            }
            if ( $col_name == 'MAG' ) {
                $grade_item->sortorder  = 3;
            }

            // Save it all.
            
            if ( !$gi = $grade_item->insert() ) {
                tlog(' Column \'' . $col_name . '\' could not be inserted for course ' . $course->id . '.', 'EROR');
            } else {
                tlog(' Column \'' . $col_name . '\' created for course ' . $course->id . '.');
            }

        } // END skip processing if manual column(s) already found in course.

    } // END while working through each rquired column.


    /**
     * Collect enrolments based on each of those courses
     */

    // query Leap for LAT score
    // process into MAG and Tag or whatever
    // insert into gradebook for that course

    // EPIC 'get enrolled students' query from Stack Overflow:
    // http://stackoverflow.com/questions/22161606/sql-query-for-courses-enrolment-on-moodle
    // Works in MySQL(I), I hope it works elsewhere too.
    //$sql = "SELECT DISTINCT c.id AS courseid, c.shortname, u.id AS userid, firstname, lastname
    $sql = "SELECT DISTINCT u.id AS userid, firstname, lastname, username
        FROM mdl_user u
            JOIN mdl_user_enrolments ue ON ue.userid = u.id
            JOIN mdl_enrol e ON e.id = ue.enrolid
            JOIN mdl_role_assignments ra ON ra.userid = u.id
            JOIN mdl_context ct ON ct.id = ra.contextid 
                AND ct.contextlevel = 50
            JOIN mdl_course c ON c.id = ct.instanceid 
                AND e.courseid = c.id
            JOIN mdl_role r ON r.id = ra.roleid 
                AND r.shortname = 'student'
        WHERE courseid = " . $course->id . "
            AND e.status = 0 
            AND u.suspended = 0 
            AND u.deleted = 0
            AND (
                ue.timeend = 0 
                OR ue.timeend > NOW() 
            ) 
            AND ue.status = 0
        ORDER BY courseid ASC, userid ASC;";

    if ( !$enrollees = $DB->get_records_sql( $sql ) ) {
        tlog('No enrolled students found for course ' . $course->id . '.', 'warn');

    } else {

        $num_enrollees = count($enrollees);
        tlog('Found ' . $num_enrollees . ' students enrolled onto course ' . $course->id . '.', 'info');

        foreach ($enrollees as $enrollee) {
            
            // Attempt to extract the student ID from the username.
            $tmp = explode('@', $enrollee->username);
            $enrollee->studentid = $tmp[0];
            if ( strlen( $enrollee->studentid ) != 8 && !is_numeric( $enrollee->studentid ) ) {
                // This is most likely a satff member enrolled as a student, so skip.
                tlog(' Ignoring ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '].', 'skip');
            } else {
                // A proper student, hopefully.
                tlog(' Found ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] on course ' . $course->id . '.', 'info');

                // Assemble the URL with the correct data.
                $leapdataurl = sprintf( LEAP_TRACKER_API, $enrollee->studentid, $CFG->trackerhash );

                // Use fopen to read from the API.
                $handle = fopen($leapdataurl, 'r');
                if (!$handle) {
                    // If the API can't be reached for some reason.
                    tlog(' Cannot open ' . $leapdataurl . '.', 'EROR');

                } else {
                    // API reachable, get the data.
                    $leapdata = fgets($handle);
                    fclose($handle);
//                    tlog('  Returned JSON: ' . $leapdata, 'dbug');

                    // Handle an empty result from the API.
                    if ( strlen($leapdata) == 0 ) {
                        tlog('  API returned 0 bytes.', 'EROR');

                    } else {
                        // Decode the JSON into an object.
                        $leapdata = json_decode($leapdata);

                        // Checking for JSON decoding errors, seems only right.
                        if ( json_last_error() ) {
                            tlog('  JSON decoding returned error code ' . json_last_error() . '.', 'EROR');
                        } else {

                            // Handle any status which is not 'ok' from the API.
//                            if ( $leapdata->status == 'fail' ) {
//                                tlog('  API returned failure.', 'EROR');
//
//                            } else if ( $leapdata->status != 'ok' ) {
//                                tlog('  API returned unexpected status: \'' . $leapdata->status . '\'.', 'EROR');
//
//                            } else {
                                // We have a L3VA score! Woo!
                                //$l3va = $leapdata->data->scores->l3va;
                                $l3va = $leapdata->person->l3va;
                                if ( $l3va == '' || !is_numeric( $l3va ) || $l3va < 0 ) {
                                    // If the L3VA isn't good.
                                    tlog('  L3VA is not good: "' . $l3va . '".', 'warn');

                                } else {

                                    tlog('  ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] L3VA score: ' . $l3va . '.', 'info');

                                    // Make the MAG from the L3VA.
                                    if ( !$mag = make_mag( $l3va ) ) {
                                        // If the MAG failed to generate for some reason.
                                        tlog('   MAG failed to generate.', 'EROR');

                                    } else {
                                        tlog('   MAG: ' . $mag . '.', 'dbug');

                                        /* Loop through all three settable, updateable grades eventually. */

                                        /* Scales to be loaded here too, eventually. */

                                        // Need the grade_items.id for grade_grades.itemid.
                                        $gradeitem = $DB->get_record('grade_items', array( 
                                            'courseid' => $course->id,
                                            'itemname' => 'L3VA'
                                        ), 'id, categoryid');

                                        // Check to see if this data already exists in the database.
                                        $gradegrade = $DB->get_record('grade_grades', array( 
                                            'itemid' => $gradeitem->id,
                                        ), 'id');

                                        // New grade_grade object.
                                        $grade = new grade_grade();
                                        $grade->userid          = $enrollee->userid;
                                        $grade->itemid          = $gradeitem->id;
                                        $grade->categoryid      = $gradeitem->categoryid;
                                        $grade->finalgrade      = $l3va;
                                        $grade->timecreated     = time();
                                        $grade->timemodified    = $grade_l3va->timecreated;

                                        if ( !$gradegrade ) {
                                            // No id exists, so insert.
                                            if ( !$gl = $grade->insert() ) {
                                                tlog('   L3VA insert failed for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'EROR' );
                                            } else {
                                                tlog('   L3VA inserted for user ' . $enrollee->userid . ' on course ' . $course->id . '.' );
                                            }

                                        } else {
                                            // If the row already exists, update.
                                            $grade->id = $gradegrade->id;
                                            
                                            // Sort out the time.
                                            unset( $grade->timecreated );
                                            $grade->timemodified = time();

                                            if ( !$gl = $grade->update() ) {
                                                tlog('   L3VA update failed for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'EROR' );
                                            } else {
                                                tlog('   L3VA update for user ' . $enrollee->userid . ' on course ' . $course->id . '.' );
                                            }
                                        }



//var_dump($grade);

 die();




                                    }

                                } // END L3VA check.

//                            } // END API status.

                        } // END any json_decode errors.

                    } // END empty API result.

                } // END open leap API for reading.

            } // END extracting the student id number.

        } // END cycle through each course enrollee.

    }  // END enrollee query.

    

    // Final blank-ish log entry to separate out one course from another.
    tlog('', '----');

} // END foreach course tagged 'leapcore_*'.

tlog('Finished at ' . date( 'c', time() ) . '.', 'done');
