<?php

  // Интеграция CRM Clientbase с Яндекс.Телефонией
  // https://ClientbasePro.ru
  // https://api.yandex.mightycall.ru/api/doc/
  
require_once 'common.php'; 

  // функция проверяет доступность YT API, проверка без авторизации, возвращает bool
function YT_Ping() {
  $curl = curl_init(YT_URL.'/'.YT_PREFIX.'/'.YT_VERSION.'/auth/ping');
  curl_setopt($curl, CURLOPT_HEADER, 1);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_exec($curl);
  $answer = curl_getinfo($curl);
  curl_close($curl);
  return (200==$answer['http_code']) ? true : false;  
}

  // функция возвращает авторизационный токен пользователя $userId
function GetYTToken($userId='') {
    // начальная проверка входных данных
  if (!$userId || !($userId=intval($userId))) return false; 
    // получаем внутренний номер пользователя $userId
  $worker = sql_fetch_assoc(data_select_field(WORKERS_TABLE, 'id, f'.WORKERS_FIELD_INNER_PHONE.' AS extension', "f".WORKERS_FIELD_USER."='".$userId."' LIMIT 1"));
  if ($worker['id'] && $worker['extension']) $extension = $worker['extension'];  
  else return false;
    // сначала пробуем получить токен из таблицы YT tokens
  $row = sql_fetch_assoc(data_select_field(YTTOKENS_TABLE, 'f'.YTTOKENS_FIELD_TOKEN.' AS token', "status=0 AND f".YTTOKENS_FIELD_TOKEN."!='' AND f".YTTOKENS_FIELD_DATE.">'".date('Y-m-d H:i:s')."' AND f".YTTOKENS_FIELD_USER."='".$userId."' ORDER BY f".YTTOKENS_FIELD_DATE." DESC LIMIT 1"));
  if ($token=$row['token']) {
      // дополнительно проверяем авторизацию по нему методом /profile/$extension
    $curl = curl_init(YT_URL.'/'.YT_PREFIX.'/'.YT_VERSION.'/profile/'.$extension);
    curl_setopt_array($curl, array(
      CURLOPT_HTTPHEADER => array('Authorization: bearer '.$token, 'Content-Type: application/json',  'x-api-key: '.YT_API_KEY),
      CURLOPT_RETURNTRANSFER => true
    ));
    if ($response=curl_exec($curl)) {
      $answer = json_decode($response);
      if ($answer->isSuccess) return $token;
    }
    curl_close($curl);
  }
    // если в БД его нет или токен из таблицы не прошёл авторизацию, то запрашиваем у Я.Телефонии снова
  $curl = curl_init(YT_URL.'/'.YT_PREFIX.'/'.YT_VERSION.'/auth/token');
  curl_setopt_array($curl, array(
    CURLOPT_HTTPHEADER => array('Content-type: application/x-www-form-urlencoded', 'x-api-key: '.YT_API_KEY),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id='.YT_API_KEY.'&client_secret='.$extension
  ));
        // получаем сам токен
  if ($response=curl_exec($curl)) {
    $answer = json_decode($response);
    if ($answer->access_token && 'bearer'==$answer->token_type) $token = $answer->access_token;
  }
  curl_close($curl);
  if ($token) {
    data_insert(YTTOKENS_TABLE, EVENTS_ENABLE, array('f'.YTTOKENS_FIELD_TOKEN=>$token, 'f'.YTTOKENS_FIELD_DATE=>date("Y-m-d H:i:s",time()+$answer->expires_in), 'f'.YTTOKENS_FIELD_EXTENSION=>$extension, 'f'.YTTOKENS_FIELD_USER=>$userId)); 
    return $token;
  }
  return false;
}

    // функция инициирует исходящий звонок от пользователя $userId на номер $to с исходящим $through
function YTCallback($userId='', $to='', $through='') {
    // проверка наличия входных данных
  if (!$userId || !($userId=intval($userId)) || !$to || !($to=SetNumber($to))) return false;
  if (!$through) $through = YT_DEFAULT_BUSINESSNUMBER;
    // получаем токен на пользователя и инициируем метод calls
  if ($token=GetYTToken($userId)) {
    $curl = curl_init(YT_URL.'/'.YT_PREFIX.'/'.YT_VERSION.'/calls/makecall');
	curl_setopt_array($curl, array(
	  CURLOPT_HTTPHEADER => array('Authorization: bearer '.$token, 'Content-Type: application/json', 'x-api-key: '.YT_API_KEY),
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_POST => 1,
	  CURLOPT_POSTFIELDS => '{"from":"'.$through.'","to":"'.$to.'"}'
	));
	  // делаем попытку позвонить
    if ($response=curl_exec($curl)) { if ($answer=json_decode($response)) { curl_close($curl); return $answer->data->id; }}                       
    curl_close($curl);
    return false;
  }
  return false;
}

  // функция получает статистику звонков по токену $token с параметрами $params (https://api.yandex.mightycall.ru/api/doc/#%D0%BC%D0%B5%D1%82%D0%BE%D0%B4%D1%8B-rest-api-calls)
  // для запроса инфо по id: $params = '/id'
  // для запроса статистики: $params = '?param1=value1&param2=value2'
function GetYTCalls($token='', $params='') {
    // проверка наличия входных данных
  if (!$token) return false;
    // готовим запрос
  $curl = curl_init(YT_URL.'/'.YT_PREFIX.'/'.YT_VERSION.'/calls'.$params);
  curl_setopt_array($curl, array(
    CURLOPT_HTTPHEADER => array('Authorization: bearer '.$token, 'Content-Type: application/json', 'x-api-key: '.YT_API_KEY),
    CURLOPT_RETURNTRANSFER => true
  ));
    // выполняем запрос
  if ($response=curl_exec($curl)) { if ($answer=json_decode($response,true)) { curl_close($curl); return $answer; }}                       
  curl_close($curl);
  return false;
}

?>