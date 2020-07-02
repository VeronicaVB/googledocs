<?php
    /**
 * Schedule tasks
 *
 * @package     mod/googledocs
 * @author      Veronica Bermegui
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$tasks = [
    [
        'classname' => 'mod_googledocs\task\cron_task',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '17',
        'day' => '*',
        'month' => '1,7',
        'dayofweek' => '0',
    ],
];