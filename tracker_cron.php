<?php

/**
 * A script, to be run via cron, to pull L3VA scores from Leap and generate
 * the MAG, for each student on specifically-tagged courses, and add it into
 * our live Moodle.
 *
 * Add to admin/cli/ and run via cron.
 *
 * @copyright 2014 Paul Vaughan
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * TODO:    Consider $thiscourse as an array, not an integer.
 */

// Script start time.
$time_start = microtime(true);

// Null or an int (course's id): run the script only for this course. For testing or one-offs.
$thiscourse = null; // null or e.g. 1234

$version    = '1.0.8';
$build      = '20140916';

tlog( 'GradeTracker script, v' . $version . ', ' . $build . '.', 'hiya' );
tlog( 'Started at ' . date( 'c', $time_start ) . '.', ' go ' );
if ( $thiscourse ) {
    tlog( 'IMPORTANT! Processing only course \'' . $thiscourse . '\'.', 'warn' );
}
tlog( '', '----' );

// Make this work from a CLI.
define( 'CLI_SCRIPT', true );

// Sample Leap Tracker API URL.
define( 'LEAP_TRACKER_API', 'http://leap.southdevon.ac.uk/people/%s.json?token=%s' );

// Number of decimal places in the processed targets (and elsewhere).
define( 'DECIMALS', 3 );

// Debugging.
define( 'DEBUG', false );

// Search term to use when searching for courses to process.
define( 'IDNUMBERLIKE', 'leapcore_%' );
//define( 'IDNUMBERLIKE', 'leapcore_test' );

// Category details for the above columns to go into.
define( 'CATNAME', 'Targets' );

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once $CFG->dirroot.'/grade/lib.php';

// Check for the required config setting in config.php.
if ( !$CFG->trackerhash ) {
    tlog( '$CFG->trackerhash not set in config.php.', 'EROR' );
    exit(1);
}

// Logging array for the end-of-script summary.
$logging = array(
    'courses'           => array(),     // For each course which has been processed (key is id).
    'students'          => array(),     // For each student who has been processed.
    'grade_types'       => array(       // Can set these, but they'll get created automatically if they don't exist.
        'btec'              => 0,       // +1 for each BTEC course.
        'a level'           => 0,       // +1 for each A Level course.
        // For the sake of not causing PHP Notices, added the following:
        'refer and pass'    => 0,
        'noscale'           => 0,
        'develop, pass'     => 0,
    ),
    'poor_grades'       => array(),     // An entry for each student with a E, F, U, Refer, etc.

    'num'               => array(
        'courses'           => 0,       // Integer number of courses processed.
        'students'          => 0,       // Integer number of students processed.
        'grade_types'       => 0,       // Integer number of grade types (should be the same as courses - relevant?).
        'poor_grades'       => 0,       // Integer number of poorly-graded students processed.
    ),
);

