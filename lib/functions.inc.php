<?php
/**
* func.lib.php
* Funktionen f�r die Formulare auf den Detailseiten (z.B Zeitverlauf)
* autor: www.christian-sattler.de
* 20030701
*/
function formatdateDDMMYYYY($YYYYMMDD) {
    if($YYYYMMDD == ''){
        return 'unknown';
    }
	return substr($YYYYMMDD, 6, 2).'.'.substr($YYYYMMDD, 4, 2).'.'.substr($YYYYMMDD, 0, 4);
}
function oracle_split($string) {
	if(strlen($string)<2) {
		return "TEXT_ZU_KURZ";
	}
	elseif (strlen($string)>=2) {
		if ((!strchr($string,'{') and !strchr($string,'}'))and strlen($string) < 5) {
			$string = str_replace("%", "", $string);
		}
		elseif ((strchr($string,'{') and strchr($string,'}'))and strlen($string) < 7) {
			$string = str_replace("%", "", $string);
		}
		$split = explode(" ",$string);
	}
	else {
		$split = explode(" ",$string);
	}
	return $split;
}
function oracle_escape($string) {
	$string = trim($string);
	$string = preg_replace('/\s\s+/', ' ', $string);	
	$string = str_replace("'","''",$string);
	$string = preg_replace('/\bNOT\b/', "{NOT}", $string);
	$string = preg_replace('/\bWITHIN\b/', "{WITHIN}", $string);
	$string = preg_replace('/\bNEAR\b/', "{NEAR}", $string);
	$string = preg_replace('/\bAND\b/', "{AND}", $string);
	$string = preg_replace('/\bOR\b/', "{OR}", $string);
	$string = preg_replace('/\bABOUT\b/', "{ABOUT}", $string);
	$string = preg_replace('/\bSYN\b/', "{SYN}", $string);
	$string = str_replace('-', "{-}", $string);
	$string = str_replace('�', "AE", $string);
	$string = str_replace('�', "OE", $string);
	$string = str_replace('�', "UE", $string);
	$string = str_replace('*', "%", $string);
	$string = str_replace(',', "", $string);
	$string = str_replace('&', ' {AND} ', $string);
	$string = str_replace('(', '', $string);
	$string = str_replace(')', '', $string);
	$string = str_replace('!', '', $string);
	$string = str_replace('/\bMINUS\b/', "{MINUS}", $string);
	$string = str_replace('?', '', $string);
	
	
	return $string;
}

