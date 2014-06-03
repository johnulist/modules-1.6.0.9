<?php
class OrderOpcController extends OrderOpcControllerCore {
    public function initContent() {
	parent::initContent();
	
	if ($this->_legal && $tpl = $this->_legal->getThemeOverride('order-opc')) {
	    $this->setTemplate($tpl);
	}
    }
    
    protected function _assignCarrier() {
	parent::_assignCarrier();
	
	if ( ! $this->isLogged) {
	    $this->context->smarty->assign('PS_EU_PAYMENT_API', Configuration::get('PS_EU_PAYMENT_API') ? true : false);
	}
	
    }
    
    protected function _getCarrierList()
    {
	    $address_delivery = new Address($this->context->cart->id_address_delivery);
	    
	    $cms = new CMS(Configuration::get('PS_CONDITIONS_CMS_ID'), $this->context->language->id);
	    $link_conditions = $this->context->link->getCMSLink($cms, $cms->link_rewrite, Configuration::get('PS_SSL_ENABLED'));
	    if (!strpos($link_conditions, '?'))
		    $link_conditions .= '?content_only=1';
	    else
		    $link_conditions .= '&content_only=1';
	    
	    $carriers = $this->context->cart->simulateCarriersOutput();
	    $delivery_option = $this->context->cart->getDeliveryOption(null, false, false);

	    $wrapping_fees = $this->context->cart->getGiftWrappingPrice(false);
	    $wrapping_fees_tax_inc = $wrapping_fees = $this->context->cart->getGiftWrappingPrice();
	    $oldMessage = Message::getMessageByCartId((int)($this->context->cart->id));
	    
	    $free_shipping = false;
	    foreach ($this->context->cart->getCartRules() as $rule)
	    {
		    if ($rule['free_shipping'] && !$rule['carrier_restriction'])
		    {
			    $free_shipping = true;
			    break;
		    }			
	    }
	    
	    $this->context->smarty->assign('isVirtualCart', $this->context->cart->isVirtualCart());

	    $vars = array(
		    'free_shipping' => $free_shipping,
		    'checkedTOS' => (int)($this->context->cookie->checkedTOS),
		    'recyclablePackAllowed' => (int)(Configuration::get('PS_RECYCLABLE_PACK')),
		    'giftAllowed' => (int)(Configuration::get('PS_GIFT_WRAPPING')),
		    'cms_id' => (int)(Configuration::get('PS_CONDITIONS_CMS_ID')),
		    'conditions' => (int)(Configuration::get('PS_CONDITIONS')),
		    'link_conditions' => $link_conditions,
		    'recyclable' => (int)($this->context->cart->recyclable),
		    'gift_wrapping_price' => (float)$wrapping_fees,
		    'total_wrapping_cost' => Tools::convertPrice($wrapping_fees_tax_inc, $this->context->currency),
		    'total_wrapping_tax_exc_cost' => Tools::convertPrice($wrapping_fees, $this->context->currency),
		    'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
		    'carriers' => $carriers,
		    'checked' => $this->context->cart->simulateCarrierSelectedOutput(),
		    'delivery_option' => $delivery_option,
		    'address_collection' => $this->context->cart->getAddressCollection(),
		    'opc' => true,
		    'oldMessage' => isset($oldMessage['message'])? $oldMessage['message'] : '',
		    'PS_EU_PAYMENT_API' => Configuration::get('PS_EU_PAYMENT_API'),
		    'HOOK_BEFORECARRIER' => Hook::exec('displayBeforeCarrier', array(
			    'carriers' => $carriers,
			    'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
			    'delivery_option' => $delivery_option
		    ))
	    );
	    
	    Cart::addExtraCarriers($vars);
	    
	    $this->context->smarty->assign($vars);

	    if (!Address::isCountryActiveById((int)($this->context->cart->id_address_delivery)) && $this->context->cart->id_address_delivery != 0)
		    $this->errors[] = Tools::displayError('This address is not in a valid area.');
	    elseif ((!Validate::isLoadedObject($address_delivery) || $address_delivery->deleted) && $this->context->cart->id_address_delivery != 0)
		    $this->errors[] = Tools::displayError('This address is invalid.');
	    else
	    {
		    $result = array(
			    'HOOK_BEFORECARRIER' => Hook::exec('displayBeforeCarrier', array(
				    'carriers' => $carriers,
				    'delivery_option_list' => $this->context->cart->getDeliveryOptionList(),
				    'delivery_option' => $this->context->cart->getDeliveryOption(null, true)
			    ))
		    );
		    
		    if ($this->_legal && $tpl = $this->_legal->getThemeOverride('order-carrier')) {
			$result['carrier_block'] = $this->context->smarty->fetch($tpl);
		    }
		    else {
			$result['carrier_block'] = $this->context->smarty->fetch(_PS_THEME_DIR_.'order-carrier.tpl');
		    }

		    Cart::addExtraCarriers($result);
		    return $result;
	    }
	    if (count($this->errors)) {
		if ($this->_legal && $tpl = $this->_legal->getThemeOverride('order-carrier')) {
		    $carrier_tpl = $this->context->smarty->fetch($tpl);
		}
		else {
		    $carrier_tpl = $this->context->smarty->fetch(_PS_THEME_DIR_.'order-carrier.tpl');
		}
		
		return array(
		    'hasError' => true,
		    'errors' => $this->errors,
		    'carrier_block' => $carrier_tpl
		);
	    }
    }
    