$l3va_data = array(
    'leapcore_a2_artdes'        => array('m' => 4.4727, 'c' => 98.056),
    'leapcore_a2_artdesphoto'   => array('m' => 4.1855, 'c' => 79.949),
    'leapcore_a2_artdestext'    => array('m' => 3.9430, 'c' => 66.967),
    'leapcore_a2_biology'       => array('m' => 5.2471, 'c' => 166.67),
    'leapcore_a2_busstud'       => array('m' => 4.8372, 'c' => 123.41),
    'leapcore_a2_chemistry'     => array('m' => 4.5169, 'c' => 129.00),
    'leapcore_a2_englishlang'   => array('m' => 4.5773, 'c' => 112.14),
    'leapcore_a2_englishlit'    => array('m' => 5.0872, 'c' => 137.31),
    'leapcore_a2_envsci'        => array('m' => 6.1058, 'c' => 196.66),
    'leapcore_a2_envstud'       => array('m' => 6.1058, 'c' => 196.66),
    'leapcore_a2_filmstud'      => array('m' => 4.0471, 'c' => 76.470),
    'leapcore_a2_geography'     => array('m' => 5.4727, 'c' => 156.16),
    'leapcore_a2_govpoli'       => array('m' => 5.3215, 'c' => 145.38),
    'leapcore_a2_history'       => array('m' => 4.6593, 'c' => 118.98),
    'leapcore_a2_humanbiology'  => array('m' => 5.2471, 'c' => 166.67), // Copied from biology.
    'leapcore_a2_law'           => array('m' => 5.1047, 'c' => 140.69),
    'leapcore_a2_maths'         => array('m' => 4.5738, 'c' => 119.43),
    'leapcore_a2_mathsfurther'  => array('m' => 4.4709, 'c' => 106.40),
    'leapcore_a2_media'         => array('m' => 4.2884, 'c' => 90.279),
    'leapcore_a2_philosophy'    => array('m' => 4.7645, 'c' => 128.95),
    'leapcore_a2_physics'       => array('m' => 5.0965, 'c' => 159.08),
    'leapcore_a2_psychology'    => array('m' => 5.3872, 'c' => 158.71),
    'leapcore_a2_sociology'     => array('m' => 4.9645, 'c' => 122.95),

    'leapcore_btecex_applsci'   => array('m' => 10.606, 'c' => 269.15),

    'leapcore_default'          => array('m' => 4.8008, 'c' => 126.18),

    'btec'                      => array('m' => 4.8008, 'c' => 126.18),

);

//var_dump($l3va_data); die();

// A little function to make the output look nice.
function tlog($msg, $type = 'ok') {

    $out = ( $type == 'ok' ) ? $type = '[ ok ]' : $type = '['.$type.']';
    echo $out . ' ' . $msg . "\n";

}

/**
 * Process the L3VA score into a MAG.
 *
 * @param in        L3VA score (float)
 * @param course    Tagged course type
 * @param scale     Scale to use for this course
 * @param tag       If true, make the TAG instead of MAG
 */
function make_mag( $in, $course = 'leapcore_default', $scale = 'BTEC', $tag = false ) {

    if ( $in == '' || !is_numeric($in) || $in <= 0 || !$in ) {
        return false;
    }
    if ( $course == '' || !$in ) {
        return false;
    }

    $course = strtolower( $course );

    global $l3va_data;

    // Make the score acording to formulas if the scales are usable.
    if ( $scale == 'BTEC' || $scale == 'A Level' ) {
        $adj_l3va = ( $l3va_data[$course]['m'] * $in ) - $l3va_data[$course]['c'];
    } else {
        $adj_l3va = $in;
    }

    // Return a grade based on whatever scale we're using.
    if ( $scale == 'BTEC' && !$tag ) {
        // Using BTEC scale.

        $score = 1; // Refer
        if ( $adj_l3va >= 30 ) {
            $score = 2; // Pass
        }
        if ( $adj_l3va >= 60 ) {
            $score = 3; // Merit
        }
        if ( $adj_l3va >= 90 ) {
            $score = 4; // Distinction
        }

    } else if ( $scale == 'BTEC' && $tag ) {
        // We don't want to add in TAGs for BTEC, so return null.
        $score = null;

    } else if ( $scale == 'A Level' ) {
        // We're using an A Level scale.
        // AS Levels are exactly half of A (A2) Levels, if we need to know them in the future.
        // Does this system work for L3 (English and Maths) GSCEs also?

        // As A Level grades are precisely 30 apart, to get a TAG one grade up we just add 30 to the score.
        if ( $tag ) {
            $adj_l3va += 30;
        }

        $score = 1; // U
        if ( $adj_l3va >= 30 ) {
            $score = 2; // E
        }
        if ( $adj_l3va >= 60 ) {
            $score = 3; // D
        }
        if ( $adj_l3va >= 90 ) {
            $score = 4; // C
        }
        if ( $adj_l3va >= 120 ) {
            $score = 5; // B
        }
        if ( $adj_l3va >= 150 ) {
            $score = 6; // A
        }
        // If we ever use A*.
        //if ( $adj_l3va >= 180 ) {
        //    $score = 7; // A*
        //}

    } else if ( $scale == 'noscale' ) {
        // Using no scale, simply return null.
        $score = null;
    } else {
        // Set a default score if none of the above criteria are met.
        $score = null;
    }

    return array( $score, $adj_l3va );

}


