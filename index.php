<?php
/**
 * Novozymes report
 *
 * @package    report
 * @subpackage novozymes
 * @author Adam Vongrej
 */

require('../../config.php');
require_once($CFG->dirroot.'/lib/tablelib.php');

$PAGE->requires->css('/report/novozymes/styles/jquery-ui.css');

define('DEFAULT_PAGE_SIZE', 20);
define('SHOW_ALL_PAGE_SIZE', 5000);

$timefrom   = optional_param('timefrom', 0, PARAM_RAW); // how far back to look...
$timeto   = optional_param('timeto', 0, PARAM_RAW); // how far back to look...
$page       = optional_param('page', 0, PARAM_INT);                     // which page to show
$perpage    = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT);  // how many per page
$download = optional_param('download', null, PARAM_ALPHA); // exporting report

$url = new moodle_url('/report/novozymes/index.php', array('id'=>$id));

if ($timefrom !== 0) $url->param('timefrom');
if ($timeto !== 0) $url->param('timeto');
if ($page !== 0) $url->param('page');
if ($perpage !== DEFAULT_PAGE_SIZE) $url->param('perpage');

$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');

$context = context_system::instance();
require_capability('report/novozymes:view', $context);

$PAGE->set_title(get_string('title', 'report_novozymes'));
$PAGE->set_heading(get_string('title', 'report_novozymes'));

if(empty($download)){
	echo $OUTPUT->header();

	// print first controls.
	echo '<form class="participationselectform" action="index.php" method="get"><div>'."\n".
		 '<input type="hidden" name="id" value="'.$course->id.'" />'."\n";
	echo '<label for="menutimefrom">'.get_string('from').'</label>'."\n";
	//echo html_writer::tag('label', 'Format: `dd-mm-YYYY`', array('for' => 'menutimefrom'));
	echo html_writer::tag('input', null, 
		array('type' => 'text', 'name' => 'timefrom', 'id' => 'menutimefrom', 'value' => $timefrom));
	echo '<label for="menutimeto">'.get_string('to').'</label>'."\n";
	//echo html_writer::tag('label', 'Format: `dd-mm-YYYY`', array('for' => 'menutimeto'));
	echo html_writer::tag('input', null, 
		array('type' => 'text', 'name' => 'timeto', 'id' => 'menutimeto', 'value' => $timeto));
	echo '<input type="submit" value="'.get_string('go').'" />'."\n</div></form>\n";
}

// Trigger a content view event.
$event = \report_novozymes\event\content_viewed::create(array('other'    => array('content' => 'participants')));
$event->set_page_detail();
$event->set_legacy_logdata(array($course->id, "course", "report novozymes",
        "report/novozymes/index.php?id=$course->id", $course->id));
$event->trigger();

$baseurl = new moodle_url('/report/novozymes/index.php', array(
    'timefrom' => $timefrom,
    'timeto' => $timeto,
    'perpage' => $perpage,
    'group' => $currentgroup
));

$table = new flexible_table('course-participations');

$table->define_columns(array('metaid', 'name', 'count', 'dc_count', 'duration_total'));
$table->define_headers(array(get_string('id', 'report_novozymes'), get_string('name', 'report_novozymes'), 
	get_string('count', 'report_novozymes'), get_string('dc_count', 'report_novozymes'),
	get_string('duration', 'report_novozymes')));
$table->define_baseurl($baseurl);

$table->set_attribute('cellpadding','5');
$table->set_attribute('class', 'generaltable generalbox reporttable');

$table->sortable(true,'id','ASC');
$table->no_sorting('select');

$table->is_downloadable(true);

$table->set_control_variables(array(
									TABLE_VAR_SORT    => 'ssort',
									TABLE_VAR_HIDE    => 'shide',
									TABLE_VAR_SHOW    => 'sshow',
									TABLE_VAR_PAGE    => 'spage'
									));
$table->setup();

$table->is_downloading($download, 'report_'.date('Y-m-d'));

if ($table->get_sql_sort()) {
	$sql .= ' ORDER BY '.$table->get_sql_sort();
}

$total_count = $DB->count_records_sql("SELECT id from {course}");

if(empty($download)){
	echo '<div id="participationreport">' . "\n";
	$table->initialbars($total_count > $perpage);
	$table->pagesize($perpage, $total_count);
}

$where = '';
$params = array('roleid' => 5);

if($timefrom != 0){
	$where .= 'dc.startdate >= :startdate';
	$params['startdate'] = strtotime($timefrom);
}
if($timeto != 0){
	$where .= !empty($where) ? ' AND dc.enddate <= :enddate' : 'dc.enddate <= :enddate';
	$params['enddate'] = strtotime($timeto);
}

