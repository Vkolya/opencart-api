<?php

require_once(DIR_SYSTEM . 'engine/apiController.php');
require_once(DIR_SYSTEM.'helper/api.php');

class ControllerRestCart extends apiController
{
    public function index()
    {
        $this->checkToken();

        $this->load->language('api/cart');

        $result = array();

        switch ($this->request->server['REQUEST_METHOD']) {
            case 'POST' :
                $result = $this->addProductToCart();
                break;
            case 'PUT' :
                $data = get_input_stream_data();
                $result = $this->updateCartQuantity($data);
                break;
            case 'GET':
                $result = $this->products();
                break;
            case 'DELETE':
                $data = get_input_stream_data();
                $result = $this->removeProductFromCart($data);
                break;
        }

        $this->sendResponse($result);
    }

    private function addProductToCart()
    {
        if (isset($this->request->post['product_id'])) {

            $this->load->model('catalog/product');

            $product_info = $this->model_catalog_product->getProduct($this->request->post['product_id']);

            if ($product_info) {
                if (isset($this->request->post['quantity'])) {
                    $quantity = $this->request->post['quantity'];
                } else {
                    $quantity = 1;
                }

                if (isset($this->request->post['option'])) {
                    $option = array_filter($this->request->post['option']);
                } else {
                    $option = array();
                }

                $product_options = $this->model_catalog_product->getProductOptions($this->request->post['product_id']);

                foreach ($product_options as $product_option) {
                    if ($product_option['required'] && empty($option[$product_option['product_option_id']])) {
                        $json['error']['option'][$product_option['product_option_id']] = sprintf($this->language->get('error_required'), $product_option['name']);
                    }
                }

                if (!isset($json['error']['option'])) {
                    $this->cart->add($this->request->post['product_id'], $quantity, $option);

                    $json['success'] = $this->language->get('text_success');

                    unset($this->session->data['shipping_method']);
                    unset($this->session->data['shipping_methods']);
                    unset($this->session->data['payment_method']);
                    unset($this->session->data['payment_methods']);
                }
            } else {
                $json['error'][] = "Product not found";
                $this->statusCode = 400;
            }
            return $json;
        }
    }

    private function updateCartQuantity($data)
    {
        $this->load->language('api/cart');

        $json = array();


        if (isset($data['quantity']) && isset($data['key'])) {
            $this->cart->update($data['key'], $data['quantity']);

            $json['success'] = $this->language->get('text_success');

            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['reward']);
        } else {
            $json['error'][] = "Quantity and key parameters are required";
            $this->statusCode = 400;
        }

        return $json;
    }

    private function removeProductFromCart($data)
    {
        $this->load->language('api/cart');

        $json = array();

        // Remove
        if (isset($data['key'])) {
            $this->cart->remove($data['key']);

            unset($this->session->data['vouchers'][$data['key']]);

            $json['success'] = $this->language->get('text_success');

            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['reward']);
        } else {
            $this->statusCode = 400;
            $json['error'][] = "Key parameter are required";
        }


        return $json;
    }

    private function products()
    {
        $this->load->language('api/cart');

        $json = array();

        // Stock
        if (!$this->cart->hasStock() && (!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning'))) {
            $json['error']['stock'] = $this->language->get('error_stock');
        }

        // Products
        $json['products'] = array();

        $products = $this->cart->getProducts();

        foreach ($products as $product) {
            $product_total = 0;

            foreach ($products as $product_2) {
                if ($product_2['product_id'] == $product['product_id']) {
                    $product_total += $product_2['quantity'];
                }
            }

            if ($product['minimum'] > $product_total) {
                $json['error']['minimum'][] = sprintf($this->language->get('error_minimum'), $product['name'], $product['minimum']);
            }

            $option_data = array();

            foreach ($product['option'] as $option) {
                $option_data[] = array(
                    'product_option_id' => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'name' => $option['name'],
                    'value' => $option['value'],
                    'type' => $option['type']
                );
            }

            $json['products'][] = array(
                'cart_id' => $product['cart_id'],
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'model' => $product['model'],
                'option' => $option_data,
                'quantity' => $product['quantity'],
                'stock' => $product['stock'] ? true : !(!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning')),
                'shipping' => $product['shipping'],
                'price' => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']),
                'total' => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'], $this->session->data['currency']),
                'reward' => $product['reward']
            );
        }

        // Voucher
        $json['vouchers'] = array();

        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $key => $voucher) {
                $json['vouchers'][] = array(
                    'code' => $voucher['code'],
                    'description' => $voucher['description'],
                    'from_name' => $voucher['from_name'],
                    'from_email' => $voucher['from_email'],
                    'to_name' => $voucher['to_name'],
                    'to_email' => $voucher['to_email'],
                    'voucher_theme_id' => $voucher['voucher_theme_id'],
                    'message' => $voucher['message'],
                    //    'price' => $this->currency->format($voucher['amount'], $this->session->data['currency']),
                    'amount' => $voucher['amount']
                );
            }
        }

        // Totals
        $this->load->model('setting/extension');

        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes' => &$taxes,
            'total' => &$total
        );

        $sort_order = array();

        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);

                // We have to put the totals in an array so that they pass by reference.
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $sort_order = array();

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);

        $json['totals'] = array();

        foreach ($totals as $total) {
            $json['totals'][] = array(
                'title' => $total['title'],
                'text' => $this->currency->format($total['value'], $this->session->data['currency'])
            );
        }
        return $json;
    }
}
