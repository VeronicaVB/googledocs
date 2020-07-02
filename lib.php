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
 * Library of interface functions and constants for module googledocs
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 *
 * All the googledocs specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_googledocs
 * @copyright  2019 Michael de Raadt <michaelderaadt@gmail.com>
 * @copyright  2020 Veronica Bermegui
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/mod/googledocs/locallib.php');

/* Moodle core API */

/**
 * Returns the information on whether the module supports a feature
 *
 * See {@link plugin_supports()} for more info.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function googledocs_supports($feature) {

    switch($feature) {
        case FEATURE_MOD_INTRO:
            return false; //true
        case FEATURE_SHOW_DESCRIPTION:
            return false;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return false;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the googledocs into the database
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $googledocs Submitted data from the form in mod_form.php
 * @param mod_googledocs_mod_form $mform The form instance itself (if needed)
 * @return int The id of the newly inserted googledocs record
 */
function googledocs_add_instance(stdClass $googledocs, mod_googledocs_mod_form $mform = null) {

    try{
        global $DB, $USER;

        $googledocs->timecreated = time();
        $context = context_course::instance($googledocs->course);
        $gdrive =  new googledrive($context->id);

        if (!$gdrive->check_google_login()) {
            $googleauthlink = $gdrive->display_login_button();
            $mform->addElement('html',$googleauthlink);
            throw new Exception('Error - not authenticated with Google!');
        }

        $author = array('emailAddress' => $USER->email, 'displayName' => fullname($USER));
        $coursestudents = get_role_users(5, $context);

        foreach ($coursestudents as $student) {
            $students[] = array('id' => $student->id, 'emailAddress' => $student->email,
                'displayName' => $student->firstname . ' ' . $student->lastname);
        }

        // Use existing doc
        if (($mform->get_submitted_data())->use_document == 'existing'){
                $sharedlink = $gdrive->share_existing_file($mform->get_submitted_data()->google_doc_url);
                if($sharedlink == null) throw new exception ('An error occurred: $gdrive->share_existing_file');
                $googledocs->google_doc_url = ($mform->get_submitted_data()->google_doc_url);
                $googledocs->docid = $sharedlink['id'];
                $googledocs->parentfolderid = $sharedlink['parentid'];
                $googledocs->mimetype = $mform->get_submitted_data()->document_type;//$sharedlink['mimetype'];
                $googledocs->userid = $USER->id;
                $googledocs->timeshared = (strtotime($sharedlink['timeshared']));
                $googledocs->timemodified =  $googledocs->timecreated;
                $googledocs->name = $sharedlink['name'];
                $googledocs->intro = $sharedlink['name'];
                $googledocs->sharing = 0;
                $googledocs->distribution = ($mform->get_submitted_data())->distribution;
                $googledocs->introformat = FORMAT_MOODLE;
                $googledocs->id = $DB->insert_record('googledocs', $googledocs);
        } else {

            $folderid = $gdrive->get_parent_folder_id(($mform->get_submitted_data())->foldername);
            // No docs in this section.
            if ($folderid == null) {
                $folderid = $gdrive->create_gdrive_folder($googledocs->foldername, $author);
            }

            $sharedlink = $gdrive->create_gdrive_file($googledocs->name, $googledocs->document_type,
                $googledocs->permissions, $author, $students, $folderid);
            if($sharedlink == null) throw new exception ('An error occurred: $gdrive->create_gdrive_file');
            $googledocs->google_doc_url = $sharedlink[1];
            $googledocs->mimetype = $googledocs->document_type;
            $googledocs->docid = ($sharedlink[0])->id;
            $googledocs->parentfolderid = $folderid;
            $googledocs->userid = $USER->id;
            $googledocs->timeshared =  (strtotime(($sharedlink[0])->createdDate));
            $googledocs->timemodified = $googledocs->timecreated;
            $googledocs->name = $googledocs->name;
            $googledocs->foldername =  ($mform->get_submitted_data())->foldername;
            $googledocs->intro = $googledocs->name;
            $googledocs->sharing = 0;
            $googledocs->distribution = ($mform->get_submitted_data())->distribution;
            $googledocs->permissions  = ($mform->get_submitted_data())->permissions;

            $googledocs->introformat = FORMAT_MOODLE;
            $googledocs->id = $DB->insert_record('googledocs', $googledocs);
        }

        return $googledocs->id;

    } catch (Exception $ex) {
        print "An error occurred: " . $ex->getMessage();
    }
    return null;
}

/**
 * Make change in the google drive file first. If the change is
 * successful then it updates an instance of the googledocs in the database.
 *
 * NOTE: use the service account for this update, this avoids the error 403 daily
 * limit exceeded.
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param stdClass $googledocs An object from the form in mod_form.php
 * @param mod_googledocs_mod_form $mform The form instance itself (if needed)
 * @return boolean Success/Fail
 */
