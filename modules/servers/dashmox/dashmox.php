<?php

/**
 * Dashmox provisioning module for WHMCS.
 *
 * This file is the part WHMCS loads: every function here is an entry point it
 * calls by name, taking the array WHMCS builds and returning the strings WHMCS
 * reads. It is deliberately thin. Everything that can be got wrong (which
 * header the token travels in, which field a refusal is read from, which
 * refusals mean "try again", what order termination happens in) lives in
 * lib/Client.php and lib/Panel.php, which have tests and need neither WHMCS nor
 * a network to run.
 *
 * Nothing here can be unit tested without WHMCS, so there is as little here as
 * possible.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// The client lives once in this repository and is copied beside each module
// when one is packaged, so a downloaded module is self-contained while there is
// still only one copy to fix. Either location works: its own, if this is an
// unpacked download, or the shared one, if this is the repository.
$dashmoxLib = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../../../lib';
require_once $dashmoxLib . '/Client.php';
require_once $dashmoxLib . '/Panel.php';

use Dashmox\Whmcs\ApiError;
use Dashmox\Whmcs\Client;
use Dashmox\Whmcs\Panel;

/** Server tokens are issued with this prefix, which is worth checking early. */
const DASHMOX_TOKEN_PREFIX = 'dashmox_';

function dashmox_MetaData()
{
    return [
        'DisplayName' => 'Dashmox',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '8443',
        'DefaultSSLPort' => '8443',
        'ServiceSingleSignOnLabel' => 'Open my control panel',
    ];
}

/**
 * The panel sells the plans; this module offers them rather than inventing them.
 *
 * Creating and editing packages is closed to integration credentials on purpose:
 * the catalogue is the operator's. So the only choice here is which of the
 * operator's existing plans a product maps onto.
 */
