<?php
require_once PATH_MODELS.'/Account.php';
require_once PATH_MODELS.'/Address.php';
require_once PATH_ROOT.'Util.php';

define('ADDRESS_UNSHIPPABLE', -1);
define('ADDRESS_MATCH', 1);
define('ADDRESS_CITYSTATEZIPOK', 2);


/**
 * Stamps API service
 */
class StampService {

    /**
     * URL for Stamps API
     */
    private const URL = "https://swsim.testing.stamps.com/swsim/swsimv90.asmx?wsdl";

    /**
     * Soap client for Stamps.com API
     *
     * @var [SoapClient]
     */
    private $soapClient;

    /**
     * Authenticator string to be used for api calls, Generated initially by authenticateUser(),
     * then gets updated each time an API request is made.
     * 
     * @var [string]
     */
    private $authenticator;

    /**
     * Kaival's address or where returns will go.
     */
    private $toAddress;

    /**
     * Random string to be generated for every instance of the service.
     * Used for Fault-tolerance mechanism for Stamp API.
     * 
     * @var [string]
     */
    private $integratorTxID;

    public function __construct($toAddress = NULL) {
        $this->soapClient = new SoapClient(StampService::URL);
        $this->authenticateUser();

        // provided a new ToAddress
        if(isset($toAddress)) {
            $res = $this->cleanseAddress($toAddress);
            if($res["status"] == ADDRESS_MATCH || $res["status"] == ADDRESS_CITYSTATEZIPOK) {
                $this->toAddress = $res["address"];
            } else {
                throw new InvalidArgumentException('Address passed is invalid');
            }
        } else {
            // continue default;
            $res = $this->cleanseAddress(new KaivalAddress());
            $this->toAddress = $res["address"];
        }

        $this->integratorTxID = md5(rand());

        // $this->purchasePostage(10.00);
    }
    
