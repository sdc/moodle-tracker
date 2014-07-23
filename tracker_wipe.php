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
 * A broad brush for clearing out a database during testing. I wouldn't run this on a production db if I were you.
 * Probably should only run this on courses with 'leapcore_*' in the idnumber field, for safety's sake.
 *
 * @package   ext_cron
 * @copyright 2014 Paul Vaughan
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Make this work from a CLI.
define('CLI_SCRIPT', true);

require_once 'config.php';

$DB->delete_records('grade_categories', array( 'fullname' => 'Targets' ) );

$DB->delete_records('grade_items', array( 'itemname' => 'TAG' ) );
$DB->delete_records('grade_items', array( 'itemname' => 'L3VA' ) );
$DB->delete_records('grade_items', array( 'itemname' => 'MAG' ) );

echo "Everything is wiped.\n";
