<?php
$reflect = new ReflectionExtension('excel');

$classes = $reflect->getClasses();
foreach($classes as $class) {
    echo '<h5>'.$class->getName().'</h5>'.PHP_EOL;
    $methods = $class->getMethods();
    foreach ($methods as $method) {
        $methodname = $method->getName();
        #if (substr($methodname, 0, 2) !== '__') {
            echo '' . $methodname . '( ';
            $parameters = array();
            $params = $method->getParameters();
            foreach($params as $params) {
                $parameters[] = '$'. (string) $params->getName();
            }
            echo implode(", ", $parameters);
            echo ' ) <br>
';
        }
    #}
}
die();
require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

$is_error = false;
$error_msg = '';

$email_delivery = array(
    "Manh-Cuong.Tran@gfk.com, marketingservices-transfer@universal-music.de, Mike.Timm@gfk.com"
);
$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"Error FTP-Daten Daily Top all Stream DE",
        'body_txt'=>"(Exasol) can not connect to the database.\n");

	sendEMail($par);

	die ($db->getMessage());
}

function get_last_update_zeitkey($lastupdatefile){
    if(is_readable($lastupdatefile)){
        $fhandle = fopen($lastupdatefile, 'r');
        if($fhandle){
            $zeitkey = fread($fhandle, 8);
        }
        fclose($fhandle);
        if(preg_match('/\d{8}/', $zeitkey)){
            return $zeitkey;
        }
    }
    return false;
}

function get_week_num($zeit,$type){
	global $db;
	$result='';
	$jahr4 = substr($zeit,0,4);
    $monate2 = substr($zeit,-4,2);
    $tag2 = substr($zeit,-2,2);
	$zeit_tmp = date('Ymd',strtotime('-6 days',strtotime($jahr4.'-'.$monate2.'-'.$tag2)));
	$qry = "select min(zeit_key) as zeit_key 
			from (
				select 
					distinct zeit_key  
				from 
					zeit_wert_w 
				where 
					land_id = 1054 and app_id = 12 
					and
					to_char(start_date,'YYYYMMDD') <= $zeit_tmp 
					and $zeit_tmp <=to_char(end_date, 'YYYYMMDD')
				union
				select max(zeit_key) as zeit_key
				FROM
					zeitraum_gui
				WHERE
					zeit_lanDid = 1054 and
					zeit_appid = 12 and
					zeit_einheit = 'W' and
					zeit_Typeflag = '".$type."'
				)";				
	$rs = $db->query($qry);
	while($data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC)){
        $result = $data['zeit_key'];
    }
    $rs->free();
    return $result;
}

function update_last_update_zeitkey($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}

$lastupdatefile = $nfs_root_dir . '/tagesstream_last_update.txt';
$zeit_keys = array();

if(!isset($_REQUEST['zeitid'])) {
    //check last tagesdata date.
    echo "checking last updated zeit_key...\n";
    $last_time = get_last_update_zeitkey($lastupdatefile);
    if($last_time){
        echo "... found. it's $last_time\n";
    }
    echo "querying avalaible zeit_keys...\n";
	$qry = "
			 SELECT * from (select zeit_key
			    FROM zeitraum_gui
			   WHERE     zeit_typeflag = 'S'
			         AND zeit_einheit = 'T'
			         AND zeit_landid = '1054'
			         AND zeit_appid = 12
			ORDER BY zeit_key DESC) where rownum <= 7 ";
	$rs = $db->query($qry);
    echo "done.\n";
    while($data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC)){
        if(!$last_time){
            echo "didnt find last updated zeitkey, will generate tagesdata for the top most zeit_key.\n";
            $zeit_keys[] = $data['zeit_key'];
            break;
        }
        if($data['zeit_key'] > $last_time){
            echo "queueing date for " . $data['zeit_key'] . " ...\n";
            $zeit_keys[] = $data['zeit_key'];
        }else{
            echo $data['zeit_key'] . " is not newer than last update zeitkey ($last_time), ignored.\n";
        }
    }
    $zeit_keys = array_reverse($zeit_keys);
} else {
	$zeit_keys[] = $_REQUEST['zeitid'];
}
#$zeit_keys = array('20141216','20141217','20141218','20141219','20141220','20141221','20141222','20141223','20141224','20141225','20141226','20141227','20141228','20141229','20141230','20141231');
$zeit_keys = array('20150101','20150102','20150103','20150104','20150105','20150106','20150107','20150108','20150109','20150110','20150111','20150112','20150113');


