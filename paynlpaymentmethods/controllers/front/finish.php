<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
class PaynlPaymentMethodsFinishModuleFrontController extends ModuleFrontController
{
    const METHOD_OVERBOEKING = 136;
    const METHOD_SOFORT = 556;

    private $order = null;
    private $payOrderId = null;
    private $orderStatusId = null;
    private $paymentSessionId = null;
  /**
   * @see FrontController::postProcess()
   */
    public function postProcess()
    {
      $transactionId = $_REQUEST['orderId'];

      $iAttempt = Tools::getValue('attempt');

      $bValidationDelay = Configuration::get('PAYNL_VALIDATION_DELAY') == 1;

      $this->payOrderId = $transactionId;
      $this->orderStatusId = Tools::getValue('orderStatusId');
      $this->paymentSessionId = Tools::getValue('paymentSessionId');

      /**
       * @var $module PaynlPaymentMethods
       */
      $module = $this->module;

        try {
            $transaction = $module->getTransaction($transactionId);
            $transactionData = $transaction->getData();
            $ppid = !empty($transactionData['paymentDetails']['paymentOptionId']) ? $transactionData['paymentDetails']['paymentOptionId'] : null;
            $stateName = !empty($transactionData['paymentDetails']['stateName']) ? $transactionData['paymentDetails']['stateName'] : 'unknown';
        } catch (Exception $e) {
            $module->payLog('finishPostProcess', 'Could not retrieve transaction', null, $transactionId);
            return;
        }

        $module->payLog('finishPostProcess', 'Returning to webshop. Method: ' . $transaction->getPaymentMethodName() . '. Status: ' . $stateName , $transaction->getOrderNumber(), $transactionId);

      if ($transaction->isPaid() || $transaction->isPending() || $transaction->isBeingVerified() || $transaction->isAuthorized()) {
            // naar success
            /**
             * @var $cart CartCore
             */
            $cart = $this->context->cart;

            /**
             * @var $customer CustomerCore
             */
            $customer = new Customer($cart->id_customer);

            $slow = '';

            if (!$transaction->isPaid()) {
                $slow = '&slowvalidation=1';
                $iTotalAttempts = in_array($ppid, array(
                  self::METHOD_OVERBOEKING,
                  self::METHOD_SOFORT)
                ) ? 1 : 20;

              if($bValidationDelay == 1 && $iAttempt < $iTotalAttempts) {
                return;
              }
            }

            unset($this->context->cart);
            unset($this->context->cookie->id_cart);

            $cartId = $transaction->getOrderNumber();
            $orderId = Order::getIdByCartId($cartId);

            $this->order = $orderId;

            Tools::redirect('index.php?controller=order-confirmation'.$slow.'&id_cart=' . $cartId . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);

      } else {
        # Delete old payment fee
        $this->context->cart->deleteProduct(Configuration::get('PAYNL_FEE_PRODUCT_ID'), 0);

          if ($this->orderStatusId == '-63') {
              $this->errors[] = $this->module->l('The payment has been denied', 'finish');
              $this->redirectWithNotifications('index.php?controller=order&step=1');
          } elseif ($transaction->isCanceled()) {
              $this->errors[] = $this->module->l('The payment has been canceled', 'finish');
              $this->redirectWithNotifications('index.php?controller=order&step=1');

          } else {
              # To checkout
              Tools::redirect('index.php?controller=order&step=1');
          }


      }

  }

  public function initContent()
  {
    $iAttempt = Tools::getValue('attempt');

    if (empty($iAttempt)) {
      $iAttempt = 0;
    }

    $iAttempt += 1;
    $url =  'module/paynlpaymentmethods/finish?orderId=' . $this->payOrderId .
      '&orderStatusId=' . $this->orderStatusId .
      '&paymentSessionId=' . $this->paymentSessionId . '&utm_nooverride=1&attempt=' . $iAttempt;

    $this->context->smarty->assign(array('order' => $this->payOrderId, 'extendUrl' => $url));
    $this->setTemplate('module:paynlpaymentmethods/views/templates/front/waiting.tpl');
  }

}