    /**
     * Authenticates the user and generates an authenticator string to be used for API calls.
     * This is called upon construction of the class.
     * 
     */
    private function authenticateUser(){
        if(isset($this->soapClient)) {
            try {
                // Create new Credentials Object
                $credentials = new Credentials();
                
                $response = $this->soapClient->AuthenticateUser(array("Credentials"=>(array) $credentials));
                if (isset($response->Authenticator)) {
                    // Save authenticator
                    $this->authenticator = $response->Authenticator;
                    _log(LOG_INFO, "Authentication Successful");
                    _log(LOG_INFO, $this->authenticator);
                } else {
                    _log(LOG_ERR, "Authentication Error. Authenticator not set.");
                }
                
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        }
    }

    /**
     * Gets the account information from Stamps.com
     * Used to get information like balance left, maximum postage balance, etc.
     *
     * @return GetAccountInfoResponse - [https://developer.stamps.com/developer/docs/swsimv90.html#getaccountinfo-object]
     */
    private function getAccountInfo() {
        if(isset($this->soapClient)) {
            try {
                if (!isset($this->authenticator)) {
                    throw new Exception("Authenticator not set!");
                }
                
                $response = $this->soapClient->GetAccountInfo(array('Authenticator'=>$this->authenticator));

                if (isset($response->AccountInfo)) {
                    _log(LOG_INFO, "GetAccountInfo Successful");
                    $this->authenticator = $response->Authenticator;
                    return $response;
                } else {
                    _log(LOG_ERR, "Get Account Info Error. AccountInfo not set.");
                    return NULL;
                }
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        }
    }

    /**
     * Purchase a specified amount for postage
     *
     * @param float $amount - Amount to purchase
     * @return bool - If purchase is successful or not.
     */
    public function purchasePostage(float $amount){
        if(isset($this->soapClient)) {
            try {
                if(!isset($amount)) {
                    throw new InvalidArgumentException('Amount is required');
                }

                // Get current account information
                $currentAccountInfo = $this->getAccountInfo();

                if(isset($currentAccountInfo)) {
                    // Get control total, required by purchase postage
                    $control_total = $currentAccountInfo->AccountInfo->PostageBalance->ControlTotal;

                    _log(LOG_INFO, "Purchase Postage: Current control total = " . $control_total);

                    $response = $this->soapClient->PurchasePostage(array("Authenticator"=>$this->authenticator,
                                                                        "PurchaseAmount"=>$amount,
                                                                        "ControlTotal"=> $control_total,
                                                                        "IntegratorTxID"=>$this->integratorTxID));
                    if (isset($response->PurchaseStatus) && $response->PurchaseStatus == "Success") {
                        _log(LOG_INFO, "Purchase Postage: Success");
                        _log(LOG_INFO, "Purchase Postage: Transaction ID #" . $response->TransactionID);
                        _log(LOG_INFO, "Purchase Postage: New control total = " . $response->PostageBalance->ControlTotal);

                        $this->authenticator = $response->Authenticator;

                        return TRUE;
                    } else {
                        _log(LOG_ERR,'Purchase Postage Failed!');
                        return FALSE;
                    }
                }
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        } 
    }

    /**
     * Used to validate and format addresses via USPS standards
     * The address object returned will be the one to be used in generateShippingLabel()
     * 
     * @param [Address] $address - Complete info about the address to cleanse.
     * @return (array)
     *              [status] - Result of the cleansing. Possible Values:
     *                          *ADDRESS_UNSHIPPABLE (-1) - Address is completely unusable
     *                          *ADDRESS_MATCH (1) - Address matched with USPS Database. Perfect to use.
     *                          *ADDRESS_CITYSTATEZIPOK (2) - Address has some issues, but City, State, ZIP Code are okay
     *                                                        still usable.
     *              [address] - new Address Object
     */
    public function cleanseAddress(Address $address) {
        if(isset($this->soapClient)) {
            try {
                if(!isset($address)) {
                    throw new InvalidArgumentException('Address object is required');
                }
                
                _log(LOG_INFO, 'Cleansing address...');
    
                $params = array(
                    "Authenticator" => $this->authenticator,
                    "Address" => (array) $address
                );
                
                $response = $this->soapClient->CleanseAddress($params);
                // save new authenticator
                $this->authenticator = $response->Authenticator;

                if (!$response->CityStateZipOK) {
                    // Cant proceed. Unshippable address.
                    _log(LOG_ERR, 'Unshippable address');
                    return array("status" => ADDRESS_UNSHIPPABLE);
                }
                
                if ($response->AddressMatch) {
                    // Address is good
                    return array("status" => ADDRESS_MATCH,
                                "address" => $response->Address);
                } else if ($response->CityStateZipOK) {
                    // Address Issue
                    // City state zip are good
                    $address->OverrideHash = $response->Address->OverrideHash;
                    return array("status" => ADDRESS_CITYSTATEZIPOK,
                                "address" => $response->Address);
                }
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
            
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        } 
    }

    /**
     * Produces a list of rates for all available USPS services based on the ZIP Code 
     * being shipped to for a given package weight, size and shipDate
     *
     * @param string $fromZIPCode - ZIP Code of the customer
     * @param float $weight - Estimated weight in lb
     * @param string $serviceType - (Optional) Specific service type
     * @param string $packageType - (Optional) Specific package type
     * @param float $length - (Optional) Length of the package
     * @param float $width - (Optional) Width of the package
     * @param float $height - (Optional) Height of the package
     * @param string $shipDate - (Optional) When will the package be shipped. Format (yy-d-m). Default value is now + 3 days.
     * @return list of Rates. Rate object documentation : https://developer.stamps.com/developer/docs/swsimv90.html#getrates
     */
    public function getRates(string $fromZIPCode, float $weight, string $serviceType = NULL, string $packageType = NULL,
                             float $length = NULL, float $width = NULL, float $height = NULL,  string $shipDate = NULL) {
        if(isset($this->soapClient)) {
            try {
                if(!isset($fromZIPCode) && !isset($weight)){
                    throw new InvalidArgumentException('From ZIP code and Weight are required!');
                }

                $params = array("Authenticator" => $this->authenticator,
                                "Rate" => array(
                                    "FromZipCode" => $fromZIPCode,
                                    "ToZIPCode" => $this->toAddress->ZIPCode,
                                    "WeightLb" => $weight,
                                    "ServiceType" => isset($serviceType) ? $serviceType : '',
                                    "PackageType" => isset($packageType) ? $packageType : '',
                                    "Length" => isset($length) ? $length : '',
                                    "Width" => isset($width) ? $width : '',
                                    "Height" => isset($height) ? $height : '',
                                    "ShipDate" => isset($shipDate) ? $shipDate : date('yy-m-d', strtotime('+ 3 days')),
                                )
                );

                $response = $this->soapClient->GetRates($params);
                $this->authenticator = $response->Authenticator;
                // Remove AddOns for now
                if(is_array($response->Rates->Rate)){
                    foreach ($response->Rates->Rate as $key => $value) {
                        unset($response->Rates->Rate[$key]->AddOns);
                    }
                } else {
                    unset($response->Rates->Rate->AddOns);
                }
                
                return $response->Rates->Rate;
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        } 
    }

    /**
     * Generates the postage label based on the shipping information provided in the request.
     * 
     * @param [object] $fromAddress - Address object returned by cleanseAddress().
     * @param [Rate] $rate - A selected Rate object from the array of Rates return by getRates().
     * @return (array)
     *              [TrackingNumber] - Tracking number of the package
     *              [StampsTxID] - Stamps Transaction ID. Can be used to request refunds.
     *              [URL] - Link of the Shipping label PNG image.
     *              [Rate] - Rate info
     */
    public function generateShippingLabel($fromAddress, $rate) {
        if(isset($this->soapClient)) {
            try {
                if(!isset($fromAddress) && !isset($rate)) {
                    throw new InvalidArgumentException('From Address and Rate are required!');
                }

                if(!isset($fromAddress->CleanseHash) && !isset($fromAddress->OverrideHash)) {
                    throw new InvalidArgumentException('Address must be cleansed first. Call cleanseAddress() first.');
                }
                
                $rate = (array) $rate;
                $rate["FromZIPCode"] = $fromAddress->ZIPCode;

                // $res = $this->cleanseAddress(new KaivalAddress());
                // $this->toAddress = $res["address"];


                $params = array(
                    "Authenticator" => $this->authenticator,
                    "IntegratorTxID" => $this->integratorTxID,
                    "Rate" => $rate,
                    "From" => (array) $fromAddress,
                    "To" => (array) $this->toAddress,
                    //"SampleOnly" => true
                );

                $response = $this->soapClient->CreateIndicium($params);
                $this->authenticator = $response->Authenticator;

                $ret = array(
                    "TrackingNumber" => $response->TrackingNumber,
                    "StampsTxID" => $response->StampsTxID,
                    "URL" => $response->URL,
                    "Rate" => (array) $response->Rate
                );
                return $ret;
                
            } catch (Exception $e) {
                _log(LOG_ERR, $e->getMessage());
                throw $e;
            }
        } else {
            throw new Exception("SOAP CLIENT NOT INITIALIZED!");
        }
    }

}
?>