foreach($zeit_keys as $zeit){
	echo "generating tages Stream data for $zeit ...\n";
	$jahr4 = substr($zeit,0,4);
    $monate2 = substr($zeit,-4,2);
    $tag2 = substr($zeit,-2,2);
    $current_week_num_p = get_week_num($zeit,'P');
    $current_week_num_d = get_week_num($zeit,'D');
    $current_week_num_s = get_week_num($zeit,'S');
    $mail_body = '';
    $udata = array();
    $sql = "
      with
	tmp as (
		select
			stamm_eancid,
			stamm_match_code
		from
			stamm_gesamt
		where
			stamm_match_code != '0' AND
			length(stamm_archivnr) < 12 AND
			STAMM_AG_MAIN_FORMAT = 'S' AND
			stamm_lauf in ('DE', 'DWN') AND
			stamm_musikfremd = 0 AND
			stamm_landid = 1054 AND
			stamm_eancid not in (
				'D28678793', 'D23786869', 'D16339882', 'D25990550', 'D25439555', 'D25429311', 'D30826691'
			) and
			stamm_type_flag in ('D', 'P') and
			NVL(stamm_quelle, 'EMPTY') <> 'MA'
	),
	bwg_tmp as (
		select
			bewegung_t.*
		from
			bewegung_t,
			region
		where
			bwg_haendlerid = region_haendlerid AND
			bwg_zeitkey = $zeit and
			bwg_landid = 1054 and
			bwg_appid = 12 AND
			bwg_typeflag in ('D', 'P') AND
			region_awid not in (243, 361, 2) and
			bwg_cont_dist in (641, 645, 646, 647) and
			region_appid = 12
	),
	bewegung_query as (
		select
			nvl(bwg_haendlerid, 100009) as bwg_haendlerid,
			stamm_eancid as bwg_eancid,
			nvl(bwg_appid, 12) as bwg_appid,
			nvl(bwg_landid, 1054) as bwg_landid,
			nvl(bwg_zeitkey, $zeit) as bwg_zeitkey,
			nvl(bwg_menge, 0) as bwg_menge,
			nvl(bwg_wert, 0) as bwg_wert,
			nvl(bwg_typeflag, 'P') as bwg_typeflag,
			nvl(bwg_cont_dist, 641) as bwg_cont_dist,
			stamm_match_code
		from
				tmp
			left outer join
				bwg_tmp
			on
				tmp.stamm_eancid = bwg_tmp.bwg_eancid
	),
	prod_query as (
		SELECT
			stamm_match_code,
			bwg_cont_dist,
			sum(bwg_menge) menge,
			sum(bwg_wert) wert
		FROM
			bewegung_query
		WHERE
			bwg_zeitkey = $zeit AND
			bwg_landid = 1054 AND
			bwg_appid = 12 AND
			bwg_eancid not in (
				'D28678793',
				'D23786869',
				'D16339882',
				'D25990550',
				'D25439555',
				'D25429311',
				'D30826691'
			)
		group by
			stamm_match_code,
			bwg_cont_dist
	),
	combine_query AS(
		SELECT
			stamm_match_code,
			sum(dwn_meng) dwn_meng,
			sum(dwn_wert) dwn_wert,
			sum(phys_meng) phys_meng,
			sum(phys_wert) phys_wert,
			sum(pre_stream_meng) pre_stream_meng,
			sum(pre_stream_wert) pre_stream_wert,
			sum(free_stream_meng) free_stream_meng,
			sum(free_stream_wert) free_stream_wert,
			sum(dwn_wert) + sum(phys_wert) + sum(pre_stream_wert) + sum(free_stream_wert) prod_wert,
			sum(dwn_meng) + sum(phys_meng) + sum(pre_stream_meng) + sum(free_stream_meng) prod_meng,
			sum(pre_stream_wert) + sum(free_stream_wert) stream_prod_wert,
			sum(pre_stream_meng) + sum(free_stream_meng) stream_prod_meng,
			row_number() over(
				order by
					sum(dwn_wert) + sum(phys_wert) + sum(pre_stream_wert) + sum(free_stream_wert) DESC
			) index_
		from
			(
					SELECT
						0 dwn_meng,
						0 dwn_wert,
						menge phys_meng,
						wert phys_wert,
						0 pre_stream_meng,
						0 pre_stream_wert,
						0 free_stream_meng,
						0 free_stream_wert,
						stamm_match_code
					FROM
						prod_query
					WHERE
						bwg_cont_dist = 641
				union all
					SELECT
						menge dwn_meng,
						wert dwn_wert,
						0 phys_meng,
						0 phys_wert,
						0 pre_stream_meng,
						0 pre_stream_wert,
						0 free_stream_meng,
						0 free_stream_wert,
						stamm_match_code
					FROM
						prod_query
					WHERE
						bwg_cont_dist = 645
				union all
					SELECT
						0 dwn_meng,
						0 dwn_wert,
						0 phys_meng,
						0 phys_wert,
						menge pre_stream_meng,
						wert pre_stream_wert,
						0 free_stream_meng,
						0 free_stream_wert,
						stamm_match_code
					FROM
						prod_query
					WHERE
						bwg_cont_dist = 646
				union all
					SELECT
						0 dwn_meng,
						0 dwn_wert,
						0 phys_meng,
						0 phys_wert,
						0 pre_stream_meng,
						0 pre_stream_wert,
						menge free_stream_meng,
						wert free_stream_wert,
						stamm_match_code
					FROM
						prod_query
					WHERE
						bwg_cont_dist = 647
			)
		group by
			stamm_match_code
	),
	rank_query AS(SELECT * FROM combine_query order by index_ asc),
	voe_query_dwn AS(
		SELECT
			rank_query.*,
			nvl(voe_menge, 0) as voe_menge,
			nvl(voe_wert, 0) as voe_wert,
			voe_lauf
		FROM
			(
				SELECT
					nvl(weekdata.voe_menge_W, 0) + nvl(daydata.voe_menge_T, 0) AS voe_menge,
					nvl(weekdata.voe_wert_W, 0) + nvl(daydata.voe_wert_T, 0) AS voe_wert,
					1054 AS voe_landid,
					coalesce(weekdata.voe_lauf, daydata.voe_lauf) AS voe_lauf,
					coalesce(weekdata.voe_match_code, daydata.voe_match_code) AS voe_match_code
				FROM
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_W,
								SUM(bwg_menge) AS voe_menge_W,
								bwg_landid AS voe_landid,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_W,
								stamm_gesamt,
								cont_dist,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								cont_dist_id = bwg_cont_dist AND
								cont_dist_id = 645 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								bwg_zeitkey <= $current_week_num_d AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) weekdata FULL
					OUTER JOIN
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_T,
								SUM(bwg_menge) AS voe_menge_T,
								bwg_landid AS voe_landid,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_T,
								stamm_gesamt,
								cont_dist,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								cont_dist_id = bwg_cont_dist AND
								cont_dist_id = 645 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								bwg_zeitkey > (
									SELECT
										to_char(end_date, 'YYYYMMDD')
									FROM
										zeit_wert_w
									WHERE
										zeit_key = $current_week_num_d and
										land_id = 1054 and
										app_id = 12
								) AND
								bwg_zeitkey <= $zeit AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) daydata
					ON
						(weekdata.voe_match_code = daydata.voe_match_code)
			),
			rank_query
		WHERE
			voe_match_code = stamm_match_code AND
			voe_landid = 1054 AND
			voe_lauf = 'DWN'
	),
	voe_query_phys AS(
		SELECT
			rank_query.*,
			voe_menge,
			voe_wert,
			voe_lauf
		FROM
			(
				SELECT
					nvl(weekdata.voe_menge_W, 0) + nvl(daydata.voe_menge_T, 0) AS voe_menge,
					nvl(weekdata.voe_wert_W, 0) + nvl(daydata.voe_wert_T, 0) AS voe_wert,
					1054 AS voe_landid,
					coalesce(weekdata.voe_lauf, daydata.voe_lauf) AS voe_lauf,
					coalesce(weekdata.voe_match_code, daydata.voe_match_code) AS voe_match_code
				FROM
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_W,
								SUM(bwg_menge) AS voe_menge_W,
								bwg_landid AS voe_landid,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_W,
								stamm_gesamt,
								cont_dist,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								cont_dist_id = bwg_cont_dist AND
								cont_dist_id = 641 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								bwg_zeitkey <= $current_week_num_p AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) weekdata FULL
					OUTER JOIN
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_T,
								SUM(bwg_menge) AS voe_menge_T,
								bwg_landid AS voe_landid,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_T,
								stamm_gesamt,
								cont_dist,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								cont_dist_id = bwg_cont_dist AND
								cont_dist_id = 641 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								bwg_zeitkey > (
									SELECT
										to_char(end_date, 'YYYYMMDD')
									FROM
										zeit_wert_w
									WHERE
										zeit_key = $current_week_num_p and
										land_id = 1054 and
										app_id = 12
								) AND
								bwg_zeitkey <= $zeit AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) daydata
					ON
						(weekdata.voe_match_code = daydata.voe_match_code)
			),
			rank_query
		WHERE
			voe_match_code = stamm_match_code AND
			voe_landid = 1054 AND
			voe_lauf = 'DE'
	),
	voe_query_pre_stream AS(
		SELECT
			rank_query.*,
			voe_menge,
			voe_wert,
			voe_lauf
		FROM
			(
				SELECT
					nvl(weekdata.voe_menge_W, 0) + nvl(daydata.voe_menge_T, 0) AS voe_menge,
					nvl(weekdata.voe_wert_W, 0) + nvl(daydata.voe_wert_T, 0) AS voe_wert,
					1054 AS voe_landid,
					coalesce(weekdata.voe_lauf, daydata.voe_lauf) AS voe_lauf,
					coalesce(weekdata.voe_match_code, daydata.voe_match_code) AS voe_match_code
				FROM
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_W,
								SUM(bwg_menge) AS voe_menge_W,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_W,
								stamm_gesamt,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								bwg_cont_dist = 646 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								bwg_zeitkey <= $current_week_num_s AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) weekdata FULL
					OUTER JOIN
						(
							SELECT
								SUM(bwg_wert) AS voe_wert_T,
								SUM(bwg_menge) AS voe_menge_T,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_T,
								stamm_gesamt,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								bwg_cont_dist = 646 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								bwg_zeitkey > (
									SELECT
										to_char(end_date, 'YYYYMMDD')
									FROM
										zeit_wert_w
									WHERE
										zeit_key = $current_week_num_s and
										land_id = 1054 and
										app_id = 12
								) AND
								bwg_zeitkey <= $zeit AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) daydata
					ON
						(weekdata.voe_match_code = daydata.voe_match_code)
			),
			rank_query
		WHERE
			voe_match_code = stamm_match_code AND
			voe_landid = 1054 AND
			voe_lauf = 'DWN'
	),
	voe_query_free_stream AS(
		SELECT
			rank_query.*,
			voe_menge,
			voe_wert,
			voe_lauf
		FROM
			(
				SELECT
					nvl(weekdata.voe_menge_W, 0) + nvl(daydata.voe_menge_T, 0) AS voe_menge,
					nvl(weekdata.voe_wert_W, 0) + nvl(daydata.voe_wert_T, 0) AS voe_wert,
					1054 AS voe_landid,
					coalesce(weekdata.voe_lauf, daydata.voe_lauf) AS voe_lauf,
					coalesce(weekdata.voe_match_code, daydata.voe_match_code) AS voe_match_code
				FROM
						(
							SELECT
								sum(bwg_wert) AS voe_wert_W,
								SUM(bwg_menge) AS voe_menge_W,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_W,
								stamm_gesamt,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								bwg_cont_dist = 647 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								bwg_zeitkey <= $current_week_num_s AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) weekdata FULL
					OUTER JOIN
						(
							SELECT
								sum(bwg_wert) AS voe_wert_T,
								SUM(bwg_menge) AS voe_menge_T,
								stamm_lauf AS voe_lauf,
								stamm_gesamt.stamm_match_code AS voe_match_code
							FROM
								bewegung_T,
								stamm_gesamt,
								region,
								rank_query
							WHERE
								bwg_eancid = stamm_eancid AND
								region_haendlerid = bwg_haendlerid AND
								bwg_cont_dist = 647 AND
								bwg_landid = 1054 AND
								stamm_landid = 1054 AND
								bwg_appid = 12 AND
								stamm_appid = 12 AND
								region_appid = 12 AND
								bwg_zeitkey > (
									SELECT
										to_char(end_date, 'YYYYMMDD')
									FROM
										zeit_wert_w
									WHERE
										zeit_key = $current_week_num_s and
										land_id = 1054 and
										app_id = 12
								) AND
								bwg_zeitkey <= $zeit AND
								stamm_gesamt.stamm_match_code != '0' AND
								length(stamm_archivnr) < 12 AND
								STAMM_AG_MAIN_FORMAT = 'S' AND
								stamm_gesamt.stamm_match_code = rank_query.stamm_match_code AND
								region_awid not in (243, 361, 2) AND
								NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
								stamm_landid = 1054 AND
								stamm_musikfremd = 0 AND
								stamm_eancid not in (
									'D28678793',
									'D23786869',
									'D16339882',
									'D25990550',
									'D25439555',
									'D25429311',
									'D30826691'
								)
							GROUP BY
								bwg_landid,
								stamm_lauf,
								stamm_gesamt.stamm_match_code
						) daydata
					ON
						(weekdata.voe_match_code = daydata.voe_match_code)
			),
			rank_query
		WHERE
			voe_match_code = stamm_match_code AND
			voe_landid = 1054 AND
			voe_lauf = 'DWN'
	),
	voe_combine_query AS(
		SELECT
			COALESCE(
				a.stamm_match_code,
				b.stamm_match_code,
				c.stamm_match_code,
				d.stamm_match_code
			) stamm_match_code,
			COALESCE(a.dwn_meng, b.dwn_meng, c.dwn_meng, d.dwn_meng) dwn_meng,
			COALESCE(a.dwn_wert, b.dwn_wert, c.dwn_wert, d.dwn_wert) dwn_wert,
			COALESCE(
				a.phys_meng,
				b.phys_meng,
				c.phys_meng,
				d.phys_meng
			) phys_meng,
			COALESCE(
				a.phys_wert,
				b.phys_wert,
				c.phys_wert,
				d.phys_wert
			) phys_wert,
			COALESCE(
				a.pre_stream_meng,
				b.pre_stream_meng,
				c.pre_stream_meng,
				d.pre_stream_meng
			) pre_stream_meng,
			COALESCE(
				a.pre_stream_wert,
				b.pre_stream_wert,
				c.pre_stream_wert,
				d.pre_stream_wert
			) pre_stream_wert,
			COALESCE(
				a.free_stream_meng,
				b.free_stream_meng,
				c.free_stream_meng,
				d.free_stream_meng
			) free_stream_meng,
			COALESCE(
				a.free_stream_wert,
				b.free_stream_wert,
				c.free_stream_wert,
				d.free_stream_wert
			) free_stream_wert,
			COALESCE(
				a.prod_meng,
				b.prod_meng,
				c.prod_meng,
				d.prod_meng
			) prod_meng,
			COALESCE(
				a.prod_wert,
				b.prod_wert,
				c.prod_wert,
				d.prod_wert
			) prod_wert,
			COALESCE(
				a.stream_prod_wert,
				b.stream_prod_wert,
				c.stream_prod_wert,
				d.stream_prod_wert
			) stream_prod_wert,
			COALESCE(
				a.stream_prod_meng,
				b.stream_prod_meng,
				c.stream_prod_meng,
				d.stream_prod_meng
			) stream_prod_meng,
			COALESCE(a.index_, b.index_, c.index_, d.index_) index_,
			nvl(a.voe_menge, 0) + nvl(b.voe_menge, 0) + nvl(c.voe_menge, 0) + nvl(d.voe_menge, 0) prod_voe_meng,
			nvl(a.voe_wert, 0) + nvl(b.voe_wert, 0) + nvl(c.voe_wert, 0) + nvl(d.voe_wert, 0) prod_voe_wert,
			nvl(c.voe_menge, 0) + nvl(d.voe_menge, 0) stream_prod_voe_meng,
			nvl(c.voe_wert, 0) + nvl(d.voe_wert, 0) stream_prod_voe_wert,
			nvl(b.voe_menge, 0) dwn_voe_meng,
			nvl(b.voe_wert, 0) dwn_voe_wert,
			nvl(a.voe_menge, 0) phys_voe_meng,
			nvl(a.voe_wert, 0) phys_voe_wert,
			nvl(c.voe_menge, 0) pre_stream_voe_meng,
			nvl(c.voe_wert, 0) pre_stream_voe_wert,
			nvl(d.voe_menge, 0) free_stream_voe_meng,
			nvl(d.voe_wert, 0) free_stream_voe_wert
		FROM
				voe_query_phys a full
			outer join
				voe_query_dwn b
			on
				a.stamm_match_code = b.stamm_match_code full
			outer join
				voe_query_pre_stream c
			on
				c.stamm_match_code = COALESCE(a.stamm_match_code, b.stamm_match_code) full
			outer join
				voe_query_free_stream d
			on
				d.stamm_match_code = COALESCE(
					a.stamm_match_code,
					b.stamm_match_code,
					c.stamm_match_code
				)
	)
