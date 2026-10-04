#!/usr/bin/env php
<?php

/**
 * Websites on a panel that WHMCS does not know about, and services WHMCS holds
 * whose website is gone.
 *
 *     php tools/find-orphans.php --server 3
 *
 * Read-only. It changes nothing and is safe to run against a live server; there
 * is no --apply, because deciding what to do about an unknown website is not a
 * decision a script should make. One of them is somebody's shop.
 *
 * **Why this matters beyond tidiness.** A WHMCS server group balancing on
 * "least accounts" counts the services WHMCS created. A panel that also holds
 * websites WHMCS never made (migrated in, made by hand, left behind by a
 * customer removed here but not there) looks emptier than it is, so the
 * balancer sends *more* work to the fullest machine. Run this before trusting a
 * balanced group.
 *
 * The other direction matters more for billing: a service WHMCS is invoicing
 * whose website is no longer on the panel is somebody paying for nothing.
 */

require_once __DIR__ . '/lib.php';

$options = getopt('', ['server:', 'help']);
if (isset($options['help']) || !isset($options['server'])) {
    fwrite(STDERR, "usage: find-orphans.php --server <whmcs server id>\n");
    exit(isset($options['help']) ? 0 : 2);
}

$whmcs = dashmox_tool_bootstrap();
$serverId = (int) $options['server'];
$panel = dashmox_tool_panel($serverId);

// What the panel holds. Every page of it: a client that read only the first
// would report everything past it as an orphan, which is the failure mode that
// makes a tool like this worse than nothing.
$onPanel = [];
foreach ($panel->sites() as $site) {
    if (!empty($site['id'])) {
        $onPanel[$site['id']] = isset($site['primary_domain']) ? $site['primary_domain'] : $site['id'];
    }
}

// What WHMCS believes it put there. Addressed by the panel's own id, which is
// what the module stored: matching on domain would call a renamed website an
// orphan and a domain held twice a duplicate.
$knownIds = [];
$missing = [];
$services = dashmox_tool_services($whmcs, $serverId);
foreach ($services as $service) {
    $siteId = dashmox_tool_field($whmcs, (int) $service->packageid, $service->id, 'dashmox_site_id');
    if ($siteId === '') {
        $missing[] = sprintf('#%d %s (never recorded a website)', $service->id, $service->domain);
        continue;
    }
    $knownIds[$siteId] = true;
    if (!isset($onPanel[$siteId])) {
        $missing[] = sprintf('#%d %s (website %s is not on the panel)', $service->id, $service->domain, $siteId);
    }
}

$orphans = [];
foreach ($onPanel as $id => $domain) {
    if (!isset($knownIds[$id])) {
        $orphans[] = sprintf('%s (%s)', $domain, $id);
    }
}

printf("Panel holds %d website(s). WHMCS has %d service(s) on this server.\n\n", count($onPanel), count($services));

if ($orphans) {
    printf("On the panel, unknown to WHMCS (%d):\n", count($orphans));
    foreach ($orphans as $one) {
        printf("  %s\n", $one);
    }
    print("\nThese are counted by the panel and not by WHMCS, so a group balancing on\n");
    print("least accounts will under-count this server. None of them is billed here.\n\n");
} else {
    print("Nothing on the panel is unknown to WHMCS.\n\n");
}

if ($missing) {
    printf("Billed by WHMCS, not on the panel (%d):\n", count($missing));
    foreach ($missing as $one) {
        printf("  %s\n", $one);
    }
    print("\nEach of these is a service that may be invoiced for hosting that is not there.\n");
} else {
    print("Every service WHMCS holds has its website on the panel.\n");
}

exit($orphans || $missing ? 1 : 0);
