<?php

class Pay_Model extends Model
{
    const STATUS_PENDING = 'PENDING'; // phpcs:ignore
    const STATUS_CANCELED = 'CANCELED'; // phpcs:ignore
    const STATUS_COMPLETE = 'COMPLETE'; // phpcs:ignore
    const STATUS_REFUNDED = 'REFUNDED'; // phpcs:ignore

    protected $_paymentOptionId;

    /**
     * @return void
     */
    public function createTables()
    {
        $this->db->query("                
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_transactions` (
                            `id` varchar(255) NOT NULL,
                            `orderId` int(11) NOT NULL,
                            `optionId` int(11) NOT NULL,
                            `optionSubId` int(11) DEFAULT NULL,
                            `amount` int(11) NOT NULL,
                            `status` varchar(255) NOT NULL,
                            `created` int(11) NOT NULL,
                            `last_update` int(11) DEFAULT NULL,
                            `start_data` text NOT NULL,
                            PRIMARY KEY (`id`)
                          ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci ;
		");
        $this->db->query("                
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_paymentoptions` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `optionId` int(11) NOT NULL,
                            `serviceId` varchar(20) NOT NULL,
                            `name` varchar(255) NOT NULL,
                            `img` varchar(255) NOT NULL,
                            `update_date` datetime NOT NULL,
                            PRIMARY KEY (`id`)
                          ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci ;
		");
        $this->db->query("                
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paynl_paymentoption_subs` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `optionSubId` int(11) NOT NULL,   
                            `paymentOptionId` int(11) NOT NULL,                                                  
                            `name` varchar(255) NOT NULL,
                            `img` varchar(255) NOT NULL,
                            `update_date` datetime NOT NULL,
                            PRIMARY KEY (`id`)
                          ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci ;
		");
    }

    /**
     * @param string $transactionId
     * @param string $orderId
     * @param string $optionId
     * @param string $amount
     * @param string $startData
     * @param string|null $optionSubId
     * @return string
     */
    public function addTransaction($transactionId, $orderId, $optionId, $amount, $startData, $optionSubId = null)
    {
        $sql = "INSERT INTO `" . DB_PREFIX . "paynl_transactions` (id, orderId, optionId, optionSubId, amount, status, created, start_data) VALUES ("
                . "'" . $this->db->escape($transactionId) . "'"
                . ",'" . $this->db->escape($orderId) . "'"
                . ",'" . $this->db->escape($optionId) . "'"
                . "," . (is_null($optionSubId) ? 'NULL' : "'" . $this->db->escape($optionSubId) . "'")
                . ",'" . $this->db->escape($amount) . "'"
                . ", '" . self::STATUS_PENDING . "'"
                . ", UNIX_TIMESTAMP() "
                . ",'" . $this->db->escape(json_encode($startData)) . "'"
                . ")";
        return $this->db->query($sql);
    }

    /**
     * @param string $serviceId
     * @param string $apiToken
     * @param string $gateway
     * @return void
     */
    public function refreshPaymentOptions($serviceId, $apiToken, $gateway)
    {
        $serviceId = $this->db->escape($serviceId);
        //eerst de oude verwijderen
        $sql = "DELETE options,optionsubs  FROM `" . DB_PREFIX . "paynl_paymentoptions` as options "
                . "LEFT JOIN `" . DB_PREFIX . "paynl_paymentoption_subs` as optionsubs ON optionsubs.paymentOptionId = options.id ";
        $this->db->query($sql);

        //nieuwe ophalen
        $api = new Pay_Api_Getservice();
        $api->setApiToken($apiToken);
        $api->setServiceId($serviceId);

        if (!empty($gateway)) {
            $api->setApiBase($gateway);
        }

        $result = $api->doRequest();

        foreach ($result['paymentOptions'] as $paymentOption) {
            $img = $paymentOption['img'];

            //variabelen filteren
            $optionId = $this->db->escape($paymentOption['id']);
            $name = $this->db->escape($paymentOption['visibleName']);
            $img = $this->db->escape($img);
            $brand_id = isset($paymentOption['brand']['id']) ? $paymentOption['brand']['id'] : 0;
            $brand_id = $this->db->escape($brand_id);

            $imageArr = array('img' => $img, 'brand_id' => $brand_id);
            $imageJson = json_encode($imageArr);

            $sql = "INSERT INTO `" . DB_PREFIX . "paynl_paymentoptions` "
                    . "(optionId, serviceId, name, img, update_date) VALUES "
                    . "('$optionId', '$serviceId', '$name', '$imageJson', NOW())";
            $this->db->query($sql);

            $internalOptionId = $this->db->getLastId();
            foreach ($paymentOption['paymentOptionSubList'] as $optionSub) {
                $optionSubId = $optionSub['id'];
                $name = $optionSub['visibleName'];
                $img = $optionSub['image'];

                //variabelen filteren
                $optionSubId = $this->db->escape($optionSubId);
                $name = $this->db->escape($name);
                $img = $this->db->escape($img);

                $sql = "INSERT INTO `" . DB_PREFIX . "paynl_paymentoption_subs` "
                        . "(optionSubId, paymentOptionId, name, img, update_date) VALUES "
                        . "('$optionSubId', $internalOptionId, '$name', '$img', NOW() )";
                $this->db->query($sql);
            }
        }
    }

    /**
     * @param $text
     * @return void
     */
    public function log($text)
    {
        if ($this->config->get('payment_paynl_general_logging') !== '0') {
            $log = new Log('pay.log');
            $log->write($text);
        }
    }

    /**
     * @param integer $paymentOptionId
     * @return boolean|array
     */
    public function getPaymentOption($paymentOptionId)
    {

        $paymentOptionId = $this->db->escape($paymentOptionId);
        $sql = "SELECT * FROM `" . DB_PREFIX . "paynl_paymentoptions` WHERE optionId = '$paymentOptionId' LIMIT 1;";
        $result = $this->db->query($sql);

        $paymentOption = $result->row;
        if (empty($paymentOption)) {
            return false;
        }

        //kijken of er subs zijn
        $sql = "SELECT * FROM `" . DB_PREFIX . "paynl_paymentoption_subs` WHERE paymentOptionId = '" . $paymentOption['id'] . "' ORDER BY name ASC; ";
        $result = $this->db->query($sql);
        $optionSubs = $result->rows;
        $arrOptionSubs = array();
        if (!empty($optionSubs)) {
            foreach ($optionSubs as $optionSub) {
                $arrOptionSubs[] = array(
                    'id' => $optionSub['optionSubId'],
                    'name' => $optionSub['name'],
                    'img' => $optionSub['img'],
                    'update_date' => $optionSub['update_date'],
                );
            }
        }

        $imgArr = json_decode($paymentOption['img']);
        if (is_object($imgArr)) {
            $img = $imgArr->img;
            $brand_id = $imgArr->brand_id;
        } else {
            $img = $paymentOption['img'];
            $brand_id = 0;
        }

        $arrPaymentOption = array(
            'id' => $paymentOption['optionId'],
            'name' => $paymentOption['name'],
            'optionSubs' => $arrOptionSubs,
            'img' => $img,
            'update_date' => $paymentOption['update_date'],
            'brand_id' => $brand_id,
        );

        return $arrPaymentOption;
    }

    /**
     * @param $transactionId
     * @param $orderId
     * @return mixed
     */
    public function getTransaction($transactionId, $orderId = null)
    {
        if (empty($orderId)) {
            $sql = "SELECT * FROM `" . DB_PREFIX . "paynl_transactions` WHERE id = '" . $this->db->escape($transactionId) . "' LIMIT 1;";
        } else {
            $sql = "SELECT * FROM `" . DB_PREFIX . "paynl_transactions` WHERE" .
                " orderId = '" . $this->db->escape($orderId) . "' AND " .
                " id = '" . $this->db->escape($transactionId) . "' LIMIT 1;";
        }

        $result = $this->db->query($sql);

        return $result->row;
    }

    /**
     * @param string $orderId
     * @return array
     */
    public function getTransactionFromOrderId($orderId)
    {
        $sql = "SELECT * FROM `" . DB_PREFIX . "paynl_transactions` WHERE orderId = '" . $this->db->escape($orderId) . "' LIMIT 1;";
        $result = $this->db->query($sql);

        return $result->row;
    }

    /**
     * Get The statusses of the order.
     * Because the order can have multiple transactions,
     * We have to check here if the order hasn't already been completed
     *
     * @param integer $orderId
     * @return array
     */
    public function getStatussesOfOrder($orderId)
    {
        $sql = "SELECT `status` FROM `" . DB_PREFIX . "paynl_transactions` WHERE orderId = '" . $this->db->escape($orderId) . "';";
        $result = $this->db->query($sql);

        $rows = $result->rows;
        $result = array();
        foreach ($rows as $row) {
            $result[] = $row['status'];
        }
        return $result;
    }

    /**
     * @param $transactionId
     * @param $status
     * @param $orderId
     * @return true|void
     * @throws Pay_Exception
     */
    public function updateTransactionStatus($transactionId, $status, $orderId = null)
    {
        if (!in_array($status, array(self::STATUS_CANCELED, self::STATUS_COMPLETE, self::STATUS_PENDING, self::STATUS_REFUNDED))) {
            throw new Pay_Exception('Invalid transaction status');
        }

        # Safety so processed orders cannot go to cancelled
        $transaction = $this->getTransaction($transactionId, $orderId);

        if (empty($transaction)) {
            throw new Pay_Exception('Transaction not found');
        }

        //Because an order can have multiple transactions, we have to look for the status complete in all transactions for this order.
        $orderStatusses = self::getStatussesOfOrder($transaction['orderId']);

        if (in_array(self::STATUS_COMPLETE, $orderStatusses) && $status != self::STATUS_COMPLETE) {
            if ($status != self::STATUS_REFUNDED) {
                die("TRUE|already paid");
            }
        }


        if ($transaction['status'] == $status) {
            //status is not changed
            return true;
        }

        $sql = "UPDATE `" . DB_PREFIX . "paynl_transactions` SET status = '$status' , last_update = UNIX_TIMESTAMP() WHERE id = '" . $this->db->escape($transactionId) . "'";

        return $this->db->query($sql);
    }

    /**
     * @param $orderId
     * @return string
     */
    private function getCustomerGroupId($orderId)
    {
        $sql = "SELECT `customer_group_id` FROM `" . DB_PREFIX . "order` WHERE order_Id = '" . $this->db->escape($orderId) . "';";
        $result = $this->db->query($sql);

        $rows = $result->rows;

        $result = '';
        foreach ($rows as $row) {
            $result = $row['customer_group_id'];
        }
        return $result;
    }

    /**
     * @param string $key
     * @param string $pm
     * @return string
     */
    private function getConfig($key, $pm)
    {
        return $this->config->get('payment_' . $pm . '_' . $key);
    }

    /**
     * @param string|boolean $address
     * @param string|boolean $orderAmount
     * @return boolean|array
     */
    public function getMethod($address = false, $orderAmount = false)
    {
        $pm = empty($this->_paymentMethodName) ? null : $this->_paymentMethodName;
        $config_status = $this->getConfig('status', $pm);
        $pmEnabled = !empty($config_status);
        if (!$pmEnabled) {
            return false;
        }

        $paymentOptions = $this->getPaymentOption($this->_paymentOptionId);
        $minOrderAmount = $this->getConfig('total', $pm);
        $maxOrderAmount = $this->getConfig('totalmax', $pm);
        $geozone = (int)$this->getConfig('geo_zone_id', $pm);
        $customerType = $this->getConfig('customer_type', $pm);

        if ($orderAmount >= 0) {
            if (!empty($minOrderAmount) && $orderAmount < $minOrderAmount) {
                return false;
            }
            if (!empty($maxOrderAmount) && $orderAmount > $maxOrderAmount) {
                return false;
            }
        }

        $strQuery = "SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . $geozone . "' AND country_id = '" . (int) $address['country_id'] . "' " .
          " AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')";
        $query = $this->db->query($strQuery);

        if (!empty($geozone) && $query->num_rows == 0) {
            return false;
        }

        $company = (isset($address['company'])) ? trim($address['company']) : '';

        if (
            ($customerType == 'private' && !empty($company)) ||
             ($customerType == 'business' && empty($company))
        ) {
            return false;
        }

        $icon = "";
        if ($this->config->get('payment_paynl_general_display_icon') != '') {
            $iconSize = $this->config->get('payment_paynl_general_display_icon') ;

            if (!empty($paymentOptions['brand_id'])) {
                $style = ' style="width:50px; height:50px;"';
                switch ($iconSize) {
                    case '20x20':
                        $style = ' style="width:20px; height:20px;"';
                        break;
                    case '25x25':
                        $style = ' style="width:25px; height:25px;"';
                        break;
                    case '50x50':
                        $style = ' style="width:50px; height:50px;"';
                        break;
                    case '75x75':
                        $style = ' style="width:75px; height:75px;"';
                        break;
                    case '100x100':
                        $style = ' style="width:100px; height:100px;"';
                        break;
                }
                $icon = "<img " . $style . " class='paynl_icon' src=\"/image/Pay/" . $paymentOptions['brand_id'] . ".png\"> ";
            }
        }

        return array(
          'code' => $pm,
          'title' => $icon . $this->getLabel(),
          'terms' => '',
          'sort_order' =>  $this->getConfig('sort_order', $pm));
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->config->get('payment_' . $this->_paymentMethodName . '_label');
    }

    /**
     * @param integer $orderId
     * @return boolean
     */
    public function isAlreadyPaid($orderId)
    {
        //Because an order can have multiple transactions, we have to look for the status complete in all transactions for this order.
        $orderStatusses = $this->getStatussesOfOrder($orderId);

        if (in_array(self::STATUS_COMPLETE, $orderStatusses)) {
            return true;
        }
        return false;
    }

    /**
     * @param $transactionId
     * @return string
     * @throws Pay_Api_Exception
     * @throws Pay_Exception
     */
    public function processTransaction($transactionId)
    {
        $this->load->model('setting/setting');
        $this->load->model('checkout/order');
        $settings = $this->model_setting_setting->getSetting('payment_' . $this->_paymentMethodName);
        $this->log('processTransaction ' . $transactionId . ' name: ' . $this->_paymentMethodName . print_r($settings, true));

        $transaction = $this->getTransaction($transactionId);
        $apiInfo = new Pay_Api_Info();
        $apiInfo->setApiToken($this->model_setting_setting->getSettingValue('payment_paynl_general_apitoken'));
        $apiInfo->setServiceId($this->model_setting_setting->getSettingValue('payment_paynl_general_serviceid'));

        if (!empty(trim($this->model_setting_setting->getSettingValue('payment_paynl_general_gateway')))) {
            $apiInfo->setApiBase(trim($this->model_setting_setting->getSettingValue('payment_paynl_general_gateway')));
        }

        $apiInfo->setTransactionId($transactionId);
        $result = $apiInfo->doRequest();

        $status = Pay_Helper::getStatus($result['paymentDetails']['state']);
        $orderStatusId = Pay_Helper::getOrderStatusId($result['paymentDetails']['state'], $settings, $this->_paymentMethodName);

        $this->log('pre ' . print_r(array($result['paymentDetails']['state'], $this->_paymentMethodName, $status, $orderStatusId), true));

        # Status update
        $this->updateTransactionStatus($transactionId, $status);
        $message = "Pay. Updated order to $status.";

        # Order update
        $order_info = $this->model_checkout_order->getOrder($transaction['orderId']);

        if ($this->_paymentOptionId != $result['paymentDetails']['paymentOptionId'] && $this->config->get('payment_paynl_general_follow_payment_method') !== '0') {
            $newPaymentMethod = $result['paymentDetails']['payment_profile_name'];
            $oldPaymentMethod = $order_info['payment_method'];
            $orderId = $transaction['orderId'];
            $followPaymentMessage = "Pay. Updated payment method from " . $oldPaymentMethod . " to " . $newPaymentMethod . ".";

            $order_info['customer_group_id'] = $this->getCustomerGroupId($orderId);
            $order_info['payment_method'] = $newPaymentMethod;

            if (!empty($order_info['customer_group_id'])) {
                $this->model_checkout_order->editOrder($orderId, $order_info);
                $this->log('addOrderHistory: ' . print_r(array($order_info['order_id'], $orderStatusId, $followPaymentMessage), true));
                $this->model_checkout_order->addOrderHistory($order_info['order_id'], $orderStatusId, $followPaymentMessage, false);
            }
        }

        if ($order_info['payment_code'] != $this->_paymentMethodName && $status == self::STATUS_CANCELED) {
            return 'Not cancelling because the last used method is not this method';
        }

        if ($order_info['order_status_id'] != $orderStatusId) {
            # Only update when status is changed
            $settingSendUpdates = $this->model_setting_setting->getSettingValue('payment_' . $this->_paymentMethodName . '_send_status_updates');
            $send_status_update = $settingSendUpdates == 1;
            if ($order_info['order_status_id'] == 0 && $status != self::STATUS_COMPLETE && !$send_status_update) {
                # not confirmed, only save when completed
                $this->log('No update, returning. Vars:' . print_r(array($order_info['order_status_id'], $status), true));
                return $status;
            }
            $this->log('addOrderHistory: ' . print_r(array($order_info['order_id'], $orderStatusId, $message, $send_status_update), true));
            $this->model_checkout_order->addOrderHistory($order_info['order_id'], $orderStatusId, $message, $send_status_update);
        } else {
            $this->log('Not updating  ' . $order_info['order_status_id'] . ' vs ' . $orderStatusId);
        }

        return $status;
    }

    public function updateOrderAfterWebhook($order_id, $payment_data, $shipping_data, $customer_data) {
        $order_query = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");

        if ($order_query->num_rows) {
            $query = "UPDATE `" . DB_PREFIX . "order` SET ";
            $fields = array();

            $fields[] = "payment_firstname = '" . $this->db->escape($payment_data['firstname']) . "'";
            $fields[] = "payment_lastname = '" . $this->db->escape($payment_data['lastname']) . "'";
            $fields[] = "payment_address_1 = '" . $this->db->escape($payment_data['address_1']) . "'";
            $fields[] = "payment_city = '" . $this->db->escape($payment_data['city']) . "'";
            $fields[] = "payment_postcode = '" . $this->db->escape($payment_data['postcode']) . "'";
            $fields[] = "payment_country = '" . $this->db->escape($payment_data['country']) . "'";
            $fields[] = "payment_method = '" . $this->db->escape($payment_data['method']) . "'";

            $fields[] = "shipping_firstname = '" . $this->db->escape($shipping_data['firstname']) . "'";
            $fields[] = "shipping_lastname = '" . $this->db->escape($shipping_data['lastname']) . "'";
            $fields[] = "shipping_address_1 = '" . $this->db->escape($shipping_data['address_1']) . "'";
            $fields[] = "shipping_city = '" . $this->db->escape($shipping_data['city']) . "'";
            $fields[] = "shipping_postcode = '" . $this->db->escape($shipping_data['postcode']) . "'";
            $fields[] = "shipping_country = '" . $this->db->escape($shipping_data['country']) . "'";

            if ($order_query->row['customer_id'] == 0) {
                $fields[] = "firstname = '" . $this->db->escape($customer_data['firstname']) . "'";
                $fields[] = "lastname = '" . $this->db->escape($customer_data['lastname']) . "'";
                $fields[] = "email = '" . $this->db->escape($customer_data['email']) . "'";
                $fields[] = "telephone = '" . $this->db->escape($customer_data['phone']) . "'";
            }

            $query .= implode(", ", $fields);
            $query .= " WHERE order_id = '" . (int)$order_id . "'";

            $this->db->query($query);

            return true;
        } else {
            return false;
        }
    }
}