SELECT
	stamm_eanc,
	stamm_titel stitl,
	stamm_artist sinte,
	stamm_label_txt slabl,
	stamm_herst_txt AS herst_txt,
	stamm_firm_code,
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
	stamm_display_code displaycode,
	stamm_gesamt.stamm_match_code match_code,
	index_,
	round(stream_prod_meng, 0) prod_stream_meng,
	round(phys_meng, 0) phys_meng,
	round(dwn_meng, 0) dwn_meng,
	round(pre_stream_meng, 0) pre_stream_meng,
	round(free_stream_meng, 0) free_stream_meng,
	round(stream_prod_voe_meng, 0) stream_prod_voe_meng,
	round(phys_voe_meng, 0) phys_voe_meng,
	round(dwn_voe_meng, 0) dwn_voe_meng,
	round(pre_stream_voe_meng, 0) pre_stream_voe_meng,
	round(free_stream_voe_meng, 0) free_stream_voe_meng
FROM
	voe_combine_query,
	stamm_gesamt
WHERE
	voe_combine_query.stamm_match_code = stamm_gesamt.stamm_match_code AND
	stamm_mainprod_header = 1 AND
	stamm_landid = 1054 AND
	CASE
		WHEN
			stamm_lauf = 'DE' AND
			stamm_mainprod_header = 1
		THEN
			'DE'
		ELSE
			'DWN'
	END = stamm_lauf AND
	stamm_eancid not in (
		'D28678793',
		'D23786869',
		'D16339882',
		'D25990550',
		'D25439555',
		'D25429311',
		'D30826691'
	)