function dashmox_ConfigOptions()
{
    return [
        'package' => [
            'FriendlyName' => 'Panel package',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'The package id from the panel, under Customers &rarr; Packages. '
                . 'Leave empty to create accounts with no package.',
        ],
        'runtime' => [
            'FriendlyName' => 'Runtime',
            'Type' => 'dropdown',
            'Options' => 'php,node,proxy,static',
            'Default' => 'php',
            'Description' => 'What new websites are created as.',
        ],
        'php_version' => [
            'FriendlyName' => 'PHP version',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '8.3',
            'Description' => 'Used only when the runtime is php.',
        ],
        'ssl' => [
            'FriendlyName' => 'Request a certificate',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Ask the panel for a certificate when the website is created.',
        ],
        'client_summary' => [
            'FriendlyName' => 'Show hosting summary',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Show the customer what their websites are doing (status, disk, '
                . 'transfer and certificate expiry) on the service page here. Read-only: every '
                . 'change is still made in the panel.',
        ],
        'client_panel_link' => [
            'FriendlyName' => 'Show a sign on button',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Show the customer a button that signs them into the panel. It asks '
                . 'the panel for a one time link when the button is pressed, and lands them on '
                . 'the panel sign-in page instead when no link can be had. Turn it off if your '
                . 'customers should only ever deal with you.',
        ],
    ];
}

/**
 * Build a Panel from what WHMCS holds about the server.
 *
 * The token goes in the server's Access Hash rather than the password field:
 * it belongs to the installation rather than to a person, and there is no
 * username to go with it.
 */
function dashmox_panel(array $params)
{
    $host = $params['serverhostname'] !== '' ? $params['serverhostname'] : $params['serverip'];
    $scheme = $params['serversecure'] ? 'https' : 'http';
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8443;
    $token = trim($params['serveraccesshash']);

    return new Panel(new Client($scheme . '://' . $host . ':' . $port, $token, (bool) $params['serversecure']));
}

/**
 * The panel's own sign-in page.
 *
 * Where a sign-on link cannot be minted this is still a better answer than an
 * error: the customer at least arrives at the right door. It follows the
 * server's own Secure setting rather than assuming https, because that setting
 * is what every other request to this panel already obeys.
 */
function dashmox_panelAddress(array $params)
{
    $host = $params['serverhostname'] !== '' ? $params['serverhostname'] : $params['serverip'];
    $scheme = $params['serversecure'] ? 'https' : 'http';
    $port = !empty($params['serverport']) ? (int) $params['serverport'] : 8443;

    return $scheme . '://' . $host . ':' . $port . '/';
}

/**
 * What a refusal should say to whoever is reading it.
 *
 * The panel writes sentences meant for a person: "this customer still owns 2
 * website(s); remove them before removing the customer" says what to do next -
 * so they are passed through rather than replaced with a status code. The two
 * cases worth adding to are the ones where the panel's sentence is true but the
 * reason is somewhere else entirely.
 */
function dashmox_explain(ApiError $e)
{
    if ($e->isUnlicensed()) {
        return 'The panel has no Pro or Business licence in force, so it refuses every integration '
            . 'request. The token is kept and works again once a licence is applied. (' . $e->getMessage() . ')';
    }
    if ($e->isTemporary()) {
        return 'The panel is still working on this website: ' . $e->getMessage()
            . '. This clears on its own; try again shortly.';
    }
    if ($e->getStatus() === 0) {
        return $e->getMessage();
    }

    return $e->getMessage();
}

function dashmox_TestConnection(array $params)
{
    try {
        $token = trim($params['serveraccesshash']);
        if ($token === '') {
            return ['success' => false, 'error' => 'No server token. Put it in the Access Hash field.'];
        }
        if (strpos($token, DASHMOX_TOKEN_PREFIX) !== 0) {
            return [
                'success' => false,
                'error' => 'That does not look like a Dashmox server token: they begin with "'
                    . DASHMOX_TOKEN_PREFIX . '". Make one in the panel under Server &rarr; Integrations.',
            ];
        }
        // Health first, because it is the one call that proves the token
        // reaches this panel. Then the two things an admin cannot see from
        // WHMCS and will otherwise find out when a customer orders: whether
        // the token may do the work, and whether the packages a product points
        // at are there. A test that only says "connected" leaves both to be
        // discovered by the first order.
        $panel = dashmox_panel($params);
        $panel->health();

        $problems = [];
        try {
            $packages = $panel->packages();
        } catch (ApiError $e) {
            $packages = null;
            $problems[] = $e->getStatus() === 403
                ? 'the token cannot read hosting packages, so it is missing the customers.manage '
                    . 'permission; grant it in the panel under Server, then Integrations'
                : 'hosting packages could not be read: ' . $e->getMessage();
        }
        if ($packages !== null && count($packages) === 0) {
            $problems[] = 'this panel has no hosting packages yet, so products that name one will '
                . 'not provision until it is made under Customers, then Packages';
        }
        if ($problems) {
            // Not a failure: the server is reachable and the token works. WHMCS
            // shows this on the servers page, which is where somebody linking a
            // server is looking, and it is the last moment before an order is
            // the thing that tells them.
            return ['success' => true, 'error' => 'Connected, but: ' . implode('; ', $problems) . '.'];
        }

        return ['success' => true, 'error' => ''];
    } catch (ApiError $e) {
        logModuleCall('dashmox', 'TestConnection', $params, $e->getMessage(), $e->getTraceAsString());

        return ['success' => false, 'error' => dashmox_explain($e)];
    } catch (Exception $e) {
        logModuleCall('dashmox', 'TestConnection', $params, $e->getMessage(), $e->getTraceAsString());

        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create the customer, then the website under it.
 *
 * The panel answers 202: the website is a record immediately and is not finished
 * being built. Both ids are kept on the service, because every later operation
 * addresses them and a module that has to search for them by domain will one day
 * find the wrong one.
 */
function dashmox_CreateAccount(array $params)
{
    try {
        // Somewhere to put the two ids, before anything is made that would need
        // remembering. Without this the create succeeded, remember() silently
        // did nothing, and every later operation found no ids, so terminating
        // the service answered "there is nothing to terminate" while the
        // customer and their website went on running on the panel, billed by
        // nobody and noticed by no one until find-orphans was written. The
        // install instructions never mentioned the fields, so the documented
        // way to set this module up produced exactly that.
        if (!dashmox_fields((int) $params['pid'])) {
            return 'This product has nowhere to record the panel ids, and they could not be '
                . 'created. Add two admin-only custom fields to it named dashmox_customer_id and '
                . 'dashmox_site_id, then try again. Nothing was created on the panel.';
        }
        $panel = dashmox_panel($params);
        $client = $params['clientsdetails'];

        $made = $panel->createAccount(
            [
                'name' => trim($client['firstname'] . ' ' . $client['lastname']),
                'email' => $client['email'],
                'company' => $client['companyname'],
                'phone' => $client['phonenumber'],
                'address' => trim($client['address1'] . ' ' . $client['address2']),
                'city' => $client['city'],
                'region' => $client['state'],
                'postal_code' => $client['postcode'],
                'country' => $client['country'],
                'package_id' => $params['configoption1'],
            ],
            [
                'name' => $params['domain'] !== '' ? $params['domain'] : $params['username'],
                'domain' => $params['domain'],
                'runtime' => $params['configoption2'] !== '' ? $params['configoption2'] : 'php',
                'php_version' => $params['configoption3'],
                'ssl' => (bool) $params['configoption4'],
            ]
        );

        dashmox_remember($params['pid'], $params['serviceid'], $made['customer_id'], $made['site']['id'] ?? '');

        return 'success';
    } catch (ApiError $e) {
        logModuleCall('dashmox', 'CreateAccount', $params, $e->getMessage(), $e->getTraceAsString());

        return dashmox_explain($e);
    } catch (Exception $e) {
        logModuleCall('dashmox', 'CreateAccount', $params, $e->getMessage(), $e->getTraceAsString());

        return $e->getMessage();
    }
}

/**
 * Suspend the whole account, not one website.
 *
 * Non-payment is about the customer: this flips them, signs out their people and
 * reconfigures each of their websites. Suspending a single website is a
 * different operation and is not what an overdue invoice means.
 */
function dashmox_SuspendAccount(array $params)
{
    return dashmox_setSuspended($params, true, 'SuspendAccount');
}

function dashmox_UnsuspendAccount(array $params)
{
    return dashmox_setSuspended($params, false, 'UnsuspendAccount');
}

function dashmox_setSuspended(array $params, $suspended, $operation)
{
    try {
        $ids = dashmox_recall((int) $params['pid'], $params['serviceid']);
        if ($ids['customer_id'] === '') {
            return 'This service has no panel customer recorded, so there is nothing to '
                . ($suspended ? 'suspend' : 'restore') . '.';
        }
        dashmox_panel($params)->setCustomerSuspended($ids['customer_id'], $suspended);

        return 'success';
    } catch (ApiError $e) {
        logModuleCall('dashmox', $operation, $params, $e->getMessage(), $e->getTraceAsString());

        return dashmox_explain($e);
    } catch (Exception $e) {
        logModuleCall('dashmox', $operation, $params, $e->getMessage(), $e->getTraceAsString());

        return $e->getMessage();
    }
}

/**
 * Every website first, then the account.
 *
 * The panel refuses a customer who still owns websites and says how many, so
 * this has an order rather than being one call. A website with work still queued
 * refuses to be removed as well, and that refusal clears on its own, so it is
 * reported as "try again shortly" rather than as a failed termination, which
 * would send somebody looking for a problem that is not there.
 */
function dashmox_TerminateAccount(array $params)
{
    try {
        $panel = dashmox_panel($params);
        $ids = dashmox_recall((int) $params['pid'], $params['serviceid']);
        if ($ids['customer_id'] === '') {
            return 'This service has no panel customer recorded, so there is nothing to terminate.';
        }

        if ($ids['site_id'] !== '') {
            $site = $panel->site($ids['site_id']);
            if (!empty($site['domain'])) {
                // Answers 202 with the job that does it. The website is gone
                // from the panel's point of view before its files are.
                $panel->deleteSite($ids['site_id'], $site['domain']);
            }
        }

        // The name the panel holds, not the one WHMCS holds now. A client
        // renamed since their account was created would otherwise fail the
        // echo, and a 400 about a name not matching is impossible to act on
        // when the two names are in different systems.
        $customer = $panel->customer($ids['customer_id']);
        if ($customer === null) {
            // Already gone. Nothing to remove and nothing wrong: a termination
            // retried after a partial run lands here.
            return 'success';
        }

        try {
            $panel->deleteCustomer($ids['customer_id'], $customer['name']);
        } catch (ApiError $e) {
            // The websites are still being removed. Removing them is queued
            // work, so this is the ordinary outcome of terminating an account
            // with a website on it, not a failure, and reporting it as one
            // sends somebody looking for a problem that is finishing by itself.
            if ($e->getStatus() === 409 && strpos($e->getMessage(), 'still owns') !== false) {
                return 'The website is still being removed, so the account cannot go yet: '
                    . $e->getMessage() . ' Terminate again once the removal has finished.';
            }
            throw $e;
        }

        return 'success';
    } catch (ApiError $e) {
        logModuleCall('dashmox', 'TerminateAccount', $params, $e->getMessage(), $e->getTraceAsString());

        return dashmox_explain($e);
    } catch (Exception $e) {
        logModuleCall('dashmox', 'TerminateAccount', $params, $e->getMessage(), $e->getTraceAsString());

        return $e->getMessage();
    }
}

function dashmox_ChangePackage(array $params)
{
    try {
        $ids = dashmox_recall((int) $params['pid'], $params['serviceid']);
        if ($ids['customer_id'] === '') {
            return 'This service has no panel customer recorded, so its plan cannot be changed.';
        }
        if ($params['configoption1'] === '') {
            return 'This product names no panel package, so there is nothing to move the account onto.';
        }
        dashmox_panel($params)->setPackage($ids['customer_id'], $params['configoption1']);

        return 'success';
    } catch (ApiError $e) {
        logModuleCall('dashmox', 'ChangePackage', $params, $e->getMessage(), $e->getTraceAsString());

        return dashmox_explain($e);
    } catch (Exception $e) {
        logModuleCall('dashmox', 'ChangePackage', $params, $e->getMessage(), $e->getTraceAsString());

        return $e->getMessage();
    }
}

/**
 * Disk used, from the daily samples rather than from a reading taken just now.
 *
 * The peak over the billing period is the figure a plan priced on disk is sold
 * against, and it is what survives a customer tidying up the day before the
 * invoice. A website with no samples yet reports nothing rather than zero:
 * "not measured" and "used nothing" are different claims, and only one of them
 * is safe to bill on.
 *
 * Bandwidth is absent: the panel does not measure transfer at all, so no figure
 * is written rather than a zero that would look like a customer who used none.
 */
function dashmox_UsageUpdate(array $params)
{
    $panel = dashmox_panel($params);
    $services = Illuminate\Database\Capsule\Manager::table('tblhosting')
        ->where('server', $params['serverid'])
        ->where('domainstatus', 'Active')
        ->get();

    foreach ($services as $service) {
        try {
            // packageid is the product, which is what a custom field belongs
            // to. This loop reads services out of the table rather than being
            // handed one, so it is the one place the product is not in $params.
            $ids = dashmox_recall((int) $service->packageid, $service->id);
            if ($ids['site_id'] === '') {
                continue;
            }
            $peak = $panel->peakBytes($ids['site_id'], '30d');
            if ($peak === null) {
                continue;
            }
            Illuminate\Database\Capsule\Manager::table('tblhosting')
                ->where('id', $service->id)
                ->update([
                    'diskusage' => (int) round($peak / 1024),
                    'lastupdate' => date('Y-m-d H:i:s'),
                ]);
        } catch (Exception $e) {
            logModuleCall('dashmox', 'UsageUpdate', ['service' => $service->id], $e->getMessage(),
                $e->getTraceAsString());
        }
    }
}

/**
 * Where the panel's ids are kept.
 *
 * WHMCS has nowhere of its own for a module to put two identifiers, so they go
 * in tblcustomfieldsvalues against the service. Addressing by id rather than
 * searching by domain matters: a domain can be moved, renamed, or exist twice
 * across customers, and a termination that found the wrong website would not be
 * recoverable.
 */
function dashmox_remember($productId, $serviceId, $customerId, $siteId)
{
    dashmox_setField($productId, $serviceId, 'dashmox_customer_id', $customerId);
    dashmox_setField($productId, $serviceId, 'dashmox_site_id', $siteId);
}

/**
 * The two fields this module keeps its ids in, made if they are not there.
 *
 * Made rather than demanded, because the alternative is an install step nobody
 * is told about whose only symptom is a website that outlives the service that
 * paid for it. They are admin-only and hidden from the client area: a panel id
 * is not something a customer needs to read, and a customer who can edit one
 * can point their service at somebody else's website.
 *
 * Per product, which is what `type = product` means in WHMCS. This used to look
 * a field up by name alone and take the first of them, so with two Dashmox
 * products every service stored its ids against whichever product's field came
 * back first, and deleting that product would have taken the other's ids with
 * it.
 *
 * @return bool Whether both fields now exist.
 */
function dashmox_fields($productId)
{
    if ($productId <= 0) {
        return false;
    }
    $wanted = [
        'dashmox_customer_id' => 'Dashmox customer id',
        'dashmox_site_id' => 'Dashmox website id',
    ];
    foreach ($wanted as $name => $description) {
        if (dashmox_fieldId($productId, $name)) {
            continue;
        }
        try {
            Illuminate\Database\Capsule\Manager::table('tblcustomfields')->insert([
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => $name,
                'fieldtype' => 'text',
                'description' => $description,
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 0,
            ]);
        } catch (Exception $e) {
            logModuleCall('dashmox', 'fields', ['product' => $productId, 'field' => $name],
                $e->getMessage(), $e->getTraceAsString());
        }
        if (!dashmox_fieldId($productId, $name)) {
            return false;
        }
    }

    return true;
}

function dashmox_recall($productId, $serviceId)
{
    return [
        'customer_id' => dashmox_getField($productId, $serviceId, 'dashmox_customer_id'),
        'site_id' => dashmox_getField($productId, $serviceId, 'dashmox_site_id'),
    ];
}

function dashmox_fieldId($productId, $name)
{
    $field = Illuminate\Database\Capsule\Manager::table('tblcustomfields')
        ->where('type', 'product')
        ->where('relid', $productId)
        ->where('fieldname', $name)
        ->first();

    return $field ? $field->id : 0;
}

function dashmox_setField($productId, $serviceId, $name, $value)
{
    $fieldId = dashmox_fieldId($productId, $name);
    if (!$fieldId) {
        return;
    }
    $existing = Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)->where('relid', $serviceId)->first();
    if ($existing) {
        Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)->where('relid', $serviceId)
            ->update(['value' => $value]);

        return;
    }
    Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
        ->insert(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value]);
}

