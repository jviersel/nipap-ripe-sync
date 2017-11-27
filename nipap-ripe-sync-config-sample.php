<?php
/**
 * This file contains an example configuration strings.
 */

$nipapUsername   = 'example_user';          // Your NIPAP username
$nipapPassword   = 'example_pw';            // Your NIPAP password
$ripeDbPassword  = '';                      // Your RIPE-DB MD5 password

$customerHandles = array(                   // A List of RIPE-DB handles for your customers
    1 => 'JVI-RIPE',					    // Example: Customer 1 has handle JVI-RIPE
    2 => 'PTB-RIPE',                        // Example: Customer 2 has handle PTB-RIPE
);

$updatePrefixes  = array(                   // List of prefixes that need to be synced, script is fixed to /24
    '192.168.0.0/24',                       // Example
    '192.168.1.0/24',                       // Example
);

$netnameFormat   = 'MYISP-CUSTOMER-%d';     // Format to use for your netname-attributes

$defaultAdminC   = array(                   // List of default ADMIN-C contacts that needs to be added
    'JVI-RIPE',							    // Example
    'PTB-RIPE',                             // Example
);

$defaultTechC    = array(                   // List of default TECH-C contacts that needs to be added
    'JVI-RIPE',						        // Example
    'PTB-RIPE',                             // Example
);

$maintainer      = 'EXAMPLE-MNT';           // RIPE-db maintainer for the objects
$country         = 'NL';                    // Your country-code
$notifyContact   = 'notify@example.com';    // Email address RIPE should notify about changes. Empty string for none
