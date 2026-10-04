#!/usr/bin/env php
<?php

/**
 * Put the packages WHMCS sells onto a Dashmox panel.
 *
 * Run from the WHMCS root, which is where the database credentials are:
 *
 *     php modules/servers/dashmox/../../../tools/push-packages.php --server 3
 *     php tools/push-packages.php --server 3 --apply
 *
 * It reads nothing by default. Without --apply it says what it would do and
 * stops, because a tool that changes a hosting server the first time you run it
 * to see what it does is a tool nobody runs twice.
 *
 * **One direction only.** WHMCS is where the packages are sold, so WHMCS is
 * where they are decided; this copies them onto the panel. It never reads a
 * panel package back into WHMCS, because two systems that can each overwrite
 * the other's idea of a plan will eventually disagree at the worst moment and
 * neither will be able to say which was right.
 *
 * What it does not do is delete. A panel package this tool did not create, or
 * one a customer is on, is left alone and named. Removing an allowance somebody
 * is hosting under is not something to do from a sync script.
 */

require_once __DIR__ . '/lib.php';

$options = getopt('', ['server:', 'apply', 'help']);
if (isset($options['help']) || !isset($options['server'])) {
    fwrite(STDERR, "usage: push-packages.php --server <whmcs server id> [--apply]\n");
    exit(isset($options['help']) ? 0 : 2);
}

$whmcs = dashmox_tool_bootstrap();
$panel = dashmox_tool_panel((int) $options['server']);
$apply = isset($options['apply']);

$existing = [];
foreach ($panel->packages() as $package) {
    $existing[strtolower($package['name'])] = $package;
}

$products = $whmcs->table('tblproducts')
    ->where('servertype', 'dashmox')
    ->get();

$made = 0;
$already = 0;
foreach ($products as $product) {
    $name = trim($product->name);
    if ($name === '') {
        continue;
    }
    if (isset($existing[strtolower($name)])) {
        $already++;
        printf("  already there  %s\n", $name);
        continue;
    }
    // Disk is the one allowance both systems agree on. WHMCS also carries
    // bandwidth, mailbox and database numbers; the panel meters transfer now
    // but does not cap it, and the other two are customer allowances rather
    // than per-website numbers, so sending them would be inventing a limit
    // the panel does not enforce and the customer would later be told about.
    $disk = (int) $product->disklimit;
    $wanted = ['name' => $name];
    if ($disk > 0) {
        $wanted['storage_bytes'] = $disk * 1024 * 1024;
    }
    if (!$apply) {
        printf("  would create   %s%s\n", $name, $disk > 0 ? sprintf(' (%d MB)', $disk) : ' (no disk limit)');
        $made++;
        continue;
    }
    try {
        $panel->createPackage($wanted);
        $made++;
        printf("  created        %s\n", $name);
    } catch (Exception $e) {
        printf("  FAILED         %s: %s\n", $name, $e->getMessage());
    }
}

printf(
    "\n%d product(s) on this server type; %d already on the panel, %d %s.\n",
    count($products),
    $already,
    $made,
    $apply ? 'created' : 'would be created'
);
if (!$apply && $made > 0) {
    print("Nothing was changed. Run it again with --apply.\n");
}
