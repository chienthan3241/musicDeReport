<?php 
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
#error_reporting(0);
ini_set('memory_limit', '-1');
set_time_limit(0);
require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

function get_sony_stream_monate_last_update_zeit_key($lastupdatefile){
	if(is_readable($lastupdatefile)){
		$fhandle = fopen($lastupdatefile, 'r');
        if($fhandle){
            $zeitkey = fread($fhandle, 6);
        }
        fclose($fhandle);
        if(preg_match('/\d{6}/', $zeitkey)){
            return $zeitkey;
        }
	}
	return false;
}

function update_sony_stream_monate_last_update_zeit_key($lastupdatefile,$zeitkey){
	$fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}
$is_error = false;

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"Error FTP-Daten Daily Top all Stream DE",
        'body_txt'=>"(Exasol) can not connect to the database.\n");

	sendEMail($par);

	die ($db->getMessage());
}

//===============================================================
$lastupdatefile = $nfs_root_dir . '/sony_stream_monatsdata_last_update.txt';
$qry = "select
			max (zeit_key) as zeit_key
		from
			zeitraum_gui
		where
			zeit_landid = 1054 and
			zeit_appid = 12 and
			zeit_einheit = 'M' and
			zeit_typeflag = 'S'";
$rs = $db->query($qry);
$data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
$zeit = $data['zeit_key'];
echo "checking last updated zeit_key...\n";
$last_time = get_sony_stream_monate_last_update_zeit_key($lastupdatefile);
if($last_time){
  echo "... found. it's $last_time\n";
  if($last_time < $zeit){
      echo "seems we have new month data.\n";
  }else{
      echo "No new month data, aborting.\n";
      exit();
  }
}else{
  echo "... not found. Generating data for the newst month.\n";
}
//$zeit = '201403';
$jahr4 = substr($zeit,0,4);
$jahr_tmp = $jahr4;
$monat = substr($zeit,-2,2);
if(substr($monat,0,1)=='0' && substr($monat,1,1) < 4){
	$jahr_tmp = $jahr_tmp-1;
}
$sql1="WITH
	stamm_query AS(
		SELECT
			DISTINCT stamm_prodnr AS own_prodnr
		FROM
			stamm_gesamt
		WHERE
			stamm_lauf = 'DWN' AND
			stamm_type_flag = 'D' AND
			stamm_appid = 12 AND
			stamm_landid = 1054
	),
	pre_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_pre_s,
			SUM(bwg_wert) sum_wert_pre_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key = $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 646 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	free_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_free_s,
			0 sum_wert_free_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key = $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 647 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	fact_query1 AS(
		SELECT
			COALESCE(pre_stream_query.prodnr, free_stream_query.prodnr) AS prodnr,
			(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) AS sum_meng,
			(NVL(sum_wert_pre_s, 0) + NVL(sum_wert_free_s, 0)) AS sum_wert,
			NVL(sum_meng_pre_s, 0) AS sum_meng_pre_s,
			NVL(sum_wert_pre_s, 0) AS sum_wert_pre_s,
			NVL(sum_meng_free_s, 0) AS sum_meng_free_s,
			NVL(sum_wert_free_s, 0) AS sum_wert_free_s,
			ROW_NUMBER() OVER(
				ORDER BY
					(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) DESC
			) index_
		FROM
				pre_stream_query FULL
			OUTER JOIN
				free_stream_query
			on
				free_stream_query.prodnr = pre_stream_query.prodnr
	),
	fact_query AS(
		SELECT
			fact_query1.*
		FROM
			fact_query1,
			stamm_query
		WHERE
			own_prodnr = prodnr
	),	
	rank_query AS(
		SELECT
			*
		FROM
			fact_query
		WHERE
			index_ BETWEEN 1 AND
			50000
	)
