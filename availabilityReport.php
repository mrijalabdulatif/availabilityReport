<?

// Cacti Availability Reporting v 1.3
// by Paul Taylor (paultaylor@winn-dixie.com)
//
// This application produces three separate Availability reports.
//
// 1. Daily Availability:  Each day a report will be generated showing the
//    availability of all Cacti devices since the last report.
// 2. Weekly Availability: On the day you designate as the first day of the week,
//    a report will be generated showing availability for the last 7 days.  This 
//    is calculated by taking the daily information from each day.
// 3. Monthly Availability: On the 1st of the month, a report will be generated
//    showing availability for the entire month.  The statistics are then cleared
//    for the next month.

// Change Log
//
// Version   Change
// 1.3       Added the weekly availability report
// 1.2       Added the ability to only show specific devices via the $filter variable
// 1.1       Added a second call to clear the SQL data on monthly boundaries after a pause.  This was
//              needed because some records were locked in large environments.  Waiting a few seconds 
//              and re-running the second clear was a quick fix to be relatively sure that the data 
//              is properly cleared.
             

// Database Settings
$myServer = "localhost";
$myUser = "cactiuser";
$myPass = "CactiMadeEZ";
$myDB = "cacti";
$myTable = "host";

// Your mail settings
$smtpServer = "yourmailserver.yourdomain.com";   // Mail server that allows unrestricted SMTP from your Cacti machine
$fromAddr = "admin@yourdomain.com"; // The "From" address

$dailyRecipients = array("mgr1@yourdomain.com","mgr2@yourdomain.com");   // A comma separated list of recipients for the daily availability report
$weeklyRecipients = array("mgr1@yourdomain.com","mgr2@yourdomain.com");    // A comma separated list of recipients for the daily availability report
$monthlyRecipients = array("mgr1@yourdomain.com","mgr2@yourdomain.com"); // A comma separated list of recipients for the monthly availability report

// Misc. Settings

// This directory is where the "yesterday" data file can be written.  This should
// be a directory that will preserve data across a reboot (not on a memory file system!)
// NOTE: The trailing "/" is required!
$tmpDir = "/tmp/";

// First day of the week for reporting purposes, numerically: 1 (for Monday) through 7 (for Sunday)
// Note:  Selecting 1 here will cause the weekly report to be sent out each Monday, including all
//        data since the last weekly report.
$firstDayOfWeek = 1;  

// Settings specifc to the daily report
// For topX and availPercent. do not attempt to set both to non-zero values.
$daily['topX'] = 0;  // 0 shows all, otherwise show the Top X
$daily['availPercent'] = 0; // 0 shows all, otherwise show only devices with availability under this percentage


// Settings specific to the monthly report
// For topX and availPercent. do not attempt to set both to non-zero values.
$weekly['topX'] = 0;
$weekly['availPercent'] = 0;

// Settings specific to the monthly report
// For topX and availPercent. do not attempt to set both to non-zero values.
$monthly['topX'] = 0;
$monthly['availPercent'] = 0;

$filter = '';  // No filter
//$filter = 'AND description != "Cacti"'; // Exclude a specific host
//$filter = 'AND description like "s%r"'; // Include hosts with a particular description

// No code changes below this line should be needed.
//---------------------------------------------------------------------------------

// Filenames for datafiles
$monthFile = 'month.dat';
$weekFile = 'week.dat';
$yesterdayFile = 'yesterday.dat';

// Pre-load weekly stats
readStats($weekFile,$weeklyStats);

require_once("smtp.class.php");

function natsort2d( &$arrIn, $index = null )
{
   
   $arrTemp = array();
   $arrOut = array();
   
   if (is_array($arrIn)) {
	   foreach ( $arrIn as $key=>$value ) {
		   
		   reset($value);
		   $arrTemp[$key] = is_null($index)
							   ? current($value)
							   : $value[$index];
	   }
   }
   
   natsort($arrTemp);
   
   foreach ( $arrTemp as $key=>$value ) {
       $arrOut[$key] = $arrIn[$key];
   }
   
   $arrIn = $arrOut;
   
}


function writeData($fname,&$data)
{
	global $tmpDir;
	
	$fname = $tmpDir . $fname;
	if (file_exists($fname))
		unlink($fname);
	$file = fopen($fname, 'a');
	fwrite($file, serialize($data)); 
	fclose($file);	
}

function readStats($fname,&$data) 
{
	global $tmpDir;
	
	$fname = $tmpDir . $fname;
	if (file_exists($fname))
	{
		$file = fopen($fname,'r');
		$data = unserialize(fread($file, filesize($fname)));
		fclose($file);			
	}
}

