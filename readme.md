# Moodle 'Tracker' Project

One Git repo for all the brainfunk associated with this project.

## tracker_cron.php

Place this file into the root on your Moodle 2.7 installation and add to cron thus:

> `0 0 * * * root php /srv/moodle/tracker_cron.php > /tmp/moodle-tracker-cron.log 2>&1`

0 minutes and 0 hours (midnight) every day, run this script and dump the output (if any) into a file.

### To do

* The fields are in the correct order (at this time) but in testing the Targets category came second to an assignment.
