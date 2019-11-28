<?php


namespace Drupal\uc_uapay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_order\OrderInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\uc_uapay\UaPayApi;


class UaPayController extends ControllerBase
{

  public function complete(OrderInterface $uc_order)
  {
    UaPayApi::writeLog($uc_order->id() . ' complete', '', '');
    $session = \Drupal::service('session');

    $order = Order::load($uc_order->id());

    $query = \Drupal::database()->select('uc_uapay_comments', 'ufd');
    $query->fields('ufd', array('invoiceId', 'typeOperation', 'amount'));
    $query->condition('ufd.order_id', $uc_order->id());
    $uapay_payment_info = $query->execute()->fetchAssoc();

    $invoiceId = $uapay_payment_info['invoiceId'];

    $plugin = \Drupal::service('plugin.manager.uc_payment.method')
      ->createFromOrder($uc_order);

    $configuration = $plugin->getConfiguration();

    $uapay = new UapayApi($configuration);
    $uapay->testMode();
    $invoice = $uapay->getDataInvoice($invoiceId);
    $payment = $invoice['payments'][0];
    UaPayApi::writeLog($uapay->messageError, 'messageError', '');
    UaPayApi::writeLog($uapay->messageErrorCode, 'messageErrorCode', '');
    UaPayApi::writeLog($uapay_payment_info, '$uapay_payment_info', '');
    UaPayApi::writeLog($payment, 'payment', '');
    UaPayApi::writeLog($payment['paymentStatus'], 'paymentStatus', '');
    UaPayApi::writeLog($payment['status'], 'status', '');
    UaPayApi::writeLog($order->getStateId(), 'getStateId', '');
    UaPayApi::writeLog($uapay_payment_info['typeOperation'], 'typeOperation', '');

    switch ($uapay_payment_info['typeOperation']) {
      case UaPayApi::STATUS_FINISHED:
        if ($payment['status'] == 'FINISHED') {
          UaPayApi::writeLog('STATUS_FINISHED', '', '');

          $session->set('uc_checkout_complete_' . $uc_order->id(), TRUE);
          return $this->redirect('uc_cart.checkout_complete');
        }
        break;
      case UaPayApi::STATUS_HOLDED:
        if ($payment['status'] == 'PAID' && $order->getStateId() != 'processing') {
          UaPayApi::writeLog('STATUS_HOLDED status=PAID', '', '');

          $session->set('uc_checkout_complete_' . $uc_order->id(), TRUE);

          return $this->redirect('uc_cart.checkout_complete');
        }
        break;
      case UaPayApi::STATUS_NEED_CONFIRM:
      case UaPayApi::STATUS_CANCELED:
      case UaPayApi::STATUS_REVERSED:
      case UaPayApi::STATUS_REJECTED:
        UaPayApi::writeLog($uapay_payment_info['typeOperation'], 'default status ', '');
        break;
    }
    return $this->redirect('uc_cart.cart');
  }


  public function capture($order_id)
  {
    $order = Order::load($order_id);
    // Get current order payment method.
    $plugin = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);
    $configuration = $plugin->getConfiguration();

    $query = \Drupal::database()->select('uc_uapay_comments', 'ufd');
    $query->fields('ufd', array('invoiceId', 'paymentId', 'typeOperation', 'amount'));
    $query->condition('ufd.order_id', $order_id);
    $uapay_payment_info = $query->execute()->fetchAssoc();

    UaPayApi::writeLog(array($uapay_payment_info), 'capture $uapay_payment_info', '');