function dashmox_getField($productId, $serviceId, $name)
{
    $fieldId = dashmox_fieldId($productId, $name);
    if (!$fieldId) {
        return '';
    }
    $row = Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)->where('relid', $serviceId)->first();

    return $row ? (string) $row->value : '';
}

/**
 * Sign the customer into their panel.
 *
 * **This is the only way most customers can get in.** The panel customer is
 * created by the integration key with no password, and so is the website's own
 * account, so the "open the hosting panel" link this module used to offer led
 * to a sign-in page where the customer had no credentials to type. The panel
 * mints a one-time link instead: ninety seconds, spent the moment it is
 * opened, and good for that one website.
 *
 * WHMCS calls this when a customer reaches
 * clientarea.php?action=productdetails&id=<service>&dosinglesignon=1, which is
 * what the button in the template points at. WHMCS does the redirecting; this
 * returns where to.
 *
 * The three refusals a caller can do nothing about fall back to the panel's
 * sign-in page rather than reporting a failure: an older panel, a key the
 * operator did not give users.manage, and a website still being built. That is
 * where the button went before, so the worst case here is the old behaviour.
 */
function dashmox_ServiceSingleSignOn(array $params)
{
    $plain = dashmox_panelAddress($params);
    $ids = dashmox_recall((int) $params['pid'], $params['serviceid']);
    if (empty($ids['site_id'])) {
        return ['success' => true, 'redirectTo' => $plain];
    }

    try {
        // Written into the panel's audit log as the claim it is: the panel
        // cannot check it. An operator asking who went in gets an answer
        // better than "the billing system".
        $who = 'WHMCS, service ' . (int) $params['serviceid'];
        if (!empty($params['clientsdetails']['userid'])) {
            // Read off clientsdetails rather than the top level, because
            // clientsdetails is the one this module already relies on and has
            // been seen to be there. A client id it does not have is left out
            // rather than written down as nought.
            $who .= ', client ' . (int) $params['clientsdetails']['userid'];
        }
        $url = dashmox_panel($params)->signOn($ids['site_id'], $who);
    } catch (ApiError $e) {
        return ['success' => false, 'errorMsg' => dashmox_explain($e)];
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => 'The panel could not be reached just now.'];
    }

    return ['success' => true, 'redirectTo' => $url ? $url : $plain];
}

