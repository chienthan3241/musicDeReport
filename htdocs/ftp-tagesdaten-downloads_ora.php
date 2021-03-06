<?php

require_once(dirname(__FILE__).'/../lib/global.php');
require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');
require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

//Abbruch durch Benutzer ignorieren
#ignore_user_abort(true);

$uni     = array(8001);
$emi     = array(8003,8025,8029,8065);
$sonybmg = array(8002);
$warner  = array(8005);
$groove  = array(8009,8019,8135);

$is_error = false;

$dsn = $exasol_dsn;

$db = MDB2::connect($dsn);

if (MDB2::isError($db)) {
    $par['empfaenger'] = $reports_error_recipients;
    $par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads (TAGESDATEN) ",
       'body_txt'=>"Bei Generierung FTP-Daten (TAGESDATEN) Fehler aufgetreten:\n can not connect to database.");

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

function update_last_update_zeitkey($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}


$lastupdatefile = $nfs_root_dir . '/tagesdata_last_update.txt';

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
               WHERE     zeit_typeflag = 'D'
                     AND zeit_einheit = 'T'
                     AND zeit_landid = '1054'
                     AND zeit_appid = 12
            ORDER BY zeit_key DESC) where rownum <= 5 ";
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

#$zeit_keys = array('20150410','20150411','20150412');