    protected function _assignPayment() {
	if (Configuration::get('PS_EU_PAYMENT_API')) {
	    return ParentOrderController::_assignPayment();
	}
	else {
	    return parent::_assignPayment();
	}
    }
    
    protected function _getPaymentMethods() {
	    if (!$this->isLogged)
		    return '<p class="warning">'.Tools::displayError('Please sign in to see payment methods.').'</p>';
	    if ($this->context->cart->OrderExists())
		    return '<p class="warning">'.Tools::displayError('Error: This order has already been validated.').'</p>';
	    if (!$this->context->cart->id_customer || !Customer::customerIdExistsStatic($this->context->cart->id_customer) || Customer::isBanned($this->context->cart->id_customer))
		    return '<p class="warning">'.Tools::displayError('Error: No customer.').'</p>';
	    $address_delivery = new Address($this->context->cart->id_address_delivery);
	    $address_invoice = ($this->context->cart->id_address_delivery == $this->context->cart->id_address_invoice ? $address_delivery : new Address($this->context->cart->id_address_invoice));
	    if (!$this->context->cart->id_address_delivery || !$this->context->cart->id_address_invoice || !Validate::isLoadedObject($address_delivery) || !Validate::isLoadedObject($address_invoice) || $address_invoice->deleted || $address_delivery->deleted)
		    return '<p class="warning">'.Tools::displayError('Error: Please select an address.').'</p>';
	    if (count($this->context->cart->getDeliveryOptionList()) == 0 && !$this->context->cart->isVirtualCart())
	    {
		    if ($this->context->cart->isMultiAddressDelivery())
			    return '<p class="warning">'.Tools::displayError('Error: None of your chosen carriers deliver to some of  the addresses you\'ve selected.').'</p>';
		    else
			    return '<p class="warning">'.Tools::displayError('Error: None of your chosen carriers deliver to the address you\'ve selected.').'</p>';
	    }
	    if (!$this->context->cart->getDeliveryOption(null, false) && !$this->context->cart->isVirtualCart())
		    return '<p class="warning">'.Tools::displayError('Error: Please choose a carrier.').'</p>';
	    if (!$this->context->cart->id_currency)
		    return '<p class="warning">'.Tools::displayError('Error: No currency has been selected.').'</p>';
	    if (!$this->context->cookie->checkedTOS && Configuration::get('PS_CONDITIONS') && !Configuration::get('PS_EU_PAYMENT_API'))
		    return '<p class="warning">'.Tools::displayError('Please accept the Terms of Service.').'</p>';
	    
	    /* If some products have disappear */
	    if (!$this->context->cart->checkQuantities())
		    return '<p class="warning">'.Tools::displayError('An item in your cart is no longer available. You cannot proceed with your order.').'</p>';

	    /* Check minimal amount */
	    $currency = Currency::getCurrency((int)$this->context->cart->id_currency);

	    $minimal_purchase = Tools::convertPrice((float)Configuration::get('PS_PURCHASE_MINIMUM'), $currency);
	    if ($this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS) < $minimal_purchase)
		    return '<p class="warning">'.sprintf(
			    Tools::displayError('A minimum purchase total of %1s (tax excl.) is required in order to validate your order, current purchase total is %2s (tax excl.).'),
			    Tools::displayPrice($minimal_purchase, $currency), Tools::displayPrice($this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS), $currency)
		    ).'</p>';

	    /* Bypass payment step if total is 0 */
	    if ($this->context->cart->getOrderTotal() <= 0)
		    return '<p class="center"><button class="button btn btn-default button-medium" name="confirmOrder" id="confirmOrder" onclick="confirmFreeOrder();" type="submit"> <span>'.Tools::displayError('I confirm my order.').'</span></button></p>';

	    if (Configuration::get('PS_EU_PAYMENT_API'))
		    $return = $this->_getEuPaymentOptionsHTML();
	    else
		    $return = Hook::exec('displayPayment');
		    
	    if (!$return)
		    return '<p class="warning">'.Tools::displayError('No payment method is available for use at this time. ').'</p>';
	    return $return;
    }
}