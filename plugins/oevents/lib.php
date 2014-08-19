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
 * Library function for syncing the events from O365. This library will
 contain functions to add/edit and delete events
 *
 * @package    local
 * @subpackage oevents - to get office 365 events.
 * @copyright  2014 Introp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class events_o365 {
    // TODO: Need to parametrize this so it can be called from cron as well as login hook
    public function sync_calendar(){
        global $USER,$DB,$SESSION;
        if (!isloggedin()) {
            return false;
        }

        $params = array();
        $curl = new curl();
        date_default_timezone_set('UTC');
        $header = array('Authorization: Bearer '.$SESSION->accesstoken);
        $curl->setHeader($header);
        $courseevents = null;

        //to get the list of courses the user is enrolled
        $courses = enrol_get_my_courses();
        if($courses) {
            //get the calendar course id for each of the courses and get events
            foreach($courses as $course) {
                $course_id = $course->id;
                //teacher role comes with courses.
                $context = get_context_instance(CONTEXT_COURSE, $course_id, true);
                $roles = get_user_roles($context, $USER->id, true);
                $role = key($roles);
                $roleid = $roles[$role]->roleid;
                if($roleid == 3) {
                   $course_cal = $DB->get_record('course_calendar_ext',array("course_id" => $course_id));
                   $courseevent = $curl->get("https://outlook.office365.com/ews/odata/Me/Calendars('".$course_cal->calendar_course_id."')/Events");
                   $courseevents =json_decode($courseevent);
                }
            }
        }

        $eventresponse = $curl->get('https://outlook.office365.com/ews/odata/Me/Calendar/Events'); // TODO: Restrict time range to be the same as moodle events
        $eventresponses = json_decode($eventresponse);

        // echo "<pre>";
        //print_r($courseevents);

        if($courseevents && (count($courseevents) > 0)) {
            $courseeventscal = array();
            foreach ($courseevents as $event) {
                $courseeventscal = array_push($courseeventscal,$event);
            }

            if ($eventresponses && is_array($eventresponses))
                $o365events = array_merge($eventresponses,$courseeventscal);
            else
                $o365events = $eventresponses;
        } else {
            $o365events = $eventresponses;
        }

        // print_r($o365events);
        // exit;

        //Need to give start time and end time to get all the events from calendar.
        //TODO: Here I am giving the time recent and next 60 days.
        $timestart = time() - 4320000;
        $timeend = time() + 5184000;
        $moodleevents = calendar_get_events($timestart,$timeend,$USER->id,FALSE,FALSE,true,true);
        $context_value = 0;

        // loop through all Office 365 events and create or update moodle events
        if (!isset($o365events->error)) {
            foreach ($o365events->value as $o365event) {
                // if event already exists in moodle, get its id so we can update it instead of creating a new one
                //Keep both the course name and O365 category name same.
                /*if($o365event->Categories) {
                    $course = $DB->get_record('course', array("fullname" => $o365event->Categories[0]));
                    $course_id = $course->id;
                    $context_value = context_course::instance($course_id);
                } else {
                    $context_value = 0;
                }*/

                if (strtotime($o365event->Start) == 0) // this happens due to some bug in O365, ignore these events
                    continue;

                $event_id = 0;
                if ($moodleevents) {
                    foreach ($moodleevents as $moodleevent) {
                        if ((trim($moodleevent->uuid)) == trim($o365event->Id)) {
                            $event_id = $moodleevent->id;
                            break;
                        }
                    }
                }

                // prepare event data
                if ($event_id != 0) {
                    $event = calendar_event::load($event_id);
                } else {
                    $event = new stdClass;
                    $event->id = 0;
                    $event->userid       = $USER->id;
                    $event->eventtype    = 'user';
                    if ($context_value != 0)
                        $event->context      = $context_value;
                }

                $event->uuid         = $o365event->Id;
                $event->name         = empty($o365event->Subject) ? '<unnamed>' : $o365event->Subject;
                $event->description  = array("text" => empty($o365event->Subject) ? '<unnamed>' : $o365event->Subject,
                                        "format" => 1,
                                        "itemid" => $o365event->Id
                                         );
                $event->timestart    = strtotime($o365event->Start);
                $event->timeduration = strtotime($o365event->End) - strtotime($o365event->Start);

                // create or update moodle event
                if ($event_id != 0) {
                    $event->update($event);
                } else {
                    $event = new calendar_event($event);
                    // error_log(print_r($o365event, true));
                    // error_log(print_r($event, true));
                    $event->update($event);
                }
            }
        }

        // if an event exists in moodle but not in O365, we need to delete it from moodle
        if ($moodleevents) {
            foreach ($moodleevents as $moodleevent) {
                //echo "event: "; print_r($moodleevent); echo "<br/><br/>";
                $found = false;

                foreach ($o365events->value as $o365key => $o365event) {
                    if (trim($moodleevent->uuid) == trim($o365event->Id)) {
                        $found = true;
                        unset($o365events->value[$o365key]);
                        break;
                    }
                }

                if (!$found) {
                    $event = calendar_event::load($moodleevent->id);
                    $event->delete();
                }
            }
        }
    }

    public function insert_o365($data) {
        global $DB,$SESSION,$USER;

        error_log('insert_o365 called');
        error_log(print_r($data, true));

        date_default_timezone_set('UTC');

        //Checking if access token has expired, then ask for a new token
        $this->check_token_expiry();

        // if this event already exists in O365, it will have a uuid, so don't insert it again
        //$data does not provide with uuid. So for that we are retrieving each event by event id.
        $eventdata = calendar_get_events_by_id(array($data->id));

        if ($eventdata[$data->id]->uuid != "") {
            return;
        }
        //curl object
        $curl = new curl();
        $header = array("Accept: application/json",
                        "Content-Type: application/json;odata.metadata=full",
                        "Authorization: Bearer ". $SESSION->accesstoken);
        $curl->setHeader($header);
        //event object to be passed
        $oevent = new object;
        $oevent->Subject = $data->name;
        $oevent->Body = array("ContentType" => "Text",
            "Content" => trim($data->description)
        );

        $oevent->Start = date("Y-m-d\TH:i:s\Z", $data->timestart);

        if($data->timeduration == 0) {
            $oevent->End = date("Y-m-d\TH:i:s\Z", $data->timestart + 3600);
        } else {
            $oevent->End = date("Y-m-d\TH:i:s\Z", $data->timestart + $data->timeduration);
        }

        if ($data->courseid != 0) { //Course event
            $course = $DB->get_record('course',array("id" => $data->courseid));
            $course_name = $course->fullname;
            $course_cal = $DB->get_record('course_calendar_ext',array("course_id" => $data->courseid));
            $course_id = $course_cal->calendar_course_id;
            $course_name = array(0 => $course_name);
            //In moodle the roles are based on the context, to check if logged in user is a teacher
            $context = get_context_instance(CONTEXT_COURSE, $data->courseid, true);
            $roles = get_user_roles($context, $USER->id, true);
            $role = key($roles);
            //print_r($roles);exit;
            $roleid = $roles[$role]->roleid;
            //Role id of teacher is 3 and in context of course
            if($roleid == 3) {
                // If this is a course event, and the logged in user is a teacher,
                //make students in that course as attendees
                if (property_exists($data, "courseid")) {
                    $sql = "SELECT u.id,u.firstname, u.lastname, u.email FROM `mdl_user` u
                            JOIN mdl_role_assignments ra ON u.id = ra.userid
                            JOIN mdl_role r ON ra.roleid = r.id
                            JOIN mdl_context c ON ra.contextid = c.id
                            WHERE c.contextlevel = 50
                            AND c.instanceid = ".$data->courseid."
                            AND r.shortname = 'student' ";
                    $students = $DB->get_records_sql($sql);
                }

                $attendees = array();
                foreach ($students as $student) {
                    $attend = array(
                                   "Name" => $student->firstname." ".$student->lastname,
                                   "Address" => $student->email,
                                   "Type" => "Required"
                                    );
                    array_push($attendees,$attend);
                }
                $oevent->Attendees = $attendees;
            }
            $event_data =  json_encode($oevent);
            //POST https://outlook.office365.com/ews/odata/Me/Calendars(<calendar_id>)/Events
            $eventresponse = $curl->post("https://outlook.office365.com/ews/odata/Me/Calendars('".$course_id."')/Events",$event_data);
        } else { //if user event, either teacher or student
            $event_data =  json_encode($oevent);
            $eventresponse = $curl->post('https://outlook.office365.com/ews/odata/Me/Calendar/Events',$event_data);
        }
        // obtain uuid back from O365 and set it into the moodle event
        $eventresponse = json_decode($eventresponse);
        // print_r($eventresponse);
        if($eventresponse && $eventresponse->Id) {
            $event = calendar_event::load($data->id);
            $event->uuid = $eventresponse->Id;
            $event->update($event);
        }
    }

    public function delete_o365($data) {
        global $DB,$SESSION;

        error_log('delete_o365 called');
        error_log(print_r($data, true));

        //Checking if access token has expired, then ask for a new token
        $this->check_token_expiry();

        if($data->uuid) {
            $curl = new curl();
            $url = "https://outlook.office365.com/ews/odata/Me/Calendar/Events('".$data->uuid."')";
            $header = array("Authorization: Bearer ".$SESSION->accesstoken);
            $curl->setHeader($header);
            $eventresponse = $curl->delete($url);
        }
    }

    public function check_token_expiry() {
        global $SESSION;

        date_default_timezone_set('UTC');

        if (time() > $SESSION->expires) {
            $refresh = array();
            $refresh['client_id'] = $SESSION->params_office['client_id'];
            $refresh['client_secret'] = $SESSION->params_office['client_secret'];
            $refresh['grant_type'] = "refresh_token";
            $refresh['refresh_token'] = $SESSION->refreshtoken;
            $refresh['resource'] = $SESSION->params_office['resource'];
            $requestaccesstokenurl = "https://login.windows.net/common/oauth2/token";

            $curl = new curl();
            $refresh_token_access = $curl->post($requestaccesstokenurl, $refresh);

            $access_token = json_decode($refresh_token_access)->access_token;
            $refresh_token = json_decode($refresh_token_access)->refresh_token;
            $expires_on = json_decode($refresh_token_access)->expires_on;

            $SESSION->accesstoken =  $access_token;
            $SESSION->refreshtoken = $refresh_token;
            $SESSION->expires = $expires_on;
         }
    }

    public function get_app_token(){
        $clientsecret = urlencode(get_config('auth/googleoauth2', 'azureadclientsecret'));
        $resource = urlencode('https://outlook.office365.com'); //'https://graph.windows.net' 'https://outlook.office365.com'
        $clientid = urlencode(get_config('auth/googleoauth2', 'azureadclientid'));
        $state = urlencode('state=bb706f82-215e-4836-921d-ac013e7e6ae5');

        $fields = 'grant_type=client_credentials&client_secret='.$clientsecret
                  .'&resource='.$resource.'&client_id='.$clientid.'&state='.$state;

        $curl = curl_init();

        $stsurl = 'https://login.windows.net/' . get_config('auth/googleoauth2', 'azureaddomain') . '/oauth2/token?api-version=1.0';
        curl_setopt($curl, CURLOPT_URL, $stsurl);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS,  $fields);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $output = curl_exec($curl);

        curl_close($curl);

        $tokenoutput = json_decode($output);
        // echo 'token: ' ; print_r($tokenoutput);

        return $tokenoutput->{'access_token'};
    }

    public function get_calendar_events($token, $upn) {
        $curl = new curl();
        $header = array('Authorization: Bearer ' . $token);
        $curl->setHeader($header);
        $eventresponse = $curl->get('https://outlook.office365.com/ews/odata/' . urlencode($upn) . '/Calendar/Events'); // TODO: Restrict time range to be the same as moodle events
        //$eventresponse = $curl->get('https://outlook.office365.com/ews/odata/Me/Calendar/Events'); // TODO: Restrict time range to be the same as moodle events

        $o365events = json_decode($eventresponse);
        // echo 'events: '; print_r($o365events);

        return $o365events;
    }

}