function clearAvailabilityInSQL()
{
	global $myServer, $myUser, $myPass, $myDB, $myTable;
	
	$s = mysql_connect($myServer, $myUser, $myPass) 
	  or die ("Couldn't open database $myDB");
	
	mysql_select_db($myDB) 
	  or die("Couldn't open database $myDB");

	$query = "UPDATE host SET total_polls = '0',failed_polls = '0'";
	
	$result = mysql_query($query) or die('Query failed: ' . mysql_error());

  	mysql_close($s);
  	
}

// Read availability stats directly out of Cacti database
function getReportDetails() 
{
	global $myServer, $myUser, $myPass, $myDB, $myTable, $filter;
	
	$s = mysql_connect($myServer, $myUser, $myPass) 
	  or die ("Couldn't open database $myDB");
	
	mysql_select_db($myDB) 
	  or die("Couldn't open database $myDB");

	$query = "SELECT description, availability, total_polls, failed_polls FROM $myTable WHERE disabled != 'on' $filter ORDER by availability, description";		
	
	$result = mysql_query($query) or die('Query failed: ' . mysql_error());

	while($row = mysql_fetch_array($result, MYSQL_ASSOC))
    {
    	$data[$row['description']]['availability'] = $row['availability'];
    	$data[$row['description']]['total_polls'] = $row['total_polls'];
    	$data[$row['description']]['failed_polls'] = $row['failed_polls'];
    }
   	
  	mysql_close($s);
  	
  	return $data;
  	
}

function weeklyAvailability()
{
	global $tmpDir, $weeklyRecipients, $weekly, $weekFile, $dayOfWeek, $firstDayOfWeek, $weeklyStats;
	
	if ($dayOfWeek == $firstDayOfWeek) {
		// It's the day to send the weekly report
		// The process to gather today's stats has already been ran, so all data is in $weeklyStats
		// Just need to combine the data for each day
		foreach ($weeklyStats as $dailyStats) {

			foreach ($dailyStats as $device => $stats) {
				if (isset($avail[$device])) {
					$avail[$device]['total_polls'] += $stats['total_polls'];
					$avail[$device]['failed_polls'] += $stats['failed_polls'];
					$avail[$device]['availability'] = number_format(($avail[$device]['total_polls'] - $avail[$device]['failed_polls']) / $avail[$device]['total_polls'] * 100,5,'.','');
				} else {
					$avail[$device]['total_polls'] = $stats['total_polls'];
					$avail[$device]['failed_polls'] = $stats['failed_polls'];
					$avail[$device]['availability'] = number_format(($avail[$device]['total_polls'] - $avail[$device]['failed_polls']) / $avail[$device]['total_polls'] * 100,5,'.','');
				}
			}
			
		}	
		
		// Generate and send report
		natsort2d($avail,'availability');
	
		$extTitle = '';
		
		if ($weekly['topX'] > 0) {
			$extTitle .= "Weekly Top {$weekly['topX']} Worst Availability<br>";
			$count = 0;
			foreach ($avail as $device => $stat) {
				$count++;
				$tmp[$device] = $stat;
				if ($count == $weekly['topX']) {
					break;
				}
			}
			if (isset($tmp)) {
				$avail = $tmp;
			} else {
				$avail = array();
			}
		}
		
		if ($weekly['availPercent'] > 0) {
			$extTitle .= "Devices with Availability Less Than {$weekly['availPercent']}%<br>";
			foreach ($avail as $device => $stat) {
				if ($stat['availability'] > $weekly['availPercent']) {
					break;
				}
				$tmp[$device] = $stat;
			}
			if (isset($tmp)) {
				$avail = $tmp;
			} else {
				$avail = array();
			}
		}
		
		$extTitle = '';
		if (file_exists($tmpDir . $weekFile)) {
			$extTitle .= "Report Duration: " .  date("F j, Y, g:i a",strtotime("1 week ago")) . 
		    	        " - " . date("F j, Y, g:i a") . "<br>";
		    // Delete the weekly file
		    unlink($tmpDir . $weekFile);
		}
	
		emailReport($avail,$weeklyRecipients,"Weekly Availability Report",$extTitle);
		
	} else {
		// Not the day to send the report.  
		// Save the weekly file off
		writeData($weekFile,$weeklyStats);
	}
}

