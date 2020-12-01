<?php
/**
 * NOTE: OpenCart uses camelCase for functions/variables and
 *       snake_case for database fields (e.g. order_status_id).
 *
 *       Retrieved model data simply returns field name and their
 *       value in snake_case rather than camelCase otherwise used
 *       in OpenCart's PHP coding standards
 *
 *       Loaded models also use snake_case
 */
class ControllerExtensionPaymentCardstream extends Controller
{
    /**
     * Gateway Direct API Endpoint
     */
    const API_ENDPOINT_DIRECT = 'https://test.3ds-pit.com/direct/';


    static private $url;
	static private $curi;
	static private $token;

	public function __construct($registry) {
		parent::__construct($registry);
		$module = strtolower(basename(__FILE__, '.php'));
		self::$url = 'extension/payment/' . $module;
		self::$curi = 'payment_' . $module;
		self::$token = (isset($this->session->data['user_token']) ? '&user_token=' . $this->session->data['user_token'] : '');
	}


	public function index() {
		// Only load where the confirm action is asking us to show the form!
		if ($_REQUEST['route'] == 'checkout/confirm') {
			$this->load->language(self::$url);

            $integrationType = $this->config->get(self::$curi . '_module_type');

            if (in_array($integrationType, ['hosted', 'hosted_v2', 'hosted_v3'], true)) {
				return $this->createForm($integrationType);
			}

            if (in_array($integrationType, ['direct', 'direct_v2'], true)) {
				return $this->createDirectForm( 'direct_v2' === $integrationType);
            }
            
		} else {
			return new \Exception('Unauthorized!');
		}
	}