SELECT
	stamm_eanc,
	stamm_artist,
	stamm_titel,
	stamm_herst_txt,
	stamm_firm_code,
	stamm_label_txt,
	stamm_herst_txt2,
	stamm_wg_txt,
	TO_CHAR(
		DECODE(
			STAMM_VDATUM,
			'00000000',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'0',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'1',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			to_date(STAMM_VDATUM, 'yyyymmdd')
		),
		'DD.MM.YYYY'
	) as vdatum,
	index_,
	ROUND(sum_meng, 0) sum_meng,
	ROUND(sum_wert, 2) sum_wert,
	ROUND(sum_meng_pre_s, 0) sum_meng_pre_s,
	ROUND(sum_wert_pre_s, 2) sum_wert_pre_s,
	ROUND(sum_meng_free_s, 0) sum_meng_free_s,
	0 sum_wert_free_s	
FROM
	rank_query,
	stamm_gesamt
WHERE
	rank_query.prodnr = stamm_prodnr AND
	stamm_is_header = 1 AND
	stamm_landid = 1054 AND
	stamm_lauf = 'DWN' AND
	stamm_eancid not in (
		'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
	)
ORDER BY
	index_ ASC";

$sql2="WITH
	stamm_query AS(
		SELECT
			DISTINCT stamm_prodnr AS own_prodnr
		FROM
			stamm_gesamt
		WHERE
			stamm_lauf = 'DWN' AND
			stamm_type_flag = 'D' AND
			stamm_appid = 12 AND
			stamm_landid = 1054
	),
	pre_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_pre_s,
			SUM(bwg_wert) sum_wert_pre_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key >= ".$jahr4."01 AND
			zeit_key <= $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 646 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	free_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_free_s,
			0 sum_wert_free_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key >= ".$jahr4."01 AND
			zeit_key <= $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 647 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	fact_query1 AS(
		SELECT
			COALESCE(pre_stream_query.prodnr, free_stream_query.prodnr) AS prodnr,
			(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) AS sum_meng,
			(NVL(sum_wert_pre_s, 0) + NVL(sum_wert_free_s, 0)) AS sum_wert,
			NVL(sum_meng_pre_s, 0) AS sum_meng_pre_s,
			NVL(sum_wert_pre_s, 0) AS sum_wert_pre_s,
			NVL(sum_meng_free_s, 0) AS sum_meng_free_s,
			NVL(sum_wert_free_s, 0) AS sum_wert_free_s,
			ROW_NUMBER() OVER(
				ORDER BY
					(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) DESC
			) index_
		FROM
				pre_stream_query FULL
			OUTER JOIN
				free_stream_query
			on
				free_stream_query.prodnr = pre_stream_query.prodnr
	),
	fact_query AS(
		SELECT
			fact_query1.*
		FROM
			fact_query1,
			stamm_query
		WHERE
			own_prodnr = prodnr
	),	
	rank_query AS(
		SELECT
			*
		FROM
			fact_query
		WHERE
			index_ BETWEEN 1 AND
			50000
	)
SELECT
	stamm_eanc,
	stamm_artist,
	stamm_titel,
	stamm_herst_txt,
	stamm_firm_code,
	stamm_label_txt,
	stamm_herst_txt2,
	stamm_wg_txt,
	TO_CHAR(
		DECODE(
			STAMM_VDATUM,
			'00000000',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'0',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'1',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			to_date(STAMM_VDATUM, 'yyyymmdd')
		),
		'DD.MM.YYYY'
	) as vdatum,
	index_,
	ROUND(sum_meng, 0) sum_meng,
	ROUND(sum_wert, 2) sum_wert,
	ROUND(sum_meng_pre_s, 0) sum_meng_pre_s,
	ROUND(sum_wert_pre_s, 2) sum_wert_pre_s,
	ROUND(sum_meng_free_s, 0) sum_meng_free_s,
	0 sum_wert_free_s	
FROM
	rank_query,
	stamm_gesamt
