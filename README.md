# WHMCS Provisioning Module for Dashmox

Sell hosting from WHMCS and let it create, suspend, restore and terminate
accounts on your own Linux server. This is the official WHMCS server module for
[Dashmox](https://dashmox.com), a hosting control panel you run yourself.

Free to use, and free to read before you install it. Nothing here is obfuscated
or encoded.

## What it does

| In WHMCS | On your server |
| --- | --- |
| Create | Creates the customer, then their website under it |
| Suspend and Unsuspend | Flips the whole customer, which signs out their people and reconfigures their websites |
| Terminate | Removes every website first, then the customer |
| Change package | Moves the customer onto another hosting plan you already sell |
| Usage update | Peak disk over the billing period, from daily samples |
| Test connection | Checks the panel is reachable and the credential works |

Your customer also gets a read only summary on their service page in the client
area: status, disk used, transfer over the last thirty days and when the
certificate expires.

## Requirements

* A Dashmox panel on your own server. See [dashmox.com](https://dashmox.com).
* **WHMCS 8 or newer**, with PHP 8.1 or newer.
* **An integration key**, which comes with a Pro or Business licence. Create one
  in the panel under **Server**, then **Integrations**.

Grant the key these permissions: `customers.manage`, `sites.create`,
`sites.view`, `sites.edit`, `sites.delete`, `server.view`. It reaches only the
routes that name a permission it holds, so nothing else is open to it whatever
else you tick.

The key belongs to the installation rather than to a person, so it does not stop
working when the administrator who made it leaves.

## Installing

1. Copy the `modules` folder into your WHMCS root, so the module lands at
   `modules/servers/dashmox/`.
2. In WHMCS go to **System Settings**, then **Products/Services**, then
   **Servers**, and add a server of type **Dashmox**.
3. Put your panel's address in **Hostname** and the integration key in
   **Password**. Leave **Username** empty.
4. Press **Test Connection**.

Then point a product at that server and set its **Package** to the name of a
hosting plan that exists on the panel.

## Two tools worth knowing about

Both live in `tools/` and both are read only until you ask otherwise.

**`find-orphans.php`** lists websites on the panel that WHMCS does not know
about, and services WHMCS is invoicing whose website is gone. The second kind is
somebody paying for nothing. The first kind matters if you balance a server
group on account count, because a panel holding websites WHMCS never made looks
emptier than it is, and the balancer then sends more work to your fullest
machine.

```
php tools/find-orphans.php --server 3
```

**`push-packages.php`** copies the packages you sell in WHMCS onto the panel, so
the plan names match without typing them twice. It says what it would do and
stops unless you pass `--apply`, it never reads a panel package back into WHMCS,
and it never deletes.

```
php tools/push-packages.php --server 3
php tools/push-packages.php --server 3 --apply
```

## What it does not do

Stated plainly, because a module that quietly does nothing is worse than one
that says it cannot.

* **No single sign on yet.** The panel mints one time sign on links as of
  0.99.30 and the client under `lib/` can ask for one, but no button in the
  client area calls it: the handoff lands in your customer's browser, and that
  is worth shipping only once it has been driven by a real WHMCS. The
  [FOSSBilling manager](https://github.com/Dashmox-Software/fossbilling-server-manager)
  has it today, built on the same client. Meanwhile every hosted domain answers
  `/dashmox` with a redirect to the sign in page, so your customer can still get
  there.
* **No bandwidth in the usage update.** Transfer is measured by the panel and
  can be read, but what a usage update should send when a plan is priced on both
  disk and transfer is a decision about how you sell. Disk is measured, kept
  daily for four hundred days, and reported.
* **No password changes.** The route exists on the panel and is deliberately
  closed to integration credentials. Setting a website account's password is the
  difference between an integration doing an account's work and becoming the
  account.

## How it behaves when things go wrong

Provisioning is asynchronous. Creating a website answers with the job that builds
it, so the module records the website and polls its status rather than assuming
the work finished.

Two refusals are not failures and are retried rather than reported: a website
with work still queued, and one already being removed. Both clear on their own.

If the panel's licence lapses, every request for a key is refused and the module
says so as something for you to fix rather than as a credential problem. Nothing
is deleted, and it works again once a licence is applied.

## Tests

`tests/client_test.php` covers the part that talks to the panel. It needs
neither WHMCS nor a network.

```
php tests/client_test.php
```

## Support

Found a problem? Open an issue on this repository.

Pro and Business customers get a guaranteed reply through support at
[dashmox.com](https://dashmox.com). Issues here are answered when we can. Pull
requests are read, and not every one will be merged: this module provisions real
hosting, and a mistake in it suspends somebody's customers.

## Licence

MIT. Use it, change it, ship it inside whatever you are running. See
[LICENSE](LICENSE).

## The other two

The same thing exists for other billing systems, built on the same client:

* [FOSSBilling server manager](https://github.com/Dashmox-Software/fossbilling-server-manager)
* [Blesta provisioning module](https://github.com/Dashmox-Software/blesta-module)