	private function createForm($type = 'hosted') {
		$data['button_confirm'] = $this->language->get('button_confirm');
        $merchant_secret = $this->config->get(self::$curi . '_merchantsecret');

        $formdata = $this->captureOrder();

        $data['includeDeviceData'] = true;
        $data['isHostedModal'] = $type === 'hosted_v3';

        $formdata = array_merge($formdata, [
            'threeDSOptions[browserAcceptHeader]'      => (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
            'threeDSOptions[browserIPAddress]'         => (isset($_SERVER['REMOTE_ADDR']) ? htmlentities($_SERVER['REMOTE_ADDR']) : null),
            'threeDSOptions[browserJavaEnabledVal]'    => '',
            'threeDSOptions[browserJavaScriptEnabled]' => true,
            'threeDSOptions[browserLanguage]'		   => (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
            'threeDSOptions[browserScreenColorDepth]'  => '',
            'threeDSOptions[browserScreenHeight]'      => '',
            'threeDSOptions[browserScreenWidth]'       => '',
            'threeDSOptions[browserTimeZone]'          => '0',
            'threeDSOptions[browserUserAgent]'		   => (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
        ]);

        $callback = $this->url->link(self::$url . '/callback', '', true);
        $formdata['redirectURL'] = $callback;
        $formdata['callbackURL'] = $callback;
        $formdata['threeDSVersion'] = 2;

        $formdata['signature'] = $this->createSignature($formdata, $merchant_secret);

        $data['formdata'] = $formdata;

        if (in_array($type, ['hosted', 'hosted_v3'], true)) {
            return $this->load->view(self::$url . '_hosted', $data);
        }

        if (in_array($type, ['hosted_v2'], true)) {
            return $this->load->view(self::$url . '_iframe', $data);
        }
    }

	private function createDirectForm($includeDeviceDetails = false) {
		$data['cc_card_number'] = $this->language->get('cc_card_number');
		$data['cc_card_start_date'] = $this->language->get('cc_card_start_date');
		$data['cc_card_expiry_date'] = $this->language->get('cc_card_expiry_date');
		$data['cc_card_cvv'] = $this->language->get('cc_card_cvv');
		$data['text_credit_card'] = $this->language->get('text_credit_card');
		$data['button_confirm'] = $this->language->get('button_confirm');
        $data['process_url'] = $this->url->link(self::$url . '/direct_callback', '', true);

        if ($includeDeviceDetails) {
            $data['threeDSOptions'] = [
                'browserAcceptHeader' => (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                'browserIPAddress' => (isset($_SERVER['REMOTE_ADDR']) ? htmlentities($_SERVER['REMOTE_ADDR']) : null),
            ];
        }

        //if direct v2
        $data['threeDSVersion'] = 2;

		return $this->load->view(self::$url . '_direct', $data);
	}

	/**
	 * callback is used with the hosted form integration after a payment attempt to further process the order
	 */
	public function callback() {

		// Setup page headers
		$isSecure = isset($this->request->server['HTTPS']) && $this->request->server['HTTPS'] == 'on';
		$isIframe = $this->config->get(self::$curi . '_module_type') == 'iframe';
		$data['base']              = ($isSecure ? HTTPS_SERVER : HTTP_SERVER);
		$data['language']          = $this->language->get('code');
		$data['direction']         = $this->language->get('direction');
		// Page titles
		$data['title']             = sprintf($this->language->get('heading_title'), $this->config->get('config_name'));
		$data['heading_title']     = sprintf($this->language->get('heading_title'), $this->config->get('config_name'));
		$data['text_response']     = $this->language->get('text_response');
		// Success text
		$data['text_success']      = $this->language->get('text_success');
		$data['text_success_wait'] = sprintf($this->language->get('text_success_wait'), $this->url->link('checkout/success'));
		// Error text
		$data['text_failure']      = $this->language->get('text_failure');
		$data['text_failure_wait'] = sprintf($this->language->get('text_failure_wait'), $this->url->link('checkout/cart'));
		// Mismatch
		$data['text_mismatch']     = $this->language->get('text_mismatch');
		// Start processing response data
		$data = $this->request->post;

        $this->processResponse($data, $isIframe);
    }

	public function direct_callback()
    {
        $step = isset($_REQUEST['step']) ? (int)$_REQUEST['step'] : 1;
        $redirectUrl = $this->url->link(self::$url . '/direct_callback', '', true);
        $merchant_id = $this->config->get(self::$curi . '_merchantid');

        $options = [
            'merchantID' => $merchant_id,
            'redirectURL' => $redirectUrl,
            'secret' => $this->config->get(self::$curi . '_merchantsecret'),
        ];

        switch ($step) {
            case 1:
                // usually at this step we display the form, please check template file implementation
            case 2:
                $orderData = $this->captureOrder();

                $parameters = array_merge($orderData, [
                    'type' => 1,
                    'cardNumber' => $_POST['cardNumber'],
                    'cardExpiryMonth' => $_POST['cardExpiryMonth'],
                    'cardExpiryYear' => $_POST['cardExpiryYear'],
                    'cardCVV' => $_POST['cardCVV'],
                    'threeDSRedirectURL' => $redirectUrl . "&step=4",
                ]);

                if (isset($_POST['threeDSOptions'])) {
                    $parameters['threeDSOptions'] = $_POST['threeDSOptions'];
                }

                if (isset($_POST['threeDSVersion'])) {
                $parameters['threeDSVersion'] = 2;
                }

                $parameters['signature'] = $this->createSignature($parameters, $options['secret']);

                $response = $this->post($parameters, $options);


                //If response has threeDSXref store this to a cookie.
                if(isset($response['threeDSRef'])) {
                    setcookie('threeDSRef', $response['threeDSRef'], ini_get('session.cookie_lifetime'), ini_get('session.cookie_path'), ini_get('session.cookie_domain'));
                }

                return $this->response->setOutput(json_encode($response));
            case 3:
                $req = [
                    'merchantID' => $options['merchantID'],
                    'xref' => $_REQUEST['xref'],
                    'action' => 'SALE',
                    'threeDSMD' => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
                    'threeDSPaRes' => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
                    'threeDSPaReq' => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null),
                ];

                $req['signature'] = self::createSignature($req, $options['secret']);

                $response = self::post($req);

                $this->processResponse($response, false);
                break;
            case 4:
          
                // Case 4 (3DS v2)

                $req = array(
                    'merchantID' => $options['merchantID'],
                    'action' => 'SALE',
                    // The following field must be passed to continue the 3DS request
                    'threeDSRef' => $_COOKIE['threeDSRef'],
                    'threeDSResponse' => $_POST,
                );

                $req['signature'] = self::createSignature($req, $options['secret']);

                $response = self::post($req);

                if($response['responseCode'] == 65802) {

                    // Save the threeDSRef to a a cookie to use after callback.
                    setcookie('threeDSRef', $response['threeDSRef'], ini_get('session.cookie_lifetime'), ini_get('session.cookie_path'), ini_get('session.cookie_domain'));

                    // Render an IFRAME to show the ACS challenge (hidden for fingerprint method)
                    $style = (isset($response['threeDSRequest']['threeDSMethodData']) ? 'display: none;' : '');
                    echo "<iframe name=\"threeds_acs\" style=\"height:420px; width:420px; {$style}\"></iframe>\n";
    
                    // Silently POST the 3DS request to the ACS in the IFRAME
                    echo $this->silentPost($response['threeDSURL'], $response['threeDSRequest'], 'threeds_acs');
    
                    exit();

                } else {
                    $this->processResponse($response, true);
                }

                break;
            default:
                throw new DomainException(sprintf(
                    'Integration %s do not have such step: %s',
                    $options['integrationType'],
                    $step
                ));
        }
    }

    protected function silentPost($url = '?', array $post = null, $target = '_self') {

        $url = htmlentities($url);
        $target = htmlentities($target);
        $fields = '';

        if ($post) {
            foreach ($post as $name => $value) {
                $fields .= $this->fieldToHtml($name, $value);
            }
        }

        $ret = "
		<form id=\"silentPost\" action=\"{$url}\" method=\"post\" target=\"{$target}\">
			{$fields}	
		</form>
		<script>
			window.setTimeout('document.forms.silentPost.submit()', 0);
		</script>
	";

        return $ret;
    }

    protected function fieldToHtml($name, $value) {
        $ret = '';
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $ret .= $this->fieldToHtml($name . '[' . $n . ']', $v);
            }
        } else {
            // Convert all applicable characters or none printable characters to HTML entities
            $value = preg_replace_callback('/[\x00-\x1f]/', function($matches) { return '&#' . ord($matches[0]) . ';'; }, htmlentities($value, ENT_COMPAT, 'UTF-8', true));
            $ret = "<input type=\"hidden\" name=\"{$name}\" value=\"{$value}\" />\n";
        }

        return $ret;
    }

	private function getResponseTemplate(){
		return array(
			'orderRef',
			'signature',
			'responseCode',
			'transactionUnique',
			'responseMessage',
			'action'
		);
	}

	private function hasKeys($array, $keys) {
		$checkKeys = array_keys($array);
		$str = '';
		foreach ($keys as $key){
			if(!array_key_exists($key, $array)) {
				return false;
			}
		}
		return true;
	}

	private function buildMessage($data) {

		$msg = "Payment " . ($data['responseCode'] == 0 ? "Successful" : "Unsuccessful") . "<br/><br/>" .
				"Amount Received: " . (isset($data['amountReceived']) ? floatval($data['amountReceived']) / 100 : 'Unknown') . "<br/>" .
				"Message: \"" . ucfirst($data['responseMessage']) . "\"</br>" .
				"Xref: " . $data['xref'] . "<br/>" .
				(isset($data['cv2Check']) ? "CV2 Check: " . ucfirst($data['cv2Check']) . "</br>": '') .
				(isset($data['addressCheck']) ? "Address Check: " . ucfirst($data['addressCheck']) . "</br>": '') .
				(isset($data['postcodeCheck']) ? "Postcode Check: " . ucfirst($data['postcodeCheck']) . "</br>": '');

		if (isset($data['threeDSEnrolled'])) {
			switch ($data['threeDSEnrolled']) {
				case "Y":
					$enrolledtext = "Enrolled.";
					break;
				case "N":
					$enrolledtext = "Not Enrolled.";
					break;
				case "U";
					$enrolledtext = "Unable To Verify.";
					break;
				case "E":
					$enrolledtext = "Error Verifying Enrolment.";
					break;
				default:
					$enrolledtext = "Integration unable to determine enrolment status.";
					break;
			}
			$msg .= "<br />3D Secure enrolled check outcome: \"" . $enrolledtext . "\"";
		}

		if (isset($data['threeDSAuthenticated'])) {
			switch ($data['threeDSAuthenticated']) {
				case "Y":
					$authenticatedtext = "Authentication Successful";
					break;
				case "N":
					$authenticatedtext = "Not Authenticated";
					break;
				case "U";
					$authenticatedtext = "Unable To Authenticate";
					break;
				case "A":
					$authenticatedtext = "Attempted Authentication";
					break;
				case "E":
					$authenticatedtext = "Error Checking Authentication";
					break;
				default:
					$authenticatedtext = "Integration unable to determine authentication status.";
					break;
			}
			$msg .= "<br />3D Secure authenticated check outcome: \"" . $authenticatedtext . "\"";
		}
		return $msg;
	}

    /**
     * Sign requests with a SHA512 hash
     * @param array $data Request data
     *
     * @param $key
     * @return string|null
     */
    protected function createSignature(array $data, $key) {
        if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
            return null;
        }

        ksort($data);

        // Create the URL encoded signature string
        $ret = http_build_query($data, '', '&');

        // Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
        $ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);
        // Hash the signature string and the key together
        return hash('SHA512', $ret . $key);
    }

    public function post($parameters, $options = null) {
        $gatewayUrl = isset($options['gatewayURL']) && !empty($options['gatewayURL']) ? $options['gatewayURL'] : self::API_ENDPOINT_DIRECT;

//        $url = 'https://gateway.Cardstream.com/direct/';
        $ch = curl_init($gatewayUrl);
//        curl_setopt($ch, CURLOPT_PROXY, 'http://0.0.0.0:80');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
        curl_setopt($ch, CURLOPT_HEADER, false);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);

        $str = curl_exec($ch);
        parse_str($str, $response);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
        }
        curl_close($ch);

        return $response;
    }

    /**
     * @return array
     */
    private function captureOrder()
    {
        $merchant_id = $this->config->get(self::$curi . '_merchantid');
        $country_code = $this->config->get(self::$curi . '_countrycode');
        $currency_code = $this->config->get(self::$curi . '_currencycode');
        $form_responsive = $this->config->get(self::$curi . '_form_responsive');

        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $amount = $this->currency->format($order['total'], $order['currency_code'], $order['currency_value'], false);
        $amount = (int)round($amount, 2) * 100;

        $trans_id = $this->session->data['order_id'];
        $bill_name = $order['payment_firstname'] . ' ' . $order['payment_lastname'];

        $bill_addr = "";
        $addressFields = [
            'payment_address_1',
            'payment_address_2',
            'payment_city',
            'payment_zone',
            'payment_country'
        ];
        foreach ($addressFields as $item) {
            $bill_addr .= $order[$item] . ($item == 'payment_country' ? "" : ",\n");
        }

        return array(
            "action" => "SALE",
            "merchantID" => $merchant_id,
            "amount" => $amount,
            "countryCode" => $country_code,
            "currencyCode" => $currency_code,
            "transactionUnique" => $trans_id,
            "orderRef" => "Order " . $trans_id,
            "customerName" => $bill_name,
            "customerAddress" => $bill_addr,
            "customerPostCode" => $order['payment_postcode'],
            "customerEmail" => $order['email'],
            "customerPhone" => @$order['telephone'],
            "item1Description" => "Order " . $trans_id,
            "item1Quantity" => 1,
            "item1GrossValue" => $amount,
            "formResponsive" => $form_responsive,
        );
    }

    /**
     * @param $data
     * @param $isIframe
     */
    private function processResponse($data, $isIframe = false)
    {
        // Make sure it's a valid request
        if ($this->hasKeys($data, $this->getResponseTemplate())) {
            $this->load->model('checkout/order');
            $orderId = $data['transactionUnique'];
            $order = $this->model_checkout_order->getOrder($orderId);
            $amountExpected = (int)round($this->currency->format($order['total'], $order['currency_code'], $order['currency_value'], false), 2) * 100;

            if (intval($data['responseCode']) == 0) {
                // Only update if the order id has not been properly set yet
                if ($order['order_status_id'] == 0) {
                    $this->model_checkout_order->addOrderHistory(
                        $data['transactionUnique'], // order ID
                        $this->config->get(self::$curi . '_order_status_id'), // order status ID
                        $this->buildMessage($data), // Comment to status
                        true //Send notification
                    );
                }
                $url = $this->url->link('checkout/success', '', true) . self::$token;
                if ($isIframe) {
                    $this->response->setOutput("<script>top.location = '$url';</script>");
                } else {
                    $this->response->redirect($url);
                }

            } else {
                if ($order) {
                    try {
                        $this->model_checkout_order->deleteOrder($orderId);
                    } catch (Exception $e) {
                        // Order was not present
                    }
                }
                $error = true;
            }
        } else {
            // Don't try to delete an order here,
            // since it could be a fraudulent request!
            $error = true;
        }

        if (isset($error) && $error) {
            $url = $this->url->link('checkout/failure', '', true) . self::$token;
            if ($isIframe) {
                $this->response->setOutput("<script>top.location = '$url';</script>");
            } else {
                $this->response->redirect($url);
            }
        }
    }
}