foreach($zeit_keys as $zeit){

    echo "generating tagesdata for $zeit ...\n";

    $jahr4 = substr($zeit,0,4);
    $monate2 = substr($zeit,-4,2);
    $tag2 = substr($zeit,-2,2);

    $udata = array();

    $sql = "
      SELECT   s1.*,
               stamm_asnr AS seanc,
               stamm_eancid AS seanc_id,
               sprnr,
               stamm_ag_txt AS tart,
               stamm_agid AS tartid,
               stamm_firm_code AS firmid,
               stamm_artnr AS satnr,
               stamm_titel AS stitl,
               stamm_artist AS sinte,
               stamm_label_txt AS slabl,
               stamm_herst_txt AS vertrieb_lang,
               stamm_herst_txt2 AS vertrieb,
               stamm_archivnr AS archivnr
        FROM   (SELECT   *
                  FROM   (SELECT   ROWNUM AS index_, daten.*
                            FROM   (  SELECT /*+ REWRITE */
                                               stamm_prodnr AS sprnr,
                                               SUM (bwg_wert) AS sum_wert,
                                               SUM (bwg_menge) AS sum_meng,
                                               SUM (bwg_wert_abs) AS sum_wert_abs,
                                               SUM (bwg_menge_abs) AS sum_meng_abs
                                        FROM   bewegung_T, region, stamm_gesamt
                                       WHERE       bwg_zeitkey = $zeit
                                               AND stamm_landid = 1054
                                               AND bwg_landid = 1054
                                               AND region_landid = 1054
                                               AND bwg_appid = 12
                                               AND stamm_appid = 12
                                               AND region_appid = 12
                                               AND bwg_haendlerid = region_haendlerid
                                               AND stamm_eancid = bwg_eancid
                                               AND stamm_type_flag = 'D'
                                               AND stamm_lauf = 'DWN'
                             AND BWG_CONT_DIST IN ('641','645')
                                               AND NVL(stamm_quelle, 'EMPTY') <> 'MA'
                                               AND (region_awid IN
                                                   (SELECT region_awid
                                                      FROM region
                                                     WHERE region_awid NOT IN
                                                              (240, 243, 2)
                                                           AND region_appid = 12
                                                           AND region_landid = 1054))
                                    GROUP BY   stamm_prodnr
                                    ORDER BY   SUM (bwg_menge) DESC) daten)) s1,
               stamm_gesamt
       WHERE       s1.sprnr = SUBSTR (stamm_gesamt.stamm_eancid, 2)
               AND stamm_type_flag = 'D'
               AND stamm_lauf = 'DWN'
               AND stamm_landid = 1054
               AND stamm_is_header = 1
    GROUP BY   sprnr,
               stamm_ag_txt,
               stamm_agid,
               stamm_firm_code,
               stamm_eancid,
               stamm_titel,
               stamm_asnr,
               stamm_artnr,
               stamm_artist,
               sum_wert,
               sum_meng,
               sum_wert_abs,
               sum_meng_abs,
               stamm_label_txt,
               stamm_herst_txt,
               stamm_herst_txt2,
               stamm_archivnr,
               index_
    ORDER BY   index_ ASC ";

    //echo $sql."\n";

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
                if(isset($udata[$arr['vertrieb']])) {
                        $firma = $udata[$arr['vertrieb']];
                } else {
                        $firma = '';
                }
                //convert everything from UTF8 to ISO for backward compatibility
                $arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data[$ct]['ISRC']          = (isset($arr['satnr']) and strlen($arr['satnr']) > 0) ? $arr['satnr'] : '999999999999';
                $data[$ct]['Titel']         = (isset($arr['stitl']) and strlen($arr['stitl']) > 0) ? $arr['stitl'] : '          ';
                $data[$ct]['Interpret']     = (isset($arr['sinte']) and strlen($arr['sinte']) > 0) ? $arr['sinte'] : '          ';
                $data[$ct]['Menge'] = $arr['sum_meng'];
                $data[$ct]['Firma'] = $firma;
                $data[$ct]['Label'] = $arr['slabl'];
                $data[$ct]['Vertrieb'] = $arr['vertrieb'];
                $data[$ct]['FirmID'] = $arr['firmid'];
                $data[$ct]['Archivnr'] = trim($arr['archivnr']);
                $data[$ct]['Umsatz'] = $arr['sum_wert'];
                $data[$ct]['Tart'] = trim($arr['tart']);
                $data[$ct]['TartID'] = $arr['tartid'];
            }
            $ct++;
        }
        $rs->free();
    }

    echo "Schreibe SonyBMG-File...";

    if(($dlsony = fopen($nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.APA', 'w')) === FALSE) {
        $is_error = true;
    } else {
        $artschl = '000000_____________';
        $ean = '             ';
        $titinte = '                              ';
        foreach ($data as $c=>$dt) {
            fwrite($dlsony,substr_replace($artschl,$dt['ISRC'],0-strlen($dt['ISRC'])));
            fwrite($dlsony,'EAN            '); //ean
            fwrite($dlsony,'EIN');             //ein
            if(!isset($dt['FirmID']) or $dt['FirmID'] == 0 or $dt['FirmID'] == '') {            //phnr
                fwrite($dlsony,'0000         ');
            } else {
                fwrite($dlsony, $dt['FirmID'] . '         ');
            }
            fwrite($dlsony,substr_replace($ean,$dt['ISRC'],0,strlen($dt['ISRC']))); //ISRC
            fwrite($dlsony,substr_replace($titinte,substr($dt['Interpret'],0,30),0-strlen(substr($dt['Interpret'],0,30))));
            fwrite($dlsony,substr_replace($titinte,substr($dt['Titel'],0,30),0-strlen(substr($dt['Titel'],0,30))));
            fwrite($dlsony,'            '); //phononet artikel nummer
            #fwrite($dlsony,'     ');       //tontraegeart nr
            fwrite($dlsony,substr_replace('     ',substr($dt['TartID'],15),0,strlen(substr($dt['TartID'], 15))));   //tontraegeart nr
            #fwrite($dlsony,$titinte);      //tontraegerart bezeichnung
            fwrite($dlsony,substr_replace($titinte,substr($dt['Tart'],0,30),0-strlen(substr($dt['Tart'],0,30))));
            fwrite($dlsony,$titinte);
            fwrite($dlsony,$titinte);
            if(strlen($dt['Label']) == 0) {
                fwrite($dlsony, '          ');
            } else {
                fwrite($dlsony,substr_replace('          ',substr($dt['Label'],0,10),0-strlen(substr($dt['Label'],0,10))));
            }
            fwrite($dlsony,'   ');
            fwrite($dlsony,'     ');
            fwrite($dlsony,'       ');
            fwrite($dlsony,'      ');
            fwrite($dlsony,'  ');
            #   fwrite($dlsony,"     \n");
            fwrite($dlsony,substr_replace('     ',$dt['Archivnr'],0-strlen($dt['Archivnr']))."\n");
        }
        fclose($dlsony);
    }

    if(($dlsony = fopen($nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.DAT', 'w')) === FALSE) {
        $is_error = true;
    } else {
        $mengwert = '000000000000000';
        foreach ($data as $c=>$dt) {
            fwrite($dlsony,substr($zeit, 2).'_'.substr($zeit, 2));
            fwrite($dlsony,'00000001');
            fwrite($dlsony,substr_replace($artschl,$dt['ISRC'],0-strlen($dt['ISRC'])));
            fwrite($dlsony,substr_replace($mengwert, number_format($dt['Menge'],0,".",""),0-strlen(number_format($dt['Menge'],0,".",""))));

            if(in_array($dt['FirmID'], $sonybmg) or $zeit >= "20110901") {
                fwrite($dlsony, substr_replace($mengwert, number_format($dt['Umsatz'],2,".",""),0-strlen(number_format($dt['Umsatz'],2,".",""))));
            } else {
                fwrite($dlsony, substr_replace($mengwert, number_format(0,2,".",""),0-strlen(number_format(0,2,".",""))));
            }
            fwrite($dlsony,$mengwert);
            fwrite($dlsony,$mengwert);
            fwrite($dlsony,$mengwert."\n");
        }
        fclose($dlsony);
    }
    echo "fertig.\n";

    echo "Creating Tar...\n";
    #"tar -C t/ DLBM10KW41.DAT DLBM10KW41.APA -cf t/DLBM10KW41.TAR";
    $script = "tar -C $nfs_root_dir" . "temp/ DLBM" .$jahr4.$monate2.$tag2. '.DAT'
                . " " . 'DLBM' .$jahr4.$monate2.$tag2. '.APA'
                . " -cf " . "$nfs_root_dir" . "temp/DLBM" .$jahr4.$monate2.$tag2. '.TAR';
    $exec_output = array();
    exec($script, $exec_output);
    echo "Zip Sony-File...\n";
    $is_error = !create_zip(
                array(
                    array(
                        "abs_path" => $nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.TAR',
                        "rel_path" => 'DLBM'.$jahr4.$monate2.$tag2.'.TAR'
                    )
                ),
                $nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.ZIP',
                true
            );

    echo "fertig.\n";


/*
    echo "Schreibe Groove-File...";
    if(($dlgroove = fopen($nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.TXT', 'w')) === FALSE){
        $is_error = true;
    } else {
        foreach ($data as $c=>$dt) {
            if(in_array($dt['FirmID'], $groove)) {
                fwrite($dlgroove, date('YW', mktime(0, 0, 0, (int)$monate2, (int)$tag2, $jahr4)).';'); #week
                fwrite($dlgroove, $jahr4.$monate2.$tag2.';');                      #day
                fwrite($dlgroove, $jahr4.$monate2.$tag2.';');                      #day
                fwrite($dlgroove, $dt['ISRC'].';');                                #ISRC
                fwrite($dlgroove, '"'.$dt['Interpret'].'";');                      #Artist
                fwrite($dlgroove, '"'.$dt['Titel'].'";');                          #Title
                fwrite($dlgroove, ';');                                            #Artikel nr.
                fwrite($dlgroove, $dt['TartID'].';');                              #Tart Code
                fwrite($dlgroove, number_format($dt['Menge'],0,",","").';');       #Menge
                fwrite($dlgroove, $dt['FirmID'].';');                              #Phononet Nummer
                fwrite($dlgroove, ';');                                            #Artikel nr.
                fwrite($dlgroove, number_format(($dt['Menge'] == 0 ? 0 : ($dt['Umsatz']/$dt['Menge'])),2,",","")); #Final Price
                fwrite($dlgroove,"\n");
            } //Groove Attack has no competitor data.
        }

        fclose($dlgroove);
        echo "fertig.\n";
    }
    echo "Zip Groove-File...\n";
    $is_error = !create_zip(
                array(
                    array(
                        "abs_path" => $nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.TXT',
                        "rel_path" => 'DLGR'.$jahr4.$monate2.$tag2.'.TXT'
                    )
                ),
                $nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.ZIP',
                true
            );

    echo "fertig.\n";
*/
    echo "Upload die Dateien auf die entsprechenden FTP-Laufwerke...";
    $ftp = new FTP;
    $ftp->connect($ftp_host);
    $ftp->login($ftp_username, $ftp_password);

    //Groove
//    if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr2 . 'KW' . $woche . ".ZIP",
//            $nfs_root_dir . 'temp/DLGR' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){

    //Groove Attack
    /*if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr4 . $monate2 . $tag2 . '.ZIP',
            $nfs_root_dir  .  'temp/DLGR' . $jahr4 . $monate2 . $tag2 . '.ZIP', FTP_BINARY)) {
        $is_error = true;
        echo "failed to upload Groove Attack file.\n";
    }

    if( ! rmdirr($nfs_root_dir  .  'temp/DLGR' . $jahr4 . $monate2 . $tag2 . '.TXT')){
        $is_error = true;
        echo "failed to delete DLGR" . $jahr4 . $monate2 . $tag2 . ".TXT\n";
    }
*/
    //SonyBMG
    if( ! $ftp->put($target_path_de . '/BMG/DLBM' . $jahr4 . $monate2 . $tag2 . '.ZIP',
            $nfs_root_dir  .  'temp/DLBM' . $jahr4 . $monate2 . $tag2 . '.ZIP', FTP_BINARY)){
        $is_error = true;
        echo "failed to upload SonyBMG file.\n";
    }

    if (!rmdirr($nfs_root_dir  .  'temp/DLBM' . $jahr4 . $monate2 . $tag2 . '.TAR')){
        echo "failed to delete DLBM" . $jahr4 . $monate2 . $tag2 . ".TAR\n";
        $is_error = true;
    }
    $ftp->close();

    echo "done.\n";

    echo "Generierte Dateien in Archiv verschieben...";
    if (!mkdir($nfs_root_dir . 'temp/'.$zeit) and !(rmdirr($nfs_root_dir . 'temp/'.$zeit) and mkdir($nfs_root_dir . 'temp/'.$zeit))) {
        echo "failed to create directory: ".$nfs_root_dir . 'temp/'.$zeit;
        $is_error = true;
    } else {
        //Groove Attack
        /*if (!rename($nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.ZIP',$nfs_root_dir . 'temp/'.$zeit.'/DLGR'.$jahr4.$monate2.$tag2.'.ZIP')) {
            echo "failed to archive DLGR".$jahr4.$monate2.$tag2.".ZIP\n";
            $is_error = true;
        }*/
        //SonyBMG
        if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.APA',$nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr4.$monate2.$tag2.'.APA')) {
            echo "failed to archive DLBM".$jahr4.$monate2.$tag2.".APA\n";
            $is_error = true;
        }
        if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.DAT',$nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr4.$monate2.$tag2.'.DAT')) {
            echo "failed to archive DLBM".$jahr4.$monate2.$tag2.".DAT\n";
            $is_error = true;
        }
        if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr4.$monate2.$tag2.'.ZIP',$nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr4.$monate2.$tag2.'.ZIP')) {
            echo "failed to archive DLBM".$jahr4.$monate2.$tag2.".ZIP\n";
            $is_error = true;
        }
    }
    echo "fertig.\n";

    if(!update_last_update_zeitkey($lastupdatefile, $zeit)){
        echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
    }

    if($is_error == true) {
        $par['empfaenger'] = $reports_error_recipients;
        $par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads",'body_txt'=>"Bei Generierung FTP-Daten (TAGESDATEN, $jahr4.$monate2.$tag2 ) Fehler aufgetreten\n");
    } else {
        $par['empfaenger'] = $reports_normal_recipients;
        $par['message'] = array('subject'=>"(Exasol) Erfolg FTP-Daten Downloads",'body_txt'=>"FTP-Daten (TAGESDATEN, $jahr4.$monate2.$tag2 ) generiert und verteilt.\n");
    }
    sendEMail($par);
}
?>
