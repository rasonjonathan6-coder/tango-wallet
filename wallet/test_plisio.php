<?php

require_once "plisio_config.php";

$url = PLISIO_API_URL . "/operations?api_key=" . PLISIO_SECRET_KEY;


$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);

curl_close($ch);


echo "<pre>";
print_r(json_decode($response,true));
echo "</pre>";

?>