function generateQueryString($not_insert = array()){
	$query_string = '?';

	foreach($_POST as $key=>$value){
		foreach($not_insert as $search){
			if(strcmp($key, $search)){
				$query_string .= $key.'='.$value.'&';
			}
		}
  	}

  	$query_string = substr($query_string, 0, strlen($query_string) - 1);

  	if(strlen($query_string) == 0){
  		$query_string = '?';
	  	}

	  	foreach($_POST as $key=>$value){
	  		foreach($not_insert as $search){
	  			if(strcmp($key, $search)){
	  				$query_string .= $key.'='.$value.'&';
	  			}
	  		}
	  	}

	  	$query_string = substr($query_string, 0, strlen($query_string) - 1);
			
	  	return $query_string;
	}
	
	function getIvalParm($parm, $o_ival){
		foreach($o_ival as $value => $option){
			if($value == $parm){
				return $option;
			}
		}
	}
	
	function getLastDate($value, $date_parms, $ival, $start_date){
	
		$value = $value - 1;
		$date_index = -1;
		$date = $ival.$start_date;
		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;

			if($key == $date){
				$date_index = $i;
			}

			$i++;
		}

		if(!isset($dates[$date_index - $value])){
			return $dates[0];
		}
		else{
			return $dates[$date_index - $value];
		}
	}
	
	function getMaxDate($date_parms){

		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;
			$i++;
		}
		return $dates[0];
	}

	function getMinDate($date_parms){
	
		$count = count($date_parms);
		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;
			$i++;
		}
		return $dates[$count - 1];
	}
	
	function getLessDate($now_date, $date_parms, $count_less){

		$key_index = 0;
		$ival = $_SESSION['ival'];
		$search_date = $ival.$now_date;
		$dates = array();
		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;

			if(!strcmp($key, $search_date)){
				$key_index = $i;
			}
			$i++;
		}
		if(($key_index + $count_less) > count($dates) - 1){
			$return_index = count($dates) - 1;
		}
		else{
			$return_index = $key_index + $count_less;
		}
		return $dates[$return_index];
	}
	
	function getHigherDate($now_date, $date_parms, $count_high){

		$key_index = 0;		
		$ival = $_SESSION['ival'];
		$search_date = $ival.$now_date;
		$dates = array();
		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;

			if(!strcmp($key, $search_date)){
				$key_index = $i;
			}
			$i++;
		}

		if($key_index < $count_high) {
			return $dates[$count_high];
		}		
		else {
			return $dates[$key_index - $count_high];
		}
	}
	
	function compareDate($date1, $date2){
		
		$ival = $_SESSION['ival'];
		$date1 = replace_dat($date1, $ival);
		$date2 = replace_dat($date2, $ival);

		if(!strcmp($date1, $date2)){
			return true;
		}
		else{
			return false;
		}
	}
	
	function replace_dat($date, $ival){

		$date = str_replace($ival, '', $date);
		
		return $date;
	}
	
	function getDatePos($search, $date_parms){
	
		$i = 0;

		foreach($date_parms as $key => $parm){
			$dates[$i] = $key;

			if(!strcmp($key, $search)){
				$key_index = $i;
			}
			$i++;
		}
		
		return $key_index;
	}
	
	function fillData($datf, $datt, $date_parms, $auswert_max){

		$data = array();

		for($i = 0; $i < $auswert_max; $i++){
			$data[$i]['date'] = replace_dat(getHigherDate($datf, $date_parms, $i), $_SESSION['ival']);
			$data[$i]['sum_meng'] = '0.00';
			$data[$i]['sum_wert'] = '0.00';
		}
		
		return $data;
	}
	
	function fillCVData($datf, $datt, $date_parms, $auswert_max){

		$data = array();

		for($i = 0; $i < $auswert_max; $i++){
			$data[$i]['date'] = replace_dat(getHigherDate($datf, $date_parms, $i), $_SESSION['ival']);
			$data[$i]['sum_meng_phys'] = '0.00';
			$data[$i]['sum_meng_dl'] = '0.00';
			$data[$i]['sum_meng_ap'] = '0.00';
		}
		
		return $data;
	}

	function fillPData($datf, $datt, $date_parms, $auswert_max){

		$data = array();

		for($i = 0; $i < $auswert_max; $i++){
			$data[$i]['date'] = replace_dat(getHigherDate($datf, $date_parms, $i), $_SESSION['ival']);
			$data[$i]['sum_meng_phys'] = '0.00';
			$data[$i]['sum_wert_phys'] = '0.00';
			$data[$i]['sum_meng_dl'] = '0.00';
			$data[$i]['sum_wert_dl'] = '0.00';
		}
		
		return $data;
	}

	function mergeData($array1, $array2){
		
		$merged = array();
		
		if(!is_array($array2) || count($array2) == 0){
			$merged = $array1;
		}
		else{
			$i = 0;

			foreach($array1 as $row1){
				foreach($array2 as $row2){
					if($row1['date'] == $row2['date']){
						$merged[$i] = $row2;
						break;
					}
					else{
						$merged[$i] = $row1;
					}
				}
				$i++;
			}
		}
		
		return $merged;
	}
	
	function getDateDifference($datf, $datt, $date_parms){

		$key_index1 = 0;		
		$key_index2 = 0;
		$i = 0;
		$differ = 0;

		foreach($date_parms as $key => $parm){
			if(!strcmp($key, $datf)){
				$key_index1 = $i;
			}
			if(!strcmp($key, $datt)){
				$key_index2 = $i;
			}
			$i++;
		}

		if($key_index1 < $key_index2) {
			$differ = $key_index2 - $key_index1;
		}
		else {
			$differ = $key_index1 - $key_index2;
		}
		
		return $differ;
	}
	
	function dynImage($base_img, $text, $startx, $starty, $font = 'times_new_roman.ttf', $font_size = 10, $url = 'create_button.php', 
						$font_path = '/usr/local/httpd/include/fonts/', $font_angle = 0, $out_format = 'png', $red = 0, $green = 0, $blue = 0){
		
		$src = $url;
		$src .= '?base_image='.base64_encode($base_img).'&';
		$src .= 'text='._tl(base64_encode($text)).'&';
		$src .= 'font='.base64_encode($font).'&';
		$src .= 'font_path='.base64_encode($font_path).'&';
		$src .= 'font_size='.base64_encode($font_size).'&';
		$src .= 'font_angle='.base64_encode($font_angle).'&';
		$src .= 'out_format='.base64_encode($out_format).'&';
		$src .= 'startx='.base64_encode($startx).'&';
		$src .= 'starty='.base64_encode($starty).'&';
		$src .= 'red='.base64_encode($red).'&';
		$src .= 'green='.base64_encode($green).'&';
		$src .= 'blue='.base64_encode($blue);

		//echo $src;
		return $src;
	}

function sendEMail($par,$file = false) {
	
		
		$recipients  	= $par['empfaenger'];		
		$message_array	= $par['message'];
				
		$from			= 'Develop.Entertainment@gfk.com';
		$backend 		= 'smtp';
		
		$subject		= $message_array['subject'];
		$body_txt		= $message_array['body_txt'];
			
		$crlf 			= "\n";
			
		$params 		= array(
						'host' 			=> '10.149.43.10',
						'port' 			=> 25,
						'auth' 			=> false,
						'username' 		=> false,
						'password' 		=> false,
						'localhost'		=> 'localhost',
						'timeout' 		=> null,
						#'verp' 			=> false,
						'debug' 		=> false
		);		
	    
	    foreach ($recipients as $recipient) {
	    	$headers 		= array(
			              	'From'    	=> $from,
			              	'To'    	=> $recipient,
			              	'Subject' 	=> $subject
		    );
		    
	    	$mime = new Mail_mime($crlf);
		
			$mime->setTXTBody($body_txt);
			if (is_file($file)) {
				$ctype = MIME_Type::autoDetect($file);
				$mime->addAttachment($file, $ctype);
			}
		
			$body = $mime->get();
			$hdrs = $mime->headers($headers);
			
			$mail =& Mail::factory($backend, $params);
			$mail->send($recipient, $hdrs, $body);
	    }		    		
}

?>