WHERE
	rank_query.prodnr = stamm_prodnr AND
	stamm_is_header = 1 AND
	stamm_landid = 1054 AND
	stamm_lauf = 'DWN' AND
	stamm_eancid not in (
		'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
	)
ORDER BY
	index_ ASC";

$sql3="WITH
	stamm_query AS(
		SELECT
			DISTINCT stamm_prodnr AS own_prodnr
		FROM
			stamm_gesamt
		WHERE
			stamm_lauf = 'DWN' AND
			stamm_type_flag = 'D' AND
			stamm_appid = 12 AND
			stamm_landid = 1054
	),
	pre_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_pre_s,
			SUM(bwg_wert) sum_wert_pre_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key >= ".$jahr_tmp."04 AND
			zeit_key <= $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 646 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	free_stream_query AS(
		SELECT
			stamm_prodnr prodnr,
			SUM(bwg_menge) sum_meng_free_s,
			0 sum_wert_free_s
		FROM
			stamm_gesamt,
			region,
			bewegung_M,
			zeitraum_M
		WHERE
			bwg_haendlerid = region_haendlerid AND
			bwg_eancid = stamm_eancid AND
			bwg_zeitkey = zeit_key AND
			zeit_key >= ".$jahr_tmp."04 AND
			zeit_key <= $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 and
			stamm_appid = 12 and
			region_appid = 12 AND
			bwg_typeflag = 'D' AND
			stamm_type_flag = 'D' AND
			STAMM_AG_PHY_DIGI = 'D' AND
			stamm_lauf = 'DWN' AND
			bwg_cont_dist = 647 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) AND
			NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
			stamm_musikfremd = 0 AND
			stamm_ag_main_format = 'S' AND
			stamm_ag_audio_video in ('A', 'V') AND
			stamm_wg_header_code in (10100, 10200) AND
			stamm_wg_code not in (10112, 10124)
		GROUP BY
			stamm_prodnr
	),
	fact_query1 AS(
		SELECT
			COALESCE(pre_stream_query.prodnr, free_stream_query.prodnr) AS prodnr,
			(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) AS sum_meng,
			(NVL(sum_wert_pre_s, 0) + NVL(sum_wert_free_s, 0)) AS sum_wert,
			NVL(sum_meng_pre_s, 0) AS sum_meng_pre_s,
			NVL(sum_wert_pre_s, 0) AS sum_wert_pre_s,
			NVL(sum_meng_free_s, 0) AS sum_meng_free_s,
			NVL(sum_wert_free_s, 0) AS sum_wert_free_s,
			ROW_NUMBER() OVER(
				ORDER BY
					(NVL(sum_meng_pre_s, 0) + NVL(sum_meng_free_s, 0)) DESC
			) index_
		FROM
				pre_stream_query FULL
			OUTER JOIN
				free_stream_query
			on
				free_stream_query.prodnr = pre_stream_query.prodnr
	),
	fact_query AS(
		SELECT
			fact_query1.*
		FROM
			fact_query1,
			stamm_query
		WHERE
			own_prodnr = prodnr
	),	
	rank_query AS(
		SELECT
			*
		FROM
			fact_query
		WHERE
			index_ BETWEEN 1 AND
			50000
	)
SELECT
	stamm_eanc,
	stamm_artist,
	stamm_titel,
	stamm_herst_txt,
	stamm_firm_code,
	stamm_label_txt,
	stamm_herst_txt2,
	stamm_wg_txt,
	TO_CHAR(
		DECODE(
			STAMM_VDATUM,
			'00000000',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'0',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			'1',
			to_date('01.01.1980', 'dd.mm.yyyy'),
			to_date(STAMM_VDATUM, 'yyyymmdd')
		),
		'DD.MM.YYYY'
	) as vdatum,
	index_,
	ROUND(sum_meng, 0) sum_meng,
	ROUND(sum_wert, 2) sum_wert,
	ROUND(sum_meng_pre_s, 0) sum_meng_pre_s,
	ROUND(sum_wert_pre_s, 2) sum_wert_pre_s,
	ROUND(sum_meng_free_s, 0) sum_meng_free_s,
	0 sum_wert_free_s	
