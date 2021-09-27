<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

class klypHubspot
{
    public $apiKey;
    public $basePath;

    public $gfFormFields;
    public $gfEmailField;
    public $hsEmailField;
    public $hsFormId;
    public $postedData = array ();

    public function __construct()
    {
        $this->apiKey   = get_option('klyp_gftohs_api_key');
        $this->portalId = get_option('klyp_gftohs_portal_id');
        $this->basePath = get_option('klyp_gftohs_base_url');
    }

    private function remotePost($url, $method = 'POST', $body, $contentType)
    {
        $response = wp_remote_post(
            $url,
            array (
                'method'  => $method,
                'body'    => wp_json_encode($body),
                'headers' => array (
                    'Content-Type' => $contentType
                )
            )
        );

        return $response;
    }

    private function remoteGet($url, $contentType)
    {
        $response = wp_remote_get(
            $url,
            array (
                'headers' => array (
                    'Content-Type'  => $contentType
                )
            )
        );

        return $response;
    }

    public function remoteStatus($response)
    {
        if (is_wp_error($response)) {
            $status = $response->get_error_code();
        } else {
            $status = wp_remote_retrieve_response_code($response);
        }

        return $status;
    }

    private function processData()
    {
        foreach ($this->gfFormFields as $key => $field) {
            if (isset($this->postedData[$field['id']]) && ! empty($field['field_gf_to_hs_map'])) {
                $this->data[] = array (
                    'name'  => $field['field_gf_to_hs_map'],
                    'value' => (is_array($this->postedData[$field['id']]) ? implode(';', $this->postedData[$field['id']]) : sanitize_text_field($this->postedData[$field['id']]))
                );
            }
        }
        return $this->data;
    }

    private function processContextData()
    {
        $hutk = isset($_COOKIE['hubspotutk']) ? sanitize_text_field($_COOKIE['hubspotutk']) : '';
        $referrer = wp_get_referer();
        $objId = 0;

        if ($referrer) {
            $objId = url_to_postid($referrer);
        }

        $currentUrl = get_permalink($objId);
        $pageName = get_the_title($objId);
        $context = array ();

        if (! empty($hutk)) {
            $context['hutk'] = $hutk;
        }

        if (! empty($currentUrl)) {
            $context['pageUri'] = $currentUrl;
        }

        if (! empty($pageName)) {
            $context['pageName'] = $pageName;
        }

        $context = array (
            'context' => $context
        );

        return $context;
    }

    public function getFormFields($formId, $property = null)
    {
        $url        = $this->basePath . 'forms/v2/fields/' . $formId . '?hapikey=' . $this->apiKey;
        $response   = $this->remoteGet($url, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $body = wp_remote_retrieve_body($response);

            if ($body) {
                $body = json_decode($body);
            }

            if ($property) {
                foreach ($body as $key => $value) {
                    if ($value->name == $property) {
                        return $value->type;
                    }
                }
            } else {
                return $body;
            }
        }

        exit();
    }

    public function createContact()
    {
        $data       = array ('fields' => $this->processData());
        $context    = $this->processContextData();
        $url        = 'https://api.hsforms.com/submissions/v3/integration/submit/' . $this->portalId . '/' . $this->hsFormId;

        if (! empty($context['context'])) {
            $data = array_merge($data, $context);
        }

        $response   = $this->remotePost($url, 'POST', $data, 'application/json');
        $status     = $this->remoteStatus($response);

        if ($status == 200) {
            $return = array (
                'success'   => true,
                'message'   => '',
            );
        } else {
            $response = wp_remote_retrieve_body($response);

            if ($response) {
                $message = json_decode($response)->message ?: 'There is something wrong while processing your request. Please try again later.';
                $errors = json_decode($response)->errors;
            } else {
                $message = 'There is something wrong while processing your request. Please try again later.';
                $errors = null;
            }

            $return = array (
                'success'   => false,
                'message'   => $message,
                'errors'    => $errors
            );
        }

        return $return;
    }
}