function monthlyAvailability()
{
	global $tmpDir, $monthlyRecipients, $monthly, $monthFile;
	
	$avail = getReportDetails();

	natsort2d($avail,'availability');
	
	$extTitle = '';
	
	if ($monthly['topX'] > 0) {
		$extTitle .= "Monthly Top {$monthly['topX']} Worst Availability<br>";
		$count = 0;
		foreach ($avail as $device => $stat) {
			$count++;
			$tmp[$device] = $stat;
			if ($count == $monthly['topX']) {
				break;
			}
		}
		if (isset($tmp)) {
			$avail = $tmp;
		} else {
			$avail = array();
		}
	}
	
	if ($monthly['availPercent'] > 0) {
		$extTitle .= "Devices with Availability Less Than {$monthly['availPercent']}%<br>";
		foreach ($avail as $device => $stat) {
			if ($stat['availability'] > $monthly['availPercent']) {
				break;
			}
			$tmp[$device] = $stat;
		}
		if (isset($tmp)) {
			$avail = $tmp;
		} else {
			$avail = array();
		}
	}
	
	$extTitle = '';
	if (file_exists($tmpDir . $monthFile)) {
		$extTitle .= "Report Duration: " .  date("F j, Y, g:i a",filemtime($tmpDir . $monthFile)) . 
	    	        " - " . date("F j, Y, g:i a") . "<br>";
	}

	emailReport($avail,$monthlyRecipients,"Monthly Availability Report",$extTitle);
	
}

function yesterdaysAvailability() 
{
	global $tmpDir, $dailyRecipients, $daily, $yesterdayFile, $weeklyStats;
	
	$today = getReportDetails();
	readStats($yesterdayFile,$yesterday);
	foreach ($today as $device => $todayStat) {
		if (isset($yesterday[$device])) {
			// Make sure yesterday's counters are lower than todays before subtracting one from the other
			if ($yesterday[$device]['total_polls'] < $today[$device]['total_polls']) {
				$avail[$device]['total_polls'] = $today[$device]['total_polls'] - $yesterday[$device]['total_polls'];
				$avail[$device]['failed_polls'] = $today[$device]['failed_polls'] - $yesterday[$device]['failed_polls'];
			} else {
				// Perhaps this device was deleted and added back to Cacti with the same name.  In any case, just use the total polls read today
				$avail[$device]['total_polls'] = $today[$device]['total_polls'];
				$avail[$device]['failed_polls'] = $today[$device]['failed_polls'];
			}
			$avail[$device]['availability'] = number_format(($avail[$device]['total_polls'] - $avail[$device]['failed_polls']) / $avail[$device]['total_polls'] * 100,5,'.','');
		} else {
			$avail[$device] = $today[$device];
		}
	}
	
	$weeklyStats[(date("N")-1)] = $avail;

	// Only write out data if this is the first time running today
	
	natsort2d($avail,'availability');
	
	$extTitle = '';
	
	if ($daily['topX'] > 0) {
		$extTitle .= "Daily Top {$daily['topX']} Worst Availability<br>";
		$count = 0;
		foreach ($avail as $device => $stat) {
			$count++;
			$tmp[$device] = $stat;
			if ($count == $daily['topX']) {
				break;
			}
		}
		if (isset($tmp)) {
			$avail = $tmp;
		} else {
			$avail = array();
		}
	}
	
	if ($daily['availPercent'] > 0) {
		$extTitle .= "Devices with Availability Less Than {$daily['availPercent']}%<br>";
		foreach ($avail as $device => $stat) {
			if ($stat['availability'] > $daily['availPercent']) {
				break;
			}
			$tmp[$device] = $stat;
		}
		if (isset($tmp)) {
			$avail = $tmp;
		} else {
			$avail = array();
		}
	}
	
	if (file_exists($tmpDir . $yesterdayFile)) {
		$extTitle .= "Report Duration: " .  date("F j, Y, g:i a",filemtime($tmpDir . $yesterdayFile)) . 
	    	        " - " . date("F j, Y, g:i a") . "<br>";
	}

	if (!(file_exists($tmpDir . $yesterdayFile)) or (date("d", filemtime($tmpDir . $yesterdayFile)) != date("d"))) {
		writeData($yesterdayFile,$today);
	}
	
	emailReport($avail,$dailyRecipients,"Daily Availability Report",$extTitle);
	
}

function newMonth()
{
	global $tmpDir, $yesterdayFile, $monthFile;
	
	// Clear the SQL stats
	clearAvailabilityInSQL();
        sleep(5);
        clearAvailabilityInSQL();
	
	// Delete the yesterday file
	if (file_exists($tmpDir . $yesterdayFile)) {
		unlink($tmpDir . $yesterdayFile);
	}
	
	// Create the new month.dat file
	// Doesn't matter what we put here - Only care about the timestamp
	writeData($monthFile,$monthFile);
	
}