// Just for internal use, defines the grade type (int) and what it is (string).
$gradetypes = array (
    0 => 'None',    // Scale ID: null
    1 => 'Value',   // Scale ID: null. Uses grademax and grademin instead.
    2 => 'Scale',   // Scale ID depends on whatever's available: IDs relate to mdl_scale.id.
    3 => 'Text',    // ...
);

// Define the wanted column names (will appear in this order in the Gradebook, initially).
$column_names = array(
    'TAG'   => 'Target Achievable Grade.',
    'L3VA'  => 'Level 3 Value Added.',
    'MAG'   => 'Indicative Minimum Achievable Grade.',
);

// Make an array keyed to the column names to store the grades in.
$targets = array();
foreach ( $column_names as $name => $desc ) {
    $targets[strtolower($name)] = '';
}

// If $thiscourse is set, query only that course.
$thiscoursestring = '';
if ( $thiscourse ) {
    $thiscoursestring = ' AND id = ' . $thiscourse;
}

// All courses which are appropriately tagged.
$courses = $DB->get_records_select(
    'course',
    "idnumber LIKE '%|" . IDNUMBERLIKE . "|%'" . $thiscoursestring,
    null,
    "id ASC",
    'id, shortname, fullname, idnumber'
);
if ( !$courses && $thiscourse ) {
    tlog('No courses tagged \'' . IDNUMBERLIKE . '\' with ID \'' . $thiscourse . '\' found, so halting.', 'EROR');
    exit(0);
} else if ( !$courses ) {
    tlog('No courses tagged \'' . IDNUMBERLIKE . '\' found, so halting.', 'EROR');
    exit(0);
}

/**
 * Sets up each course tagged with leapcore_ with a category and columns within it.
 */
