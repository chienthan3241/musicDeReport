<?php
require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

//Abbruch durch Benutzer ignorieren
#ignore_user_abort(true);

$groove     = array(8009,8019,8135);

$is_error = false;

function report_groove_error(){
    if($is_error == true) {
        $par['empfaenger'] = $reports_error_recipients;
        $par['message'] = array('subject'=>"(Exasol) Error FTP-Daten (Monats) Downloads",'body_txt'=>"Bei Generierung FTP-Daten Fehler aufgetreten.\n");
    } else {
        $par['empfaenger'] = $reports_normal_recipients;
        $par['message'] = array('subject'=>"(Exasol) Erfolg FTP-Daten (Monats) Downloads",'body_txt'=>"FTP-Daten generiert und verteilt.\n");
    }
    sendEMail($par);
}

function get_last_update_groove_zeitkey_month($lastupdatefile){
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

function update_last_update_groove_zeitkey_month($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);

if (MDB2::isError($db)) {
  $par['empfaenger'] = $reports_error_recipients;
  $par['message'] = array('subject'=>"(Exasol) Error GROOVE Monthly FTP-Daten (Monats) Downloads",
        'body_txt'=>"Bei Generierung GROOVE Monthly FTP-Daten Fehler aufgetreten (Datenbankverbindung).\n");

  sendEMail($par);

  die ($db->getMessage());
}

$lastupdatefile = $nfs_root_dir . 'monatsdata_groove_last_update.txt';

echo "querying avalaible zeit_keys...\n";

$qry = "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'D' and zeit_einheit = 'M' and zeit_landid = 1054";
  /***************************
   * THE MONTH DATA FLATFILE MUST RUN AT THE FIRST SATURDAY OF EACH MONTH
   * and the date key must of course already in the database.
   *
   */
$rs = $db->query($qry);
$data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
$zeit = $data['max_zeit'];

if(substr($zeit,-2,2) != ( date('n') == '1' ? '13' : date('n') ) - 1){
  $error_msg =  "$zeit can not be generated in " . date('Ym') . ", aborting.\n";
  $is_error = true;
  echo $error_msg;
}else{
  echo "checking last updated zeit_key...\n";

  $last_time = get_last_update_groove_zeitkey_month($lastupdatefile);

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

  $jahr2 = substr($zeit,2,2);
  $jahr4 = substr($zeit,0,4);
  $monat = substr($zeit,-2,2);

  $uarr = array();
  $udata = array();

  $sql = "
    SELECT
  stamm_asnr AS seanc,
  stamm_eancid AS seanc_id,
  stamm_ag_txt AS tart,
  stamm_agid AS tartid,
  stamm_firm_code AS firmid,
  stamm_artnr AS satnr,
  stamm_titel AS stitl,
  stamm_artist AS sinte,
  stamm_label_txt AS slabl,
  stamm_herst_txt AS vertrieb_lang,
  stamm_herst_txt2 AS vertrieb,
  stamm_archivnr AS archivnr,
  SUM(bwg_wert) AS sum_wert,
  SUM(bwg_menge) AS sum_meng,
  SUM(bwg_wert_abs) AS sum_wert_abs,
  SUM(bwg_menge_abs) AS sum_meng_abs
FROM
  bewegung_m,
  region,
  stamm_gesamt
WHERE
  bwg_zeitkey = $zeit AND
  BWG_CONT_DIST IN('641', '645') AND
  stamm_landid = 1054 AND
  bwg_landid = 1054 AND
  region_landid = 1054 AND
  bwg_appid = 12 AND
  stamm_appid = 12 AND
  region_appid = 12 AND
  bwg_haendlerid = region_haendlerid AND
  stamm_eancid = bwg_eancid AND
  stamm_type_flag = 'D' AND
  stamm_lauf = 'DWN' AND
  NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
  (
    region_awid IN(
      SELECT
        region_awid
      FROM
        region
      WHERE
        region_awid NOT IN(240, 243, 2) AND
        region_appid = 12 AND
        region_landid = 1054
    )
  )
GROUP BY
  stamm_asnr,
  stamm_eancid,
  stamm_ag_txt,
  stamm_agid,
  stamm_firm_code,
  stamm_artnr,
  stamm_titel,
  stamm_artist,
  stamm_label_txt,
  stamm_herst_txt,
  stamm_herst_txt2,
  stamm_archivnr
ORDER BY
  SUM(bwg_menge) DESC ";

  echo $sql."\n";

  $arr = array();
  $data = array();



  $rs=$db->query($sql);

  if (MDB2::isError($rs)) {
      dbug('error', $sql);
      $is_error = true;
  } else {      
      //open file Groove Attack
      if(($dlgroove = fopen($nfs_root_dir . 'temp/DLGR'.$jahr2.'M'.$monat.'.TXT', 'w')) === FALSE) {
          $is_error = true;
          report_error();
          die();
      }
      
      echo "Schreibe Files...";

      //Counter
      $ct = 0;
      while($arr = $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
          if ($arr) {
              $dt = array();
              if(isset($udata[$arr['vertrieb']])) {
                      $firma = $udata[$arr['vertrieb']];
              } else {
                      $firma = '';
              }
              $arr = array_utf_to_iso($arr);
              #ISRC could sometimes be null. in this case, simply fill it with 13 times 9
              $dt['ISRC']          = (isset($arr['satnr']) and strlen($arr['satnr']) > 0) ? $arr['satnr'] : '9999999999999';
              $dt['Titel']         = (isset($arr['stitl']) and strlen($arr['stitl']) > 0) ? $arr['stitl'] : '          ';
              $dt['Interpret']     = (isset($arr['sinte']) and strlen($arr['sinte']) > 0) ? $arr['sinte'] : '          ';
              $dt['Menge'] = $arr['sum_meng'];
              $dt['Firma'] = $firma;
              $dt['Label'] = $arr['slabl'];
              $dt['Vertrieb'] = $arr['vertrieb'];
              $dt['FirmID'] = $arr['firmid'];
              $dt['Archivnr'] = trim($arr['archivnr']);
              $dt['Umsatz'] = $arr['sum_wert'];
              $dt['Tart'] = trim($arr['tart']);
              $dt['TartID'] = $arr['tartid'];             

              /**********************************************/
              //GROOVE ATTACK:
              //Groove Attack has no competitor data
              if(in_array($dt['FirmID'], $groove)) {
                fwrite($dlgroove, $zeit.';');                                      #zeitkey
                fwrite($dlgroove, date('Ymd', monthfirstday($monat,$jahr4)).';');  #start date
                fwrite($dlgroove, date('Ymd', monthlastday($monat,$jahr4)).';');   #end date
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
              }
              /**********************************************/
          }
          $ct++;
      }
      fclose($dlgroove);
      $rs->free();
  }


  echo "fertig. $ct Datensatz wurden geschrieben.\n";

  echo "Zipping Groove-File...\n";
  $is_error = !create_zip(
              array(
                  array(
                      "abs_path" => $nfs_root_dir . 'temp/DLGR'.$jahr2.'M'.$monat.'.TXT',
                      "rel_path" => 'DLGR'.$jahr2.'M'.$monat.'.TXT'
                  )
              ),
              $nfs_root_dir . 'temp/DLGR'.$jahr2.'M'.$monat.'.ZIP',
              true
          );

  if($is_error){
      $error_msg .= 'can not zip file.\n';
  }
  echo "fertig.\n";

  echo "Upload der Dateien auf die entsprechenden FTP-Laufwerke...";

  $ftp = new FTP;
  $ftp->connect($ftp_host);
  $ftp->login($ftp_username, $ftp_password);
 
  //Groove
  if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr2 . 'M' . $monat . ".ZIP",
          $nfs_root_dir . 'temp/DLGR' . $jahr2 . 'M' . $monat . '.ZIP', FTP_BINARY)){
          echo "Groove upload failed.\n";
          $is_error = true;
  }

  $ftp->close();

  echo "fertig.\n";

  echo "Generierte Dateien in Archiv verschieben...";
  if (!mkdir($nfs_root_dir . 'temp/M'.$zeit) and !(rmdirr($nfs_root_dir . 'temp/M'.$zeit) and mkdir($nfs_root_dir . 'temp/M'.$zeit))) {
    echo "failed to create directory: ".$nfs_root_dir . 'temp/M'.$zeit;
    $is_error = true;
  } else {    
    //Groovy
    if (!rename($nfs_root_dir . 'temp/DLGR'.$jahr2.'M'.$monat.'.ZIP',$nfs_root_dir . 'temp/'.$zeit.'/DLGR'.$jahr2.'M'.$monat.'.ZIP')) {
      echo "failed to archive DLGR".$jahr2."M".$monat.".ZIP\n";
      $is_error = true;
    }
    if (!rename($nfs_root_dir . 'temp/DLGR'.$jahr2.'M'.$monat.'.TXT',$nfs_root_dir . 'temp/'.$zeit.'/DLGR'.$jahr2.'M'.$monat.'.TXT')) {
      echo "failed to archive DLGR".$jahr2."M".$monat.".TXT\n";
      $is_error = true;
    }
  }

  echo "Updating $lastupdatefile ...\n";
  if(!update_last_update_groove_zeitkey_month($lastupdatefile, $zeit)){
      echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
  }
  echo "fertig.\n";
}
?>
