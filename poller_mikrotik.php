#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

$no_http_headers = true;

chdir(dirname(__FILE__));
chdir('../..');
include('./include/global.php');
include_once('./lib/poller.php');
include_once('./plugins/mikrotik/snmp.php');
include_once('./lib/ping.php');
include_once('./plugins/mikrotik/RouterOS/routeros_api.class.php');

ini_set('memory_limit', '256M');

if ($config['poller_id'] > 1) {
	exit;
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $key, $forcerun;

$debug          = FALSE;
$forcerun       = FALSE;
$forcediscovery = FALSE;
$mainrun        = FALSE;
$host_id        = '';
$start          = '';
$seed           = '';
$key            = '';

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = TRUE;
				break;
			case '--host-id':
				$host_id = $value;
				break;
			case '--seed':
				$seed = $value;
				break;
			case '--key':
				$key = $value;
				break;
			case '-f':
			case '--force':
				$forcerun = TRUE;
				break;
			case '-fd':
			case '--force-discovery':
				$forcediscovery = TRUE;
				break;
			case '-M':
				$mainrun = TRUE;
				break;
			case '-s':
			case '--start':
				$start = $value;
				break;
			case '-v':
			case '-V':
			case '--version':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit;
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
				display_help();
				exit;
		}
	}
}

/* Check for mandatory parameters */
if (!$mainrun && $host_id == '') {
	echo "FATAL: You must specify a Cacti host-id run\n";
	exit;
}

/* Do not process if not enabled */
if (read_config_option('mikrotik_enabled') == '' || db_fetch_cell("SELECT status FROM plugin_config WHERE directory='mikrotik'") != 1) {
	echo "WARNING: The MikroTik Collection is Down!  Exiting\n";
	exit(0);
}

if ($seed == '') {
	$seed = rand();
}

if ($start == '') {
	list($micro,$seconds) = explode(' ', microtime());
	$start = $seconds + $micro;
}

if ($mainrun) {
	process_hosts();
	getLatestVersion();
}else{
	checkHost($host_id);
}

exit(0);

function getLatestVersion() {
	$t = intval(read_config_option('mikrotik_latestversioncheck'));
	if ($t == 0 || time() - $t > 86400) {
		$latest = file_get_contents('http://upgrade.mikrotik.com/routeros/LATEST.6');
		if ($latest) {
			$latest = explode(' ', $latest);
			if (isset($latest[1])) {
				$latest = $latest[0];
				if ($latest > 0) {
					set_config_option('mikrotik_latestversion', $latest);
				}
			}
		}
		set_config_option('mikrotik_latestversioncheck', time());
	}
}

function runCollector($start, $lastrun, $frequency) {
	global $forcerun;

	if ($frequency == -1) {
		return false;
	}elseif (empty($lastrun)) {
		return true;
	}elseif ($start - $lastrun > ($frequency - 55)) {
		return true;
	}elseif ($forcerun) {
		return true;
	}else{
		return false;
	}
}

function debug($message) {
	global $debug;
	static $timer = 0;

	$mytime = time();
	if ($timer == 0) {
		$elapsed = 0;
		$timer = $mytime;
	}else{
		$elapsed = $mytime - $timer;
	}

	if ($debug) {
		echo 'DEBUG: Elapsed: ' . $elapsed . ', Message: ' . trim($message) . "\n";
		flush();
	}
}