FROM
	rank_query,
	stamm_gesamt
WHERE
	rank_query.prodnr = stamm_prodnr AND
	stamm_is_header = 1 AND
	stamm_landid = 1054 AND
	stamm_lauf = 'DWN' AND
	stamm_eancid not in (
		'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
	)
ORDER BY
	index_ ASC";
$arr1 = array();
$data1 = array();
$ct = 0;
$rs1=$db->query($sql1);
if (MDB2::isError($rs1)) {
    dbug('error', $sql1);
    $is_error = true;
    $mail_body .= "SQL error!!\n";
} else {
	while($arr1= $rs1->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if ($arr1) {                
                //convert everything from UTF8 to ISO for backward compatibility
                #$arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data1[$ct]['time']           	= $zeit;
                $data1[$ct]['isrc']          	= (isset($arr1['stamm_eanc']) and strlen($arr1['stamm_eanc']) > 0) ? $arr1['stamm_eanc'] : '';
				$data1[$ct]['artist']     		= (isset($arr1['stamm_artist']) and strlen($arr1['stamm_artist']) > 0) ? $arr1['stamm_artist'] : '';
                $data1[$ct]['title']         	= (isset($arr1['stamm_titel']) and strlen($arr1['stamm_titel']) > 0) ? $arr1['stamm_titel'] : '';
                $data1[$ct]['distributor'] 		= (isset($arr1['stamm_herst_txt']) and strlen($arr1['stamm_herst_txt']) > 0) ? $arr1['stamm_herst_txt'] : '';
				$data1[$ct]['distributor_code'] = $arr1['stamm_firm_code'];
                $data1[$ct]['label'] 			= $arr1['stamm_label_txt'];
                $data1[$ct]['div'] 				= $arr1['stamm_herst_txt2'];
                $data1[$ct]['genre']			= $arr1['stamm_wg_txt'];
                $data1[$ct]['vdatum']			= $arr1['vdatum'];               
                $data1[$ct]['stream_units'] 	= $arr1['sum_meng'];
                $data1[$ct]['pre_stream_units'] 	= $arr1['sum_meng_pre_s'];
                $data1[$ct]['free_stream_units'] 	= $arr1['sum_meng_free_s'];
                
            }
            $ct++;
        }
        $rs1->free();
}
$arr2 = array();
$data2 = array();
$ct = 0;
$rs2=$db->query($sql2);
if (MDB2::isError($rs2)) {
    dbug('error', $sql2);
    $is_error = true;
    $mail_body .= "SQL error!!\n";
} else {
	while($arr2= $rs2->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if ($arr2) {                
                //convert everything from UTF8 to ISO for backward compatibility
                #$arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data2[$ct]['time']           	= $jahr4.'01-'.$zeit;
                $data2[$ct]['isrc']          	= (isset($arr2['stamm_eanc']) and strlen($arr2['stamm_eanc']) > 0) ? $arr2['stamm_eanc'] : '';
				$data2[$ct]['artist']     		= (isset($arr2['stamm_artist']) and strlen($arr2['stamm_artist']) > 0) ? $arr2['stamm_artist'] : '';
                $data2[$ct]['title']         	= (isset($arr2['stamm_titel']) and strlen($arr2['stamm_titel']) > 0) ? $arr2['stamm_titel'] : '';
                $data2[$ct]['distributor'] 		= (isset($arr2['stamm_herst_txt']) and strlen($arr2['stamm_herst_txt']) > 0) ? $arr2['stamm_herst_txt'] : '';
				$data2[$ct]['distributor_code'] = $arr2['stamm_firm_code'];
                $data2[$ct]['label'] 			= $arr2['stamm_label_txt'];
                $data2[$ct]['div'] 				= $arr2['stamm_herst_txt2'];
                $data2[$ct]['genre']			= $arr2['stamm_wg_txt'];
                $data2[$ct]['vdatum']			= $arr2['vdatum'];               
                $data2[$ct]['stream_units'] 	= $arr2['sum_meng'];
                $data2[$ct]['pre_stream_units'] 	= $arr2['sum_meng_pre_s'];
                $data2[$ct]['free_stream_units'] 	= $arr2['sum_meng_free_s'];
                
            }
            $ct++;
        }
        $rs2->free();
}