//--------------------------------------------------------------------------------------------------------------------------------------------
// Cron method
function local_oevents_cron() {
    mtrace( "O365 Calendar Sync cron script is starting." );

    date_default_timezone_set('UTC');

    //$this->check_token_expiry();
    //$this->sync_calendar();

    $oevents = new events_o365();
    $token = $oevents->get_app_token();

    // TOOD: get all users

    // TODO: Loop over all users and sync their calendars
    $o365events = $oevents->get_calendar_events($token, get_config('auth/googleoauth2', 'azureadadminupn'));

    mtrace( "O365 Calendar Sync cron script completed." );

    return true;
}

//--------------------------------------------------------------------------------------------------------------------------------------------
// Event handlers
function on_course_created($data) {
    create_course_calendar($data);
}

function on_course_deleted($data) {
    delete_course_calendar($data);
}

function on_user_enrolment_created($data) {
    subscribe_to_course_calendar($data);
}

function on_user_enrolment_deleted($data) {
    unsubscribe_from_course_calendar($data);
}

// TODO: Use this type of event hooks?
// function on_calendar_event_created($data) {
    // $in = new events_o365();
    // $in->delete_o365($data);
// }

// function on_calendar_event_deleted($data) {
    // $in = new events_o365();
    // $in->delete_o365($data);