// Main program execution starts here

$today = date("d");
$dayOfWeek = date("N");

if ($today == "1") {
	// First day of the month!
	
	// Send yesterday's Availability Report
	yesterdaysAvailability();

	// Do calculations for Weekly Availability & send report (if needed)
	weeklyAvailability();
	
	// Send monthly Availability Report
	monthlyAvailability();

	// Clear Availability Statistics
	newMonth();
	
} else {
	
	// Send yesterday's Availability Report
	yesterdaysAvailability();
	
	// Do calculations for Weekly Availability & send report (if needed)
	weeklyAvailability();


}

function arrayToText ($ar)
{
	$val = '';
	foreach ($ar as $a) {
		$val .= $a . ", ";
	}
	$val = substr($val,0,(strlen($val)-1));
	
	return $val;
}

function emailReport($avail, $recipients, $title, $extTitle = '') 
{
  global $smtpServer, $fromAddr;

  $smtp=new smtp_class;
  $smtp->host_name=$smtpServer; /* relay SMTP server address */
  $smtp->localhost="CACTHDQ01R"; /* this computer address */
  $from=$fromAddr;
  $smtp->direct_delivery=0; /* Set to 1 to deliver directly to the recepient SMTP server */
  $smtp->timeout=10;        /* Set to the number of seconds wait for a successful connection to the SMTP server */
  $smtp->data_timeout=0;    /* Set to the number seconds wait for sending or retrieving data from the SMTP server.
                             Set to 0 to use the same defined in the timeout variable */
  $smtp->debug=1;           /* Set to 1 to output the communication with the SMTP server */
  $smtp->html_debug=0;      /* Set to 1 to format the debug output as HTML */
  $smtp->user="";           /* Set to the user name if the server requires authetication */
  $smtp->realm="";          /* Set to the authetication realm, usually the authentication user e-mail domain */
  $smtp->password="";       /* Set to the authetication password */

  $htmlHeader = "<html>\n\n<head>\n<style>\n<!--\nbody, table, tr, td {\nfont-family: Verdana, Arial, Helvetica, sans-serif;\nfont-size: 10px;\n}\n.textArea {\nfont-size: 12px;\n}\n-->\n</style>\n</head>\n\n<body>\n";
  $tableHeader = "<p class='textArea'>$extTitle</p>" . '<table cellpadding="3" cellspacing="1" border="0" bgcolor="6d88ad">' . "\n" . '<tr bgcolor="#e5e5e5">' . "\n<td><b>Device</b></td>\n<td><b>Availability</b></td>\n<td><b>Total Polls</b></td>\n<td><b>Failed Polls</b></td>\n</tr>";
  $htmlFooter = "</table>\n</body>\n\n</html>";
    
  $body = $htmlHeader . $tableHeader;
  $count = 0;
  if (is_array($avail)) {
	  foreach ($avail as $device => $data) {
	  	$count++;
	  	$altColor = ($count % 2 == 0) ? '#e7e9f2' : '#f5f5f5';
	  	$body .= '<tr bgcolor="'.$altColor.'">' . "\n<td>$device</td>\n<td align='center'>{$data['availability']}</td>\n<td align='center'>{$data['total_polls']}</td>\n<td align='center'>{$data['failed_polls']}</td>\n</tr>";
	  }
  }
  
  $body .= $htmlFooter;
  
  if($smtp->SendMessage($from,$recipients,array("From: $from\nContent-Type: text/html","To: " . arrayToText($recipients),"Subject: $title " . strftime("%b %d %Y"),
	  "Date: ".strftime("%a, %d %b %Y %H:%M:%S %Z")),$body . "\n"))
	  {
  		echo "Message sent to ".arrayToText($recipients)." OK.\n";
	  }
  else
 	echo "Cound not send the message to ".arrayToText($recipients).".\nError: ".$smtp->error."\n";	
	
}

// Test Output

function testOutput($details)
{
	$count = 0;
	echo "Store	    Availability     Total Polls     Failed Polls\n";
	foreach ($details as $device => $data) {
		$count++;
		//$avail['availability'] = number_format(($data['total_polls'] - $data['failed_polls']) / $data['total_polls'] * 100,5,'.','');
		echo $device . "    " . $data['availability'] . "     " . $data['total_polls'] . "     " . $data['failed_polls'] . "\n";
		if ($count == 10) break;
	}
}

//print_r($details);


?>