function googledocs_update_instance(stdClass $googledocs, mod_googledocs_mod_form $mform = null) {
    global $DB;

    $context = context_course::instance($googledocs->course);
    var_dump( $mform->get_submitted_data()); exit;
    $gdrive =  new googledrive($context->id, true);
    $result = $gdrive->update_file(($mform->get_current())->docid, $mform->get_submitted_data());

    $googledocs->introeditor = null;
    $googledocs->timemodified = time();
    $googledocs->id = $googledocs->instance;


    if ($result == null) {
        $googledocs->update_status = 'error';
    }else{
        $googledocs->update_status = 'modified';
    }
    $googledocs->intro =   ($mform->get_submitted_data())->name;
   // var_dump($googledocs); exit;
   // var_dump($googledocs->intro); exit;
    //if ($googledocs->intro == null) $googledocs->intro = $mform->get_current()->intro;
    //if ($googledocs->introformat == null) $googledocs->introformat = $mform->get_current()->introformat;

    $result = $DB->update_record('googledocs', $googledocs);


    return $result;
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every googledocs event in the site is checked, else
 * only googledocs events belonging to the course specified are checked.
 * This is only required if the module is generating calendar events.
 *
 * @param int $courseid Course ID
 * @return bool
 */
function googledocs_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$googledocss = $DB->get_records('googledocs')) {
            return true;
        }
    } else {
        if (!$googledocss = $DB->get_records('googledocs', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($googledocss as $googledocs) {
        // Create a function such as the one below to deal with updating calendar events.
        // googledocs_update_events($googledocs);
    }

    return true;
}

/**
 * Removes an instance of the newmodule from the database
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function googledocs_delete_instance($id) {
    global $DB;

    if (! $googledocs = $DB->get_record('googledocs', array('id' => $id))) {
        return false;
    }

    // Delete any dependent records here.

    $DB->delete_records('googledocs', array('id' => $googledocs->id));
    $DB->delete_records('googledocs_files', array('googledocid'  => $googledocs->id));
    //googledocs_grade_item_delete($googledocs);

    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 *
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param stdClass $course The course record
 * @param stdClass $user The user record
 * @param cm_info|stdClass $mod The course module info object or record
 * @param stdClass $googledocs The googledocs instance record
 * @return stdClass|null
 */
function googledocs_user_outline($course, $user, $mod, $googledocs) {

    $return = new stdClass();
    $return->time = 0;
    $return->info = '';
    return $return;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * It is supposed to echo directly without returning a value.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $googledocs the module instance record
 */
function googledocs_user_complete($course, $user, $mod, $googledocs) {
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in googledocs activities and print it out.
 *
 * @param stdClass $course The course record
 * @param bool $viewfullnames Should we display full names
 * @param int $timestart Print activity since this timestamp
 * @return boolean True if anything was printed, otherwise false
 */
function googledocs_print_recent_activity($course, $viewfullnames, $timestart) {
    return false;
}

/**
 * Prepares the recent activity data
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML via
 * {@link googledocs_print_recent_mod_activity()}.
 *
 * Returns void, it adds items into $activities and increases $index.
 *
 * @param array $activities sequentially indexed array of objects with added 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 */
function googledocs_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0) {
}

/**
 * Prints single activity item prepared by {@link googledocs_get_recent_mod_activity()}
 *
 * @param stdClass $activity activity record with added 'cmid' property
 * @param int $courseid the id of the course we produce the report for
 * @param bool $detail print detailed report
 * @param array $modnames as returned by {@link get_module_types_names()}
 * @param bool $viewfullnames display users' full names
 */
function googledocs_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Function to be run periodically according to the moodle cron
 *
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * Note that this has been deprecated in favour of scheduled task API.
 *
 * @return boolean
 */
function googledocs_cron () {
    return true;
}

/**
 * Returns all other caps used in the module
 *
 * For example, this could be array('moodle/site:accessallgroups') if the
 * module uses that capability.
 *
 * @return array
 */
function googledocs_get_extra_capabilities() {
    return array();
}


/* File API */

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@link file_browser::get_file_info_context_module()}
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function googledocs_get_file_areas($course, $cm, $context) {
    return array();
}

/**
 * File browsing support for googledocs file areas
 *
 * @package mod_googledocs
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 */
function googledocs_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    return null;
}

/**
 * Serves the files from the googledocs file areas
 *
 * @package mod_googledocs
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the googledocs's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function googledocs_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options=array()) {
    global $DB, $CFG;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    send_file_not_found();
}

/* Navigation API */

/**
 * Extends the global navigation tree by adding googledocs nodes if there is a relevant content
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the googledocs module instance
 * @param stdClass $course current course record
 * @param stdClass $module current googledocs instance record
 * @param cm_info $cm course module information
 */
/*
function googledocs_extend_navigation(navigation_node $navref, stdClass $course, stdClass $module, cm_info $cm) {
    // TODO Delete this function and its docblock, or implement it.
}
*/

/**
 * Extends the settings navigation with the googledocs settings
 *
 * This function is called when the context for the page is a googledocs module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav complete settings navigation tree
 * @param navigation_node $googledocsnode googledocs administration node
 */
function googledocs_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $googledocsnode=null) {
    // TODO Delete this function and its docblock, or implement it.

    // TODO: what Google drive documents are allowed SELECT box
}
