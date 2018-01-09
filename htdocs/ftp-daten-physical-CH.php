<?php 
require_once(dirname(__FILE__).'/../lib/global.php');
require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
function tart_code_fill($tart_code){
	switch (strlen(trim($tart_code))) {
		case 1:
			return '000'.trim($tart_code);
			break;
		case 2:
			return '00'.trim($tart_code);
			break;
		case 3: 
			return '0'.trim($tart_code);
			break;
		default:
			return trim($tart_code);
			break;
	}
}

function repe_code_cut($repe_code){
	switch (strlen(trim($repe_code))) {
		case 5:
			return substr(trim($repe_code), 2);
			break;
		
		default:
			return trim($repe_code);
			break;
	}
}

$warner    = array(18505);
$is_error = false;
$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error FTP-Daten PHYS",
        'body_txt'=>"Bei Generierung FTP-Daten Fehler aufgetreten, /tmp/ftp_dl_gen.log checken!\n");

	sendEMail($par);

	die ($db->getMessage());
}

////////////////////////////////////////
///// ZEITKEY EINSTELLUNG //////////////
////////////////////////////////////////
if(!isset($_REQUEST['zeitid'])) {
	$qry = "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'I' and zeit_einheit = 'W' and zeit_landid = 1041";
	$rs = $db->query($qry);
	$data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
	$zeit = $data['max_zeit'];
} else {
	$zeit = substr($_REQUEST['zeitid'],0,6);
}

$zeit_keys = array(



'201416'
,'201417'
,'201418'
,'201419'
,'201420'
,'201421'
,'201422'
,'201423'
,'201424'
,'201425'
,'201426'
,'201427'

);
////////////////////////////////////////
///// END ZEITKEY EINSTELLUNG //////////
////////////////////////////////////////

/**
 * for historical data re-generation
 */
#foreach ($zeit_keys as $zeit){
$jahr2 = substr($zeit,2,2);
$jahr4 = substr($zeit,0,4);
$woche = substr($zeit,-2,2);

$sql = " SELECT 
	weeknr,
	startdate,
	enddate,
	seanc,
	sinte,
	stitl,
	mainprodid,
	tart_code,
	tart_txt,
	repe_code,
	repe_txt,
	sum_meng AS meng,
	display_code,
	distributor_txt,
	label_txt,
	case display_code when '18505' then sum_wert else 0 end AS wert
FROM
	(
		SELECT
			bwg_zeitkey 					AS weeknr,
			NVL(SUM(bwg_wert),0) 			AS sum_wert,
			NVL(SUM(bwg_menge),0) 			AS sum_meng,
			NVL(bwg_eancid,'') 				AS bwg_eancid,
			NVL(MAX(stamm_eanc),'') 		AS seanc,
			NVL(MIN(stamm_artist),'') 		AS sinte,
			NVL(MIN(stamm_titel),'') 		AS stitl,
			NVL(MAX(stamm_main_prodnr),'') 	AS mainprodid,
			NVL(MAX(stamm_wg_code),'') 		AS repe_code,
			NVL(MAX(stamm_wg_txt),'') 		AS repe_txt,
			NVL(MAX(stamm_ag_code),'') 		AS tart_code,
			NVL(MAX(stamm_ag_txt),'') 		AS tart_txt,
			NVL(MIN(stamm_label_txt),'') 	AS label_txt,
			NVL(MIN(stamm_herst_txt),'') 	AS distributor_txt,
			NVL(MAX(stamm_display_code),'') AS display_code
		FROM
			stamm_gesamt,
			bewegung_w
		WHERE
			stamm_eancid 				= bwg_eancid AND
			stamm_type_flag 			= 'I' AND
			stamm_lauf 					= 'INT' AND
			NVL(stamm_quelle,'EMPTY') 	<> 'MA' AND
			stamm_landid 				= 1041 AND
			bwg_zeitkey 				= $zeit and
			bwg_landid 					= 1041 AND
			bwg_appid 					= 12 AND
			bwg_typeflag 				= 'I' AND
			BWG_CONT_DIST 				= 641
		group by
			bwg_eancid,
			bwg_zeitkey
	) a,
	(
		select
			zeit_key,
			to_char(start_date,'YYYYMMDD') 	as startdate,
			to_char(end_date,'YYYYMMDD') 	as enddate
		from
			zeit_wert_w
		where
			land_id 	= 1041 and
			app_id 		= 12 and
			zeit_key 	= $zeit
	) b
where
	a.weeknr = b.zeit_key
order by
	display_code asc,
	to_number(seanc) asc";

