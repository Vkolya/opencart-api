<?php

require_once(DIR_SYSTEM . 'engine/apiController.php');

class ControllerRestSession extends apiController
{

    public function index()
    {
        $this->load->language('api/login');

        $json = array();

        $this->load->model('account/api');
        $this->load->model('account/rest');

        $api_info = $this->model_account_rest->getApiInfoByUsername('Default');

        if ($api_info) {

            $json['success'] = $this->language->get('text_success');

            $session = new Session($this->config->get('session_engine'), $this->registry);

            $session->start();

            $session->data['api_id'] = $api_info['api_id'];

            $currency_code = $this->config->get('config_currency');
            $session->data['currency'] = $currency_code;

            $language_code = $this->config->get('config_language');
            $session->data['language'] = $language_code;

            $this->model_account_api->addApiSession($api_info['api_id'], $session->getId(), $this->request->server['REMOTE_ADDR']);

            // Create Token
            $json['token'] = $session->getId();

        }

        $this->sendResponse($json);
    }
}
