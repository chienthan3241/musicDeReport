<?php
require_once(dirname(__FILE__) . '/../lib/global.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	die ($db->getMessage());
}

//Abbruch durch Benutzer ignorieren
#ignore_user_abort(true);

//EMI-Report London Downloads

if(!isset($_REQUEST['zeitid'])) {
	$qry 	= "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'D' and zeit_einheit = 'W'";
	$rs 	= $db->query($qry);
	$data 	= $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
	$zeit 	= $data['max_zeit'];
} else {
	$zeit 	= substr($_REQUEST['zeitid'],5);
}

$jahr 	= substr($zeit,0,4);
$woche 	= substr($zeit,4,2);

if(substr($woche,0,1) == '0')
$woche2 = substr($woche,1);
else
$woche2 = $woche;

$zeit2 = $woche2 . '.' . $jahr;

$is_error=false;

$qry = "
  SELECT   DECODE (physmenge, NULL, 0, physmenge) AS physmenge,
           DECODE (physwert, NULL, 0, physwert) AS physwert,
           DECODE (dlmenge, NULL, 0, dlmenge) AS dlmenge,
           DECODE (dlwert, NULL, 0, dlwert) AS dlwert,
           (DECODE (physmenge, NULL, 0, physmenge) + DECODE (dlmenge, NULL, 0, dlmenge)) AS sumdlphys,
           (DECODE (physwert, NULL, 0, physwert) + DECODE (dlwert, NULL, 0, dlwert)) AS sumdlphyswert,
           DECODE (physvoemenge, NULL, 0, physvoemenge) as physvoemenge,
           DECODE (physvoewert, NULL, 0, physvoewert) as physvoewert,
           DECODE (dlvoemenge, NULL, 0, dlvoemenge) as dlvoemenge,
           DECODE (dlvoewert, NULL, 0, dlvoewert) as dlvoewert,
           (decode (physvoemenge, null, 0, physvoemenge) + decode(dlvoemenge, null, 0, dlvoemenge)) as sumvoemenge,
           (decode (physvoewert, null, 0, physvoewert) + decode(dlvoewert, null, 0, dlvoewert)) as sumvoewert,
           TO_NUMBER (Platzierung) AS chart_rank,
           chart_ean,
           Titel AS chart_title,
           Autor AS chart_artist,
           Firma AS label,
           Titel_2 as firma_dwl,
           Modul_Id,
           REPLACE (Code, 'A', '') AS chart_archivnr,
           Code AS PillePalle,
           CASE
              WHEN (Modul_Id) = 101 THEN 'SG'
              WHEN (Modul_Id) = 102 THEN 'LP'
              WHEN (Modul_Id) = 103 THEN 'CP'
              WHEN (Modul_Id) = 104 THEN 'MV'
              ELSE Modul_Id
           END
              AS chart_type,
           tart,
           repe
    FROM   (  SELECT   stamm_archivnr AS sarchnr,
                       MIN (stamm_eanc) AS chart_ean,
                       MIN (stamm_ag_txt) AS tart,
                       MIN (stamm_wg_txt) AS repe,
                       ROUND (SUM (bwg_menge), 0) AS physmenge,
                       ROUND (SUM (bwg_wert), 2) AS physwert
                FROM   bewegung_w, stamm_gesamt, region
               WHERE       bwg_eancid = stamm_eancid
                       AND stamm_type_flag = 'P'
			  AND BWG_CONT_DIST IN ('641','645')
                       AND stamm_lauf = 'DE'
                       AND stamm_ag_audio_video = 'V'
                       AND stamm_musikfremd = 0
                       AND NVL(stamm_quelle, 'EMPTY') <> 'MA'
                       AND bwg_zeitkey = $zeit
                       AND bwg_landid = 1054
                       AND bwg_appid = 12
                       AND bwg_haendlerid = region_haendlerid
                       AND (region_awid IN
                           (SELECT region_awid
                              FROM region
                             WHERE     region_awid NOT IN (240, 243, 2)
                                   AND region_appid = 12
                                   AND region_landid = 1054))                       
            GROUP BY   stamm_archivnr) s1,
           (  SELECT   stamm_archivnr AS sarchnr,
                       SUM (ROUND (bwg_menge)) AS dlmenge,
                       ROUND (SUM (bwg_wert), 2) AS dlwert
                FROM   bewegung_w, stamm_gesamt
               WHERE       bwg_zeitkey = $zeit
		         AND BWG_CONT_DIST IN ('641','645')
                       AND bwg_eancid = stamm_eancid
                       AND stamm_type_flag = 'D'
                       AND stamm_lauf = 'DWN'
                       AND stamm_ag_audio_video = 'V'
                       AND bwg_landid = 1054
                       AND bwg_appid = 12                       
            GROUP BY   stamm_archivnr) s2,
             (  SELECT stamm_archivnr AS sarchnr,
                       ROUND (SUM (voe_menge), 0) AS physvoemenge,
                       ROUND (SUM(VOE_wert), 2) as physvoewert
              FROM     (
        		    SELECT 
          			SUM(bwg_wert) AS voe_wert,
          			SUM(bwg_menge) AS voe_menge,          
          			bwg_eancid as voe_eancid,
          			stamm_lauf as voe_lauf
        		    FROM
          			bewegung_w,
          			stamm_gesamt
        		   WHERE
          			bwg_eancid = stamm_eancid
          			AND bwg_landid = 1054
          			AND bwg_appid = 12
          			AND BWG_CONT_DIST IN ('641','645')
          			AND stamm_type_flag = 'P'
          			AND stamm_lauf = 'DE'
          			AND bwg_typeflag = 'P'
          			AND stamm_landid = 1054
          			AND stamm_ag_audio_video = 'V'
          
        		group by
          			bwg_eancid,          
          			stamm_lauf
      			), 
			stamm_gesamt
             WHERE     voe_eancid = stamm_eancid
                   AND stamm_type_flag = 'P'
                   AND voe_lauf = 'DE'
                   AND stamm_ag_audio_video = 'V'
          	GROUP BY stamm_archivnr) s3,
             (  SELECT stamm_archivnr AS sarchnr,
                       ROUND (SUM (voe_menge), 0) AS dlvoemenge,
                       ROUND (SUM(VOE_wert), 2) as dlvoewert
                FROM   (
        		    SELECT 
          			SUM(bwg_wert) AS voe_wert,
          			SUM(bwg_menge) AS voe_menge,          
          			bwg_eancid as voe_eancid,
          			stamm_lauf as voe_lauf
        		    FROM
          			bewegung_w,
          			stamm_gesamt
        		   WHERE
          			bwg_eancid = stamm_eancid
          			AND bwg_landid = 1054
          			AND bwg_appid = 12
          			AND BWG_CONT_DIST IN ('641','645')
          			AND stamm_type_flag = 'D'
          			AND stamm_lauf = 'DWN'
          			AND bwg_typeflag = 'D'
          			AND stamm_landid = 1054
          			AND stamm_ag_audio_video = 'V'
          
        		group by
          			bwg_eancid,          
          			stamm_lauf
      			), stamm_gesamt
               WHERE   voe_eancid = stamm_eancid
                   AND stamm_type_flag = 'D'
                   AND voe_lauf = 'DWN'
                   AND stamm_ag_audio_video = 'V'
            GROUP BY   stamm_archivnr) s4,
           mcchart
   WHERE       Ausw_Zeitraum = '$zeit2'
           AND REPLACE (Code, 'A', '') = s1.sarchnr(+)
           AND REPLACE (Code, 'A', '') = s2.sarchnr(+)
           AND REPLACE (Code, 'A', '') = s3.sarchnr(+)
           AND REPLACE (Code, 'A', '') = s4.sarchnr(+)
           AND Modul_Id = 104
           AND TO_NUMBER (Platzierung) <= 50
