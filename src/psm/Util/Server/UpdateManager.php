<?php

/**
 * PHP Server Monitor
 * Monitor your servers and websites.
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     phpservermon
 * @author      Pepijn Over <pep@mailbox.org>
 * @copyright   Copyright (c) 2008-2017 Pepijn Over <pep@mailbox.org>
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: @package_version@
 * @link        http://www.phpservermonitor.org/
 * @since       phpservermon 3.0.0
 **/

namespace psm\Util\Server;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Run an update on all servers.
 */
class UpdateManager implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Add any new servers automatically
     */
    public function addnewservers()
    {
	$affected_rows = 0;
	foreach (glob("/home/bitnami/htdocs/GateLogs/UnitStatus/*.*") as $filename) {
		echo $filename . "\n";
		$server_details = parse_ini_file($filename);

 		if (strpos($filename, 'Server') !== false) {
			$ip = explode('.', $filename)[1];
			$device_type = 'Server';
		} else {
			$ip = explode('.', $filename)[0];
			$parts = explode('/',$ip);
			$ip = end($parts);
			$device_type = 'Sensor';
		}

		$sql  = "Select ip as result  from psm_servers where ip = '$ip'";
		$stmt = $this->container->get('db')->query($sql);
		
		if(!count($stmt)) {
		    	echo 'Not Found' . "\n";
     			$sql = "INSERT INTO psm_servers (ip,    label,     type,           status, last_check) 
		  		 		  VALUES('$ip', 'Default', '$device_type', 'on',   NOW());";

			$stmt = $this->container->get('db')->query($sql);

			/*
		 	 * if type server and we had to add then we need to set the alerts for servers to include
	 		 */

			if($device_type=='Server') {
				$affected_rows += count($stmt);
			}
		}
	}

	if($affected_rows) {
		echo 'Updating user permissions' . "/n";
		$sql = "Insert into psm_users_servers (server_id, user_id) 
		        Select server_id, user_id  from psm_servers join psm_users where type = 'Server';";
		$stmt = $this->container->get('db')->query($sql);
	}
    }

    /**
     * Load PAX Data 
     */
    public function loadpax()
    {
	$affected_rows = 0;
	foreach (glob("/home/bitnami/htdocs/GateLogs/PAX/") as $filename) {
		echo 'Directory: ' . $filename . "\n";
		
		/**
		 * Get the latest PAX record and server id from the database
		 * Then scan that directory for any filemtime > that date/time
		 */
		
		$sql  = "Select ip as result  from psm_servers where ip = '$ip'";
		$stmt = $this->container->get('db')->query($sql);
		
	}

    }

   /**
     * Go :-)
     *
     * @param boolean $skip_perms if TRUE, no user permissions will be taken in account and all servers will be updated
     * @param string|null $status If all servers (null), or just `on` or `off` should be checked.
     */
    public function run($skip_perms = false, $status = null)
    {
     	
	// add any new servers first
	$this->addnewservers();
	
	// load pax data
	$this->loadpax();
	    
	    
	// added green, yellow, red
	if (false === in_array($status, ['on', 'off', 'green', 'yellow', 'red'], true)) {
            $status = null;
        }

	// check if we need to restrict the servers to a certain user
        $sql_join = '';

        if (!$skip_perms && $this->container->get('user')->getUserLevel() > PSM_USER_ADMIN) {
            // restrict by user_id
            $sql_join = "JOIN `" . PSM_DB_PREFIX . "users_servers` AS `us` ON (
                        `us`.`user_id`={$this->container->get('user')->getUserId()}
                        AND `us`.`server_id`=`s`.`server_id`
                        )";
        }

        $sql = "SELECT `s`.`server_id`,`s`.`ip`,`s`.`port`,`s`.`label`,`s`.`type`,`s`.`pattern`,`s`.`header_name`,
            `s`.`header_value`,`s`.`status`,`s`.`active`,`s`.`email`,`s`.`sms`,`s`.`pushover`,`s`.`webhook`,`s`.`telegram`, 
            `s`.`jabber`
                FROM `" . PSM_DB_PREFIX . "servers` AS `s`
                {$sql_join}
                WHERE `active`='yes' " . ($status !== null ? ' AND `status` = \'' . $status . '\'' : '');

        $servers = $this->container->get('db')->query($sql);

        $updater = new Updater\StatusUpdater($this->container->get('db'));
        $notifier = new Updater\StatusNotifier($this->container->get('db'));

        foreach ($servers as $server) {
            $status_old = $server['status']; // ($server['status'] == 'on') ? true : false;
            $status_new = $updater->update($server['server_id']);
		
	    echo 'Status Old: ' . $status_old . "\n";
	    echo 'Status New: ' . $status_new . "\n";
	
	    /*	
	    if($status_old=='green') {
	       $status_new = 'yellow';
	    } elseif($status_old=='yellow') {
		$status_new = 'red';
	    } elseif($status_old=='red') {
		$status_new = 'green';
	    }	
	    */
		
            // notify the nerds if applicable
		
	    /* ION Custom Code -- Suppress Sensor Warnings from being emailed
	     * 
	     */
	    if(strtolower($server['type']) != 'sensor') {
               $notifier->notify($server['server_id'], $status_old, $status_new);
	    }
		
	    // clean-up time!! archive all records
            $archive = new ArchiveManager($this->container->get('db'));
            $archive->archive($server['server_id']);
            $archive->cleanup($server['server_id']);
        }
        if ($notifier->combine) {
            $notifier->notifyCombined();
        }
    }
}