$num_courses = count($courses);
$cur_courses = 0;
foreach ($courses as $course) {

    $cur_courses++;

    tlog('Processing course (' . $cur_courses . '/' . $num_courses . ') ' . $course->shortname . ' (id: ' . $course->id . ').', 'info');
    $logging['courses'][] = $course->fullname . ' (' . $course->shortname . ') [' . $course->id . '].';

    // Set up the scale to be used here, null by default.
    $course->scalename  = '';
    $course->scaleid    = null;

    $leapcore = explode( '|', $course->idnumber );
    foreach ( $leapcore as $key => $value ) {
        if ( empty( $value ) ) {
            unset ( $leapcore[$key] );
        } else {
            // This check is specifically for A2 (A Level) courses.
            if ( stristr( $value, str_replace ( '%', '', IDNUMBERLIKE ) . 'a2' ) ) {
                $course->scalename  = 'A Level';
                $course->coursetype = $value;

                tlog( 'Course ' . $course->id . ' appears to be an A Level (A2) course, so setting that scale for use later.', 'info' );

                // Get the scale ID.
                if ( !$moodlescaleid = $DB->get_record( 'scale', array( 'name' => 'A Level' ), 'id' ) ) {
                    tlog( ' Could not find a scale called \'' . $course->scalename . '\' for course ' . $course->id . '.', 'warn' );

                } else {
                    // Scale located.
                    $course->scaleid = $moodlescaleid->id;
                    tlog( ' Scale called \'' . $course->scalename . '\' found with ID ' . $moodlescaleid->id . '.', 'info' );
                }

                break;
            } else {
                $course->coursetype = $value;
            }
        }

    }

    // If we've found an A2 course, set the scale here.
    if ( !empty( $course->scalename ) ) {
        $gradeid = 2;                   // Set this to scale.
        $scaleid = $course->scaleid;    // Set this to what we pulled out of Moodle earlier.

        tlog(' Grade ID \'' . $gradeid . '\' and scale ID \'' . $scaleid . '\' set.');

    // Figure out the grade type and scale here, pulled directly from the course's gradebook's course itemtype.
    } else if ( $coursegradescale = $DB->get_record( 'grade_items', array( 'courseid' => $course->id, 'itemtype' => 'course' ), 'gradetype, scaleid' ) ) {

        $gradeid = $coursegradescale->gradetype;
        $scaleid = $coursegradescale->scaleid;

        // Found a grade type
        tlog('Gradetype \'' . $gradeid . '\' (' . $gradetypes[$gradeid] . ') found.', 'info');

        // If the grade type is 2 / scale.
        if ( $gradeid == 2 ) {
            if ( $coursescale = $DB->get_record( 'scale', array( 'id' => $scaleid ) ) ) {

                $course->scalename  = $coursescale->name;
                $course->scaleid    = $scaleid;

                $course->coursetype = $coursescale->name;

                $tolog = ' Scale \'' . $coursescale->id . '\' (' . $coursescale->name . ') found [' . $coursescale->scale . ']';
                $tolog .= ( $coursescale->courseid ) ? ' (which is specific to course ' . $coursescale->courseid . ')' : ' (which is global)';
                $tolog .= '.';
                tlog($tolog, 'info');

            } else {

                // If the scale doesn't exist that the course is using, this is a problem.
                tlog(' Gradetype \'2\' set, but no matching scale found.', 'warn');

            }

        } else if ( $gradeid == 1 ) {
            // If the grade type is 1 / value.
            $course->scalename  = 'noscale';
            $course->scaleid    = 1;
            // Already set, above.
            //$course->coursetype = 'Value';

            $tolog = ' Using \'' . $gradetypes[$gradeid] . '\' gradetype.';

        }


    } else {
        // Set it to default if no good scale could be found/used.
        $gradeid = 0;
        $scaleid = 0;
        tlog('No \'gradetype\' found, so using defaults instead.', 'info');
    }

    $logging['grade_types'][strtolower($course->scalename)]++;


    /**
     * Category checking or creation.
     */
    if ( $DB->get_record( 'grade_categories', array( 'courseid' => $course->id, 'fullname' => CATNAME ) ) ) {
        // Category exists, so skip creation.
        tlog('Category \'' . CATNAME . '\' already exists for course ' . $course->id . '.', 'skip');

    } else {
        // Create a category for this course.
        $grade_category = new grade_category();

        // Course id.
        $grade_category->courseid = $course->id;

        // Set the category name (no description).
        $grade_category->fullname = CATNAME;

        // Set the sort order (making this the first category in the gradebook, hopefully).
        $grade_category->sortorder = 1;

        // Save all that...
        if ( !$gc = $grade_category->insert() ) {
            tlog('Category \'' . CATNAME . '\' could not be inserted for course '.$course->id.'.', 'EROR');
            exit(1);
        } else {
            tlog('Category \'' . CATNAME . '\' (' . $gc . ') created for course '.$course->id.'.');
        }
    }

    // We've either checked a category exists or created one, so this *should* always work.
    $cat_id = $DB->get_record( 'grade_categories', array(
        'courseid' => $course->id,
        'fullname' => CATNAME,
    ) );
    $cat_id = $cat_id->id;

    // One thing we need to do is set 'gradetype' to 0 on that newly created category, which prevents a category total showing
    // and the grades counting towards the total course grade.
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
                $grade_item->gradetype  = $gradeid;
                $grade_item->scaleid    = $scaleid;
                $grade_item->display    = 1; // 'Real'. MIGHT need to seperate out options for BTEC and A Level.
            }
            if ( $col_name == 'L3VA' ) {
                // Lock the L3VA col as it's calculated elsewhere.
                $grade_item->sortorder  = 2;
                $grade_item->locked     = 1;
                $grade_item->decimals   = 0;
                $grade_item->display    = 1; // 'Real'.
            }
            if ( $col_name == 'MAG' ) {
                $grade_item->sortorder  = 3;
                //$grade_item->locked     = 1;
                $grade_item->gradetype  = $gradeid;
                $grade_item->scaleid    = $scaleid;
                $grade_item->display    = 1; // 'Real'.
            }

            // Scale ID, generated earlier. An int, 0 or greater.
            // TODO: Check if we need this any more!!
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
     * Move the category to the first location in the gradebook if it isn't already.
     */
    //$gtree = new grade_tree($course->id, false, false);
    //$temp = grade_edit_tree::move_elements(1, '')


    /**
     * Collect enrolments based on each of those courses
     */

    // EPIC 'get enrolled students' query from Stack Overflow:
    // http://stackoverflow.com/questions/22161606/sql-query-for-courses-enrolment-on-moodle
    // Only selects manually enrolled, not self-enrolled student roles.
    $sql = "SELECT DISTINCT u.id AS userid, firstname, lastname, username
        FROM mdl_user u
            JOIN mdl_user_enrolments ue ON ue.userid = u.id
            JOIN mdl_enrol e ON e.id = ue.enrolid
                AND e.enrol = 'manual'
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
        ORDER BY userid ASC;";

    if ( !$enrollees = $DB->get_records_sql( $sql ) ) {
        tlog('No manually enrolled students found for course ' . $course->id . '.', 'warn');

    } else {

        $num_enrollees = count($enrollees);
        tlog('Found ' . $num_enrollees . ' students manually enrolled onto course ' . $course->id . '.', 'info');

        // A variable to store which enrollee we're processing.
        $cur_enrollees = 0;
        foreach ($enrollees as $enrollee) {
            $cur_enrollees++;

            // Attempt to extract the student ID from the username.
            $tmp = explode('@', $enrollee->username);
            $enrollee->studentid = $tmp[0];
            //if ( strlen( $enrollee->studentid ) != 8 && !is_numeric( $enrollee->studentid ) ) {
            //    // This is most likely a staff member enrolled as a student, so skip.
            //    tlog(' Ignoring (' . $cur_enrollees . '/' . $num_enrollees . ') ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '].', 'skip');
            //} else {
                // A proper student, hopefully.
                tlog(' Processing user (' . $cur_enrollees . '/' . $num_enrollees . ') ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] on course ' . $course->id . '.', 'info');
                $logging['students'][] = $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->studentid . ') [' . $enrollee->userid . '] on course ' . $course->id . '.';

                // Assemble the URL with the correct data.
                //$leapdataurl = sprintf( LEAP_TRACKER_API, $CFG->trackerhash, $enrollee->studentid );
                $leapdataurl = sprintf( LEAP_TRACKER_API, $enrollee->studentid, $CFG->trackerhash );
                if ( DEBUG ) {
                    tlog('  Leap URL: ' . $leapdataurl, 'dbug');
                }

                // Use fopen to read from the API.
                //$handle = fopen($leapdataurl, 'r');
                if ( !$handle = fopen($leapdataurl, 'r') ) {
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
                            tlog('  JSON decoding returned error code ' . json_last_error() . ' for user ' . $enrollee->studentid . '.', 'EROR');
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
                                $targets['l3va'] = number_format( $leapdata->person->l3va, DECIMALS );
                                if ( $targets['l3va'] == '' || !is_numeric( $targets['l3va'] ) || $targets['l3va'] <= 0 ) {
                                    // If the L3VA isn't good.
                                    tlog('  L3VA is not good: \'' . $targets['l3va'] . '\'.', 'warn');

                                } else {

                                    tlog('  ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->userid . ') [' . $enrollee->studentid . '] L3VA score: ' . $targets['l3va'] . '.', 'info');

                                    // Make the MAG from the L3VA.
                                    $magtemp        = make_mag( $targets['l3va'], $course->coursetype, $course->scalename );
                                    $targets['mag'] = $magtemp[0];

                                    // Make the TAG in the same way, setting 'true' at the end for the next grade up.
                                    $tagtemp        = make_mag( $targets['l3va'], $course->coursetype, $course->scalename, true );
                                    $targets['tag'] = $tagtemp[0];

                                    tlog('   Generated data: MAG: \'' . $targets['mag'] . '\' ['. $magtemp[1] .']. TAG: \'' . $targets['tag'] . '\' ['. $tagtemp[1] .'].', 'info');
                                    if ( $targets['mag'] == 'U' || $targets['mag'] == 'F' || $targets['mag'] == 'E' ) {
                                        $logging['poor_grades'][] = 'MAG ' . $targets['mag'] . ' assigned to ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->studentid . ') [' . $enrollee->userid . '] on course ' . $course->id . '.';
                                    }
                                    if ( $targets['tag'] == 'U' || $targets['tag'] == 'F' || $targets['tag'] == 'E') {
                                        $logging['poor_grades'][] = 'TAG ' . $targets['tag'] . ' assigned to ' . $enrollee->firstname . ' ' . $enrollee->lastname . ' (' . $enrollee->studentid . ') [' . $enrollee->userid . '] on course ' . $course->id . '.';
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
                                            'userid' => $enrollee->userid,
                                        ), 'id');

                                        // New grade_grade object.
                                        $grade = new grade_grade();
                                        $grade->userid          = $enrollee->userid;
                                        $grade->itemid          = $gradeitem->id;
                                        $grade->categoryid      = $gradeitem->categoryid;
                                        $grade->rawgrade        = $score; // Will stay as set.
                                        $grade->finalgrade      = $score; // Will change with the grade, e.g. 3.
                                        $grade->timecreated     = time();
                                        $grade->timemodified    = $grade->timecreated;

                                        // If no id exists, INSERT.
                                        if ( !$gradegrade ) {

                                            if ( !$gl = $grade->insert() ) {
                                                tlog('   ' . strtoupper( $target ) . ' insert failed for user ' . $enrollee->userid . ' on course ' . $course->id . '.', 'EROR' );
                                            } else {
                                                tlog('   ' . strtoupper( $target ) . ' (' . $score . ') inserted for user ' . $enrollee->userid . ' on course ' . $course->id . '.' );
                                            }

                                        } else {
                                            // If the row already exists, update, but don't ever *update* the TAG.
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

            //} // END extracting the student id number.

        } // END cycle through each course enrollee.

    }  // END enrollee query.

    // Final blank-ish log entry to separate out one course from another.
    tlog('', '----');

} // END foreach course tagged 'leapcore_*'.