GROUP BY   TO_NUMBER (Platzierung),
           chart_ean,
           REPLACE (Code, 'A', ''),
           Code,
           Titel,
           Autor,
           physmenge,
           dlmenge,
           physwert,
           dlwert,
           physvoemenge,
           physvoewert,
           dlvoemenge,
           dlvoewert,
           Firma,
           Titel_2,
           Modul_Id,
           tart,
           repe
ORDER BY   TO_NUMBER (Platzierung) ASC ";

echo $qry."\n" ;

$rs=$db->query($qry);

if (MDB2::isError($rs)) {
	dbug('error', $qry);
	$is_error=true;
}
else {
	while($arr= $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
		if ($arr) {
            $arr = array_utf_to_iso($arr);
			$daten[] = $arr;
		}
	}
	$rs->free();
}

//Achtung! Schreibt jetzt nach jedem Datensatz!
echo "\n\nSchreibe Daten.\n";
if(!$emif = fopen($nfs_root_dir . "temp/GERMV".substr($jahr,3).$woche."_new.SDF","w")) {
	$is_error=true;
} else {
	foreach ($daten as $satz) {
		if (!isset($satz['label']) || ($satz['label']) == '') {
			$satz['label'] = $satz['firma_dwl'];
		}
		$emineu = $satz['chart_rank'].';'.$satz['chart_ean'].';"'.$satz['chart_title'].'";"'.$satz['chart_artist'].'";'.
		$satz['physmenge'].';'.$satz['physwert'].';'.
        $satz['dlmenge'].';'.$satz['dlwert'].
        ';"'.$satz['label'].'";"'.$satz['chart_artist'].'";"'.$satz['chart_type'].'";"'.$satz['tart'].'";"'.
		$satz['repe'].'";"Download";'.$satz['sumdlphys'].';'.$satz['sumdlphyswert'].';'.
        $satz['physvoemenge'].";".$satz['physvoewert'].";".
        $satz['dlvoemenge'].";".$satz['dlvoewert'].
        "\r\n";
		fwrite($emif, $emineu);
	}
	fclose($emif);
}

if($is_error) {
	$par['empfaenger'] = $emi_reports_error_recipients;
	$par['message'] = array('subject'=>"Error EMI-MUSICVIDEOS-Datei",'body_txt'=>"(Exasol) Bei Generierung EMI-Datei Fehler aufgetreten!\n");
} else {
	$par['empfaenger'] = $emi_reports_normal_recipients;
	$par['message'] = array('subject'=>"EMI-MUSICVIDEOS-Datei",'body_txt'=>"(Exasol) EMI-MUSICVIDEOS-Datei generiert, siehe Anhang.\n");
}
sendEMail($par,$nfs_root_dir . "temp/GERMV".substr($jahr,3).$woche."_new.SDF");
unlink($nfs_root_dir . "temp/GERMV".substr($jahr,3).$woche."_new.SDF");

unset($daten);
?>
