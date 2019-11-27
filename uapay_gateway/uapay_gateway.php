<?php
/*
Plugin Name: UAPAY Gateway
Description: Платежный шлюз "UAPAY" для сайтов на WordPress.
Version: 1.0
Lat Update: 10.10.2019
Author: UAPAY
Author URI: https://uapay.ua
*/

ini_set('display_errors', 1);

if (!defined('ABSPATH')) exit;

define('UAPAY_DIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', 'uapay_init', 0);

load_plugin_textdomain( 'uapay', false, basename(UAPAY_DIR) . '/languages' );

add_action( 'init', 'uapay_endpoint' );
add_action( 'pre_get_posts', 'uapay_listen_redirect' );

function uapay_endpoint() {
	add_rewrite_endpoint( 'uapay-redirect', EP_ROOT );
}

function uapay_listen_redirect( $query ) {
	if(($query->get('pagename') == 'uapay-redirect') || (strpos($_SERVER['REQUEST_URI'], 'uapay-redirect') !== false)) {
		get_header();
		(new WC_Gateway_Uapay)->generatePayment($_REQUEST['order_id']);
		get_footer();
		exit;
	}
}

function uapay_init()
{
	if (!class_exists('WC_Payment_Gateway')) return;

	class WC_Gateway_Uapay extends WC_Payment_Gateway
	{
		public function __construct()
		{
			require_once(__DIR__ . '/classes/UaPayApi.php');
			$this->id = 'uapay';
			$this->has_fields = false;
			$this->method_title = 'UAPAY';
			$this->method_description = __('UAPAY', 'uapay');
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');

			$this->language = $this->get_option('language');
			$this->paymenttime = $this->get_option('paymenttime');
			$this->payment_method = $this->get_option('payment_method');


			$this -> testMode = ($this->get_option('testMode') == 'yes')? 1 : 0;

			$this -> customRedirectUrl = trim($this->get_option('customRedirectUrl'));

			$this -> clientId = trim($this->get_option('clientId'));

			$this -> secretKey = trim($this->get_option('secretKey'));

			$this -> typeOperation = trim($this->get_option('typeOperation'));

			$this->icon = apply_filters('woocommerce_uapay_icon', plugin_dir_url(__FILE__) . '/logo.png');

			if (!$this->supportCurrencyUAPAY()) {
				$this->enabled = 'no';
			}

			// Actions
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

			// Payment listener/API hook
			add_action('woocommerce_api_wc_gateway_' . $this->id, [$this, 'checkUaPayResponse']);
		}

		function accessProtected($obj, $prop) {
			$reflection = new ReflectionClass($obj);
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			return $property->getValue($obj);
		}

		public function beforeSaveOrder(WC_Order $obj)
		{
			$order = new WC_Order($obj->get_id());

			if($this->id == $order->get_payment_method()) {
				$newStatus = $obj->get_status();
				$oldStatus = $order->get_status();

				if ($newStatus != $oldStatus) {
					if(!empty($order->get_meta('_uapay_partialRefunded'))){
						$order->add_meta_data('_uapay_partialRefunded', '', 1);
						$order->save_meta_data();
						return false;
					}

					if($oldStatus == 'pending' && $newStatus == 'failed'){
						$paymentId = $order->get_meta('_uapay_paymentId');
						if(!empty($paymentId)) {
							$order->add_meta_data('_uapay_invoiceFailed', 1, 1);
							$order->save_meta_data();
							return false;
						}
					}

					$invoiceFailed = empty($order->get_meta('_uapay_invoiceFailed')) ? 0 : 1;
					if ($invoiceFailed) {
						throw new Exception(__('Платеж UAPAY завершился Неудачей', 'uapay'));
					}

					$invoiceCanceled = empty($order->get_meta('_uapay_invoiceCanceled')) ? 0 : 1;
					if ($invoiceCanceled && $newStatus !== 'canceled') {
						throw new Exception(__('Платеж UAPAY уже был Отменен', 'uapay'));
					}

					$invoiceRefunded = empty($order->get_meta('_uapay_invoiceRefunded')) ? 0 : 1;
					if ($invoiceRefunded && $newStatus !== 'refunded') {
						throw new Exception(__('Платеж UAPAY уже был Возвращен', 'uapay'));
					}

					$this->statusChanged($obj);
				}
			}
		}

		public function statusChanged(WC_Order $order)
		{
			$status = $order->get_status();
			$amountHold = $order->get_meta('_uapay_amountHold');
			if (!empty($amountHold)) {
				$confirmed = empty($order->get_meta('_uapay_invoiceConfirmed')) ? 0 : 1;

				$uapay = $this->getUaPayInstance();
				if ($status == 'processing' && !$confirmed) {
					$uapay->setInvoiceId($order->get_meta('_uapay_invoiceId'));
					$uapay->setPaymentId($order->get_meta('_uapay_paymentId'));
					$result = $uapay->confirmPayment();
					self::writeLog($result, $status, 'callConfirm');
					if (!empty($result['status'])) {
						$order->add_order_note(__('UAPAY: Сумма за оплату заказа списана с клиента', 'uapay'));
						$order->add_meta_data('_uapay_invoiceConfirmed', 1, 1);
						$order->save_meta_data();
					}
					if ($result === false) {
						$msg = explode(':', $uapay->messageError);
						$msg = !empty($msg[1])? $msg[1] : $uapay->messageError;
						throw new Exception('UAPAY Error: ' . $msg);
					}
				}
//				if ($status == 'cancelled' && !$confirmed) {
				if ($status == 'cancelled') {
					$uapay->setInvoiceId($order->get_meta('_uapay_invoiceId'));
					$uapay->setPaymentId($order->get_meta('_uapay_paymentId'));
					$result = $uapay->cancelPayment();
					if (!empty($result['status'])) {
						$order->add_order_note(__('UAPAY: Платёж отменен', 'uapay'));
						$order->add_meta_data('_uapay_invoiceCanceled', 1, 1);
						$order->save_meta_data();
					}
					if ($result === false) {
						$msg = explode(':', $uapay->messageError);
						$msg = !empty($msg[1])? $msg[1] : $uapay->messageError;
						throw new Exception('UAPAY Error: ' . $msg);
					}
				}
//				if ($status == 'cancelled' && $confirmed) {
//					throw new Exception(__('Нельзя сменить на этот статус, так как платеж подтвержден', 'uapay'));
//				}
				if ($status == 'refunded') {
					$this->orderRefunded($order);
				}
			} else {
				throw new Exception(__('Нельзя сменить статус, пока платеж не подтвержден со стороны UAPAY', 'uapay'));
			}
		}

		public function order_refunded($orderId = 0)
		{
			$this->orderRefunded(new WC_Order($orderId));
		}

		public function orderRefunded(WC_Order $order)
		{
			if($this->id == $order->get_payment_method()) {
				$currentAction = current_action();
				$status = $order->get_status();

				$amountHold = $order->get_meta('_uapay_amountHold');
				if (!empty($amountHold)) {
					$total_refunded = $order->get_total_refunded();
					if($currentAction != 'woocommerce_order_refunded' && $total_refunded == 0) {
						$total_refunded = $order->get_remaining_refund_amount();
					}

					if($total_refunded > 0) {
						$uapay = $this->getUaPayInstance();

						$uapay->setInvoiceId($order->get_meta('_uapay_invoiceId'));
						$uapay->setPaymentId($order->get_meta('_uapay_paymentId'));

						$amountRefund = UapayApi::formattedAmount($total_refunded);
						$partialRefund = '';
						if ($amountRefund < (int)$amountHold) {
							$uapay->setDataAmount($total_refunded);
							$partialRefund = sprintf(__('Сума частичного возврата %s', 'uapay'), $total_refunded);
						}
						$result = $uapay->refundPayment();
						if (!empty($result['status'])) {
							$order->add_order_note(
								__('UAPAY: Платёж возвращен', 'uapay') . (!empty($partialRefund)? '. ' . $partialRefund : '')
							);
							$order->add_meta_data('_uapay_invoiceRefunded', 1, 1);
							$order->save_meta_data();
							if ($status != 'refunded') {
								$order->add_meta_data('_uapay_partialRefunded', 1, 1);
								$order->save_meta_data();
								$order->set_status('refunded');
								$order->save();
							}
						}

						self::writeLog($uapay->messageError, 'messageError', 'callRefund');
						if ($result === false) {
							$msg = explode(':', $uapay->messageError);
							$msg = !empty($msg[1])? $msg[1] : $uapay->messageError;
							throw new Exception('UAPAY Error: ' . $msg);
						}
					} else {
						throw new Exception(__('Error! Сума возврата должна быть больше 0', 'uapay'));
					}
				} else {
					throw new Exception(__('Нельзя сменить статус, пока платеж не подтвержден со стороны UAPAY', 'uapay'));
				}
			}
		}

		public function admin_options()
		{
			if ($this->supportCurrencyUAPAY()) { ?>
				<h3><?php _e('UAPAY', 'uapay'); ?></h3>
				<table class="form-table">
					<?php $this->generate_settings_html();?>
				</table>
				<?php
			} else { ?>
				<div class="inline error">
					<p>
						<strong><?php _e('Платежный шлюз отключен.', 'uapay'); ?></strong>: <?php _e('UAPAY не поддерживает валюту Вашего магазина!', 'uapay'); ?>
					</p>
				</div>
				<?php
			}
		}

		public function init_form_fields()
		{
			$this->form_fields = [
				'enabled' => [
					'title' => __('Вкл. / Выкл.', 'uapay'),
					'type' => 'checkbox',
					'label' => __('Включить', 'uapay'),
					'default' => 'yes'
				],
				'testMode' => [
					'title' => __('Вкл. / Выкл.', 'tranzzo'),
					'type' => 'checkbox',
					'label' => __('Тестовый режим', 'tranzzo'),
					'default' => 'yes'
				],
				'title' => [
					'title' => __('Заголовок', 'uapay'),
					'type' => 'text',
					'description' => __('Заголовок, который отображается на странице оформления заказа', 'uapay'),
					'default' => 'UAPAY',
					'desc_tip' => true,
				],
				'description' => [
					'title' => __('Описание', 'uapay'),
					'type' => 'textarea',
					'description' => __('Описание, которое отображается в процессе выбора формы оплаты', 'uapay'),
					'default' => __('Оплатить через платежную систему UAPAY', 'uapay'),
				],
				'customRedirectUrl' => [
					'title' => 'Redirect URL',
					'type' => 'text',
					'description' => __('URL переадресации клиента просле оплаты', 'uapay'),
				],
				'clientId' => [
					'title' => 'clientId',
					'type' => 'text',
					'description' => 'Id UAPAY',
				],
				'secretKey' => [
					'title' => 'secretKey',
					'type' => 'password',
					'description' => __('секретный ключ UAPAY', 'uapay'),
				],
				'typeOperation' => [
					'title' => 'typeOperation',
					'type' => 'select',
					'description' => __('Тип операции UAPAY', 'uapay'),
					'options' => [
						UaPayApi::OPERATION_PAY => __('Pay', 'uapay'),
						UaPayApi::OPERATION_HOLD => __('Hold', 'uapay'),
					],
					'default' => UaPayApi::OPERATION_PAY,
				],
			];
		}

		function supportCurrencyUAPAY()
		{
			return true;
		}

		function process_payment($order_id)
		{
			return [
				'result' => 'success',
				'redirect' => add_query_arg('order_id', $order_id, home_url('uapay-redirect'))
			];
		}

		public function generatePayment($order_id)
		{
			$order = new WC_Order($order_id);
			$data_order = $order->get_data();

			if(!empty($data_order)) {

				if(!empty($order->get_transaction_id())){
					?><p><? _e('Ваш заказ обрабатывается', 'uapay');?></p><?
				} else {
					$uapay = $this->getUaPayInstance();

					$callbackUrl = add_query_arg(['wc-api' => __CLASS__, 'order_id' => $order_id], home_url('/'));

					$redirectUrl = !empty($this->customRedirectUrl) ? $this->customRedirectUrl : $this->get_return_url($order);

					$uapay->setDataRedirectUrl($redirectUrl);
					$uapay->setDataCallbackUrl($callbackUrl);
					$uapay->setDataOrderId($order_id);
					$uapay->setDataAmount($data_order['total']);
					$uapay->setDataDescription("Order {$order_id}");
//					$uapay->setDataEmail($data_order['billing']['email']);
					$uapay->setDataReusability(0);

					$result = $uapay->createInvoice($this->typeOperation);

					if (!empty($result['paymentPageUrl'])) {

						$order->add_meta_data('_uapay_invoiceId', $result['id'], 1);
						$order->save_meta_data();

						$order->set_transaction_id($result['id']);
						$order->save();
						?>
						<p><? _e('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'uapay');?></p>
						<div class="btn-actions-uapay">
							<a class="button btn-primary btn-uapay" href="<?= $result['paymentPageUrl']; ?>"
							   target="_blank">
								<? _e('Оплатить', 'uapay'); ?>
							</a>
						</div>
						<style>
							.btn-actions-uapay {
								text-align: center;width: 100%;height: 50px;margin: 20px;
							}
							.btn-uapay {
								margin: 0 10px;background-color: rgb(175, 0, 2);border: rgb(175, 0, 2);
								border-radius: 7px;box-shadow: none;box-sizing: border-box;
								color: rgb(255, 255, 255);cursor: pointer;display: block;
								float: left;font-size: 14px;font-weight: 500;height: 40px;line-height: 40px;
								max-width: 100%;text-align: center;text-decoration-color: rgb(255, 255, 255);
								text-decoration-line: none;text-decoration-style: solid;transition-delay: 0s;
								transition-duration: 0.3s;transition-property: all;transition-timing-function: ease;
								width: 200px;
							}
						</style>
						<?
					}

					if ($result === false) {
						$order->add_order_note(__('UAPAY.ua сообщил об ошибке: ', 'uapay') . $uapay->messageError);
						$order->save();

						?>
						<p style="color: red; font-size: 20px">
							<? echo __('UAPAY.ua сообщил об ошибке: ', 'uapay') . $uapay->messageError; ?>
						</p>
						<?
					}
				}
			} else {
				wp_redirect(home_url('/'));
			}
		}

		public function checkUaPayResponse()
		{
			if(empty($_REQUEST['order_id'])) die('LOL! Bad Request!!!');

			$uapay = $this->getUaPayInstance();

			$order_id = (int)$_REQUEST['order_id'];
			$order = wc_get_order($order_id);

			$orderStatus = $order->get_status();

			if($orderStatus == 'processing'){
				die('Bad Request!!! Order completed!');
			}
			if($orderStatus == 'on-hold'){
				die('Bad Request!!! Order holded!');
			}

			$invoiceId = $order->get_meta('_uapay_invoiceId');

			$invoice = $uapay->getDataInvoice($invoiceId);
			$payment = $invoice['payments'][0];

			switch($payment['paymentStatus']){
				case UaPayApi::STATUS_FINISHED:
					$order->add_order_note(__('UAPAY: Заказ успешно оплачен', 'uapay'));
					$order->add_order_note(__('ID платежа(payment id): ', 'uapay') . $payment['paymentId']);
					$order->add_order_note(__('ID счета(invoice id): ', 'uapay') . $payment['invoiceId']);
					$order->add_meta_data('_uapay_paymentId', $payment['paymentId'], 1);
					$order->add_meta_data('_uapay_amountHold', $payment['amount'], 1);
					$order->add_meta_data('_uapay_invoiceConfirmed', 1, 1);
					$order->save_meta_data();

					$order->set_status('processing');
					$order->save();

					break;
				case UaPayApi::STATUS_HOLDED;
					if($payment['status'] == 'PAID') {
						$order->add_order_note(__('UAPAY: Сума для оплаты заказа зарезервирована', 'uapay'));
						$order->add_meta_data('_uapay_paymentId', $payment['paymentId'], 1);
						$order->add_meta_data('_uapay_amountHold', $payment['amount'], 1);
						$order->save_meta_data();
						$order->set_status('on-hold');
						$order->save();
					}
					break;
				case UaPayApi::STATUS_CANCELED;
				case UaPayApi::STATUS_REJECTED;
					$order->add_order_note(__('UAPAY: Заказ не оплачен', 'uapay'));
					$order->add_meta_data('_uapay_paymentId', $payment['paymentId'], 1);
					$order->save_meta_data();
					$order->update_status('failed');

					break;
			}

			if($invoice === false){
				$order->add_order_note(__('UAPAY.ua response error: ') . $uapay->messageError);
			}

			exit;
		}

		static function writeLog($data, $flag = '', $filename = 'info')
		{
			file_put_contents(__DIR__ . "/{$filename}.log", "\n\n" . date('H:i:s') . " - $flag \n" .
				(is_array($data)? json_encode($data, JSON_PRETTY_PRINT):$data)
				, FILE_APPEND);
		}

		public function getUaPayInstance()
		{
			$obj = new UaPayApi($this->clientId, $this->secretKey);
			$obj->testMode($this->testMode);
			return $obj;
		}
	}

	function woocommerce_add_uapay_gateway($methods)
	{
		$methods[] = 'WC_Gateway_Uapay';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_uapay_gateway');

	add_action( 'woocommerce_before_order_object_save', [(new WC_Gateway_Uapay()), 'beforeSaveOrder']);
	add_action( 'woocommerce_order_refunded', [(new WC_Gateway_Uapay()), 'order_refunded'], 1, 4);
}