// Sort and dump the summary log.
tlog(' Summary of all performed operations.', 'smry');
asort($logging['courses']);
asort($logging['students']);

// Processing.
$logging['num']['courses']  = count($logging['courses']);
$logging['num']['students'] = count($logging['students']);
foreach ( $logging['grade_types'] as $value ) {
    $logging['num']['grade_types'] += $value ;
}
$logging['num']['poor_grades'] = count($logging['poor_grades']);

var_dump($logging);
//$json = json_encode($logging);
//echo $json."\n";

if ( $logging['num']['courses'] ) {
    tlog( '  ' . $logging['num']['courses'] . ' courses.', 'smry' );
    foreach ( $logging['num']['courses'] as $course ) {
        echo $course . "\n";
    }
} else {
    tlog( '  No courses processed.', 'warn' );
}

if ( $logging['num']['students'] ) {
    tlog( '  ' . $logging['num']['students'] . ' students.', 'smry' );
    foreach ( $logging['num']['students'] as $student ) {
        echo $student . "\n";
    }
} else {
    tlog( '  No students processed.', 'warn' );
}

if ( $logging['num']['grade_types'] ) {
    tlog( '  ' . $logging['num']['grade_types'] . ' grade types.', 'smry' );
    foreach ( $logging['num']['students'] as $grade_type => $count ) {
        echo $grade_type . ': ' . ' . $count . ' . ".\n";
    }
} else {
    tlog( '  No grade_types found.', 'warn' );
}

if ( $logging['num']['poor_grades'] ) {
    tlog('  ' . $logging['num']['poor_grades'] . ' poor grades.', 'smry');
    foreach ( $logging['num']['poor_grades'] as $grade ) {
        echo $grade . "\n";
    }
} else {
    tlog( '  No poor grades found. Good!' );
}


// Finish time.
$time_end = microtime(true);
$duration = $time_end - $time_start;
tlog('', '----');
tlog('Finished at ' . date( 'c', $time_end ) . ', took ' . number_format( $duration, DECIMALS ) . ' seconds.', 'done');

exit(0);
