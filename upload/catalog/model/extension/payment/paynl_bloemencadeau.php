<?php

$dir = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
$autoload = $dir . '/Pay/Autoload.php';

require_once $autoload;

class ModelExtensionPaymentPaynlBloemencadeau extends Pay_Model
{
    protected $_paymentOptionId = 2607;
    protected $_paymentMethodName = 'paynl_bloemencadeau';
}
