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
$affected_rows = 0;

$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);

// Write Data to Database ...
$db =  new PDO('mysql:host=localhost;dbname=ionrs_paxdb;charset=utf8', 'ionrs_pax_user', 'I0NR$_P@X_U53R_P@55w0rd');
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );

foreach (glob("/home/bitnami/htdocs/GateLogs/UnitStatus/*.*") as $filename) {
 
 echo $filename . "\n";

 $server_details = parse_ini_file($filename);

 print_r($server_details);

 /* 
  * if Exists, add or update
  */

 if (strpos($filename, 'Server') !== false) {

	$ip = explode('.', $filename)[1];
	$device_type = 'Server';

 } else {

	$ip = explode('.', $filename)[0];
	$ip = end(explode('/',$ip));
	$device_type = 'Sensor';

 }


$stmt = $db->prepare("Select ip as result  from psm_servers where ip = :ip");

$stmt->execute(array(':ip' => $ip));

if($stmt->rowCount()) {

    echo 'Found: ' . "\n";

} else {

     echo 'Not Found' . "\n";

     $stmt = $db->prepare("INSERT INTO psm_servers (ip,  label,     type,  status,  last_check) 
			                     VALUES(:ip, 'Default', :type, :status, NOW());");

	$stmt->execute(array(':ip'            => $ip, 
			     ':type'          => $device_type,
			     ':status'        => 'on'));

	/*
	 * if type server and we had to add then we need to set the alerts for servers to include
	 */

	if($device_type=='Server') {
		$affected_rows += $stmt->rowCount();
	}
}
}

if($affected_rows) {

	$stmt = $db->prepare("Insert into psm_users_servers (server_id, user_id) 
			      Select server_id, user_id  from psm_servers join psm_users where type = 'Server';");
	$stmt->execute();
}

?>
