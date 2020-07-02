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
 * OAuth callback page that stores the user's authorisation.
 *
 * @package    mod_googledocs
 * @copyright  2019 Michael de Raadt <michaelderaadt@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include required files.
require('../../config.php');
require(__DIR__ . '/locallib.php');

// Gather parameters
$cmid = required_param('cmid', PARAM_INT);
$code = required_param('oauth2code', PARAM_RAW);

// Check the user is logged in.
require_login();

// Output start of page.
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
$PAGE->set_url(
    '/mod/googledocs/oauth2_callback.php',
    array(
        'cmid'       => $cmid,
        'sesskey'    => sesskey(),
        'oauth2code' => $code,
    )
);
$PAGE->set_context(null);
$strauthenticated = get_string('authenticated', 'googledocs');
$PAGE->set_title($strauthenticated);
$PAGE->set_pagelayout('popup');
echo $OUTPUT->header();
echo $OUTPUT->heading($strauthenticated, 2);

/// Wait as long as it takes for this script to finish
core_php_time_limit::raise();

// Save the user authentication code.
$googledrive = new googledrive($cmid);
$googledrive->callback();

// Output the page content
echo html_writer::tag('div', get_string('windowillclose', 'googledocs'), ['style' => 'margin: 20px 0;']);
echo html_writer::start_tag('div', ['class' => 'btn-group']);
echo html_writer::tag('input', '', [
    'type'    => 'button',
    'class'   => 'btn btn-primary',
    'value'   => get_string('close', 'googledocs'),
    'onclick' => 'window.close();'
]);
echo html_writer::end_tag('div');

// Reload the parent form and auto close this window, in 5sec.
echo html_writer::start_tag('script', ['type' => 'text/javascript']);
echo 'window.opener.location.reload(false);';
echo 'setTimeout("self.close()", 5000);';
echo html_writer::end_tag('script');