echo "$zeit\n";

$arr = array();
$data = array();

$ct = 0;
$rs=$db->query($sql);

if (MDB2::isError($rs)) {
    dbug('error', $sql);
    $is_error = true;
} else {
    while($arr= $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
        if ($arr) {            
            $data[$ct]['weeknr']        	= $arr['weeknr'];
            $data[$ct]['startdate']     	= $arr['startdate'];
            $data[$ct]['enddate']     		= $arr['enddate'];
            $data[$ct]['seanc']         	= $arr['seanc'];
            $data[$ct]['sinte']         	= $arr['sinte'];
            $data[$ct]['stitl']         	= $arr['stitl'];
            $data[$ct]['mainprodid']    	= trim($arr['mainprodid']);
            $data[$ct]['tart_code']  		= $arr['tart_code'];
            $data[$ct]['tart_txt']      	= $arr['tart_txt'];
            $data[$ct]['repe_code']   		= $arr['repe_code'];
            $data[$ct]['repe_txt']      	= $arr['repe_txt'];
            $data[$ct]['meng']        		= $arr['meng'];
            $data[$ct]['display_code']  	= trim($arr['display_code']);
            $data[$ct]['distributor_txt']   = $arr['distributor_txt'];
            $data[$ct]['label_txt']     	= $arr['label_txt'];
            $data[$ct]['wert']        		= $arr['wert'];
        }
    $ct++;
    }
$rs->free();
}

//write to file
echo "Schreibe Warner-File...";
if(($physwarner = fopen($nfs_root_dir .'temp/WA_CH_'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
	die('cannot open file');
}else{

	foreach ($data as $c=>$dt) {
		fwrite($physwarner, $dt['weeknr'].';'); 						#ZEITKEY
		fwrite($physwarner, $dt['startdate'].';'); 						#STARTDATE
		fwrite($physwarner, $dt['enddate'].';'); 						#ENDDATE
		fwrite($physwarner, $dt['seanc'].';'); 							#EANC
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['sinte']).'";'); 					#ARTIST
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['stitl']).'";'); 					#TITLE
		fwrite($physwarner, $dt['mainprodid'].';'); 					#PRODID
		fwrite($physwarner, '"'.tart_code_fill($dt['tart_code']).'";'); #TART_CODE
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['tart_txt']).'";'); 					#TART_TXT
		fwrite($physwarner, '"'.repe_code_cut($dt['repe_code']).'";'); 	#REPE_CODE
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['repe_txt']).'";'); 					#REPE_TXT
		fwrite($physwarner, number_format($dt['meng'],0, ",", "").';');	#MENGE
		fwrite($physwarner, '"'.$dt['display_code'].'";'); 				#DISPLAY_CODE
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['distributor_txt']).'";'); 			#DISTRIBUTOR_TXT
		fwrite($physwarner, '"'.str_replace(';', ' /', $dt['label_txt']).'";'); 				#LABEL_TXT
		fwrite($physwarner, number_format($dt['wert'],2, ",", ""));		#WERT
		fwrite($physwarner,"\n");
	}
	fclose($physwarner);
   	echo "fertig.\n";

}

echo "Kopieren der Dateien auf die entsprechenden FTP-Laufwerke...";
$ftp = new FTP;
$ftp->connect($ftp_host);
$ftp->login($ftp_username, $ftp_password);

//Warner AT, but upload to GERMANY!
if( ! $ftp->put($target_path_de . '/WARNER/DOWNLOAD/SALES/WA_CH_' . $jahr2 . 'KW' . $woche . '.TXT', $nfs_root_dir .'temp/WA_CH_'.$jahr2.'KW'.$woche.'.TXT', FTP_BINARY)){
        echo "failed to upload Warner file WA_CH_" . $jahr2 . "KW" . $woche . ".TXT\n";
        $is_error = true;
	}
echo "fertig.\n";
rmdirr($nfs_root_dir .'temp/WA_CH_'.$jahr2.'KW'.$woche.'.TXT');
if($is_error == true) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error FTP-Wochen-Daten Physical",'body_txt'=>"Bei Generierung CH-FTP-Wochen-Daten Fehler aufgetreten.\n");
} else {
	$par['empfaenger'] = $reports_normal_recipients;
	$par['message'] = array('subject'=>"(Exasol) Erfolg FTP-Wochen-Daten Physical",'body_txt'=>"CH Physical ". $jahr2 . 'KW' . $woche ." FTP-Wochen-Daten generiert und verteilt.\n");
}
sendEMail($par);

#}

?>