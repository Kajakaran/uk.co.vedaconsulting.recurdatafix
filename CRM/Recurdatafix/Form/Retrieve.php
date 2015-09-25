<?php

class CRM_Recurdatafix_Form_Retrieve extends CRM_Core_Form{

  function preProcess(){
    CRM_Utils_System::setTitle("Smart Debit - Retreive Recurring Data" );
  }

  function buildQuickForm( ) {
    require_once 'UK_Direct_Debit/Form/Main.php';

    // If no civicrm_sd, then create that table
    if(!CRM_Core_DAO::checkTableExists('civicrm_sd')) {

      $creatSql = "CREATE TABLE `civicrm_sd` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `start_date` datetime NOT NULL,
          `frequency_unit` enum('day','week','month','year') CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT 'month',
          `frequency_interval` int(10) unsigned DEFAULT NULL,
					`amount` decimal(20,2) DEFAULT NULL,
          `transaction_id` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
          `contact_id` int(10) unsigned DEFAULT NULL,
          `external_id` int(10) unsigned DEFAULT NULL,
          `membership_id` int(10) unsigned DEFAULT NULL,
          `member_count` int(10) unsigned DEFAULT NULL,
          `payment_processor_id` varchar(255) DEFAULT NULL,
          `payment_instrument_id` int(10) unsigned DEFAULT NULL,
          `cycle_day` int(10) unsigned NOT NULL DEFAULT '1',
          `contribution_status_id` int(10) DEFAULT '1',
          `is_valid` int(4) NOT NULL DEFAULT '1',
          `payerReference` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
	  `recur_id` int(10) unsigned DEFAULT NULL,
          PRIMARY KEY (`id`)
         ) ENGINE=InnoDB AUTO_INCREMENT=350 DEFAULT CHARSET=latin1";

      CRM_Core_DAO::executeQuery($creatSql);

      $columnExists = CRM_Core_DAO::checkFieldExists('civicrm_contribution_recur', 'membership_id');
      if(!$columnExists) {
        $query = "
          ALTER TABLE civicrm_contribution_recur
          ADD membership_id int(10) unsigned AFTER contact_id,
          ADD CONSTRAINT FK_civicrm_contribution_recur_membership_id
          FOREIGN KEY(membership_id) REFERENCES civicrm_membership(id) ON DELETE CASCADE ON UPDATE RESTRICT";

        CRM_Core_DAO::executeQuery($query);
      }

    }
    // if the civicrm_sd table exists, then empty it
    else {
      $emptySql = "TRUNCATE TABLE `civicrm_sd`";
      CRM_Core_DAO::executeQuery($emptySql);
    }

    $smartDebitArray = self::getSmartDebitPayments(NULL);
    foreach ($smartDebitArray as $key => $smartDebitRecord) {
      if($smartDebitRecord['current_state'] == 10 || $smartDebitRecord['current_state'] == 1
			|| $smartDebitRecord['current_state'] == 11) {

        $regularAmount = substr($smartDebitRecord['regular_amount'], 2);
        // Extract the number from reference_number
        $output = preg_match("/\d+/", $smartDebitRecord['reference_number'], $results);

        if($regularAmount && $results[0]) {
          list($y, $m, $d) = explode('-', $smartDebitRecord['start_date']);
          $sql = "INSERT INTO `civicrm_sd`(`start_date`, `frequency_unit`, `amount`, `transaction_id`, `external_id`, `payment_processor_id`, `payment_instrument_id`, `cycle_day`, `contribution_status_id`, `frequency_interval`, `payerReference`) VALUES (%1,%2,%3,%4,%5,%6,%7,%8,%9,%10,%11)";
					$params = array(
                            1 => array( $smartDebitRecord['start_date'] , 'String' ),
                            2 => array(self::translateSmartDebitFrequencyUnit($smartDebitRecord['frequency_type']) , 'String' ),
                            3 => array( $regularAmount, 'Float'),
                            4 => array( $smartDebitRecord['reference_number'], 'String'),
                            5 => array( $results[0], 'Int'),
                            6 => array( self::getSmartDebitPaymentProcessorID(), 'Int'),
                            7 => array( 5, 'Int'),
                            8 => array( $d, 'Int'),
                            9 => array( 5, 'Int'),
														10 => array( $smartDebitRecord['frequency_factor'], 'Int'),
														11 => array( $smartDebitRecord['payerReference'], 'String'),
                        );
          CRM_Core_DAO::executeQuery($sql , $params);
        }
      }
    }