$arr3 = array();
$data3 = array();
$ct = 0;
$rs3=$db->query($sql3);
if (MDB2::isError($rs3)) {
    dbug('error', $sql3);
    $is_error = true;
    $mail_body .= "SQL error!!\n";
} else {
	while($arr3= $rs3->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if ($arr3) {                
                //convert everything from UTF8 to ISO for backward compatibility
                #$arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data3[$ct]['time']           	= $jahr_tmp.'04-'.$zeit;
                $data3[$ct]['isrc']          	= (isset($arr3['stamm_eanc']) and strlen($arr3['stamm_eanc']) > 0) ? $arr3['stamm_eanc'] : '';
				$data3[$ct]['artist']     		= (isset($arr3['stamm_artist']) and strlen($arr3['stamm_artist']) > 0) ? $arr3['stamm_artist'] : '';
                $data3[$ct]['title']         	= (isset($arr3['stamm_titel']) and strlen($arr3['stamm_titel']) > 0) ? $arr3['stamm_titel'] : '';
                $data3[$ct]['distributor'] 		= (isset($arr3['stamm_herst_txt']) and strlen($arr3['stamm_herst_txt']) > 0) ? $arr3['stamm_herst_txt'] : '';
				$data3[$ct]['distributor_code'] = $arr3['stamm_firm_code'];
                $data3[$ct]['label'] 			= $arr3['stamm_label_txt'];
                $data3[$ct]['div'] 				= $arr3['stamm_herst_txt2'];
                $data3[$ct]['genre']			= $arr3['stamm_wg_txt'];
                $data3[$ct]['vdatum']			= $arr3['vdatum'];               
                $data3[$ct]['stream_units'] 	= $arr3['sum_meng'];
                $data3[$ct]['pre_stream_units'] 	= $arr3['sum_meng_pre_s'];
                $data3[$ct]['free_stream_units'] 	= $arr3['sum_meng_free_s'];
                
            }
            $ct++;
        }
        $rs3->free();
}
//==================================================================================

