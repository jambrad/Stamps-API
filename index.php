<?php
define('PATH_ROOT',__DIR__.'/');
define('PATH_MODELS', PATH_ROOT.'Models');
require_once PATH_ROOT.'StampService.php';

// Initialize service
$service = new StampService();

// Customer Address / From Address
$test_address = new Address(
    'Brendan',
    'Eblin',
    '2672 PINEY GROVE ROAD',
    'BLACKSHEAR',
    'GA',
    '31516',
    '9125485777',
    "test@gmail.com"
);

// Cleanse the Address
$res = $service->cleanseAddress($test_address);
// Update address with cleansed
$test_address = $res['address'];

// Get Rates and select a Rate
// Will get all possible package rates where serviceType is Priority Mail (US-PM)
$rate = $service->getRates($test_address->ZIPCode,5, "US-PM")[4];

// Will get all possible servicetype rates where packagetype is Flat Rate Box
// $service->getRates($test_address->ZIPCode,5, NULL, "Flat Rate Box");

// Will get new all possible combination of rates of all service types and package types.
// $service->getRates($test_address->ZIPCode,5);

// Purchase postage of exact amount
$service->purchasePostage($rate->Amount);

// Generate shipping 
$res = $service->generateShippingLabel($test_address, $rate);

var_dump($res);

die();
?>