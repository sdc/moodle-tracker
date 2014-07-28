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

// Script start time.
$time_start = microtime(true);

tlog('Started at ' . date( 'c', $time_start ) . '.', ' go ');
tlog('', '----');

// Make this work from a CLI.
define( 'CLI_SCRIPT', true );

// Sample Leap Tracker API URL.
//define( 'LEAP_TRACKER_API', 'http://172.21.4.85/api.php?hash=%s&id=%s' );
define( 'LEAP_TRACKER_API', 'http://localhost/api.php?hash=%s&id=%s' );
// Another sample Leap Tracker API URL.
//define('LEAP_TRACKER_API', 'http://172.21.11.5:3000/people/%s.json?token=%s' );

// Define the scale types and numbers (taken from mdl_scales).
$course_type_scales = array(
    'leapcourse_alevel'     => 1, // A Level scale: A B C D E U.
    'leapcourse_btec'       => 2, // BTEC scale: Pass, Merit, Distinction.
    'leapcourse_functional' => 3, // Complete scale: Not Complete, Partially Complete, Complete.
    'leapcourse_gcse'       => 4, // GCSE scale: A B C D E F U.
    'leapcourse_vrq'        => 5, // Pass scale: Refer, Pass.
    'leapcourse_percent'    => 6, // 0-100%? Requested but not allocated to a course type.
);

// Number of decimal places in the processed targets.
define( 'DECIMALS', 3 );

// Debugging.
define( 'DEBUG', true );

require_once 'config.php';
require_once $CFG->dirroot.'/grade/lib.php';

// Check for the required config setting in config.php.
if ( !$CFG->trackerhash ) {
    tlog('$CFG->trackerhash not set in config.php.', 'EROR');
    exit(1);
}

// A little function to make the output look nice.
function tlog($msg, $type = 'ok') {

    $out = ( $type == 'ok' ) ? $type = '[ ok ]' : $type = '['.$type.']';
    echo $out . ' ' . $msg . "\n";

}

// Process the L3VA score into a MAG.
function make_mag_tag($in, $type) {

    if ( $in == '' || !is_numeric($in) || $in <= 0 || !$in ) {
        return false;
    }

    if ( $type == '' || $type == 'mag' ) {
        // Calculate the MAG (default).
        $out = $in + ( $in * .099 );
        return number_format( $out, DECIMALS );

    } else if ( $type == 'tag' ) {
        // Calculate the TAG.
        $out = $in + ( $in * .247 );
        return number_format( $out, DECIMALS );

    } else {
        return false;
    }
}

// Define the wanted column names (will appear in this order in the Gradebook, initially).
$column_names = array(
    'TAG'   => 'Target Achievable Grade.',
    'L3VA'  => 'Level 3 Value Added (the new LAT).',
    'MAG'   => 'Minimum Achievable Grade.',
);

// Make an array keyed to the column names to store the grades in.
$targets = array();
foreach ( $column_names as $name => $desc ) {
    $targets[strtolower($name)] = '';
}

// Category details for the above columns to go into.
$cat_name = 'Targets';

// All courses which are appropriately tagged.
$courses = $DB->get_records_select(
    'course',
    "idnumber LIKE '%|leapcore_%|%'",
    null,
    "id ASC",
    'id, shortname, idnumber'
);
if ( !$courses ) {
    tlog('No courses tagged \'leapcore_*\' found, so halting.', 'EROR');
    exit(0);
}

/**
 * Sets up each course tagged with leapcore_ with a category and columns within it.
 */
