<?php
/**
 * @version        1.2.0
 * @package        Joomla
 * @subpackage     Membership Pro
 * @author         William David Cossey
 * @copyright      Copyright (C) 2015 Autonomy Solutions
 * @license        
 */
// no direct access
defined('_JEXEC') or die();

class os_payfast extends os_payment
{

	/**
	 * PayFast mode 
	 *
	 * @var boolean live mode : true, sandbox : false
	 */
	var $_mode = 0;
	
	/**
	 * PayFast url
	 *
	 * @var string
	 */
	var $_url = null;
	
	/**
	 * PayFast Passphrase (optional)
	 *
	 * @var string
	 */
	var $_passphrase = null;

	/**
	 * Submit Message
	 *
	 * @var string
	 */
	var $_submitMessage = 'Please wait while you are redirected to PayFast for payment processing...';
	
	/**
	 * Array of params will be posted to server
	 *
	 * @var string
	 */
	var $_params = array();

	/**
	 * Array containing data posted from PayFast to our server
	 *
	 * @var array
	 */
	var $_data = array();

	/**
	 * Constructor functions, init some parameter
	 *
	 * @param object $config
	 */
	function os_payfast($params)
	{
		parent::setName('os_payfast');
		parent::os_payment();
		parent::setCreditCard(false);
		parent::setCardType(false);
		parent::setCardCvv(false);
		parent::setCardHolderName(false);
		$this->itn_log = $params->get('payfast_debugmode');
		$this->itn_log_file = JPATH_COMPONENT . '/payfast_logs.txt';
		$this->_mode = $params->get('payfast_mode');
		
		$tmpMessage = $params->get('message_submit');

		if( !empty( $tmpMessage ) )
		{
			$this->_submitMessage = $tmpMessage;
		}
		
		if ($this->_mode)
		{
			$this->_url = 'https://www.payfast.co.za/eng/process';
			
			$this->setParam('merchant_id', $params->get('merchant_id'));
			$this->setParam('merchant_key', $params->get('merchant_key'));
			$this->_passphrase = $params->get('merchant_passphrase');
		}
		else
		{
			$this->_url = 'https://sandbox.payfast.co.za/eng/process';
			
			$this->setParam('merchant_id', $params->get('merchant_id_sandbox'));
			$this->setParam('merchant_key', $params->get('merchant_key_sandbox'));
			$this->_passphrase = $params->get('merchant_passphrase_sandbox');
		}
	}

	/**
	 * Set param value
	 *
	 * @param string $name
	 * @param string $val
	 */
	function setParam($name, $val)
	{
		$this->_params[$name] = $val;
	}

	/**
	 * Process Payment
	 *
	 * @param object $row
	 * @param array $params
	 */
	function processPayment($row, $data)
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$Itemid = JRequest::getInt('Itemid');
		$siteUrl = JUri::base();
		
		$query->select('*')
			->from('#__osmembership_plans')
			->where('id = '. $row->plan_id);
		$db->setQuery($query);
		$rowPlan = $db->loadObject();
		
		//PayFast is very particular about the order of parameters (esp. when generating a signature).
		$this->setParam('return_url', $siteUrl . 'index.php?option=com_osmembership&view=complete&Itemid=' . $Itemid);
		$this->setParam('cancel_url', $siteUrl . 'index.php?option=com_osmembership&view=cancel&id=' . $row->id . '&Itemid=' . $Itemid);
		$this->setParam('notify_url', $siteUrl . 'index.php?option=com_osmembership&task=payment_confirm&payment_method=os_payfast');
		
		$this->setParam('name_first', $row->first_name);
		$this->setParam('name_last', $row->last_name);
		$this->setParam('email_address', $row->email);
		$this->setParam('m_payment_id', $row->id);
		$this->setParam('amount', round($data['amount'], 2));
		$this->setParam('item_name', $data['item_name']);
		//$this->setParam('item_description', );
		
		//$this->setParam('custom_int1', $row->id);
		
		$passPhrase = $this->_passphrase;
		if( isset( $passPhrase ) )
		{
			$this->setParam('passphrase', urlencode($passPhrase));
		}
		
		

