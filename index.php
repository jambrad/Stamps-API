<?php
define('PATH_ROOT',__DIR__.'/');
define('PATH_MODELS', PATH_ROOT.'Models');
require_once PATH_ROOT.'StampService.php';

$service = new StampService();
$test_address = new Address(
    'Brendan',
    'Eblin',
    '2672 PINEY GROVE RD',
    'BLACKSHEAR',
    'GA',
    '31516',
    '9125485777',
    "test@gmail.com"
);

$res = $service->cleanseAddress($test_address);
//print_r('Cleanse status: ' . $res['status']);
$test_address = $res['address'];

$rate = $service->getRates($test_address->ZIPCode,10,"US-PM","Flat Rate Box");
//var_dump($rate);

$lbl = $service->generateShippingLabel($test_address, $rate);

var_dump($lbl);

$service->cancelShippingLabel($lbl["StampsTxID"]);
// var_dump($service->getRates('11762',10,"US-PM","Flat Rate Box"));
// var_dump($service->getRates('11762',10));
// var_dump($service->getRates('11762',10,NULL,NULL,20,20,20));
// var_dump($service->getRates('11762',10,NULL,NULL,20,20,20, date('yy-m-d')));
die();
?>