//==================================================================================
//Text file
$file_name1 = 'StreamMonth'.$zeit;
$file_name2 = 'StreamYTD'.$zeit;
$file_name3 = 'StreamFYTD'.$zeit;
if(($file = fopen($nfs_root_dir . 'sonystream/'.$file_name1.'.txt', 'w')) === FALSE) {
    $is_error = true;
    $mail_body .= "can not write data to file\n";
} else {
	//write first line
	$line = "Month\tEAN/ISRC\tARTIST\tTITLE\tDISTRIBUTOR\tDIST CODE\tLABEL\tDIV\tREPERTOIRE\tRELEASE DATE\tALL STR\tPREM STR\tADF STR\n";
		fwrite($file, $line);
	foreach ($data1 as $c=>$dt) {
		$line = $dt['time']."\t".$dt['isrc']."\t".$dt['artist']."\t".$dt['title']."\t".$dt['distributor']."\t".$dt['distributor_code']."\t".$dt['label']."\t".$dt['div']."\t".$dt['genre']."\t".$dt['vdatum']."\t".				
				$dt['stream_units']."\t".$dt['pre_stream_units']."\t".$dt['free_stream_units']."\n";
		fwrite($file, $line);
	}
	fclose($file);
}
if(($file = fopen($nfs_root_dir . 'sonystream/'.$file_name2.'.txt', 'w')) === FALSE) {
    $is_error = true;
    $mail_body .= "can not write data to file\n";
} else {
	//write first line
	$line = "Month\tEAN/ISRC\tARTIST\tTITLE\tDISTRIBUTOR\tDIST CODE\tLABEL\tDIV\tREPERTOIRE\tRELEASE DATE\tALL STR\tPREM STR\tADF STR\n";
		fwrite($file, $line);
	foreach ($data2 as $c=>$dt) {
		$line = $dt['time']."\t".$dt['isrc']."\t".$dt['artist']."\t".$dt['title']."\t".$dt['distributor']."\t".$dt['distributor_code']."\t".$dt['label']."\t".$dt['div']."\t".$dt['genre']."\t".$dt['vdatum']."\t".				
				$dt['stream_units']."\t".$dt['pre_stream_units']."\t".$dt['free_stream_units']."\n";
		fwrite($file, $line);
	}
	fclose($file);
}
if(($file = fopen($nfs_root_dir . 'sonystream/'.$file_name3.'.txt', 'w')) === FALSE) {
    $is_error = true;
    $mail_body .= "can not write data to file\n";
} else {
	//write first line
	$line = "Month\tEAN/ISRC\tARTIST\tTITLE\tDISTRIBUTOR\tDIST CODE\tLABEL\tDIV\tREPERTOIRE\tRELEASE DATE\tALL STR\tPREM STR\tADF STR\n";
		fwrite($file, $line);
	foreach ($data3 as $c=>$dt) {
		$line = $dt['time']."\t".$dt['isrc']."\t".$dt['artist']."\t".$dt['title']."\t".$dt['distributor']."\t".$dt['distributor_code']."\t".$dt['label']."\t".$dt['div']."\t".$dt['genre']."\t".$dt['vdatum']."\t".				
				$dt['stream_units']."\t".$dt['pre_stream_units']."\t".$dt['free_stream_units']."\n";
		fwrite($file, $line);
	}
	fclose($file);
}
//upload to FTP
echo "begin upload to FTP...\n";
	$ftp = new FTP;
	$ftp->connect($ftp_host);
	$ftp->login($ftp_username, $ftp_password);
	$ftp_path = $target_path_de . '/BMG/';
echo "Upload to $ftp_path \n";
if ( ! $ftp->put($ftp_path.$file_name1.'.txt',
	    	$nfs_root_dir.'sonystream/'.$file_name1.'.txt', FTP_BINARY)){
	  	echo "failed to upload: " . $file_name1.'.txt' . "\n";
	  	$is_error = true;
	  }
if ( ! $ftp->put($ftp_path.$file_name2.'.txt',
	    	$nfs_root_dir.'sonystream/'.$file_name2.'.txt', FTP_BINARY)){
	  	echo "failed to upload: " . $file_name2.'.txt' . "\n";
	  	$is_error = true;
	  }
if ( ! $ftp->put($ftp_path.$file_name3.'.txt',
	    	$nfs_root_dir.'sonystream/'.$file_name3.'.txt', FTP_BINARY)){
	  	echo "failed to upload: " . $file_name3.'.txt' . "\n";
	  	$is_error = true;
	  }
//===============================================================
if($is_error){
    $par['empfaenger'] = $reports_error_recipients;
    $par['message'] = array('subject'=>"Sony Stream Monat Report Skript error",'body_txt'=>$mail_body);
    sendEMail($par);
}else{
    $par['empfaenger'] = $reports_normal_recipients;
    $par['message'] = array('subject'=>"Sony Stream Monat (".$zeit.")",'body_txt'=>"Sony Stream Monat report for time $zeit ist created and upload to FTP!");
    sendEMail($par);
    update_sony_stream_monate_last_update_zeit_key($lastupdatefile,$zeit);
}
$db->disconnect();
?>