		$this->submitPost();
	}

	/**
	 * Submit post to PayFast server
	 *
	 */
	function submitPost() 
	{	
	?>
		<div class="contentheading"><?php echo  JText::_($this->_submitMessage); ?></div>
		<form method="post" action="<?php echo $this->_url; ?>" name="osm_form" id="osm_form">
			<?php
				$signatureString = null;
				foreach ($this->_params as $key=>$val) 
				{
					if(!empty ( $val ))
					{
						$signatureString .= $key.'='.urlencode( trim( $val ) ).'&';
						
						if ( ( $key !== "passphrase" ))
						{
							echo '<input type="hidden" name="'.$key.'" value="'.urlencode( trim ( $val ) ).'" />';
							echo "\n";
						}
					}
				}
				
				// Remove last ampersand.
				$signatureString = substr( $signatureString, 0, -1 );

				// Update the signature.
				$md5Hash = md5( $signatureString );				
			?>
			<script type="text/javascript">
				function redirect() 
				{
					document.osm_form.submit();
				}				
				setTimeout('redirect()', 5000);
			</script>
		</form>
	<?php	
	}

	/**
	 * Log ITN result
	 *
	 * @param string $success
	 */
	function log_itn_results($success)
	{
		if (!$this->itn_log)
			return;
		$text = '[' . date('m/d/Y g:i A') . '] - ';
		if ($success)
			$text .= "SUCCESS!\r\n";
		else
			$text .= 'FAIL: ' . $this->last_error . "\n";
		$text .= "ITN POST Variables from PayFast:\r\n";
		foreach ($this->_data as $key => $value)
		{
			$text .= "$key=$value, ";
		}
		$text .= "\r\nITN Response from PayFast Server:\r\n " . $this->itn_response;
		$fp = fopen($this->itn_log_file, 'a');
		fwrite($fp, $text . "\r\n\r\n");
		fclose($fp); // close file
	}

	function _verifyPayFast()
	{
		define( 'USER_AGENT', 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)' );
			// User Agent for cURL
		 
		// Messages
			// Error
		define( 'PF_ERR_AMOUNT_MISMATCH', 'Amount mismatch' );
		define( 'PF_ERR_BAD_SOURCE_IP', 'Bad source IP address' );
		define( 'PF_ERR_CONNECT_FAILED', 'Failed to connect to PayFast' );
		define( 'PF_ERR_BAD_ACCESS', 'Bad access of page' );
		define( 'PF_ERR_INVALID_SIGNATURE', 'Security signature mismatch' );
		define( 'PF_ERR_CURL_ERROR', 'An error occurred executing cURL' );
		define( 'PF_ERR_INVALID_DATA', 'The data received is invalid' );
		define( 'PF_ERR_UKNOWN', 'Unkown error occurred' );
		 
			// General
		define( 'PF_MSG_OK', 'Payment was successful' );
		define( 'PF_MSG_FAILED', 'Payment has failed' );
		 
		 
		// Notify PayFast that information has been received
		header( 'HTTP/1.0 200 OK' );
		flush();
		 
		// Variable initialization
		$pfError = false;
		$pfErrMsg = '';
		$filename = $this->itn_log_file;
		$output = '';
		$pfParamString = '';
		
		if ($this->_mode)
		{
			$pfHost = 'www.payfast.co.za';
		}
		else
		{
			$pfHost = 'sandbox.payfast.co.za';
		}
		
		$pfData = $_POST;
 
		//// Dump the submitted variables and calculate security signature
		if( !$pfError )
		{
			// Strip any slashes in data
			foreach( $pfData as $key => $val )
			{
				$this->_data[$key] = $val;
				$pfData[$key] = stripslashes( $val );
			}
			
			// $pfData includes of ALL the fields posted through from PayFast, this includes the empty strings
			foreach( $pfData as $key => $val )
			{
				if( $key != 'signature' )
				{
					$pfParamString .= $key .'='. urlencode( $val ) .'&';
				}
			}

			// Remove the last '&' from the parameter string
			$pfParamString = substr( $pfParamString, 0, -1 );
			$pfTempParamString = $pfParamString;
			 
			// If a passphrase has been set in the PayFast Settings, then it needs to be included in the signature string.
			$passPhrase = $this->_passphrase;
			if( !empty( $passPhrase ) )
			{
				$pfTempParamString .= '&passphrase='.urlencode( $passPhrase );
			}
			
			$signature = md5( $pfTempParamString );
			
			$pfDataSignature = $pfData['signature'];
			
			$result = ( $pfData['signature'] == $signature );
		 
			$output .= "\r\nSignature:\r\n"; // DEBUG
			$output .= "- posted     = ". $pfData['signature'] ."\r\n"; // DEBUG
			$output .= "- calculated = ". $signature ."\r\n"; // DEBUG
			$output .= "- result     = ". ( $result ? 'SUCCESS' : 'FAILURE' ) ."\r\n"; // DEBUG
			
			if( $result === false )
			{
				$pfError = true;
				$pfErrMsg = PF_ERR_INVALID_SIGNATURE;
			}
			$pfError = !$result;
		}
		 
		//// Verify source IP
		if( !$pfError )
		{
			$validHosts = array(
				'www.payfast.co.za',
				'sandbox.payfast.co.za',
				'w1w.payfast.co.za',
				'w2w.payfast.co.za',
				);
		 
			$validIps = array();
		 
			foreach( $validHosts as $pfHostname )
			{
				$ips = gethostbynamel( $pfHostname );
		 
				if( $ips !== false )
					$validIps = array_merge( $validIps, $ips );
			}
		 
			// Remove duplicates
			$validIps = array_unique( $validIps );
		 
			if( !in_array( $_SERVER['REMOTE_ADDR'], $validIps ) )
			{
				$pfError = true;
				$pfErrMsg = PF_ERR_BAD_SOURCE_IP;
			}
		}
		 
		//// Connect to server to validate data received
		if( !$pfError )
		{
			// Use cURL (If it's available)
			if( function_exists( 'curl_init' ) )
			{
				$output .= "\r\nUsing cURL\r\n"; // DEBUG
		 
				// Create default cURL object
				$ch = curl_init();
		 
				// Base settings
				$curlOpts = array(
					// Base options
					CURLOPT_USERAGENT => USER_AGENT, // Set user agent
					CURLOPT_RETURNTRANSFER => true,  // Return output as string rather than outputting it
					CURLOPT_HEADER => false,         // Don't include header in output
					CURLOPT_SSL_VERIFYHOST => true,
					CURLOPT_SSL_VERIFYPEER => false,
		 
					// Standard settings
					CURLOPT_URL => 'https://'. $pfHost . '/eng/query/validate',
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $pfParamString,
				);
				curl_setopt_array( $ch, $curlOpts );
		 
				// Execute CURL
				$res = curl_exec( $ch );
				curl_close( $ch );
		 
				if( $res === false )
				{
					$pfError = true;
					$pfErrMsg = PF_ERR_CURL_ERROR;
				}
			}
			// Use fsockopen
			else
			{
				$output .= "\n\nUsing fsockopen\n\n"; // DEBUG
		 
				// Construct Header
				$header = "POST /eng/query/validate HTTP/1.0\r\n";
				$header .= "Host: ". $pfHost ."\r\n";
				$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$header .= "Content-Length: " . strlen( $pfParamString ) . "\r\n\r\n";
		 
				// Connect to server
				$socket = fsockopen( 'ssl://'. $pfHost, 443, $errno, $errstr, 10 );
		 
				// Send command to server
				fputs( $socket, $header . $pfParamString );
		 
				// Read the response from the server
				$res = '';
				$headerDone = false;
		 
				while( !feof( $socket ) )
				{
					$line = fgets( $socket, 1024 );
		 
					// Check if we are finished reading the header yet
					if( strcmp( $line, "\r\n" ) == 0 )
					{
						// read the header
						$headerDone = true;
					}
					// If header has been processed
					else if( $headerDone )
					{
						// Read the main response
						$res .= $line;
					}
				}
			}
		}
		 
		//// Get data from server
		if( !$pfError )
		{
			// Parse the returned data
			$lines = explode( "\n", $res );
		 
			$output .= "\r\nValidate response from server:\r\n"; // DEBUG
		 
			foreach( $lines as $line ) // DEBUG
				$output .= $line ."\r\n"; // DEBUG
		}
		 
		//// Interpret the response from server
		if( !$pfError )
		{
			// Get the response from PayFast (VALID or INVALID)
			$result = trim( $lines[0] );
		 
			$output .= "\r\nResult = ". $result; // DEBUG
		 
			// If the transaction was valid
			if( strcmp( $result, 'VALID' ) == 0 )
			{
				// Process as required
			}
			// If the transaction was NOT valid
			else
			{
				// Log for investigation
				$pfError = true;
				$pfErrMsg = PF_ERR_INVALID_DATA;
			}
		}
		 
		// If an error occurred
		if( $pfError )
		{
			$output .= "\r\nAn error occurred!";
			$output .= "\r\nError = ". $pfErrMsg;
		}
		
		$this->itn_response = $output;
		$this->log_itn_results(!$pfError);
		
		return !$pfError;
	}
	
	/**
	 * Process payment 
	 *
	 */
	function verifyPayment()
	{
		$retPayFast = $this->_verifyPayFast();

		if ($retPayFast)
		{
			$config = OSMembershipHelper::getConfig();
			$row = JTable::getInstance('OsMembership', 'Subscriber');
			$id = $this->_data['m_payment_id'];
			$transactionId = $this->_data['txn_id'];
			
			if ($transactionId && OSMembershipHelper::isTransactionProcessed($transactionId))
			{
				return false;
			}
			$amount = $this->_data['amount_gross'];
			if ($amount < 0)
			{
				return false;
			}
			$row->load($id);
			if ($row->published)
			{
				return false;
			}
			if ($row->gross_amount > $amount)
			{
				return false;
			}
			$row->payment_date = date('Y-m-d H:i:s');
			$row->transaction_id = $transactionId;
			$row->published = 1;
			$row->store();
			if ($row->act == 'upgrade')
			{
				OSMembershipHelper::processUpgradeMembership($row);
			}
			JPluginHelper::importPlugin('osmembership');
			$dispatcher = JDispatcher::getInstance();
			$dispatcher->trigger('onMembershipActive', array($row));
			OSMembershipHelper::sendEmails($row, $config);
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Process recurring payment
	 *
	 * @param object $row
	 * @param object $data
	 */
	/*function processRecurringPayment($row, $data)
	{
		PayFast doesn't support recurring payments
		https://www.payfast.co.za/support/index.php?/Knowledgebase/Article/View/14/3/can-i-receive-recurring-payments
	}*/
	
	/**
	 * Verify recrrung payment
	 *
	 */
	/*function verifyRecurringPayment()
	{
		PayFast doesn't support recurring payments
	}*/
}