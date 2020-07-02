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
 * A scheduled task to sync docs with Google Drive.
 *
 * @package    mod_googledocs
 * @copyright  2020 Veronica Bermegui
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googledocs\task;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/accesslib.php');
require_once($CFG->dirroot . '/mod/googledocs/lib.php');
require_once($CFG->dirroot . '/mod/googledocs/locallib.php');



/**
 * An example of a scheduled task.
 */
class cron_task extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_task', 'googledocs');
    }

    /**
     * Execute the task.
     */
    public function execute() {

        $this->log_start("Executing Googledocs sync.");

        $this->updates();
        $this->make_copy();

        $this->log_finish("Finished.");
    }

    /**
     * Generate the google  file for each user enrolled in the course.
     * @global moodle_database  $DB
     */
    private function make_copy() {
       global $DB;
       $this->log_start("Processing Googledocs files to share");

       $rs = $DB->get_recordset_select ('googledocs', 'sharing = :sharing', ['sharing' => 0], 'course');

       if(!$rs->valid()){
           $this->log_finish('No files to process.');
       }else{

            $currentcourse = $rs->current()->course;
            $students = $this->get_students_enrolled($currentcourse);
            $author  = $DB->get_records_sql('SELECT email FROM mdl_user WHERE id = :id', ['id'=> $rs->current()->userid]);
            $author_email = (current($author))->email;
            $owncopy = $rs->current();

            foreach($rs as $file) {

                if($file->course != $currentcourse){
                    $currentcourse = $file->course;
                    $students =  $this->get_students_enrolled($currentcourse);
                    $owncopy = $file->distribution;
                }

                $gdrive =  new \googledrive($file->id, true, $author_email);

                if($owncopy == 'each_gets_own') {
                    $gdrive->make_copy($file->docid, $file->parentfolderid, $author_email, $file->name, $students,
                        $file->permissions, $file->id);
                }else{
                    $gdrive->share_copy($file->docid, $file->name, $students, $file->permissions, $file->document_type, $file->id);
                }

            }

            $rs->close();

            //Change sharing status
            $q = "UPDATE  mdl_googledocs SET  sharing = :sharing";
            $DB->execute($q, ['sharing'=> 1]);
            $this->log_finish('Finished processing Googledocs files to share');
       }
    }

    /**
     * If the teacher makes a change in a file (from settings), replicate the
     * change(s) to the student's files.
     * @global moodle_database  $DB
     */
    private function updates(){

       global $DB;
       $this->log_start("Googledocs files update starts");
      
       $q = "SELECT id, name, course, distribution
             FROM mdl_googledocs
             WHERE update_status = 'modified' AND sharing = 1 ";

       $instances = $DB->get_records_sql($q);

       if ($instances) {

            $instancesid = array_column($instances, 'id');
            $instancesids = implode(',', $instancesid);

            $gf = "SELECT *
                   FROM mdl_googledocs_files
                   WHERE googledocid in ($instancesids)
                   ORDER BY googledocid";

            $gftoupdate = $DB->get_records_sql($gf);
            $currentcourse = (current($instances))->course;
            $students = $this->get_students_enrolled($currentcourse);
            $studentdetails = current($students);
            $gdrive =  new \googledrive((current($instances))->id, true);
            $newdata = new \stdClass();

            foreach($instances as $instance => $i) {
                foreach($gftoupdate as $gf=>$studentfile) {
                    if($i->course != $currentcourse){
                        $currentcourse = $i->course;
                        $students =  $this->get_students_enrolled($currentcourse);
                        $gdrive =  new \googledrive($i->id, true);
                    }
                    if ($i->id == $studentfile->googledocid ){
                        $updateddata = $this->set_newdata($newdata, $i, $studentdetails, $studentfile, $gdrive);
                        $studentdetails = next($students);
                        $DB->update_record('googledocs_files', $updateddata);
                        $newdata = new \stdClass();
                    }
                }
                reset($students);
                $studentdetails = current($students);
            }
                //Change status of master file to updated.
                $q = "UPDATE  mdl_googledocs SET  update_status = :update_status";
                $DB->execute($q, ['update_status'=> 'updated']);
                $this->log_finish('Finished processing Googledocs files to share');
        }else{

            $this->log_finish('No files to update');

        }

       $this->log_finish('Googledocs files update finished');

    }

    //-------------Helper functions-------------------------------

    private function set_newdata($newdata, $i, $studentdetails, $studentfile, $gdrive){
        $newdata->id = $studentfile->id;
        if ($i->distribution == 'each_gets_own'){
            $newdata->name = $i->name.'_'.$studentdetails['displayName'];
            $filedetails = $gdrive->get_file_details($studentfile->url);
            $updated = $gdrive->update_file($filedetails['id'],$newdata);
            $updated == null ? $this->update_student_files_status($studentfile->id, 'error') : $this->update_student_files_status($studentfile->id, 'updated');

        }else{ // When sharing a copy, the name is the same as the parent
            $newdata->name = $i->name;
             $this->update_student_files_status($studentfile->id, 'updated');
        }
        return $newdata;
    }

    private function update_student_files_status($id, $status){
        global $DB;
        $q = "UPDATE  mdl_googledocs_files SET  update_status = :status WHERE id = :id";
        var_dump($DB->execute($q, ['status'=> $status, 'id'=> $id]));
    }

    private function get_students_enrolled($courseid){
        $context = \context_course::instance($courseid);
        $coursestudents = get_role_users(5, $context);

        foreach ($coursestudents as $student) {
            $students[] = array('id' => $student->id, 'emailAddress' => $student->email,
            'displayName' => $student->firstname . ' ' . $student->lastname);
        }

        return $students;

    }
}