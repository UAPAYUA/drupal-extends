<?php

namespace Drupal\uc_uapay\Plugin\Ubercart\PaymentMethod;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;
//use Drupal\uc_uapay\UaPayPaymentTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\uc_uapay\UaPayApi;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\ExpressPaymentMethodPluginInterface;
use GuzzleHttp\Exception\TransferException;

/**
 * Defines the UaPay payment method.
 *
 * @UbercartPaymentMethod(
 *   id = "uapay",
 *   name = @Translation("UaPay"),
 * )
 */
class UaPay extends PaymentMethodPluginBase implements OffsitePaymentMethodPluginInterface
{

  /**
   * {@inheritdoc}
   */
  public function getDisplayLabel($label)
  {
    $build['#attached']['library'][] = 'uc_uapay/uapay';
    $build['label'] = array(
      '#plain_text' => $label,
      '#suffix' => '<br />',
    );
    $build['image'] = array(
      '#theme' => 'image',
      '#uri' => drupal_get_path('module', 'uc_uapay') . '/images/uapay.png',
      '#alt' => $this->t('UaPay'),
      '#attributes' => array('class' => array('uc-uapay-logo')),
    );

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'CLIENT_ID' => '',
      'SECRET_KEY' => '',
      'TYPE_PAYMENT' => FALSE,
      'TEST_MODE' => FALSE,
      'REDIRECT_URL' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form['TEST_MODE'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Тестовый режим'),
      '#description' => $this->t('Платежы будут совершенны в тестовом режиме.'),
      '#default_value' => $this->configuration['TEST_MODE'],
    );
    $form['CLIENT_ID'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('CLIENT_ID'),
      '#default_value' => $this->configuration['CLIENT_ID'],
      '#size' => 40,
    );
    $form['SECRET_KEY'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('SECRET_KEY'),
      '#default_value' => $this->configuration['SECRET_KEY'],
      '#size' => 40,
    );
    $form['TYPE_PAYMENT'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('TYPE_PAYMENT'),
      '#description' => $this->t('Платежы будут совершенны с предавторизацией.'),
      '#default_value' => $this->configuration['TYPE_PAYMENT'],
    );
//    $form['REDIRECT_URL'] = array(
//      '#type' => 'textfield',
//      '#title' => $this->t('REDIRECT_URL'),
//      '#default_value' => $this->configuration['REDIRECT_URL'],
//      '#size' => 200,
//    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    $this->configuration['TEST_MODE'] = $form_state->getValue(['settings', 'TEST_MODE']);
    $this->configuration['CLIENT_ID'] = $form_state->getValue(['settings', 'CLIENT_ID']);
    $this->configuration['SECRET_KEY'] = $form_state->getValue(['settings', 'SECRET_KEY']);
    $this->configuration['TYPE_PAYMENT'] = $form_state->getValue(['settings', 'TYPE_PAYMENT']);
    $this->configuration['REDIRECT_URL'] = $form_state->getValue(['settings', 'REDIRECT_URL']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, OrderInterface $order = NULL)
  {
    $order_id = $order->id();
    $amount = $order->getTotal();
    UaPayApi::writeLog(array($order), '$order', '');
    UaPayApi::writeLog($order_id, '$order_id', '');
    UaPayApi::writeLog($amount, '$amount', '');

    $uapay = new UaPayApi($this->configuration);
    $uapay->testMode();
    $uapay->setDataCallbackUrl(Url::fromRoute('uc_uapay.notification', ['uc_order' => $order->id()])->setAbsolute()->toString());
    $uapay->setDataRedirectUrl(Url::fromRoute('uc_uapay.complete', ['uc_order' => $order->id()])->setAbsolute()->toString());
    $uapay->setDataOrderId(strval($order_id));
    $uapay->setDataAmount($amount);
    $uapay->setDataDescription("Order #{$order_id}");
//    $uapay->setDataEmail(!empty($order_info['email']) ? $order_info['email'] : '');
    $uapay->setDataReusability(0);

    $result = $uapay->createInvoice();

    if (!empty($result['paymentPageUrl'])) {

      $query = \Drupal::database()->select('uc_uapay_comments', 'ufd');
      $query->addField('ufd', 'comment_id');
      $query->condition('ufd.order_id', $order_id);
      $comment_id = $query->execute()->fetchField();

      if (!empty($comment_id)) {
        \Drupal::database()->update('uc_uapay_comments')
          ->fields(array(
            'amount' => $amount,
            'invoiceId' => $result['id'],
            'typeOperation' => $uapay->getTypeOperation(),
          ))
          ->condition('order_id', $order_id)
          ->execute();
      } else {
        \Drupal::database()->insert('uc_uapay_comments')
          ->fields([
            'order_id',
            'amount',
            'invoiceId',
            'typeOperation',
          ])
          ->values(array(
            $order_id,
            $amount,
            $result['id'],
            $uapay->getTypeOperation()
          ))
          ->execute();
      }

      $form['#action'] = $result['paymentPageUrl'];
      $form['#method'] = 'get';
      $form['actions'] = array('#type' => 'actions');
      $form['actions']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Submit order'),
      );
    }
    return $form;
  }
}