// }

//--------------------------------------------------------------------------------------------------------------------------------------------
// O365 library methods
function create_course_calendar($data) {
    global $DB,$SESSION;

    error_log("create_course_calendar called");
    error_log(print_r($data, true));

    $newCal = array(
                "@odata.type" => "#Microsoft.Exchange.Services.OData.Model.Calendar",
                "Name" => $data->fullname
                );
    $calendar_name = json_encode($newCal);
    $curl = new curl();
    $header = array("Accept: application/json",
                "Content-Type: application/json;odata.metadata=full",
                "Authorization: Bearer ". $SESSION->accesstoken);
    $curl->setHeader($header);
    $new_Calendar = $curl->post("https://outlook.office365.com/ews/odata/Me/Calendars", $calendar_name);
    $new_Calendar = json_decode($new_Calendar);
    //TODO Need to get the course calendar same as calendar id from office.
    //Store the id in some fields of course table
    //idnumber is varchar type and can we use it to store our calendar id we get?
    $course_calendar = new stdClass();
    $course_calendar->course_id = $data->id;
    $course_calendar->calendar_course_id = $new_Calendar->Id;
    $insert = $DB->insert_record("course_calendar_ext", $course_calendar);
    error_log(print_r($insert, true));

}

function delete_course_calendar($data) {
    global $DB,$SESSION;
    error_log("delete_course_calendar called");
    error_log(print_r($data, true));
    $course_cal = $DB->get_record('course_calendar_ext',array("course_id" => $data->id));
    $curl = new curl();
    $header = array("Authorization: Bearer ". $SESSION->accesstoken);
    $curl->setHeader($header);
    $new_Calendar = $curl->delete("https://outlook.office365.com/ews/odata/Me/Calendars('".$course_cal->calendar_course_id."')");
}

function subscribe_to_course_calendar($data) {
    error_log("subscribe_to_course_calendar called");
    error_log(print_r($data, true));

    // TODO: Get O365 calendar id for the course from course table
    // TODO: Get student UPN and share the calendar with them
    // TODO: If possible, let the student accept the request automatically. (Otherwise let them do it manually.)
}

function unsubscribe_from_course_calendar($data) {
    error_log("unsubscribe_from_course_calendar called");
    error_log(print_r($data, true));
}
