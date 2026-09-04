<?php

// Test harness only: never execute over HTTP. These files deliberately stub
// WordPress, so a web-reachable copy would run outside WordPress entirely.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/bootstrap.php';

$pass = 0;
$fail = 0;

function check($label, $actual, $expected)
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  ok   %s\n", $label);
    } else {
        $fail++;
        printf("  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

/* ---------------------------------------------------------------- fields -- */

$fields = array(
    new GF_Field(array('id' => 1, 'type' => 'email', 'label' => 'Email', 'field_gf_to_hs_map' => 'email')),
    new GF_Field(array(
        'id'     => 2,
        'type'   => 'name',
        'label'  => 'Name',
        'field_gf_to_hs_map' => 'fullname',
        'inputs' => array(
            array('id' => '2.2', 'label' => 'Prefix', 'isHidden' => true),
            array('id' => '2.3', 'label' => 'First'),
            array('id' => '2.6', 'label' => 'Last'),
        ),
    )),
    new GF_Field(array(
        'id'     => 3,
        'type'   => 'checkbox',
        'label'  => 'Interests',
        'field_gf_to_hs_map' => 'interests',
        'inputs' => array(
            array('id' => '3.1', 'label' => 'Employment Law'),
            array('id' => '3.2', 'label' => 'Workplace Relations'),
            array('id' => '3.3', 'label' => 'Training'),
        ),
    )),
    new GF_Field(array('id' => 4, 'type' => 'multiselect', 'label' => 'States', 'field_gf_to_hs_map' => 'states')),
    new GF_Field(array('id' => 5, 'type' => 'consent', 'label' => 'Consent', 'field_gf_to_hs_map' => 'gdpr_optin')),
    new GF_Field(array('id' => 6, 'type' => 'date', 'label' => 'Start', 'field_gf_to_hs_map' => 'start_date')),
    new GF_Field(array('id' => 7, 'type' => 'text', 'label' => 'Unmapped')),
    new GF_Field(array('id' => 8, 'type' => 'textarea', 'label' => 'Empty', 'field_gf_to_hs_map' => 'notes')),
    new GF_Field(array('id' => 9, 'type' => 'text', 'label' => 'Dupe A', 'field_gf_to_hs_map' => 'referrer')),
    new GF_Field(array('id' => 10, 'type' => 'text', 'label' => 'Dupe B', 'field_gf_to_hs_map' => 'referrer')),
);

$entry = array(
    'id'         => '101',
    'form_id'    => '1',
    'source_url' => 'https://example.test/contact/',
    'ip'         => '203.0.113.9',
    '1'          => 'jane@example.com',
    '2.2'        => 'Ms',
    '2.3'        => 'Jane',
    '2.6'        => 'Doe',
    '3.1'        => 'Employment Law',
    '3.3'        => 'Training',
    '4'          => '["QLD","NSW"]',
    '5.1'        => '1',
    '6'          => '2026-08-25',
    '7'          => 'ignored',
    '8'          => '',
    '9'          => 'Google',
    '10'         => 'Referral',
);

$hs = new klypHubspot();
$hs->hsFormId     = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$hs->gfFormFields = $fields;
$hs->entry        = $entry;

$GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{"inlineMessage":"ok"}');
$result = $hs->createContact();

check('submission succeeded', $result['success'], true);

$call = end($GLOBALS['http_calls']);
$sent = json_decode($call['args']['body'], true);
$map  = array();
foreach ($sent['fields'] as $f) {
    $map[$f['name']] = $f['value'];
}

echo "\nPayload building\n";
check('posts to the unauthenticated submissions endpoint', $call['url'], 'https://api.hsforms.com/submissions/v3/integration/submit/1234567/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
check('email passes through', $map['email'] ?? null, 'jane@example.com');
check('name joins visible inputs as values, not labels', $map['fullname'] ?? null, 'Jane Doe');
check('checkbox sends only selected values, semicolon delimited', $map['interests'] ?? null, 'Employment Law;Training');
check('multiselect JSON decodes to semicolon list', $map['states'] ?? null, 'QLD;NSW');
check('consent maps to a boolean string', $map['gdpr_optin'] ?? null, 'true');
check('date passes through as stored Y-m-d', $map['start_date'] ?? null, '2026-08-25');
check('unmapped field is omitted', array_key_exists('7', $map), false);
check('empty mapped field is omitted', array_key_exists('notes', $map), false);
check('duplicate property appears once', count(array_filter($sent['fields'], fn($f) => $f['name'] === 'referrer')), 1);
check('duplicate property keeps the last mapped value', $map['referrer'] ?? null, 'Referral');

echo "\nContext\n";
check('pageUri sent by default (populates Conversion page)', $sent['context']['pageUri'] ?? null, 'https://example.test/contact/');
check('pageName sent by default', $sent['context']['pageName'] ?? null, 'Contact Us');
check('ipAddress from entry', $sent['context']['ipAddress'] ?? null, '203.0.113.9');

// The filter still allows opting out where the portal cannot see the domain.
$GLOBALS['filters']['klyp_gftohs_send_page_context'] = function () { return false; };
$GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{}');
$hs->createContact();
$sentOptOut = json_decode(end($GLOBALS['http_calls'])['args']['body'], true);
unset($GLOBALS['filters']['klyp_gftohs_send_page_context']);

check('pageUri withheld when the filter returns false', array_key_exists('pageUri', $sentOptOut['context'] ?? array()), false);
check('pageName withheld when the filter returns false', array_key_exists('pageName', $sentOptOut['context'] ?? array()), false);
check('ipAddress still sent when page context is off', $sentOptOut['context']['ipAddress'] ?? null, '203.0.113.9');

// A URL that does not resolve to a post still gets a readable page name.
$hsPath = new klypHubspot();
$hsPath->hsFormId     = 'form-guid';
$hsPath->gfFormFields = array(new GF_Field(array('id' => 1, 'type' => 'email', 'label' => 'Email', 'field_gf_to_hs_map' => 'email')));
$hsPath->entry        = array('id' => '500', '1' => 'jane@example.com', 'source_url' => 'https://example.test/news/archive/');
$GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{}');
$hsPath->createContact();
$sentPath = json_decode(end($GLOBALS['http_calls'])['args']['body'], true);
check('pageName falls back to the path for a non-post URL', $sentPath['context']['pageName'] ?? null, 'news/archive');

/* ------------------------------------------------------- email guarantee -- */

echo "\nEmail guarantee\n";
$hs2 = new klypHubspot();
$hs2->hsFormId     = 'form-guid';
$hs2->gfFormFields = array(new GF_Field(array('id' => 1, 'type' => 'email', 'label' => 'Email')));
$hs2->entry        = array('id' => '102', '1' => 'bob@example.com');
$hs2->gfEmailField = '1';
$hs2->hsEmailField = 'email';

$GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{}');
$hs2->createContact();
$sent2 = json_decode(end($GLOBALS['http_calls'])['args']['body'], true);
check('unmapped email field still reaches Hubspot', $sent2['fields'][0], array('name' => 'email', 'value' => 'bob@example.com'));

/* ------------------------------------------------------------- failures -- */

echo "\nFailure handling\n";
$hs3 = new klypHubspot();
$hs3->hsFormId     = 'form-guid';
$hs3->gfFormFields = $fields;
$hs3->entry        = $entry;

$GLOBALS['http_next'] = array(
    'response' => array('code' => 400),
    'body'     => '{"message":"Error in \'fields.email\'","errors":[{"message":"Invalid email"}]}',
);
$r = $hs3->createContact();
check('4xx is reported as failure', $r['success'], false);
check('Hubspot message surfaced', $r['message'], "Error in 'fields.email'");
check('per-field errors collected', $r['errors'], array('Invalid email'));

$GLOBALS['http_next'] = new WP_Error('http_request_failed', 'cURL error 28: timeout');
$r = $hs3->createContact();
check('transport error is reported, not fatal', $r['success'], false);
check('transport message surfaced', $r['message'], 'cURL error 28: timeout');

$hs4 = new klypHubspot();
$hs4->hsFormId = '';
$r = $hs4->createContact();
check('missing form id short circuits', $r['success'], false);

/* -------------------------------------------------------- field reading -- */

echo "\nField discovery (Marketing Forms v3)\n";
$GLOBALS['transients'] = array();
$hs5 = new klypHubspot();

$GLOBALS['http_next'] = array(
    'response' => array('code' => 200),
    'body'     => json_encode(array(
        'id'          => 'form-guid',
        'fieldGroups' => array(
            array('fields' => array(
                array('name' => 'email', 'label' => 'Email', 'fieldType' => 'email', 'objectTypeId' => '0-1'),
                array('name' => 'firstname', 'label' => '', 'fieldType' => 'single_line_text', 'objectTypeId' => '0-1'),
            )),
            array('fields' => array(
                array(
                    'name' => 'industry', 'label' => 'Industry', 'fieldType' => 'dropdown', 'objectTypeId' => '0-1',
                    'dependentFields' => array(
                        array('name' => 'other_industry', 'label' => 'Other', 'fieldType' => 'single_line_text', 'objectTypeId' => '0-1'),
                    ),
                ),
            )),
        ),
    )),
);

$hsFields = $hs5->getFormFields('form-guid');
$call = end($GLOBALS['http_calls']);

check('reads the v3 marketing forms endpoint', $call['url'], 'https://api.hubapi.com/marketing/v3/forms/form-guid');
check('authenticates with a bearer token', $call['args']['headers']['Authorization'], 'Bearer pat-na1-test');
check('flattens all field groups', array_map(fn($f) => $f->name, $hsFields), array('email', 'firstname', 'industry', 'other_industry'));
check('falls back to the name when a label is blank', $hsFields[1]->label, 'firstname');
check('field type carried through', $hsFields[0]->type, 'email');
$byName = array();
foreach ($hsFields as $f) { $byName[$f->name] = $f; }
check('option lists captured for enumeration fields', $byName['industry']->type, 'dropdown');
check('required flag captured', $byName['email']->required, false);

$before = count($GLOBALS['http_calls']);
$hs5->getFormFields('form-guid');
check('second lookup is served from cache', count($GLOBALS['http_calls']), $before);

echo "\nField discovery failures\n";
$GLOBALS['transients'] = array();
$hs6 = new klypHubspot();
$GLOBALS['http_next'] = array('response' => array('code' => 401), 'body' => '{"message":"Authentication credentials not found"}');
check('401 returns an empty list rather than exiting', $hs6->getFormFields('form-guid'), array());
check('401 reason recorded', $hs6->lastError, 'Authentication credentials not found');
check('a failure is not cached', isset($GLOBALS['transients']['klyp_gftohs_fields_' . md5('form-guid|pat-na1-test|0')]), false);

$GLOBALS['options']['klyp_gftohs_access_token'] = '';
$hs7 = new klypHubspot();
check('missing token returns an empty list', $hs7->getFormFields('form-guid'), array());
check('missing token explains itself', $hs7->lastError, 'No Hubspot Private App access token has been configured.');

/* ---------------------------------------------- hubspot boolean checkbox -- */

echo "\nHubspot single_checkbox properties\n";

/**
 * Build a submission against a Hubspot form whose consent property is a
 * required single_checkbox, and return the fields that were posted.
 */
function klyp_submit_consent($ticked)
{
    $GLOBALS['transients'] = array();
    $GLOBALS['options']['klyp_gftohs_access_token'] = 'pat-na1-test';

    $consent = new GF_Field(array(
        'id'     => 10,
        'type'   => 'checkbox',
        'label'  => 'Agree',
        'field_gf_to_hs_map' => 'consent_property',
        'inputs' => array(array('id' => '10.1', 'label' => 'Agree')),
    ));

    $entry = array('id' => '200', '1' => 'jane@example.com');

    if ($ticked) {
        $entry['10.1'] = '1';
    }

    $hs = new klypHubspot();
    $hs->hsFormId     = 'form-guid';
    $hs->gfFormFields = array($consent);
    $hs->entry        = $entry;

    // First call: the field type lookup. Second: the submission.
    $GLOBALS['http_next'] = array(
        'response' => array('code' => 200),
        'body'     => json_encode(array('fieldGroups' => array(
            array('fields' => array(
                array('name' => 'consent_property', 'label' => 'I agree', 'fieldType' => 'single_checkbox', 'required' => true),
            )),
        ))),
    );
    $hs->getFormFields('form-guid');

    $GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{}');
    $hs->createContact();

    $sent = json_decode(end($GLOBALS['http_calls'])['args']['body'], true);
    $map  = array();
    foreach ($sent['fields'] as $f) {
        $map[$f['name']] = $f['value'];
    }

    return $map;
}

$ticked = klyp_submit_consent(true);
check('ticked checkbox sends "true", not the choice value', $ticked['consent_property'] ?? null, 'true');

$unticked = klyp_submit_consent(false);
check('unticked checkbox is still sent, as "false"', $unticked['consent_property'] ?? null, 'false');

/* --------------------------------------------------- payload validation -- */

echo "\nPre-flight payload validation\n";

/**
 * Submit a single mapped text field against a Hubspot form whose target
 * property is a dropdown with a fixed option list.
 */
function klyp_submit_dropdown($value, $property = 'centre_name')
{
    $GLOBALS['transients'] = array();
    $GLOBALS['options']['klyp_gftohs_access_token'] = 'pat-na1-test';

    $hs = new klypHubspot();
    $hs->hsFormId     = 'form-guid';
    $hs->gfFormFields = array(new GF_Field(array(
        'id' => 8, 'type' => 'text', 'label' => 'State', 'field_gf_to_hs_map' => $property,
    )));
    $hs->entry = array('id' => '300', '8' => $value);

    $GLOBALS['http_next'] = array(
        'response' => array('code' => 200),
        'body'     => json_encode(array('fieldGroups' => array(
            array('fields' => array(
                array(
                    'name' => 'centre_name', 'label' => 'Centre Name', 'fieldType' => 'dropdown',
                    'options' => array(
                        array('label' => 'Albion Park', 'value' => '226'),
                        array('label' => 'Algester', 'value' => '104'),
                    ),
                ),
            )),
        ))),
    );
    $hs->getFormFields('form-guid');

    $before = count($GLOBALS['http_calls']);
    $GLOBALS['http_next'] = array('response' => array('code' => 200), 'body' => '{}');
    $result = $hs->createContact();

    return array($result, count($GLOBALS['http_calls']) > $before);
}

list($bad, $posted) = klyp_submit_dropdown('Ad quibusdam aut dol');
check('invalid dropdown value fails instead of being accepted', $bad['success'], false);
check('invalid dropdown value is not sent to Hubspot at all', $posted, false);
check('error names the property so it maps back to a field', strpos($bad['errors'][0], "fields.centre_name") !== false, true);
check('error quotes the offending value', strpos($bad['errors'][0], 'Ad quibusdam aut dol') !== false, true);

list($good, $posted) = klyp_submit_dropdown('226');
check('valid dropdown option is accepted', $good['success'], true);
check('valid dropdown option is posted', $posted, true);

list($unknown,) = klyp_submit_dropdown('anything', 'not_on_this_form');
check('property missing from the Hubspot form is reported', $unknown['success'], false);
check('unknown property error names the property', strpos($unknown['errors'][0], 'fields.not_on_this_form') !== false, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