foreach ($courses as $course) {

    tlog('Processing course ' . $course->shortname . ' (' . $course->id . ').', 'info');

    // Figure out the scale here. Scales are defined at the top (based on mdl_scale.id) and applied based on how the course is tagged.
    $scaleid = 0;
    foreach ( $course_type_scales as $type => $scale) {
        if ( stripos( $course->idnumber, $type ) ) {
            tlog('Course type \'' . $type . '\' found for course ' . $course->id . '.' );
            $scaleid = $scale;
            break;
        }
    }
    if ( !$scaleid ) {
        tlog('No course type \'leapcourse_*\' found for course ' . $course->id . ', so no scale could be set.', 'warn' );
    }

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
            exit(1);
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

    // One thing we need to do (aesthetic reasons) is set 'gradetype' to 0 on that newly created category, which prevents a category total showing.
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

            // Don't want it hidden or locked (by default).
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
                $grade_item->locked     = 1;
            }

            // Scale ID, generated earlier. An int, 0 or greater.
            $grade_item->scale = $scaleid;

            // Save it all.
            if ( !$gi = $grade_item->insert() ) {
                tlog(' Column \'' . $col_name . '\' could not be inserted for course ' . $course->id . '.', 'EROR');
                exit(1);
            } else {
                tlog(' Column \'' . $col_name . '\' created for course ' . $course->id . '.');
            }

        } // END skip processing if manual column(s) already found in course.

    } // END while working through each rquired column.


    /**
     * Collect enrolments based on each of those courses
     */

    // EPIC 'get enrolled students' query from Stack Overflow:
    // http://stackoverflow.com/questions/22161606/sql-query-for-courses-enrolment-on-moodle
    // Works in MySQL(I), I hope it works elsewhere too.
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

        // A variable to store which employee we're processing.
        $cur_enrollees = 0;
        foreach ($enrollees as $enrollee) {
            $cur_enrollees++;

            // Attempt to extract the student ID from the username.
            $tmp = explode('@', $enrollee->username);
            $enrollee->studentid = $tmp[0];
            if ( strlen( $enrollee->studentid ) != 8 && !is_numeric( $enrollee->studentid ) ) {
                // This is most likely a satff member enrolled as a student, so skip.
                tlog(' Ignoring (' . $cur_enrollees . '/' . $num_enrollees . ') ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '].', 'skip');
            } else {
                // A proper student, hopefully.
                tlog(' Processing (' . $cur_enrollees . '/' . $num_enrollees . ') ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] on course ' . $course->id . '.', 'info');

                // Assemble the URL with the correct data.
                $leapdataurl = sprintf( LEAP_TRACKER_API, $CFG->trackerhash, $enrollee->studentid );
                if ( DEBUG ) {
                    tlog('  Leap URL: ' . $leapdataurl, 'dbug');
                }

                // Use fopen to read from the API.
                $handle = fopen($leapdataurl, 'r');
                if (!$handle) {
                    // If the API can't be reached for some reason.
                    tlog(' Cannot open ' . $leapdataurl . '.', 'EROR');

                } else {
                    // API reachable, get the data.
                    $leapdata = fgets($handle);
                    fclose($handle);

                    if ( DEBUG ) {
                        tlog('  Returned JSON: ' . $leapdata, 'dbug');
                    }

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
                                //$targets['l3va'] = $leapdata->data->scores->l3va;
                                $targets['l3va'] = number_format( $leapdata->person->l3va, DECIMALS );
                                if ( $targets['l3va'] == '' || !is_numeric( $targets['l3va'] ) || $targets['l3va'] < 0 ) {
                                    // If the L3VA isn't good.
                                    tlog('  L3VA is not good: \'' . $targets['l3va'] . '\'.', 'warn');

                                } else {

                                    tlog('  ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] L3VA score: ' . $targets['l3va'] . '.', 'info');

                                    // Make the MAG from the L3VA.
                                    $targets['mag'] = make_mag_tag( $targets['l3va'] );
                                    $targets['tag'] = make_mag_tag( $targets['l3va'], 'tag' );

                                    if ( DEBUG ) {
                                        tlog('   MAG: ' . $targets['mag'] . '. TAG: ' . $targets['tag'] . '.', 'dbug');
                                    }

                                    // Loop through all three settable, updateable grades.
                                    foreach ( $targets as $target => $score ) {

                                        // Need the grade_items.id for grade_grades.itemid.
                                        $gradeitem = $DB->get_record('grade_items', array( 
                                            'courseid' => $course->id,
                                            'itemname' => strtoupper( $target ),
                                        ), 'id, categoryid');

                                        // Check to see if this data already exists in the database, so we can insert or update.
                                        $gradegrade = $DB->get_record('grade_grades', array( 
                                            'itemid' => $gradeitem->id,
                                        ), 'id');

                                        // New grade_grade object.
                                        $grade = new grade_grade();
                                        $grade->userid          = $enrollee->userid;
                                        $grade->itemid          = $gradeitem->id;
                                        $grade->categoryid      = $gradeitem->categoryid;
                                        $grade->finalgrade      = $score;
                                        $grade->timecreated     = time();
                                        $grade->timemodified    = $grade->timecreated;

                                        if ( !$gradegrade ) {
                                            // No id exists, so insert.
                                            if ( !$gl = $grade->insert() ) {
                                                tlog('   ' . strtoupper( $target ) . ' insert failed for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'EROR' );
                                            } else {
                                                tlog('   ' . strtoupper( $target ) . ' (' . $score . ') inserted for user ' . $enrollee->userid . ' on course ' . $course->id . '.' );
                                            }

                                        } else {
                                            // If the row already exists, update.

                                            // Don't *update* the TAG, ever.
                                            if ( $target != 'tag' ) {
                                                $grade->id = $gradegrade->id;

                                                // We don't want to set this again, but we do want the modified time set.
                                                unset( $grade->timecreated );
                                                $grade->timemodified = time();

                                                if ( !$gl = $grade->update() ) {
                                                    tlog('   ' . strtoupper( $target ) . ' update failed for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'EROR' );
                                                } else {
                                                    tlog('   ' . strtoupper( $target ) . ' (' . $score . ') update for user ' . $enrollee->userid . ' on course ' . $course->id . '.' );
                                                }

                                            } else {
                                                tlog('   ' . strtoupper( $target ) . ' purposefully not updated for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'skip' );

                                            } // END ignore updating the TAG.

                                        } // END insert or update check.

                                    } // END foreach loop.

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

// Finish time.
$time_end = microtime(true);

$duration = $time_end - $time_start;

tlog('Finished at ' . date( 'c', $time_end ) . ', took ' . number_format( $duration, 3 ) . ' seconds.', 'done');

exit(0);