if(!empty($where)){
	$where = "WHERE {$where}";
}

#Issue with filtering enrol table by role_id which removes some of the meta courses
/*
$sql = "SELECT mc.id as metaid, dc.id as id, mc.name as name, count(ue.userid) as count, 
	mc.duration, mc.duration_unit, (mc.duration * mc.duration_unit) as duration_total
	FROM  {meta_course} mc
	LEFT JOIN  {meta_datecourse} dc ON mc.id = dc.metaid 
	LEFT JOIN {enrol} e ON e.courseid = dc.courseid 
	LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id {$where}
	GROUP BY mc.id";
	*/
	
$sql = "SELECT mc.id as metaid, dc.id as id, mc.name as name, 
	count(ue.userid) as count, count(DISTINCT dc.id) as dc_count,
	mc.duration, mc.duration_unit, (mc.duration * mc.duration_unit) as duration_total
	FROM  {meta_course} mc
	LEFT JOIN  {meta_datecourse} dc ON mc.id = dc.metaid 
	LEFT JOIN (SELECT * FROM enrol WHERE roleid = :roleid) as e ON e.courseid = dc.courseid
	LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id {$where}
	GROUP BY mc.id";
	
/*	
$sql = "SELECT mc.id, mc.name, mc.duration, mc.duration_unit, (mc.duration * mc.duration_unit) as duration_total,
	(SELECT count(*) FROM {meta_datecourse} dc
		JOIN {enrol} e ON e.courseid = dc.courseid 
		LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
		WHERE e.roleid = :roleid and dc.metaid = mc.id GROUP BY mc.id) as count
	FROM {meta_course} mc JOIN {meta_datecourse} dc ON mc.id = dc.metaid {$where}";
*/

if ($table->get_sql_sort()) {
	$sql .= ' ORDER BY '.$table->get_sql_sort();
}

$courses = $DB->get_records_sql($sql, $params);
$total_count = count($courses);

$table->start_output();

foreach($courses as $course){
	$data = array($course->metaid);
	
	if(empty($download)){
		array_push($data, html_writer::tag('a', $course->name, array('href' => "{$CFG->wwwroot}/blocks/metacourse/view_metacourse.php?id={$course->metaid}")));
	}
	else{
		array_push($data, $course->name);
	}
	
	array_push($data, $course->count, $course->dc_count);
		
	switch($course->duration_unit){
		//Seconds
		case 1:
			array_push($data, $course->duration . ($course->duration == 1 ? ' second' : ' seconds'));
			break;
		//Minutes
		case 60:
			array_push($data, $course->duration . ($course->duration == 1 ? ' minute' : ' minutes'));
			break;
		//Hours
		case 3600:
			array_push($data, $course->duration . ($course->duration == 1 ? ' hour' : ' hours'));
			break;
		//Days
		case 86400:
			array_push($data, $course->duration . ($course->duration == 1 ? ' day' : ' days'));
			break;
		//Weeks
		case 604800:
			array_push($data, $course->duration . ($course->duration == 1 ? ' week' : ' weeks'));
			break;
		default:
			//TODO: maybe handle this differently
			continue;
	}
	$table->add_data($data);
}

$table->finish_output();

if ($perpage == SHOW_ALL_PAGE_SIZE) {
	$perpageurl = new moodle_url($baseurl, array('perpage' => DEFAULT_PAGE_SIZE));
	echo html_writer::start_div('', array('id' => 'showall'));
	echo html_writer::link($perpageurl, get_string('showperpage', '', DEFAULT_PAGE_SIZE));
	echo html_writer::end_div();
} else if ($matchcount > 0 && $perpage < $matchcount) {
	$perpageurl = new moodle_url($baseurl, array('perpage' => SHOW_ALL_PAGE_SIZE));
	echo html_writer::start_div('', array('id' => 'showall'));
	echo html_writer::link($perpageurl, get_string('showall', '', $matchcount));
	echo html_writer::end_div();
}

$PAGE->requires->js_init_call('M.report_novozymes.init');

//$PAGE->requires->js("/report/novozymes/js/modernizr-custom.js");
//$PAGE->requires->js("/report/novozymes/js/polyfiller.js");
//$PAGE->requires->js("/report/novozymes/js/main.js");

$PAGE->requires->jquery();
$PAGE->requires->js("/report/novozymes/js/jquery-1.12.3.min.js");
$PAGE->requires->js("/report/novozymes/js/jquery-ui.min.js");
$PAGE->requires->js("/report/novozymes/js/main.js");

if(empty($download)){
	echo $table->download_buttons();
	echo $OUTPUT->footer();	
}
