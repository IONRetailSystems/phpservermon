<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('TIMEZONE', 'America/New_York');

date_default_timezone_set(TIMEZONE);



$now = new DateTime();
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;

$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);

// Write Data to Database ...
$db =  new PDO('mysql:host=localhost;dbname=ionrs_paxdb;charset=utf8', 'ionrs_pax_user', 'I0NR$_P@X_U53R_P@55w0rd');
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );


foreach (glob("/home/bitnami/htdocs/GateLogs/UnitStatus/Server.*") as $filename) {
 
 echo $filename . "\n";

 $server_details = parse_ini_file($filename);

 print_r($server_details);

 /* 
  * if Exists, add or update
  */

$ip = explode('.', $filename)[1];

/*
 * Status: Green, Yellow, Red
 */

$status = 'green';

/*
 * Yellow is indicative of some server issue not impacting system
 */

if(strtolower($server_details['server_status']) != 'ok') {
 $status = 'yellow';
}

if(strtolower($server_details['sensors_state']) !='ok') {
 $status = 'red';
}


echo 'Status: ' . $status . "\n";

$stmt = $db->prepare("Select ip as result  from psm_servers where ip = :ip");

$stmt->execute(array(':ip' => $ip));

if($stmt->rowCount()) {

    echo 'Found: ' . "\n";

    $stmt = $db->prepare("Update psm_servers Set label         = :label,
						 status        = :status,
                                                 server_status = :server_status,
	     				         sensor_status = :sensor_status,
					         last_counts   = :last_counts,
					         last_online   = :last_online,
					         last_check    = NOW()
				 WHERE ip = :ip;");


    $stmt->execute(array(':ip'            => $ip,
	                 ':label'=> $server_details['district'],
			 ':status'        => $status,
		         ':server_status' => $server_details['server_status'],
                         ':sensor_status' => $server_details['sensors_state'],
			 ':last_counts'   => $server_details['last_counts'],
			 ':last_online'   => $server_details['status_set']));

} else {

     echo 'Not Found' . "\n";

     $stmt = $db->prepare("INSERT INTO psm_servers (ip, label, type, status, server_status, sensor_status, 
						    last_counts, last_online,last_check) 
			             VALUES(:ip, :label, :type, :status, :server_status, :sensor_status, :last_counts, 
					    :last_online, NOW());");

	$stmt->execute(array(':ip'            => $ip, 
			     ':label'         => $server_details['district'],
			     ':type'          => 'server',
			     ':status'        => $status,
			     ':server_status' => $server_details['server_status'],
			     ':sensor_status' => $server_details['sensors_state'],
			     ':last_counts'   => $server_details['last_counts'],
			     ':last_online'   => $server_details['status_set']));

}

	$affected_rows = $stmt->rowCount();

echo 'Rows: ' . $affected_rows . "\n";
}

?>
