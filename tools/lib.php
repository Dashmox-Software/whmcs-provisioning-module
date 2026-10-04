<?php

/**
 * What the two admin tools share: finding WHMCS, and building a panel client
 * from a server WHMCS already holds.
 *
 * These run from the WHMCS root because that is where the database credentials
 * are. Nothing here reads a password out of a file itself: WHMCS's own
 * bootstrap does that, and a tool that learned to decrypt server passwords
 * would be a much more interesting thing to steal than a tool that asks WHMCS.
 */

require_once __DIR__ . '/../modules/servers/dashmox/lib/Client.php';
require_once __DIR__ . '/../modules/servers/dashmox/lib/Panel.php';

/**
 * Load WHMCS and hand back its query builder.
 *
 * Searched for rather than assumed: these tools are copied into a WHMCS
 * installation whose layout is the host's business, and a wrong guess about
 * where init.php lives is a confusing failure rather than a clear one.
 */
function dashmox_tool_bootstrap()
{
    $candidates = [
        getcwd() . '/init.php',
        __DIR__ . '/../../../init.php',
        __DIR__ . '/../../init.php',
    ];
    foreach ($candidates as $init) {
        if (is_file($init)) {
            require_once $init;

            return new Illuminate\Database\Capsule\Manager();
        }
    }
    fwrite(STDERR, "WHMCS was not found. Run this from your WHMCS root directory.\nLooked in:\n  " .
        implode("\n  ", $candidates) . "\n");
    exit(2);
}

/**
 * A Panel for one of the servers WHMCS holds.
 *
 * The token is the server's access hash, which is where this module puts it:
 * it belongs to the installation rather than to a person, and there is no
 * username to go with it.
 */
function dashmox_tool_panel($serverId)
{
    $server = Illuminate\Database\Capsule\Manager::table('tblservers')
        ->where('id', $serverId)
        ->first();
    if (!$server) {
        fwrite(STDERR, "No server #$serverId in WHMCS.\n");
        exit(2);
    }
    if ($server->type !== 'dashmox') {
        fwrite(STDERR, "Server #$serverId is a '{$server->type}' server, not a Dashmox one.\n");
        exit(2);
    }
    $host = $server->hostname !== '' ? $server->hostname : $server->ipaddress;
    $port = (int) $server->port ?: 8443;
    $scheme = $server->secure ? 'https' : 'http';
    $token = trim(decrypt($server->accesshash));
    if ($token === '') {
        fwrite(STDERR, "Server #$serverId has no access hash, which is where the panel token goes.\n");
        exit(2);
    }

    return new Panel(new Client($scheme . '://' . $host . ':' . $port, $token, (bool) $server->secure));
}

/** Every service on one server that this module provisions. */
function dashmox_tool_services($whmcs, $serverId)
{
    return Illuminate\Database\Capsule\Manager::table('tblhosting')
        ->where('server', $serverId)
        ->whereIn('domainstatus', ['Active', 'Suspended'])
        ->get();
}

/**
 * One of the panel ids the module stored against a service.
 *
 * Scoped to the service's own product, because a custom field belongs to one:
 * looking it up by name alone finds whichever product's field comes back first,
 * and with two Dashmox products that is the wrong one about half the time.
 */
function dashmox_tool_field($whmcs, $productId, $serviceId, $name)
{
    $field = Illuminate\Database\Capsule\Manager::table('tblcustomfields')
        ->where('type', 'product')->where('relid', $productId)
        ->where('fieldname', $name)->first();
    if (!$field) {
        return '';
    }
    $value = Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
        ->where('fieldid', $field->id)->where('relid', $serviceId)->first();

    return $value ? trim($value->value) : '';
}