    if (!empty($uapay_payment_info) && !empty($uapay_payment_info['paymentId'])) {
      $uapay = new UaPayApi($configuration);
      $uapay->testMode();
      $invoice = $uapay->getDataInvoice($uapay_payment_info['invoiceId']);
      $payment = $invoice['payments'][0];

      $uapay->setInvoiceId($uapay_payment_info['invoiceId']);
      $uapay->setPaymentId($uapay_payment_info['paymentId']);

      UaPayApi::writeLog($payment['paymentStatus'], 'paymentStatus', '');

      $result = $uapay->completeInvoice();
      if (!empty($result['status'])) {
        UaPayApi::writeLog('ok', '', '');

        \Drupal::database()->update('uc_uapay_comments')
          ->fields(array(
            'typeOperation' => UaPayApi::STATUS_FINISHED,
          ))
          ->condition('order_id', $order_id)
          ->execute();

        uc_order_comment_save($order->id(), 1, t('@message: @amount', ['@message' => 'Оплата через UaPay', '@amount' => uc_currency_format($order->getTotal())]), 'admin');
        uc_order_comment_save($order->id(), 1, t('@message: @amount', ['@message' => 'Оплата через UaPay', '@amount' => uc_currency_format($order->getTotal())]), 'order', 'payment_received');
      } else {
        UaPayApi::writeLog($uapay->messageError, 'Error! ', '');
        UaPayApi::writeLog($uapay->messageErrorCode, 'messageErrorCode! ', '');

        uc_order_comment_save($order->id(), 1, t('Error! @mes', ['@mes' => $uapay->messageError]), 'admin');
      }
    } else {
      UaPayApi::writeLog('else', '', '');
      \Drupal::logger('uc_payment')->warning(t('UaPay payment was not successful with payment id. The payment id has not found in order @order_id.', ['@order_id' => $order->id()]));
    }
  }

  public function notification(OrderInterface $uc_order)
  {
    UaPayApi::writeLog('notification ', '', '');

    $session = \Drupal::service('session');
    \Drupal::logger('uc_uapay')->notice('Был сделан заказ @order_id на сайте с помощью UaPay.', ['@order_id' => $uc_order->id()]);

    $order = Order::load($uc_order->id());

    if (!$order || $order->getStateId() != 'in_checkout') {
      return ['#plain_text' => $this->t('Произошла ошибка во время оплаты. Пожалуйста, свяжитесь с нами, чтобы убедиться, что ваш заказ оплачен.')];
    }

    $query = \Drupal::database()->select('uc_uapay_comments', 'ufd');
    $query->fields('ufd', array('invoiceId', 'typeOperation', 'amount'));
    $query->condition('ufd.order_id', $uc_order->id());
    $uapay_payment_info = $query->execute()->fetchAssoc();

    UaPayApi::writeLog(array($uapay_payment_info), '$uapay_payment_info', '');

    $invoiceId = $uapay_payment_info['invoiceId'];

    $plugin = \Drupal::service('plugin.manager.uc_payment.method')
      ->createFromOrder($uc_order);

    $configuration = $plugin->getConfiguration();

    $uapay = new UapayApi($configuration);

    $uapay->testMode();
    $invoice = $uapay->getDataInvoice($invoiceId);
    $payment = $invoice['payments'][0];
    $amount = $uapay_payment_info['amount'];

    switch ($payment['paymentStatus']) {
      case UaPayApi::STATUS_FINISHED:
        if ($payment['status'] == 'FINISHED') {
          UaPayApi::writeLog('STATUS_FINISHED', '', '');

          \Drupal::database()->update('uc_uapay_comments')
            ->fields(array(
              'amount' => $amount,
              'invoiceId' => $invoiceId,
              'paymentId' => $payment['paymentId'],
              'typeOperation' => UaPayApi::STATUS_FINISHED,//UaPayApi::OPERATION_PAY,
            ))
            ->condition('order_id', $uc_order->id())
            ->execute();

          $comment = $this->t('Платеж UaPay был проведен методом @method, номер заказа: @txn_id', ['@method' => UaPayApi::OPERATION_PAY, '@txn_id' => $uc_order->id()]);
          uc_payment_enter($uc_order->id(), 'uapay', $amount, $uc_order->getOwnerId(), NULL, $comment);
          $uc_order->setStatusId('payment_received')->save();

          $this->messenger()->addStatus($this->t('Ваш заказ был обработан с помощью UaPay'));
          uc_order_comment_save($uc_order->id(), 1, $this->t('Оплата через UaPay'), 'admin');
          uc_order_comment_save($uc_order->id(), 1, $this->t('Оплата через UaPay'), 'order', 'payment_received');
        }
        break;
      case UaPayApi::STATUS_HOLDED:
        if ($payment['status'] == 'PAID') {
          UaPayApi::writeLog('STATUS_HOLDED status=PAID', '', '');
          UaPayApi::writeLog($order->getStateId(), 'getStateId ', '');
          UaPayApi::writeLog('STATUS_HOLDED_1 order_status_id !=in_checkout', '', '');

          \Drupal::database()->update('uc_uapay_comments')
            ->fields(array(
              'amount' => $amount,
              'invoiceId' => $invoiceId,
              'paymentId' => $payment['paymentId'], //+
              'typeOperation' => UaPayApi::STATUS_HOLDED,
            ))
            ->condition('order_id', $uc_order->id())
            ->execute();

          $comment = $this->t('Платеж UaPay был проведен методом @method, номер заказа: @txn_id', ['@method' => UaPayApi::OPERATION_HOLD, '@txn_id' => $uc_order->id()]);
          uc_payment_enter($uc_order->id(), 'uapay', $amount, $uc_order->getOwnerId(), NULL, $comment);
          $uc_order->setStatusId('processing')->save();

          $this->messenger()->addStatus($this->t('Ваш заказ был обработан с помощью UaPay'));
          uc_order_comment_save($uc_order->id(), 1, $this->t('Заказ оплачен с предавторизацией через UaPay. Для зачисление измените статус на "Payment received"'), 'admin');
          uc_order_comment_save($uc_order->id(), 1, $this->t('Оплата с предавторизацией через UaPay'), 'order', 'processing');
        }
        break;
      default:
        UaPayApi::writeLog($payment['paymentStatus'], 'default paymentStatus', '');
        break;
    }
    return new Response();
  }
}