/**
 * What the customer sees on their service page.
 *
 * Read-only, deliberately. The operator settled on 2026-09-23 that this shows
 * what the websites are doing and changes nothing: every action stays in the
 * panel, where it is done once rather than built a second time here and kept in
 * step for ever. What this has to earn is the trip not taken: a customer who
 * can see that their disk is fine and their certificate renews next month has
 * no reason to open anything.
 *
 * The one thing on it to press changes nothing either: the sign-on button
 * opens the panel, which is still where every change is made.
 *
 * It degrades rather than fails. A panel that cannot be reached, a website the
 * panel has forgotten, a figure never measured: each is its own sentence, and
 * none of them stops the rest of the page drawing. A billing page that will not
 * load because a hosting server is down is two outages.
 */
function dashmox_ClientArea(array $params)
{
    if (empty($params['configoption5']) || $params['configoption5'] !== 'on') {
        return '';
    }
    $ids = dashmox_recall((int) $params['pid'], $params['serviceid']);
    if (empty($ids['site_id'])) {
        return [
            'templatefile' => 'clientarea',
            'vars' => ['problem' => 'This service has no website on the panel yet.'],
        ];
    }

    $vars = ['problem' => '', 'site' => null, 'sso' => '', 'sslDays' => null];
    if (!empty($params['configoption6']) && $params['configoption6'] === 'on') {
        // WHMCS draws nothing for single sign-on by itself: its own
        // documentation says the link belongs in the sidebar or in the module's
        // client area output, and this is the module's client area output. The
        // address is WHMCS's own trigger, which calls the function above and
        // then redirects to whatever it returns.
        $vars['sso'] = 'clientarea.php?action=productdetails&id='
            . (int) $params['serviceid'] . '&dosinglesignon=1';
    }

    try {
        $panel = dashmox_panel($params);
        $site = $panel->site($ids['site_id']);
        $vars['site'] = [
            'domain' => isset($site['primary_domain']) ? $site['primary_domain'] : '',
            'status' => isset($site['status']) ? $site['status'] : 'unknown',
            'runtime' => isset($site['runtime']) ? $site['runtime'] : '',
        ];
        $vars['disk'] = dashmox_measured(function () use ($panel, $ids) {
            $storage = $panel->storage($ids['site_id']);

            return isset($storage['bytes']) ? (int) $storage['bytes'] : null;
        });
        $vars['transfer'] = dashmox_measured(function () use ($panel, $ids) {
            $moved = $panel->bandwidth($ids['site_id'], '30d');

            return isset($moved['bytes']) ? (int) $moved['bytes'] : null;
        });
        $vars['sslDays'] = dashmox_measured(function () use ($panel, $ids) {
            $certificate = $panel->certificate($ids['site_id']);
            if (!$certificate || empty($certificate['expires_at'])) {
                return null;
            }
            $expires = strtotime($certificate['expires_at']);

            return $expires ? (int) floor(($expires - time()) / 86400) : null;
        });
    } catch (ApiError $e) {
        $vars['problem'] = dashmox_explain($e);
    } catch (Exception $e) {
        $vars['problem'] = 'The panel could not be reached just now.';
    }

    return ['templatefile' => 'clientarea', 'vars' => $vars];
}

/**
 * A figure, or null when it was not measured.
 *
 * Null rather than zero, for the reason peakBytes() already gives: a server on
 * its first day has taken no samples, and "used nothing" is a different claim
 * from "not measured". One of them shows a customer a reassuring zero while the
 * thing that measures it is broken.
 */
function dashmox_measured(callable $read)
{
    try {
        return $read();
    } catch (Exception $e) {
        return null;
    }
}
