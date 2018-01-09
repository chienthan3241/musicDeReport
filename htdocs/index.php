<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Musik-Reports (DE)</title>
<link rel="stylesheet" type="text/css" media="all" href="standard.css" />
<link rel="stylesheet" type="text/css" media="all" href="style.css" />
</head>
<body>
<?php
include_once('../lib/global.php');
include_once('cron-config.php');
?>
<h1>Music Reports Generator Status</h1>
<p>Edit the <code>lib/global</code> file to change settings.</p>
<h2>"Cron"</h2>
<p>The <code>cron.php</code> will be called by system cron every minute. It simulates
the real crontab, but does not support format like <code>*/5</code>.</p>
<pre>
<?php
foreach($jobs as $jobname => $crontab){
    echo "<strong>$jobname</strong>:<br>";
    if(is_array($crontab)){
        foreach($crontab as $c){
            echo "&nbsp;&nbsp;&nbsp;&nbsp;$c<br>";
        }
    }else{
        echo "&nbsp;&nbsp;&nbsp;&nbsp;$crontab<br>";
    }
}
?>
</pre>
<p><strong>Current time: (min, hour, day, month, weekday):</strong></p>
<pre>&nbsp;&nbsp;&nbsp;&nbsp;<?php
$date = getdate();
echo implode(' ', array(
    $date['minutes'],
    $date['hours'],
    $date['mday'],
    $date['mon'],
    $date['wday']
));
?></pre>
<h3>Lock files</h3>
<p>lock files are saved on the NFS under:</p>
<pre>
<?php echo $lockfile_path; ?>
</pre>
<p>lock files:</p>
<?php
$files = new DirectoryIterator($lockfile_path);
$count = 0;
foreach($files as $fileinfo){
    if($fileinfo->isFile()){
        echo $fileinfo->getFilename() . '<br>';
        $count++;
    }
}
if($count == 0) echo "(no lock file found, no job is running.)";
?>
<h3>Last Update Date</h3>
<?php
$lastupdatefile_daily = $nfs_root_dir . 'tagesdata_last_update.txt';
$lastupdatefile_monthly = $nfs_root_dir . 'monatsdata_last_update.txt';
?>
<ul>
    <li>Tagesdaten last update (<code><?php echo $lastupdatefile_daily; ?></code>):<br>
<code><strong><?php
if($c = file_get_contents($lastupdatefile_daily)){
    echo $c;
}else{
    echo "<no record>";
}
    ?></strong></code></li>
    <li>Monatsdaten last update (<code><?php echo $lastupdatefile_monthly; ?></code>):<br>
<code><strong><?php
if($c = file_get_contents($lastupdatefile_monthly)){
    echo $c;
}else{
    echo "<no record>";
}
    ?></strong></code></li>
</ul>
<h3>Target path</h3>
<p><code><?php echo $target_path; ?></code></p>
</h3>
<h2>FTP Reports</h2>
<p>Generated on server, these reports are in different formats
and will be published to <code>/home/asbad04/*</code> for clients to
download.</p>
<h3>Normal Reports will be delivered to:</h3>
<pre>
<?php
echo $reports_normal_recipients[0];
?>
</pre>
<h3>Error will be reported to:</h3>
<pre>
<?php
echo $reports_error_recipients[0];
?>
</pre>

<h2>EMI Reports</h2>
<p>Reports generated specifically for EMI, sent via E-mail interally</p>
<h3>Normal reports will be delivered to:</h3>
<pre>
<?php
echo $emi_reports_normal_recipients[0];
?>
</pre>
<h3>Error will be reported to:</h3>
<pre>
<?php
echo $emi_reports_error_recipients[0];
?>
</pre>

</body>
</html>