order by
	index_ ";
	
$arr = array();
$data = array();
$ct = 0;

$rs=$db->query($sql);

if (MDB2::isError($rs)) {
    dbug('error', $sql);
    $is_error = true;
    $mail_body .= "SQL error!!\n";
} else {
	while($arr= $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if ($arr) {                
                //convert everything from UTF8 to ISO for backward compatibility
                #$arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data[$ct]['day']           = $zeit;
                $data[$ct]['isrc']         	= (isset($arr['stamm_eanc']) and strlen($arr['stamm_eanc']) > 0) ? $arr['stamm_eanc'] : '';
		$data[$ct]['artist']     	= (isset($arr['sinte']) and strlen($arr['sinte']) > 0) ? $arr['sinte'] : '';
                $data[$ct]['title']         = (isset($arr['stitl']) and strlen($arr['stitl']) > 0) ? $arr['stitl'] : '';
                $data[$ct]['distributor'] 	= (isset($arr['herst_txt']) and strlen($arr['herst_txt']) > 0) ? $arr['herst_txt'] : '';
		$data[$ct]['distributor_code'] = $arr['stamm_firm_code'];
                $data[$ct]['label'] 		= $arr['slabl'];
                $data[$ct]['div'] 			= $arr['stamm_herst_txt2'];
                $data[$ct]['genre']			= $arr['stamm_wg_txt'];
                $data[$ct]['vdatum']		= $arr['vdatum'];

                $data[$ct]['phys_units'] 	= $arr['phys_meng'];
                $data[$ct]['phys_units_sr'] = $arr['phys_voe_meng'];
                $data[$ct]['dwn_units'] 	= $arr['dwn_meng'];
                $data[$ct]['dwn_units_sr']  = $arr['dwn_voe_meng'];
                $data[$ct]['stream_units'] 	= $arr['prod_stream_meng'];
                $data[$ct]['stream_units_sr']  = $arr['stream_prod_voe_meng'];
                $data[$ct]['pre_stream_units'] 	= $arr['pre_stream_meng'];
                $data[$ct]['pre_stream_units_sr']  = $arr['pre_stream_voe_meng'];
                $data[$ct]['free_stream_units'] 	= $arr['free_stream_meng'];
                $data[$ct]['free_stream_units_sr']  = $arr['free_stream_voe_meng'];
                
            }
            $ct++;
        }
        $rs->free();
}
//write to file
$file_name = date('Y-m-d-H-i-s');
if(($file = fopen($nfs_root_dir . 'stream/'.$file_name.'.txt', 'w')) === FALSE) {
    $is_error = true;
    $mail_body .= "can not write data to file\n";
} else {
	//write first line
	$line = "DAY\tEAN/ISRC\tARTIST\tTITLE\tDISTRIBUTOR\tDIST CODE\tLABEL\tDIV\tREPERTOIRE\tRELEASE DATE\tPHYS UNITS\tPHYS UNITS SR\tDWL UNITS\tDWL UNITS SR\t ALL STR\tALL STR SR\tPREM STR\tPREM STR SR\tADF STR\tADF STR SR\n";
		fwrite($file, $line);
	foreach ($data as $c=>$dt) {
		$line = $dt['day']."\t".$dt['isrc']."\t".$dt['artist']."\t".$dt['title']."\t".$dt['distributor']."\t".$dt['distributor_code']."\t".$dt['label']."\t".$dt['div']."\t".$dt['genre']."\t".$dt['vdatum']."\t".
				$dt['phys_units']."\t".$dt['phys_units_sr']."\t".$dt['dwn_units']."\t".$dt['dwn_units_sr']."\t".
				$dt['stream_units']."\t".$dt['stream_units_sr']."\t".$dt['pre_stream_units']."\t".$dt['pre_stream_units_sr']."\t".
				$dt['free_stream_units']."\t".$dt['free_stream_units_sr']."\n";
		fwrite($file, $line);
	}
	fclose($file);
}
sleep(5);
echo "FTP upload..\n";
$ftp = new FTP;
$ftp->connect('ftp.media.universal-music.de');
$ftp->login('datameersechs', 'RGE6fBUepGQ256zH');
if( ! $ftp->put('/' . $file_name . ".txt",
        $nfs_root_dir . 'stream/'.$file_name.'.txt', FTP_BINARY)){
        echo "FTP upload failed.\n";
        $is_error = true;
}

$ftp->close();
echo "Done.\n";
/*
echo "send Email...\n";
if($is_error){
    $par['empfaenger'] = $reports_error_recipients;
    $par['message'] = array('subject'=>"Error Universal Daten Daily Top all Stream DE",'body_txt'=>$mail_body);
    sendEMail($par);
}else{
	$mail_body = ' ';
    $par['empfaenger'] = $email_delivery;
    $par['message'] = array('subject'=>"Daten Daily Top all Stream DE (".$zeit.")",'body_txt'=>$mail_body);
    sendEMail($par);
}
*/
    
}

?>