function autoDiscoverHosts() {
	global $debug, $start;

	$hosts = db_fetch_assoc("SELECT *
		FROM host
		WHERE snmp_version>0
		AND disabled!='on'
		AND status!=1");

	$template_id = db_fetch_cell('SELECT id FROM host_template WHERE hash="d364e2b9570f166ab33c8df8bd503887"');

	debug("Starting AutoDiscovery for '" . sizeof($hosts) . "' Hosts");

	/* set a process lock */
	db_execute('REPLACE INTO plugin_mikrotik_processes (pid, taskid) VALUES (' . getmypid() . ', 0)');

	if (sizeof($hosts)) {
	foreach($hosts as $host) {
		debug("AutoDiscovery Check for Host '" . $host['description'] . ' [' . $host['hostname'] . "]'");
		if (strpos($host['snmp_sysDescr'], 'RouterOS') !== false) {
			debug("Host '" . $host['description'] . '[' . $host['hostname'] . "]' Supports MikroTik Resources");
			db_execute('INSERT INTO plugin_mikrotik_system (host_id) VALUES (' . $host['id'] . ') ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)');
		}else if ($host['host_template_id'] == $template_id) {
			debug("Host '" . $host['description'] . '[' . $host['hostname'] . "]' Supports MikroTik Resources");
			db_execute('INSERT INTO plugin_mikrotik_system (host_id) VALUES (' . $host['id'] . ') ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)');
		}
	}
	}

	/* remove the process lock */
	db_execute('DELETE FROM plugin_mikrotik_processes WHERE pid=' . getmypid());
	db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_autodiscovery_lastrun', '" . time() . "')");

	return true;
}

function process_hosts() {
	global $start, $seed, $key;

	echo "NOTE: Processing Hosts Begins\n";

	/* All time/dates will be stored in timestamps
	 * Get Autodiscovery Lastrun Information
	 */
	$auto_discovery_lastrun = read_config_option('mikrotik_autodiscovery_lastrun');

	/* Get Collection Frequencies (in seconds) */
	$auto_discovery_freq = read_config_option('mikrotik_autodiscovery_freq');

	/* Set the booleans based upon current times */
	if (read_config_option('mikrotik_autodiscovery') == 'on') {
		echo "NOTE: Auto Discovery Starting\n";

		if (runCollector($start, $auto_discovery_lastrun, $auto_discovery_freq)) {
			autoDiscoverHosts();
		}

		echo "NOTE: Auto Discovery Complete\n";
	}

	/* Purge collectors that run longer than 10 minutes */
	db_execute('DELETE FROM plugin_mikrotik_processes WHERE (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(started)) > 600');

	/* Do not process collectors are still running */
	if (db_fetch_cell('SELECT count(*) FROM plugin_mikrotik_processes') > 0) {
		echo "WARNING: Another MikroTik Collector is still running!  Exiting\n";
		exit(0);
	}

	$types = array('storage', 'trees', 'users', 'queues', 'interfaces', 'wireless_aps', 'wireless_reg');
	$run = false;
	foreach ($types as $t) {
		$lastrun = read_config_option('mikrotik_' . $t . '_lastrun');
		$freq = read_config_option('mikrotik_' . $t . '_freq');
		if (runCollector($start, $lastrun, $freq)) {
			$run = true;
		}
	}
	if (!$run) {
		print "No collectors scheduled for this run, exiting\n";
		exit;
	}

	/* The hosts to scan will
	 *  1) Not be disabled,
	 *  2) Be linked to the host table
	 *  3) Be up and operational
	 */
	$hosts = db_fetch_assoc("SELECT hm.host_id, h.description, h.hostname 
		FROM plugin_mikrotik_system AS hm
		INNER JOIN host AS h
		ON h.id=hm.host_id
		WHERE h.disabled!='on'
		AND h.status!=1");

	/* Remove entries from  down and disabled hosts */
	db_execute("DELETE FROM plugin_mikrotik_processor 
		WHERE host_id IN(SELECT id FROM host AS h WHERE disabled='on' OR h.status=1)");

	$concurrent_processes = read_config_option('mikrotik_concurrent_processes');

	echo "NOTE: Launching Collectors Starting\n";

	$i = 0;
	if (sizeof($hosts)) {
	foreach ($hosts as $host) {
		while ( true ) {
			$processes = db_fetch_cell('SELECT COUNT(*) FROM plugin_mikrotik_processes');

			if ($processes < $concurrent_processes) {
				/* put a placeholder in place to prevent overloads on slow systems */
				$key = rand();
				db_execute("INSERT INTO plugin_mikrotik_processes (pid, taskid, started) VALUES ($key, $seed, NOW())");

				echo "NOTE: Launching Host Collector For: '" . $host['description'] . '[' . $host['hostname'] . "]'\n";
				process_host($host['host_id'], $seed, $key);
				usleep(10000);

				break;
			}else{
				sleep(1);
			}
		}
	}
	}

	/* taking a break cause for slow systems slow */
	sleep(5);

	echo "NOTE: All Hosts Launched, proceeding to wait for completion\n";

	/* wait for all processes to end or max run time */
	while ( true ) {
		$processes_left = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_processes WHERE taskid=$seed");
		$pl = db_fetch_cell('SELECT count(*) FROM plugin_mikrotik_processes');

		if ($processes_left == 0) {
			echo "NOTE: All Processees Complete, Exiting\n";
			break;
		}else{
			echo "NOTE: Waiting on '$processes_left' Processes\n";
			sleep(2);
		}
	}

	echo "NOTE: Updating Last Run Statistics\n";

	// Update the last runtimes
	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$storage_lastrun      = read_config_option('mikrotik_storage_lastrun');
	$trees_lastrun        = read_config_option('mikrotik_trees_lastrun');
	$users_lastrun        = read_config_option('mikrotik_users_lastrun');
	$queues_lastrun       = read_config_option('mikrotik_queues_lastrun');
	$interfaces_lastrun   = read_config_option('mikrotik_interfaces_lastrun');
	$processor_lastrun    = read_config_option('mikrotik_processor_lastrun');
	$wireless_reg_lastrun = read_config_option('mikrotik_wireless_reg_lastrun');

	// Get Collection Frequencies (in seconds)
	$storage_freq        = read_config_option('mikrotik_storage_freq');
	$processor_freq      = read_config_option('mikrotik_processor_freq');
	$trees_freq          = read_config_option('mikrotik_trees_freq');
	$users_freq          = read_config_option('mikrotik_users_freq');
	$queues_freq         = read_config_option('mikrotik_queues_freq');
	$interfaces_freq     = read_config_option('mikrotik_interfaces_freq');
	$wireless_reg_freq   = read_config_option('mikrotik_wireless_reg_freq');

	/* set the collector statistics */
	if (runCollector($start, $storage_lastrun, $storage_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_storage_lastrun', '$start')");
	}

	if (runCollector($start, $trees_lastrun, $trees_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_trees_lastrun', '$start')");

		/* for users that are active, increment data */
		db_execute("UPDATE plugin_mikrotik_trees
			SET curBytes=IF(prevBytes>0 AND bytes>prevBytes,(bytes-prevBytes)/$trees_freq,0),
			curPackets=IF(prevPackets>0 AND packets>prevPackets,(packets-prevPackets)/$trees_freq,0),
			curHCBytes=IF(prevHCBytes>0 AND HCBytes>prevHCBytes,(HCBytes-prevHCBytes)/$trees_freq,0)
			WHERE present=1");

		/* for users that are active, store previous data */
		db_execute('UPDATE plugin_mikrotik_trees
			SET prevBytes=bytes,
			prevPackets=packets,
			prevHCBytes=HCBytes
			WHERE present=1');

		/* for users that are inactive, clear information */
		db_execute('UPDATE plugin_mikrotik_trees
			SET bytes=0, packets=0, HCBytes=0, 
			curBytes=0, curPackets=0, curHCBytes=0, 
			prevBytes=0, prevPackets=0, prevHCBytes=0
			WHERE present=0');
	}

	if (runCollector($start, $users_lastrun, $users_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_users_lastrun', '$start')");

		/* for users that are active, increment data */
		db_execute("UPDATE plugin_mikrotik_users
			SET curBytesIn=IF(prevBytesIn>0 AND bytesIn>prevBytesIn,(bytesIn-prevBytesIn)/$users_freq,0),
			curBytesOut=IF(prevBytesIn>0 AND bytesOut>prevBytesOut,(bytesOut-prevBytesOut)/$users_freq,0),
			curPacketsIn=IF(prevPacketsIn>0 AND packetsIn>prevPacketsIn,(packetsIn-prevPacketsIn)/$users_freq,0),
			curPacketsOut=IF(prevPacketsOut>0 AND packetsOut>prevPacketsOut,(packetsOut-prevPacketsOut)/$users_freq,0)
			WHERE present=1");

		/* for users that are active, store previous data */
		db_execute('UPDATE plugin_mikrotik_users
			SET prevBytesIn=bytesIn,
			prevBytesOut=bytesOut,
			prevPacketsIn=packetsIn,
			prevPacketsOut=packetsOut
			WHERE present=1');

		/* for users that are inactive, clear information */
		db_execute('UPDATE plugin_mikrotik_users
			SET bytesIn=0, bytesOut=0, packetsIn=0, packetsOut=0, 
			curBytesIn=0, curBytesOut=0, curPacketsIn=0, curPacketsOut=0, 
			prevBytesIn=0, prevBytesOut=0, prevPacketsIn=0, prevPacketsOut=0, 
			connectTime=0
			WHERE present=0 AND userType=0');
	}

	if (runCollector($start, $wireless_reg_lastrun, $wireless_reg_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_wireless_reg_lastrun', '$start')");

		/* for users that are active, increment data */
		db_execute("UPDATE plugin_mikrotik_wireless_registrations
			SET curTxBytes=IF(prevTxBytes>0 AND TxBytes>prevTxBytes,(TxBytes-prevTxBytes)/$wireless_reg_freq,0),
			curRxBytes=IF(prevRxBytes>0 AND RxBytes>prevRxBytes,(RxBytes-prevRxBytes)/$wireless_reg_freq,0),
			curTxPackets=IF(prevTxPackets>0 AND TxPackets>prevTxPackets,(TxPackets-prevTxPackets)/$wireless_reg_freq,0),
			curRxPackets=IF(prevRxPackets>0 AND RxPackets>prevRxPackets,(RxPackets-prevRxPackets)/$wireless_reg_freq,0)
			WHERE present=1");

		/* for users that are active, store previous data */
		db_execute('UPDATE plugin_mikrotik_wireless_registrations
			SET prevTxBytes=TxBytes,
			prevRxBytes=RxBytes,
			prevTxPackets=TxPackets,
			prevRxPackets=RxPackets
			WHERE present=1');

		/* for users that are inactive, clear information */
		db_execute('UPDATE plugin_mikrotik_wireless_registrations
			SET TxBytes=0, TxPackets=0, RxBytes=0, RxPackets=0,
			curTxBytes=0, curTxPackets=0, curRxBytes=0, curRxPackets=0,
			prevTxBytes=0, prevTxPackets=0, prevRxBytes=0, prevRxPackets=0,
			Uptime=0
			WHERE present=0');
	}

	if (runCollector($start, $processor_lastrun, $processor_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_processor_lastrun', '$start')");
	}

	if (runCollector($start, $interfaces_lastrun, $interfaces_freq)) {
		global $mikrotikInterfaces;

		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_interfaces_lastrun', '$start')");

		$sql = '';
		foreach($mikrotikInterfaces as $key => $oid) {
			if ($key == 'index') continue;
			if ($key == 'name') continue;

			$sql .= (strlen($sql) ? ',':'') . 'cur' . $key . '=IF(prev' . $key . '>0 AND ' . $key . '>prev' . $key . ',(' . $key . '-prev' . $key . ')/' . $interfaces_freq . ',0)';
		}

		/* for users that are active, increment data */
		db_execute("UPDATE plugin_mikrotik_interfaces SET $sql WHERE present=1");

		$sql = '';
		foreach($mikrotikInterfaces as $key => $oid) {
			if ($key == 'index') continue;
			if ($key == 'name') continue;

			$sql .= (strlen($sql) ? ',':'') . 'prev' . $key . '=' . $key;
		}

		/* for users that are active, store previous data */
		db_execute("UPDATE plugin_mikrotik_interfaces SET $sql WHERE present=1");

		$sql = '';
		foreach($mikrotikInterfaces as $key => $oid) {
			if ($key == 'index') continue;
			if ($key == 'name') continue;

			$sql .= (strlen($sql) ? ',':'') . $key . '=0, cur' . $key . '=0, prev' . $key . '=0';
		}

		/* for users that are inactive, clear information */
		db_execute("UPDATE plugin_mikrotik_interfaces SET $sql WHERE present=0");
	}

	if (runCollector($start, $queues_lastrun, $queues_freq)) {
		db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_queues_lastrun', '$start')");

		/* for users that are active, increment data */
		db_execute("UPDATE plugin_mikrotik_queues
			SET curBytesIn=IF(prevBytesIn>0 AND bytesIn>prevBytesIn,(bytesIn-prevBytesIn)/$queues_freq,0),
			curBytesOut=IF(prevBytesIn>0 AND bytesOut>prevBytesOut,(bytesOut-prevBytesOut)/$queues_freq,0),
			curPacketsIn=IF(prevPacketsIn>0 AND packetsIn>prevPacketsIn,(packetsIn-prevPacketsIn)/$queues_freq,0),
			curPacketsOut=IF(prevPacketsOut>0 AND packetsOut>prevPacketsOut,(packetsOut-prevPacketsOut)/$queues_freq,0),
			curQueuesIn=IF(prevQueuesIn>0 AND queuesIn>prevQueuesIn,(queuesIn-prevQueuesIn)/$queues_freq,0),
			curQueuesOut=IF(prevQueuesOut>0 AND queuesOut>prevQueuesOut,(queuesOut-prevQueuesOut)/$queues_freq,0),
			curDroppedIn=IF(prevDroppedIn>0 AND droppedIn>prevDroppedIn,(droppedIn-prevDroppedIn)/$queues_freq,0),
			curDroppedOut=IF(prevDroppedOut>0 AND droppedOut>prevDroppedOut,(droppedOut-prevDroppedOut)/$queues_freq,0)
			WHERE present=1");

		/* for users that are active, store previous data */
		db_execute('UPDATE plugin_mikrotik_queues
			SET prevBytesIn=bytesIn,
			prevBytesOut=bytesOut,
			prevPacketsIn=packetsIn,
			prevPacketsOut=packetsOut,
			prevQueuesIn=QueuesIn,
			prevQueuesOut=QueuesOut,
			prevDroppedIn=DroppedIn,
			prevDroppedOut=DroppedOut
			WHERE present=1');

		/* for users that are inactive, clear information */
		db_execute('UPDATE plugin_mikrotik_queues
			SET bytesIn=0, bytesOut=0, packetsIn=0, packetsOut=0, queuesIn=0, queuesOut=0, droppedIn=0, droppedOut=0,
			curBytesIn=0, curBytesOut=0, curPacketsIn=0, curPacketsOut=0, curQueuesIn=0, curQueuesOut=0, curDroppedIn=0, curDroppedOut=0,
			prevBytesIn=0, prevBytesOut=0, prevPacketsIn=0, prevPacketsOut=0, prevQueuesIn=0, prevQueuesOut=0, prevDroppedIn=0, prevDroppedOut=0
			WHERE present=0');
	}

	if (read_config_option('mikrotik_autopurge') == 'on') {
		echo "NOTE: Auto Purging Hosts\n";

		$dead_hosts = db_fetch_assoc("SELECT host_id FROM plugin_mikrotik_system AS hr
			LEFT JOIN host AS h
			ON h.id=hr.host_id
			WHERE h.id IS NULL");

		if (sizeof($dead_hosts)) {
			foreach($dead_hosts as $host) {
				db_execute('DELETE FROM plugin_mikrotik_processor WHERE host_id='. $host['host_id']);
				db_execute('DELETE FROM plugin_mikrotik_system WHERE host_id='. $host['host_id']);
				db_execute('DELETE FROM plugin_mikrotik_trees WHERE host_id='. $host['host_id']);
				db_execute('DELETE FROM plugin_mikrotik_users WHERE host_id='. $host['host_id']);
				db_execute('DELETE FROM plugin_mikrotik_queues WHERE host_id='. $host['host_id']);
				echo "Purging Host with ID '" . $host['host_id'] . "'\n";
			}
		}
	}

	echo "NOTE: Updating Summary Statistics for Each Host\n";

	/* update some statistics in system */
	$stats = db_fetch_assoc('SELECT h.id AS host_id,
		h.status AS host_status,
		AVG(`load`) AS cpuPercent,
		COUNT(`load`) AS numCpus
		FROM host AS h
		INNER JOIN plugin_mikrotik_system AS hrs
		ON h.id=hrs.host_id
		LEFT JOIN plugin_mikrotik_processor AS hrp
		ON hrp.host_id=hrs.host_id
		GROUP BY h.id, h.status');

	if (sizeof($stats)) {
		$sql_insert = '';

		$sql_prefix = 'INSERT INTO plugin_mikrotik_system
			(host_id, host_status, cpuPercent, numCpus) VALUES ';

		$sql_suffix = ' ON DUPLICATE KEY UPDATE
			host_status=VALUES(host_status),
			cpuPercent=VALUES(cpuPercent),
			numCpus=VALUES(numCpus)';

		$j = 0;
		foreach($stats as $s) {
			$sql_insert .= (strlen($sql_insert) ? ', ':'') . '(' .
				$s['host_id']     . ', ' .
				$s['host_status'] . ', ' .
				(!empty($s['cpuPercent']) ? $s['cpuPercent']:'0') . ', ' .
				(!empty($s['numCpus'])    ? $s['numCpus']:'0')    . ')';

			$j++;

			if (($j % 200) == 0) {
				db_execute($sql_prefix . $sql_insert . $sql_suffix);
				$sql_insert = '';
			}
		}

		if (strlen($sql_insert)) {
			db_execute($sql_prefix . $sql_insert . $sql_suffix);
		}
	}

	/* update the memory information */
	db_execute('INSERT INTO plugin_mikrotik_system
		(host_id, memSize, memUsed, diskSize, diskUsed)
		SELECT host_id,
		SUM(CASE WHEN type=11 THEN size * allocationUnits ELSE 0 END) AS memSize,
		SUM(CASE WHEN type=11 THEN (used / size) * 100 ELSE 0 END) AS memUsed,
		SUM(CASE WHEN type=14 THEN size * allocationUnits ELSE 0 END) AS diskSize,
		SUM(CASE WHEN type=14 THEN (used / size) * 100 ELSE 0 END) AS diskUsed
		FROM plugin_mikrotik_storage
		WHERE type IN(11,14)
		GROUP BY host_id
		ON DUPLICATE KEY UPDATE
			memSize=VALUES(memSize),
			memUsed=VALUES(memUsed),
			diskSize=VALUES(diskSize),
			diskUsed=VALUES(diskUsed)');

	/* update the user information */
	db_execute('INSERT INTO plugin_mikrotik_system
		(host_id, users)
		SELECT host_id,
		COUNT(name) AS users
		FROM plugin_mikrotik_users
		WHERE present=1
		GROUP BY host_id
		ON DUPLICATE KEY UPDATE
			users=VALUES(users)');

	/* update the maxProcesses information */
	db_execute('UPDATE plugin_mikrotik_system SET maxProcesses=processes WHERE processes>maxProcesses');

	echo "NOTE: Detecting Host Types Based Upon Host Types Table\n";

	/* for hosts that are down, clear information */
	db_execute('UPDATE plugin_mikrotik_system
		SET users=0, cpuPercent=0, processes=0, memUsed=0, diskUsed=0, uptime=0, sysUptime=0
		WHERE host_status IN (0,1)');

	// Clear tables when disabled
	if ($storage_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_storage");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_storage WHERE host_id NOT IN (SELECT id FROM host)");
	}

	if ($processor_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_processor");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_processor WHERE host_id NOT IN (SELECT id FROM host)");
	}

	if ($trees_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_trees");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_trees WHERE host_id NOT IN (SELECT id FROM host)");
	}

	if ($users_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_users");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_users WHERE host_id NOT IN (SELECT id FROM host)");
	}

	if ($queues_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_queues");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_queues WHERE host_id NOT IN (SELECT id FROM host)");
	}

	if ($interfaces_freq == -1) {
		db_execute("TRUNCATE plugin_mikrotik_interfaces");
	}else{
		db_execute("DELETE FROM plugin_mikrotik_interfaces WHERE host_id NOT IN (SELECT id FROM host)");
	}

	/* prune old tables of orphan hosts */
	db_execute("DELETE FROM plugin_mikrotik_system WHERE host_id NOT IN (SELECT id FROM host)");
	db_execute("DELETE FROM plugin_mikrotik_system_health WHERE host_id NOT IN (SELECT id FROM host)");

	/* take time and log performance data */
	list($micro,$seconds) = explode(' ', microtime());
	$end = $seconds + $micro;

	$interfaces = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_interfaces WHERE present=1");
	$queues     = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_queues WHERE present=1");
	$users      = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_users WHERE present=1");
	$trees      = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_trees WHERE present=1");
	$waps       = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_wireless_aps WHERE present=1");
	$wreg       = db_fetch_cell("SELECT count(*) FROM plugin_mikrotik_wireless_registrations WHERE present=1");

	$cacti_stats = sprintf(
		'Time:%01.4f Processes:%s Hosts:%s Interfaces:%s Queues:%s Users:%s Trees:%s Waps:%s Wreg:%s',
		round($end-$start,2),
		$concurrent_processes,
		sizeof($hosts),
		$interfaces, 
		$queues, 
		$users, 
		$trees, 
		$waps, 
		$wreg
	);

	/* log to the database */
	db_execute("REPLACE INTO settings (name,value) VALUES ('stats_mikrotik', '" . $cacti_stats . "')");

	/* log to the logfile */
	cacti_log('MIKROTIK STATS: ' . $cacti_stats , TRUE, 'SYSTEM');
	echo "NOTE: MikroTik Polling Completed, $cacti_stats\n";

	/* launch the graph creation process */
	process_graphs();
}

function process_host($host_id, $seed, $key) {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option('path_php_binary'),' -q ' .
		$config['base_path'] . '/plugins/mikrotik/poller_mikrotik.php' .
		' --host-id=' . $host_id .
		' --start=' . $start .
		' --seed=' . $seed .
		' --key=' . $key .
		($forcerun ? ' --force':'') .
		($debug ? ' --debug':''));
}

function process_graphs() {
	global $config, $debug, $start, $forcerun;

	exec_background(read_config_option('path_php_binary'),' -q ' .
		$config['base_path'] . '/plugins/mikrotik/poller_graphs.php' .
		($forcerun ? ' --force':'') .
		($debug ? ' --debug':''));
}

function checkHost($host_id) {
	global $config, $start, $seed, $key;

	// All time/dates will be stored in timestamps;
	// Get Collector Lastrun Information
	$storage_lastrun      = read_config_option('mikrotik_storage_lastrun');
	$trees_lastrun        = read_config_option('mikrotik_trees_lastrun');
	$users_lastrun        = read_config_option('mikrotik_users_lastrun');
	$queues_lastrun       = read_config_option('mikrotik_queues_lastrun');
	$interfaces_lastrun   = read_config_option('mikrotik_interfaces_lastrun');
	$processor_lastrun    = read_config_option('mikrotik_processor_lastrun');
	$wireless_aps_lastrun = read_config_option('mikrotik_wireless_aps_lastrun');
	$wireless_reg_lastrun = read_config_option('mikrotik_wireless_reg_lastrun');

	// Get Collection Frequencies (in seconds)
	$storage_freq       = read_config_option('mikrotik_storage_freq');
	$trees_freq         = read_config_option('mikrotik_trees_freq');
	$users_freq         = read_config_option('mikrotik_users_freq');
	$queues_freq        = read_config_option('mikrotik_queues_freq');
	$interfaces_freq    = read_config_option('mikrotik_interfaces_freq');
	$processor_freq     = read_config_option('mikrotik_processor_freq');
	$wireless_aps_freq  = read_config_option('mikrotik_wireless_aps_freq');
	$wireless_reg_freq  = read_config_option('mikrotik_wireless_reg_freq');

	/* remove the key process and insert the set a process lock */
	if (!empty($key)) {
		db_execute("DELETE FROM plugin_mikrotik_processes WHERE pid=$key");
	}
	db_execute("REPLACE INTO plugin_mikrotik_processes (pid, taskid) VALUES (" . getmypid() . ", $seed)");

	/* obtain host information */
	$host = db_fetch_row("SELECT * FROM host WHERE id=$host_id");

	if (function_exists('snmp_read_mib')) {
		debug('Function snmp_read_mib() EXISTS!');
		snmp_read_mib($config['base_path'] . '/plugins/mikrotik/MIKROTIK-MIB.txt');
	}else{
		putenv('MIBS=all');
	}

	$system_up = collect_system($host);

	if (!$system_up) {
		cacti_log('MikroTik System: ' . $host['id'] . ' Is down and will not be interrogated');
	}else{
		if (runCollector($start, $users_lastrun, $users_freq)) {
			collect_users($host);

			// Remove old records
			db_execute_prepared('DELETE FROM plugin_mikrotik_users WHERE userType=0 AND name RLIKE "' . read_config_option('mikrotik_user_exclusion') . '" AND present = 0 AND host_id = ? AND last_seen < FROM_UNIXTIME(UNIX_TIMESTAMP()-' . read_config_option('mikrotik_user_exclusion_ttl') . ')', array($host['id']));
		}

		if (runCollector($start, $trees_lastrun, $trees_freq)) {
			collect_trees($host);
		}
		if (runCollector($start, $queues_lastrun, $queues_freq)) {
			collect_queues($host);
			collect_pppoe_users_api($host);
		}
		if (runCollector($start, $interfaces_lastrun, $interfaces_freq)) {
			collect_interfaces($host);
		}
		if (runCollector($start, $processor_lastrun, $processor_freq)) {
			collect_processor($host);
		}
		if (runCollector($start, $storage_lastrun, $storage_freq)) {
			collect_storage($host);
		}
		if (runCollector($start, $wireless_aps_lastrun, $wireless_aps_freq)) {
			collect_wireless_aps($host);
		}
		if (runCollector($start, $wireless_reg_lastrun, $wireless_reg_freq)) {
			collect_wireless_reg($host);
		}
	
		if (!function_exists('snmp_read_mib')) {
			putenv('MIBS=');
		}
	}

	/* remove the process lock */
	db_execute('DELETE FROM plugin_mikrotik_processes WHERE pid=' . getmypid());
}

function collect_system(&$host) {
	global $mikrotikSystem, $config;

	if (sizeof($host)) {
		// Collect system mib information first
		debug("Polling System from '" . $host['description'] . '[' . $host['hostname'] . "]'");
		$hostMib   = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.25.1', $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'],
			$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
			$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
			read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		if ($hostMib == false) {
			return false; 
		}

		$systemMib = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.2.1.1', $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'],
			$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
			$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
			read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$hostMib = array_merge($hostMib, $systemMib);

		$set_string = '';

		// Locate the values names
		if (sizeof($hostMib)) {
		foreach($hostMib as $mib) {
			/* do some cleanup */
			if (substr($mib['oid'], 0, 1) != '.') $mib['oid'] = '.' . trim($mib['oid']);
			if (substr($mib['value'], 0, 4) == 'OID:') $mib['value'] = str_replace('OID:', '', $mib['value']);

			$key = array_search($mib['oid'], $mikrotikSystem);

			if ($key == 'date') {
				$mib['value'] = mikrotik_dateParse($mib['value']);
			}

			if (!empty($key)) {
				$set_string .= (strlen($set_string) ? ',':'') . $key . "=" . db_qstr(trim($mib['value'], ' "'));
			}
		}
		}

		/* Update the values */
		if (strlen($set_string)) {
			db_execute("UPDATE plugin_mikrotik_system SET $set_string WHERE host_id=" . $host['id']);
		}

		/* system mibs */
		$tikInfoOIDs = array(
			'softwareId'            => '.1.3.6.1.4.1.14988.1.1.4.1.0',
			'licVersion'            => '.1.3.6.1.4.1.14988.1.1.4.4.0',
			'firmwareVersion'       => '.1.3.6.1.4.1.14988.1.1.7.4.0',
			'firmwareVersionLatest' => '.1.3.6.1.4.1.14988.1.1.7.7.0',
			'serialNumber'          => '.1.3.6.1.4.1.14988.1.1.7.3.0'
		);

		foreach($tikInfoOIDs as $key => $oid) {
			$tikInfoData[$key] = cacti_snmp_get($host['hostname'], $host['snmp_community'], $oid, $host['snmp_version'],
				$host['snmp_username'], $host['snmp_password'],
				$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
				$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
				read_config_option('snmp_retries'), SNMP_VALUE_LIBRARY, SNMP_WEBUI);

			db_execute("UPDATE plugin_mikrotik_system SET $key=" . db_qstr($tikInfoData[$key]) . " WHERE host_id=" . $host['id']);
		}

		/* health oids */
		$tikHealthOIDs = array(
			'HlCoreVoltage'            => '.1.3.6.1.4.1.14988.1.1.3.1.0',
			'HlThreeDotThreeVoltage'   => '.1.3.6.1.4.1.14988.1.1.3.2.0',
			'HlFiveVoltage'            => '.1.3.6.1.4.1.14988.1.1.3.3.0',
			'HlTwelveVoltage'          => '.1.3.6.1.4.1.14988.1.1.3.4.0',
			'HlSensorTemperature'      => '.1.3.6.1.4.1.14988.1.1.3.5.0',
			'HlCpuTemperature'         => '.1.3.6.1.4.1.14988.1.1.3.6.0',
			'HlBoardTemperature'       => '.1.3.6.1.4.1.14988.1.1.3.7.0',
			'HlVoltage'                => '.1.3.6.1.4.1.14988.1.1.3.8.0',
			'HlActiveFan'              => '.1.3.6.1.4.1.14988.1.1.3.9.0',
			'HlTemperature'            => '.1.3.6.1.4.1.14988.1.1.3.10.0',
			'HlProcessorTemperature'   => '.1.3.6.1.4.1.14988.1.1.3.11.0',
			'HlPower'                  => '.1.3.6.1.4.1.14988.1.1.3.12.0',
			'HlCurrent'                => '.1.3.6.1.4.1.14988.1.1.3.13.0',
			'HlProcessorFrequency'     => '.1.3.6.1.4.1.14988.1.1.3.14.0',
			'HlPowerSupplyState'       => '.1.3.6.1.4.1.14988.1.1.3.15.0',
			'HlBackupPowerSupplyState' => '.1.3.6.1.4.1.14988.1.1.3.16.0',
			'HlFanSpeed1'              => '.1.3.6.1.4.1.14988.1.1.3.17.0',
			'HlFanSpeed2'              => '.1.3.6.1.4.1.14988.1.1.3.18.0'
		);

		$healthMibs = cacti_snmp_walk($host['hostname'], $host['snmp_community'], '.1.3.6.1.4.1.14988.1.1.3', $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'],
			$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
			$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
			read_config_option('snmp_retries'), $host['max_oids'], SNMP_VALUE_LIBRARY, SNMP_WEBUI);

		$set_string = '';

		// Locate the values names
		if (sizeof($healthMibs)) {
		foreach($healthMibs as $mib) {
			/* do some cleanup */
			if (substr($mib['oid'], 0, 1) != '.') $mib['oid'] = '.' . trim($mib['oid']);
			if (substr($mib['value'], 0, 4) == 'OID:') $mib['value'] = str_replace('OID:', '', $mib['value']);

			$key = array_search($mib['oid'], $tikHealthOIDs);

			if ($key == 'date') {
				$mib['value'] = mikrotik_dateParse($mib['value']);
			}

			if (!empty($key)) {
				$set_string .= (strlen($set_string) ? ',':'') . $key . "=" . (is_numeric($mib['value']) ? $mib['value']:db_qstr(trim($mib['value'], ' "')));
			}
		}
		}

		/* Update the values */
		if (strlen($set_string)) {
			db_execute("INSERT IGNORE INTO plugin_mikrotik_system_health (host_id) VALUES (" . $host['id'] . ")");
			db_execute("UPDATE plugin_mikrotik_system_health SET $set_string WHERE host_id=" . $host['id']);
		}
	}
}

function mikrotik_dateParse($value) {
	$value = explode(',', $value);

	if (isset($value[1]) && strpos($value[1], '.')) {
		$value[1] = substr($value[1], 0, strpos($value[1], '.'));
	}

	$date1 = trim($value[0] . ' ' . (isset($value[1]) ? $value[1]:''));
	if (strtotime($date1) === false) {
		$value = date('Y-m-d H:i:s');
	}else{
		$value = $date1;
	}

	return $value;
}

function mikrotik_macParse($value) {
	if (is_hexadecimal($value)) {
		return $value;
	}else{
		$newval = '';
		for ($i = 0; $i < strlen($value); $i++) {
			$newval .= (strlen($newval) ? ":":"") . bin2hex($value[$i]);
		}
		return ($newval);
	}
}

function mikrotik_splitBaseIndex($oid, $depth = 1) {
	$oid        = strrev($oid);
	$parts      = explode('.', $oid);
	$index      = '';

	for ($i = 0; $i < $depth; $i++) {
		$index .= ($i > 0 ? '.':'') . $parts[$i];
		unset($parts[$i]);
	}

	$base  = strrev(implode('.', $parts));
	$index = strrev($index);

	if ($index != '') {
		return array($base, $index);
	}else{
		return array();
	}
}

function collectHostIndexedOid(&$host, $tree, $table, $name, $preserve = false, $depth = 1) {
	static $types;

	debug("Beginning Processing for '" . $host['description'] . '[' . $host['hostname'] . "]', Table '$name'");

	if (sizeof($host)) {
		/* mark for deletion */
		if ($name == 'users') {
			db_execute("UPDATE $table SET present=0 WHERE host_id=" . $host['id'] . ' AND userType=0');
		}else{
			db_execute("UPDATE $table SET present=0 WHERE host_id=" . $host['id']);
		}

		debug("Polling $name from '" . $host['description'] . '[' . $host['hostname'] . "]'");
		$treeMib   = array();
		$goodVals  = array();
		foreach($tree AS $mname => $oid) {
			if ($name == 'processor') {
				$retrieval = SNMP_VALUE_PLAIN;
			}elseif ($mname == 'mac') {
				$retrieval = SNMP_VALUE_LIBRARY;
			}elseif ($mname == 'apBSSID') {
				$retrieval = SNMP_VALUE_LIBRARY;
			}elseif ($mname == 'date') {
				$retrieval = SNMP_VALUE_LIBRARY;
			}elseif ($mname != 'baseOID') {
				$retrieval = SNMP_VALUE_PLAIN;
			}else{
				continue;
			}

			$walk = cacti_snmp_walk($host['hostname'], $host['snmp_community'], $oid, $host['snmp_version'],
				$host['snmp_username'], $host['snmp_password'],
				$host['snmp_auth_protocol'], $host['snmp_priv_passphrase'], $host['snmp_priv_protocol'],
				$host['snmp_context'], $host['snmp_port'], $host['snmp_timeout'],
				read_config_option('snmp_retries'), $host['max_oids'], $retrieval, SNMP_WEBUI);

			if (sizeof($walk)) {
				$goodVals[$mname] = true;
			}else{
				$goodVals[$mname] = false;
			}

			if (($mname == 'index' || $mname == 'name' || $mname == 'apSSID' || $mname == 'Strength') && !sizeof($walk)) {
				debug('No Index Information for OID: ' . $oid . ' on ' . $host['description'] . ' returning');
				return;
			}

			if ($goodVals[$mname]) {
				$treeMib = array_merge($treeMib, $walk);
			}

			debug('Polled: ' . $host['description'] . ', OID: ' . $oid . ', Size: ' . sizeof($walk));
		}

		$set_string = '';
		$values     = '';
		$sql_suffix = '';
		$sql_prefix = "INSERT INTO $table";

		if (sizeof($tree)) {
		foreach($tree as $mname => $oid) {
			if ($mname != 'baseOID' && $mname != 'index') {
				if ($goodVals[$mname] == true) {
					$values     .= (strlen($values) ? '`, `':'`') . $mname;
					$sql_suffix .= (!strlen($sql_suffix) ? ' ON DUPLICATE KEY UPDATE `index`=VALUES(`index`), `':', `') . $mname . '`=VALUES(`' . $mname . '`)';
				}
			}
		}
		}

		$sql_prefix .= ' (`host_id`, `index`, ' . ($preserve ? '`last_seen`, ':'') . $values . '`) VALUES ';
		$sql_suffix .= ', present=1' . ($preserve ? ', last_seen=NOW()':'');

		// Locate the values names
		$prevIndex    = '';
		$new_array    = array();

		if (sizeof($treeMib)) {
		foreach($treeMib as $mib) {
			/* do some cleanup */
			if (substr($mib['oid'], 0, 1) != '.') $mib['oid'] = '.' . $mib['oid'];
			if (substr($mib['value'], 0, 4) == 'OID:') {
				$mib['value'] = trim(str_replace('OID:', '', $mib['value']));
			}

			$splitIndex = mikrotik_splitBaseIndex($mib['oid'], $depth);

			if (sizeof($splitIndex)) {
				if ($name == 'wireless_registrations') {
					$parts = explode('.', $splitIndex[1]);
					$index = '';
					for ($i = 0; $i < 6; $i++) {
						$index .= ($i>0 ? ':':'') . strtoupper(substr('0' . dechex($parts[$i]), -2));
					}
				}else{
					$index = $splitIndex[1];
				}

				$oid   = $splitIndex[0];
				$key   = array_search($oid, $tree);

				if (!empty($key) && $goodVals[$key] == true) {
					if ($key == 'type') {
						if ($mib['value'] == '.1.3.6.1.2.1.25.2.1.1') {
							$new_array[$index][$key] = 11;
						}elseif($mib['value'] == '.1.3.6.1.2.1.25.2.1.4') {
							$new_array[$index][$key] = 14;
						}
					}elseif ($key == 'name') {
						$new_array[$index][$key] = str_replace('<', '', str_replace('>', '', strtoupper($mib['value'])));
					}elseif ($key == 'date') {
						$new_array[$index][$key] = mikrotik_dateParse($mib['value']);
					}elseif ($key == 'mac') {
						$new_array[$index][$key] = mikrotik_macParse($mib['value']);
					}elseif ($key != 'index') {
						$new_array[$index][$key] = $mib['value'];
					}

					if (!empty($key) && $key != 'index') {
						debug("Key:'" . $key . "', Orig:'" . $mib['oid'] . "', Val:'" . (isset($new_array[$index]) ? $new_array[$index][$key] : '(Index not defined)') . "', Index:'" . $index . "', Base:'" . $oid . "'");
					}
				}
			}else{
				echo "WARNING: Error parsing OID value\n";
			}
		}
		}

		/* dump the output to the database */
		$sql_insert = '';
		$count      = 0;
		if (sizeof($new_array)) {
			foreach($new_array as $index => $item) {
				$sql_insert .= (strlen($sql_insert) ? '), (':'(') . $host['id'] . ", '" . $index . "', " . ($preserve ? 'NOW(), ':'');
				$i = 0;
				foreach($tree as $mname => $oid) {
					if ($mname != 'baseOID' && $mname != 'index') {
						if ($goodVals[$mname] == true) {
							$sql_insert .= ($i >  0 ? ', ':'') . (isset($item[$mname]) && strlen(strlen($item[$mname])) ? db_qstr($item[$mname]):"''");
							$i++;
						}
					}
				}
			}
			$sql_insert .= ')';
			$count++;
			if (($count % 200) == 0) {
				db_execute($sql_prefix . $sql_insert . $sql_suffix);
				$sql_insert = '';
			}
		}

		//print $sql_prefix . $sql_insert . $sql_suffix . "\n";

		if (strlen($sql_insert)) {
			db_execute($sql_prefix . $sql_insert . $sql_suffix);
		}
	}
}

function collect_trees(&$host) {
	global $mikrotikTrees;
	collectHostIndexedOid($host, $mikrotikTrees, 'plugin_mikrotik_trees', 'trees', true);
}

function collect_users(&$host) {
	global $mikrotikUsers;
	collectHostIndexedOid($host, $mikrotikUsers, 'plugin_mikrotik_users', 'users', true);
}

function collect_pppoe_users_api(&$host) {
	$rows = array();

	$api  = new RouterosAPI();
	$api->debug = false;

	$rekey_array = array(
		'host_id', 'name', 'index', 'userType', 'serverID', 'domain', 
		'bytesIn', 'bytesOut', 'packetsIn', 'packetsOut',
        'curBytesIn', 'curBytesOut', 'curPacketsIn', 'curPacketsOut', 
		'prevBytesIn', 'prevBytesOut', 'prevPacketsIn', 'prevPacketsOut', 
		'present', 'last_seen'
	);

	// Put the queues into an array
	$users = array_rekey(db_fetch_assoc_prepared("SELECT 
		host_id, '0' AS `index`, '1' AS userType, '0' AS serverID, SUBSTRING(name, 7) AS name, '' AS domain,
		BytesIn AS bytesIn, BytesOut AS bytesOut, PacketsIn as packetsIn, PacketsOut AS packetsOut,
		curBytesIn, curBytesOut, curPacketsIn, curPacketsOut, 
		prevBytesIn, prevBytesOut, prevPacketsIn, prevPacketsOut, present, last_seen
		FROM plugin_mikrotik_queues 
		WHERE host_id = ? 
		AND name LIKE 'PPPOE-%'", array($host['id'])),
		'name', $rekey_array);

	$creds = db_fetch_row_prepared('SELECT * FROM plugin_mikrotik_credentials WHERE host_id = ?', array($host['id']));

	$start = microtime(true);

	if (sizeof($creds)) {
		if ($api->connect($host['hostname'], $creds['user'], $creds['password'])) {
			$api->write('/ppp/active/getall');

			$read  = $api->read(false);
			$array = $api->parseResponse($read);

			$end = microtime(true);

			$sql   = array();

			cacti_log('MIKROTIK RouterOS API STATS: API Returned ' . sizeof($array) . ' PPPoe Users in ' . round($end-$start,2) . ' seconds.', false, 'SYSTEM');

			if (sizeof($array)) {
				foreach($array as $row) {
					if (!isset($row['name'])) continue;
					$name = strtoupper($row['name']);
					if (isset($users[$name])) {
						$user = $users[$name];

						$user['mac']           = $row['caller-id'];
						$user['ip']            = $row['address'];
						$user['connectTime']   = uptimeToSeconds($row['uptime']);
						$user['host_id']       = $host['id'];
						$user['radius']        = ($row['radius'] == 'true' ? 1:0);
						$user['limitBytesIn']  = $row['limit-bytes-in'];
						$user['limitBytesOut'] = $row['limit-bytes-out'];
						$user['userType']      = 1;
					
						$sql[] = '(' . 
							$user['host_id']            . ',' . 
							$user['index']              . ',' . 
							$user['userType']           . ',' . 
							$user['serverID']           . ',' . 
							db_qstr($user['name'])      . ',' . 
							db_qstr($user['domain'])    . ',' . 
							db_qstr($user['mac'])       . ',' . 
							db_qstr($user['ip'])        . ',' . 
							$user['connectTime']        . ',' .
							$user['bytesIn']            . ',' .
							$user['bytesOut']           . ',' .
							$user['packetsIn']          . ',' .
							$user['packetsOut']         . ',' .
							$user['curBytesIn']         . ',' .
							$user['curBytesOut']        . ',' .
							$user['curPacketsIn']       . ',' .
							$user['curPacketsOut']      . ',' .
							$user['prevBytesIn']        . ',' .
							$user['prevBytesOut']       . ',' .
							$user['prevPacketsIn']      . ',' .
							$user['prevPacketsOut']     . ',' .
							$user['limitBytesIn']       . ',' .
							$user['limitBytesOut']      . ',' .
							$user['radius']             . ',' .
							$user['present']            . ',' .
							db_qstr($user['last_seen']) . ')';
					}
				}

				if (sizeof($sql)) {
					db_execute('INSERT INTO plugin_mikrotik_users 
						(host_id, `index`, userType, serverID, name, domain, mac, ip, connectTime, 
						bytesIn, bytesOut, packetsIn, packetsOut, 
						curBytesIn, curBytesOut, curPacketsIn, curPacketsOut, 
						prevBytesIn, prevBytesOut, prevPacketsIn, prevPacketsOut, 
						limitBytesIn, limitBytesOut, radius, present, last_seen) 
						VALUES ' . implode(', ', $sql) . '
						ON DUPLICATE KEY UPDATE connectTime=VALUES(connectTime), 
						bytesIn=VALUES(bytesIn), bytesOut=VALUES(bytesOut), 
						packetsIn=VALUES(packetsIn), packetsOut=VALUES(packetsOut), 
						curBytesIn=VALUES(curBytesIn), curBytesOut=VALUES(curBytesOut),
						curPacketsIn=VALUES(curPacketsIn), curPacketsOut=VALUES(curPacketsOut),
						prevBytesIn=VALUES(prevBytesIn), prevBytesOut=VALUES(prevBytesOut),
						prevPacketsIn=VALUES(prevPacketsIn), prevPacketsOut=VALUES(prevPacketsOut),
						limitBytesIn=VALUES(limitBytesIn), limitBytesOut=VALUES(limitBytesOut),
						radius=VALUES(radius), present=VALUES(present), last_seen=VALUES(last_seen)');
				}
			}

			$idle_users = db_fetch_assoc_prepared('SELECT name 
				FROM plugin_mikrotik_users 
				WHERE last_seen < FROM_UNIXTIME(UNIX_TIMESTAMP() - ?) 
				AND present=1 
				AND host_id = ?', 
				array(read_config_option('mikrotik_queues_freq'), $host['id']));

			db_execute_prepared('UPDATE IGNORE plugin_mikrotik_users SET
				bytesIn=0, bytesOut=0, packetsIn=0, packetsOut=0, connectTime=0,
				curBytesIn=0, curBytesOut=0, curPacketsIn=0, curPacketsOut=0,
				prevBytesIn=0, prevBytesOut=0, prevPacketsIn=0, prevPacketsOut=0, present=0
				WHERE host_id = ? 
				AND userType = 1 
				AND last_seen < FROM_UNIXTIME(UNIX_TIMESTAMP() - ?)', 
				array($host['id'], read_config_option('mikrotik_queues_freq')));

			$api->disconnect();
		}else{
			cacti_log('ERROR:RouterOS @ ' . $host['description'] . ' Timed Out');
		}
	}
}

function uptimeToSeconds($value) {
	$uptime = 0;

	// remove days first
	$parts = explode('d', $value);
	if (sizeof($parts) == 2) {
		$uptime += $parts[0] * 86400;
		$value   = $parts[1];
	}else{
		$value   = $parts[0];
	}

	// remove hours
	$parts = explode('h', $value);
	if (sizeof($parts) == 2) {
		$uptime += $parts[0] * 3600;
		$value   = $parts[1];
	}else{
		$value   = $parts[0];
	}

	// remove minutes
	$parts = explode('m', $value);
	if (sizeof($parts) == 2) {
		$uptime += $parts[0] * 60;
		$value   = $parts[1];
	}else{
		$value   = $parts[0];
	}

	// remove seconds
	$parts = explode('s', $value);
	if (sizeof($parts) == 2) {
		$uptime += $parts[0];
	}

	return $uptime;
}

function collect_queues(&$host) {
	global $mikrotikQueueSimpleEntry;
	collectHostIndexedOid($host, $mikrotikQueueSimpleEntry, 'plugin_mikrotik_queues', 'queues', true);
}

function collect_interfaces(&$host) {
	global $mikrotikInterfaces;
	collectHostIndexedOid($host, $mikrotikInterfaces, 'plugin_mikrotik_interfaces', 'interfaces', true);
}

function collect_processor(&$host) {
	global $mikrotikProcessor;
	collectHostIndexedOid($host, $mikrotikProcessor, 'plugin_mikrotik_processor', 'processor');
}

function collect_storage(&$host) {
	global $mikrotikStorage;
	collectHostIndexedOid($host, $mikrotikStorage, 'plugin_mikrotik_storage', 'storage');
}

function collect_wireless_aps(&$host) {
	global $mikrotikWirelessAps;
	collectHostIndexedOid($host, $mikrotikWirelessAps, 'plugin_mikrotik_wireless_aps', 'wireless_aps', true);
}

function collect_wireless_reg(&$host) {
	global $mikrotikWirelessRegistrations;
	collectHostIndexedOid($host, $mikrotikWirelessRegistrations, 'plugin_mikrotik_wireless_registrations', 'wireless_registrations', true, 7);
}

function display_version() {
	global $config;
	if (!function_exists('plugin_mikrotik_version')) {
		include_once($config['base_path'] . '/plugins/mikrotik/setup.php');
	}

	$info = plugin_mikrotik_version();
	echo "MikroTik Poller Process, Version " . $info['version'] . ", " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	echo "\nThe main MikroTik poller process script for Cacti.\n\n";
	echo "usage: \n";
	echo "master process: poller_mikrotik.php [-M] [-f] [-fd] [-d]\n";
	echo "child  process: poller_mikrotik.php --host-id=N [--seed=N] [-f] [-d]\n\n";
}