    if($smartDebitArray) {
      CRM_Core_Session::setStatus('Smart debit data retreived successfully.', 'Success', 'info');
    }
  }

  function getSmartDebitPayments($referenceNumber) {
    $paymentProcessorType = CRM_Core_PseudoConstant::paymentProcessorType(false, null, 'name');
    $paymentProcessorTypeId = CRM_Utils_Array::key('Smart Debit', $paymentProcessorType);
		$domainID = CRM_Core_Config::domainID();

      $sql  = " SELECT user_name ";
      $sql .= " ,      password ";
      $sql .= " ,      signature ";
      $sql .= " FROM civicrm_payment_processor ";
      $sql .= " WHERE payment_processor_type_id = %1";
      $sql .= " AND is_test= %2 AND domain_id = %3";

      $params = array( 1 => array( $paymentProcessorTypeId, 'Integer' )
                     , 2 => array( '0', 'Int' )
										 , 3 => array(  $domainID, 'Int' )
                     );

      $dao = CRM_Core_DAO::executeQuery( $sql, $params);

      if ($dao->fetch()) {

          $username = $dao->user_name;
          $password = $dao->password;
          $pslid    = $dao->signature;

      }

    // Send payment POST to the target URL
    $url = "https://secure.ddprocessing.co.uk/api/data/dump?query[service_user][pslid]=$pslid&query[report_format]=XML";

    // Restrict to a single payer if we have a reference
    if ($referenceNumber) {
      $url .= "&query[reference_number]=$referenceNumber";
    }

    $response = self::requestPost( $url, $username, $password );

		// Take action based upon the response status
    switch ( strtoupper( $response["Status"] ) ) {
        case 'OK':

            $smartDebitArray = array();

					  // Cater for a single response
					  if (isset($response['Data']['PayerDetails']['@attributes'])) {
							$smartDebitArray[] = $response['Data']['PayerDetails']['@attributes'];
						} else {
							foreach ($response['Data']['PayerDetails'] as $key => $value) {
							  $smartDebitArray[] = $value['@attributes'];
							}
						}
            return $smartDebitArray;

        default:
            return false;
    }

  }

    /*************************************************************
      Send a post request with cURL
        $url = URL to send request to
        $data = POST data to send (in URL encoded Key=value pairs)
    *************************************************************/
    function requestPost($url, $username, $password){
        // Set a one-minute timeout for this script
        set_time_limit(160);

        // Initialise output variable
        $output = array();

        $options = array(
                        CURLOPT_RETURNTRANSFER => true, // return web page
                        CURLOPT_HEADER => false, // don't return headers
                        CURLOPT_POST => true,
                        CURLOPT_USERPWD => $username . ':' . $password,
                        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                        CURLOPT_HTTPHEADER => array("Accept: application/xml"),
                        CURLOPT_USERAGENT => "XYZ Co's PHP iDD Client", // Let Webservice see who we are
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_SSL_VERIFYPEER => false,
                      );

        $session = curl_init( $url );

        curl_setopt_array( $session, $options );

        // Tell curl that this is the body of the POST
        curl_setopt ($session, CURLOPT_POSTFIELDS, null );

        // $output contains the output string
        $output = curl_exec($session);
        $header = curl_getinfo( $session );

        //Store the raw response for later as it's useful to see for integration and understanding
        $_SESSION["rawresponse"] = $output;

        if(curl_errno($session)) {
          $resultsArray["Status"] = "FAIL";
          $resultsArray['StatusDetail'] = curl_error($session);
        }
        else {
          // Results are XML so turn this into a PHP Array
          $resultsArray = json_decode(json_encode((array) simplexml_load_string($output)),1);

          // Determine if the call failed or not
          switch ($header["http_code"]) {
            case 200:
              $resultsArray["Status"] = "OK";
              break;
            default:
              $resultsArray["Status"] = "INVALID";
          }
        }

        // Return the output
        return $resultsArray;

    } // END function requestPost()


    public function postProcess() {

      parent::postProcess();
    }
    function getSmartDebitPaymentProcessorID() {
      // Get all contacts who have the tag set
      $selectSql     =  " SELECT id";
      $selectSql     .= " FROM civicrm_payment_processor cpp ";
      $selectSql     .= " WHERE cpp.class_name = %1 AND cpp.is_test = 0";
      $selectParams  = array( 1 => array( 'uk.co.vedaconsulting.payment.smartdebitdd' , 'String' ) );
      $dao           = CRM_Core_DAO::executeQuery( $selectSql, $selectParams );

      while ($dao->fetch()) {
          return $dao->id;
      }
      return 0;
    }

      function translateSmartDebitFrequencyUnit($smartDebitFrequency) {
      if ($smartDebitFrequency == 'Q') {
        return('month' );
      }
      if ($smartDebitFrequency == 'Y') {
        return('year' );
      }
      return('month' );
    }


}
